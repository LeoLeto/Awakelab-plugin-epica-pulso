<?php
/**
 * Ciclo con Epica (paso 3): construir el sobre, encargar, sondear y recoger
 * la infografia. Todo esto lo llama SOLO la tarea adhoc
 * (classes/task/epica_ciclo_adhoc.php) — nunca una peticion web. Firmar,
 * encargar y sondear son trabajo de cron; el navegador nunca habla con Epica.
 *
 * Contrato resumido (ver CLAUDE.md y PROMPT_EPICA_03_TAREA_Y_CICLO.md):
 *   POST /api/moodle/laminas/encargar -> 202 {plataforma, trabajo, posicion, estado}
 *   POST /api/moodle/laminas/encargo  -> 200 siempre, {estado, posicion, traza, ...}
 * El cuerpo es PLANO (nada anidado bajo "encargo") y firmado con local_awkepica,
 * nunca a mano: epica::rol_de()/firmar_por()/pedir()/ESPERA_LARGA_S.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/creation_quota.php');
require_once(__DIR__ . '/full_text_store.php');
require_once(__DIR__ . '/content_extractor.php');

class epica_client {

    /** @var string Unica herramienta que manda Pulso en v1 (infografias). */
    const HERRAMIENTA = 'infografias';

    /** @var string Capacidad usada para resolver el rol firmado (rol_de/firmar_por). */
    const CAPABILITY = 'block/pulso:createactivity';

    const RUTA_ENCARGAR = '/api/moodle/laminas/encargar';
    const RUTA_ENCARGO  = '/api/moodle/laminas/encargo';

    /**
     * Objetivo de recorte del material: el contrato permite hasta 300.000
     * caracteres, pero Epica pide recortar bastante antes (no mejora el PNG y
     * encarece la generacion). 300.000 sigue siendo el tope duro por si algun
     * dia se sube el objetivo.
     */
    const MATERIAL_SOFT_LIMIT = 100000;
    const MATERIAL_HARD_LIMIT = 300000;

    // Cadencia de sondeo recomendada por Epica, guiada por "posicion" y no por
    // reloj (ver PROMPT_EPICA_03). En segundos.
    const POLL_QUEUED_MIN = 10;
    const POLL_QUEUED_PER_POSITION = 20;
    const POLL_QUEUED_MAX = 60;
    const POLL_WORKING = 15;

    /**
     * Los 30 minutos son cortesia para el usuario, no un limite de Epica: el
     * trabajo sigue vivo y recogible 7 dias. Pasado esto, se espacia el
     * sondeo en vez de seguir en primer plano.
     */
    const FOREGROUND_WINDOW_S = 30 * MINSECS;
    const BACKGROUND_POLL_S = 5 * MINSECS;

    /** @var int Sondeos seguidos sin bajar de posicion antes de dejar constancia. */
    const STALL_NOTICE_THRESHOLD = 3;

    /**
     * Punto de entrada de la tarea adhoc: hace UN paso segun el estado actual
     * del encargo y dice si hay que reencolar (y con que retraso). Nunca
     * duerme ni hace más de un paso por llamada.
     *
     * @param \stdClass $encargo Fila de block_pulso_encargos.
     * @return array{requeue: bool, delay: int}
     */
    public static function procesar_paso(\stdClass $encargo): array {
        switch ($encargo->status) {
            case creation_quota::STATUS_PENDING:
                return self::paso_pendiente($encargo);
            case 'encolado':
            case 'trabajando':
                return self::paso_sondeo($encargo);
            default:
                // Estado terminal (listo/fallado/desconocido/ensayo): nada que hacer.
                return ['requeue' => false, 'delay' => 0];
        }
    }

    // ----------------------------------------------------------------
    // Paso "pendiente": construir el sobre y llamar a /encargar.
    // ----------------------------------------------------------------

    private static function paso_pendiente(\stdClass $encargo): array {
        global $DB;

        $course = get_course((int)$encargo->courseid);
        $textrow = full_text_store::get_resource_text((int)$encargo->courseid, (int)$encargo->cmid);
        if ($textrow === null) {
            self::marcar_fallo($encargo, 'El recurso ya no tiene contenido disponible para generar la infografía.');
            return ['requeue' => false, 'delay' => 0];
        }

        $user = \core_user::get_user((int)$encargo->userid, '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted)) {
            self::marcar_fallo($encargo, 'El usuario que hizo el encargo ya no existe.');
            return ['requeue' => false, 'delay' => 0];
        }

        $envelope = self::build_envelope($encargo, $course, $textrow, $user);

        if (!empty(get_config('block_pulso', 'epica_dry_run'))) {
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $encargo->id,
                'status' => 'ensayo',
                'sobre_json' => json_encode($envelope, JSON_UNESCAPED_UNICODE),
                'pollcount' => (int)$encargo->pollcount + 1,
                'timemodified' => time(),
            ]);
            mtrace("Pulso Epica (ensayo): encargo {$encargo->id} — sobre construido y registrado, no enviado.");
            return ['requeue' => false, 'delay' => 0];
        }

        if (!class_exists('\local_awkepica\epica')) {
            self::marcar_fallo($encargo, 'local_awkepica no está disponible en este sitio.');
            return ['requeue' => false, 'delay' => 0];
        }

        try {
            $context = \context_course::instance((int)$encargo->courseid);
            $token = \local_awkepica\epica::firmar_por($user, $context, self::CAPABILITY, $course);
            $body = array_merge(['token' => $token], $envelope);
            $respuesta = \local_awkepica\epica::pedir(
                self::endpoint(self::RUTA_ENCARGAR),
                $body,
                \local_awkepica\epica::ESPERA_LARGA_S
            );
        } catch (\Throwable $e) {
            error_log('Pulso Epica: fallo de red al encargar #' . $encargo->id . ': ' . $e->getMessage());
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $encargo->id,
                'pollcount' => (int)$encargo->pollcount + 1,
                'timemodified' => time(),
            ]);
            return ['requeue' => true, 'delay' => 60];
        }

        [$httpcode, $data] = self::normalize_response($respuesta);

        if ($httpcode === 202) {
            $posicion = (int)($data['posicion'] ?? 0);
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $encargo->id,
                'status' => 'encolado',
                'epica_job_id' => (string)($data['trabajo'] ?? ''),
                'epica_plataforma' => (string)($data['plataforma'] ?? ''),
                'epica_posicion' => $posicion,
                'last_posicion' => $posicion,
                'timequeued' => time(),
                'pollcount' => (int)$encargo->pollcount + 1,
                'timemodified' => time(),
            ]);
            return ['requeue' => true, 'delay' => self::next_delay('en-cola', $posicion, time())];
        }

        return self::procesar_error_encargar($encargo, $httpcode, $data);
    }

    /**
     * Errores de /encargar. 429 (cuota-agotada o cuota-del-centro) es
     * transitorio: se reencola con el Retry-After que dan. El resto
     * (material-ilegible, material-excesivo, origen-contradictorio,
     * rol-sin-permiso, herramienta-no-contratada…) no mejora solo: fallo
     * terminal, sin reintentos.
     *
     * @return array{requeue: bool, delay: int}
     */
    private static function procesar_error_encargar(\stdClass $encargo, int $httpcode, array $data): array {
        global $DB;
        $motivo = (string)($data['motivo'] ?? '');

        if ($httpcode === 429) {
            $espera = self::retry_after($data);
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $encargo->id,
                'pollcount' => (int)$encargo->pollcount + 1,
                'timemodified' => time(),
            ]);
            mtrace("Pulso Epica: encargo {$encargo->id} recibió 429 ({$motivo}); reintentando en {$espera}s.");
            return ['requeue' => true, 'delay' => $espera];
        }

        self::marcar_fallo($encargo, $motivo !== '' ? $motivo : "Épica devolvió {$httpcode} al encargar.", $data['traza'] ?? null);
        return ['requeue' => false, 'delay' => 0];
    }

    // ----------------------------------------------------------------
    // Paso "encolado"/"trabajando": sondear una vez.
    // ----------------------------------------------------------------

    private static function paso_sondeo(\stdClass $encargo): array {
        global $DB;

        if (empty($encargo->epica_job_id)) {
            self::marcar_fallo($encargo, 'El encargo no tiene un trabajo de Épica asociado.');
            return ['requeue' => false, 'delay' => 0];
        }

        $course = get_course((int)$encargo->courseid);
        $user = \core_user::get_user((int)$encargo->userid, '*', IGNORE_MISSING);
        if (!$user) {
            self::marcar_fallo($encargo, 'El usuario que hizo el encargo ya no existe.');
            return ['requeue' => false, 'delay' => 0];
        }

        if (!class_exists('\local_awkepica\epica')) {
            self::marcar_fallo($encargo, 'local_awkepica no está disponible en este sitio.');
            return ['requeue' => false, 'delay' => 0];
        }

        try {
            $context = \context_course::instance((int)$encargo->courseid);
            // Token NUEVO en cada sondeo: el jti se gasta al recibirlo, así
            // que reenviar el mismo token da 401 "reusado".
            $token = \local_awkepica\epica::firmar_por($user, $context, self::CAPABILITY, $course);
            $body = ['token' => $token, 'trabajo' => $encargo->epica_job_id];
            $respuesta = \local_awkepica\epica::pedir(
                self::endpoint(self::RUTA_ENCARGO),
                $body,
                \local_awkepica\epica::ESPERA_LARGA_S
            );
        } catch (\Throwable $e) {
            error_log('Pulso Epica: fallo de red al sondear #' . $encargo->id . ': ' . $e->getMessage());
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $encargo->id,
                'pollcount' => (int)$encargo->pollcount + 1,
                'timemodified' => time(),
            ]);
            return ['requeue' => true, 'delay' => 60];
        }

        [$httpcode, $data] = self::normalize_response($respuesta);

        if ($httpcode !== 200) {
            return self::procesar_error_sondeo($encargo, $httpcode, $data);
        }

        $estado = (string)($data['estado'] ?? 'desconocido');
        $posicion = (int)($data['posicion'] ?? 0);
        $traza = $data['traza'] ?? null;

        if ($estado === 'en-cola') {
            $stallcount = ($posicion >= (int)$encargo->last_posicion) ? (int)$encargo->stall_count + 1 : 0;
            if ($stallcount >= self::STALL_NOTICE_THRESHOLD) {
                mtrace("Pulso Epica: encargo {$encargo->id} lleva {$stallcount} sondeos sin bajar de posición ({$posicion}).");
            }
        } else {
            $stallcount = 0;
        }

        switch ($estado) {
            case 'en-cola':
            case 'trabajando':
                $DB->update_record('block_pulso_encargos', (object)[
                    'id' => $encargo->id,
                    'status' => $estado === 'en-cola' ? 'encolado' : 'trabajando',
                    'epica_posicion' => $posicion,
                    'last_posicion' => $posicion,
                    'stall_count' => $stallcount,
                    'epica_traza' => $traza ? mb_substr((string)$traza, 0, 32, 'UTF-8') : $encargo->epica_traza,
                    'pollcount' => (int)$encargo->pollcount + 1,
                    'timemodified' => time(),
                ]);
                return ['requeue' => true, 'delay' => self::next_delay($estado, $posicion, (int)$encargo->timequeued)];

            case 'listo':
                self::recoger($encargo, $data, $traza);
                return ['requeue' => false, 'delay' => 0];

            case 'fallado':
            case 'desconocido':
            default:
                self::marcar_fallo(
                    $encargo,
                    (string)($data['mensaje'] ?? $data['motivo'] ?? 'Sin motivo especificado.'),
                    $traza,
                    $estado === 'desconocido' ? 'desconocido' : 'fallado'
                );
                return ['requeue' => false, 'delay' => 0];
        }
    }

    private static function procesar_error_sondeo(\stdClass $encargo, int $httpcode, array $data): array {
        global $DB;
        $motivo = (string)($data['motivo'] ?? '');

        if ($httpcode === 429) {
            $espera = self::retry_after($data);
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $encargo->id,
                'pollcount' => (int)$encargo->pollcount + 1,
                'timemodified' => time(),
            ]);
            return ['requeue' => true, 'delay' => $espera];
        }

        self::marcar_fallo($encargo, $motivo !== '' ? $motivo : "Épica devolvió {$httpcode} al sondear.", $data['traza'] ?? null);
        return ['requeue' => false, 'delay' => 0];
    }

    /**
     * Guarda el PNG con la File API de Moodle (nunca en el historial del chat
     * ni en un log: son ~1,7 MB en base64) y cierra el encargo como "listo".
     * Mira siempre "mock": si llega true, el PNG es de relleno pero válido —
     * se guarda y se enseña igual, y queda marcado para decirlo en la interfaz.
     */
    private static function recoger(\stdClass $encargo, array $data, $traza): void {
        global $DB;

        $imagen = (string)($data['imagen'] ?? '');
        $filename = $imagen !== '' ? self::guardar_artefacto($encargo, $imagen) : null;

        $DB->update_record('block_pulso_encargos', (object)[
            'id' => $encargo->id,
            'status' => $filename ? 'listo' : 'fallado',
            'filename' => $filename,
            'titulo' => mb_substr((string)($data['titulo'] ?? ''), 0, 255, 'UTF-8'),
            'tema' => mb_substr((string)($data['tema'] ?? ''), 0, 255, 'UTF-8'),
            'arquetipo' => mb_substr((string)($data['arquetipo'] ?? ''), 0, 100, 'UTF-8'),
            'mock' => !empty($data['mock']) ? 1 : 0,
            'verificado' => isset($data['verificado']) ? (int)(bool)$data['verificado'] : null,
            'avisos' => isset($data['avisos']) ? json_encode($data['avisos'], JSON_UNESCAPED_UNICODE) : null,
            'epica_traza' => $traza ? mb_substr((string)$traza, 0, 32, 'UTF-8') : $encargo->epica_traza,
            'motivo' => $filename ? null : 'La respuesta "listo" no traía una imagen válida.',
            'pollcount' => (int)$encargo->pollcount + 1,
            'timemodified' => time(),
        ]);

        if ($filename) {
            mtrace("Pulso Epica: encargo {$encargo->id} → listo" . (!empty($data['mock']) ? ' (mock)' : '') . '.');
        } else {
            mtrace("Pulso Epica: encargo {$encargo->id} → listo sin imagen válida, se marca como fallado.");
        }
    }

    private static function guardar_artefacto(\stdClass $encargo, string $base64png): ?string {
        $binary = base64_decode($base64png, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $context = \context_course::instance((int)$encargo->courseid);
        $fs = get_file_storage();
        // Idempotente: si esta llamada es un reintento tras un fallo a medio
        // guardar, no deja un fichero viejo huérfano detrás.
        $fs->delete_area_files($context->id, 'block_pulso', 'encargo', $encargo->id);

        $filename = clean_filename('infografia_' . $encargo->id . '.png');
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'block_pulso',
            'filearea'  => 'encargo',
            'itemid'    => $encargo->id,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $binary);

        return $filename;
    }

    private static function marcar_fallo(\stdClass $encargo, string $motivo, $traza = null, string $status = 'fallado'): void {
        global $DB;
        $DB->update_record('block_pulso_encargos', (object)[
            'id' => $encargo->id,
            'status' => $status,
            'motivo' => mb_substr($motivo, 0, 2000, 'UTF-8'),
            'epica_traza' => $traza ? mb_substr((string)$traza, 0, 32, 'UTF-8') : ($encargo->epica_traza ?? null),
            'pollcount' => (int)$encargo->pollcount + 1,
            'timemodified' => time(),
        ]);
        mtrace("Pulso Epica: encargo {$encargo->id} → {$status} ({$motivo}).");
    }

    // ----------------------------------------------------------------
    // El sobre.
    // ----------------------------------------------------------------

    /**
     * Construye el cuerpo del encargo SIN el campo "token" (se añade justo
     * antes de enviar, o se omite en modo de ensayo: firmar de verdad
     * requiere el secreto de local_awkepica, que en ensayo puede no estar
     * configurado todavía).
     */
    private static function build_envelope(\stdClass $encargo, \stdClass $course, array $textrow, \stdClass $user): array {
        return [
            'herramienta' => self::HERRAMIENTA,
            'peticion' => $encargo->prompt,
            'formato' => $encargo->format,
            'contexto' => self::resolve_contexto_seccion((int)$encargo->courseid, (int)$encargo->sectionnum),
            'curso' => [
                'nombre' => trim((string)$course->fullname),
                'nombre_corto' => trim((string)$course->shortname),
                'idioma' => 'es',
            ],
            'alumno' => [
                'grupo' => self::resolve_grupo((int)$encargo->courseid, (int)$user->id),
                'idioma' => 'es',
                'intento' => self::resolve_intento((int)$encargo->userid, (int)$encargo->cmid, (int)$encargo->id),
            ],
            'material' => self::resolve_material($textrow),
        ];
    }

    /**
     * El contexto de sección se manda SIEMPRE, haya material o no: nombre,
     * resumen (texto plano, recortado) y las secciones vecinas visibles.
     */
    private static function resolve_contexto_seccion(int $courseid, int $sectionnum): array {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC',
            'id, section, name, summary, visible');

        $ordered = [];
        $currentkey = null;
        foreach ($sections as $sec) {
            if ((int)$sec->section === 0) {
                continue; // Seccion general del curso, no es un "tema".
            }
            $ordered[] = $sec;
            if ((int)$sec->section === $sectionnum) {
                $currentkey = count($ordered) - 1;
            }
        }

        $current = $currentkey !== null ? $ordered[$currentkey] : null;
        $extractor = new content_extractor();

        $nombre = $current && trim((string)$current->name) !== ''
            ? trim((string)$current->name)
            : ('Sección ' . $sectionnum);

        $resumen = $current ? trim($extractor->html_to_text((string)$current->summary)) : '';
        $resumen = mb_substr($resumen, 0, 600, 'UTF-8');

        $vecinas = [];
        if ($currentkey !== null) {
            if ($currentkey > 0 && !empty($ordered[$currentkey - 1]->visible)) {
                $prev = trim((string)$ordered[$currentkey - 1]->name);
                $vecinas[] = $prev !== '' ? $prev : ('Sección ' . $ordered[$currentkey - 1]->section);
            }
            if (isset($ordered[$currentkey + 1]) && !empty($ordered[$currentkey + 1]->visible)) {
                $next = trim((string)$ordered[$currentkey + 1]->name);
                $vecinas[] = $next !== '' ? $next : ('Sección ' . $ordered[$currentkey + 1]->section);
            }
        }

        return ['seccion' => $nombre, 'resumen' => $resumen, 'vecinas' => $vecinas];
    }

    /**
     * Grupo (de agrupamiento de clase, no de permisos) al que pertenece el
     * usuario en el curso. Sin grupos o sin la API disponible, cadena vacía:
     * el contexto de sección sostiene el tema igualmente.
     */
    private static function resolve_grupo(int $courseid, int $userid): string {
        if (!function_exists('groups_get_user_groups')) {
            return '';
        }
        try {
            $groups = groups_get_user_groups($courseid, $userid);
            $ids = $groups[0] ?? [];
            if (empty($ids)) {
                return '';
            }
            $group = groups_get_group((int)reset($ids));
            return $group ? trim((string)$group->name) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Número de intento de este usuario sobre ESTE recurso concreto (encargos
     * previos, cualquiera que fuese su estado, +1). No es un intento de
     * cuestionario: es cuántas veces ha pedido ya una infografía de este
     * material.
     */
    private static function resolve_intento(int $userid, int $cmid, int $excludeid): int {
        global $DB;
        $count = $DB->count_records_select(
            'block_pulso_encargos',
            'userid = :userid AND cmid = :cmid AND id < :excludeid',
            ['userid' => $userid, 'cmid' => $cmid, 'excludeid' => $excludeid]
        );
        return $count + 1;
    }

    /**
     * "caracteres" y "extraido_por" salen TAL CUAL de block_pulso_full_text
     * (el tamaño original, no el recortado) — es la referencia que decide
     * "truncado". El recorte de "texto" es SIEMPRE con mb_substr y por
     * frontera de párrafo o palabra, nunca a mitad de una: Epica compara el
     * material contra el original para verificar fidelidad, y una frase
     * cortada envenena esa comparación.
     */
    private static function resolve_material(array $textrow): array {
        $texto = $textrow['texto'];
        if (mb_strlen($texto, 'UTF-8') > self::MATERIAL_HARD_LIMIT) {
            $texto = mb_substr($texto, 0, self::MATERIAL_HARD_LIMIT, 'UTF-8');
        }

        $truncado = false;
        if (mb_strlen($texto, 'UTF-8') > self::MATERIAL_SOFT_LIMIT) {
            $texto = self::truncate_at_boundary($texto, self::MATERIAL_SOFT_LIMIT);
            $truncado = true;
        }

        return [
            'formato' => 'texto',
            'nombre' => $textrow['module_name'],
            'texto' => $texto,
            'caracteres' => $textrow['caracteres'],
            'truncado' => $truncado,
            'extraido_por' => $textrow['extraido_por'],
        ];
    }

    private static function truncate_at_boundary(string $texto, int $limit): string {
        $cut = mb_substr($texto, 0, $limit, 'UTF-8');

        $lastparagraph = mb_strrpos($cut, "\n\n", 0, 'UTF-8');
        if ($lastparagraph !== false && $lastparagraph > $limit * 0.5) {
            return mb_substr($cut, 0, $lastparagraph, 'UTF-8');
        }

        $lastspace = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($lastspace !== false) {
            return mb_substr($cut, 0, $lastspace, 'UTF-8');
        }

        return $cut;
    }

    // ----------------------------------------------------------------
    // Transporte.
    // ----------------------------------------------------------------

    private static function endpoint(string $ruta): string {
        $base = rtrim((string)get_config('block_pulso', 'epica_base_url'), '/');
        return $base . $ruta;
    }

    /**
     * epica::pedir() no está documentado en este repo (local_awkepica es una
     * dependencia externa) — se normaliza de forma defensiva para aceptar
     * tanto un array como un stdClass, y un cuerpo ya decodificado o en
     * bruto. AJUSTAR cuando se verifique el shape real contra el secreto de
     * producción (ver memory/session-history.md).
     *
     * @return array{0: int, 1: array}
     */
    private static function normalize_response($respuesta): array {
        if (is_object($respuesta)) {
            $respuesta = (array)$respuesta;
        }
        if (!is_array($respuesta)) {
            return [0, []];
        }

        $httpcode = (int)($respuesta['httpcode'] ?? $respuesta['http_code'] ?? $respuesta['status'] ?? $respuesta['code'] ?? 0);
        $body = $respuesta['body'] ?? $respuesta['data'] ?? $respuesta['response'] ?? $respuesta;

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : [];
        } elseif (is_object($body)) {
            $body = (array)$body;
        } elseif (!is_array($body)) {
            $body = [];
        }

        return [$httpcode, $body];
    }

    private static function retry_after(array $data): int {
        $val = (int)($data['retry_after'] ?? $data['reintentar_en'] ?? $data['Retry-After'] ?? 0);
        return $val > 0 ? $val : 60;
    }

    private static function next_delay(string $estado, int $posicion, int $timequeued): int {
        if ($timequeued > 0 && (time() - $timequeued) > self::FOREGROUND_WINDOW_S) {
            return self::BACKGROUND_POLL_S;
        }
        if ($estado === 'en-cola') {
            return min(max(self::POLL_QUEUED_MIN, $posicion * self::POLL_QUEUED_PER_POSITION), self::POLL_QUEUED_MAX);
        }
        return self::POLL_WORKING;
    }
}
