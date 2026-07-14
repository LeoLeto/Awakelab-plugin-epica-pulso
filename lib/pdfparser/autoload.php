<?php
/**
 * Autoloader minimo (PSR-4, sin Composer) para la libreria smalot/pdfparser
 * vendorizada dentro de este plugin (ver LICENSE.txt — LGPL-3.0).
 *
 * Mapea el namespace Smalot\PdfParser\ a src/Smalot/PdfParser/ dentro de
 * esta misma carpeta. No requiere vendor/autoload.php de Composer.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

spl_autoload_register(function (string $class): void {
    $prefix = 'Smalot\\PdfParser\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/Smalot/PdfParser/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
