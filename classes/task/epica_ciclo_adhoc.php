<?php
/**
 * Adhoc task: UN paso del ciclo con Epica (firmar+encargar, o sondear) por
 * ejecución, nunca el ciclo entero.
 *
 * Por qué: una generación tarda minutos y la concurrencia de Epica es 2,
 * compartida con otras herramientas. Si esta tarea durmiera hasta que el
 * encargo estuviera listo, una sola clase encargando a la vez dejaría un
 * proceso de cron bloqueado casi una hora por encargo. En su lugar, cada
 * ejecución hace un paso (epica_client::procesar_paso()) y, si toca seguir,
 * se vuelve a encolar A SÍ MISMA con set_next_run_time() para la cadencia que
 * toque — nunca sleep().
 *
 * Se encola desde creation_quota::dispatch_to_epica() al crear el encargo, y
 * desde aquí mismo en cada paso intermedio. Un encargo en estado terminal
 * (listo/fallado/desconocido/ensayo) no se reencola nunca — ver CLAUDE.md.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../epica_client.php');

class epica_ciclo_adhoc extends \core\task\adhoc_task {

    /** @var string[] Estados que no se vuelven a tocar jamás. */
    const TERMINALES = ['listo', 'fallado', 'desconocido', 'ensayo'];

    public function get_name(): string {
        return get_string('task_epica_ciclo_adhoc', 'block_pulso');
    }

    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $encargoid = (int)($data->encargoid ?? 0);
        if ($encargoid <= 0) {
            mtrace('Pulso Epica (adhoc): sin encargoid en custom data, nada que hacer.');
            return;
        }

        $encargo = $DB->get_record('block_pulso_encargos', ['id' => $encargoid]);
        if (!$encargo) {
            mtrace("Pulso Epica (adhoc): el encargo {$encargoid} ya no existe.");
            return;
        }

        if (in_array($encargo->status, self::TERMINALES, true)) {
            mtrace("Pulso Epica (adhoc): encargo {$encargoid} ya está en estado terminal '{$encargo->status}'.");
            return;
        }

        $resultado = \block_pulso\epica_client::procesar_paso($encargo);

        if (!empty($resultado['requeue'])) {
            $siguiente = new self();
            $siguiente->set_component('block_pulso');
            $siguiente->set_custom_data(['encargoid' => $encargoid]);
            $siguiente->set_next_run_time(time() + max(1, (int)$resultado['delay']));
            \core\task\manager::queue_adhoc_task($siguiente);
        }
    }
}
