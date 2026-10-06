<?php
/**
 * Retos (Epica): proponer seis retos, elegir uno y recibir el ENLACE al reto,
 * que se resuelve y se corrige en Epica. A diferencia de las laminas y los
 * juegos, Retos se llama desde la PETICION WEB y no desde una tarea adhoc:
 * Epica lo aprobo (carta 8 §1) porque todas sus puertas contestan enseguida —
 * lo lento ocurre en su cola — y el usuario esta delante esperando para elegir.
 * El navegador sondea NUESTRO endpoint (api_retos.php); nunca habla con Epica.
 *
 * Reglas (ver CLAUDE.md, "Retos — paso 1", y docs/epica_retos_carta7/8.md):
 *  - Token NUEVO en cada llamada, tambien en cada sondeo (el jti se gasta al
 *    recibirlo): reintentar es firmar otro token.
 *  - Ningun id que venga del cliente se usa sin comprobar que la fila es de ESE
 *    usuario y curso; el cmid se recalcula contra get_resources_context().
 *  - Los topes propios se comprueban ANTES de llamar a Epica (el cupo de Epica
 *    se gasta al admitir, no al entregar).
 *  - Lo que viene de Epica se guarda tal cual (solo con los campos conocidos y
 *    tipos coaccionados) y se escapa al pintarlo; los enlaces se validan.
 *  - Nada de material ni de token en logs.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/reto_error.php');
require_once(__DIR__ . '/epica_client.php');
require_once(__DIR__ . '/creation_quota.php');
require_once(__DIR__ . '/full_text_store.php');
require_once(__DIR__ . '/chat_pipeline.php');

class retos_service {

    const RUTA_PROPONER  = '/api/moodle/retos/proponer';
    const RUTA_PROPUESTA = '/api/moodle/retos/propuesta';
    const RUTA_ELEGIR    = '/api/moodle/retos/elegir';
    const RUTA_RETO      = '/api/moodle/retos/reto';
    const RUTA_CURSO     = '/api/moodle/retos/curso';

    const ESTADO_EN_COLA = 'en-cola';
    const ESTADO_TRABAJANDO = 'trabajando';
    const ESTADO_LISTO = 'listo';
    const ESTADO_FALLADO = 'fallado';
    const ESTADO_DESCONOCIDO = 'desconocido';

    /** @var int Tope del tema (contrato, retos.ts:76). */
    const TEMA_MAX = 500;
    /** @var int Limites del reto propio (contrato, retos.ts:78-79). */
    const PROPIO_MIN = 8;
    const PROPIO_MAX = 140;

    /** @var int Una propuesta caduca en Epica a los 7 dias (carta 7 §4). */
    const PROPUESTA_VIDA_S = 7 * DAYSECS;

    /** @var int Tope de retos (y de propuestas pendientes) en «Mis creaciones»; igual que api_create_status.php. */
    const MIS_RETOS_MAX = 24;

    /** @var int Candado por usuario: la peticion mas larga es proponer con material (espera larga) + margen. */
    const CANDADO_VIDA_S = 300;

    // ----------------------------------------------------------------
    // 1. proponer
    // ----------------------------------------------------------------

    /**
     * @param \stdClass $user Registro completo del usuario (core_user::get_user).
     * @param int $propuestaid Nuestro id de una propuesta anterior ("proponer otros"); 0 si no.
     * @return array{propuesta: int, estado: string, posicion: int}
     */
    public static function proponer(\stdClass $user, int $courseid, int $cmid, string $tema, int $propuestaid): array {
        global $DB;
        self::asegurar_tablas();

        $course = get_course($courseid);
        $tema = trim($tema);
        if (mb_strlen($tema, 'UTF-8') > self::TEMA_MAX) {
            throw new reto_error('tema-largo', 'El tema es demasiado largo (máximo ' . self::TEMA_MAX . ' caracteres).');
        }

        $cuerpo = [];
        $espera = null;
        $cmidfila = null;

        if ($propuestaid > 0) {
            // «Proponer otros»: SOLO la propuesta (con material o tema es 400 encargo-ambiguo).
            if ($cmid > 0 || $tema !== '') {
                throw new reto_error('encargo-ambiguo',
                    'Para pedir otros retos no hay que indicar recurso ni tema: se reutiliza la propuesta anterior.');
            }
            $padre = self::cargar_propuesta($propuestaid, (int)$user->id, $courseid);
            self::exigir_propuesta_vigente($padre);
            if ($padre->estado !== self::ESTADO_LISTO) {
                throw new reto_error('propuesta-no-lista', 'Esa propuesta todavía no está lista.');
            }
            $cuerpo['propuesta'] = (string)$padre->epica_propuesta;
            // La nueva propuesta hereda recurso y tema: es la misma fuente.
            $cmidfila = $padre->cmid !== null ? (int)$padre->cmid : null;
            $tema = (string)$padre->tema;
        } else if ($cmid > 0) {
            // El cmid se RECALCULA: visible de verdad para este usuario y con texto aprovechable.
            $recurso = self::recurso_disponible($courseid, (int)$user->id, $cmid);
            $textrow = full_text_store::get_resource_text($courseid, $cmid);
            if ($recurso === null || $textrow === null) {
                throw new reto_error('recurso-no-disponible', 'Ese recurso ya no está disponible para crear retos con Pulse.');
            }
            $cuerpo['material'] = epica_client::resolve_material($textrow);
            $cuerpo['contexto'] = epica_client::resolve_contexto_seccion($courseid, (int)$recurso['sectionnum']);
            $cuerpo['curso'] = self::sobre_curso($course);
            $cuerpo['alumno'] = self::sobre_alumno($courseid, $user, $cmid);
            if ($tema !== '') {
                $cuerpo['tema'] = $tema; // Con material, el tema acota dentro de él.
            }
            $cmidfila = $cmid;
            $espera = epica_client::espera_larga(); // Lleva material.
        } else {
            if ($tema === '' || !preg_match('/[\p{L}\p{N}]/u', $tema)) {
                throw new reto_error('encargo-sin-tema', 'Elige un recurso o escribe un tema para los retos.');
            }
            $cuerpo['tema'] = $tema;
            $cuerpo['curso'] = self::sobre_curso($course);
            $cuerpo['alumno'] = self::sobre_alumno($courseid, $user, 0);
        }

        return self::con_candado($user, $courseid, function() use ($DB, $user, $courseid, $course, $cuerpo, $espera, $cmidfila, $tema) {
            self::comprobar_tope_propuestas((int)$user->id);

            [$http, $datos] = self::llamar(self::RUTA_PROPONER, $cuerpo, $user, $course, $espera);
            if ($http !== 202) {
                throw self::traducir_error($http, $datos);
            }
            $epicaid = trim((string)($datos['propuesta'] ?? ''));
            if ($epicaid === '') {
                throw new reto_error('respuesta-inesperada', 'El servicio de retos aceptó la petición pero no devolvió el identificador de la propuesta.', 502);
            }

            $estado = self::estado_conocido($datos['estado'] ?? '', self::ESTADO_EN_COLA);
            $posicion = max(0, (int)($datos['posicion'] ?? 0));
            $now = time();
            $id = $DB->insert_record('block_pulso_reto_propuestas', (object)[
                'userid' => (int)$user->id,
                'courseid' => $courseid,
                'cmid' => $cmidfila,
                'tema' => $tema,
                'epica_propuesta' => mb_substr($epicaid, 0, 100, 'UTF-8'),
                'estado' => $estado,
                'posicion' => $posicion,
                'documento_json' => null,
                'retos_json' => null,
                'motivo' => null,
                'timetrabajando' => $estado === self::ESTADO_TRABAJANDO ? $now : null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            return ['propuesta' => (int)$id, 'estado' => $estado, 'posicion' => $posicion];
        });
    }

    // ----------------------------------------------------------------
    // 2. propuesta (sondeo)
    // ----------------------------------------------------------------

    /**
     * Sondeo. El servidor NO corta mientras la propuesta este en cola (carta 8
     * §1): devuelve `trabajando_desde` (segundos desde el primer "trabajando")
     * para que el cliente cuente SU limite de 2 minutos desde ahi, no desde
     * que se pulso el boton. fallado y desconocido son terminales.
     */
    public static function consultar_propuesta(\stdClass $user, int $courseid, int $propuestaid): array {
        global $DB;
        self::asegurar_tablas();

        $row = self::cargar_propuesta($propuestaid, (int)$user->id, $courseid);

        // Terminal en nuestra tabla (listo/fallado/desconocido): no se vuelve a llamar a Épica.
        if (in_array($row->estado, [self::ESTADO_LISTO, self::ESTADO_FALLADO, self::ESTADO_DESCONOCIDO], true)) {
            return ['propuesta' => self::payload_propuesta($row)];
        }

        $course = get_course($courseid);
        [$http, $datos] = self::llamar(self::RUTA_PROPUESTA, ['propuesta' => (string)$row->epica_propuesta], $user, $course);
        if ($http !== 200) {
            $error = self::traducir_error($http, $datos);
            if ($error->motivo === 'propuesta-desconocida') {
                // Caducó o no existe: ya no sirve para nada (igual que en elegir).
                $DB->update_record('block_pulso_reto_propuestas', (object)[
                    'id' => $row->id,
                    'estado' => self::ESTADO_DESCONOCIDO,
                    'motivo' => 'Esta propuesta ya no está disponible (caducó o no es de este centro).',
                    'timemodified' => time(),
                ]);
            }
            throw $error;
        }

        $estado = (string)($datos['estado'] ?? '');
        $traza = $datos['traza'] ?? null;
        $upd = (object)['id' => $row->id, 'timemodified' => time()];

        switch ($estado) {
            case self::ESTADO_LISTO:
                $retos = self::normalizar_retos($datos['retos'] ?? null);
                if (empty($retos)) {
                    $upd->estado = self::ESTADO_FALLADO;
                    $upd->motivo = 'La propuesta llegó sin retos.';
                    self::registrar_traza('propuesta', $row->id, $upd->motivo, $traza);
                    break;
                }
                $upd->estado = self::ESTADO_LISTO;
                $upd->posicion = 0;
                $upd->motivo = null;
                $upd->documento_json = self::json(self::normalizar_documento($datos['documento'] ?? null));
                $upd->retos_json = self::json($retos);
                break;

            case self::ESTADO_EN_COLA:
            case self::ESTADO_TRABAJANDO:
                $upd->estado = $estado;
                $upd->posicion = max(0, (int)($datos['posicion'] ?? 0));
                if ($estado === self::ESTADO_TRABAJANDO && empty($row->timetrabajando)) {
                    $upd->timetrabajando = time(); // El limite de 2 min cuenta desde aqui.
                }
                break;

            case self::ESTADO_FALLADO:
                $upd->estado = self::ESTADO_FALLADO;
                $upd->motivo = self::texto_corto($datos['mensaje'] ?? $datos['motivo'] ?? 'Sin motivo especificado.', 2000);
                self::registrar_traza('propuesta', $row->id, $upd->motivo, $traza);
                break;

            case self::ESTADO_DESCONOCIDO:
                $upd->estado = self::ESTADO_DESCONOCIDO;
                $upd->motivo = 'Esta propuesta ya no está disponible (ha caducado o no es de este centro). Pide una nueva.';
                self::registrar_traza('propuesta', $row->id, 'desconocido', $traza);
                break;

            default:
                throw new reto_error('respuesta-inesperada', 'El servicio de retos contestó con un estado que no esperábamos.', 502, ['reintentable' => true]);
        }

        $DB->update_record('block_pulso_reto_propuestas', $upd);
        return ['propuesta' => self::payload_propuesta($DB->get_record('block_pulso_reto_propuestas', ['id' => $row->id], '*', MUST_EXIST))];
    }

    // ----------------------------------------------------------------
    // 3. elegir
    // ----------------------------------------------------------------

    /**
     * @param string $reto Uno de los seis ids GUARDADOS (si no esta, error: nunca "el primero").
     * @param string $propio Reto escrito por la persona (8–140 caracteres), alternativo a $reto.
     * @return array{codigo: string, enlace: string, enlace_curso: string, titulo: string}
     */
    public static function elegir(\stdClass $user, int $courseid, int $propuestaid, string $reto, string $propio): array {
        global $DB;
        self::asegurar_tablas();

        $reto = trim($reto);
        $propio = trim($propio);
        if (($reto === '') === ($propio === '')) {
            throw new reto_error('encargo-ambiguo', 'Elige uno de los retos propuestos o escribe el tuyo, pero no los dos a la vez.');
        }
        if ($propio !== '') {
            $len = mb_strlen($propio, 'UTF-8');
            if ($len < self::PROPIO_MIN) {
                throw new reto_error('encargo-sin-tema', 'Describe tu reto con al menos ' . self::PROPIO_MIN . ' caracteres.');
            }
            if ($len > self::PROPIO_MAX) {
                throw new reto_error('propio-largo', 'Tu reto es demasiado largo (máximo ' . self::PROPIO_MAX . ' caracteres).');
            }
        }

        $row = self::cargar_propuesta($propuestaid, (int)$user->id, $courseid);
        self::exigir_propuesta_vigente($row);
        if ($row->estado !== self::ESTADO_LISTO) {
            throw new reto_error('propuesta-no-lista', 'Esa propuesta todavía no está lista para elegir.');
        }

        $titulopropuesto = '';
        if ($reto !== '') {
            $encontrado = false;
            foreach (self::decodificar_lista($row->retos_json) as $candidato) {
                if (($candidato['id'] ?? null) === $reto) {
                    $encontrado = true;
                    $titulopropuesto = (string)($candidato['titulo'] ?? '');
                    break;
                }
            }
            if (!$encontrado) {
                throw new reto_error('reto-desconocido', 'Ese reto no estaba entre los propuestos. Vuelve a elegir uno de la lista.');
            }
        }

        $course = get_course($courseid);
        $cuerpo = ['propuesta' => (string)$row->epica_propuesta];
        if ($reto !== '') {
            $cuerpo['reto'] = $reto;
        } else {
            $cuerpo['propio'] = $propio;
        }

        return self::con_candado($user, $courseid, function() use ($DB, $user, $courseid, $course, $cuerpo, $row, $propio, $titulopropuesto) {
            self::comprobar_topes_elegir((int)$user->id, $courseid);

            [$http, $datos] = self::llamar(self::RUTA_ELEGIR, $cuerpo, $user, $course);
            if ($http !== 202) {
                $error = self::traducir_error($http, $datos);
                if ($error->motivo === 'propuesta-desconocida') {
                    // Caducó o no existe: ya no sirve para elegir nunca más.
                    $DB->update_record('block_pulso_reto_propuestas', (object)[
                        'id' => $row->id,
                        'estado' => self::ESTADO_DESCONOCIDO,
                        'motivo' => 'Esta propuesta ya no está disponible (caducó o no es de este centro).',
                        'timemodified' => time(),
                    ]);
                }
                throw $error;
            }

            $codigo = trim((string)($datos['codigo'] ?? ''));
            $enlace = self::enlace_epica($datos['enlace'] ?? '');
            if ($codigo === '' || mb_strlen($codigo, 'UTF-8') > 64 || $enlace === '') {
                throw new reto_error('respuesta-inesperada',
                    'El servicio de retos aceptó la elección pero no devolvió un enlace válido al reto.', 502);
            }
            $enlacecurso = self::enlace_epica($datos['enlace_curso'] ?? '');
            // El del reto elegido es el PROPUESTO: el modelo le pondrá su propio título al escribirlo (refrescar lo recoge).
            $titulo = self::texto_corto($datos['titulo'] ?? '', 255);
            if ($titulo === '') {
                $titulo = $propio !== '' ? self::texto_corto($propio, 255) : self::texto_corto($titulopropuesto, 255);
            }

            $now = time();
            $DB->insert_record('block_pulso_retos', (object)[
                'userid' => (int)$user->id,
                'courseid' => $courseid,
                'propuestaid' => (int)$row->id,
                'codigo' => $codigo,
                'enlace' => $enlace,
                'enlace_curso' => $enlacecurso,
                'titulo' => $titulo,
                'titulo_final' => '',
                'propio' => $propio !== '' ? 1 : 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            return ['codigo' => $codigo, 'enlace' => $enlace, 'enlace_curso' => $enlacecurso, 'titulo' => $titulo];
        });
    }

    // ----------------------------------------------------------------
    // 4. refrescar
    // ----------------------------------------------------------------

    /**
     * Estado de UN reto del usuario. Si esta listo guarda `titulo_final` (el
     * que ve la pagina del reto, distinto del propuesto — carta 8). Los
     * numeros de intentos/nota son de CUALQUIERA que abrio el reto, nunca de
     * quien lo pidio (carta 8 §2): solo se dan a quien tiene viewanalytics.
     */
    public static function refrescar(\stdClass $user, int $courseid, string $codigo): array {
        global $DB;
        self::asegurar_tablas();

        $row = $DB->get_record('block_pulso_retos', ['codigo' => $codigo, 'userid' => (int)$user->id, 'courseid' => $courseid]);
        if (!$row) {
            throw new reto_error('reto-desconocido', 'No encontramos ese reto entre los tuyos en este curso.', 404);
        }

        $course = get_course($courseid);
        [$http, $datos] = self::llamar(self::RUTA_RETO, ['codigo' => (string)$row->codigo], $user, $course);
        if ($http !== 200) {
            throw self::traducir_error($http, $datos);
        }

        $estado = self::estado_conocido($datos['estado'] ?? '', self::ESTADO_DESCONOCIDO);
        $final = (string)$row->titulo_final;
        if ($estado === self::ESTADO_LISTO) {
            $nuevo = self::texto_corto($datos['titulo'] ?? '', 255);
            if ($nuevo !== '' && $nuevo !== $final) {
                $final = $nuevo;
                $DB->update_record('block_pulso_retos', (object)['id' => $row->id, 'titulo_final' => $final, 'timemodified' => time()]);
            }
        }

        $out = [
            'estado' => $estado,
            'titulo' => $final !== '' ? $final : (string)$row->titulo,
            'enlace' => (string)$row->enlace,
        ];
        if ($estado === self::ESTADO_FALLADO) {
            $out['motivo'] = self::texto_corto($datos['mensaje'] ?? $datos['motivo'] ?? '', 500);
            self::registrar_traza('reto', $row->id, $out['motivo'], $datos['traza'] ?? null);
        }

        if ($estado === self::ESTADO_LISTO && chat_pipeline::user_can_view_analytics($courseid)) {
            $out['intentos'] = max(0, (int)($datos['intentos'] ?? 0));
            $out['analizados'] = max(0, (int)($datos['analizados'] ?? 0));
            $out['ultimaPuntuacion'] = isset($datos['ultimaPuntuacion']) && is_numeric($datos['ultimaPuntuacion'])
                ? (int)$datos['ultimaPuntuacion'] : null;
        }
        return $out;
    }

    // ----------------------------------------------------------------
    // 5. curso
    // ----------------------------------------------------------------

    /** La pagina de retos del curso (Épica) y su lista, sin nada más. Sin curso → lista vacía. */
    public static function curso(\stdClass $user, int $courseid): array {
        self::asegurar_tablas();

        $course = get_course($courseid);
        [$http, $datos] = self::llamar(self::RUTA_CURSO, [], $user, $course);
        if ($http !== 200) {
            throw self::traducir_error($http, $datos);
        }

        $retos = [];
        foreach ((is_array($datos['retos'] ?? null) ? $datos['retos'] : []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $enlace = self::enlace_epica($r['enlace'] ?? '');
            $codigo = trim((string)($r['codigo'] ?? ''));
            if ($enlace === '' || $codigo === '') {
                continue; // Sin enlace válido no hay nada que abrir.
            }
            $retos[] = [
                'codigo' => mb_substr($codigo, 0, 64, 'UTF-8'),
                'enlace' => $enlace,
                'titulo' => self::texto_corto($r['titulo'] ?? '', 255),
                'dificultad' => self::texto_corto($r['dificultad'] ?? '', 30),
                'minutos' => max(0, (int)($r['minutos'] ?? 0)),
                'competencias' => self::lista_textos($r['competencias'] ?? null, 10, 100),
                'icono' => self::texto_corto($r['icono'] ?? '', 30),
                'origen' => self::texto_corto($r['origen'] ?? '', 20),
                'creado' => self::texto_corto($r['creado'] ?? '', 40),
            ];
        }

        return [
            'enlace' => self::enlace_epica($datos['enlace'] ?? ''),
            'titulo' => self::texto_corto($datos['titulo'] ?? '', 255),
            'total' => max(count($retos), (int)($datos['total'] ?? 0)),
            'retos' => $retos,
        ];
    }

    // ----------------------------------------------------------------
    // 6. mis_retos (sin llamar a Epica)
    // ----------------------------------------------------------------

    /**
     * Retos elegidos de ESTE usuario en ESTE curso, más las propuestas que aún no
     * tienen reto elegido (en cola, trabajando o listas, de menos de 7 días): sin
     * ellas, quien sale de una propuesta en cola no tiene vía de vuelta y volver a
     * proponer gasta otra unidad de cupo. Solo lee nuestras tablas (el estado es el
     * de la última vez que se sondeó; abrir la propuesta lo refresca).
     *
     * @return array{retos: array, propuestas: array}
     */
    public static function mis_retos(\stdClass $user, int $courseid): array {
        global $DB;
        self::asegurar_tablas();

        $rows = $DB->get_records('block_pulso_retos', ['userid' => (int)$user->id, 'courseid' => $courseid],
            'timecreated DESC, id DESC', '*', 0, self::MIS_RETOS_MAX);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'codigo' => (string)$r->codigo,
                'enlace' => (string)$r->enlace,
                'titulo' => (string)($r->titulo_final !== '' ? $r->titulo_final : $r->titulo),
                'propio' => !empty($r->propio),
                'creado' => (int)$r->timecreated,
            ];
        }
        return ['retos' => $out, 'propuestas' => self::propuestas_pendientes($user, $courseid)];
    }

    /**
     * Propuestas sin reto elegido, vigentes y no fallidas. Misma pertenencia que
     * cargar_propuesta(): userid + courseid; una ajena no sale nunca.
     */
    private static function propuestas_pendientes(\stdClass $user, int $courseid): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal(
            [self::ESTADO_EN_COLA, self::ESTADO_TRABAJANDO, self::ESTADO_LISTO], SQL_PARAMS_NAMED, 'est');
        $params = $inparams + [
            'userid' => (int)$user->id,
            'courseid' => $courseid,
            'desde' => time() - self::PROPUESTA_VIDA_S,
        ];
        $rows = $DB->get_records_sql(
            "SELECT p.id, p.estado, p.timecreated
               FROM {block_pulso_reto_propuestas} p
              WHERE p.userid = :userid AND p.courseid = :courseid
                AND p.timecreated > :desde
                AND p.estado $insql
                AND NOT EXISTS (SELECT 1 FROM {block_pulso_retos} r WHERE r.propuestaid = p.id)
           ORDER BY p.timecreated DESC, p.id DESC",
            $params, 0, self::MIS_RETOS_MAX
        );

        $out = [];
        foreach ($rows as $p) {
            $out[] = [
                'id' => (int)$p->id,
                'estado' => (string)$p->estado,
                'creado' => (int)$p->timecreated,
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------
    // Llamada a Epica
    // ----------------------------------------------------------------

    /**
     * Firma un token NUEVO y llama a una puerta de Retos. Un fallo transitorio
     * (criterio de epica_client::es_transitorio) se convierte en un error
     * recuperable para el cliente; el 4xx/429 se devuelve para traducirlo.
     *
     * @return array{0: int, 1: array} [http, datos]
     */
    private static function llamar(string $ruta, array $cuerpo, \stdClass $user, \stdClass $course, ?int $espera = null): array {
        if (!class_exists('\local_awkepica\epica')) {
            throw new reto_error('epica-no-disponible', 'Los retos no están disponibles en este sitio.', 503);
        }
        $motivo = epica_client::precondiciones_error($user);
        if ($motivo !== null) {
            throw new reto_error('epica-no-configurada', $motivo, 503);
        }
        // pedir() no comprueba json_encode(): si fallara, mandaría un cuerpo vacío.
        if (json_encode($cuerpo) === false) {
            throw new reto_error('material-ilegible',
                'No hemos podido leer el material de este recurso. Prueba con otro recurso o escribe un tema.');
        }

        $reintentable = ['reintentable' => true];
        $transitorio = 'El servicio de retos no responde ahora mismo. Inténtalo de nuevo en unos segundos.';
        try {
            $context = \context_course::instance((int)$course->id);
            // Token nuevo en CADA llamada; (string) del id, nunca el objeto del curso.
            $token = \local_awkepica\epica::firmar_por($user, $context, epica_client::CAPABILITY_ROL, (string)$course->id);
            $respuesta = \local_awkepica\epica::pedir(
                epica_client::endpoint($ruta),
                array_merge(['token' => $token], $cuerpo),
                $espera
            );
        } catch (\Throwable $e) {
            error_log('Pulso Retos: fallo al llamar a ' . $ruta . ': ' . get_class($e) . ': ' . $e->getMessage());
            throw new reto_error('epica-no-disponible', $transitorio, 503, $reintentable);
        }

        if (epica_client::es_transitorio($respuesta)) {
            error_log('Pulso Retos: fallo transitorio en ' . $ruta . ': ' . epica_client::describir_transitorio($respuesta));
            throw new reto_error('epica-no-disponible', $transitorio, 503, $reintentable);
        }
        return epica_client::normalize_response($respuesta);
    }

    /** Traduce un error de Epica (carta 7 §4) por `motivo`, nunca por el texto. */
    private static function traducir_error(int $http, array $datos): reto_error {
        $motivo = (string)($datos['motivo'] ?? '');
        self::registrar_traza('epica', 0, "HTTP {$http} {$motivo}", $datos['traza'] ?? null);

        switch ($motivo) {
            case 'cuota-agotada':
                $espera = epica_client::retry_after($datos);
                return new reto_error($motivo,
                    'Has pedido varios retos seguidos y el servicio te pide un respiro (3 cada 10 minutos). Podrás seguir en '
                    . self::formatear_espera($espera) . '.', 429, ['esperaS' => $espera]);
            case 'cuota-del-centro':
                $espera = epica_client::retry_after($datos);
                return new reto_error($motivo,
                    'Tu centro ha llegado al límite diario de retos. No es nada que hayas hecho tú: vuelve a intentarlo más tarde o mañana.',
                    429, ['esperaS' => $espera]);
            case 'encargo-sin-tema':
                return new reto_error($motivo, 'Elige un recurso o escribe un tema (o un reto de al menos ' . self::PROPIO_MIN . ' caracteres).');
            case 'encargo-ambiguo':
                return new reto_error($motivo, 'La petición mezclaba fuentes que no se pueden combinar. Inténtalo de nuevo.');
            case 'propuesta-desconocida':
                return new reto_error($motivo, 'Esa propuesta ya no existe o ha caducado (duran 7 días). Pide una nueva.');
            case 'reto-desconocido':
                return new reto_error($motivo, 'Ese reto no estaba entre los propuestos. Vuelve a elegir uno de la lista.');
            case 'material-excesivo':
                return new reto_error($motivo, 'El material de este recurso es demasiado grande para crear retos. Prueba con otro recurso o escribe un tema.');
            case 'material-ilegible':
                return new reto_error($motivo, 'No hemos podido leer el material de este recurso. Prueba con otro recurso o escribe un tema.');
            case 'origen-contradictorio':
                return new reto_error($motivo, 'La plataforma no coincide con la registrada en el servicio de retos. Avisa a quien administra el sitio.');
            case 'herramienta-no-contratada':
                return new reto_error($motivo, 'Retos no está activado en esta plataforma. Avisa a quien administra el sitio.', 409);
        }

        if ($http === 429) {
            return new reto_error('cuota-agotada', 'El servicio de retos no puede atender más peticiones por ahora. Inténtalo en unos minutos.',
                429, ['esperaS' => epica_client::retry_after($datos)]);
        }
        $detalle = preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $motivo);
        $detalle = mb_substr((string)$detalle, 0, 100, 'UTF-8');
        return new reto_error($detalle !== '' ? $detalle : 'epica-' . $http,
            'El servicio de retos no ha podido atender la petición' . ($detalle !== '' ? " ({$detalle})" : " (HTTP {$http})") . '.', 502);
    }

    // ----------------------------------------------------------------
    // Topes propios (independientes de Epica, infografias, juegos y ampliacion)
    // ----------------------------------------------------------------

    private static function comprobar_tope_propuestas(int $userid): void {
        global $DB;
        $limite = self::cfg_int('retos_max_propuestas_usuario_dia', 6);
        $usadas = $DB->count_records_select('block_pulso_reto_propuestas',
            'userid = :userid AND timecreated >= :since', ['userid' => $userid, 'since' => usergetmidnight(time())]);
        if ($usadas >= $limite) {
            throw new reto_error('tope-propuestas',
                "Has llegado a tu límite de propuestas de retos de hoy ({$limite}). Vuelve mañana.", 429);
        }
    }

    private static function comprobar_topes_elegir(int $userid, int $courseid): void {
        global $DB;
        $desde = usergetmidnight(time());

        $limiteusuario = self::cfg_int('retos_max_elegidos_usuario_dia', 3);
        $usados = $DB->count_records_select('block_pulso_retos',
            'userid = :userid AND timecreated >= :since', ['userid' => $userid, 'since' => $desde]);
        if ($usados >= $limiteusuario) {
            throw new reto_error('tope-elegidos',
                "Has llegado a tu límite de retos elegidos de hoy ({$limiteusuario}). Vuelve mañana.", 429);
        }

        $limitecurso = self::cfg_int('retos_max_elegidos_curso_dia', 40);
        $usadoscurso = $DB->count_records_select('block_pulso_retos',
            'courseid = :courseid AND timecreated >= :since', ['courseid' => $courseid, 'since' => $desde]);
        if ($usadoscurso >= $limitecurso) {
            throw new reto_error('tope-elegidos-curso',
                "Este curso ha llegado al límite de retos elegidos de hoy ({$limitecurso}). Vuelve mañana.", 429);
        }
    }

    private static function cfg_int(string $name, int $default): int {
        $value = get_config('block_pulso', $name);
        return ($value === false || $value === null || $value === '') ? $default : max(0, (int)$value);
    }

    /**
     * Candado por usuario: «proponer dos veces son dos propuestas, y elegir
     * dos veces son dos retos» (carta 7 §5) — los dos gastan cupo. Si ya hay
     * una peticion de este usuario en marcha (doble clic, dos pestañas), la
     * segunda se rechaza en vez de gastar otra unidad.
     */
    private static function con_candado(\stdClass $user, int $courseid, callable $fn): array {
        $lock = null;
        try {
            $factory = \core\lock\lock_config::get_lock_factory('block_pulso_retos');
            $lock = $factory->get_lock('retos_' . (int)$user->id, 2, self::CANDADO_VIDA_S);
        } catch (\Throwable $e) {
            $lock = null; // Sin factoría de candados: se sigue sin protección extra.
        }
        if ($lock === false) {
            throw new reto_error('peticion-en-curso',
                'Ya tienes una petición de retos en marcha. Espera a que termine antes de pedir otra.', 409);
        }
        try {
            return $fn();
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    // ----------------------------------------------------------------
    // Pertenencia y comprobaciones
    // ----------------------------------------------------------------

    /** Propuesta de ESTE usuario y ESTE curso; cualquier otra es "desconocida" (mismo error que una inexistente). */
    private static function cargar_propuesta(int $id, int $userid, int $courseid): \stdClass {
        global $DB;
        $row = $id > 0
            ? $DB->get_record('block_pulso_reto_propuestas', ['id' => $id, 'userid' => $userid, 'courseid' => $courseid])
            : false;
        if (!$row) {
            throw new reto_error('propuesta-desconocida', 'No encontramos esa propuesta de retos. Pide una nueva.', 404);
        }
        return $row;
    }

    private static function exigir_propuesta_vigente(\stdClass $row): void {
        if (time() - (int)$row->timecreated > self::PROPUESTA_VIDA_S) {
            throw new reto_error('propuesta-desconocida', 'Esa propuesta ha caducado (duran 7 días). Pide una nueva.', 404);
        }
    }

    /** El recurso, si es visible y aprovechable para este usuario (mismo filtro que Crear/Ampliar). */
    private static function recurso_disponible(int $courseid, int $userid, int $cmid): ?array {
        $ctx = creation_quota::get_resources_context($courseid, $userid, false);
        foreach ($ctx['resources'] as $candidato) {
            if ((int)$candidato['cmid'] === $cmid) {
                return $candidato;
            }
        }
        return null;
    }

    private static function asegurar_tablas(): void {
        global $DB;
        static $ok = null;
        if ($ok === null) {
            $tablas = $DB->get_tables();
            $ok = in_array('block_pulso_reto_propuestas', $tablas, true) && in_array('block_pulso_retos', $tablas, true);
        }
        if (!$ok) {
            throw new reto_error('sin-actualizar',
                'Retos aún no está listo en este sitio: falta ejecutar la actualización de Moodle (Administración → Notificaciones).', 503);
        }
    }

    // ----------------------------------------------------------------
    // Sobre
    // ----------------------------------------------------------------

    private static function sobre_curso(\stdClass $course): array {
        // curso.nombre es el que sale como título en la lista del curso en Épica.
        return ['nombre' => trim((string)$course->fullname), 'idioma' => 'es'];
    }

    private static function sobre_alumno(int $courseid, \stdClass $user, int $cmid): array {
        global $DB;
        // intento: propuestas previas de este usuario sobre la MISMA fuente (recurso, o "solo tema"), +1.
        $params = ['userid' => (int)$user->id, 'courseid' => $courseid];
        $where = 'userid = :userid AND courseid = :courseid AND ' . ($cmid > 0 ? 'cmid = :cmid' : 'cmid IS NULL');
        if ($cmid > 0) {
            $params['cmid'] = $cmid;
        }
        $alumno = [
            'idioma' => 'es',
            'intento' => $DB->count_records_select('block_pulso_reto_propuestas', $where, $params) + 1,
        ];
        $grupo = epica_client::resolve_grupo($courseid, (int)$user->id);
        if ($grupo !== '') {
            $alumno['grupo'] = $grupo;
        }
        return $alumno;
    }

    // ----------------------------------------------------------------
    // Datos de Epica: normalizar y validar (se guarda tal cual, solo con los
    // campos conocidos y tipos coaccionados; se escapa al pintarlo)
    // ----------------------------------------------------------------

    private static function normalizar_retos($retos): array {
        $out = [];
        foreach ((is_array($retos) ? $retos : []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = self::texto_corto($r['id'] ?? '', 100);
            $titulo = self::texto_corto($r['titulo'] ?? '', 300);
            if ($id === '' || $titulo === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'titulo' => $titulo,
                'descripcion' => self::texto_corto($r['descripcion'] ?? '', 2000),
                'dificultad' => self::texto_corto($r['dificultad'] ?? '', 30),
                'minutos' => max(0, (int)($r['minutos'] ?? 0)),
                'competencias' => self::lista_textos($r['competencias'] ?? null, 10, 100),
                'icono' => self::texto_corto($r['icono'] ?? '', 30),
            ];
        }
        return $out;
    }

    private static function normalizar_documento($doc): array {
        $doc = is_array($doc) ? $doc : [];
        return [
            'titulo' => self::texto_corto($doc['titulo'] ?? '', 300),
            'resumen' => self::texto_corto($doc['resumen'] ?? '', 2000),
            'temas' => self::lista_textos($doc['temas'] ?? null, 20, 150),
        ];
    }

    private static function lista_textos($lista, int $max, int $largo): array {
        $out = [];
        foreach ((is_array($lista) ? $lista : []) as $v) {
            $t = self::texto_corto($v, $largo);
            if ($t !== '') {
                $out[] = $t;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** Texto recortado con mb_* (nunca substr: son acentos en UTF-8). Valores no escalares → ''. */
    private static function texto_corto($valor, int $max): string {
        if (!is_scalar($valor)) {
            return '';
        }
        return mb_substr(trim((string)$valor), 0, $max, 'UTF-8');
    }

    /** Solo https:// y el host de epica_base_url; cualquier otra cosa → ''. */
    private static function enlace_epica($url): string {
        if (!is_string($url) || $url === '' || mb_strlen($url, 'UTF-8') > 512) {
            return '';
        }
        $partes = parse_url($url);
        $base = parse_url((string)get_config('block_pulso', 'epica_base_url'));
        if (!is_array($partes) || !is_array($base) || empty($partes['host']) || empty($base['host'])) {
            return '';
        }
        if (strtolower($partes['scheme'] ?? '') !== 'https' || isset($partes['user']) || isset($partes['pass'])) {
            return '';
        }
        if (strtolower($partes['host']) !== strtolower($base['host'])) {
            return '';
        }
        // Mismo puerto que epica_base_url (el implicito de https es 443).
        $puerto = (int)($partes['port'] ?? 443);
        $puertobase = (int)($base['port'] ?? 443);
        return $puerto === $puertobase ? $url : '';
    }

    private static function estado_conocido($estado, string $defecto): string {
        $validos = [self::ESTADO_EN_COLA, self::ESTADO_TRABAJANDO, self::ESTADO_LISTO, self::ESTADO_FALLADO, self::ESTADO_DESCONOCIDO];
        return is_string($estado) && in_array($estado, $validos, true) ? $estado : $defecto;
    }

    private static function json($valor): string {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function decodificar_lista($json): array {
        $lista = json_decode((string)$json, true);
        return is_array($lista) ? $lista : [];
    }

    private static function payload_propuesta(\stdClass $row): array {
        $terminal = in_array($row->estado, [self::ESTADO_LISTO, self::ESTADO_FALLADO, self::ESTADO_DESCONOCIDO], true);
        $out = [
            'id' => (int)$row->id,
            'estado' => (string)$row->estado,
            'posicion' => (int)$row->posicion,
            // Segundos desde el primer "trabajando": el limite de 2 min del cliente cuenta desde aqui, no desde la cola.
            'trabajando_desde' => (!$terminal && !empty($row->timetrabajando)) ? max(0, time() - (int)$row->timetrabajando) : null,
        ];
        if ($row->estado === self::ESTADO_FALLADO || $row->estado === self::ESTADO_DESCONOCIDO) {
            $out['motivo'] = (string)$row->motivo;
        }
        if ($row->estado === self::ESTADO_LISTO) {
            $out['documento'] = self::decodificar_lista($row->documento_json);
            $out['retos'] = self::decodificar_lista($row->retos_json);
        }
        return $out;
    }

    /** Traza de Epica (cuando viene) al log, junto a nuestra referencia: nunca material ni token. */
    private static function registrar_traza(string $que, int $id, string $detalle, $traza): void {
        if (empty($traza)) {
            return;
        }
        error_log('Pulso Retos: ' . $que . ' #' . $id . ' (' . mb_substr($detalle, 0, 200, 'UTF-8')
            . ') traza Épica=' . mb_substr(
                is_scalar($traza) ? (string)$traza : (string)json_encode($traza, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                0, 64, 'UTF-8'
            ));
    }

    private static function formatear_espera(int $segundos): string {
        if ($segundos < 60) {
            return max(1, $segundos) . ' segundos';
        }
        $minutos = (int)ceil($segundos / 60);
        return $minutos === 1 ? '1 minuto' : "{$minutos} minutos";
    }
}
