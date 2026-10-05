<?php
/**
 * Límite de uso del chat por persona (v1.31.0).
 *
 * Sin tope, una clase entera o un script pueden agotar el límite de la organización
 * en Anthropic para TODOS los cursos. Dos ventanas, ajustes de plugin (0 = sin límite):
 *   - chat_max_por_minuto_usuario (6): ventana móvil de 60 s.
 *   - chat_max_por_dia_usuario (150): por día natural del servidor.
 *
 * Se cuenta en MUC (definición `chatrate`, db/caches.php) y se comprueba ANTES de leer
 * contexto, RAG o llamar a Anthropic. Falla ABIERTO: si la caché no está disponible
 * (plugin sin actualizar, store caído) el chat sigue funcionando — un límite de ritmo
 * no puede tumbar el servicio que protege. Cuenta cada pregunta admitida, también las
 * que se resuelven por la ruta directa o se deniegan por rol: el límite protege el
 * servidor entero, no solo el gasto en tokens.
 *
 * Limitación conocida: MUC no es atómico entre procesos; un candado corto por usuario
 * cierra la carrera típica (doble envío), y sin candado disponible se sigue sin él.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/pulso_error.php');

class chat_rate_limiter {

    /** Ventana móvil del límite por minuto (s). */
    const WINDOW_S = 60;

    /**
     * Registra una pregunta del usuario y lanza pulso_error si pasa algún tope.
     *
     * @param int $userid
     * @throws pulso_error rate_limited | rate_limited_day (HTTP 429)
     */
    public static function check(int $userid): void {
        $perminute = max(0, (int)self::setting('chat_max_por_minuto_usuario', 6));
        $perday = max(0, (int)self::setting('chat_max_por_dia_usuario', 150));
        if ($perminute === 0 && $perday === 0) {
            return;
        }

        try {
            $cache = \cache::make('block_pulso', 'chatrate');
        } catch (\Throwable $e) {
            return; // Definición sin registrar todavía: sin límite hasta pasar por Notificaciones.
        }

        $lock = null;
        try {
            $lock = \core\lock\lock_config::get_lock_factory('block_pulso_chatrate')
                ->get_lock('chat_' . $userid, 2, 10);
        } catch (\Throwable $e) {
            $lock = null;
        }

        try {
            self::count($cache, $userid, $perminute, $perday);
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    private static function count(\cache $cache, int $userid, int $perminute, int $perday): void {
        $now = time();
        $day = date('Ymd', $now);
        $key = 'u' . $userid;

        $state = $cache->get($key);
        if (!is_array($state) || ($state['day'] ?? '') !== $day) {
            $state = ['day' => $day, 'n' => 0, 'ts' => []];
        }
        $recent = array_values(array_filter((array)($state['ts'] ?? []), function ($t) use ($now) {
            return (int)$t > $now - self::WINDOW_S;
        }));

        if ($perminute > 0 && count($recent) >= $perminute) {
            throw new pulso_error('rate_limited', '', 429);
        }
        if ($perday > 0 && (int)$state['n'] >= $perday) {
            throw new pulso_error('rate_limited_day', '', 429);
        }

        $recent[] = $now;
        $state['ts'] = $recent;
        $state['n'] = (int)$state['n'] + 1;
        try {
            $cache->set($key, $state);
        } catch (\Throwable $e) {
            // Nunca romper el chat por un fallo del store de caché.
        }
    }

    private static function setting(string $name, $default) {
        $value = get_config('block_pulso', $name);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}
