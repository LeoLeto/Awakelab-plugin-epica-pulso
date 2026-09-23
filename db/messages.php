<?php
/**
 * Message provider definitions for block_pulso.
 *
 * Un solo proveedor: el aviso de que un encargo de creación (Epica) terminó
 * -listo o fallado-, mandado por classes/epica_client.php al llegar a un
 * estado terminal real. Nunca en 'ensayo' (no es una generación real) ni en
 * cada sondeo: un aviso por encargo, solo al acabar.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'epica_encargo' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN + MESSAGE_DEFAULT_LOGGEDOFF,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDOFF,
        ],
    ],
];
