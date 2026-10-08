<?php
/**
 * Privacy provider de block_pulso (RGPD).
 *
 * Todos los datos personales viven en contexto de CURSO (tablas con userid +
 * courseid, y las fileareas encargo/juego, que se guardan en el contexto del
 * curso, no en el del bloque).
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /** @var string[] Tablas con userid + courseid que se borran/exportan por persona. */
    const USER_TABLES = [
        'block_pulso_encargos',
        'block_pulso_reto_propuestas',
        'block_pulso_retos',
    ];

    /** @var string[] Fileareas de block_pulso con itemid = id de block_pulso_encargos. */
    const FILEAREAS = ['encargo', 'juego'];

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_pulso_encargos', [
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'tool' => 'privacy:metadata:tool',
            'prompt' => 'privacy:metadata:prompt',
            'status' => 'privacy:metadata:status',
            'titulo' => 'privacy:metadata:titulo',
            'tema' => 'privacy:metadata:tema',
            'motivo' => 'privacy:metadata:motivo',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:block_pulso_encargos');

        $collection->add_database_table('block_pulso_ampliaciones', [
            'userid' => 'privacy:metadata:userid',
            'tema' => 'privacy:metadata:tema',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:block_pulso_ampliaciones');

        $collection->add_database_table('block_pulso_reto_propuestas', [
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'tema' => 'privacy:metadata:tema',
            'estado' => 'privacy:metadata:status',
            'retos_json' => 'privacy:metadata:retos_json',
            'motivo' => 'privacy:metadata:motivo',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:block_pulso_reto_propuestas');

        $collection->add_database_table('block_pulso_retos', [
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'enlace' => 'privacy:metadata:enlace',
            'titulo' => 'privacy:metadata:titulo',
            'titulo_final' => 'privacy:metadata:titulo',
            'propio' => 'privacy:metadata:propio',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:block_pulso_retos');

        $collection->link_subsystem('core_files', 'privacy:metadata:core_files');
        $collection->link_subsystem('core_message', 'privacy:metadata:core_message');

        // Anthropic: respuestas del chat. Al profesorado además le viaja el contexto analítico
        // del curso (nombres, notas, finalización, accesos); al alumnado, solo contenido.
        $collection->add_external_location_link('anthropic', [
            'question' => 'privacy:metadata:anthropic:question',
            'history' => 'privacy:metadata:anthropic:history',
            'role' => 'privacy:metadata:anthropic:role',
            'coursecontext' => 'privacy:metadata:anthropic:coursecontext',
        ], 'privacy:metadata:anthropic');

        // OpenAI: SOLO embeddings (RAG). No recibe historial ni datos de personas.
        $collection->add_external_location_link('openai', [
            'question' => 'privacy:metadata:openai:question',
            'coursecontent' => 'privacy:metadata:openai:coursecontent',
        ], 'privacy:metadata:openai');

        // Épica (local_awkepica): token firmado (sub, email, nombre, rol, curso) + sobre/cuerpo
        // (petición, material, contexto, curso, alumno.idioma/intento/grupo). Al borrar los datos
        // de una persona se pide a Épica que borre lo suyo (carta 14): lo dice la descripción.
        $collection->add_external_location_link('epica', [
            'sub' => 'privacy:metadata:epica:sub',
            'email' => 'privacy:metadata:epica:email',
            'nombre' => 'privacy:metadata:epica:nombre',
            'rol' => 'privacy:metadata:epica:rol',
            'curso' => 'privacy:metadata:epica:curso',
            'peticion' => 'privacy:metadata:epica:peticion',
            'grupo' => 'privacy:metadata:epica:grupo',
            'intento' => 'privacy:metadata:epica:intento',
        ], 'privacy:metadata:epica');

        // NO se declaran, a propósito:
        // - YouTube y OpenAlex: solo reciben consultas generadas a partir del tema del recurso,
        //   sin ningún dato de la persona (ni el texto del recurso).
        // - Caché `chatrate` (db/caches.php): contador de preguntas con TTL de 1 día, sin contenido.
        // - Historial del chat: vive en el sessionStorage del navegador y en $SESSION; no se guarda en BD.
        // - block_pulso_content_chunks / block_pulso_full_text: material del curso, sin userid.
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $parts = [];
        $params = ['contextlevel' => CONTEXT_COURSE];
        foreach (array_merge(self::USER_TABLES, ['block_pulso_ampliaciones']) as $i => $table) {
            $parts[] = "SELECT courseid FROM {{$table}} WHERE userid = :userid{$i}";
            $params["userid{$i}"] = $userid;
        }
        $sql = "SELECT c.id
                  FROM {context} c
                 WHERE c.contextlevel = :contextlevel
                   AND c.instanceid IN (" . implode(' UNION ', $parts) . ")";
        $contextlist->add_from_sql($sql, $params);
        // Gancho del borrado en Épica (carta 14): con Épica disponible se añade el contexto de
        // USUARIO de la persona. Así Moodle nos llama en delete_data_for_user() aunque ya no
        // queden filas en nuestras tablas (p. ej. porque se borró el curso). Exportar y borrar
        // tablas ignoran ese contexto.
        if (self::epica_disponible()) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel === CONTEXT_USER && self::epica_disponible()) {
            // Coherente con get_contexts_for_userid(): el contexto de usuario es el gancho del
            // borrado en Épica. delete_data_for_users() no hace nada con él (ver allí).
            $userlist->add_user((int)$context->instanceid);
            return;
        }
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $parts = [];
        $params = [];
        foreach (array_merge(self::USER_TABLES, ['block_pulso_ampliaciones']) as $i => $table) {
            $parts[] = "SELECT userid FROM {{$table}} WHERE courseid = :courseid{$i} AND userid > 0";
            $params["courseid{$i}"] = $context->instanceid;
        }
        $userlist->add_from_sql('userid', implode(' UNION ', $parts), $params);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $base = get_string('pluginname', 'block_pulso');

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }
            $courseid = (int)$context->instanceid;
            $writer = writer::with_context($context);
            $where = ['userid' => $userid, 'courseid' => $courseid];

            // Creaciones (infografías y juegos). NO se exporta sobre_json: repite la petición, que ya
            // se exporta, y el material del curso, que no es dato de la persona.
            $creaciones = [];
            foreach ($DB->get_records('block_pulso_encargos', $where, 'id ASC') as $e) {
                $creaciones[] = (object)[
                    'herramienta' => $e->tool,
                    'peticion' => $e->prompt,
                    'estado' => $e->status,
                    'titulo' => $e->titulo,
                    'tema' => $e->tema,
                    'motivo' => in_array($e->status, ['fallado', 'desconocido'], true) ? $e->motivo : null,
                    'creada' => transform::datetime($e->timecreated),
                    'modificada' => transform::datetime($e->timemodified),
                ];
                foreach (self::FILEAREAS as $area) {
                    $writer->export_area_files(
                        [$base, get_string('privacy:export:creaciones', 'block_pulso'), (string)$e->id],
                        'block_pulso', $area, $e->id);
                }
            }
            if ($creaciones) {
                $writer->export_data([$base, get_string('privacy:export:creaciones', 'block_pulso')],
                    (object)['creaciones' => $creaciones]);
            }

            $propuestas = [];
            foreach ($DB->get_records('block_pulso_reto_propuestas', $where, 'id ASC') as $p) {
                $propuestas[] = (object)[
                    'tema' => $p->tema,
                    'estado' => $p->estado,
                    'retos' => $p->retos_json !== null ? json_decode($p->retos_json) : null,
                    'motivo' => $p->motivo,
                    'creada' => transform::datetime($p->timecreated),
                    'modificada' => transform::datetime($p->timemodified),
                ];
            }
            if ($propuestas) {
                $writer->export_data([$base, get_string('privacy:export:propuestas', 'block_pulso')],
                    (object)['propuestas' => $propuestas]);
            }

            $retos = [];
            foreach ($DB->get_records('block_pulso_retos', $where, 'id ASC') as $r) {
                $retos[] = (object)[
                    'titulo' => $r->titulo,
                    'titulo_final' => $r->titulo_final,
                    'enlace' => $r->enlace,
                    'escrito_por_la_persona' => transform::yesno($r->propio),
                    'creado' => transform::datetime($r->timecreated),
                    'modificado' => transform::datetime($r->timemodified),
                ];
            }
            if ($retos) {
                $writer->export_data([$base, get_string('privacy:export:retos', 'block_pulso')],
                    (object)['retos' => $retos]);
            }

            // Ampliaciones: la fila es una caché compartida del curso; solo se dice cuáles generó la persona.
            $ampliaciones = [];
            foreach ($DB->get_records('block_pulso_ampliaciones', $where, 'id ASC') as $a) {
                $ampliaciones[] = (object)[
                    'recurso' => self::nombre_recurso($courseid, (int)$a->cmid, (string)$a->tema),
                    'generada' => transform::datetime($a->timecreated),
                ];
            }
            if ($ampliaciones) {
                $writer->export_data([$base, get_string('privacy:export:ampliaciones', 'block_pulso')],
                    (object)['ampliaciones' => $ampliaciones]);
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        self::delete_in_course((int)$context->instanceid, $context->id, null);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (empty($contextlist->count())) {
            return;
        }
        $userid = (int)$contextlist->get_user()->id;
        $pedirborradoepica = false;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_COURSE) {
                self::delete_in_course((int)$context->instanceid, $context->id, [$userid]);
            } else if ($context->contextlevel === CONTEXT_USER && (int)$context->instanceid === $userid) {
                $pedirborradoepica = true;
            }
        }
        // Borrado en Épica (carta 14): UNA tarea por llamada, no una por contexto, y solo desde
        // esta solicitud por persona (ver delete_data_for_users()).
        if ($pedirborradoepica) {
            \block_pulso\task\epica_borrar_alumno_adhoc::encolar($userid);
        }
    }

    /**
     * NO encola el borrado en Épica, a propósito: esta llamada (y la de todos los usuarios de un
     * contexto) también la hace la caducidad de datos por curso, y que caduque UN curso no puede
     * borrar el historial del alumno en Épica, que es de todos sus cursos del centro («no hay
     * borrado por curso», carta 14).
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if ($context->contextlevel !== CONTEXT_COURSE || !$userids) {
            return;
        }
        self::delete_in_course((int)$context->instanceid, $context->id, $userids);
    }

    /**
     * Borra los datos de un curso, de todos ($userids = null) o de las personas dadas.
     *
     * - Los ficheros se borran encargo a encargo (itemid), nunca el área entera del curso cuando
     *   se borra a una sola persona.
     * - Las ampliaciones NO se borran: son la caché compartida que protege la cuota de YouTube.
     *   Se anonimiza `userid` a 0 (no cuenta ya para el tope diario de nadie).
     * - Efecto aceptado: borrar los encargos de hoy de una persona le devuelve cupo de ese día.
     *   Es raro y legítimo.
     */
    private static function delete_in_course(int $courseid, int $contextid, ?array $userids): void {
        global $DB;

        $where = 'courseid = :courseid';
        $params = ['courseid' => $courseid];
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $where .= " AND userid {$insql}";
            $params += $inparams;
        }

        $fs = get_file_storage();
        foreach ($DB->get_fieldset_select('block_pulso_encargos', 'id', $where, $params) as $encargoid) {
            foreach (self::FILEAREAS as $area) {
                $fs->delete_area_files($contextid, 'block_pulso', $area, $encargoid);
            }
        }

        foreach (self::USER_TABLES as $table) {
            $DB->delete_records_select($table, $where, $params);
        }
        $DB->set_field_select('block_pulso_ampliaciones', 'userid', 0, $where, $params);
    }

    /** ¿Hay Épica en este sitio? Única puerta antes de tocar nada de \local_awkepica (vía epica_client). */
    private static function epica_disponible(): bool {
        return \block_pulso\epica_client::disponible();
    }

    private static function nombre_recurso(int $courseid, int $cmid, string $fallback): string {
        try {
            return format_string(get_fast_modinfo($courseid)->get_cm($cmid)->name);
        } catch (\Throwable $e) {
            // Recurso borrado: se cae al tema guardado.
            return $fallback;
        }
    }
}
