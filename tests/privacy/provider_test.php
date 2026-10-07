<?php
/**
 * Pruebas del privacy provider de block_pulso.
 *
 * Ejecutar en un Moodle con PHPUnit inicializado:
 *   vendor/bin/phpunit blocks/pulso/tests/privacy/provider_test.php
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \block_pulso\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {

    /** Crea un encargo con un PNG en `encargo` y un HTML en `juego`. */
    private function crear_encargo(int $userid, \stdClass $course, string $tool = 'infografia'): int {
        global $DB;
        $now = time();
        $id = $DB->insert_record('block_pulso_encargos', (object)[
            'courseid' => $course->id, 'cmid' => 1, 'sectionnum' => 1, 'userid' => $userid,
            'tool' => $tool, 'format' => '', 'prompt' => 'Peticion de ' . $userid, 'status' => 'fallado',
            'titulo' => 'Titulo', 'tema' => 'Tema', 'motivo' => 'Motivo X', 'sobre_json' => '{"secreto":1}',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $ctx = \context_course::instance($course->id);
        $fs = get_file_storage();
        foreach (['encargo' => 'a.png', 'juego' => 'a.html'] as $area => $name) {
            $fs->create_file_from_string([
                'contextid' => $ctx->id, 'component' => 'block_pulso', 'filearea' => $area,
                'itemid' => $id, 'filepath' => '/', 'filename' => $name,
            ], 'contenido');
        }
        return $id;
    }

    private function crear_resto(int $userid, \stdClass $course): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_pulso_reto_propuestas', (object)[
            'userid' => $userid, 'courseid' => $course->id, 'tema' => 'Tema reto', 'epica_propuesta' => 'p' . $userid . $course->id,
            'estado' => 'listo', 'retos_json' => '[{"id":"r1","titulo":"Reto"}]', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('block_pulso_retos', (object)[
            'userid' => $userid, 'courseid' => $course->id, 'propuestaid' => 1, 'codigo' => 'c' . $userid . '_' . $course->id,
            'enlace' => 'https://epica.example/reto/x', 'titulo' => 'Reto', 'titulo_final' => 'Reto final', 'propio' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('block_pulso_ampliaciones', (object)[
            'courseid' => $course->id, 'cmid' => 1, 'content_hash' => 'h' . $userid . $course->id, 'status' => 'listo',
            'tema' => 'Tema amp', 'userid' => $userid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    private function filas(string $table, array $cond = []): int {
        global $DB;
        return $DB->count_records($table, $cond);
    }

    private function ficheros(\stdClass $course, int $itemid): int {
        $ctx = \context_course::instance($course->id);
        $n = 0;
        foreach (['encargo', 'juego'] as $area) {
            $n += count(get_file_storage()->get_area_files($ctx->id, 'block_pulso', $area, $itemid, 'id', false));
        }
        return $n;
    }

    /** Dos personas, dos cursos: u1 en c1 y c2, u2 solo en c1. Devuelve [u1, u2, c1, c2, ids]. */
    private function escenario(): array {
        $this->resetAfterTest();
        $g = $this->getDataGenerator();
        $u1 = $g->create_user();
        $u2 = $g->create_user();
        $c1 = $g->create_course();
        $c2 = $g->create_course();
        $ids = [
            'u1c1' => $this->crear_encargo($u1->id, $c1),
            'u1c2' => $this->crear_encargo($u1->id, $c2, 'gamificacion'),
            'u2c1' => $this->crear_encargo($u2->id, $c1),
        ];
        $this->crear_resto($u1->id, $c1);
        $this->crear_resto($u1->id, $c2);
        $this->crear_resto($u2->id, $c1);
        return [$u1, $u2, $c1, $c2, $ids];
    }

    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('block_pulso'));
        $nombres = array_map(fn($i) => $i->get_name(), $collection->get_collection());
        foreach (['block_pulso_encargos', 'block_pulso_ampliaciones', 'block_pulso_reto_propuestas', 'block_pulso_retos',
                  'core_files', 'core_message', 'anthropic', 'openai', 'epica'] as $esperado) {
            $this->assertContains($esperado, $nombres);
        }
        $this->assertNotContains('youtube', $nombres);
        $this->assertNotContains('openalex', $nombres);
    }

    public function test_contextos_y_personas(): void {
        [$u1, $u2, $c1, $c2] = $this->escenario();

        $ids1 = provider::get_contexts_for_userid($u1->id)->get_contextids();
        $this->assertEqualsCanonicalizing(
            [\context_course::instance($c1->id)->id, \context_course::instance($c2->id)->id], $ids1);
        $this->assertEquals([\context_course::instance($c1->id)->id], provider::get_contexts_for_userid($u2->id)->get_contextids());

        $ul = new userlist(\context_course::instance($c1->id), 'block_pulso');
        provider::get_users_in_context($ul);
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], $ul->get_userids());

        $ul2 = new userlist(\context_course::instance($c2->id), 'block_pulso');
        provider::get_users_in_context($ul2);
        $this->assertEquals([$u1->id], $ul2->get_userids());

        // Un contexto que no es de curso no devuelve a nadie.
        $ul3 = new userlist(\context_system::instance(), 'block_pulso');
        provider::get_users_in_context($ul3);
        $this->assertEmpty($ul3->get_userids());
    }

    public function test_exportacion_con_ficheros(): void {
        [$u1, , $c1, , $ids] = $this->escenario();
        $ctx = \context_course::instance($c1->id);

        provider::export_user_data(new approved_contextlist($u1, 'block_pulso', [$ctx->id]));
        $w = writer::with_context($ctx);
        $this->assertTrue($w->has_any_data());

        $base = get_string('pluginname', 'block_pulso');
        $creaciones = $w->get_data([$base, get_string('privacy:export:creaciones', 'block_pulso')]);
        $this->assertCount(1, $creaciones->creaciones);
        $this->assertEquals('Peticion de ' . $u1->id, $creaciones->creaciones[0]->peticion);
        $this->assertEquals('Motivo X', $creaciones->creaciones[0]->motivo);
        // sobre_json no se exporta.
        $this->assertFalse(isset($creaciones->creaciones[0]->sobre_json));
        $this->assertStringNotContainsString('secreto', json_encode($creaciones));

        $ficheros = $w->get_files([$base, get_string('privacy:export:creaciones', 'block_pulso'), (string)$ids['u1c1']]);
        $this->assertEqualsCanonicalizing(['a.png', 'a.html'], array_keys($ficheros));

        $this->assertNotEmpty($w->get_data([$base, get_string('privacy:export:propuestas', 'block_pulso')]));
        $this->assertNotEmpty($w->get_data([$base, get_string('privacy:export:retos', 'block_pulso')]));
        $amp = $w->get_data([$base, get_string('privacy:export:ampliaciones', 'block_pulso')]);
        $this->assertCount(1, $amp->ampliaciones);
    }

    public function test_borrar_por_persona(): void {
        [$u1, $u2, $c1, $c2, $ids] = $this->escenario();
        $ctx1 = \context_course::instance($c1->id);

        provider::delete_data_for_user(new approved_contextlist($u1, 'block_pulso', [$ctx1->id]));

        $this->assertEquals(0, $this->filas('block_pulso_encargos', ['userid' => $u1->id, 'courseid' => $c1->id]));
        $this->assertEquals(0, $this->ficheros($c1, $ids['u1c1']));
        // Su otro curso y la otra persona, intactos.
        $this->assertEquals(1, $this->filas('block_pulso_encargos', ['userid' => $u1->id, 'courseid' => $c2->id]));
        $this->assertEquals(2, $this->ficheros($c2, $ids['u1c2']));
        $this->assertEquals(1, $this->filas('block_pulso_encargos', ['userid' => $u2->id]));
        $this->assertEquals(2, $this->ficheros($c1, $ids['u2c1']));
        $this->assertEquals(0, $this->filas('block_pulso_reto_propuestas', ['userid' => $u1->id, 'courseid' => $c1->id]));
        $this->assertEquals(1, $this->filas('block_pulso_retos', ['userid' => $u2->id]));
    }

    public function test_borrar_por_lista(): void {
        [$u1, $u2, $c1, $c2, $ids] = $this->escenario();
        $ctx1 = \context_course::instance($c1->id);

        provider::delete_data_for_users(new approved_userlist($ctx1, 'block_pulso', [$u1->id, $u2->id]));

        $this->assertEquals(0, $this->filas('block_pulso_encargos', ['courseid' => $c1->id]));
        $this->assertEquals(0, $this->ficheros($c1, $ids['u1c1']));
        $this->assertEquals(0, $this->ficheros($c1, $ids['u2c1']));
        $this->assertEquals(1, $this->filas('block_pulso_encargos', ['courseid' => $c2->id]));
        $this->assertEquals(2, $this->ficheros($c2, $ids['u1c2']));
    }

    public function test_borrar_por_contexto_y_ampliacion_anonimizada(): void {
        [$u1, $u2, $c1, $c2, $ids] = $this->escenario();

        provider::delete_data_for_all_users_in_context(\context_course::instance($c1->id));

        foreach (['block_pulso_encargos', 'block_pulso_reto_propuestas', 'block_pulso_retos'] as $t) {
            $this->assertEquals(0, $this->filas($t, ['courseid' => $c1->id]), $t);
            $this->assertGreaterThan(0, $this->filas($t, ['courseid' => $c2->id]), $t);
        }
        $this->assertEquals(0, $this->ficheros($c1, $ids['u1c1']));
        // Las ampliaciones siguen (caché compartida) pero sin persona.
        $this->assertEquals(2, $this->filas('block_pulso_ampliaciones', ['courseid' => $c1->id]));
        $this->assertEquals(2, $this->filas('block_pulso_ampliaciones', ['courseid' => $c1->id, 'userid' => 0]));
        // El otro curso conserva a su persona.
        $this->assertEquals(1, $this->filas('block_pulso_ampliaciones', ['courseid' => $c2->id, 'userid' => $u1->id]));
        // Y tras anonimizar, ya no aparece en el curso.
        $this->assertEmpty(array_intersect([\context_course::instance($c1->id)->id],
            provider::get_contexts_for_userid($u1->id)->get_contextids()));
    }

    public function test_pre_course_delete_limpia_todo(): void {
        global $DB;
        [$u1, , $c1, $c2] = $this->escenario();
        require_once(__DIR__ . '/../../lib.php');
        $now = time();
        foreach (['block_pulso_content_chunks' => ['chunk_text' => 'x'], 'block_pulso_full_text' => ['texto' => 'x']] as $t => $extra) {
            foreach ([$c1, $c2] as $c) {
                $DB->insert_record($t, (object)(['courseid' => $c->id, 'cmid' => 1, 'timecreated' => $now, 'timemodified' => $now] + $extra));
            }
        }
        set_config('lastindexqueue_' . $c1->id, $now, 'block_pulso');
        set_config('enabled_course_' . $c1->id, 1, 'block_pulso');

        block_pulso_pre_course_delete($c1);

        foreach (['block_pulso_content_chunks', 'block_pulso_full_text', 'block_pulso_encargos', 'block_pulso_ampliaciones',
                  'block_pulso_reto_propuestas', 'block_pulso_retos'] as $t) {
            $this->assertEquals(0, $this->filas($t, ['courseid' => $c1->id]), $t);
            $this->assertGreaterThan(0, $this->filas($t, ['courseid' => $c2->id]), $t);
        }
        $this->assertFalse(get_config('block_pulso', 'lastindexqueue_' . $c1->id));
        $this->assertFalse(get_config('block_pulso', 'enabled_course_' . $c1->id));
    }
}
