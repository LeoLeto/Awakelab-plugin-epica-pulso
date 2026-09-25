<?php
/**
 * Upgrade script for block_pulso
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_block_pulso_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026031600) {
        // Add block_pulso_content_chunks table for RAG.
        $table = new xmldb_table('block_pulso_content_chunks');

        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('module_type',  XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, '');
        $table->add_field('module_name',  XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('chunk_index',  XMLDB_TYPE_INTEGER,  '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('chunk_text',   XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
        $table->add_field('content_hash', XMLDB_TYPE_CHAR,    '64', null, XMLDB_NOTNULL, null, '');
        $table->add_field('embedding_json', XMLDB_TYPE_TEXT,  null, null, null);
        $table->add_field('token_count',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('idx_cmid',     XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        $table->add_index('idx_hash',     XMLDB_INDEX_NOTUNIQUE, ['content_hash']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026031600, 'pulso');
    }

    if ($oldversion < 2026080417) {
        // block_pulso_history se definió en install.xml desde el primer día pero
        // ningún código la escribió ni la leyó nunca (el historial vive en $SESSION
        // y en el sessionStorage del cliente). Se elimina para no dejar esquema
        // muerto.
        //
        // Se borra SOLO si está vacía: aunque no debería tener filas jamás, borrar
        // datos de un sitio en producción sin comprobarlo no es aceptable. Si alguien
        // llegó a escribir ahí, la tabla se conserva y queda un aviso en el log.
        $table = new xmldb_table('block_pulso_history');
        if ($dbman->table_exists($table)) {
            if ($DB->count_records('block_pulso_history') == 0) {
                $dbman->drop_table($table);
            } else {
                mtrace('block_pulso: block_pulso_history tiene filas, NO se elimina. '
                    . 'Revísala y bórrala a mano si no la necesitas.');
            }
        }

        upgrade_block_savepoint(true, 2026080417, 'pulso');
    }

    if ($oldversion < 2026092302) {
        // Texto completo (sin trocear) por modulo, para Epica (material.texto).
        // NO se reconstruye concatenando chunk_text de block_pulso_content_chunks:
        // los fragmentos SE SOLAPAN (CHUNK_OVERLAP) y esa concatenacion
        // duplicaria texto. Se captura aparte, en content_extractor::chunk_text(),
        // que ya recibe el texto entero antes de partirlo.
        $table = new xmldb_table('block_pulso_full_text');

        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('module_type',  XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, '');
        $table->add_field('module_name',  XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('texto',        XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
        $table->add_field('caracteres',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('extraido_por', XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('usable',       XMLDB_TYPE_INTEGER,  '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('content_hash', XMLDB_TYPE_CHAR,    '64', null, XMLDB_NOTNULL, null, '');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('idx_cmid',     XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        $table->add_index('idx_courseid_cmid', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026092302, 'pulso');
    }

    if ($oldversion < 2026092303) {
        // Encargos de creacion para Epica (paso 1: infografias; el mismo
        // contador servira para retos en v2 -Epica avisa de que la cola de
        // encargos es compartida entre herramientas). Cada fila cuenta contra
        // los cupos anti-abuso y es la que el paso 4 completara con el job id
        // real que devuelva Epica.
        $table = new xmldb_table('block_pulso_encargos');

        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sectionnum',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tool',         XMLDB_TYPE_CHAR,    '30', null, XMLDB_NOTNULL, null, 'infografia');
        $table->add_field('format',       XMLDB_TYPE_CHAR,    '30', null, XMLDB_NOTNULL, null, '');
        $table->add_field('prompt',       XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
        $table->add_field('status',       XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'pendiente');
        $table->add_field('epica_job_id', XMLDB_TYPE_CHAR,   '255', null, null, null);
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('idx_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $table->add_index('idx_courseid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timecreated']);
        $table->add_index('idx_userid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026092303, 'pulso');
    }

    if ($oldversion < 2026092304) {
        // Ciclo con Epica (paso 3): columnas para firmar/encargar/sondear/recoger
        // sin salirse de la fila que ya existe. "status" reutiliza el campo del
        // paso 1 con los estados nuevos (encolado/trabajando/listo/fallado/
        // desconocido/ensayo); lo demas es lo que hace falta para no repetir un
        // encargo terminal ni perder la cadencia de sondeo entre ejecuciones de
        // la tarea adhoc (que NUNCA duerme: un paso por ejecucion, ver CLAUDE.md).
        $table = new xmldb_table('block_pulso_encargos');

        $fields = [
            new xmldb_field('epica_plataforma', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'epica_job_id'),
            new xmldb_field('epica_posicion', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'epica_plataforma'),
            new xmldb_field('epica_traza', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'epica_posicion'),
            new xmldb_field('last_posicion', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'epica_traza'),
            new xmldb_field('stall_count', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0', 'last_posicion'),
            new xmldb_field('pollcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'stall_count'),
            new xmldb_field('timequeued', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'pollcount'),
            new xmldb_field('mock', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timequeued'),
            new xmldb_field('verificado', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'mock'),
            new xmldb_field('avisos', XMLDB_TYPE_TEXT, null, null, null, null, null, 'verificado'),
            new xmldb_field('titulo', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'avisos'),
            new xmldb_field('tema', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'titulo'),
            new xmldb_field('arquetipo', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'tema'),
            new xmldb_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'arquetipo'),
            new xmldb_field('motivo', XMLDB_TYPE_TEXT, null, null, null, null, null, 'filename'),
            new xmldb_field('sobre_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'motivo'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_block_savepoint(true, 2026092304, 'pulso');
    }

    if ($oldversion < 2026092305) {
        // Paso 4: panel de estado + galeria + aviso de mensajeria. "notified"
        // asegura que ese aviso se manda UNA sola vez por encargo: aunque un
        // encargo terminal (listo/fallado/desconocido) nunca se reprocesa
        // (ver epica_client::procesar_paso()), esta columna deja la garantia
        // explicita en la fila en vez de depender solo de esa invariante.
        $table = new xmldb_table('block_pulso_encargos');
        $field = new xmldb_field('notified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sobre_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026092305, 'pulso');
    }

    if ($oldversion < 2026092306) {
        // Limpieza de encargos huerfanos: los creados antes de v1.19.0 (cuando
        // creation_quota::dispatch_to_epica() todavia era un no-op) se quedaron
        // en "pendiente" para siempre — nunca se encolo la tarea adhoc que los
        // habria movido de estado, asi que epica_ciclo_adhoc jamas los toca (se
        // encola solo al crear el encargo o desde un paso anterior de la propia
        // tarea, nunca por un barrido periodico). Sin esto seguian saliendo como
        // "pendientes" en la galeria del usuario.
        //
        // Un umbral de antiguedad NO sirve para distinguir un huerfano de uno
        // vivo: un "pendiente" reintentando un 429 tambien puede llevar horas
        // ahi. El criterio real es si existe una tarea adhoc de
        // epica_ciclo_adhoc apuntando a ese encargo. dispatch_to_epica() y el
        // propio epica_ciclo_adhoc::execute() la encolan siempre con
        // set_custom_data(['encargoid' => $id]) (mismo shape en los dos
        // sitios), asi que se decodifica el JSON de task_adhoc.customdata en
        // vez de comparar con LIKE sobre el texto. Se marcan como fallo
        // terminal en vez de borrarse, para no perder el registro.
        $pendientes = $DB->get_records('block_pulso_encargos', ['status' => 'pendiente'], '', 'id');

        if (!empty($pendientes)) {
            $select = $DB->sql_like('classname', ':classname') . ' AND component = :component';
            $tasks = $DB->get_records_select(
                'task_adhoc',
                $select,
                ['classname' => '%epica_ciclo_adhoc', 'component' => 'block_pulso'],
                '',
                'id, customdata'
            );

            $encolados = [];
            foreach ($tasks as $task) {
                $data = json_decode((string)$task->customdata, true);
                if (is_array($data) && isset($data['encargoid'])) {
                    $encolados[(int)$data['encargoid']] = true;
                }
            }

            foreach ($pendientes as $row) {
                if (isset($encolados[(int)$row->id])) {
                    continue; // Tiene tarea adhoc viva: pendiente real, no huerfano.
                }
                $DB->update_record('block_pulso_encargos', (object)[
                    'id' => $row->id,
                    'status' => 'fallado',
                    'motivo' => 'Encargo huérfano de antes de la integración con Épica (v1.19.0): nunca llegó a encolarse.',
                    'notified' => 1,
                    'timemodified' => time(),
                ]);
                mtrace("block_pulso: encargo {$row->id} huérfano (sin tarea adhoc), marcado como fallado.");
            }
        }

        upgrade_block_savepoint(true, 2026092306, 'pulso');
    }

    if ($oldversion < 2026092307) {
        // Tope a los reintentos de red del ciclo (visto en produccion: el
        // encargo 6 se quedaba en "pendiente" reintentando cada 60s sin fin,
        // porque el catch(\Throwable) de paso_pendiente()/paso_sondeo() no
        // tenia limite). "error_count" cuenta SOLO excepciones de red
        // seguidas (no respuestas 4xx/5xx normales de Epica); un 202/200
        // correcto lo pone a 0.
        $table = new xmldb_table('block_pulso_encargos');
        $field = new xmldb_field('error_count', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0', 'notified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026092307, 'pulso');
    }

    if ($oldversion < 2026092501) {
        // Encargo 7 (2026-09-25): Epica respondio "listo" pero recoger() no
        // encontro una imagen valida en la forma esperada -> fallado sin
        // imagen, con titulo/tema vacios. Epica guarda el trabajo aceptado 7
        // dias, asi que volver a pedirlo no cuesta cupo. Se devuelven a
        // "trabajando" los encargos con ESE motivo exacto, con job id (o no
        // habria nada que sondear) y encargados hace menos de 6 dias (margen
        // sobre los 7 reales), para que el siguiente sondeo pase por el
        // diagnostico nuevo de recoger() en vez de quedarse fallados para
        // siempre por una forma de respuesta que todavia no entendemos.
        //
        // "motivo" es XMLDB_TYPE_TEXT: la comparacion de igualdad va con
        // sql_compare_text() en los dos lados (campo y parametro), no con un
        // "=" a pelo, por portabilidad entre motores.
        $motivo = 'La respuesta "listo" no traía una imagen válida.';
        $cutoff = time() - 6 * DAYSECS;

        $select = "status = :status AND " . $DB->sql_compare_text('motivo') . " = " . $DB->sql_compare_text(':motivo')
            . " AND epica_job_id IS NOT NULL AND epica_job_id <> :vacio AND timequeued > :cutoff";
        $params = ['status' => 'fallado', 'motivo' => $motivo, 'vacio' => '', 'cutoff' => $cutoff];
        $encargos = $DB->get_records_select('block_pulso_encargos', $select, $params, '', 'id');

        foreach ($encargos as $row) {
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $row->id,
                'status' => 'trabajando',
                'error_count' => 0,
                'notified' => 0,
                'timemodified' => time(),
            ]);

            try {
                $task = new \block_pulso\task\epica_ciclo_adhoc();
                $task->set_component('block_pulso');
                $task->set_custom_data(['encargoid' => (int)$row->id]);
                \core\task\manager::queue_adhoc_task($task);
                mtrace("block_pulso: encargo {$row->id} devuelto a 'trabajando' y reencolado para recoger con diagnostico.");
            } catch (\Throwable $e) {
                mtrace("block_pulso: encargo {$row->id} devuelto a 'trabajando' pero NO se pudo reencolar: " . $e->getMessage());
            }
        }

        upgrade_block_savepoint(true, 2026092501, 'pulso');
    }

    if ($oldversion < 2026092502) {
        // Epica confirmo la forma real de "listo" (backend/src/laminas.ts:469,
        // encargos.ts:191,389): la raiz solo lleva "plataforma"/"estado", todo
        // lo demas (imagen incluida) va anidado bajo "lamina". La v1.20.4 leia
        // los campos en la raiz, asi que el encargo 7 (devuelto a "trabajando"
        // por el paso 2026092501) volvio a fallar, esta vez con el motivo del
        // diagnostico nuevo anexo al string original. Misma recuperacion, pero
        // con LIKE sobre el PREFIJO del motivo (ya no es un string exacto).
        $prefijo = $DB->sql_like_escape('La respuesta "listo" no traía una imagen válida.') . '%';
        $cutoff = time() - 6 * DAYSECS;

        $select = "status = :status AND " . $DB->sql_like($DB->sql_compare_text('motivo'), ':motivo')
            . " AND epica_job_id IS NOT NULL AND epica_job_id <> :vacio AND timequeued > :cutoff";
        $params = ['status' => 'fallado', 'motivo' => $prefijo, 'vacio' => '', 'cutoff' => $cutoff];
        $encargos = $DB->get_records_select('block_pulso_encargos', $select, $params, '', 'id');

        foreach ($encargos as $row) {
            $DB->update_record('block_pulso_encargos', (object)[
                'id' => $row->id,
                'status' => 'trabajando',
                'error_count' => 0,
                'notified' => 0,
                'timemodified' => time(),
            ]);

            try {
                $task = new \block_pulso\task\epica_ciclo_adhoc();
                $task->set_component('block_pulso');
                $task->set_custom_data(['encargoid' => (int)$row->id]);
                \core\task\manager::queue_adhoc_task($task);
                mtrace("block_pulso: encargo {$row->id} devuelto a 'trabajando' y reencolado (2ª recuperación, lectura de lámina corregida).");
            } catch (\Throwable $e) {
                mtrace("block_pulso: encargo {$row->id} devuelto a 'trabajando' pero NO se pudo reencolar: " . $e->getMessage());
            }
        }

        upgrade_block_savepoint(true, 2026092502, 'pulso');
    }

    return true;
}
