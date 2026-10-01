<?php
/**
 * Error de negocio de Retos, listo para devolver al navegador: un codigo
 * estable (`motivo`, el que decide el cliente — nunca el texto), un mensaje en
 * castellano para la persona, el HTTP que corresponde y campos extra opcionales
 * (esperaS, reintentable). Ver classes/retos_service.php y api_retos.php.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

class reto_error extends \Exception {

    /** @var string Codigo estable del error (p. ej. cuota-agotada, reto-desconocido). */
    public $motivo;

    /** @var int Codigo HTTP con el que contesta el endpoint. */
    public $status;

    /** @var array Campos extra para el cliente (esperaS, reintentable…). */
    public $extra;

    public function __construct(string $motivo, string $mensaje, int $status = 400, array $extra = []) {
        parent::__construct($mensaje);
        $this->motivo = $motivo;
        $this->status = $status;
        $this->extra = $extra;
    }
}
