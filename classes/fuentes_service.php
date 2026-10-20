<?php
/**
 * Fuentes de Épica en el chat (carta 17, v2.7.0).
 *
 * El profesorado sube material a Épica (PDF, Word, PowerPoint, vídeo transcrito) y Épica lo sirve por
 * tres puertas: `fuentes` (índice), `fuentes/texto` (texto, SIN usar de momento) y `fuentes/buscar`.
 * Se SUMA al RAG de Moodle, no lo sustituye. Reglas (CLAUDE.md → «Fuentes de Épica»):
 *  - Se llama desde la PETICIÓN WEB, con $USER, contexto de curso y `epica_client::CAPABILITY_ROL`
 *    (viewanalytics, nunca createactivity) y `(string)$courseid`. Token nuevo en CADA llamada.
 *  - FALLA ABIERTO: timeout, error, 4xx o 5xx → sin fragmentos y el chat sigue con su RAG. Al log solo
 *    va el código, nunca texto del material ni el token.
 *  - Lo que viene de Épica se guarda con solo los campos conocidos, tipos coaccionados y recortes con mb_*.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/epica_client.php');

class fuentes_service {

    const RUTA_INDICE = '/api/moodle/fuentes';
    const RUTA_BUSCAR = '/api/moodle/fuentes/buscar';

    /** Esperas máximas (s) que recomienda la carta 17 §2. */
    const ESPERA_INDICE_S = 1;
    const ESPERA_BUSCAR_S = 2;

    /** Resultados pedidos a la búsqueda (la carta recomienda 5-8). */
    const K = 6;

    const CONSULTA_MAX = 500;
    const FRAGMENTO_MAX = 1500;
    const TOTAL_MAX = 8000;
    const NOMBRE_MAX = 150;
    const RUTA_NIVEL_MAX = 100;

    /** Caché del índice por curso (solo `version` y nº de fuentes). */
    const INDICE_TTL_S = 300;
    /** 409 herramienta-no-contratada: es de la PLATAFORMA, así que una sola marca para todo el sitio. */
    const NO_CONTRATADA_TTL_S = 3600;
    /** Fallo del índice: no volver a esperar 1 s en cada pregunta mientras Épica no contesta. */
    const ERROR_TTL_S = 60;

    const CACHE_AREA = 'fuentesidx';
    const CLAVE_NO_CONTRATADA = 'nocontratada';

    // ----------------------------------------------------------------
    // Consulta.
    // ----------------------------------------------------------------

    /** Palabras sin significado para contar si una pregunta es corta (no se usa para buscar). */
    const STOPWORDS = [
        'a', 'al', 'algo', 'ante', 'con', 'como', 'cómo', 'cual', 'cuál', 'cuales', 'cuáles', 'cuando', 'cuándo',
        'de', 'del', 'donde', 'dónde', 'e', 'el', 'ella', 'en', 'entre', 'es', 'esa', 'ese', 'eso', 'esta', 'este',
        'esto', 'hay', 'la', 'las', 'le', 'les', 'lo', 'los', 'me', 'mi', 'muy', 'no', 'nos', 'o', 'para', 'pero',
        'por', 'porque', 'porqué', 'que', 'qué', 'quien', 'quién', 'se', 'si', 'sí', 'sin', 'sobre', 'son', 'su',
        'sus', 'te', 'tu', 'un', 'una', 'uno', 'y', 'ya', 'yo',
    ];

    /** Referencias al turno anterior: sin él la pregunta no se puede buscar. */
    const RE_REFERENCIA = '/(?<![\p{L}\p{N}])(eso|esto|esa|ese|esos|esas|estos|estas|aquello|aquel|aquella|ello|ahí|allí'
        . '|lo anterior|lo mismo|lo de antes|y por qué|y por que|y entonces|el primero|la primera|el segundo|la segunda'
        . '|el tercero|la tercera|el último|la última|el anterior|la anterior)(?![\p{L}\p{N}])/u';

    /** Una pregunta de ≤ este nº de palabras con significado se considera corta. */
    const PALABRAS_CORTA = 6;

    /**
     * Consulta para la búsqueda: la pregunta tal cual (la búsqueda ya ignora palabras vacías y tildes) y,
     * si es corta o depende del turno anterior, con la última pregunta del usuario detrás (carta 17 §3).
     *
     * @param string $userquery Pregunta actual.
     * @param array $history Historial ya preparado (la pregunta actual NO está dentro).
     * @return string Sin recortar: buscar() limita a 500 caracteres.
     */
    public static function construir_consulta(string $userquery, array $history): string {
        $actual = self::compactar($userquery);
        if ($actual === '') {
            return '';
        }
        $minus = mb_strtolower($actual, 'UTF-8');
        $palabras = preg_split('/[^\p{L}\p{N}]+/u', $minus, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $conSentido = array_filter($palabras, function ($p) {
            return !in_array($p, self::STOPWORDS, true);
        });
        $corta = count($conSentido) <= self::PALABRAS_CORTA;
        $dependiente = preg_match(self::RE_REFERENCIA, $minus) === 1
            || preg_match('/^(y|pero|entonces)(?![\p{L}\p{N}])/u', $minus) === 1;
        if (!$corta && !$dependiente) {
            return $actual;
        }

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $msg = $history[$i];
            if (!is_array($msg) || ($msg['role'] ?? '') !== 'user' || !is_string($msg['content'] ?? null)) {
                continue;
            }
            $previa = mb_substr(self::compactar($msg['content']), 0, 300, 'UTF-8');
            if ($previa !== '' && $previa !== $actual) {
                return $actual . ' ' . $previa;
            }
        }
        return $actual;
    }

    // ----------------------------------------------------------------
    // Índice (caché MUC por curso).
    // ----------------------------------------------------------------

    /**
     * Índice de fuentes del curso.
     *
     * @param int $courseid
     * @return array|null ['version' => string, 'fuentes' => int], o null si no se puede saber
     *                    (sin Épica, herramienta no contratada, error): quien llama no busca.
     */
    public static function indice(int $courseid): ?array {
        if (!epica_client::disponible()) {
            return null;
        }
        $cache = self::cache();
        $ahora = time();

        $nc = self::cache_get($cache, self::CLAVE_NO_CONTRATADA);
        if (is_array($nc) && $ahora - (int)($nc['ts'] ?? 0) < self::NO_CONTRATADA_TTL_S) {
            return null;
        }
        $clave = 'idx_' . $courseid;
        $guardado = self::cache_get($cache, $clave);
        if (is_array($guardado)) {
            $edad = $ahora - (int)($guardado['ts'] ?? 0);
            if (!empty($guardado['error'])) {
                if ($edad < self::ERROR_TTL_S) {
                    return null;
                }
            } else if ($edad < self::INDICE_TTL_S) {
                return ['version' => (string)$guardado['version'], 'fuentes' => (int)$guardado['fuentes']];
            }
        }

        $r = self::llamar(self::RUTA_INDICE, [], $courseid, self::ESPERA_INDICE_S);
        [$http, $datos] = epica_client::normalize_response($r);

        if (epica_client::es_transitorio($r)) {
            error_log('Pulso fuentes: indice no disponible: ' . epica_client::describir_transitorio($r));
            self::cache_set($cache, $clave, ['ts' => $ahora, 'error' => 1]);
            return null;
        }
        if ($http === 409 && self::codigo($datos['motivo'] ?? '') === 'herramienta-no-contratada') {
            self::cache_set($cache, self::CLAVE_NO_CONTRATADA, ['ts' => $ahora]);
            return null;
        }
        if ($http !== 200) {
            error_log('Pulso fuentes: indice HTTP ' . $http . ' motivo ' . self::codigo($datos['motivo'] ?? ''));
            self::cache_set($cache, $clave, ['ts' => $ahora, 'error' => 1]);
            return null;
        }

        $total = $datos['total']['fuentes'] ?? (is_array($datos['fuentes'] ?? null) ? count($datos['fuentes']) : 0);
        $version = (string)($datos['version'] ?? '');
        $indice = [
            'version' => preg_match('/^[0-9A-Za-z_-]{1,64}\z/', $version) === 1 ? $version : '',
            'fuentes' => max(0, (int)$total),
        ];
        self::cache_set($cache, $clave, ['ts' => $ahora] + $indice);
        return $indice;
    }

    // ----------------------------------------------------------------
    // Búsqueda.
    // ----------------------------------------------------------------

    /**
     * Busca en el material del curso. FALLA ABIERTO.
     *
     * @param int $courseid
     * @param string $consulta
     * @return array{hecha: bool, fragmentos: array} `hecha` solo si la búsqueda llegó a contestar (200),
     *         aunque sin resultados: es lo que permite decir «eso no está en el material». Con índice a 0,
     *         sin Épica o ante cualquier fallo: hecha=false y sin fragmentos.
     */
    public static function buscar(int $courseid, string $consulta): array {
        $nada = ['hecha' => false, 'fragmentos' => []];
        $consulta = mb_substr(self::compactar($consulta), 0, self::CONSULTA_MAX, 'UTF-8');
        if ($consulta === '') {
            return $nada;
        }
        $indice = self::indice($courseid);
        if ($indice === null || $indice['fuentes'] <= 0) {
            return $nada;
        }

        $r = self::llamar(self::RUTA_BUSCAR, ['consulta' => $consulta, 'k' => self::K], $courseid, self::ESPERA_BUSCAR_S);
        [$http, $datos] = epica_client::normalize_response($r);
        if (epica_client::es_transitorio($r)) {
            error_log('Pulso fuentes: busqueda no disponible: ' . epica_client::describir_transitorio($r));
            return $nada;
        }
        if ($http !== 200) {
            error_log('Pulso fuentes: busqueda HTTP ' . $http . ' motivo ' . self::codigo($datos['motivo'] ?? ''));
            return $nada;
        }
        return ['hecha' => true, 'fragmentos' => self::normalizar_resultados($datos['resultados'] ?? [])];
    }

    /**
     * Solo los campos conocidos, con tipos coaccionados y recortes con mb_*: nombre, unidad, desde, hasta,
     * inicio y fin (vídeo), ruta y texto. Texto a ~1.500 caracteres por fragmento y ~8.000 en total.
     *
     * @param mixed $resultados `resultados` de la respuesta de Épica.
     * @return array Lista de fragmentos normalizados (sin los que no traen texto).
     */
    public static function normalizar_resultados($resultados): array {
        if (!is_array($resultados)) {
            return [];
        }
        $salida = [];
        $restante = self::TOTAL_MAX;
        foreach (array_slice(array_values($resultados), 0, self::K) as $res) {
            if (!is_array($res) || !is_string($res['texto'] ?? null)) {
                continue;
            }
            $texto = trim(self::limpiar($res['texto']));
            if ($texto === '') {
                continue;
            }
            if ($restante < 200) {
                break;
            }
            $texto = self::recortar($texto, min(self::FRAGMENTO_MAX, $restante));
            $restante -= mb_strlen($texto, 'UTF-8');

            $nombre = is_string($res['nombre'] ?? null) ? trim(preg_replace('/\s+/u', ' ', self::limpiar($res['nombre'])) ?? '') : '';
            $unidad = is_string($res['unidad'] ?? null) ? strtolower($res['unidad']) : '';
            $ruta = [];
            foreach (is_array($res['ruta'] ?? null) ? array_slice(array_values($res['ruta']), 0, 4) : [] as $nivel) {
                if (is_string($nivel) && trim($nivel) !== '') {
                    $ruta[] = self::recortar(trim(preg_replace('/\s+/u', ' ', self::limpiar($nivel)) ?? ''), self::RUTA_NIVEL_MAX);
                }
            }
            $salida[] = [
                'nombre' => $nombre !== '' ? self::recortar($nombre, self::NOMBRE_MAX) : 'Documento',
                'unidad' => preg_match('/^[a-z]{1,20}\z/', $unidad) === 1 ? $unidad : '',
                'desde' => self::entero($res['desde'] ?? null),
                'hasta' => self::entero($res['hasta'] ?? null),
                'inicio' => self::entero($res['inicio'] ?? null),
                'fin' => self::entero($res['fin'] ?? null),
                'ruta' => implode(' › ', $ruta),
                'texto' => $texto,
            ];
        }
        return $salida;
    }

    // ----------------------------------------------------------------
    // Cita y formato para el prompt.
    // ----------------------------------------------------------------

    /** ¿Es la transcripción de un vídeo o audio? (unidad `tramo`, con `inicio` en segundos) */
    public static function es_video(array $f): bool {
        return ($f['unidad'] ?? '') === 'tramo';
    }

    /**
     * «Nombre, p. 12», «Nombre, diapositiva 3» o, en vídeo, «Nombre, 12:30» (de `inicio`).
     */
    public static function cita(array $f): string {
        $nombre = (string)($f['nombre'] ?? 'Documento');
        $unidad = (string)($f['unidad'] ?? '');
        $desde = $f['desde'] ?? null;
        $hasta = $f['hasta'] ?? null;
        $inicio = $f['inicio'] ?? null;

        if (self::es_video($f) && $inicio !== null) {
            $h = intdiv($inicio, 3600);
            $m = intdiv($inicio % 3600, 60);
            $s = $inicio % 60;
            return $nombre . ', ' . ($h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s));
        }
        if ($desde === null) {
            return $nombre;
        }
        $rango = ($hasta !== null && $hasta > $desde) ? $desde . '–' . $hasta : (string)$desde;
        if ($unidad === 'pagina') {
            return $nombre . ', ' . ($rango === (string)$desde ? 'p. ' : 'pp. ') . $rango;
        }
        if ($unidad === 'diapositiva') {
            return $nombre . ', ' . ($rango === (string)$desde ? 'diapositiva ' : 'diapositivas ') . $rango;
        }
        return $nombre . ($unidad !== '' ? ', ' . $unidad . ' ' . $rango : '');
    }

    /**
     * Fragmentos delimitados y con su cita, listos para el bloque dinámico del system. El texto ya
     * viene sin `<<<`/`>>>`, así que no puede cerrar el delimitador ni abrir uno falso.
     *
     * @param array $fragmentos Salida de buscar()['fragmentos'].
     * @return string '' si no hay fragmentos.
     */
    public static function formatear(array $fragmentos): string {
        $salida = '';
        foreach (array_values($fragmentos) as $i => $f) {
            $salida .= '<<<FRAGMENTO ' . ($i + 1) . ">>>\n";
            $salida .= 'Cita: ' . self::cita($f) . "\n";
            if (self::es_video($f)) {
                $salida .= "Tipo: transcripción automática de vídeo\n";
            }
            if (($f['ruta'] ?? '') !== '') {
                $salida .= 'Ruta: ' . $f['ruta'] . "\n";
            }
            $salida .= $f['texto'] . "\n<<<FIN>>>\n";
        }
        return $salida;
    }

    // ----------------------------------------------------------------
    // Transporte.
    // ----------------------------------------------------------------

    /**
     * POST a una puerta de fuentes con un token NUEVO. Nunca lanza: un fallo de firma sale como
     * respuesta de transporte (errno), igual que un timeout.
     *
     * @return array Respuesta de pedir() (errno, error, http, datos, crudo).
     */
    private static function llamar(string $ruta, array $cuerpo, int $courseid, int $espera): array {
        global $USER;
        $fallo = ['errno' => 1, 'error' => '', 'http' => 0, 'datos' => null, 'crudo' => ''];
        try {
            if (empty($USER->email)) {
                // Sin correo no hay token que firmar (firmar_por() no lo comprueba).
                return ['error' => 'usuario sin correo'] + $fallo;
            }
            $contexto = \context_course::instance($courseid);
            $token = \local_awkepica\epica::firmar_por($USER, $contexto, epica_client::CAPABILITY_ROL, (string)$courseid);
            // pedir() no comprueba json_encode(): UTF-8 inválido mandaría un cuerpo vacío.
            $cuerpo = ['token' => $token] + $cuerpo;
            if (json_encode($cuerpo) === false) {
                return ['error' => 'cuerpo no codificable'] + $fallo;
            }
            return \local_awkepica\epica::pedir(epica_client::endpoint($ruta), $cuerpo, $espera);
        } catch (\Throwable $e) {
            // Solo la clase: el mensaje podría arrastrar el token o texto del material.
            error_log('Pulso fuentes: fallo al llamar a ' . $ruta . ': ' . get_class($e));
            return ['error' => get_class($e)] + $fallo;
        }
    }

    // ----------------------------------------------------------------
    // Utilidades.
    // ----------------------------------------------------------------

    /** Caché del índice. Si la definición aún no existe (falta Notificaciones) → null y se llama siempre. */
    private static function cache(): ?\cache {
        try {
            return \cache::make('block_pulso', self::CACHE_AREA);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function cache_get(?\cache $cache, string $clave) {
        try {
            return $cache === null ? false : $cache->get($clave);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function cache_set(?\cache $cache, string $clave, array $valor): void {
        try {
            if ($cache !== null) {
                $cache->set($clave, $valor);
            }
        } catch (\Throwable $e) {
            // Sin caché el servicio sigue funcionando.
            unset($e);
        }
    }

    /** Código de motivo de Épica apto para el log: solo la forma `^[a-z][a-z0-9-]{0,63}$`. */
    private static function codigo($motivo): string {
        return is_string($motivo) && preg_match('/^[a-z][a-z0-9-]{0,63}\z/', $motivo) === 1 ? $motivo : '-';
    }

    private static function entero($v): ?int {
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) && (float)$v >= 0 && (float)$v < 1e9
            ? (int)floor((float)$v) : null;
    }

    /** Espacios en blanco colapsados y sin caracteres de control. */
    private static function compactar(string $t): string {
        return trim(preg_replace('/\s+/u', ' ', self::limpiar($t)) ?? '');
    }

    /**
     * UTF-8 válido, sin caracteres de control (salvo salto de línea y tabulador) y sin tiradas de `<<<`/`>>>`
     * (los delimitadores del prompt).
     */
    private static function limpiar(string $t): string {
        $t = mb_scrub($t, 'UTF-8');
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t) ?? '';
        $t = preg_replace('/<{3,}/', '<<', $t) ?? '';
        return preg_replace('/>{3,}/', '>>', $t) ?? '';
    }

    /** Recorte con mb_*, hasta una frontera de palabra si cae en el último 20 % (nunca a mitad de una). */
    private static function recortar(string $t, int $max): string {
        if (mb_strlen($t, 'UTF-8') <= $max) {
            return $t;
        }
        $corte = mb_substr($t, 0, $max, 'UTF-8');
        if (preg_match('/^(.*)\s\S*\z/su', $corte, $m) === 1 && mb_strlen($m[1], 'UTF-8') > $max * 0.8) {
            $corte = $m[1];
        }
        return rtrim($corte) . '…';
    }
}
