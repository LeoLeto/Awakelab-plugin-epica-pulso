<?php
/**
 * CHAT SIMPLE VIEW - PHP + HTML + CSS inline
 * 
 * Archivo simple para renderizar el chat sin dependencias complejas.
 * Incluye todo: HTML, CSS y lógica PHP básica.
 * 
 * Uso: require_once(__DIR__ . '/chat_simple_view.php');
 *      render_chat_simple($courseid, $context);
 * 
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Renderizar chat simple
 *
 * @param int $courseid
 * @param object $context Contexto del curso
 * @param bool $isteacher ¿Tiene 'block/pulso:viewanalytics'? Con false se
 *                        renderiza la versión de ALUMNO: sin tarjetas de
 *                        analítica, saludo y capacidades de contenido. Es solo
 *                        presentación — el bloqueo de datos está en servidor
 *                        (chat_pipeline / data_retriever / rag_retriever).
 * @param bool $cancreate ¿Tiene 'block/pulso:createactivity'? Con false se
 *                        elimina del HTML el bloque "Crear" (encargos a
 *                        Epica). Por defecto lo tienen alumnado y
 *                        profesorado: existe para que un centro se lo pueda
 *                        quitar sin tocar código. Es solo presentación — el
 *                        bloqueo real está en los endpoints api_create_*.php.
 * @return string
 */
function render_chat_simple($courseid, $context, $isteacher = true, $cancreate = true) {
    global $OUTPUT, $USER, $CFG;

    // Construir URL base correcta para AJAX
    $api_url = $CFG->wwwroot . '/blocks/pulso/api_chat.php';
    $stream_url = $CFG->wwwroot . '/blocks/pulso/api_chat_stream.php';
    $create_form_url = $CFG->wwwroot . '/blocks/pulso/api_create_form.php';
    $create_submit_url = $CFG->wwwroot . '/blocks/pulso/api_create_submit.php';
    $create_status_url = $CFG->wwwroot . '/blocks/pulso/api_create_status.php';
    $ampliacion_url = $CFG->wwwroot . '/blocks/pulso/api_ampliacion.php';
    $retos_url = $CFG->wwwroot . '/blocks/pulso/api_retos.php';
    $historial_url = $CFG->wwwroot . '/blocks/pulso/epica_historial.php';

    // Leer la versión directamente de version.php (no de la BD) para que el
    // badge del header refleje siempre el código desplegado, incluso antes
    // de ejecutar la actualización de Moodle.
    $plugin = new stdClass();
    include(__DIR__ . '/version.php');
    $pulso_release = 'v' . ($plugin->release ?? $plugin->version ?? '?');

    // Token anti-CSRF de la sesión: los endpoints lo exigen con require_sesskey().
    $pulso_sesskey = sesskey();

    // Rol para la UI (capacidades y sugerencias). No es un control de acceso.
    $pulso_isteacher = !empty($isteacher) ? 'true' : 'false';

    // Inyectar variables globales JavaScript
    $js_init = <<<JSINIT
    <script>
        // Variables globales para AJAX
        window.courseid = {$courseid};
        window.apiUrl = '{$api_url}';
        window.streamApiUrl = '{$stream_url}';
        window.apiCreateFormUrl = '{$create_form_url}';
        window.apiCreateSubmitUrl = '{$create_submit_url}';
        window.apiCreateStatusUrl = '{$create_status_url}';
        window.apiAmpliacionUrl = '{$ampliacion_url}';
        window.apiRetosUrl = '{$retos_url}';
        window.apiHistorialUrl = '{$historial_url}';
        window.pulsoSesskey = '{$pulso_sesskey}';
        // Solo para adaptar la UI: el servidor decide qué datos se devuelven.
        window.pulsoIsTeacher = {$pulso_isteacher};

        // T2.5.3: Recuperar historial de sessionStorage (persiste entre recargas)
        try {
            var savedHistory = sessionStorage.getItem('pulso_history_' + {$courseid});
            window.conversationHistory = savedHistory ? JSON.parse(savedHistory) : [];
        } catch(e) {
            window.conversationHistory = [];
        }
    </script>
    JSINIT;
    
    // HTML y CSS combinados
    $html = <<<'HTML'
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');

        /* ==================================================================
           PULSO AI — Identidad Awakelab 2026, tema CLARO (variante B)
           Cuerpo claro con cabecera azul profunda de marca como ancla oscura;
           cian como acento funcional (iconos, bordes, foco), nunca como color
           de texto sobre superficie clara. Poppins.
           ================================================================== */
        .pulso-chat-bubble,
        .pulso-chat-container {
            --pulso-ink: #27334F;                      /* texto principal */
            --pulso-deep: #003670;                     /* azul profundo de marca (cabecera) */
            --pulso-navy: #003670;                     /* azul profundo secundario */
            --pulso-slate: #34547A;                     /* texto secundario */
            --pulso-muted: #3B6996;                     /* texto terciario / hints */
            --pulso-cyan: #0B93AA;                      /* acento (iconos/bordes/foco, no texto) */
            --pulso-cyan-soft: #D9FBFF;                 /* fondo del bloque de siguiente paso */
            --pulso-teal: #0B93AA;                      /* acento funcional (focus) */
            --pulso-teal-ink: #0B93AA;                  /* marcadores */
            --pulso-bg: #FFFFFF;                        /* fondo del panel */
            --pulso-surface: #F7F9FD;                   /* tarjetas / superficies */
            --pulso-surface-2: #EDF1FA;                 /* cabecera de tabla / anidado */
            --pulso-line: #DCE3F2;                      /* bordes y divisores */
            --pulso-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* ========== BOTÓN CIRCULAR INICIAL ========== */
        .pulso-chat-bubble {
            position: fixed;
            bottom: 28px;
            right: 32px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(160deg, #003670 0%, #012142 62%);
            color: #D9FBFF;
            border: 1px solid rgba(17, 234, 234, 0.35);
            cursor: pointer;
            z-index: 9999;
            box-shadow: 0 8px 24px rgba(1, 25, 50, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .pulso-chat-bubble img {
            width: 30px;
            height: 30px;
            display: block;
            pointer-events: none;
        }

        .pulso-chat-bubble:hover {
            transform: scale(1.08);
            box-shadow: 0 10px 28px rgba(1, 25, 50, 0.4), 0 0 0 4px rgba(17, 234, 234, 0.18);
        }

        .pulso-chat-bubble:active {
            transform: scale(0.95);
        }

        .pulso-chat-bubble.has-chat {
            animation: pulso-pulse 2.4s ease-in-out infinite;
        }

        @keyframes pulso-pulse {
            0%, 100% { box-shadow: 0 8px 24px rgba(1, 25, 50, 0.35), 0 0 0 0 rgba(17, 234, 234, 0.35); }
            50% { box-shadow: 0 8px 24px rgba(1, 25, 50, 0.35), 0 0 0 7px rgba(17, 234, 234, 0); }
        }

        .pulso-chat-bubble.drawer-collapsed {
            bottom: 88px;
        }

        /* ========== CHAT CONTAINER (oculto por defecto) ========== */
        .pulso-chat-container {
            display: none;
            position: fixed;
            top: auto;
            bottom: 100px;
            right: 32px;
            width: min(640px, calc(100vw - 48px));
            height: min(600px, calc(100vh - 130px));
            min-width: 300px;
            min-height: min(300px, calc(100vh - 112px));
            max-width: calc(100vw - 48px);
            max-height: calc(100vh - 112px);
            z-index: 9999;
            background: var(--pulso-bg);
            border: 1px solid var(--pulso-line);
            border-radius: 16px;
            overflow: hidden;
            flex-direction: column;
            box-shadow: 0 24px 64px rgba(1, 25, 50, 0.18);
            font-family: var(--pulso-font);
            font-size: 0.92rem;
            color: var(--pulso-ink);
            box-sizing: border-box;
            resize: both;
            animation: pulso-slideUp 0.3s ease-out;
        }

        .pulso-chat-container :focus-visible,
        .pulso-chat-bubble:focus-visible {
            outline: 2px solid var(--pulso-teal);
            outline-offset: 2px;
        }

        .pulso-chat-container.is-open {
            display: flex;
        }

        /* El panel sube 50px: height/max-height descuentan el bottom real (100px
           + 12px de margen superior = 112px; 150px + 12px = 162px), para que la
           cabecera nunca se salga por arriba en ventanas bajas. */
        .pulso-chat-container.drawer-collapsed {
            bottom: 150px;
            height: min(600px, calc(100vh - 180px));
            min-height: min(300px, calc(100vh - 162px));
            max-height: calc(100vh - 162px);
        }

        @keyframes pulso-slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .pulso-chat-header {
            background: linear-gradient(150deg, #003670 0%, #012142 70%);
            color: #ffffff;
            padding: 12px 16px;
            border-bottom: 2px solid rgba(17, 234, 234, 0.45);
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
            cursor: move;
            flex-shrink: 0;
        }

        .pulso-header-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .pulso-header-logo {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
        }

        .pulso-chat-header h4 {
            margin: 0;
            font-size: 0.98rem;
            font-weight: 600;
            font-family: var(--pulso-font);
            letter-spacing: 0.01em;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }

        .pulso-header-sub {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.7rem;
            font-weight: 500;
            color: #D9FBFF;
            opacity: 0.85;
            margin-top: 1px;
        }

        .pulso-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--pulso-cyan);
            box-shadow: 0 0 6px rgba(17, 234, 234, 0.9);
            flex-shrink: 0;
        }

        .pulso-version-badge {
            display: inline-block;
            font-size: 0.64rem;
            font-weight: 600;
            color: var(--pulso-cyan-soft);
            background: rgba(17, 234, 234, 0.14);
            border: 1px solid rgba(17, 234, 234, 0.3);
            padding: 1px 8px;
            border-radius: 999px;
            margin-left: 8px;
            vertical-align: middle;
            letter-spacing: 0.04em;
        }

        .header-controls {
            display: flex;
            gap: 6px;
            margin-left: 12px;
        }

        .header-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: #ffffff;
            width: 30px;
            height: 30px;
            padding: 0;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .header-btn svg {
            width: 15px;
            height: 15px;
            display: block;
        }

        .header-btn:hover {
            background: rgba(17, 234, 234, 0.18);
            border-color: rgba(17, 234, 234, 0.5);
        }

        .header-btn:active {
            transform: scale(0.95);
        }

        .pulso-chat-messages {
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: auto;
            padding: 16px 14px;
            background: var(--pulso-bg);
            min-height: 120px;
            width: 100%;
        }

        .pulso-chat-messages::-webkit-scrollbar {
            width: 8px;
        }

        .pulso-chat-messages::-webkit-scrollbar-thumb {
            background: rgba(39, 51, 79, 0.18);
            border-radius: 8px;
        }

        .pulso-chat-messages::-webkit-scrollbar-thumb:hover {
            background: rgba(39, 51, 79, 0.32);
        }

        /* ========== PANTALLA DE INICIO (acciones predefinidas) ========== */
        .pulso-home {
            padding: 4px 2px 10px;
        }

        .pulso-home-hello {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 4px 0 18px;
        }

        .pulso-home-avatar {
            flex-shrink: 0;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--pulso-deep) url('https://media.awakelab.world/MARCA_AWK26/awakelab_isotipo_fondo-oscuro_transparente.png') center / 24px 24px no-repeat;
            border: 1px solid rgba(11, 147, 170, 0.4);
        }

        .pulso-home-hello h5 {
            margin: 0;
            font-size: 1.02rem;
            font-weight: 600;
            font-family: var(--pulso-font);
            color: var(--pulso-ink);
            line-height: 1.4;
        }

        /* Botón "¿Qué puede hacer Pulso?" — descubrimiento rápido para recién llegados. */
        .pulso-home-help-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            box-sizing: border-box;
            margin: 0 0 18px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(11, 147, 170, 0.4);
            background: linear-gradient(135deg, rgba(11, 147, 170, 0.12), rgba(11, 147, 170, 0.03));
            color: var(--pulso-ink);
            font-family: var(--pulso-font);
            font-size: 0.9rem;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.15s;
        }

        .pulso-home-help-btn:hover {
            border-color: rgba(11, 147, 170, 0.7);
        }

        .pulso-home-help-btn:active {
            transform: scale(0.99);
        }

        .pulso-home-help-btn svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            color: var(--pulso-cyan);
        }

        .pulso-home-help-btn .pulso-home-help-sub {
            display: block;
            font-weight: 400;
            font-size: 0.76rem;
            color: var(--pulso-slate);
            margin-top: 1px;
        }

        /* Bloque de preguntas sugeridas en lista (evita el corte en 2 columnas). */
        .pulso-suggest {
            padding: 10px 14px;
        }

        .pulso-suggest-title {
            font-weight: 600;
            color: var(--pulso-navy);
            font-size: 0.88rem;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pulso-suggest ul {
            margin: 0;
            padding-left: 20px;
            line-height: 1.55;
        }

        .pulso-suggest li {
            margin: 5px 0;
        }

        .pulso-suggest li::marker {
            color: var(--pulso-cyan);
        }

        .pulso-home-section {
            margin-bottom: 18px;
        }

        .pulso-home-section-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .pulso-home-section-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--pulso-ink);
        }

        .pulso-home-context-chip {
            font-size: 0.66rem;
            font-weight: 500;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(39, 51, 79, 0.06);
            border: 1px solid var(--pulso-line);
            color: var(--pulso-slate);
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pulso-home-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .pulso-action-card {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 14px;
            padding: 14px;
            background: var(--pulso-surface);
            border: 1px solid var(--pulso-line);
            border-radius: 14px;
            cursor: pointer;
            text-align: left;
            color: var(--pulso-ink);
            font-family: var(--pulso-font);
            transition: border-color 0.2s, background 0.2s, transform 0.15s;
        }

        .pulso-action-card:hover {
            border-color: rgba(11, 147, 170, 0.55);
            background: var(--pulso-surface-2);
            transform: translateY(-1px);
        }

        .pulso-action-card:active {
            transform: translateY(0);
        }

        /* Sección de contenido del curso: tarjetas con tinte azul de marca. */
        .pulso-home-section.course .pulso-action-card {
            background: #F0F3FC;
            border-color: rgba(78, 126, 165, 0.35);
        }

        .pulso-home-section.course .pulso-action-card:hover {
            border-color: rgba(11, 147, 170, 0.55);
            background: #E2E6F2;
        }

        .pulso-action-icon {
            width: 20px;
            height: 20px;
            color: var(--pulso-cyan);
        }

        .pulso-action-label {
            font-size: 0.84rem;
            font-weight: 500;
            line-height: 1.3;
            padding-right: 30px;
        }

        .pulso-action-chevron {
            position: absolute;
            right: 12px;
            bottom: 12px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: rgba(39, 51, 79, 0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pulso-slate);
        }

        .pulso-action-chevron svg {
            width: 12px;
            height: 12px;
        }

        /* ========== BLOQUE "CREAR" (encargos a Epica) ==========
           Distinto a propósito de los otros dos bloques: aquellos son
           preguntas, este es una acción que cuesta dinero y genera algo.
           Fondo navy sólido + texto blanco (nunca cian como color de texto,
           regla del tema claro), acento cian solo en el icono. */
        /* Fila con los CTA de "Crear" (infografía / juego / ampliar / reto):
           rejilla 2×2; 1 columna en pantallas estrechas. Mismo patrón
           repetido, no un formulario por herramienta. */
        .pulso-create-cta-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .pulso-create-cta-row .pulso-create-cta {
            min-width: 0;
        }

        @media (max-width: 420px) {
            .pulso-create-cta-row {
                grid-template-columns: 1fr;
            }
        }

        .pulso-create-cta {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            box-sizing: border-box;
            padding: 14px 16px;
            border: none;
            border-radius: 14px;
            background: var(--pulso-navy);
            color: #ffffff;
            font-family: var(--pulso-font);
            cursor: pointer;
            text-align: left;
            box-shadow: 0 2px 10px rgba(1, 25, 50, 0.18);
            transition: transform 0.15s, box-shadow 0.2s;
        }

        .pulso-create-cta:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(1, 25, 50, 0.26);
        }

        .pulso-create-cta:active {
            transform: translateY(0);
        }

        .pulso-create-cta-icon {
            flex-shrink: 0;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(11, 147, 170, 0.28);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pulso-create-cta-icon svg {
            width: 20px;
            height: 20px;
            color: var(--pulso-cyan-soft);
        }

        .pulso-create-cta-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .pulso-create-cta-title {
            font-size: 0.9rem;
            font-weight: 600;
        }

        .pulso-create-cta-sub {
            font-size: 0.74rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.78);
        }

        /* Pantalla de "Crear infografía": NO es un mensaje de chat, es una
           pantalla propia que sustituye a la home/mensajes mientras está
           abierta (misma capa, se alternan con una clase en #pulso-messages
           para no duplicar el scroll). */
        .pulso-create-panel {
            display: none;
        }

        #pulso-messages.pulso-showing-create > *:not(.pulso-create-panel) {
            display: none;
        }

        #pulso-messages.pulso-showing-create .pulso-create-panel {
            display: block;
        }

        .pulso-create-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }

        .pulso-create-back {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1px solid var(--pulso-line);
            background: var(--pulso-surface);
            color: var(--pulso-ink);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .pulso-create-head svg {
            width: 16px;
            height: 16px;
        }

        .pulso-create-head h4 {
            margin: 0;
            font-size: 0.98rem;
            font-weight: 600;
            color: var(--pulso-ink);
            font-family: var(--pulso-font);
        }

        .pulso-create-body {
            font-family: var(--pulso-font);
            color: var(--pulso-ink);
            font-size: 0.88rem;
        }

        .pulso-create-field {
            margin-bottom: 14px;
        }

        .pulso-create-field label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--pulso-slate);
            margin-bottom: 6px;
        }

        .pulso-create-field select,
        .pulso-create-field textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid var(--pulso-line);
            border-radius: 10px;
            font-family: var(--pulso-font);
            font-size: 0.86rem;
            color: var(--pulso-ink);
            background: var(--pulso-surface);
        }

        .pulso-create-field select:focus,
        .pulso-create-field textarea:focus {
            outline: none;
            border-color: var(--pulso-cyan);
            box-shadow: 0 0 0 2px rgba(11, 147, 170, 0.2);
        }

        .pulso-create-field textarea {
            resize: vertical;
            min-height: 76px;
        }

        .pulso-create-hint {
            font-size: 0.72rem;
            color: var(--pulso-muted);
            margin-top: 4px;
            min-height: 1em;
        }

        .pulso-create-hint.warn {
            color: #8A6100;
        }

        /* Tres tipos de juego ya escritos (carta 9): solo en "Crear juego" */
        .pulso-create-ejemplos {
            margin-top: 8px;
        }

        .pulso-create-ejemplos-label {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--pulso-slate);
            margin-bottom: 6px;
        }

        .pulso-create-ejemplos-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .pulso-create-ejemplo {
            flex: 1 1 140px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
            padding: 8px 12px;
            border: 1px solid var(--pulso-line);
            border-radius: 12px;
            background: var(--pulso-surface-2);
            color: var(--pulso-ink);
            font-family: var(--pulso-font);
            text-align: left;
            cursor: pointer;
        }

        .pulso-create-ejemplo:hover {
            border-color: var(--pulso-teal);
        }

        .pulso-create-ejemplo:focus-visible {
            outline: 2px solid var(--pulso-teal);
            outline-offset: 2px;
        }

        .pulso-create-ejemplo-title {
            font-size: 0.82rem;
            font-weight: 600;
        }

        .pulso-create-ejemplo-desc {
            font-size: 0.72rem;
            color: var(--pulso-slate);
        }

        .pulso-create-submit {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 999px;
            background: var(--pulso-navy);
            color: #ffffff;
            font-family: var(--pulso-font);
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
        }

        .pulso-create-submit:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .pulso-create-notice {
            padding: 14px;
            border-radius: 12px;
            background: var(--pulso-surface);
            border-left: 3px solid var(--pulso-cyan);
            font-size: 0.86rem;
            line-height: 1.5;
        }

        /* ========== ESTADO DEL ENCARGO (paso 4) ==========
           Progreso del encargo recien creado, o de uno abierto desde la
           galeria. Mismos colores de estado que el resto del plugin (nunca
           cian como texto): exito #0F7A57, aviso #8A6100, error #B3261E. */
        .pulso-create-status {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .pulso-create-status-head {
            display: flex;
            align-items: center;
        }

        .pulso-create-notice-inline {
            padding: 10px 12px;
            border-radius: 10px;
            background: var(--pulso-surface);
            font-size: 0.82rem;
            line-height: 1.5;
            border-left: 3px solid var(--pulso-line);
        }

        .pulso-create-notice-inline.warn {
            border-left-color: #8A6100;
            color: #8A6100;
        }

        .pulso-create-notice-inline.danger {
            border-left-color: #B3261E;
            color: #B3261E;
        }

        .pulso-create-image {
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--pulso-line);
            display: block;
        }

        .pulso-create-image-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--pulso-ink);
        }

        .pulso-create-image-tema {
            font-size: 0.8rem;
            color: var(--pulso-slate);
            margin-top: -6px;
        }

        .pulso-create-image-actions {
            display: flex;
            gap: 10px;
        }

        .pulso-create-image-actions a {
            flex: 1;
            text-align: center;
            padding: 9px 12px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            background: var(--pulso-navy);
            color: #ffffff !important;
        }

        .pulso-create-back-link {
            align-self: flex-start;
            border: none;
            background: transparent;
            color: var(--pulso-slate);
            font-family: var(--pulso-font);
            font-size: 0.8rem;
            cursor: pointer;
            padding: 4px 0;
            text-decoration: underline;
        }

        .pulso-create-sobre summary {
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--pulso-slate);
        }

        .pulso-create-sobre pre {
            margin-top: 8px;
            padding: 10px;
            border-radius: 10px;
            background: var(--pulso-surface-2);
            font-size: 0.72rem;
            line-height: 1.4;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Galeria de encargos anteriores del usuario en el curso. */
        .pulso-create-gallery-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--pulso-slate);
            margin: 18px 0 8px;
        }

        /* «Mi historial» (carta 10): enlace discreto, texto slate (nunca cian). */
        .pulso-historial-link {
            background: none;
            border: 0;
            padding: 2px 4px;
            font: inherit;
            font-size: 0.72rem;
            font-weight: 500;
            color: var(--pulso-slate);
            text-decoration: underline;
            cursor: pointer;
            border-radius: 4px;
        }

        .pulso-historial-link.head {
            margin-left: auto;
        }

        .pulso-historial-link:hover {
            color: var(--pulso-navy);
        }

        .pulso-create-gallery-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .pulso-create-gallery-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 0;
            border: 1px solid var(--pulso-line);
            border-radius: 10px;
            background: var(--pulso-surface);
            cursor: pointer;
            overflow: hidden;
            font-family: var(--pulso-font);
        }

        .pulso-create-gallery-item img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            display: block;
        }

        /* Tarjeta de juego: sin imagen (no la hay), icono + título de respaldo. */
        .pulso-create-gallery-icon {
            width: 100%;
            aspect-ratio: 1 / 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--pulso-surface-2);
            color: var(--pulso-muted);
        }

        .pulso-create-gallery-icon svg {
            width: 26px;
            height: 26px;
        }

        .pulso-create-gallery-item-title {
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--pulso-ink);
            padding: 0 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* "Juego" / "Infografía" — nunca cian como color de texto. */
        .pulso-create-gallery-tag {
            font-size: 0.62rem;
            font-weight: 600;
            color: var(--pulso-slate);
            padding: 0 4px;
        }

        .pulso-create-gallery-date {
            font-size: 0.65rem;
            color: var(--pulso-muted);
            padding: 0 4px 4px;
        }

        /* ========== AMPLIAR RECURSO (resultados) ==========
           Tarjetas de vídeo/artículo enlazadas a contenido EXTERNO. Nunca
           cian como texto: tinta/slate/muted; "Acceso abierto" en success. */
        .pulso-amp {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .pulso-amp-loading {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px;
            border-radius: 12px;
            background: var(--pulso-surface);
            border-left: 3px solid var(--pulso-cyan);
            font-size: 0.86rem;
            line-height: 1.5;
            color: var(--pulso-ink);
        }

        .pulso-amp-head {
            font-size: 0.92rem;
            color: var(--pulso-ink);
            overflow-wrap: anywhere;
        }

        .pulso-amp-section-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--pulso-slate);
            margin-bottom: 6px;
        }

        .pulso-amp-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .pulso-amp-card {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 8px;
            border: 1px solid var(--pulso-line);
            border-radius: 12px;
            background: var(--pulso-surface);
            color: var(--pulso-ink) !important;
            text-decoration: none;
            font-family: var(--pulso-font);
        }

        a.pulso-amp-card:hover {
            border-color: var(--pulso-cyan);
            box-shadow: 0 2px 8px rgba(1, 25, 50, 0.12);
        }

        .pulso-amp-thumb {
            flex-shrink: 0;
            width: 96px;
            aspect-ratio: 16 / 9;
            border-radius: 8px;
            object-fit: cover;
            background: var(--pulso-surface-2);
        }

        .pulso-amp-thumb-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pulso-muted);
        }

        .pulso-amp-thumb-empty svg {
            width: 24px;
            height: 24px;
        }

        .pulso-amp-card-text {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .pulso-amp-card-title {
            font-size: 0.84rem;
            font-weight: 600;
            line-height: 1.35;
            color: var(--pulso-ink);
        }

        .pulso-amp-card-meta {
            font-size: 0.74rem;
            color: var(--pulso-slate);
            line-height: 1.4;
        }

        .pulso-amp-tag {
            align-self: flex-start;
            font-size: 0.68rem;
            font-weight: 600;
            padding: 1px 8px;
            border-radius: 999px;
            color: #0F7A57;
            background: rgba(15, 122, 87, 0.12);
        }

        .pulso-amp-tag-lang {
            color: #34547A;
            background: rgba(52, 84, 122, 0.10);
        }

        .pulso-amp-footnote {
            font-size: 0.72rem;
            color: var(--pulso-muted);
        }

        .pulso-amp-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        /* ========== CREAR RETO (formulario, propuestas, reto elegido) ==========
           Colores de estado del tema claro (éxito #0F7A57, aviso #8A6100,
           error #B3261E); el cian solo en bordes/foco, nunca como texto. */
        .pulso-retos {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .pulso-retos-doc-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--pulso-ink);
            overflow-wrap: anywhere;
        }

        .pulso-retos-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .pulso-retos-chip {
            font-size: 0.68rem;
            padding: 1px 8px;
            border-radius: 999px;
            color: #34547A;
            background: rgba(52, 84, 122, 0.10);
            overflow-wrap: anywhere;
        }

        .pulso-reto-card {
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid var(--pulso-line);
            border-radius: 12px;
            background: var(--pulso-surface);
            color: var(--pulso-ink);
            font-family: var(--pulso-font);
            text-align: left;
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.2s;
        }

        .pulso-reto-card:hover:not(:disabled) {
            border-color: var(--pulso-cyan);
            box-shadow: 0 2px 8px rgba(1, 25, 50, 0.12);
        }

        .pulso-reto-card:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .pulso-reto-top {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .pulso-reto-icon {
            flex-shrink: 0;
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: var(--pulso-surface-2);
            color: var(--pulso-cyan);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pulso-reto-icon svg {
            width: 20px;
            height: 20px;
        }

        .pulso-reto-title {
            font-size: 0.86rem;
            font-weight: 600;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .pulso-reto-desc {
            font-size: 0.78rem;
            line-height: 1.45;
            color: var(--pulso-slate);
            overflow-wrap: anywhere;
        }

        .pulso-reto-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px 6px;
        }

        .pulso-reto-diff {
            font-size: 0.68rem;
            font-weight: 600;
            padding: 1px 8px;
            border-radius: 999px;
            color: #34547A;
            background: rgba(52, 84, 122, 0.10);
        }

        .pulso-reto-diff.ok { color: #0F7A57; background: rgba(15, 122, 87, 0.12); }
        .pulso-reto-diff.warn { color: #8A6100; background: rgba(138, 97, 0, 0.14); }
        .pulso-reto-diff.danger { color: #B3261E; background: rgba(179, 38, 30, 0.12); }

        .pulso-reto-min {
            font-size: 0.72rem;
            color: var(--pulso-slate);
        }

        .pulso-reto-cta {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--pulso-navy);
        }

        .pulso-create-secondary {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--pulso-navy);
            border-radius: 999px;
            background: transparent;
            color: var(--pulso-navy);
            font-family: var(--pulso-font);
            font-weight: 600;
            font-size: 0.86rem;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
            display: block;
        }

        .pulso-create-secondary:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .pulso-retos-msg {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .pulso-retos-msg:empty {
            display: none;
        }

        .pulso-create-field + .pulso-retos-msg:not(:empty) {
            margin-bottom: 10px;
        }

        .pulso-retos-own {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .pulso-retos-own[hidden] {
            display: none;
        }

        .pulso-retos-own textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid var(--pulso-line);
            border-radius: 10px;
            font-family: var(--pulso-font);
            font-size: 0.86rem;
            color: var(--pulso-ink);
            background: var(--pulso-surface);
            resize: vertical;
            min-height: 64px;
        }

        .pulso-retos-own-count {
            align-self: flex-end;
            font-size: 0.7rem;
            color: var(--pulso-muted);
        }

        .pulso-retos-done {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .pulso-retos-done-head {
            font-size: 1rem;
            font-weight: 600;
            color: #0F7A57;
        }

        a.pulso-retos-open {
            display: block;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
            color: #ffffff !important;
        }

        .pulso-retos-note {
            font-size: 0.76rem;
            color: var(--pulso-slate);
            line-height: 1.45;
        }

        a.pulso-create-gallery-item {
            text-decoration: none;
        }

        .pulso-message {
            margin-bottom: 14px;
            display: flex;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .pulso-message.user {
            justify-content: flex-end;
        }

        .pulso-message.ai {
            justify-content: flex-start;
        }

        /* Avatar del asistente: isotipo sobre disco azul profundo. */
        .pulso-message.ai::before {
            content: '';
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            margin-right: 8px;
            border-radius: 50%;
            background: var(--pulso-deep) url('https://media.awakelab.world/MARCA_AWK26/awakelab_isotipo_fondo-oscuro_transparente.png') center / 16px 16px no-repeat;
            border: 1px solid rgba(17, 234, 234, 0.35);
            align-self: flex-start;
        }

        .pulso-message-content {
            max-width: calc(98% - 36px);
            padding: 11px 14px;
            border-radius: 14px;
            word-wrap: break-word;
            overflow-x: auto;
            font-size: 0.92rem;
            line-height: 1.55;
        }

        .pulso-message.user .pulso-message-content {
            background: var(--pulso-navy);
            color: #ffffff;
            border-bottom-right-radius: 4px;
            max-width: 85%;
            box-shadow: 0 2px 8px rgba(1, 25, 50, 0.2);
        }

        .pulso-message.ai .pulso-message-content {
            background: var(--pulso-surface);
            color: var(--pulso-ink);
            border: 1px solid var(--pulso-line);
            border-top-left-radius: 4px;
            box-shadow: 0 2px 10px rgba(1, 25, 50, 0.1);
            flex: 1 1 auto;
            min-width: 0;
        }

        /* Revelado escalonado del contenido de la respuesta. */
        .pulso-message.ai .pulso-rich-answer > * {
            opacity: 0;
            animation: pulsoFadeUp 0.4s ease forwards;
        }

        .pulso-message.ai .pulso-rich-answer > *:nth-child(1) { animation-delay: 0.03s; }
        .pulso-message.ai .pulso-rich-answer > *:nth-child(2) { animation-delay: 0.12s; }
        .pulso-message.ai .pulso-rich-answer > *:nth-child(3) { animation-delay: 0.21s; }
        .pulso-message.ai .pulso-rich-answer > *:nth-child(4) { animation-delay: 0.3s; }
        .pulso-message.ai .pulso-rich-answer > *:nth-child(5) { animation-delay: 0.39s; }
        .pulso-message.ai .pulso-rich-answer > *:nth-child(n+6) { animation-delay: 0.48s; }

        @keyframes pulsoFadeUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .pulso-rich-answer {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .pulso-rich-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--pulso-navy);
            margin-bottom: 2px;
            line-height: 1.35;
        }

        .pulso-rich-summary {
            background: var(--pulso-surface-2);
            border: 1px solid var(--pulso-line);
            border-left: 3px solid var(--pulso-cyan);
            padding: 10px 12px;
            border-radius: 10px;
            color: var(--pulso-ink);
            line-height: 1.6;
        }

        /* Enlace directo "Ir a ..." de una actividad o recurso del curso. */
        .pulso-goto-wrap {
            margin-top: 4px;
        }

        .pulso-goto-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 10px;
            background: var(--pulso-navy);
            color: #ffffff !important;
            font-weight: 600;
            font-size: 0.9rem;
            line-height: 1.2;
            text-decoration: none !important;
            border: 1px solid var(--pulso-navy);
            transition: filter 0.15s ease, transform 0.15s ease;
        }

        .pulso-goto-link:hover,
        .pulso-goto-link:focus {
            filter: brightness(1.15);
            transform: translateY(-1px);
            text-decoration: none !important;
            color: #ffffff !important;
        }

        .pulso-goto-icon {
            font-size: 1rem;
            line-height: 1;
        }

        .pulso-rich-paragraph {
            margin: 2px 0;
            line-height: 1.65;
            color: var(--pulso-ink);
        }

        .pulso-rich-steps {
            margin: 4px 0;
            padding-left: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
            line-height: 1.65;
        }

        .pulso-rich-steps li {
            margin: 0;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--pulso-line);
            background: var(--pulso-surface-2);
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .pulso-step-badge {
            display: inline-block;
            align-self: flex-start;
            padding: 2px 10px;
            border-radius: 999px;
            background: var(--pulso-navy);
            color: var(--pulso-cyan-soft);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .pulso-step-body {
            color: var(--pulso-ink);
            line-height: 1.62;
        }

        .pulso-rich-bullets {
            margin: 2px 0;
            padding-left: 20px;
            line-height: 1.6;
        }

        .pulso-rich-bullets li {
            margin: 6px 0;
        }

        .pulso-rich-bullets li::marker {
            color: var(--pulso-teal-ink);
        }

        .pulso-meta-card {
            background: var(--pulso-surface-2);
            border: 1px solid var(--pulso-line);
            border-radius: 12px;
            overflow: hidden;
            margin: 4px 0;
        }

        .pulso-meta-row {
            display: grid;
            grid-template-columns: minmax(0, max-content) minmax(0, 1fr);
            gap: 4px 12px;
            align-items: baseline;
            padding: 10px 14px;
            border-bottom: 1px solid var(--pulso-line);
        }

        .pulso-meta-card .pulso-meta-row:last-child {
            border-bottom: none;
        }

        .pulso-meta-key {
            font-weight: 600;
            color: var(--pulso-navy);
            font-size: 0.85em;
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .pulso-meta-value {
            color: var(--pulso-ink);
            line-height: 1.55;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .pulso-card-item {
            padding: 10px 12px;
            border-bottom: 1px solid var(--pulso-line);
            width: 100%;
            box-sizing: border-box;
        }

        .pulso-meta-card .pulso-card-item:last-child {
            border-bottom: none;
        }

        .pulso-card-item-title {
            font-weight: 600;
            color: var(--pulso-navy);
            font-size: 0.95rem;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pulso-card-item-body {
            color: var(--pulso-slate);
            font-size: 0.9rem;
            line-height: 1.5;
            word-break: break-word;
        }

        .pulso-activity-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 2px 0;
        }

        .pulso-activity-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: var(--pulso-surface-2);
            border: 1px solid var(--pulso-line);
        }

        .pulso-activity-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 74px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: rgba(11, 147, 170, 0.12);
            color: var(--pulso-navy);
            border: 1px solid rgba(11, 147, 170, 0.3);
        }

        .pulso-activity-badge.resource {
            background: rgba(15, 122, 87, 0.12);
            color: #0F7A57;
            border-color: rgba(15, 122, 87, 0.3);
        }

        .pulso-activity-badge.label {
            background: rgba(138, 97, 0, 0.12);
            color: #8A6100;
            border-color: rgba(138, 97, 0, 0.3);
        }

        .pulso-activity-badge.page,
        .pulso-activity-badge.book,
        .pulso-activity-badge.wiki {
            background: rgba(52, 84, 122, 0.12);
            color: #34547A;
            border-color: rgba(52, 84, 122, 0.3);
        }

        .pulso-activity-name {
            color: var(--pulso-ink);
            font-weight: 500;
            line-height: 1.45;
        }

        .pulso-formula {
            background: #E2E6F2;
            border: 1px solid var(--pulso-line);
            border-left: 3px solid var(--pulso-cyan);
            border-radius: 8px;
            padding: 10px 12px;
            color: var(--pulso-ink);
            font-family: Consolas, Monaco, "Courier New", monospace;
            font-size: 0.88em;
            line-height: 1.45;
            overflow-x: auto;
            white-space: nowrap;
            max-width: 100%;
        }

        .pulso-result-box {
            background: rgba(15, 122, 87, 0.1);
            border: 1px solid rgba(15, 122, 87, 0.25);
            border-left: 3px solid #0F7A57;
            color: #0F7A57;
            border-radius: 10px;
            padding: 10px 12px;
            font-weight: 500;
            line-height: 1.55;
        }

        /* ===== Secciones de análisis (Insights / Recomendaciones) ===== */
        .pulso-section-label {
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--pulso-slate);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }

        .pulso-section-label::before {
            content: '';
            width: 14px;
            height: 2px;
            border-radius: 2px;
            background: var(--pulso-cyan);
        }

        .pulso-insights,
        .pulso-recos {
            background: var(--pulso-surface-2);
            border: 1px solid var(--pulso-line);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 4px;
        }

        /* Ofrecimiento del siguiente paso (campo 'next_step'). Cierra la respuesta,
           así que se distingue del cuerpo con el acento cian a la izquierda pero sin
           tarjeta: no es una sección más, es la última frase. */
        .pulso-next-step {
            margin-top: 10px;
            padding: 8px 0 8px 12px;
            border-left: 2px solid var(--pulso-cyan);
            background: var(--pulso-cyan-soft);
            color: var(--pulso-navy);
            font-size: 13px;
            line-height: 1.5;
        }

        .pulso-insights ul,
        .pulso-recos ul {
            margin: 0;
            padding-left: 18px;
        }

        .pulso-insights li,
        .pulso-recos li {
            margin: 5px 0;
            line-height: 1.55;
            color: var(--pulso-ink);
        }

        .pulso-insights li::marker {
            color: var(--pulso-teal-ink);
        }

        .pulso-recos li::marker {
            color: var(--pulso-muted);
        }

        /* ===== Tarjetas de lista (resultados por alumno/actividad) ===== */
        .pulso-list-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 4px 0;
        }

        .pulso-list-card {
            padding: 11px 14px;
            background: var(--pulso-surface-2);
            border: 1px solid var(--pulso-line);
            border-left: 3px solid var(--pulso-muted);
            border-radius: 10px;
        }

        .pulso-list-card.success { border-left-color: #0F7A57; }
        .pulso-list-card.info    { border-left-color: var(--pulso-cyan); }
        .pulso-list-card.warn    { border-left-color: #8A6100; }
        .pulso-list-card.danger  { border-left-color: #B3261E; }

        .pulso-list-card-title {
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--pulso-ink);
        }

        .pulso-kv-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 12px;
            font-size: 0.85em;
        }

        .pulso-kv-grid.secondary {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--pulso-line);
            font-size: 0.82em;
        }

        .pulso-kv-key {
            color: var(--pulso-slate);
            font-weight: 500;
        }

        .pulso-kv-val {
            color: var(--pulso-ink);
        }

        .pulso-list-card-desc {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--pulso-line);
            font-size: 0.88em;
            color: var(--pulso-slate);
            line-height: 1.5;
        }

        .pulso-list-item-simple {
            padding: 10px 12px;
            border-left: 3px solid var(--pulso-cyan);
            background: var(--pulso-surface-2);
            border-top: 1px solid var(--pulso-line);
            border-right: 1px solid var(--pulso-line);
            border-bottom: 1px solid var(--pulso-line);
            border-radius: 10px;
            color: var(--pulso-ink);
        }

        .pulso-empty {
            color: var(--pulso-slate);
            font-size: 0.9em;
            padding: 12px 14px;
            background: var(--pulso-surface-2);
            border-radius: 10px;
            border: 1px dashed rgba(59, 105, 150, 0.35);
            margin: 4px 0;
        }
        
        /* ========== TABLA DE DATOS ========== */
        .pulso-table-card {
            background: var(--pulso-surface-2);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--pulso-line);
            margin: 4px 0;
        }

        .pulso-table-toolbar {
            padding: 10px 12px;
            background: var(--pulso-surface-2);
            border-bottom: 1px solid var(--pulso-line);
        }

        .pulso-table-search {
            width: 100%;
            box-sizing: border-box;
            padding: 8px 12px;
            border: 1px solid var(--pulso-line);
            border-radius: 8px;
            font-size: 0.85em;
            font-family: var(--pulso-font);
            color: var(--pulso-ink);
            background: var(--pulso-bg);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .pulso-table-search:focus {
            outline: none;
            border-color: var(--pulso-teal);
            box-shadow: 0 0 0 3px rgba(11, 147, 170, 0.25);
        }

        .pulso-table-search::placeholder {
            color: var(--pulso-muted);
        }

        .pulso-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .pulso-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88em;
            min-width: 480px;
        }

        .pulso-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .pulso-table th {
            background: var(--pulso-deep);
            padding: 11px 14px;
            text-align: left;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.82em;
            letter-spacing: 0.02em;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
            border-bottom: 2px solid transparent;
            transition: background 0.2s;
        }

        .pulso-table th:hover {
            background: var(--pulso-navy);
        }

        .pulso-table th.is-sorted {
            background: var(--pulso-navy);
            border-bottom-color: var(--pulso-cyan);
        }

        .pulso-table th .pulso-sort-mark {
            font-size: 0.75em;
            margin-left: 4px;
            opacity: 0.6;
        }

        .pulso-table td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--pulso-line);
            color: var(--pulso-ink);
        }

        .pulso-table tbody tr:nth-child(even) {
            background: rgba(3, 54, 112, 0.03);
        }

        .pulso-table tbody tr {
            transition: background 0.15s;
        }

        .pulso-table tbody tr:hover {
            background: rgba(11, 147, 170, 0.1);
        }

        .pulso-no-results td {
            padding: 20px;
            text-align: center;
            color: var(--pulso-muted);
        }

        .pulso-table-footer {
            padding: 10px 12px;
            background: var(--pulso-surface-2);
            border-top: 1px solid var(--pulso-line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pulso-table-count {
            color: var(--pulso-slate);
            font-size: 0.82em;
            font-weight: 500;
        }

        .pulso-table-actions {
            display: flex;
            gap: 6px;
        }

        .pulso-export-btn {
            padding: 6px 14px;
            background: transparent;
            color: var(--pulso-navy);
            border: 1px solid var(--pulso-line);
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8em;
            font-weight: 500;
            font-family: var(--pulso-font);
            transition: border-color 0.2s, background 0.2s;
        }

        .pulso-export-btn:hover {
            border-color: rgba(11, 147, 170, 0.55);
            background: rgba(11, 147, 170, 0.1);
        }

        /* Píldoras de estado en celdas */
        .pulso-status-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.85em;
            font-weight: 500;
            white-space: nowrap;
        }

        .pulso-status-pill.success { background: rgba(15, 122, 87, 0.14); color: #0F7A57; }
        .pulso-status-pill.danger  { background: rgba(179, 38, 30, 0.14); color: #B3261E; }
        .pulso-status-pill.warning { background: rgba(138, 97, 0, 0.14); color: #8A6100; }
        .pulso-status-pill.neutral { background: rgba(52, 84, 122, 0.1); color: var(--pulso-slate); }

        .pulso-message-content ul,
        .pulso-message-content ol {
            margin: 10px 0;
            padding-left: 25px;
        }

        .pulso-message-content li {
            margin: 6px 0;
        }

        .pulso-message-content p {
            margin: 10px 0;
        }

        .pulso-message-content strong {
            font-weight: 600;
        }

        /* ========== FOLLOW-UP QUESTIONS CHIPS (T2.4.12) ========== */
        .pulso-followup-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
            padding: 10px 0 10px 36px;
        }

        .pulso-followup-chip {
            background: var(--pulso-surface);
            border: 1px solid var(--pulso-line);
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.84rem;
            font-family: var(--pulso-font);
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s, transform 0.15s;
            color: var(--pulso-navy);
            font-weight: 500;
            white-space: normal;
            text-align: left;
            line-height: 1.4;
            max-width: 100%;
        }

        .pulso-followup-chip:hover {
            border-color: rgba(11, 147, 170, 0.55);
            background: var(--pulso-surface-2);
            transform: translateY(-1px);
        }

        .pulso-followup-chip:active {
            transform: translateY(0);
        }

        /* ========== BURBUJA "ESCRIBIENDO" (tres puntos estilo WhatsApp) ========== */
        .pulso-typing-dots {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 2px;
        }

        .pulso-typing-dots span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--pulso-cyan);
            opacity: 0.35;
            animation: pulso-typing-bounce 1.2s ease-in-out infinite;
        }

        .pulso-typing-dots span:nth-child(2) { animation-delay: 0.16s; }
        .pulso-typing-dots span:nth-child(3) { animation-delay: 0.32s; }

        @keyframes pulso-typing-bounce {
            0%, 70%, 100% {
                transform: translateY(0) scale(1);
                opacity: 0.35;
                box-shadow: none;
            }
            35% {
                transform: translateY(-7px) scale(1.18);
                opacity: 1;
                box-shadow: 0 0 8px rgba(11, 147, 170, 0.55);
            }
        }

        /* Latido suave (solo opacidad) para movimiento reducido. */
        @keyframes pulso-typing-fade {
            0%, 100% { opacity: 0.3; }
            50%      { opacity: 1; }
        }

        /* ========== STREAMING (respuesta en vivo) ========== */
        .pulso-stream-text {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .pulso-stream-cursor {
            display: inline-block;
            width: 3px;
            height: 1.05em;
            margin-left: 3px;
            border-radius: 2px;
            background: var(--pulso-cyan);
            vertical-align: text-bottom;
            animation: pulso-blink 1s steps(2, start) infinite;
        }

        @keyframes pulso-blink {
            to { visibility: hidden; }
        }
        
        .pulso-chat-input-area {
            border-top: 1px solid var(--pulso-line);
            padding: 12px 14px;
            background: var(--pulso-bg);
            flex-shrink: 0;
        }

        .pulso-input-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pulso-input-group input {
            flex: 1;
            padding: 11px 16px;
            border: 1px solid var(--pulso-line);
            border-radius: 999px;
            font-size: 0.9rem;
            font-family: var(--pulso-font);
            color: var(--pulso-ink);
            background: var(--pulso-surface);
            min-width: 0;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }

        .pulso-input-group input::placeholder {
            color: var(--pulso-muted);
        }

        .pulso-input-group input:focus {
            outline: none;
            border-color: rgba(11, 147, 170, 0.6);
            background: var(--pulso-surface-2);
            box-shadow: 0 0 0 3px rgba(11, 147, 170, 0.22);
        }

        .pulso-send-btn {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--pulso-cyan);
            color: var(--pulso-deep);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s, transform 0.15s;
        }

        .pulso-send-btn svg {
            width: 17px;
            height: 17px;
            display: block;
            margin-left: 2px;
        }

        .pulso-send-btn:hover {
            background: #19F7F1;
            box-shadow: 0 0 16px rgba(11, 147, 170, 0.35);
        }

        .pulso-send-btn:active {
            transform: scale(0.94);
        }

        .pulso-mic-btn {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--pulso-surface);
            color: var(--pulso-cyan);
            border: 1px solid var(--pulso-line);
            border-radius: 50%;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, box-shadow 0.2s, transform 0.15s;
        }

        .pulso-mic-btn svg {
            width: 18px;
            height: 18px;
            display: block;
        }

        .pulso-mic-btn:hover {
            background: var(--pulso-surface-2);
            border-color: rgba(11, 147, 170, 0.5);
        }

        .pulso-mic-btn:active {
            transform: scale(0.94);
        }

        /* Estado grabando: acento cian con anillo pulsante */
        .pulso-mic-btn.pulso-mic-recording {
            background: var(--pulso-cyan);
            color: var(--pulso-deep);
            border-color: var(--pulso-cyan);
            animation: pulso-mic-pulse 1.4s ease-out infinite;
        }

        @keyframes pulso-mic-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(11, 147, 170, 0.5); }
            70%  { box-shadow: 0 0 0 10px rgba(11, 147, 170, 0); }
            100% { box-shadow: 0 0 0 0 rgba(11, 147, 170, 0); }
        }

        .pulso-char-count {
            font-size: 0.7rem;
            color: var(--pulso-muted);
            margin-top: 6px;
            text-align: right;
            padding-right: 6px;
        }

        /* ========== ACCESIBILIDAD: movimiento reducido ========== */
        @media (prefers-reduced-motion: reduce) {
            .pulso-chat-bubble,
            .pulso-chat-bubble.has-chat,
            .pulso-chat-container,
            .pulso-message,
            .pulso-message.ai .pulso-rich-answer > *,
            .pulso-stream-cursor,
            .pulso-mic-btn.pulso-mic-recording,
            .pulso-action-card {
                animation: none !important;
                transition: none !important;
            }

            .pulso-message.ai .pulso-rich-answer > * {
                opacity: 1;
            }

            /* Sin desplazamiento, pero mantiene un latido de opacidad para que se note "pensando". */
            .pulso-typing-dots span {
                animation: pulso-typing-fade 1.4s ease-in-out infinite !important;
                transform: none !important;
                box-shadow: none !important;
            }
        }

    </style>
    
    <!-- Botón circular flotante -->
    <button class="pulso-chat-bubble" id="pulso-chat-bubble" onclick="toggleChat()" title="Pulse AI — Asistente del curso" aria-label="Abrir el asistente Pulse AI">
        <img src="https://media.awakelab.world/MARCA_AWK26/awakelab_isotipo_fondo-oscuro_transparente.png" alt="" aria-hidden="true">
    </button>

    <div class="pulso-chat-container" id="pulso-chat-container" role="dialog" aria-label="Pulse AI, asistente del curso">
        <div class="pulso-chat-header" id="pulso-chat-header">
            <div class="pulso-header-brand">
                <img class="pulso-header-logo" src="https://media.awakelab.world/MARCA_AWK26/awakelab_isotipo_fondo-oscuro_transparente.png" alt="" aria-hidden="true">
                <div>
                    <h4>Pulse AI <span class="pulso-version-badge">%%PULSO_VERSION%%</span></h4>
                    <span class="pulso-header-sub"><span class="pulso-status-dot" aria-hidden="true"></span>Asistente del curso</span>
                </div>
            </div>
            <div class="header-controls">
                <button class="header-btn" id="pulso-clear-btn" title="Nueva conversación" aria-label="Empezar una conversación nueva" onclick="clearConversation()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 9 8 9"/></svg>
                </button>
                <button class="header-btn" id="pulso-minimize-btn" title="Minimizar" aria-label="Minimizar el chat" onclick="toggleChat()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
            </div>
        </div>

        <div class="pulso-chat-messages" id="pulso-messages" role="log" aria-live="polite" aria-label="Conversación con Pulse AI">
            <div class="pulso-home" id="pulso-home">
                <div class="pulso-home-hello">
                    <div class="pulso-home-avatar" aria-hidden="true"></div>
                    <h5>%%PULSO_GREETING%%</h5>
                </div>

                <button type="button" class="pulso-home-help-btn" onclick="showCapabilities()" aria-label="Descubre qué puede hacer Pulse">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span>¿Qué puede hacer Pulse?<span class="pulso-home-help-sub">Descúbrelo en 10 segundos</span></span>
                </button>

                <!--PULSO_TEACHER_ONLY_START-->
                <div class="pulso-home-section">
                    <div class="pulso-home-section-head">
                        <span class="pulso-home-section-title">Analítica del curso</span>
                        <span class="pulso-home-context-chip">%%PULSO_COURSENAME%%</span>
                    </div>
                    <div class="pulso-home-grid">
                        <button class="pulso-action-card" onclick="askPreset('¿Cuál es la tasa de completitud?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                            <span class="pulso-action-label">Completitud</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Cuáles son las notas promedio?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="20" x2="6" y2="14"/><line x1="12" y1="20" x2="12" y2="8"/><line x1="18" y1="20" x2="18" y2="4"/></svg>
                            <span class="pulso-action-label">Notas medias</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Qué estudiantes están en riesgo?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span class="pulso-action-label">Alumnos en riesgo</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Cuál es el engagement?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span class="pulso-action-label">Participación</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Cuáles son los mejores alumnos del curso?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span class="pulso-action-label">Mejores alumnos</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Qué alumnos llevan más de una semana sin acceder?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span class="pulso-action-label">Alumnos inactivos</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('Dame un panorama general del curso: tasa de completitud, nota media y cuántos alumnos están en riesgo.')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                            <span class="pulso-action-label">Panorama del curso</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Cuáles son los resultados de los cuestionarios del curso: nota media, número de intentos y cuántos alumnos aprobaron?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="m9 13 2 2 4-4"/></svg>
                            <span class="pulso-action-label">Resultados de cuestionarios</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Cuál es el estado de las entregas de las tareas: cuántas entregadas, cuántas pendientes y cuántas sin calificar?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                            <span class="pulso-action-label">Estado de entregas</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                    </div>
                </div>
                <!--PULSO_TEACHER_ONLY_END-->

                <div class="pulso-home-section course">
                    <div class="pulso-home-section-head">
                        <span class="pulso-home-section-title">Contenido del curso</span>
                        <!--PULSO_STUDENT_ONLY_START--><span class="pulso-home-context-chip">%%PULSO_COURSENAME%%</span><!--PULSO_STUDENT_ONLY_END-->
                    </div>
                    <div class="pulso-home-grid">
                        <button class="pulso-action-card" onclick="askPreset('¿De qué trata este curso?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <span class="pulso-action-label">Resumen del curso</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Qué actividades y recursos tiene el curso?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                            <span class="pulso-action-label">Actividades y recursos</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Qué secciones tiene el curso?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            <span class="pulso-action-label">Secciones</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Qué cuestionarios hay en el curso?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span class="pulso-action-label">Cuestionarios</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('¿Qué tareas hay en el curso?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>
                            <span class="pulso-action-label">Tareas del curso</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <!--PULSO_STUDENT_ONLY_START-->
                        <button class="pulso-action-card" onclick="askPreset('¿Qué materiales y documentos hay disponibles en el curso para estudiar?')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            <span class="pulso-action-label">Materiales de estudio</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('Explícame de qué trata la sección 1 del curso y qué contenidos incluye.')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                            <span class="pulso-action-label">Explícame un tema</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" onclick="askPreset('Resume los conceptos principales del material del curso para repasar.')">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11H5a2 2 0 0 0-2 2v7h18v-7a2 2 0 0 0-2-2h-4"/><path d="M9 11V5a3 3 0 0 1 6 0v6"/></svg>
                            <span class="pulso-action-label">Repaso rápido</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <!--PULSO_STUDENT_ONLY_END-->
                    </div>
                </div>

                <!--PULSO_CREATE_ONLY_START-->
                <div class="pulso-home-section create">
                    <div class="pulso-home-section-head">
                        <span class="pulso-home-section-title">Crear</span>
                        <!--PULSO_STUDENT_ONLY_START--><button type="button" class="pulso-historial-link head" onclick="pulsoAbrirHistorial()">Mi historial ↗</button><!--PULSO_STUDENT_ONLY_END-->
                    </div>
                    <div class="pulso-create-cta-row">
                        <button type="button" class="pulso-create-cta" onclick="openCreatePanel('infografia')">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Crear infografía</span>
                                <span class="pulso-create-cta-sub">A partir de un recurso del curso</span>
                            </span>
                        </button>
                        <button type="button" class="pulso-create-cta" onclick="openCreatePanel('gamificacion')">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="12" x2="10" y2="12"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="15" y1="13" x2="15.01" y2="13"/><line x1="18" y1="11" x2="18.01" y2="11"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Crear juego</span>
                                <span class="pulso-create-cta-sub">A partir de un recurso del curso</span>
                            </span>
                        </button>
                        <button type="button" class="pulso-create-cta" onclick="openCreatePanel('ampliacion')">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Ampliar recurso</span>
                                <span class="pulso-create-cta-sub">Vídeos y artículos sobre un recurso del curso</span>
                            </span>
                        </button>
                        <button type="button" class="pulso-create-cta" onclick="openCreatePanel('retos')">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Crear reto</span>
                                <span class="pulso-create-cta-sub">Un caso práctico que corrige la IA</span>
                            </span>
                        </button>
                    </div>
                </div>
                <!--PULSO_CREATE_ONLY_END-->
            </div>

            <!--PULSO_CREATE_ONLY_START-->
            <div class="pulso-create-panel" id="pulso-create-panel">
                <div class="pulso-create-head">
                    <button type="button" class="pulso-create-back" onclick="closeCreatePanel()" aria-label="Volver a la pantalla de inicio">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <h4 id="pulso-create-title">Crear infografía</h4>
                </div>
                <div class="pulso-create-body" id="pulso-create-body">
                    <p class="pulso-create-hint">Cargando…</p>
                </div>
            </div>
            <!--PULSO_CREATE_ONLY_END-->
        </div>

        <div class="pulso-chat-input-area">
            <form id="pulso-chat-form" onsubmit="sendMessage(event)">
                <div class="pulso-input-group">
                    <input
                        type="text"
                        id="pulso-input"
                        placeholder="Pregunta sobre el curso..."
                        maxlength="500"
                        autocomplete="off"
                        aria-label="Escribe tu pregunta sobre el curso"
                    />
                    <button type="button" id="pulso-mic-btn" class="pulso-mic-btn" style="display:none;" aria-label="Dictar pregunta por voz" aria-pressed="false" title="Dictar por voz" onclick="toggleMic()">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-2.08A7 7 0 0 0 19 12h-2Z"/></svg>
                    </button>
                    <button type="submit" class="pulso-send-btn" aria-label="Enviar pregunta">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21.85 12 3.4 3.6l-.01 6.53L15 12 3.39 13.87z"/></svg>
                    </button>
                </div>
            </form>
            <div class="pulso-char-count">
                <span id="pulso-char-count">0</span>/500
            </div>
        </div>
    </div>
    
    <script>
        function formatAIResponse(answer, showAnalysisSections = true) {
            try {
                let jsonStr = answer.trim();
                
                // Si está envuelto en markdown code block (```json ... ```)
                if (/^\s*```/.test(jsonStr)) {
                    // Remover markdown code block — handle any whitespace/newlines around fences
                    jsonStr = jsonStr.replace(/^\s*```[a-z]*\s*/i, '').replace(/\s*```\s*$/i, '').trim();
                    console.log('📌 Limpiado markdown code block');
                }
                
                // Si está envuelto en comillas extra, removerlas
                if ((jsonStr.startsWith('"') && jsonStr.endsWith('"')) ||
                    (jsonStr.startsWith("'") && jsonStr.endsWith("'"))) {
                    jsonStr = jsonStr.slice(1, -1);
                    console.log('📌 Limpiado comillas extra');
                }

                // Si viene texto extra antes/despues del JSON, extraer solo el bloque {...}
                const firstBrace = jsonStr.indexOf('{');
                const lastBrace = jsonStr.lastIndexOf('}');
                if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
                    jsonStr = jsonStr.slice(firstBrace, lastBrace + 1).trim();
                }
                
                // Intentar parsear como JSON
                const data = JSON.parse(jsonStr);
                console.log('✅ JSON parseado:', data);
                
                // ========== MANEJO DE ERRORES ==========
                if (data.status === 'insufficient_data' || data.status === 'error') {
                    console.warn('⚠️ La IA retornó status:', data.status);
                    // Intentar mostrar mensaje útil en vez de solo error
                    if (data.message) {
                        return `<div class="pulso-empty">${escapeHtml(data.message)}</div>`;
                    }
                    return `<div class="pulso-empty">No se encontraron datos de analítica para este curso. Asegúrate de que el seguimiento de completitud y las calificaciones estén configurados en Moodle.</div>`;
                }
                
                if (!data || !data.type) {
                    // No tiene el campo type requerido
                    console.warn('⚠️ JSON sin campo type:', data);
                    // Si es otro formato de respuesta, mostrar como texto
                    return formatRichTextResponse(answer);
                }
                
                let html = '<div class="pulso-rich-answer">';

                // Simplificación: cuando la respuesta tiene cuerpo real
                // (content/data), el título y los resúmenes boilerplate solo
                // duplican información antes de la respuesta — no renderizarlos.
                const hasBody = (function() {
                    if (Array.isArray(data.data) && data.data.length > 0) return true;
                    if (data.content) {
                        if (Array.isArray(data.content)) return data.content.length > 0;
                        if (typeof data.content === 'object') return Object.keys(data.content).length > 0;
                        return String(data.content).trim() !== '';
                    }
                    return false;
                })();
                const boilerplateSummaryRe = /^(he\s+(localizado|encontrado|recuperado|obtenido|listado)\b|contenido de la etiqueta\b|resumen del (curso|recurso|pdf|archivo)\b)/i;

                // Título: solo cuando no hay cuerpo (es lo único que hay que mostrar).
                if (data.title && !hasBody) {
                    html += `<div class="pulso-rich-title">${escapeHtml(String(data.title))}</div>`;
                }

                // Resumen: mantenerlo como dato clave en tablas/listas (salvo
                // boilerplate); en respuestas narrativas el contenido ya lo repite.
                const summaryText = data.summary ? String(data.summary).trim() : '';
                const showSummary = summaryText !== '' && (
                    !hasBody
                    || ((data.type === 'table' || data.type === 'list') && !boilerplateSummaryRe.test(summaryText))
                );
                if (showSummary) {
                    html += `<div class="pulso-rich-summary">${formatRichTextResponse(summaryText, true)}</div>`;
                }
                
                // Datos según tipo
                if (data.type === 'table' && data.data && Array.isArray(data.data)) {
                    html += formatAsTable(data.data);
                } else if (data.type === 'list' && data.data && Array.isArray(data.data)) {
                    html += formatAsList(data.data);
                } else if (data.type === 'text') {
                    if (data.content) {
                        if (Array.isArray(data.content)) {
                            // Extraer texto plano de arrays de objetos tipo {paragraph: "..."}
                            const textParts = data.content.map(item => {
                                if (typeof item === 'string') return item;
                                if (typeof item === 'object' && item !== null) {
                                    return Object.values(item).map(v => String(v)).join(' ');
                                }
                                return String(item);
                            });
                            html += formatRichTextResponse(textParts.join('\n\n'));
                        } else if (typeof data.content === 'object') {
                            const texts = Object.values(data.content).map(v => String(v));
                            html += formatRichTextResponse(texts.join('\n\n'));
                        } else {
                            html += formatRichTextResponse(String(data.content));
                        }
                    }
                    // Fallback: si type es text pero también hay data como array,
                    // extract text from content-wrapper objects and render via formatRichTextResponse.
                    if (data.data && Array.isArray(data.data) && data.data.length > 0) {
                        const contentOnlyKeys = ['paragraph', 'párrafo', 'parrafo', 'text', 'texto', 'content', 'contenido', 'summary', 'resumen', 'conclusion', 'conclusión', 'introduction', 'introducción', 'analysis', 'análisis', 'observation', 'observación', 'comment', 'comentario', 'response', 'respuesta', 'answer', 'description', 'descripción', 'descripcion'];
                        const textItems = [];
                        let allText = true;
                        for (const item of data.data) {
                            if (typeof item === 'string') {
                                textItems.push(item);
                            } else if (typeof item === 'object' && item !== null) {
                                const keys = Object.keys(item);
                                if (keys.every(k => contentOnlyKeys.includes(k.toLowerCase()))) {
                                    textItems.push(Object.values(item).map(v => String(v)).join(' '));
                                } else {
                                    allText = false;
                                    break;
                                }
                            } else {
                                allText = false;
                                break;
                            }
                        }
                        if (allText && textItems.length > 0) {
                            html += formatRichTextResponse(textItems.join('\n\n'));
                        } else {
                            html += formatAsList(data.data);
                        }
                    }
                } else if (data.data && Array.isArray(data.data)) {
                    // Tipo desconocido pero hay data como array
                    html += formatAsList(data.data);
                }
                
                // Insights
                if (showAnalysisSections && data.insights && Array.isArray(data.insights) && data.insights.length > 0) {
                    html += '<div class="pulso-insights">';
                    html += '<div class="pulso-section-label">Insights</div>';
                    html += '<ul>';
                    data.insights.forEach(insight => {
                        html += `<li>${escapeHtml(String(insight))}</li>`;
                    });
                    html += '</ul></div>';
                }

                // Recommendations
                if (showAnalysisSections && data.recommendations && Array.isArray(data.recommendations) && data.recommendations.length > 0) {
                    html += '<div class="pulso-recos">';
                    html += '<div class="pulso-section-label">Recomendaciones</div>';
                    html += '<ul>';
                    data.recommendations.forEach(rec => {
                        html += `<li>${escapeHtml(String(rec))}</li>`;
                    });
                    html += '</ul></div>';
                }

                // Ofrecimiento del siguiente paso (campo 'next_step', v1.15.2). Se pinta
                // como bloque APARTE y SIEMPRE que venga, sin depender de data.type ni de
                // showAnalysisSections: en v1.15.0 la regla pedía la frase dentro de
                // 'content', que solo se pinta si type === 'text', así que en las
                // respuestas de analítica (type table/list) era invisible por diseño; y
                // 'recommendations' se oculta entero cuando la pregunta no parece de
                // analítica. Con campo propio no hay layout en el que se pierda.
                // Se escapa aquí y no pasa por formatRichTextResponse() (que escapa el
                // bloque entero una sola vez), igual que el enlace de abajo.
                const nextStep = (data.next_step !== undefined && data.next_step !== null)
                    ? String(data.next_step).trim()
                    : '';
                if (nextStep !== '') {
                    html += `<div class="pulso-next-step">${escapeHtml(nextStep)}</div>`;
                }

                // Enlace directo a la actividad/recurso. La URL la construye SIEMPRE el
                // servidor con moodle_url y solo llega si el usuario puede verla
                // (uservisible), asi que aqui solo hay que pintarla. Se escapa por si
                // acaso y se trata como campo aparte para no pasar por
                // formatRichTextResponse() (que escapa el bloque entero una sola vez).
                if (data.link && data.link.url) {
                    const linkUrl = String(data.link.url);
                    const linkLabel = data.link.label ? String(data.link.label) : 'Abrir en el curso';
                    html += `<div class="pulso-goto-wrap">`
                        + `<a class="pulso-goto-link" href="${escapeHtml(linkUrl)}">`
                        + `<span class="pulso-goto-icon">↗</span>${escapeHtml(linkLabel)}`
                        + `</a></div>`;
                }

                html += '</div>';
                
                console.log('✅ HTML generado correctamente');
                return html;
            } catch (e) {
                console.warn('⚠️ Error al parsear JSON:', e.message);
                console.warn('⚠️ Texto que intentó parsear:', answer.substring(0, 200));

                // Red de seguridad: si el texto SIGUE pareciendo JSON (empieza por
                // { o [) y no se ha podido parsear ni reparar en servidor, no se
                // pinta en crudo — el usuario vería un objeto JSON en la burbuja.
                // Ocurre raras veces, cuando el modelo emite JSON malformado.
                const looksLikeJson = /^\s*[\{\[]/.test(String(answer || ''));
                if (looksLikeJson) {
                    console.warn('⚠️ JSON no renderizable, se muestra aviso al usuario. Respuesta completa:', answer);
                    return '<div class="pulso-empty">No he podido formatear la respuesta. '
                        + 'Inténtalo de nuevo o reformula la pregunta.</div>';
                }

                // Si no es JSON, retornar como está
                return formatRichTextResponse(answer);
            }
        }

        function formatRichTextResponse(text, inline = false) {
            let raw = String(text || '').replace(/\r\n/g, '\n').trim();
            if (!raw) {
                return '';
            }

            // Strip structural-only prefixes (paragraph:, text:, resumen:, etc.)
            // BEFORE line splitting so the cleaned text gets full formatting.
            const contentPrefixRe = /^(paragraph|párrafo|parrafo|text|texto|content|contenido|summary|resumen|conclusion|conclusión|conclusiones|introduction|introducción|introduccion|analysis|análisis|analisis|observation|observación|observacion|comment|comentario|response|respuesta|answer):\s+/i;
            // Process each line, stripping prefixes per-line.
            raw = raw.split('\n').map(function(line) {
                let l = line;
                while (contentPrefixRe.test(l)) {
                    l = l.replace(contentPrefixRe, '');
                }
                return l;
            }).join('\n');

            const isResourceDetailLayout = /(^|\n)\s*recurso\s*:/i.test(raw)
                && /(^|\n)\s*archivo\s*:/i.test(raw)
                && /(^|\n)\s*tipo\s*:/i.test(raw);

            // Forzar salto visual antes de pasos numerados pegados en una sola línea.
            raw = raw.replace(/\s(?=(\d+)\.\s+\*\*)/g, '\n');
            raw = raw.replace(/\s(?=(\d+)\.\s+[A-ZÁÉÍÓÚÑ¿])/g, '\n');

            const formulas = [];
            raw = raw.replace(/\\\[([\s\S]*?)\\\]/g, function(_, expr) {
                const id = formulas.length;
                formulas.push(prettifyFormula(expr));
                return '@@PULSO_FORMULA_' + id + '@@';
            });

            raw = escapeHtml(raw);
            raw = raw.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            raw = raw.replace(/\n{3,}/g, '\n\n');

            // Solo convertir a pasos cuando el texto parezca realmente procedural.
            if (!isResourceDetailLayout && !/\n\s*\d+\.\s+/m.test(raw) && shouldFormatAsSteps(raw)) {
                raw = toNumberedSteps(raw);
            }

            const lines = raw.split('\n');
            const totalNumberedSteps = lines.filter(function(line) {
                return /^\s*\d+\.\s+/.test(line);
            }).length;
            const chunks = [];
            let inOl = false;
            let inUl = false;
            let bulletContainerType = '';
            let stepCounter = 0;
            let lastStepNumber = 0;
            let inMetaGroup = false;

            function closeLists() {
                if (inMetaGroup) {
                    chunks.push('</div>');
                    inMetaGroup = false;
                }
                if (inOl) {
                    chunks.push('</ol>');
                    inOl = false;
                }
                if (inUl) {
                    chunks.push(bulletContainerType === 'activity' ? '</div>' : '</ul>');
                    inUl = false;
                    bulletContainerType = '';
                }
            }

            lines.forEach(function(line) {
                const trimmed = line.trim();

                if (!trimmed) {
                    closeLists();
                    return;
                }

                const olMatch = trimmed.match(/^(\d+)\.\s+(.+)$/);
                if (olMatch) {
                    if (isResourceDetailLayout) {
                        closeLists();
                        chunks.push('<p class="pulso-rich-paragraph">' + highlightResultPhrases(trimmed) + '</p>');
                        return;
                    }

                    // If the numbered item has a bold title (e.g. "**Gamificación**: Introduce..."),
                    // render as an advice card instead of a procedural step.
                    const bodyText = olMatch[2];
                    const adviceMatch = bodyText.match(/^<strong>([^<]+)<\/strong>\s*:\s*(.+)$/);
                    if (adviceMatch) {
                        closeLists();
                        if (!inMetaGroup) {
                            chunks.push('<div class="pulso-meta-card">');
                            inMetaGroup = true;
                        }
                        chunks.push('<div class="pulso-card-item"><div class="pulso-card-item-title"><span>💡</span> ' + adviceMatch[1] + '</div><div class="pulso-card-item-body">' + highlightResultPhrases(adviceMatch[2]) + '</div></div>');
                        return;
                    }

                    if (inUl) {
                        chunks.push('</ul>');
                        inUl = false;
                    }
                    if (!inOl) {
                        chunks.push('<ol class="pulso-rich-steps">');
                        inOl = true;
                    }

                    const parsedStep = parseInt(olMatch[1], 10);
                    if (!Number.isNaN(parsedStep) && parsedStep > 0) {
                        stepCounter = parsedStep;
                    } else {
                        stepCounter = lastStepNumber + 1;
                    }
                    lastStepNumber = stepCounter;

                    chunks.push('<li><span class="pulso-step-badge">Paso ' + stepCounter + '</span><div class="pulso-step-body">' + renderStepBody(olMatch[2], stepCounter, totalNumberedSteps) + '</div></li>');
                    return;
                }

                // Detect bold-title lines like "<strong>Title</strong>: description"
                // that aren't inside a numbered list — render as advice cards.
                const boldTitleMatch = trimmed.match(/^<strong>([^<]+)<\/strong>\s*:\s*(.+)$/);
                if (boldTitleMatch && !inOl) {
                    closeLists();
                    chunks.push('<div class="pulso-card-item"><div class="pulso-card-item-title"><span>💡</span> ' + boldTitleMatch[1] + '</div><div class="pulso-card-item-body">' + highlightResultPhrases(boldTitleMatch[2]) + '</div></div>');
                    return;
                }

                const ulMatch = trimmed.match(/^[-•]\s+(.+)$/);
                if (ulMatch) {
                    if (inOl) {
                        chunks.push('</ol>');
                        inOl = false;
                    }
                    const activityItem = renderActivityItem(ulMatch[1]);
                    if (activityItem) {
                        if (!inUl) {
                            chunks.push('<div class="pulso-activity-list">');
                            inUl = true;
                            bulletContainerType = 'activity';
                        } else if (bulletContainerType !== 'activity') {
                            chunks.push('</ul><div class="pulso-activity-list">');
                            bulletContainerType = 'activity';
                        }
                        chunks.push(activityItem);
                        return;
                    }
                    if (!inUl) {
                        chunks.push('<ul class="pulso-rich-bullets">');
                        inUl = true;
                        bulletContainerType = 'bullet';
                    } else if (bulletContainerType !== 'bullet') {
                        chunks.push('</div><ul class="pulso-rich-bullets">');
                        bulletContainerType = 'bullet';
                    }
                    chunks.push('<li>' + highlightResultPhrases(ulMatch[1]) + '</li>');
                    return;
                }

                closeLists();

                if (/@@PULSO_FORMULA_\d+@@/.test(trimmed)) {
                    chunks.push(trimmed);
                    return;
                }

                if (/^(respuesta final|resultado final|en resumen|conclusi[oó]n)/i.test(trimmed)) {
                    chunks.push('<div class="pulso-result-box">' + highlightResultPhrases(trimmed) + '</div>');
                    return;
                }

                const metaRow = renderMetaRow(trimmed);
                if (metaRow) {
                    if (!inMetaGroup) {
                        chunks.push('<div class="pulso-meta-card">');
                        inMetaGroup = true;
                    }
                    // If this is a body row (pulso-card-item-body), merge it into the
                    // preceding card-item by re-opening the container.
                    if (metaRow.indexOf('pulso-card-item-body') !== -1 && chunks.length > 0) {
                        const lastIdx = chunks.length - 1;
                        if (chunks[lastIdx].indexOf('pulso-card-item"') !== -1 && chunks[lastIdx].endsWith('</div>')) {
                            // Title ends with </div></div> — strip the outer closing </div>
                            // so the body sits inside the card-item, then re-close it.
                            const stripped = chunks[lastIdx].replace(/<\/div>\s*$/, '');
                            chunks[lastIdx] = stripped;
                            chunks.push(metaRow + '</div>');
                            return;
                        }
                    }
                    chunks.push(metaRow);
                    return;
                }

                if (inMetaGroup) {
                    chunks.push('</div>');
                    inMetaGroup = false;
                }

                chunks.push('<p class="pulso-rich-paragraph">' + highlightResultPhrases(trimmed) + '</p>');
            });

            closeLists();

            let html = chunks.join('');
            formulas.forEach(function(formula, idx) {
                const box = '<div class="pulso-formula">' + formula + '</div>';
                html = html.replaceAll('@@PULSO_FORMULA_' + idx + '@@', box);
            });

            if (inline) {
                return html;
            }

            return '<div class="pulso-rich-answer">' + html + '</div>';
        }

        function shouldFormatAsSteps(text) {
            const value = String(text || '').toLowerCase();

            // Nunca formatear como pasos si el texto es corto (menos de 5 líneas con contenido).
            const contentLines = value.split('\n').map(function(l) { return l.trim(); }).filter(Boolean);
            if (contentLines.length < 5) {
                return false;
            }

            // Respuestas con numeración explícita "1. xxx" del modelo → respetar.
            if (/^\s*\d+\.\s+/m.test(value)) {
                // Solo si hay al menos 3 líneas numeradas (no una suelta).
                const numberedCount = contentLines.filter(function(l) { return /^\d+\.\s+/.test(l); }).length;
                if (numberedCount >= 3) {
                    return true;
                }
            }

            // Solo formatear como pasos cuando hay instrucciones procedurales explícitas.
            const proceduralHints = [
                'sigamos estos pasos', 'paso a paso', 'sigue estos pasos',
                'los pasos son', 'pasos a seguir'
            ];

            const hasProceduralHint = proceduralHints.some(function(hint) {
                return value.indexOf(hint) !== -1;
            });

            if (!hasProceduralHint) {
                return false;
            }

            const metaLikeLines = contentLines.filter(function(line) { return /^[^:]{2,40}:\s+.+$/.test(line); }).length;
            if (metaLikeLines >= Math.max(2, contentLines.length - 1)) {
                return false;
            }

            return true;
        }

        function toNumberedSteps(text) {
            const lines = String(text).split('\n');
            const stepLines = [];

            lines.forEach(function(line) {
                const s = line.trim();
                if (!s) {
                    return;
                }

                // Separar cuando aparezcan conectores típicos de procedimiento.
                const parts = s
                    .replace(/\s+(?=(primero|segundo|tercero|cuarto|quinto|luego|despu[eé]s|a continuaci[oó]n|finalmente|por [uú]ltimo)\b)/gi, '\n')
                    .split('\n')
                    .map(function(p) { return p.trim(); })
                    .filter(Boolean);

                parts.forEach(function(p) {
                    stepLines.push(p);
                });
            });

            // Solo convertir a pasos si realmente hay varias partes.
            if (stepLines.length < 2) {
                return text;
            }

            const numbered = stepLines.map(function(step, idx) {
                return (idx + 1) + '. ' + step;
            });

            return numbered.join('\n');
        }

        function prettifyFormula(expr) {
            let s = String(expr || '').replace(/\s+/g, ' ').trim();
            s = s.replace(/\\text\{([^}]*)\}/g, '$1');
            s = s.replace(/\\times/g, '×');
            s = s.replace(/\\cdot/g, '·');
            s = s.replace(/\\frac\{([^}]*)\}\{([^}]*)\}/g, '($1 / $2)');
            s = s.replace(/\\,/g, ' ');
            s = s.replace(/\\/g, '');
            return escapeHtml(s);
        }

        function renderStepBody(stepText, stepNumber, totalSteps) {
            const highlighted = highlightResultPhrases(stepText);
            const finalBlock = extractFinalAnswerBlock(stepText, stepNumber, totalSteps);
            if (!finalBlock) {
                return highlighted;
            }

            return '<div>' + highlighted + '</div>' + finalBlock;
        }

        function extractFinalAnswerBlock(stepText, stepNumber, totalSteps) {
            const raw = String(stepText || '').trim();
            if (!raw) {
                return '';
            }

            // OJO: stepText llega ya escapado (viene de formatRichTextResponse);
            // no volver a aplicar escapeHtml o se pintan '&quot;' literales.
            if (/^(respuesta final|resultado final|en resumen|conclusi[oó]n)/i.test(raw)) {
                return '<div class="pulso-result-box">' + highlightResultPhrases(raw) + '</div>';
            }

            const finalCue = /(por lo tanto|en conclusi[oó]n|la respuesta final es|el resultado final es|la respuesta es|el resultado es)[:\s]+(.+)/i.exec(raw);
            if (finalCue && finalCue[2]) {
                return '<div class="pulso-result-box"><strong>Respuesta final:</strong> ' + highlightResultPhrases(finalCue[2].trim()) + '</div>';
            }

            const likelyFinal = /(?:\b(?:es|son)\b\s*)(\d+(?:[.,]\d+)?)\s*(metros|metro|m|kil[oó]metros|km|hect[oó]metros|hm|cent[ií]metros|cm|euros|€|%|grados)?\b/i.exec(raw);
            const isLastStep = totalSteps >= 2 && stepNumber === totalSteps;
            if (isLastStep && likelyFinal) {
                return '<div class="pulso-result-box"><strong>Respuesta final:</strong> ' + highlightResultPhrases(raw) + '</div>';
            }

            return '';
        }

        // Trocea un valor que sea una ristra de preguntas en una lista.
        // Devuelve null si no parece un listado de preguntas.
        function pulsoSplitQuestions(value) {
            const s = String(value || '');
            // Preguntas en español delimitadas por ¿ … ?
            const spanish = s.match(/¿[^¿?]*\?/g);
            if (spanish && spanish.length >= 2) {
                return spanish.map(function(q) { return q.trim(); });
            }
            // Fallback (inglés o sin ¿): solo si hay 2+ signos de interrogación.
            if ((s.match(/\?/g) || []).length >= 2) {
                const parts = s.split('?').map(function(p) { return p.trim(); }).filter(Boolean);
                if (parts.length >= 2) {
                    return parts.map(function(p) { return p + '?'; });
                }
            }
            return null;
        }

        function renderMetaRow(line) {
            const raw = String(line);

            // Detect lines with multiple key:value pairs like
            // "advantage: Velocidad  description: Spark puede..."
            // Split them into separate parts and render each.
            const multiMatch = raw.match(/^([^:]{2,40}):\s+(.+?)\s{2,}([^:]{2,40}):\s+(.+)$/);
            if (multiMatch) {
                const part1 = renderMetaRow(multiMatch[1].trim() + ': ' + multiMatch[2].trim());
                const part2 = renderMetaRow(multiMatch[3].trim() + ': ' + multiMatch[4].trim());
                if (part1 || part2) {
                    return (part1 || '') + (part2 || '');
                }
            }

            const match = raw.match(/^([^:]{2,40}):\s+(.+)$/);
            if (!match) {
                return '';
            }

            const key = match[1].trim();
            const value = match[2].trim();
            if (!key || !value) {
                return '';
            }

            const titleKeys = ['strategy', 'estrategia', 'recommendation', 'recomendación', 'recomendacion', 'tip', 'consejo', 'action', 'acción', 'accion', 'step', 'paso', 'objective', 'objetivo', 'benefit', 'beneficio', 'advantage', 'ventaja', 'option', 'opción', 'opcion', 'feature', 'característica', 'caracteristica', 'tool', 'herramienta', 'method', 'método', 'metodo', 'approach', 'enfoque', 'solution', 'solución', 'solucion', 'idea', 'suggestion', 'sugerencia', 'challenge', 'reto', 'desafío', 'desafio', 'risk', 'riesgo', 'category', 'categoría', 'categoria', 'topic', 'tema', 'area', 'área', 'example', 'ejemplo', 'technique', 'técnica', 'tecnica', 'principle', 'principio', 'role', 'rol', 'activity', 'actividad', 'resource', 'platform', 'plataforma', 'channel', 'canal'];
            const bodyKeys = ['description', 'descripcion', 'descripción', 'detail', 'detalle', 'explanation', 'explicación', 'explicacion', 'reason', 'razón', 'razon', 'impact', 'impacto', 'result', 'resultado', 'note', 'nota', 'details', 'detalles', 'how', 'cómo', 'como', 'why', 'por qué', 'implementation', 'implementación', 'implementacion'];

            const keyLower = key.toLowerCase();

            const isTitleKey = titleKeys.includes(keyLower);
            const isBodyKey = bodyKeys.includes(keyLower);

            // OJO: el texto llega ya escapado (formatRichTextResponse hace
            // escapeHtml sobre todo el bloque antes de trocearlo en líneas);
            // volver a escapar aquí pintaba '&quot;' literales en pantalla.
            if (isTitleKey) {
                return '<div class="pulso-card-item"><div class="pulso-card-item-title"><span>🎯</span> ' + value + '</div></div>';
            }

            if (isBodyKey) {
                return '<div class="pulso-card-item-body">' + highlightResultPhrases(value) + '</div>';
            }

            const translations = {
                'file_name': { label: 'Nombre del archivo', icon: '📄' },
                'file_type': { label: 'Tipo de archivo', icon: '📋' },
                'Archivo': { label: 'Archivo', icon: '📄' },
                'Tipo': { label: 'Tipo', icon: '📋' },
                'Descripcion': { label: 'Descripción', icon: '💬' },
                'Recurso': { label: 'Recurso', icon: '📦' },
                'Seccion': { label: 'Sección', icon: '📂' },
                'Resumen': { label: 'Resumen', icon: '📝' },
                'Nombre': { label: 'Nombre', icon: '🏷️' },
                'Nota máxima': { label: 'Nota máxima', icon: '⭐' },
                'Nota maxima': { label: 'Nota máxima', icon: '⭐' },
                'Preguntas': { label: 'Preguntas', icon: '❓' },
                'Intentos permitidos': { label: 'Intentos permitidos', icon: '🔄' },
                'Completado por': { label: 'Completado por', icon: '✅' },
                'Entregas': { label: 'Entregas', icon: '📥' },
                'Calificación media': { label: 'Calificación media', icon: '📊' },
                'Discusiones': { label: 'Discusiones', icon: '💬' },
                'Mensajes': { label: 'Mensajes', icon: '✉️' },
                'Capítulos': { label: 'Capítulos', icon: '📖' },
                'Entradas': { label: 'Entradas', icon: '📝' }
            };

            const info = translations[key] || { label: key, icon: 'ℹ️' };

            // Si el valor es una ristra de preguntas (p. ej. las sugerencias del
            // saludo), pintarlas como lista en vez de una fila de 2 columnas que
            // se estruja y se corta en anchos pequeños.
            const questions = pulsoSplitQuestions(value);
            if (questions && questions.length >= 2) {
                const items = questions.map(function(q) {
                    return '<li>' + highlightResultPhrases(q) + '</li>';
                }).join('');
                return '<div class="pulso-suggest"><div class="pulso-suggest-title"><span>' + info.icon + '</span> ' + info.label + '</div><ul>' + items + '</ul></div>';
            }

            return '<div class="pulso-meta-row"><div class="pulso-meta-key"><span>' + info.icon + '</span> ' + info.label + '</div><div class="pulso-meta-value">' + highlightResultPhrases(value) + '</div></div>';
        }

        function renderActivityItem(line) {
            const match = String(line).match(/^\[([^\]]+)\]\s+(.+)$/);
            if (!match) {
                return '';
            }

            const moduleType = String(match[1]).trim().toLowerCase();
            const name = String(match[2]).trim();
            if (!moduleType || !name) {
                return '';
            }

            return '<div class="pulso-activity-item"><span class="pulso-activity-badge ' + escapeHtml(moduleType) + '">' + escapeHtml(moduleType) + '</span><div class="pulso-activity-name">' + escapeHtml(name) + '</div></div>';
        }

        function highlightResultPhrases(line) {
            return String(line)
                .replace(/(respuesta final|resultado final|por lo tanto|en conclusion|en conclusión|distancia total|hect[oó]metros)/gi, '<strong>$1</strong>');
        }
        
        function formatAsTable(data) {
            if (!data || data.length === 0) {
                return '<p class="pulso-empty">No hay datos disponibles para mostrar.</p>';
            }

            const tableId = 'table-' + Math.random().toString(36).substr(2, 9);
            let html = '';

            const firstRow = data[0];
            if (typeof firstRow !== 'object') {
                return formatAsList(data);
            }

            html += '<div class="pulso-table-card">';

            // Buscador
            html += '<div class="pulso-table-toolbar">';
            html += '<input type="text" class="pulso-table-search" id="filter-' + tableId + '" placeholder="Buscar en esta tabla..." aria-label="Buscar en la tabla" onkeyup="filterTable(\'' + tableId + '\')" />';
            html += '</div>';

            // Contenedor con scroll horizontal responsivo
            html += '<div class="pulso-table-scroll">';
            html += '<table id="' + tableId + '" class="pulso-table">';

            html += '<thead><tr>';
            Object.keys(firstRow).forEach((key, idx) => {
                const label = pulsoFieldLabel(key);
                html += '<th onclick="sortTable(\'' + tableId + '\', ' + idx + ')" title="Ordenar por ' + escapeHtml(label) + '">';
                html += escapeHtml(label) + '<span class="pulso-sort-mark" aria-hidden="true">⇅</span></th>';
            });
            html += '</tr></thead>';

            html += '<tbody>';
            data.forEach((row) => {
                html += '<tr>';
                Object.values(row).forEach((value, colIdx) => {
                    const isLastCol = colIdx === Object.keys(firstRow).length - 1;
                    const valueStr = String(value).toLowerCase();

                    let cellContent = escapeHtml(String(value));
                    let cellClass = '';

                    // Estado semántico (success/danger/warning/neutral) → píldora.
                    if (isLastCol && (valueStr === 'success' || valueStr === 'danger' || valueStr === 'warning' || valueStr === 'neutral')) {
                        cellContent = '<span class="pulso-status-pill ' + valueStr + '">' + cellContent + '</span>';
                        cellClass = ' style="text-align:center"';
                    }

                    html += '<td' + cellClass + '>' + cellContent + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody>';

            html += '</table>';
            html += '</div>';

            // Footer con recuento y exportación
            html += '<div class="pulso-table-footer">';
            html += '<span class="pulso-table-count">' + data.length + ' registros</span>';
            html += '<div class="pulso-table-actions">';
            html += '<button class="pulso-export-btn" onclick="exportTableAsExcel(\'' + tableId + '\')">Exportar Excel</button>';
            html += '<button class="pulso-export-btn" onclick="exportTableAsCSV(\'' + tableId + '\')">Exportar CSV</button>';
            html += '</div>';
            html += '</div>';

            html += '</div>';

            // Inicializar estado de tabla
            setTimeout(() => {
                const filterInput = document.getElementById('filter-' + tableId);
                if (filterInput) {
                    filterInput.addEventListener('keyup', () => filterTable(tableId));
                }
                if (!window.tableState) window.tableState = {};
                window.tableState[tableId] = { sortCol: -1, sortAsc: true };
            }, 0);

            return html;
        }
        
        function sortTable(tableId, colIdx) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const state = window.tableState[tableId] || {};
            
            // Toggle sort direction
            if (state.sortCol === colIdx) {
                state.sortAsc = !state.sortAsc;
            } else {
                state.sortAsc = true;
            }
            state.sortCol = colIdx;
            window.tableState[tableId] = state;
            
            // Actualizar indicador visual en headers
            const headers = table.querySelectorAll('th');
            headers.forEach((th, idx) => {
                if (idx === colIdx) {
                    th.classList.add('is-sorted');
                    th.setAttribute('aria-sort', state.sortAsc ? 'ascending' : 'descending');
                } else {
                    th.classList.remove('is-sorted');
                    th.removeAttribute('aria-sort');
                }
            });
            
            // Ordenar filas
            rows.sort((a, b) => {
                const aVal = a.cells[colIdx].textContent.trim();
                const bVal = b.cells[colIdx].textContent.trim();
                
                // Intentar comparar como números
                const aNum = parseFloat(aVal);
                const bNum = parseFloat(bVal);
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return state.sortAsc ? aNum - bNum : bNum - aNum;
                }
                
                // Si no, comparar como strings (case-insensitive)
                const aCmp = aVal.toLowerCase();
                const bCmp = bVal.toLowerCase();
                return state.sortAsc ? aCmp.localeCompare(bCmp) : bCmp.localeCompare(aCmp);
            });
            
            // Re-insert rows sorted (el zebra striping lo aporta el CSS via nth-child).
            const tbody = table.querySelector('tbody');
            rows.forEach((row) => {
                tbody.appendChild(row);
            });
        }
        
        function filterTable(tableId) {
            const table = document.getElementById(tableId);
            const filterId = 'filter-' + tableId;
            const filter = document.getElementById(filterId);
            
            if (!table || !filter) return;
            
            const filterText = filter.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                if (row.classList.contains('filter-no-results')) return;
                const text = row.textContent.toLowerCase();
                if (text.includes(filterText)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Mostrar mensaje si no hay resultados
            if (visibleCount === 0) {
                let msg = table.querySelector('.filter-no-results');
                if (!msg) {
                    msg = document.createElement('tr');
                    msg.className = 'filter-no-results pulso-no-results';
                    const cols = table.querySelectorAll('thead th').length;
                    msg.innerHTML = '<td colspan="' + cols + '">No se encontraron resultados</td>';
                    table.querySelector('tbody').appendChild(msg);
                }
            } else {
                const msg = table.querySelector('.filter-no-results');
                if (msg) msg.remove();
            }
        }
        
        // Exportar tabla como Excel (.xlsx) - T2.5.1
        function exportTableAsExcel(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            // Extraer headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                let text = th.textContent.replace(/\s*⇅\s*$/, '').trim();
                headers.push(text);
            });
            
            // Extraer filas visibles
            const rows = [];
            table.querySelectorAll('tbody tr').forEach(tr => {
                if (tr.classList.contains('filter-no-results')) return;
                if (tr.style.display === 'none') return;
                const rowData = [];
                tr.querySelectorAll('td').forEach(td => {
                    rowData.push(td.textContent.trim());
                });
                if (rowData.length > 0) rows.push(rowData);
            });
            
            // Construir XML de hoja de cálculo Excel (SpreadsheetML)
            let xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
            xml += '<?mso-application progid="Excel.Sheet"?>\n';
            xml += '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
            xml += ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n';
            
            // Estilos
            xml += '<Styles>\n';
            xml += '  <Style ss:ID="header">\n';
            xml += '    <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>\n';
            xml += '    <Interior ss:Color="#012142" ss:Pattern="Solid"/>\n';
            xml += '    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>\n';
            xml += '    <Borders>\n';
            xml += '      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '    </Borders>\n';
            xml += '  </Style>\n';
            xml += '  <Style ss:ID="cell">\n';
            xml += '    <Font ss:Size="10"/>\n';
            xml += '    <Alignment ss:Vertical="Center" ss:WrapText="1"/>\n';
            xml += '    <Borders>\n';
            xml += '      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '    </Borders>\n';
            xml += '  </Style>\n';
            xml += '  <Style ss:ID="cellAlt">\n';
            xml += '    <Font ss:Size="10"/>\n';
            xml += '    <Interior ss:Color="#F8F9FA" ss:Pattern="Solid"/>\n';
            xml += '    <Alignment ss:Vertical="Center" ss:WrapText="1"/>\n';
            xml += '    <Borders>\n';
            xml += '      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '    </Borders>\n';
            xml += '  </Style>\n';
            xml += '</Styles>\n';
            
            xml += '<Worksheet ss:Name="Datos">\n';
            xml += '<Table>\n';
            
            // Anchos de columna automáticos
            headers.forEach(() => {
                xml += '<Column ss:AutoFitWidth="1" ss:Width="120"/>\n';
            });
            
            // Fila de headers
            xml += '<Row ss:Height="24">\n';
            headers.forEach(h => {
                xml += '  <Cell ss:StyleID="header"><Data ss:Type="String">' + escapeXml(h) + '</Data></Cell>\n';
            });
            xml += '</Row>\n';
            
            // Filas de datos
            rows.forEach((row, idx) => {
                xml += '<Row>\n';
                let style = idx % 2 === 0 ? 'cell' : 'cellAlt';
                row.forEach(val => {
                    // Detectar si es número
                    let numVal = parseFloat(val);
                    if (!isNaN(numVal) && val === String(numVal)) {
                        xml += '  <Cell ss:StyleID="' + style + '"><Data ss:Type="Number">' + numVal + '</Data></Cell>\n';
                    } else if (val.match(/^\d+([.,]\d+)?%?$/) && !isNaN(parseFloat(val))) {
                        xml += '  <Cell ss:StyleID="' + style + '"><Data ss:Type="Number">' + parseFloat(val) + '</Data></Cell>\n';
                    } else {
                        xml += '  <Cell ss:StyleID="' + style + '"><Data ss:Type="String">' + escapeXml(val) + '</Data></Cell>\n';
                    }
                });
                xml += '</Row>\n';
            });
            
            xml += '</Table>\n';
            xml += '</Worksheet>\n';
            xml += '</Workbook>';
            
            // Descargar archivo
            const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'pulso-datos-' + new Date().toISOString().slice(0,10) + '.xls';
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            console.log('✅ Excel export completado: ' + rows.length + ' filas exportadas');
        }
        
        // Escapar caracteres especiales XML
        function escapeXml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&apos;');
        }
        
        // Exportar tabla como CSV (T2.5.1)
        function exportTableAsCSV(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            // Extraer headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                // Remover el símbolo de sorting (⇅)
                let text = th.textContent.replace(/\s*⇅\s*$/, '').trim();
                headers.push(text);
            });
            
            // Extraer filas visibles
            const rows = [];
            table.querySelectorAll('tbody tr').forEach(tr => {
                // Saltar filas de "no resultados"
                if (tr.classList.contains('filter-no-results')) return;
                // Solo incluir filas visibles
                if (tr.style.display === 'none') return;
                
                const rowData = [];
                tr.querySelectorAll('td').forEach(td => {
                    rowData.push(escapeCsvField(td.textContent.trim()));
                });
                if (rowData.length > 0) {
                    rows.push(rowData);
                }
            });
            
            // Generar CSV
            let csv = headers.map(h => escapeCsvField(h)).join(',') + '\n';
            rows.forEach(row => {
                csv += row.join(',') + '\n';
            });
            
            // Descargar archivo
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'tabla-' + new Date().getTime() + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            console.log('✅ CSV export completado: ' + rows.length + ' filas exportadas');
        }
        
        // Escape comillas y comas en campos CSV
        function escapeCsvField(field) {
            if (!field) return '';
            field = String(field);
            // Si el campo contiene comillas, comas o saltos de línea, envolverlo en comillas
            if (field.includes(',') || field.includes('"') || field.includes('\n')) {
                return '"' + field.replace(/"/g, '""') + '"';
            }
            return field;
        }
        
        
        // Mapeo de claves técnicas del modelo → etiquetas amigables en español.
        // Compartido por las tablas (encabezados) y las tarjetas (clave: valor).
        const PULSO_FIELD_LABELS = {
            'active_users': 'Usuarios activos',
            'status': 'Estado',
            'grade': 'Calificación',
            'grade_10': 'Nota sobre 10',
            'completion': 'Completitud',
            'completado': 'Completado',
            'score': 'Puntuación',
            'pass_rate': 'Tasa de aprobación',
            'percentage': 'Porcentaje',
            'value': 'Valor',
            'count': 'Cantidad',
            'trend': 'Tendencia',
            'rank': 'Puesto',
            'reason': 'Motivo',
            'action': 'Acción recomendada',
            'risk': 'Riesgo',
            'engagement': 'Participación',
            'progress': 'Progreso',
            'avg_grade': 'Nota media',
            'color': 'Estado',
            'duration': 'Duración',
            'drop_rate': 'Tasa de abandono',
            'started': 'Iniciado',
            'completed': 'Completado',
            'period': 'Período',
            'metric': 'Métrica',
            'item': 'Elemento',
            'time': 'Hora',
            'actions': 'Acciones',
            'name': 'Nombre',
            'title': 'Título',
            'label': 'Etiqueta',
            'firstname': 'Nombre',
            'lastname': 'Apellidos',
            'student': 'Estudiante',
            'module': 'Módulo',
            'activity': 'Actividad',
            'last_access': 'Último acceso',
            'enrolled': 'Inscripción',
            'fecha_inscripcion': 'Fecha de inscripción',
            'nota_promedio': 'Nota media',
            'attempts': 'Intentos',
            'email': 'Correo'
        };

        // Etiqueta amigable para una clave: usa el mapa y, si no está, la
        // embellece (guiones bajos → espacios, primera letra en mayúscula).
        function pulsoFieldLabel(key) {
            const k = String(key || '').trim();
            const mapped = PULSO_FIELD_LABELS[k.toLowerCase()];
            if (mapped) {
                return mapped;
            }
            const pretty = k.replace(/_/g, ' ').trim();
            return pretty.charAt(0).toUpperCase() + pretty.slice(1);
        }

        function formatAsList(data) {
            if (!data || data.length === 0) {
                return '<p class="pulso-empty">No hay datos disponibles para mostrar.</p>';
            }

            let html = '<div class="pulso-list-stack">';
            
            data.forEach((item, idx) => {
                if (typeof item === 'object') {
                    // If the object has only content-wrapper keys (paragraph, text, etc.),
                    // extract the text and render it as a clean paragraph.
                    const contentOnlyKeys = ['paragraph', 'párrafo', 'parrafo', 'text', 'texto', 'content', 'contenido', 'summary', 'resumen', 'conclusion', 'conclusión', 'introduction', 'introducción', 'analysis', 'análisis', 'observation', 'observación', 'comment', 'comentario', 'response', 'respuesta', 'answer', 'description', 'descripción', 'descripcion'];
                    const keys = Object.keys(item);
                    const allContentKeys = keys.every(k => contentOnlyKeys.includes(k.toLowerCase()));
                    if (allContentKeys && keys.length > 0) {
                        const textVal = Object.values(item).map(v => String(v)).join(' ');
                        html += formatRichTextResponse(textVal, true);
                        return;
                    }

                    // Objeto con propiedades - crear tarjeta bonita
                    let primaryLabel = item.name || item.title || item.label || item.period || item.student || item.firstname || item.activity || null;
                    
                    // Encontrar propiedades clave para mostrar en la tarjeta
                    const keyProps = {};
                    const displayOrder = ['status', 'grade', 'completion', 'score', 'pass_rate', 'percentage', 'value', 'count', 'trend', 'rank', 'reason', 'action', 'risk', 'engagement', 'progress', 'avg_grade', 'color', 'duration', 'active_users', 'drop_rate', 'started', 'completed'];
                    
                    // Title-like keys that should be used as card heading (not shown as key:value).
                    const titleLikeKeys = ['strategy', 'estrategia', 'recommendation', 'recomendación', 'recomendacion', 'tip', 'consejo', 'step', 'paso', 'objective', 'objetivo', 'benefit', 'beneficio', 'advantage', 'ventaja', 'option', 'opción', 'opcion', 'feature', 'característica', 'caracteristica', 'tool', 'herramienta', 'method', 'método', 'metodo', 'approach', 'enfoque', 'solution', 'solución', 'solucion', 'idea', 'suggestion', 'sugerencia', 'challenge', 'reto', 'desafío', 'desafio', 'category', 'categoría', 'categoria', 'topic', 'tema', 'area', 'área', 'example', 'ejemplo', 'technique', 'técnica', 'tecnica', 'principle', 'principio', 'role', 'rol', 'activity', 'actividad', 'resource', 'platform', 'plataforma', 'channel', 'canal', 'technology', 'tecnología', 'tecnologia', 'database', 'component', 'componente', 'module', 'módulo', 'modulo', 'service', 'servicio', 'framework', 'library', 'librería', 'libreria', 'protocol', 'protocolo', 'pattern', 'patrón', 'patron', 'type', 'tipo'];
                    // Long text keys rendered as body description.
                    const longTextKeys = ['description', 'descripcion', 'descripción', 'detail', 'detalle', 'explanation', 'explicación', 'explicacion', 'summary', 'resumen', 'content', 'contenido', 'text', 'texto', 'note', 'nota', 'comment', 'comentario', 'details', 'detalles', 'intro', 'introduction', 'introducción', 'introduccion', 'observation', 'observación', 'observacion', 'definition', 'definición', 'definicion', 'use', 'uso', 'purpose', 'propósito', 'proposito'];

                    // If no primary label, try to extract from title-like keys.
                    if (!primaryLabel) {
                        for (const k of Object.keys(item)) {
                            if (titleLikeKeys.includes(k.toLowerCase())) {
                                primaryLabel = item[k];
                                break;
                            }
                        }
                    }

                    for (const key of displayOrder) {
                        if (item.hasOwnProperty(key) && key !== 'name' && key !== 'title' && key !== 'label' && key !== 'period') {
                            keyProps[key] = item[key];
                        }
                    }
                    
                    // Clase de acento semántico según status/riesgo.
                    let accentClass = '';

                    const statusStr = String(item.status || '');
                    if (statusStr.indexOf('PASSED') !== -1 || statusStr === 'Excellent' || /excelente/i.test(statusStr) || /✓/.test(statusStr)) {
                        accentClass = ' success';
                    } else if (item.risk === 'High' || statusStr.indexOf('FAILED') !== -1 || /❌|✕/.test(statusStr)) {
                        accentClass = ' danger';
                    } else if (statusStr.indexOf('BORDERLINE') !== -1 || item.risk === 'Medium' || /⚠/.test(statusStr)) {
                        accentClass = ' warn';
                    } else if (statusStr === 'Good' || item.completion === '100%') {
                        accentClass = ' info';
                    }

                    html += '<div class="pulso-list-card' + accentClass + '">';

                    // Título principal
                    if (primaryLabel) {
                        html += '<div class="pulso-list-card-title">' + escapeHtml(String(primaryLabel)) + '</div>';
                    }

                    // Propiedades en dos columnas
                    html += '<div class="pulso-kv-grid">';
                    for (const [key, value] of Object.entries(keyProps)) {
                        const friendlyLabel = pulsoFieldLabel(key);
                        html += '<div><span class="pulso-kv-key">' + escapeHtml(friendlyLabel) + ':</span> <span class="pulso-kv-val">' + escapeHtml(String(value)) + '</span></div>';
                    }
                    html += '</div>';
                    
                    // Agregar cualquier propiedad no estándar
                    const otherProps = Object.entries(item).filter(([k, v]) => 
                        !['name', 'title', 'label', 'period', 'student', 'firstname', ...displayOrder].includes(k)
                        && !titleLikeKeys.includes(k.toLowerCase())
                    );
                    
                    if (otherProps.length > 0) {
                        // Separate long text props (description, etc.) from short ones.
                        const shortProps = otherProps.filter(([k]) => !longTextKeys.includes(k.toLowerCase()));
                        const longProps = otherProps.filter(([k]) => longTextKeys.includes(k.toLowerCase()));

                        if (shortProps.length > 0) {
                            html += '<div class="pulso-kv-grid secondary">';
                            for (const [key, value] of shortProps) {
                                const friendlyLabel = pulsoFieldLabel(key);
                                html += '<div><span class="pulso-kv-key">' + escapeHtml(friendlyLabel) + ':</span> <span class="pulso-kv-val">' + escapeHtml(String(value)) + '</span></div>';
                            }
                            html += '</div>';
                        }
                        if (longProps.length > 0) {
                            for (const [key, value] of longProps) {
                                html += '<div class="pulso-list-card-desc">' + escapeHtml(String(value)) + '</div>';
                            }
                        }
                    }

                    html += '</div>';
                } else {
                    // String simple - renderizar como elemento de lista simple
                    html += '<div class="pulso-list-item-simple">' + escapeHtml(String(item)) + '</div>';
                }
            });
            
            html += '</div>';
            return html;
        }
        
        function escapeHtml(unsafe) {
            return String(unsafe || '')
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        
        function setMessage(text) {
            document.getElementById('pulso-input').value = text;
            updateCharCount();
        }

        // Tarjeta de acción de la pantalla de inicio: enviar pregunta predefinida.
        function askPreset(question) {
            const input = document.getElementById('pulso-input');
            input.value = question;
            updateCharCount();
            sendMessage(new Event('submit'));
        }

        // Botón "¿Qué puede hacer Pulso?": explicación instantánea (sin LLM),
        // formateada como lista, para que un recién llegado lo entienda rápido.
        function showCapabilities() {
            setHomeVisible(false);

            // Dos versiones: el alumno no puede pedir analítica, así que no se le
            // ofrece (se le rechazaría en servidor).
            const isTeacher = window.pulsoIsTeacher !== false;
            let html;
            let examples;

            if (isTeacher) {
                html = ''
                    + '<div class="pulso-rich-answer">'
                    + '<p>Soy <strong>Pulse AI</strong>, tu asistente del curso. Esto es lo que puedo hacer por ti:</p>'
                    + '<ul class="pulso-rich-bullets">'
                    + '<li>📊 <strong>Analítica del curso:</strong> tasa de completitud, notas medias, ranking de mejores alumnos y nivel de participación.</li>'
                    + '<li>⚠️ <strong>Alerta temprana:</strong> detecto alumnos en riesgo o que llevan días sin acceder.</li>'
                    + '<li>📚 <strong>Contenido del curso:</strong> resumen, secciones, actividades, recursos y cuestionarios.</li>'
                    + '<li>📄 <strong>Documentos:</strong> leo y resumo PDFs y materiales, y respondo sobre lo que dicen.</li>'
                    + '<li>🎤 <strong>Voz o texto:</strong> pregúntame en lenguaje natural escribiendo o usando el micrófono.</li>'
                    + '</ul>'
                    + '<p>👉 Toca una tarjeta de la pantalla de inicio o prueba con una de estas preguntas:</p>'
                    + '</div>';
                examples = [
                    '¿Cuál es la tasa de completitud del curso?',
                    '¿Qué estudiantes están en riesgo?',
                    '¿De qué trata este curso?'
                ];
            } else {
                html = ''
                    + '<div class="pulso-rich-answer">'
                    + '<p>Soy <strong>Pulse AI</strong>, tu asistente de estudio. Esto es lo que puedo hacer por ti:</p>'
                    + '<ul class="pulso-rich-bullets">'
                    + '<li>📚 <strong>Contenido del curso:</strong> te cuento de qué trata, qué secciones tiene y qué materiales hay en cada una.</li>'
                    + '<li>📄 <strong>Resúmenes y explicaciones:</strong> leo los PDFs y materiales del curso y te los resumo o te los explico paso a paso.</li>'
                    + '<li>❓ <strong>Dudas de estudio:</strong> pregúntame sobre lo que dice el material y te lo aclaro con ejemplos.</li>'
                    + '<li>🗂️ <strong>Actividades:</strong> te digo qué cuestionarios y tareas hay, sus instrucciones y sus fechas.</li>'
                    + '<li>🎤 <strong>Voz o texto:</strong> pregúntame en lenguaje natural escribiendo o usando el micrófono.</li>'
                    + '</ul>'
                    + '<p>Las notas y los datos de la clase los gestiona el profesorado, así que eso no te lo puedo dar. '
                    + 'Tus propias calificaciones están en el libro de calificaciones del curso.</p>'
                    + '<p>👉 Toca una tarjeta de la pantalla de inicio o prueba con una de estas preguntas:</p>'
                    + '</div>';
                examples = [
                    '¿De qué trata este curso?',
                    '¿Qué materiales hay en el curso?',
                    'Explícame de qué trata la sección 1 del curso.'
                ];
            }

            addMessage(html, 'ai', true);
            showFollowupQuestions(examples);
        }

        // La pantalla de inicio (saludo + tarjetas) solo se muestra sin conversación.
        function setHomeVisible(visible) {
            const home = document.getElementById('pulso-home');
            if (home) {
                home.style.display = visible ? '' : 'none';
            }
        }

        // ========== CREAR INFOGRAFÍA (encargo a Epica) ==========
        // Es una PANTALLA propia, no un mensaje del chat: si se colara como
        // mensaje acabaría en el historial que viaja a Anthropic en cada
        // petición. #pulso-create-panel sustituye a la home/mensajes
        // alternando la clase 'pulso-showing-create' en #pulso-messages.
        // De momento no manda nada a Epica (eso es el paso 4): solo valida,
        // comprueba cupo y guarda el encargo como "pendiente".
        let pulsoCreateResources = [];

        // Un único formulario parametrizado por herramienta, no una copia por
        // herramienta (CLAUDE.md, paso 1: "añadirlos es repetir este mismo
        // patrón"). pulsoCreateTool decide textos, si se pide formato y qué
        // envía submitCreate() — el desplegable de recursos y los cupos son
        // iguales para las dos (contador conjunto, CLAUDE.md paso 3).
        let pulsoCreateTool = 'infografia';

        const PULSO_CREATE_TOOL_LABELS = {
            infografia: {
                panelTitle: 'Crear infografía',
                promptLabel: '¿Qué infografía quieres?',
                promptPlaceholder: 'Ej: Una infografía que resuma las fases del proceso para repasarlas de un vistazo.',
                submitLabel: 'Crear infografía',
                noResourcesDefault: 'No hay recursos disponibles para crear una infografía en este curso.',
                noUsableReason: 'Ninguno de los recursos de este curso tiene texto que se pueda aprovechar para generar una infografía.'
            },
            gamificacion: {
                panelTitle: 'Crear juego',
                promptLabel: '¿Qué juego quieres?',
                promptPlaceholder: 'Ej: Un juego de emparejar términos con su definición, diez pares.',
                submitLabel: 'Crear juego',
                noResourcesDefault: 'No hay recursos disponibles para crear un juego en este curso.',
                noUsableReason: 'Ninguno de los recursos de este curso tiene texto que se pueda aprovechar para generar un juego.'
            },
            // Ampliación no es una creación de Épica: sin texto libre, sin formato
            // ni cupos de encargos (tiene sus propios topes en el servidor).
            ampliacion: {
                panelTitle: 'Ampliar recurso',
                submitLabel: 'Ampliar',
                noResourcesDefault: 'No hay recursos disponibles para ampliar en este curso.',
                noUsableReason: 'Ninguno de los recursos de este curso tiene texto que se pueda aprovechar para buscar vídeos y artículos.'
            },
            // Retos (Épica, vía api_retos.php): recurso O tema. Sin recursos sigue
            // siendo usable (solo tema), así que no hay textos de "sin recursos".
            retos: {
                panelTitle: 'Crear reto',
                submitLabel: 'Proponer retos'
            }
        };

        // Tres tipos de juego ya escritos (carta 9 de Épica, §2). Los textos de
        // "peticion" están copiados LITERALMENTE de la carta: cada detalle tiene un
        // motivo (§4) — "sobre el contenido de «{tema}»" para que el tema lo siga
        // poniendo el material, "hasta" para no obligar a inventar datos, "tocando,
        // sin arrastrar" para el móvil. No se retocan sin leer esa tabla. {tema} es
        // el nombre del recurso elegido (pulsoJuegoTema()).
        const PULSO_JUEGO_EJEMPLOS = [
            {
                id: 'preguntas',
                etiqueta: 'Preguntas con pista',
                descripcion: 'Un test de opción múltiple sobre {tema}',
                peticion: 'Un juego de preguntas de opción múltiple sobre el contenido de «{tema}»: hasta diez preguntas con cuatro respuestas cada una y una pista opcional por pregunta, que si se usa da menos puntos. Al responder, se dice si es correcta y por qué. Al terminar, la puntuación y un repaso de las preguntas falladas con su respuesta correcta.'
            },
            {
                id: 'emparejar',
                etiqueta: 'Emparejar conceptos',
                descripcion: 'Une cada concepto de {tema} con su definición',
                peticion: 'Un juego de emparejar sobre el contenido de «{tema}»: hasta ocho conceptos clave, cada uno con su definición o explicación tal como aparecen en el material. Se empareja tocando un concepto y después su definición, sin arrastrar, para que funcione igual con ratón, con teclado y en el móvil. Las parejas acertadas quedan fijadas y cada fallo resta puntos. Al terminar, la puntuación y la lista de todas las parejas correctas.'
            },
            {
                id: 'completar',
                etiqueta: 'Completar frases',
                descripcion: 'Elige la palabra que falta en frases de {tema}',
                peticion: 'Un juego de completar frases sobre el contenido de «{tema}»: hasta diez frases importantes del material a las que les falta una palabra clave, que hay que elegir entre tres opciones. Las opciones incorrectas son otras palabras del mismo material. Al responder, se enseña la frase completa. Al terminar, la puntuación y las frases falladas con su palabra correcta.'
            }
        ];

        // Último texto que puso un chip ({idx, text}) o null. Sirve para saber si el
        // usuario lo ha tocado: solo un ejemplo SIN editar se re-escribe al cambiar
        // de recurso. pulsoJuegoRenderedTema evita reconstruir los chips en cada input.
        let pulsoJuegoFill = null;
        let pulsoJuegoRenderedTema = null;

        // {tema} = name del recurso (campo del servidor, nunca el texto de la
        // <option>, que lleva el prefijo de tipo y el aviso de texto escaso) sin la
        // extensión final de archivo.
        function pulsoJuegoTema() {
            const select = document.getElementById('pulso-create-resource');
            if (!select) return '';
            const cmid = parseInt(select.value, 10);
            const resource = pulsoCreateResources.find(function(r) { return r.cmid === cmid; });
            if (!resource) return '';
            return String(resource.name || '').replace(/\.[A-Za-z]{2,5}$/, '').trim();
        }

        // split/join y no String.replace: el tema puede traer "$&" o similares.
        function pulsoJuegoFillText(text, tema) {
            return text.split('{tema}').join(tema);
        }

        // Pinta los chips (si el tema cambió) y los muestra solo con tema válido y el
        // cuadro vacío. resourceChanged: re-escribe un ejemplo sin editar con el
        // tema nuevo; si el usuario lo había editado, no lo toca.
        function pulsoJuegoRefresh(resourceChanged) {
            const box = document.getElementById('pulso-juego-ejemplos');
            const promptEl = document.getElementById('pulso-create-prompt');
            if (!box || !promptEl) return;

            const tema = pulsoJuegoTema();

            if (resourceChanged && pulsoJuegoFill) {
                if (promptEl.value === pulsoJuegoFill.text) {
                    if (tema) {
                        const text = pulsoJuegoFillText(PULSO_JUEGO_EJEMPLOS[pulsoJuegoFill.idx].peticion, tema);
                        promptEl.value = text;
                        pulsoJuegoFill.text = text;
                    } else {
                        promptEl.value = '';
                        pulsoJuegoFill = null;
                    }
                } else {
                    pulsoJuegoFill = null;
                }
            }

            if (tema !== pulsoJuegoRenderedTema) {
                pulsoJuegoRenderedTema = tema;
                let html = '';
                if (tema) {
                    html = '<div class="pulso-create-ejemplos-label">Prueba con:</div><div class="pulso-create-ejemplos-list">';
                    PULSO_JUEGO_EJEMPLOS.forEach(function(ej, i) {
                        const desc = pulsoJuegoFillText(ej.descripcion, tema);
                        html += '<button type="button" class="pulso-create-ejemplo" data-ejemplo="' + i + '" title="' + pulsoEscapeAttr(desc) + '">'
                            + '<span class="pulso-create-ejemplo-title">' + escapeHtmlText(ej.etiqueta) + '</span>'
                            + '<span class="pulso-create-ejemplo-desc">' + escapeHtmlText(desc) + '</span>'
                            + '</button>';
                    });
                    html += '</div>';
                }
                box.innerHTML = html;
            }

            box.style.display = (tema && promptEl.value.trim() === '') ? '' : 'none';
        }

        // Un clic rellena el cuadro y NADA más: ni submitCreate() ni ninguna petición.
        function pulsoJuegoPickEjemplo(idx) {
            const promptEl = document.getElementById('pulso-create-prompt');
            const ej = PULSO_JUEGO_EJEMPLOS[idx];
            const tema = pulsoJuegoTema();
            if (!promptEl || !ej || !tema) return;

            const text = pulsoJuegoFillText(ej.peticion, tema);
            promptEl.value = text;
            pulsoJuegoFill = { idx: idx, text: text };
            promptEl.focus();
            promptEl.setSelectionRange(text.length, text.length);
            pulsoJuegoRefresh(false);
        }

        function pulsoCreateSetTool(tool) {
            pulsoCreateTool = (tool === 'gamificacion' || tool === 'ampliacion' || tool === 'retos') ? tool : 'infografia';
            const titleEl = document.getElementById('pulso-create-title');
            if (titleEl) titleEl.textContent = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool].panelTitle;
        }

        function openCreatePanel(tool) {
            const messagesDiv = document.getElementById('pulso-messages');
            const body = document.getElementById('pulso-create-body');
            if (!messagesDiv || !body) return;

            pulsoCreateSetTool(tool || pulsoCreateTool);
            stopCreatePolling();
            pulsoAmpToken++;
            messagesDiv.classList.add('pulso-showing-create');
            body.innerHTML = '<p class="pulso-create-hint">Cargando…</p>';

            const params = new URLSearchParams();
            params.set('courseid', window.courseid);
            params.set('sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            if (pulsoCreateTool === 'ampliacion' || pulsoCreateTool === 'retos') params.set('tool', pulsoCreateTool);

            fetch(window.apiCreateFormUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        renderCreateNotice(data.message || 'No se ha podido comprobar el cupo. Inténtalo de nuevo.');
                        return;
                    }
                    if (!data.quota_ok) {
                        renderCreateNotice(data.quota_message || 'Has alcanzado el límite de encargos.');
                        return;
                    }
                    // Retos sigue siendo usable sin recursos: queda la opción de solo tema.
                    if ((!data.resources || data.resources.length === 0) && pulsoCreateTool !== 'retos') {
                        renderCreateNoResources(data.noresourcesreason);
                        return;
                    }
                    pulsoCreateResources = data.resources || [];
                    renderCreateForm(data.resources);
                })
                .catch(function() {
                    renderCreateNotice('No se ha podido conectar para comprobar el cupo. Inténtalo de nuevo.');
                });
        }

        function closeCreatePanel() {
            stopCreatePolling();
            pulsoAmpToken++;
            const messagesDiv = document.getElementById('pulso-messages');
            if (messagesDiv) {
                messagesDiv.classList.remove('pulso-showing-create');
            }
        }

        // La galería de "últimas infografías" se enseña siempre debajo,
        // tenga o no cupo el usuario ahora mismo: una lámina de ayer no deja
        // de existir porque hoy no queden encargos.
        function renderCreateNotice(text) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            const isamp = pulsoCreateTool === 'ampliacion';
            body.innerHTML = '<div class="pulso-create-notice"></div>' + (isamp ? '' : '<div id="pulso-create-gallery"></div>');
            body.querySelector('.pulso-create-notice').textContent = text;
            if (!isamp) loadCreateGallery();
        }

        function renderCreateNoResources(reason) {
            const labels = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool];
            const messages = {
                'not_indexed': 'Este curso todavía no se ha indexado. La indexación es nocturna: si el curso es nuevo, vuelve a intentarlo mañana.',
                'no_usable': labels.noUsableReason,
                'no_visible': 'No tienes acceso a ningún recurso indexado de este curso.'
            };
            renderCreateNotice(messages[reason] || labels.noResourcesDefault);
        }

        function renderCreateForm(resources) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;

            const labels = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool];
            const isjuego = pulsoCreateTool === 'gamificacion';
            const isamp = pulsoCreateTool === 'ampliacion';

            if (pulsoCreateTool === 'retos') {
                renderRetosForm(resources);
                return;
            }

            let optionsHtml = '';
            resources.forEach(function(r) {
                const flag = r.lowtext ? ' — texto escaso, resultado limitado' : '';
                optionsHtml += '<option value="' + r.cmid + '">'
                    + escapeHtmlText(r.moduletypelabel) + ': ' + escapeHtmlText(r.name) + escapeHtmlText(flag)
                    + '</option>';
            });

            // El formato es solo de las infografías (CLAUDE.md, paso 3): un
            // juego no lleva ese campo, ni en el formulario ni en el envío.
            const formatFieldHtml = isjuego ? '' : (
                '<div class="pulso-create-field">'
                + '<label for="pulso-create-format">Formato</label>'
                + '<select id="pulso-create-format">'
                + '<option value="poster_2_3">Póster (2:3)</option>'
                + '<option value="square">Cuadrado</option>'
                + '<option value="landscape_3_2">Horizontal (3:2)</option>'
                + '</select>'
                + '</div>'
            );

            // Ampliación: solo recurso + botón. Sin aviso de cupo por sección ni
            // galería (una ampliación es compartida por recurso, no del usuario).
            if (isamp) {
                body.innerHTML = ''
                    + '<div class="pulso-create-field">'
                    + '<label for="pulso-create-resource">Recurso</label>'
                    + '<select id="pulso-create-resource">' + optionsHtml + '</select>'
                    + '</div>'
                    + '<button type="button" class="pulso-create-submit" id="pulso-create-submit-btn" onclick="submitAmpliacion()">'
                    + escapeHtmlText(labels.submitLabel) + '</button>';
                return;
            }

            body.innerHTML = ''
                + '<div class="pulso-create-field">'
                + '<label for="pulso-create-resource">Recurso</label>'
                + '<select id="pulso-create-resource">' + optionsHtml + '</select>'
                + '<div class="pulso-create-hint" id="pulso-create-section-hint"></div>'
                + '</div>'
                + '<div class="pulso-create-field">'
                + '<label for="pulso-create-prompt">' + escapeHtmlText(labels.promptLabel) + '</label>'
                + '<textarea id="pulso-create-prompt" maxlength="4000" placeholder="' + pulsoEscapeAttr(labels.promptPlaceholder) + '"></textarea>'
                + (isjuego ? '<div class="pulso-create-ejemplos" id="pulso-juego-ejemplos" style="display:none"></div>' : '')
                + '</div>'
                + formatFieldHtml
                + '<button type="button" class="pulso-create-submit" id="pulso-create-submit-btn" onclick="submitCreate()">' + escapeHtmlText(labels.submitLabel) + '</button>'
                + '<div id="pulso-create-gallery"></div>';

            const select = document.getElementById('pulso-create-resource');
            if (select) {
                select.addEventListener('change', updateCreateSectionHint);
                updateCreateSectionHint();
            }

            if (isjuego) {
                pulsoJuegoFill = null;
                pulsoJuegoRenderedTema = null;
                const promptEl = document.getElementById('pulso-create-prompt');
                const box = document.getElementById('pulso-juego-ejemplos');
                if (select) select.addEventListener('change', function() { pulsoJuegoRefresh(true); });
                if (promptEl) promptEl.addEventListener('input', function() { pulsoJuegoRefresh(false); });
                if (box) {
                    box.addEventListener('click', function(e) {
                        const chip = e.target.closest('[data-ejemplo]');
                        if (chip) pulsoJuegoPickEjemplo(parseInt(chip.getAttribute('data-ejemplo'), 10));
                    });
                }
                pulsoJuegoRefresh(false);
            }
            loadCreateGallery();
        }

        // Avisa del cupo por sección ANTES de que el usuario escriba su
        // petición (con los datos que ya trajo el GET, sin otra petición):
        // un tope que se descubre después de teclear se lee como una avería.
        function updateCreateSectionHint() {
            const select = document.getElementById('pulso-create-resource');
            const hint = document.getElementById('pulso-create-section-hint');
            const btn = document.getElementById('pulso-create-submit-btn');
            if (!select || !hint) return;

            const cmid = parseInt(select.value, 10);
            const resource = pulsoCreateResources.find(function(r) { return r.cmid === cmid; });
            if (!resource) return;

            if (resource.sectionused >= resource.sectionlimit) {
                hint.textContent = 'Ya has llegado al límite de encargos de hoy para la sección de este recurso ('
                    + resource.sectionlimit + '). Elige otro recurso.';
                hint.classList.add('warn');
                if (btn) btn.disabled = true;
            } else {
                hint.textContent = '';
                hint.classList.remove('warn');
                if (btn) btn.disabled = false;
            }
        }

        function submitCreate() {
            const select = document.getElementById('pulso-create-resource');
            const promptEl = document.getElementById('pulso-create-prompt');
            const formatEl = document.getElementById('pulso-create-format');
            const btn = document.getElementById('pulso-create-submit-btn');
            if (!select || !promptEl) return;

            const isjuego = pulsoCreateTool === 'gamificacion';
            const labels = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool];

            const prompt = promptEl.value.trim();
            if (!prompt) {
                promptEl.focus();
                return;
            }

            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Guardando…';
            }

            const formData = new FormData();
            formData.append('sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            formData.append('courseid', window.courseid);
            formData.append('cmid', select.value);
            formData.append('prompt', prompt);
            formData.append('tool', pulsoCreateTool);
            if (!isjuego && formatEl) {
                formData.append('format', formatEl.value);
            }

            fetch(window.apiCreateSubmitUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        const body = document.getElementById('pulso-create-body');
                        if (body) body.innerHTML = '<p class="pulso-create-hint">Encargo guardado. Comprobando estado…</p>';
                        startCreatePolling(data.encargoid);
                    } else {
                        renderCreateNotice(data.message || 'No se ha podido guardar el encargo. Inténtalo de nuevo.');
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = labels.submitLabel;
                        }
                    }
                })
                .catch(function() {
                    renderCreateNotice('No se ha podido conectar para guardar el encargo. Inténtalo de nuevo.');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = labels.submitLabel;
                    }
                });
        }

        // ---- Ampliar recurso: vídeos + artículos (api_ampliacion.php) ----
        // Misma pantalla y mismo desplegable que Crear. TODO lo que viene de
        // fuera (títulos, canales, autores, revistas, tema, avisos) se escapa;
        // las URLs solo se pintan si son https://, y no se incrusta YouTube.
        let pulsoAmpToken = 0;

        // escapeHtmlText no escapa comillas: para CUALQUIER valor de atributo (href, src, alt, placeholder…) usar esta.
        function pulsoEscapeAttr(text) {
            return escapeHtmlText(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function pulsoAmpIsHttps(url) {
            return typeof url === 'string' && url.indexOf('https://') === 0;
        }

        function pulsoAmpFormatViews(n) {
            n = Number(n) || 0;
            if (n <= 0) return '';
            if (n >= 1000000) {
                return (n / 1000000).toLocaleString('es-ES', { maximumFractionDigits: 1 }) + ' M de visualizaciones';
            }
            return n.toLocaleString('es-ES') + (n === 1 ? ' visualización' : ' visualizaciones');
        }

        function pulsoAmpFormatDuration(seconds) {
            const s = Math.round(Number(seconds) || 0);
            if (s <= 0) return '';
            if (s < 60) return s + ' s';
            const m = Math.round(s / 60);
            if (m < 60) return m + ' min';
            const h = Math.floor(m / 60);
            const r = m % 60;
            return h + ' h' + (r ? ' ' + r + ' min' : '');
        }

        function pulsoAmpCard(url, inner) {
            if (pulsoAmpIsHttps(url)) {
                return '<a class="pulso-amp-card" href="' + pulsoEscapeAttr(url) + '" target="_blank" rel="noopener noreferrer">' + inner + '</a>';
            }
            return '<div class="pulso-amp-card">' + inner + '</div>';
        }

        function pulsoAmpVideoCard(v) {
            const title = v.titulo || 'Sin título';
            const thumbOk = typeof v.miniatura === 'string' && v.miniatura.indexOf('https://i.ytimg.com/') === 0;
            const thumb = thumbOk
                ? '<img class="pulso-amp-thumb" src="' + pulsoEscapeAttr(v.miniatura) + '" alt="' + pulsoEscapeAttr(title)
                    + '" loading="lazy" referrerpolicy="no-referrer">'
                : '<span class="pulso-amp-thumb pulso-amp-thumb-empty" aria-hidden="true">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><polygon points="10 9 15 12 10 15 10 9"/></svg></span>';
            const meta = [v.canal || '', pulsoAmpFormatViews(v.vistas), pulsoAmpFormatDuration(v.duracion)].filter(Boolean);
            return pulsoAmpCard(v.url, thumb
                + '<span class="pulso-amp-card-text">'
                + '<span class="pulso-amp-card-title">' + escapeHtmlText(title) + '</span>'
                + (meta.length ? '<span class="pulso-amp-card-meta">' + escapeHtmlText(meta.join(' · ')) + '</span>' : '')
                + '</span>');
        }

        // «En inglés»: solo si el articulo trae idioma y no es el del curso.
        function pulsoAmpLangLabel(code) {
            if (!code) return 'En otro idioma';
            try {
                const name = new Intl.DisplayNames(['es'], { type: 'language' }).of(code);
                if (name && name !== code) return 'En ' + name;
            } catch (e) { /* navegador sin Intl.DisplayNames */ }
            return 'En otro idioma';
        }

        function pulsoAmpArticleCard(a, courseLang) {
            const title = a.titulo || 'Sin título';
            const otherLang = a.idioma && courseLang && a.idioma !== courseLang;
            const line = [a.autores || '', a.anio ? String(a.anio) : '', a.revista || ''].filter(Boolean);
            const cites = Number(a.citas) || 0;
            return pulsoAmpCard(a.url,
                '<span class="pulso-amp-card-text">'
                + '<span class="pulso-amp-card-title">' + escapeHtmlText(title) + '</span>'
                + (line.length ? '<span class="pulso-amp-card-meta">' + escapeHtmlText(line.join(' · ')) + '</span>' : '')
                + (cites > 0 ? '<span class="pulso-amp-card-meta">' + escapeHtmlText(cites.toLocaleString('es-ES') + (cites === 1 ? ' cita' : ' citas')) + '</span>' : '')
                + (a.acceso_abierto ? '<span class="pulso-amp-tag">Acceso abierto</span>' : '')
                + (otherLang ? '<span class="pulso-amp-tag pulso-amp-tag-lang">' + escapeHtmlText(pulsoAmpLangLabel(a.idioma)) + '</span>' : '')
                + '</span>');
        }

        function pulsoAmpActions(primaryLabel) {
            return '<div class="pulso-amp-actions">'
                + '<button type="button" class="pulso-create-submit" onclick="openCreatePanel(\'ampliacion\')">' + escapeHtmlText(primaryLabel) + '</button>'
                + '<button type="button" class="pulso-create-back-link" onclick="closeCreatePanel()">Volver</button>'
                + '</div>';
        }

        function renderAmpliacionError(message) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-amp">'
                + '<div class="pulso-create-notice-inline danger">' + escapeHtmlText(message) + '</div>'
                + pulsoAmpActions('Ampliar otro recurso')
                + '</div>';
        }

        function renderAmpliacion(data) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            const videos = Array.isArray(data.videos) ? data.videos : [];
            const articulos = Array.isArray(data.articulos) ? data.articulos : [];
            const avisos = Array.isArray(data.avisos) ? data.avisos : [];

            let html = '<div class="pulso-amp">';
            if (data.tema) {
                html += '<div class="pulso-amp-head">Para ampliar: <strong>' + escapeHtmlText(data.tema) + '</strong></div>';
            }
            if (videos.length) {
                html += '<div><div class="pulso-amp-section-title">Vídeos</div><div class="pulso-amp-list">'
                    + videos.map(pulsoAmpVideoCard).join('') + '</div></div>';
            }
            if (articulos.length) {
                html += '<div><div class="pulso-amp-section-title">Artículos académicos</div><div class="pulso-amp-list">'
                    + articulos.map(function (a) { return pulsoAmpArticleCard(a, data.idioma_curso); }).join('') + '</div></div>';
            }
            avisos.forEach(function(a) {
                html += '<div class="pulso-create-notice-inline">' + escapeHtmlText(a) + '</div>';
            });
            if (!videos.length && !articulos.length && !avisos.length) {
                html += '<div class="pulso-create-notice-inline">No hemos encontrado contenido para ampliar este recurso.</div>';
            }
            html += '<div class="pulso-amp-footnote">'
                + (window.pulsoIsTeacher
                    ? 'Contenido externo seleccionado automáticamente. Revísalo antes de usarlo en clase.'
                    : 'Contenido externo seleccionado automáticamente.')
                + '</div>'
                + pulsoAmpActions('Ampliar otro recurso')
                + '</div>';
            body.innerHTML = html;
        }

        function submitAmpliacion() {
            const select = document.getElementById('pulso-create-resource');
            const btn = document.getElementById('pulso-create-submit-btn');
            const body = document.getElementById('pulso-create-body');
            if (!select || !body || (btn && btn.disabled)) return;
            if (btn) btn.disabled = true;

            const cmid = parseInt(select.value, 10);
            const resource = pulsoCreateResources.find(function(r) { return r.cmid === cmid; });
            const name = resource ? resource.name : '';
            const token = ++pulsoAmpToken;

            body.innerHTML = '<div class="pulso-amp-loading" role="status">'
                + '<span class="pulso-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
                + '<span>Buscando vídeos y artículos sobre ' + escapeHtmlText(name) + '…</span></div>';

            const formData = new FormData();
            formData.append('sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            formData.append('courseid', window.courseid);
            formData.append('cmid', String(cmid));

            fetch(window.apiAmpliacionUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (token !== pulsoAmpToken) return; // el usuario ya salió de esta pantalla
                    if (data && data.success) {
                        renderAmpliacion(data);
                    } else {
                        renderAmpliacionError((data && data.message) || 'No se ha podido ampliar el recurso. Inténtalo de nuevo.');
                    }
                })
                .catch(function() {
                    if (token !== pulsoAmpToken) return;
                    renderAmpliacionError('No se ha podido conectar para ampliar el recurso. Inténtalo de nuevo.');
                });
        }

        // ---- Crear reto (api_retos.php, v1.27) ----
        // Mismo panel y mismo desplegable que el resto de Crear. Retos se llama desde la
        // petición web (carta 8 §1): el navegador sondea NUESTRO endpoint cada 4 s y
        // nunca habla con Épica. Todo lo que viene de Épica se escapa (atributos con
        // pulsoEscapeAttr), los enlaces solo si son https:// y no hay ningún iframe:
        // el reto se abre siempre en pestaña nueva (Épica sirve X-Frame-Options: DENY).
        const PULSO_RETOS_POLL_MS = 4000;
        const PULSO_RETOS_SLOW_S = 120;        // límite de espera, contado desde el primer "trabajando"
        const PULSO_RETOS_REFRESH_MS = 45000;  // primera petición del título final del reto escrito
        const PULSO_RETOS_REFRESH_RETRY_MS = 20000;  // reintentos mientras Épica siga en-cola/trabajando
        const PULSO_RETOS_REFRESH_MAX_MS = 180000;   // tope total desde que se pinta el reto
        const PULSO_RETO_PROPIO_MIN = 8;
        const PULSO_RETO_PROPIO_MAX = 140;

        let pulsoRetosBusy = false;            // antidoble clic: una sola acción en vuelo
        let pulsoRetosCourseBusy = false;
        let pulsoRetosPollTimer = null;
        let pulsoRetosRefreshTimer = null;
        let pulsoRetosPropuestaId = 0;
        let pulsoRetosList = [];               // los seis retos; se elige por ÍNDICE, el id nunca va a un atributo
        let pulsoRetosGrace = 0;               // "Seguir esperando": trabajando_desde ya tolerado
        let pulsoRetosLastSeen = 0;
        let pulsoRetosLastParams = null;       // para «Volver a intentarlo» (propuesta nueva)
        let pulsoRetosRetryFn = null;          // «Reintentar» repite la MISMA acción

        // Los doce nombres fijos de icono de Épica; uno desconocido cae en 'flag'.
        const PULSO_RETOS_ICONS = {
            wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            chart: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            network: '<rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/>',
            users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            lightbulb: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
            code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            cpu: '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
            target: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            briefcase: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            leaf: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
            flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>'
        };

        // Texto de Épica → HTML: el escape va SIEMPRE antes; luego solo **negrita**, nada más de markdown.
        function pulsoRetosMd(text) {
            return escapeHtmlText(text).replace(/\*\*([^*\r\n]+?)\*\*/g, '<strong>$1</strong>');
        }

        function pulsoRetosIconSvg(name) {
            const key = String(name || '').toLowerCase();
            const path = Object.prototype.hasOwnProperty.call(PULSO_RETOS_ICONS, key) ? PULSO_RETOS_ICONS[key] : PULSO_RETOS_ICONS.flag;
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
        }

        // Inicial / Intermedio / Avanzado con los colores de estado del tema claro.
        function pulsoRetosDiff(raw) {
            const key = String(raw || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            const known = { inicial: ['Inicial', 'ok'], basico: ['Inicial', 'ok'], intermedio: ['Intermedio', 'warn'], avanzado: ['Avanzado', 'danger'] };
            if (Object.prototype.hasOwnProperty.call(known, key)) return { label: known[key][0], cls: known[key][1] };
            return raw ? { label: String(raw), cls: '' } : null;
        }

        // Llamada única a api_retos.php. Nunca rechaza: devuelve el JSON del servidor
        // ({ok:true,…} o {ok:false,error,mensaje,…}) o un error de red reintentable.
        function pulsoRetosCall(accion, fields) {
            const fd = new FormData();
            fd.append('sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            fd.append('courseid', window.courseid);
            fd.append('accion', accion);
            Object.keys(fields || {}).forEach(function(k) { fd.append(k, fields[k]); });
            return fetch(window.apiRetosUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function(r) {
                    return r.json().catch(function() {
                        return { ok: false, error: 'respuesta-invalida', mensaje: 'La respuesta del servidor no es válida. Inténtalo de nuevo.', reintentable: true };
                    });
                })
                .catch(function() {
                    return { ok: false, error: 'sin-conexion', mensaje: 'No se ha podido conectar. Comprueba tu conexión e inténtalo de nuevo.', reintentable: true };
                });
        }

        // Al salir del panel o abrir otra pantalla (la llama stopCreatePolling):
        // para sondeo y refresco, libera el antidoble clic e invalida con
        // pulsoAmpToken cualquier respuesta tardía.
        function pulsoRetosReset() {
            if (pulsoRetosPollTimer) { clearTimeout(pulsoRetosPollTimer); pulsoRetosPollTimer = null; }
            if (pulsoRetosRefreshTimer) { clearTimeout(pulsoRetosRefreshTimer); pulsoRetosRefreshTimer = null; }
            pulsoRetosBusy = false;
            pulsoRetosCourseBusy = false;
            pulsoRetosRetryFn = null;
            pulsoAmpToken++;
        }

        // Antidoble clic: con una acción en vuelo se desactivan TODAS las de la pantalla.
        function pulsoRetosSetBusy(busy) {
            pulsoRetosBusy = busy;
            document.querySelectorAll('#pulso-create-body [data-retos-action]').forEach(function(el) { el.disabled = busy; });
        }

        const PULSO_RETOS_BANNER_ACTIONS = {
            retry: ['Reintentar', 'pulsoRetosRetry()'],
            wait: ['Seguir esperando', 'pulsoRetosSeguirEsperando()']
        };

        // Aviso en #pulso-retos-msg (texto siempre con textContent). action: 'retry' | 'wait'.
        function pulsoRetosBanner(text, kind, action) {
            const el = document.getElementById('pulso-retos-msg');
            if (!el) return;
            if (!text) { el.innerHTML = ''; return; }
            const act = action ? PULSO_RETOS_BANNER_ACTIONS[action] : null;
            el.innerHTML = '<div class="pulso-create-notice-inline ' + (kind || '') + '"></div>'
                + (act ? '<button type="button" class="pulso-create-secondary" onclick="' + act[1] + '">' + act[0] + '</button>' : '');
            el.firstChild.textContent = text;
        }

        // Se decide por `error`, nunca por el texto. Solo se reescribe lo que tiene
        // texto propio; el resto (topes propios, herramienta-no-contratada,
        // epica-no-disponible, encargo-sin-tema…) usa el mensaje del servidor.
        function pulsoRetosErrorText(d) {
            const msg = (d && typeof d.mensaje === 'string' && d.mensaje) ? d.mensaje : 'No hemos podido completar la operación. Inténtalo de nuevo.';
            switch (d && d.error) {
                case 'cuota-agotada': {
                    const s = Number(d.esperaS) || 0;
                    return s > 0 ? 'Has pedido varios seguidos; espera ' + Math.max(1, Math.ceil(s / 60)) + ' min.'
                                 : 'Has pedido varios seguidos; espera unos minutos.';
                }
                case 'cuota-del-centro': return 'El centro ha alcanzado el límite de hoy. No es nada que hayas hecho tú.';
                case 'material-ilegible': return 'No hemos podido leer este recurso; prueba con otro o con un tema.';
                case 'propuesta-desconocida': return 'Esta propuesta ya no está disponible.';
                case 'reto-desconocido': return 'Ese reto no estaba entre los propuestos. Elige otro de la lista.';
                default: return msg;
            }
        }

        function pulsoRetosHandleError(d) {
            const code = d && d.error;
            if (code === 'peticion-en-curso') return; // ya hay una en vuelo: en silencio
            if (code === 'propuesta-desconocida') {
                pulsoRetosRenderEnd('danger', pulsoRetosErrorText(d), 'Proponer retos de nuevo', 'form');
                return;
            }
            pulsoRetosBanner(pulsoRetosErrorText(d), 'danger',
                (d && d.reintentable === true && typeof pulsoRetosRetryFn === 'function') ? 'retry' : null);
        }

        function pulsoRetosRetry() {
            const fn = pulsoRetosRetryFn;
            if (pulsoRetosBusy || typeof fn !== 'function') return;
            pulsoRetosBanner('');
            fn();
        }

        // --- Formulario ---
        function renderRetosForm(resources) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;

            let optionsHtml = '<option value="0">Sin recurso, solo un tema</option>';
            resources.forEach(function(r) {
                const flag = r.lowtext ? ' — texto escaso, resultado limitado' : '';
                optionsHtml += '<option value="' + r.cmid + '">'
                    + escapeHtmlText(r.moduletypelabel) + ': ' + escapeHtmlText(r.name) + escapeHtmlText(flag)
                    + '</option>';
            });

            body.innerHTML = ''
                + '<div class="pulso-create-field">'
                + '<label for="pulso-create-resource">Recurso</label>'
                + '<select id="pulso-create-resource" data-retos-action>' + optionsHtml + '</select>'
                + '</div>'
                + '<div class="pulso-create-field">'
                + '<label for="pulso-retos-tema">Tema (opcional)</label>'
                + '<textarea id="pulso-retos-tema" data-retos-action maxlength="500" placeholder="'
                + pulsoEscapeAttr('Por ejemplo: muestreo estratificado en una encuesta sobre ocio juvenil') + '"></textarea>'
                + '<div class="pulso-create-hint">Obligatorio si eliges «Sin recurso». Con un recurso, acota los retos dentro de su contenido.</div>'
                + '</div>'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<button type="button" class="pulso-create-submit" id="pulso-retos-propose-btn" data-retos-action onclick="pulsoRetosProponer()">Proponer retos</button>'
                + '<button type="button" class="pulso-create-back-link" onclick="pulsoRetosVerCurso()">Ver todos los retos del curso</button>'
                + '<div id="pulso-create-gallery"></div>';
            loadCreateGallery();
        }

        function pulsoRetosProponer() {
            const select = document.getElementById('pulso-create-resource');
            const temaEl = document.getElementById('pulso-retos-tema');
            if (!select || !temaEl || pulsoRetosBusy) return;
            const cmid = parseInt(select.value, 10) || 0;
            const tema = temaEl.value.trim();
            if (!cmid && !tema) {
                pulsoRetosBanner('Elige un recurso o escribe un tema para los retos.', 'warn');
                temaEl.focus();
                return;
            }
            const params = {};
            if (cmid > 0) params.cmid = String(cmid);
            if (tema) params.tema = tema;
            pulsoRetosEnviarProponer(params, 'pulso-retos-propose-btn');
        }

        // «Proponer otros»: SOLO `propuesta` (con recurso o tema sería encargo-ambiguo).
        function pulsoRetosOtros() {
            pulsoRetosEnviarProponer({ propuesta: String(pulsoRetosPropuestaId) }, 'pulso-retos-others-btn');
        }

        // «Volver a intentarlo» tras un fallado: es una propuesta nueva.
        function pulsoRetosVolverAIntentar() {
            if (!pulsoRetosLastParams) { openCreatePanel('retos'); return; }
            pulsoRetosEnviarProponer(pulsoRetosLastParams, 'pulso-retos-end-btn');
        }

        // Una propuesta nueva (gasta cupo): un solo vuelo a la vez. La pantalla
        // actual se queda como está hasta tener respuesta (así un 409 ignorado
        // no deja nada roto); solo el botón pulsado cambia de texto.
        function pulsoRetosEnviarProponer(params, btnId) {
            if (pulsoRetosBusy) return;
            pulsoRetosSetBusy(true);
            const token = pulsoAmpToken;
            const btn = document.getElementById(btnId);
            const label = btn ? btn.textContent : '';
            if (btn) btn.textContent = 'Enviando…';
            pulsoRetosBanner('');
            pulsoRetosRetryFn = function() { pulsoRetosEnviarProponer(params, btnId); };

            pulsoRetosCall('proponer', params).then(function(d) {
                if (token !== pulsoAmpToken) return; // el usuario ya salió de esta pantalla
                // proponer devuelve el id PLANO en `propuesta` (el sondeo lo devuelve anidado).
                const id = Number(d.propuesta && typeof d.propuesta === 'object' ? d.propuesta.id : d.propuesta) || 0;
                if (!d.ok || !id) {
                    pulsoRetosSetBusy(false);
                    if (btn) btn.textContent = label;
                    pulsoRetosHandleError(d.ok ? { error: 'respuesta-inesperada', mensaje: 'No hemos recibido el identificador de la propuesta. Inténtalo de nuevo.' } : d);
                    return;
                }
                pulsoRetosSetBusy(false);
                pulsoRetosPropuestaId = id;
                pulsoRetosLastParams = params;
                pulsoRetosGrace = 0;
                pulsoRetosLastSeen = 0;
                pulsoRetosRenderWaiting();
                pulsoRetosShowStatus(d.estado, d.posicion);
                pulsoRetosRetryFn = function() { pulsoRetosPoll(pulsoAmpToken); };
                pulsoRetosSchedulePoll(token);
            });
        }

        // --- Esperando las propuestas ---
        function pulsoRetosRenderWaiting() {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-retos">'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<div class="pulso-amp-loading" role="status">'
                + '<span class="pulso-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
                + '<span id="pulso-retos-status">Preparando tus retos…</span></div>'
                + '</div>';
        }

        function pulsoRetosShowStatus(estado, posicion) {
            const el = document.getElementById('pulso-retos-status');
            if (!el) return;
            const n = Number(posicion) || 0;
            el.textContent = estado === 'trabajando' ? 'Escribiendo seis retos para ti…'
                : (n > 0 ? 'Hay ' + n + ' por delante…' : 'Preparando tus retos…');
        }

        function pulsoRetosSchedulePoll(token) {
            pulsoRetosPollTimer = setTimeout(function() { pulsoRetosPoll(token); }, PULSO_RETOS_POLL_MS);
        }

        // NO se corta mientras esté `en-cola` (carta 8 §1: cortar y volver a pulsar
        // encola otra propuesta y gasta otra unidad del cupo). El límite de 2 min
        // cuenta desde el primer `trabajando` (trabajando_desde) y solo ofrece
        // «Seguir esperando», que reanuda la MISMA propuesta.
        function pulsoRetosPoll(token) {
            pulsoRetosPollTimer = null;
            if (token !== pulsoAmpToken) return;
            pulsoRetosRetryFn = function() { pulsoRetosPoll(pulsoAmpToken); };

            pulsoRetosCall('propuesta', { propuesta: String(pulsoRetosPropuestaId) }).then(function(d) {
                if (token !== pulsoAmpToken) return;
                if (!d.ok) { pulsoRetosHandleError(d); return; } // el sondeo se detiene; «Reintentar» lo reanuda
                const p = d.propuesta || {};

                if (p.estado === 'listo') { pulsoRetosRenderPropuestas(p); return; }
                if (p.estado === 'fallado') {
                    pulsoRetosRenderEnd('danger', 'No hemos podido preparar los retos' + (p.motivo ? ': ' + p.motivo : '.'), 'Volver a intentarlo', 'retry');
                    return;
                }
                if (p.estado === 'desconocido') {
                    pulsoRetosRenderEnd('warn', 'Esta propuesta ya no está disponible.', 'Volver al formulario', 'form');
                    return;
                }

                pulsoRetosShowStatus(p.estado, p.posicion);
                if (p.estado === 'trabajando') {
                    pulsoRetosLastSeen = Number(p.trabajando_desde) || 0;
                    if (pulsoRetosLastSeen - pulsoRetosGrace >= PULSO_RETOS_SLOW_S) {
                        pulsoRetosBanner('Está tardando más de lo normal.', 'warn', 'wait');
                        return;
                    }
                }
                pulsoRetosSchedulePoll(token);
            });
        }

        function pulsoRetosSeguirEsperando() {
            pulsoRetosGrace = pulsoRetosLastSeen; // otros 2 min a partir de ahora
            pulsoRetosBanner('');
            pulsoRetosPoll(pulsoAmpToken);
        }

        // Pantalla final de error/aviso: act 'retry' = propuesta nueva con los mismos datos; 'form' = formulario.
        function pulsoRetosRenderEnd(kind, text, label, act) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-retos">'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<button type="button" class="pulso-create-submit" id="pulso-retos-end-btn" data-retos-action onclick="'
                + (act === 'retry' ? 'pulsoRetosVolverAIntentar()' : "openCreatePanel('retos')") + '">' + escapeHtmlText(label) + '</button>'
                + '</div>';
            pulsoRetosBanner(text, kind);
        }

        // --- Las seis propuestas ---
        function pulsoRetosChip(text) {
            return '<span class="pulso-retos-chip">' + escapeHtmlText(text) + '</span>';
        }

        function pulsoRetosRenderPropuestas(p) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            pulsoRetosList = Array.isArray(p.retos) ? p.retos : [];
            if (!pulsoRetosList.length) {
                pulsoRetosRenderEnd('danger', 'No hemos recibido ningún reto. Inténtalo de nuevo.', 'Volver a intentarlo', 'retry');
                return;
            }
            const doc = p.documento || {};
            const temas = Array.isArray(doc.temas) ? doc.temas : [];

            let html = '<div class="pulso-retos">'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<div><div class="pulso-retos-doc-title">' + escapeHtmlText(doc.titulo || 'Retos para ti') + '</div>'
                + (doc.resumen ? '<div class="pulso-retos-note">' + pulsoRetosMd(doc.resumen) + '</div>' : '')
                + (temas.length ? '<div class="pulso-retos-chips">' + temas.map(pulsoRetosChip).join('') + '</div>' : '')
                + '</div>'
                + '<div class="pulso-create-hint">Elige el reto que quieras hacer.</div>';

            pulsoRetosList.forEach(function(r, i) {
                const diff = pulsoRetosDiff(r.dificultad);
                const minutos = Number(r.minutos) || 0;
                const comps = Array.isArray(r.competencias) ? r.competencias : [];
                html += '<button type="button" class="pulso-reto-card" data-retos-action onclick="pulsoRetosElegir(' + i + ')">'
                    + '<span class="pulso-reto-top">'
                    + '<span class="pulso-reto-icon" aria-hidden="true">' + pulsoRetosIconSvg(r.icono) + '</span>'
                    + '<span class="pulso-reto-title">' + escapeHtmlText(r.titulo) + '</span></span>'
                    + (r.descripcion ? '<span class="pulso-reto-desc">' + pulsoRetosMd(r.descripcion) + '</span>' : '')
                    + '<span class="pulso-reto-meta">'
                    + (diff ? '<span class="pulso-reto-diff ' + diff.cls + '">' + escapeHtmlText(diff.label) + '</span>' : '')
                    + (minutos > 0 ? '<span class="pulso-reto-min">' + minutos + ' min</span>' : '')
                    + comps.map(pulsoRetosChip).join('')
                    + '</span>'
                    + '<span class="pulso-reto-cta">Elegir este reto</span>'
                    + '</button>';
            });

            html += '<button type="button" class="pulso-create-secondary" id="pulso-retos-others-btn" data-retos-action onclick="pulsoRetosOtros()">Proponer otros</button>'
                + '<button type="button" class="pulso-create-secondary" id="pulso-retos-idea-btn" data-retos-action aria-expanded="false" aria-controls="pulso-retos-own" onclick="pulsoRetosToggleIdea()">¿Tienes otra idea?</button>'
                + '<div class="pulso-retos-own" id="pulso-retos-own" hidden>'
                + '<label class="pulso-retos-note" for="pulso-retos-own-text">Cuéntanos qué reto quieres (entre ' + PULSO_RETO_PROPIO_MIN + ' y ' + PULSO_RETO_PROPIO_MAX + ' caracteres)</label>'
                + '<textarea id="pulso-retos-own-text" data-retos-action maxlength="' + PULSO_RETO_PROPIO_MAX + '" oninput="pulsoRetosOwnCount()"></textarea>'
                + '<span class="pulso-retos-own-count" id="pulso-retos-own-count">0/' + PULSO_RETO_PROPIO_MAX + '</span>'
                + '<button type="button" class="pulso-create-submit" id="pulso-retos-own-btn" data-retos-action onclick="pulsoRetosElegirPropio()">Crear mi reto</button>'
                + '</div>'
                + '<button type="button" class="pulso-create-back-link" data-retos-action onclick="openCreatePanel(\'retos\')">← Volver al formulario</button>'
                + '</div>';
            body.innerHTML = html;
        }

        function pulsoRetosToggleIdea() {
            const box = document.getElementById('pulso-retos-own');
            const btn = document.getElementById('pulso-retos-idea-btn');
            if (!box || pulsoRetosBusy) return;
            box.hidden = !box.hidden;
            if (btn) btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
            if (!box.hidden) {
                const ta = document.getElementById('pulso-retos-own-text');
                if (ta) ta.focus();
            }
        }

        function pulsoRetosOwnCount() {
            const ta = document.getElementById('pulso-retos-own-text');
            const out = document.getElementById('pulso-retos-own-count');
            if (ta && out) out.textContent = ta.value.length + '/' + PULSO_RETO_PROPIO_MAX;
        }

        // --- Elegir ---
        function pulsoRetosElegir(i) {
            const r = pulsoRetosList[i];
            if (!r || !r.id) return;
            const card = document.querySelectorAll('.pulso-reto-card')[i];
            pulsoRetosEnviarElegir({ reto: String(r.id) }, card ? card.querySelector('.pulso-reto-cta') : null);
        }

        function pulsoRetosElegirPropio() {
            const ta = document.getElementById('pulso-retos-own-text');
            if (!ta || pulsoRetosBusy) return;
            const text = ta.value.trim();
            if (text.length < PULSO_RETO_PROPIO_MIN) {
                pulsoRetosBanner('Describe tu reto con al menos ' + PULSO_RETO_PROPIO_MIN + ' caracteres.', 'warn');
                ta.focus();
                return;
            }
            if (text.length > PULSO_RETO_PROPIO_MAX) {
                pulsoRetosBanner('Tu reto es demasiado largo (máximo ' + PULSO_RETO_PROPIO_MAX + ' caracteres).', 'warn');
                return;
            }
            pulsoRetosEnviarElegir({ propio: text }, document.getElementById('pulso-retos-own-btn'));
        }

        // elegir devuelve el resultado PLANO: {codigo, enlace, enlace_curso, titulo}.
        function pulsoRetosEnviarElegir(fields, labelEl) {
            if (pulsoRetosBusy) return;
            pulsoRetosSetBusy(true);
            const token = pulsoAmpToken;
            const label = labelEl ? labelEl.textContent : '';
            if (labelEl) labelEl.textContent = 'Creando tu reto…';
            pulsoRetosBanner('');
            pulsoRetosRetryFn = function() { pulsoRetosEnviarElegir(fields, labelEl); };

            pulsoRetosCall('elegir', Object.assign({ propuesta: String(pulsoRetosPropuestaId) }, fields)).then(function(d) {
                if (token !== pulsoAmpToken) return;
                if (!d.ok || !pulsoAmpIsHttps(d.enlace)) {
                    pulsoRetosSetBusy(false);
                    if (labelEl) labelEl.textContent = label;
                    pulsoRetosHandleError(d.ok ? { error: 'respuesta-inesperada', mensaje: 'No hemos podido obtener el enlace del reto. Inténtalo de nuevo.' } : d);
                    return;
                }
                pulsoRetosSetBusy(false);
                pulsoRetosRenderDone(d, token);
            });
        }

        // --- El reto elegido ---
        function pulsoRetosRenderDone(d, token) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-retos-done">'
                + '<div class="pulso-retos-done-head">¡Tu reto está listo!</div>'
                + '<div class="pulso-reto-title" id="pulso-retos-done-title">' + escapeHtmlText(d.titulo || 'Reto') + '</div>'
                + '<div id="pulso-retos-done-stats" class="pulso-retos-note"></div>'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<a class="pulso-create-submit pulso-retos-open" href="' + pulsoEscapeAttr(d.enlace) + '" target="_blank" rel="noopener noreferrer">Abrir reto</a>'
                + '<div class="pulso-retos-note">Se abre en una pestaña nueva. Si lo abres enseguida verás "Creando tu reto": tarda menos de un minuto.</div>'
                + (pulsoAmpIsHttps(d.enlace_curso)
                    ? '<a class="pulso-create-secondary" href="' + pulsoEscapeAttr(d.enlace_curso) + '" target="_blank" rel="noopener noreferrer">Ver retos del curso</a>'
                    : '')
                + '<button type="button" class="pulso-create-secondary" onclick="openCreatePanel(\'retos\')">Crear otro reto</button>'
                + '</div><div id="pulso-create-gallery"></div>';
            loadCreateGallery();

            // Sin bloquear nada: a los ~45 s, y luego cada 20 s mientras no esté listo, hasta 3 min en total.
            const codigo = String(d.codigo || '');
            if (codigo) {
                const started = Date.now();
                pulsoRetosRefreshTimer = setTimeout(function() { pulsoRetosRefrescar(token, codigo, started); }, PULSO_RETOS_REFRESH_MS);
            }
        }

        function pulsoRetosRefrescar(token, codigo, started) {
            pulsoRetosRefreshTimer = null;
            if (token !== pulsoAmpToken) return;
            pulsoRetosCall('refrescar', { codigo: codigo }).then(function(d) {
                if (token !== pulsoAmpToken || !d.ok) return; // es un extra: si falla, no se dice nada
                // Aún en cola/trabajando: otra vuelta a los 20 s si cabe en los 3 min totales.
                if (d.estado !== 'listo' && d.estado !== 'fallado' && d.estado !== 'desconocido'
                        && Date.now() - started + PULSO_RETOS_REFRESH_RETRY_MS <= PULSO_RETOS_REFRESH_MAX_MS) {
                    pulsoRetosRefreshTimer = setTimeout(function() { pulsoRetosRefrescar(token, codigo, started); }, PULSO_RETOS_REFRESH_RETRY_MS);
                    return;
                }
                const title = document.getElementById('pulso-retos-done-title');
                if (title && d.titulo) title.textContent = d.titulo;
                // intentos/nota solo llegan si quien pregunta tiene viewanalytics: nunca al alumno.
                const stats = document.getElementById('pulso-retos-done-stats');
                if (stats && typeof d.intentos === 'number') {
                    const n = d.intentos;
                    stats.textContent = n + (n === 1 ? ' intento' : ' intentos')
                        + (d.ultimaPuntuacion === null || d.ultimaPuntuacion === undefined ? ' · aún sin nota' : ' · última nota ' + d.ultimaPuntuacion);
                }
                if (d.estado === 'fallado') {
                    pulsoRetosBanner('No se ha podido crear este reto' + (d.motivo ? ': ' + d.motivo : '.'), 'danger');
                }
            });
        }

        // «Ver todos los retos del curso»: la lista vive en Épica, no se pinta aquí.
        // Se abre una pestaña EN el clic (si no, el navegador bloquea el pop-up tras el
        // fetch) y se le pone el enlace al llegar; sin permiso, se ofrece un enlace.
        function pulsoRetosVerCurso() {
            if (pulsoRetosCourseBusy || pulsoRetosBusy) return;
            pulsoRetosCourseBusy = true;
            const token = pulsoAmpToken;
            pulsoRetosRetryFn = pulsoRetosVerCurso;
            pulsoRetosBanner('');
            const win = window.open('', '_blank');
            if (win) win.opener = null; // la página de Épica no debe poder tocar la pestaña de Moodle

            pulsoRetosCall('curso').then(function(d) {
                if (token !== pulsoAmpToken) { if (win) win.close(); return; }
                pulsoRetosCourseBusy = false;
                if (d.ok && pulsoAmpIsHttps(d.enlace)) {
                    if (win) {
                        win.location.href = d.enlace;
                    } else {
                        const el = document.getElementById('pulso-retos-msg');
                        if (el) el.innerHTML = '<a class="pulso-create-secondary" href="' + pulsoEscapeAttr(d.enlace)
                            + '" target="_blank" rel="noopener noreferrer">Abrir los retos del curso</a>';
                    }
                    return;
                }
                if (win) win.close();
                if (d.ok) {
                    pulsoRetosBanner('Todavía no hay una página de retos para este curso.', 'warn');
                } else {
                    pulsoRetosHandleError(d);
                }
            });
        }

        // ---- Estado del encargo (paso 4): sondeo del panel, nunca de Epica ----
        // El navegador solo habla con api_create_status.php; quien sondea a
        // Epica de verdad es la tarea adhoc (classes/epica_client.php). Una
        // lámina tarda ~150s con concurrencia 2 en Epica, así que con una
        // clase entera encargando a la vez el último puede esperar casi una
        // hora: nada de spinner bloqueante, solo sondeo de fondo con la misma
        // cortesía de 30 minutos que aplica la tarea (CLAUDE.md).
        let pulsoCreatePollTimer = null;
        let pulsoCreatePollStart = 0;
        const PULSO_CREATE_POLL_MS = 7000;
        const PULSO_CREATE_POLL_WINDOW_MS = 30 * 60 * 1000;

        function stopCreatePolling() {
            if (pulsoCreatePollTimer) {
                clearTimeout(pulsoCreatePollTimer);
                pulsoCreatePollTimer = null;
            }
            pulsoRetosReset(); // sondeo y refresco de Retos, antidoble clic
        }

        function startCreatePolling(encargoid) {
            stopCreatePolling();
            pulsoCreatePollStart = Date.now();
            pollCreateStatusOnce(encargoid);
        }

        function pollCreateStatusOnce(encargoid) {
            // Mismo patron que Retos: si el usuario pulsa «Volver», abre otra
            // herramienta o cierra el panel mientras la peticion esta en vuelo,
            // pulsoAmpToken cambia y la respuesta tardia no pinta ni reprograma.
            const token = pulsoAmpToken;
            const params = new URLSearchParams();
            params.set('courseid', window.courseid);
            params.set('encargoid', encargoid);
            params.set('sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '');

            fetch(window.apiCreateStatusUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (token !== pulsoAmpToken) return; // el usuario ya salió de esta pantalla
                    if (!data.success) {
                        stopCreatePolling();
                        renderCreateNotice(data.message || 'No se ha podido comprobar el estado del encargo.');
                        return;
                    }
                    renderCreateStatus(data.encargo);
                    if (!data.encargo.terminal) {
                        scheduleNextCreatePoll(encargoid);
                    } else {
                        // Estado terminal: la galeria se refresca UNA vez (hay una creacion nueva).
                        loadCreateGallery();
                    }
                })
                .catch(function() {
                    if (token !== pulsoAmpToken) return;
                    // Fallo de red puntual: no dar el encargo por perdido,
                    // seguir intentando mientras quede ventana.
                    scheduleNextCreatePoll(encargoid);
                });
        }

        function scheduleNextCreatePoll(encargoid) {
            if (Date.now() - pulsoCreatePollStart >= PULSO_CREATE_POLL_WINDOW_MS) {
                appendCreateStatusNotice(
                    'Esto está tardando más de lo normal. Hemos dejado de comprobarlo aquí automáticamente: '
                    + 'te avisaremos en cuanto esté ' + (pulsoCreateTool === 'gamificacion' ? 'listo' : 'lista') + '.', 'warn'
                );
                return;
            }
            pulsoCreatePollTimer = setTimeout(function() { pollCreateStatusOnce(encargoid); }, PULSO_CREATE_POLL_MS);
        }

        function appendCreateStatusNotice(text, kind) {
            const container = document.querySelector('.pulso-create-status');
            if (!container) return;
            const div = document.createElement('div');
            div.className = 'pulso-create-notice-inline' + (kind ? ' ' + kind : '');
            div.textContent = text;
            container.appendChild(div);
        }

        function pulsoCreateStatusLabel(status, tool) {
            // Mismo criterio de género que notify_completion(): juego = masculino.
            const esjuego = tool === 'gamificacion';
            const labels = {
                pendiente: 'En preparación',
                encolado: 'En cola',
                trabajando: 'Generando',
                listo: esjuego ? 'Listo' : 'Lista',
                fallado: 'No se pudo generar',
                desconocido: 'No se pudo generar',
                ensayo: 'Modo de ensayo'
            };
            return labels[status] || status;
        }

        function pulsoCreateStatusPillClass(status) {
            if (status === 'listo') return 'success';
            if (status === 'fallado' || status === 'desconocido') return 'danger';
            if (status === 'ensayo') return 'warning';
            return 'neutral';
        }

        // Dos motivos que NO pueden dar el mismo mensaje: "cuota-agotada" es
        // el cupo propio del usuario ("vuelve a intentarlo en un rato");
        // "cuota-del-centro" es el cupo diario de TODO el centro, y esta
        // persona no ha gastado nada — decirle "has pedido varias seguidas"
        // la manda a buscar un error suyo que no existe. "material-ilegible"
        // es fallo nuestro, nunca del usuario.
        function pulsoCreateFailureMessage(encargo) {
            const motivo = String(encargo.motivo || '');
            const esjuego = encargo.tool === 'gamificacion';
            // "desconocido": Épica ya no reconoce el trabajo (p. ej. reinicio
            // del servicio a mitad de generación). El encargo SÍ cuenta en
            // nuestros topes, así que no se promete lo contrario.
            if (encargo.status === 'desconocido') {
                return (esjuego ? 'Este juego' : 'Esta infografía')
                    + ' se ha perdido en el servicio de generación. No es un error tuyo: crea '
                    + (esjuego ? 'uno nuevo.' : 'una nueva.');
            }
            if (/cuota-agotada/i.test(motivo)) {
                return 'Has pedido varias creaciones seguidas y se ha agotado tu cupo de generación. '
                    + 'Vuelve a intentarlo en unos minutos.';
            }
            if (/cuota-del-centro/i.test(motivo)) {
                return 'El centro ha alcanzado su límite de generaciones de hoy. No es nada que hayas hecho tú: '
                    + 'vuelve a intentarlo más tarde.';
            }
            if (/material-ilegible/i.test(motivo)) {
                return 'Hubo un problema para leer el material de este recurso. No es culpa tuya: lo estamos revisando.';
            }
            // Nunca se enseña el texto de relleno de marcar_fallo()
            // ("Sin motivo especificado.") ni un motivo vacío.
            if (!motivo.trim() || /^sin motivo especificado\.?$/i.test(motivo.trim())) {
                return 'No se ha podido generar. Prueba a crear un encargo nuevo.';
            }
            return 'No se ha podido generar ' + (esjuego ? 'el juego' : 'la infografía') + ': ' + motivo;
        }

        function renderCreateStatus(encargo) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;

            const esjuego = encargo.tool === 'gamificacion';
            pulsoCreateSetTool(esjuego ? 'gamificacion' : 'infografia');

            const pillClass = pulsoCreateStatusPillClass(encargo.status);
            const pillLabel = pulsoCreateStatusLabel(encargo.status, encargo.tool);

            // Estimación de juegos (carta 6 C3): posicion × 35s en cola, y
            // "casi listo" trabajando en vez del tiempo genérico de lámina.
            // Para infografías se mantiene el texto de siempre.
            let progressLine = '';
            if (encargo.status === 'encolado') {
                if (esjuego && encargo.posicion) {
                    const estSeconds = encargo.posicion * 35;
                    const estLabel = estSeconds < 60 ? (estSeconds + ' s') : (Math.round(estSeconds / 60) + ' min');
                    progressLine = 'Tienes ' + encargo.posicion + ' por delante · unos ' + estLabel;
                } else {
                    progressLine = encargo.posicion
                        ? ('Posición en cola: ' + encargo.posicion)
                        : 'Esperando turno en la cola de Épica.';
                }
            } else if (encargo.status === 'trabajando') {
                progressLine = esjuego
                    ? 'Casi listo, suele tardar alrededor de un minuto.'
                    : 'Generando la lámina… puede tardar un par de minutos.';
            } else if (encargo.status === 'pendiente') {
                progressLine = esjuego ? 'Generando tu juego…' : 'Generando tu infografía…';
            }

            let html = '<div class="pulso-create-status">'
                + '<div class="pulso-create-status-head">'
                + '<span class="pulso-status-pill ' + pillClass + '">' + escapeHtmlText(pillLabel) + '</span>'
                + '</div>';

            if (progressLine) {
                html += '<p class="pulso-create-hint">' + escapeHtmlText(progressLine) + '</p>';
            }

            // Pasados 2 minutos sin terminar, deja claro que no hace falta
            // seguir mirando: el aviso de mensajería ya lo manda
            // notify_completion() en servidor cuando el encargo termine.
            if (!encargo.terminal && (Date.now() - pulsoCreatePollStart) >= 120000) {
                html += '<p class="pulso-create-hint">Te avisaremos cuando esté ' + (esjuego ? 'listo' : 'lista') + '; puedes cerrar el chat.</p>';
            }

            // "motivo" en un estado NO terminal es el último error de red del
            // reintento (el servidor ya lo filtra a solo viewanalytics — ver
            // api_create_status.php). Es un aviso técnico de que se está
            // reintentando, no un fallo: el encargo sigue vivo.
            if (!encargo.terminal && encargo.motivo) {
                html += '<div class="pulso-create-notice-inline warn">Reintentando tras un error: '
                    + escapeHtmlText(encargo.motivo) + '</div>';
            }

            if (encargo.status === 'listo' && (encargo.imageurl || encargo.playurl)) {
                if (encargo.mock) {
                    html += '<div class="pulso-create-notice-inline warn">'
                        + (esjuego ? 'Este juego es de prueba' : 'Esta lámina es de prueba') + ', no una generación real.</div>';
                }
                if (encargo.verificado === false) {
                    html += '<div class="pulso-create-notice-inline warn">No hemos podido confirmar que '
                        + (esjuego ? 'este juego' : 'esta infografía') + ' trate de '
                        + (encargo.tema ? escapeHtmlText(encargo.tema) : 'el tema pedido') + '.</div>';
                }
                const avisosTxt = Array.isArray(encargo.avisos)
                    ? encargo.avisos.filter(function(a) { return typeof a === 'string' && a.trim() !== ''; })
                    : [];
                if (avisosTxt.length) {
                    html += '<div class="pulso-create-notice-inline warn">'
                        + avisosTxt.map(function(a) { return escapeHtmlText(a); }).join('; ') + '</div>';
                }

                if (esjuego) {
                    // El juego se juega FUERA del widget (juego.php), nunca
                    // en un iframe dentro del chat: es estrecho, y el juego
                    // corre en un marco aislado con su propia CSP/sandbox.
                    if (encargo.titulo) html += '<div class="pulso-create-image-title">' + escapeHtmlText(encargo.titulo) + '</div>';
                    if (encargo.tema) html += '<div class="pulso-create-image-tema">' + escapeHtmlText(encargo.tema) + '</div>';
                    html += '<div class="pulso-create-image-actions">'
                        + '<a href="' + pulsoEscapeAttr(encargo.playurl) + '" target="_blank" rel="noopener">Jugar</a>'
                        + '</div>';
                } else {
                    html += '<img class="pulso-create-image" src="' + pulsoEscapeAttr(encargo.imageurl) + '" alt="'
                        + pulsoEscapeAttr(encargo.titulo || 'Infografía generada') + '">';
                    if (encargo.titulo) html += '<div class="pulso-create-image-title">' + escapeHtmlText(encargo.titulo) + '</div>';
                    if (encargo.tema) html += '<div class="pulso-create-image-tema">' + escapeHtmlText(encargo.tema) + '</div>';
                    html += '<div class="pulso-create-image-actions">'
                        + '<a href="' + pulsoEscapeAttr(encargo.imageurl) + '" target="_blank" rel="noopener">Abrir a tamaño completo</a>'
                        + '<a href="' + pulsoEscapeAttr(encargo.downloadurl) + '">Descargar</a>'
                        + '</div>';
                }
            } else if (encargo.status === 'fallado' || encargo.status === 'desconocido') {
                html += '<div class="pulso-create-notice-inline danger">' + escapeHtmlText(pulsoCreateFailureMessage(encargo)) + '</div>'
                    + '<button type="button" class="pulso-create-submit" onclick="openCreatePanel(\'' + pulsoCreateTool + '\')">Crear un encargo nuevo</button>';
            } else if (encargo.status === 'ensayo') {
                html += '<div class="pulso-create-notice-inline warn">Modo de ensayo activo: el sobre se construyó pero no se envió a Épica.</div>';
                if (encargo.sobre) {
                    html += '<details class="pulso-create-sobre"><summary>Ver sobre construido</summary>'
                        + '<pre>' + escapeHtmlText(JSON.stringify(encargo.sobre, null, 2)) + '</pre></details>';
                }
            }

            html += '<button type="button" class="pulso-create-back-link" onclick="openCreatePanel(\'' + pulsoCreateTool + '\')">← Volver al formulario</button>'
                + '</div><div id="pulso-create-gallery"></div>';

            body.innerHTML = html;
            // La galeria NO se pide en cada sondeo (2 peticiones extra cada 7 s):
            // se repinta desde la ultima carga y solo se pide si nunca se cargo.
            // En estado terminal la pide pollCreateStatusOnce justo despues.
            if (pulsoGalleryCache) {
                renderCreateGallery(pulsoGalleryCache.encargos, pulsoGalleryCache.retos);
            } else if (!encargo.terminal && !pulsoGalleryLoading) {
                loadCreateGallery();
            }
        }

        let pulsoGalleryCache = null;   // {encargos, retos} de la ultima carga correcta
        let pulsoGalleryLoading = false;
        let pulsoGallerySeq = 0;        // solo vale la ultima peticion lanzada

        // La galería es un extra: si falla, no debe romper el resto del panel.
        function loadCreateGallery() {
            const seq = ++pulsoGallerySeq;
            pulsoGalleryLoading = true;
            const params = new URLSearchParams();
            params.set('courseid', window.courseid);
            params.set('sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '');

            // Infografías/juegos (api_create_status.php) y retos (api_retos.php
            // mis_retos, que no llama a Épica) se piden a la vez y se mezclan.
            // Cada fuente falla por separado: si una cae, se pinta la otra.
            const encargosP = fetch(window.apiCreateStatusUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) { return data.success ? (data.encargos || []) : null; })
                .catch(function() { return null; });
            const retosP = pulsoRetosCall('mis_retos')
                .then(function(d) { return d.ok && Array.isArray(d.retos) ? d.retos : []; });

            Promise.all([encargosP, retosP]).then(function(res) {
                if (seq !== pulsoGallerySeq) return;
                pulsoGalleryLoading = false;
                if (res[0] === null && !res[1].length) return;
                pulsoGalleryCache = { encargos: res[0] || [], retos: res[1] };
                renderCreateGallery(pulsoGalleryCache.encargos, pulsoGalleryCache.retos);
            }).catch(function() {
                if (seq === pulsoGallerySeq) pulsoGalleryLoading = false;
                /* la galería es un extra, no bloquea el panel */
            });
        }

        // Galería conjunta (paso 3): infografía y juego mezclados, más
        // recientes primero. Una infografía se ENSEÑA (miniatura real); un
        // juego se JUEGA, así que su tarjeta no tiene imagen (pluginfile.php
        // sigue bloqueando su filearea a propósito) — icono + título de
        // respaldo + etiqueta. Ninguna de las dos usa el cian como texto.
        // Retos (v1.27): tarjetas-enlace a Épica (pestaña nueva, sin iframe),
        // mezcladas por fecha con las demás; etiqueta «Reto» en slate.
        function renderCreateGallery(encargos, retos) {
            const container = document.getElementById('pulso-create-gallery');
            if (!container) return;
            retos = (retos || []).filter(function(r) { return pulsoAmpIsHttps(r.enlace); }).slice(0, 8);
            if (!encargos.length && !retos.length) {
                container.innerHTML = '<p class="pulso-create-hint">Aún no has creado nada en este curso.</p>' + pulsoHistorialFooter();
                return;
            }

            const items = encargos.map(function(e) { return { t: e.timecreated, e: e }; })
                .concat(retos.map(function(r) { return { t: r.creado, r: r }; }))
                .sort(function(a, b) { return b.t - a.t; });

            // El servidor ya filtra a status='listo' con fichero (ver
            // api_create_status.php), así que aquí no hay placeholder de
            // estado: todas las tarjetas son creaciones terminadas.
            let html = '<div class="pulso-create-gallery-title">Tus últimas creaciones</div><div class="pulso-create-gallery-grid">';
            items.forEach(function(it) {
                if (it.r) {
                    html += '<a class="pulso-create-gallery-item" href="' + pulsoEscapeAttr(it.r.enlace) + '" target="_blank" rel="noopener noreferrer">'
                        + '<span class="pulso-create-gallery-icon" aria-hidden="true">' + pulsoRetosIconSvg('target') + '</span>'
                        + '<span class="pulso-create-gallery-item-title">' + escapeHtmlText(it.r.titulo || 'Reto') + '</span>'
                        + '<span class="pulso-create-gallery-tag">Reto</span>'
                        + '<span class="pulso-create-gallery-date">' + escapeHtmlText(new Date(it.r.creado * 1000).toLocaleDateString()) + '</span>'
                        + '</a>';
                    return;
                }
                const e = it.e;
                const dateLabel = new Date(e.timecreated * 1000).toLocaleDateString();
                const esjuego = e.tool === 'gamificacion';
                const tag = esjuego ? 'Juego' : 'Infografía';
                // "tool" entra en un onclick: solo uno de los dos valores conocidos, y el id como entero.
                const toolSeguro = esjuego ? 'gamificacion' : 'infografia';
                html += '<button type="button" class="pulso-create-gallery-item" onclick="openCreateGalleryItem(' + (parseInt(e.id, 10) || 0) + ", '" + toolSeguro + "')\">";
                if (esjuego) {
                    html += '<span class="pulso-create-gallery-icon" aria-hidden="true">'
                        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                        + '<rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="12" x2="10" y2="12"/><line x1="8" y1="10" x2="8" y2="14"/>'
                        + '<line x1="15" y1="13" x2="15.01" y2="13"/><line x1="18" y1="11" x2="18.01" y2="11"/></svg>'
                        + '</span>'
                        + '<span class="pulso-create-gallery-item-title">' + escapeHtmlText(e.titulo || 'Juego') + '</span>';
                } else {
                    html += '<img src="' + pulsoEscapeAttr(e.imageurl) + '" alt="">'
                        + '<span class="pulso-create-gallery-item-title">' + escapeHtmlText(e.titulo || 'Infografía') + '</span>';
                }
                html += '<span class="pulso-create-gallery-tag">' + tag + '</span>'
                    + '<span class="pulso-create-gallery-date">' + escapeHtmlText(dateLabel) + '</span>'
                    + '</button>';
            });
            html += '</div>' + pulsoHistorialFooter();
            container.innerHTML = html;
        }

        // «Mi historial» (carta 10): solo alumnado (la UI no es control de acceso;
        // epica_historial.php ya lo exige). El token nunca pasa por este JS: se
        // crea un <form> POST con courseid + sesskey hacia nuestro endpoint, en
        // pestaña nueva y en el propio clic (sin bloqueo de ventanas emergentes).
        function pulsoHistorialFooter() {
            if (window.pulsoIsTeacher !== false) return '';
            return '<p class="pulso-create-hint"><button type="button" class="pulso-historial-link" onclick="pulsoAbrirHistorial()">Ver todo mi historial en Épica ↗</button><br>'
                + 'Se abre en una pestaña nueva. Solo aparece lo creado desde el 5 de octubre de 2026.</p>';
        }

        function pulsoAbrirHistorial() {
            if (window.pulsoIsTeacher !== false || !window.apiHistorialUrl) return;
            const form = document.createElement('form');
            form.method = 'post';
            form.action = window.apiHistorialUrl;
            form.target = '_blank';
            form.style.display = 'none';
            [['courseid', window.courseid], ['sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '']].forEach(function(p) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = p[0];
                input.value = String(p[1]);
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function openCreateGalleryItem(encargoid, tool) {
            const messagesDiv = document.getElementById('pulso-messages');
            const body = document.getElementById('pulso-create-body');
            if (!messagesDiv || !body) return;
            pulsoCreateSetTool(tool);
            messagesDiv.classList.add('pulso-showing-create');
            body.innerHTML = '<p class="pulso-create-hint">Cargando…</p>';
            // Si sigue en curso, se sondea igual que un encargo recién creado.
            startCreatePolling(encargoid);
        }

        function escapeHtmlText(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }
        
        function updateCharCount() {
            const input = document.getElementById('pulso-input');
            const count = input.value.length;
            document.getElementById('pulso-char-count').textContent = count;
            
            if (count > 500) {
                input.value = input.value.substring(0, 500);
                document.getElementById('pulso-char-count').textContent = '500';
            }
        }

        // ========== DICTADO POR VOZ (Web Speech API, transcripción en cliente) ==========

        let pulsoRecognition = null;   // instancia de SpeechRecognition (una sola)
        let pulsoMicRecording = false; // ¿grabando ahora mismo?
        let pulsoMicBase = '';         // texto ya escrito antes de empezar a dictar

        function initPulsoMic() {
            const btn = document.getElementById('pulso-mic-btn');
            if (!btn) return;

            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) {
                // Navegador sin soporte (p. ej. Firefox): dejar el botón oculto.
                return;
            }

            pulsoRecognition = new SR();
            pulsoRecognition.lang = 'es-ES';
            pulsoRecognition.interimResults = true;
            pulsoRecognition.continuous = false;
            pulsoRecognition.maxAlternatives = 1;

            pulsoRecognition.onresult = function(event) {
                const input = document.getElementById('pulso-input');
                if (!input) return;
                let transcript = '';
                for (let i = 0; i < event.results.length; i++) {
                    transcript += event.results[i][0].transcript;
                }
                let combined = (pulsoMicBase + transcript).slice(0, 500);
                input.value = combined;
                updateCharCount();
            };

            pulsoRecognition.onerror = function(event) {
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    const lang = navigator.language.startsWith('en') ? 'en' : 'es';
                    alert(lang === 'en'
                        ? 'Microphone access is blocked. Allow it in your browser to dictate.'
                        : 'El micrófono está bloqueado. Permite el acceso en tu navegador para dictar.');
                }
                // 'no-speech' / 'aborted' se ignoran silenciosamente.
                stopPulsoMic();
            };

            pulsoRecognition.onend = function() {
                stopPulsoMic();
            };

            // Soportado: mostrar el botón.
            btn.style.display = '';
        }

        function toggleMic() {
            if (!pulsoRecognition) return;
            if (pulsoMicRecording) {
                pulsoRecognition.stop();
                return;
            }
            const input = document.getElementById('pulso-input');
            // Conservar lo ya escrito y añadir un espacio de separación si hace falta.
            pulsoMicBase = input && input.value ? (input.value.replace(/\s+$/, '') + ' ') : '';
            try {
                pulsoRecognition.start();
            } catch (err) {
                // start() lanza si ya estaba activo; reintentar limpio.
                return;
            }
            pulsoMicRecording = true;
            const btn = document.getElementById('pulso-mic-btn');
            if (btn) {
                btn.classList.add('pulso-mic-recording');
                btn.setAttribute('aria-pressed', 'true');
                btn.setAttribute('aria-label', 'Detener dictado');
            }
            if (input) input.focus();
        }

        function stopPulsoMic() {
            if (!pulsoMicRecording) return;
            pulsoMicRecording = false;
            const btn = document.getElementById('pulso-mic-btn');
            if (btn) {
                btn.classList.remove('pulso-mic-recording');
                btn.setAttribute('aria-pressed', 'false');
                btn.setAttribute('aria-label', 'Dictar pregunta por voz');
            }
        }

        function detectLanguage(text) {
            // Palabras clave en inglés
            const englishKeywords = ['are', 'students', 'grades', 'how', 'what', 'completion', 'quiz', 'assignments', 'progress', 'performance', 'risk', 'going', 'doing', 'activity', 'engagement'];
            
            // Palabras clave en español
            const spanishKeywords = ['estudiantes', 'notas', 'tasa', 'cuál', 'qué', 'riesgo', 'están', 'tareas', 'progreso', 'desempeño', 'actividad', 'completitud', 'compromiso'];
            
            const lowerText = text.toLowerCase();
            
            // Contar coincidencias
            const englishCount = englishKeywords.filter(word => lowerText.includes(word)).length;
            const spanishCount = spanishKeywords.filter(word => lowerText.includes(word)).length;
            
            // Retornar idioma detectado
            return englishCount > spanishCount ? 'en' : 'es';
        }

        function isAnalyticsQuestion(text) {
            const q = (text || '').toLowerCase();
            const analyticsKeywords = [
                'analitica', 'analítica', 'analytics',
                'completitud', 'completion', 'progress', 'progreso',
                'nota', 'notas', 'grade', 'grades', 'calificacion', 'calificación',
                'engagement', 'participacion', 'participación',
                'riesgo', 'at risk', 'abandono',
                'promedio', 'average', 'porcentaje', 'tasa',
                'usuarios', 'estudiantes', 'accesos', 'logs'
            ];

            return analyticsKeywords.some(k => q.includes(k));
        }
        
        // ========== ENVÍO DE MENSAJES (streaming SSE + fallback XHR) ==========

        let pulsoSending = false;
        let streamBubble = null;
        let typingBubble = null;

        function sendMessage(e) {
            e.preventDefault();
            if (pulsoSending) return;
            if (pulsoMicRecording && pulsoRecognition) pulsoRecognition.stop();
            const input = document.getElementById('pulso-input');
            const message = input.value.trim();

            if (!message) {
                const lang = navigator.language.startsWith('en') ? 'en' : 'es';
                const alertMsg = lang === 'en' ? 'Please enter a message' : 'Por favor escribe un mensaje';
                alert(alertMsg);
                return;
            }

            // Agregar mensaje del usuario
            setHomeVisible(false);
            addMessage(message, 'user');
            input.value = '';
            updateCharCount();

            // Mostrar loading
            showLoading(true);

            // Streaming (estilo ChatGPT) cuando el navegador lo soporta;
            // si no, o si el endpoint de streaming falla, XHR clásico.
            if (window.fetch && window.ReadableStream && window.TextDecoder) {
                sendMessageStream(message);
            } else {
                sendMessageXHR(message);
            }
        }

        function buildChatFormData(message) {
            const formData = new FormData();
            formData.append('courseid', window.courseid || 2);
            formData.append('user_query', message);
            formData.append('conversation_history', JSON.stringify(window.conversationHistory || []));
            // Obligatorio: los endpoints validan con require_sesskey().
            formData.append('sesskey', window.pulsoSesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            return formData;
        }

        // El historial guarda TEXTO, nunca el JSON de la respuesta: un JSON
        // recortado en un turno de asistente hacía que el modelo continuase la
        // respuesta anterior en vez de contestar la pregunta nueva. El servidor
        // vuelve a limpiarlo igualmente (chat_pipeline::history_digest()), esto
        // evita generar historiales sucios nuevos.
        function pulsoHistoryDigest(answer) {
            const raw = String(answer || '').trim();
            if (!raw) return '';
            if (raw[0] !== '{' && raw[0] !== '[') {
                return pulsoTrimDigest(raw);
            }
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                // JSON inválido o cortado: quedarse con el texto plano que se vea.
                return pulsoTrimDigest(raw.replace(/[{}\[\]"]/g, ' '));
            }
            const parts = [];
            // 'next_step' al FINAL y en la misma posición que en el digest de PHP
            // (chat_pipeline::history_digest): los dos tienen que producir exactamente
            // lo mismo. Va incluido porque la regla de INICIATIVA prohíbe repetir el
            // ofrecimiento dos turnos seguidos, y el modelo necesita ver el anterior.
            ['title', 'summary', 'content', 'next_step'].forEach(function(key) {
                if (typeof data[key] === 'string' && data[key].trim()) {
                    parts.push(data[key].trim());
                }
            });
            // Las filas de 'data' NO entran en el digest: en una respuesta de
            // analítica son una tabla de métricas con el mismo aspecto que el
            // formato de salida que se le pide al modelo, y el modelo la
            // continuaba en vez de responder a la pregunta nueva. Para resolver
            // referencias posteriores ("ese pdf") solo hacen falta título,
            // resumen y las anclas de línea que van en 'content'.
            return pulsoTrimDigest(parts.join('\n'));
        }

        // Recorta conservando los SALTOS DE LÍNEA: las anclas "Recurso:" /
        // "Seccion:" del historial se localizan con anclas de línea, así que
        // aplastarlos deja al history-hint ciego (y hace que el "título" pase a
        // ser toda la frase).
        function pulsoTrimDigest(text) {
            return String(text || '')
                .replace(/[^\S\n]+/g, ' ')
                .replace(/\n{2,}/g, '\n')
                .trim()
                .slice(0, 500);
        }

        // Mensaje al usuario cuando una respuesta no se puede usar. Lo comparten el
        // evento 'error' del SSE y la rama de fallo de handleChatResponse, que es la
        // que en el QA de 1.15.2 mostró "Error: No success flag" — un literal de
        // desarrollador que no le dice nada a nadie.
        //
        // El texto se elige por `error_code` (estable, lo manda el servidor), NUNCA por
        // `message`: el mensaje del servidor es para quien consuma la API y ya no se
        // pinta nunca — antes se añadía como «Detalle: …» y llegaban a la pantalla
        // textos crudos de Anthropic/OpenAI. Solo se añade `detail` (causa genérica
        // escrita por nosotros), y el servidor lo manda únicamente a profesorado/admin.
        const PULSO_ERROR_TEXTS = {
            es: {
                busy: 'Pulse está muy ocupado ahora mismo. Prueba en un minuto.',
                config: 'Pulse no está disponible ahora mismo. Avisa a tu profesorado.',
                network: 'Se ha cortado la conexión. Vuelve a intentarlo.',
                session: 'Tu sesión ha caducado. Recarga la página.',
                access: 'No tienes acceso a Pulse en este curso.',
                disabled: 'Pulse está desactivado en este curso.',
                bad_request: 'No se ha podido entender la petición. Escribe otra pregunta e inténtalo de nuevo.',
                empty: 'Pulse no ha podido responder esta vez. Vuelve a intentarlo.',
                refusal: 'Pulse no puede responder a esta petición. Prueba a reformular la pregunta.',
                encoding: 'No se ha podido preparar la petición. Empieza una conversación nueva («Nueva conversación») e inténtalo de nuevo.',
                unknown: 'Algo ha fallado. Vuelve a intentarlo en un momento; si sigue igual, pulsa «Nueva conversación».'
            },
            en: {
                busy: 'Pulse is very busy right now. Try again in a minute.',
                config: 'Pulse is not available right now. Let your teacher know.',
                network: 'The connection was cut. Please try again.',
                session: 'Your session has expired. Reload the page.',
                access: 'You do not have access to Pulse in this course.',
                disabled: 'Pulse is disabled in this course.',
                bad_request: 'That request is not valid. Write another question and try again.',
                empty: 'Pulse could not answer this time. Please try again.',
                refusal: 'Pulse cannot answer this request. Try rephrasing your question.',
                encoding: 'The request could not be prepared. Start a new conversation ("Nueva conversación") and try again.',
                unknown: 'Something went wrong. Try again in a moment; if it keeps happening, press "Nueva conversación".'
            }
        };

        function pulsoFailureMessage(payload) {
            const lang = navigator.language.startsWith('en') ? 'en' : 'es';
            const texts = PULSO_ERROR_TEXTS[lang];
            const code = (payload && typeof payload.error_code === 'string') ? payload.error_code : '';
            let text = '⚠️ ' + (Object.prototype.hasOwnProperty.call(texts, code) ? texts[code] : texts.unknown);
            const detail = (payload && typeof payload.detail === 'string') ? payload.detail.trim() : '';
            if (detail !== '') {
                text += ' ' + detail;
            }
            return text;
        }

        // Procesamiento compartido de la respuesta completa (stream final / XHR).
        function handleChatResponse(message, response) {
            if (response.success && response.answer) {
                const showAnalysisSections = isAnalyticsQuestion(message);
                // Diagnóstico opcional (window.pulsoDebug = true en la consola): el JSON
                // ya limpio y QUÉ secciones se van a pintar. Sirve para localizar en qué
                // campo viene una frase que no aparece en pantalla: 'content' solo se
                // pinta si type === 'text', e 'insights'/'recommendations' solo si la
                // pregunta parece de analítica (isAnalyticsQuestion), así que un texto
                // en el campo equivocado se descarta en silencio.
                if (window.pulsoDebug) {
                    let parsed = null;
                    try { parsed = JSON.parse(response.answer); } catch (e) { parsed = null; }
                    console.log('[pulso] JSON final:', response.answer);
                    console.log('[pulso] campos:', parsed ? Object.keys(parsed) : '(no es JSON)',
                        '| type:', parsed && parsed.type,
                        '| showAnalysisSections:', showAnalysisSections,
                        '| se pinta content:', !!(parsed && parsed.content && parsed.type === 'text'),
                        '| se pintan insights/recomendaciones:', showAnalysisSections);
                }
                const formattedAnswer = formatAIResponse(response.answer, showAnalysisSections);
                addMessage(formattedAnswer, 'ai', true);

                // Mostrar preguntas sugeridas (T2.4.12) — en streaming pueden
                // llegar después como evento 'followups'.
                if (response.followup_questions && response.followup_questions.length > 0) {
                    showFollowupQuestions(response.followup_questions);
                }

                // T2.5.3: Guardar en historial para conversación futura
                if (!window.conversationHistory) {
                    window.conversationHistory = [];
                }
                window.conversationHistory.push({role: 'user', content: message});
                window.conversationHistory.push({role: 'assistant', content: pulsoHistoryDigest(response.answer)});
                if (window.conversationHistory.length > 20) {
                    window.conversationHistory = window.conversationHistory.slice(-20);
                }
                try {
                    sessionStorage.setItem('pulso_history_' + window.courseid, JSON.stringify(window.conversationHistory));
                } catch(e) {
                    console.warn('⚠️ No se pudo guardar historial en sessionStorage');
                }
            } else {
                // Este else colapsa TRES situaciones distintas y las tres acababan
                // mostrando texto de desarrollador al usuario (visto en el QA de
                // 1.15.2: "Error: No success flag", que no dice nada):
                //  - success:false con 'message' = "Error: <texto crudo de la
                //    excepción>" (api_chat.php / api_chat_stream.php) → se pintaba
                //    doblemente prefijado, "Error: Error: ...".
                //  - success:true pero 'answer' vacío → el 'message' del payload es
                //    "Query procesado exitosamente", que como error no tiene sentido.
                //  - una respuesta sin 'success' ni 'message' → el literal
                //    "No success flag".
                // Ahora al usuario se le dice qué hacer y el detalle técnico se queda
                // en la consola. El detalle del servidor solo se añade cuando es un
                // error de verdad (success:false con mensaje), nunca el "procesado
                // exitosamente" de una respuesta vacía.
                // Este turno NO entra en el historial, y así debe seguir: un turno
                // fallido en el historial se le reenvía al modelo en la pregunta
                // siguiente.
                console.error('❌ Respuesta no utilizable:', response);
                addMessage(pulsoFailureMessage(response), 'ai');
            }
        }

        // ---------- Burbuja de respuesta en vivo ----------

        function ensureStreamBubble() {
            if (streamBubble && streamBubble.isConnected) {
                return streamBubble;
            }
            const messagesDiv = document.getElementById('pulso-messages');
            const messageEl = document.createElement('div');
            messageEl.className = 'pulso-message ai';
            const contentEl = document.createElement('div');
            contentEl.className = 'pulso-message-content';
            const textEl = document.createElement('span');
            textEl.className = 'pulso-stream-text';
            const cursorEl = document.createElement('span');
            cursorEl.className = 'pulso-stream-cursor';
            contentEl.appendChild(textEl);
            contentEl.appendChild(cursorEl);
            messageEl.appendChild(contentEl);
            messagesDiv.appendChild(messageEl);
            streamBubble = messageEl;
            return messageEl;
        }

        function updateStreamBubble(text) {
            if (!text) return;
            const bubble = ensureStreamBubble();
            const textEl = bubble.querySelector('.pulso-stream-text');
            if (textEl) textEl.textContent = text;
            const messagesDiv = document.getElementById('pulso-messages');
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }

        function removeStreamBubble() {
            if (streamBubble && streamBubble.parentNode) {
                streamBubble.parentNode.removeChild(streamBubble);
            }
            streamBubble = null;
        }

        // Extraer texto legible de una respuesta parcial. Las respuestas del
        // modelo son JSON: mientras llegan tokens vamos mostrando los valores
        // de texto (title, summary, párrafos...) en vez del JSON crudo.
        function extractStreamPreview(accum) {
            let s = String(accum || '').replace(/^\s*```[a-z]*\s*/i, '').replace(/\s*```\s*$/, '');
            if (!/^\s*[\[{]/.test(s)) {
                return s; // texto plano (respuestas de documento) → mostrar tal cual
            }
            // Sin 'title': el render final ya no muestra títulos cuando hay
            // cuerpo, así evitamos previsualizar texto que luego desaparece.
            const keys = ['summary', 'paragraph', 'párrafo', 'parrafo', 'text', 'texto', 'content', 'contenido', 'description', 'descripción', 'descripcion'];
            const re = new RegExp('"(' + keys.join('|') + ')"\\s*:\\s*"((?:[^"\\\\]|\\\\.)*)', 'g');
            const parts = [];
            let m;
            while ((m = re.exec(s)) !== null) {
                const v = m[2]
                    .replace(/\\n/g, '\n')
                    .replace(/\\"/g, '"')
                    .replace(/\\\\/g, '\\');
                if (v.trim()) parts.push(v.trim());
            }
            return parts.join('\n\n');
        }

        // ---------- Streaming SSE ----------

        function sendMessageStream(message) {
            pulsoSending = true;
            let accum = '';
            let gotFinal = false;
            let receivedAny = false;

            function handleSseEvent(raw) {
                let eventName = 'message';
                const dataLines = [];
                raw.split('\n').forEach(function(line) {
                    if (line.indexOf('event:') === 0) {
                        eventName = line.slice(6).trim();
                    } else if (line.indexOf('data:') === 0) {
                        dataLines.push(line.slice(5).trim());
                    }
                });
                if (!dataLines.length) return;
                let data;
                try {
                    data = JSON.parse(dataLines.join('\n'));
                } catch (err) {
                    return;
                }
                receivedAny = true;

                if (eventName === 'status') {
                    showLoading(true, data.stage === 'generating' ? 'Generando respuesta...' : 'Analizando datos del curso...');
                } else if (eventName === 'delta') {
                    accum += (data.text || '');
                    const preview = extractStreamPreview(accum);
                    if (preview) {
                        showLoading(false);
                        updateStreamBubble(preview);
                    }
                } else if (eventName === 'final') {
                    gotFinal = true;
                    showLoading(false);
                    removeStreamBubble();
                    handleChatResponse(message, data);
                } else if (eventName === 'followups') {
                    if (data.questions && data.questions.length > 0) {
                        showFollowupQuestions(data.questions);
                    }
                } else if (eventName === 'error') {
                    gotFinal = true;
                    showLoading(false);
                    removeStreamBubble();
                    console.error('❌ Evento error del stream:', data);
                    addMessage(pulsoFailureMessage(data), 'ai');
                }
            }

            fetch(window.streamApiUrl, {
                method: 'POST',
                body: buildChatFormData(message),
                credentials: 'same-origin'
            })
            .then(function(res) {
                const ct = res.headers.get('content-type') || '';
                if (ct.indexOf('text/event-stream') === -1) {
                    // El servidor rechazó la petición ANTES de abrir el stream (sesión
                    // caducada, sesskey, permiso, curso desactivado...) con un JSON
                    // {success:false, error_code}. Se lee con CUALQUIER código HTTP y NO se
                    // reintenta por el endpoint clásico: fallaría igual y duplicaría la
                    // petición. El fallback queda solo para 404/405 y fallos de red.
                    if (res.status === 404 || res.status === 405 || !res.body) {
                        throw new Error('stream-unavailable');
                    }
                    return res.text().then(function(body) {
                        let data = null;
                        try { data = JSON.parse(body); } catch (err) { data = null; }
                        gotFinal = true;
                        showLoading(false);
                        removeStreamBubble();
                        handleChatResponse(message, (data && typeof data === 'object') ? data : {success: false});
                    });
                }
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buf = '';
                function pump() {
                    return reader.read().then(function(r) {
                        if (r.done) return;
                        buf += decoder.decode(r.value, {stream: true});
                        let idx;
                        while ((idx = buf.indexOf('\n\n')) !== -1) {
                            handleSseEvent(buf.slice(0, idx));
                            buf = buf.slice(idx + 2);
                        }
                        return pump();
                    });
                }
                return pump();
            })
            .then(function() {
                pulsoSending = false;
                if (!gotFinal) {
                    removeStreamBubble();
                    if (!receivedAny) {
                        // El servidor no habló SSE → endpoint clásico.
                        sendMessageXHR(message);
                    } else {
                        showLoading(false);
                        addMessage('⚠️ La respuesta se interrumpió. Inténtalo de nuevo.', 'ai');
                    }
                }
            })
            .catch(function(err) {
                pulsoSending = false;
                removeStreamBubble();
                if (!gotFinal && !receivedAny) {
                    console.warn('⚠️ Streaming no disponible, usando endpoint clásico:', err.message);
                    sendMessageXHR(message);
                } else if (!gotFinal) {
                    showLoading(false);
                    addMessage('⚠️ Error de conexión durante el streaming', 'ai');
                }
            });
        }

        // ---------- Fallback XHR clásico (api_chat.php) ----------

        function sendMessageXHR(message) {
            showLoading(true);
            const xhr = new XMLHttpRequest();

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    showLoading(false);
                    console.log('📡 AJAX Status:', xhr.status);

                    // El cuerpo se lee con cualquier código: el servidor contesta 4xx/5xx
                    // con {success:false, error_code} y el texto se elige por ese código.
                    let response = null;
                    try {
                        response = JSON.parse(xhr.responseText);
                    } catch (e) {
                        console.error('❌ Respuesta no JSON (HTTP ' + xhr.status + '):', xhr.responseText);
                    }
                    if (response && typeof response === 'object') {
                        handleChatResponse(message, response);
                    } else {
                        addMessage(pulsoFailureMessage({error_code: xhr.status === 0 ? 'network' : 'unknown'}), 'ai');
                    }
                }
            };

            xhr.open('POST', window.apiUrl, true);
            xhr.send(buildChatFormData(message));
        }
        
        function addMessage(text, sender, isHtml = false) {
            const messagesDiv = document.getElementById('pulso-messages');
            const messageEl = document.createElement('div');
            messageEl.className = 'pulso-message ' + sender;
            
            const contentEl = document.createElement('div');
            contentEl.className = 'pulso-message-content';
            
            if (isHtml) {
                contentEl.innerHTML = text;
            } else {
                contentEl.textContent = text;
            }
            
            messageEl.appendChild(contentEl);
            messagesDiv.appendChild(messageEl);
            
            // Auto scroll
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
        
        // Burbuja "escribiendo" estilo WhatsApp: se muestra mientras el bot
        // piensa y desaparece en cuanto llegan los primeros tokens / la respuesta.
        function showTyping() {
            if (typingBubble && typingBubble.isConnected) return;
            const messagesDiv = document.getElementById('pulso-messages');
            if (!messagesDiv) return;
            const messageEl = document.createElement('div');
            messageEl.className = 'pulso-message ai pulso-typing';
            messageEl.setAttribute('role', 'status');
            messageEl.setAttribute('aria-label', 'Pulse está escribiendo');
            const contentEl = document.createElement('div');
            contentEl.className = 'pulso-message-content';
            const dots = document.createElement('span');
            dots.className = 'pulso-typing-dots';
            dots.setAttribute('aria-hidden', 'true');
            dots.innerHTML = '<span></span><span></span><span></span>';
            contentEl.appendChild(dots);
            messageEl.appendChild(contentEl);
            messagesDiv.appendChild(messageEl);
            typingBubble = messageEl;
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }

        function hideTyping() {
            if (typingBubble && typingBubble.parentNode) {
                typingBubble.parentNode.removeChild(typingBubble);
            }
            typingBubble = null;
        }

        function showLoading(show, label) {
            if (show) {
                showTyping();
            } else {
                hideTyping();
            }
        }
        
        function showFollowupQuestions(questions) {
            if (!questions || questions.length === 0) return;

            // Frontend safety net: descartar sugerencias inválidas como '?'
            const validQuestions = questions.filter(function(q) {
                if (typeof q !== 'string') return false;
                const s = q.trim();
                if (!s) return false;
                if (/^[¿?\s]+$/.test(s)) return false;
                if (s.length < 12) return false;
                return true;
            }).slice(0, 3);

            if (validQuestions.length === 0) return;
            
            const messagesDiv = document.getElementById('pulso-messages');
            const containerEl = document.createElement('div');
            containerEl.className = 'pulso-followup-container';
            
            // Crear chip para cada pregunta sugerida
            validQuestions.forEach(function(question) {
                const chip = document.createElement('button');
                chip.className = 'pulso-followup-chip';
                chip.type = 'button';
                chip.textContent = question;
                chip.title = question;
                
                // Al hacer click, poblar el input y enviar
                chip.addEventListener('click', function() {
                    const input = document.getElementById('pulso-input');
                    input.value = question;
                    updateCharCount();
                    
                    // Enviar automáticamente
                    const form = document.getElementById('pulso-chat-form');
                    form.dispatchEvent(new Event('submit'));
                });
                
                containerEl.appendChild(chip);
            });
            
            // Agregar contenedor al final del chat
            messagesDiv.appendChild(containerEl);
            
            // Auto scroll
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
        
        // ========== FLOATING WINDOW LOGIC ==========
        
        const floatingState = {
            isOpen: false,
            isDragging: false,
            dragOffsetX: 0,
            dragOffsetY: 0
        };
        
        function toggleChat() {
            const container = document.getElementById('pulso-chat-container');
            const bubble = document.getElementById('pulso-chat-bubble');
            const header = document.getElementById('pulso-chat-header');
            
            if (!floatingState.isOpen) {
                // Abrir chat
                floatingState.isOpen = true;
                container.classList.add('is-open');
                bubble.style.display = 'none';

                // Convert auto height (from top+bottom pins) to explicit px so CSS resize works.
                container.style.height = container.offsetHeight + 'px';
                container.style.width  = container.offsetWidth  + 'px';

                // Clamp size to viewport whenever the user drags the resize handle.
                if (!container._resizeObserver && window.ResizeObserver) {
                    container._resizeObserver = new ResizeObserver(function() {
                        var maxH = window.innerHeight - 112;
                        var maxW = window.innerWidth  - 48;
                        if (container.offsetHeight > maxH) container.style.height = maxH + 'px';
                        if (container.offsetWidth  > maxW) container.style.width  = maxW + 'px';
                    });
                    container._resizeObserver.observe(container);
                }

                // Hacer draggable
                header.addEventListener('mousedown', startDrag);
                
                // Focus en input
                setTimeout(function() {
                    var input = document.getElementById('pulso-input');
                    if (input) input.focus();
                }, 100);
                
                // Scroll al final de los mensajes
                var msgs = document.getElementById('pulso-messages');
                if (msgs) msgs.scrollTop = msgs.scrollHeight;
                
                console.log('✅ Chat abierto');
            } else {
                // Minimizar a burbuja
                floatingState.isOpen = false;
                container.classList.remove('is-open');
                container.style.cssText = '';
                bubble.style.display = 'flex';
                
                // Si hay mensajes de conversación, marcar burbuja
                if (window.conversationHistory && window.conversationHistory.length > 0) {
                    bubble.classList.add('has-chat');
                }
                
                header.removeEventListener('mousedown', startDrag);
                console.log('✅ Chat minimizado a burbuja');
            }
        }
        
        function clearConversation() {
            const hasHistory = window.conversationHistory && window.conversationHistory.length > 0;
            if (hasHistory && !confirm('¿Empezar una conversación nueva? Se borrará el historial de este chat.')) {
                return;
            }

            window.conversationHistory = [];
            try {
                sessionStorage.removeItem('pulso_history_' + window.courseid);
            } catch (e) {
                // sessionStorage no disponible — no pasa nada, el estado en memoria ya está limpio.
            }

            // Quitar solo los mensajes y sugerencias; el panel de inicio
            // (#pulso-home) vive dentro del contenedor y debe conservarse.
            const messagesDiv = document.getElementById('pulso-messages');
            if (messagesDiv) {
                messagesDiv.querySelectorAll('.pulso-message, .pulso-followup-container').forEach(function(el) {
                    el.remove();
                });
            }
            removeStreamBubble();
            showLoading(false);
            setHomeVisible(true);

            const bubble = document.getElementById('pulso-chat-bubble');
            if (bubble) {
                bubble.classList.remove('has-chat');
            }

            const input = document.getElementById('pulso-input');
            if (input) {
                input.focus();
            }
        }

        function startDrag(e) {
            if (e.button !== 0) return;
            if (!floatingState.isOpen) return;
            
            e.preventDefault();
            
            const container = document.getElementById('pulso-chat-container');
            floatingState.isDragging = true;
            
            floatingState.dragOffsetX = e.clientX - container.offsetLeft;
            floatingState.dragOffsetY = e.clientY - container.offsetTop;
            
            document.addEventListener('mousemove', doDrag);
            document.addEventListener('mouseup', stopDrag);
        }

        function doDrag(e) {
            if (!floatingState.isDragging) return;
            
            e.preventDefault();
            
            const container = document.getElementById('pulso-chat-container');
            
            // Calcular nueva posición y limitar dentro de la pantalla
            let newX = e.clientX - floatingState.dragOffsetX;
            let newY = e.clientY - floatingState.dragOffsetY;
            newX = Math.max(0, Math.min(newX, window.innerWidth - container.offsetWidth));
            newY = Math.max(0, Math.min(newY, window.innerHeight - container.offsetHeight));
            
            container.style.left = newX + 'px';
            container.style.top = newY + 'px';
            container.style.right = 'auto';
            container.style.bottom = 'auto';
        }
        
        function stopDrag() {
            floatingState.isDragging = false;
            document.removeEventListener('mousemove', doDrag);
            document.removeEventListener('mouseup', stopDrag);
        }

        function isBlocksDrawerCollapsed() {
            const body = document.body;

            // 1) Deteccion por clases globales en body (si existen).
            if (body.classList.contains('drawer-open-right') || body.classList.contains('drawer-right-open')) {
                return false;
            }
            if (body.classList.contains('drawer-closed-right') || body.classList.contains('drawer-right-closed')) {
                return true;
            }

            // 2) Deteccion por toggle oficial de Moodle Boost.
            const rightToggle = document.querySelector('[data-action="toggle-drawer"][data-side="right"]');
            if (rightToggle) {
                const expanded = rightToggle.getAttribute('aria-expanded');
                if (expanded !== null) {
                    return expanded === 'false';
                }
            }

            // 3) Deteccion por estado visible del drawer derecho.
            const rightDrawer = document.querySelector('#theme_boost-drawers-blocks, [data-region="right-hand-drawer"], .drawer-right');
            if (rightDrawer) {
                const ariaHidden = rightDrawer.getAttribute('aria-hidden');
                if (ariaHidden !== null) {
                    return ariaHidden === 'true';
                }

                const drawerClasses = rightDrawer.className || '';
                if (/\bshow\b|\bopen\b|\bvisible\b/i.test(drawerClasses)) {
                    return false;
                }
                if (/\bcollapsed\b|\bhidden\b/i.test(drawerClasses)) {
                    return true;
                }

                const styles = window.getComputedStyle(rightDrawer);
                if (styles.display === 'none' || styles.visibility === 'hidden') {
                    return true;
                }
            }

            // 4) Fallback por texto del control (locale ES/EN y con/sin tilde).
            const toggler = document.querySelector(
                '[data-target*="blocks"], [aria-label*="cajón de bloques" i], [aria-label*="cajon de bloques" i], [title*="cajón de bloques" i], [title*="cajon de bloques" i], [aria-label*="block drawer" i], [title*="block drawer" i]'
            );

            if (!toggler) {
                return false;
            }

            const text = ((toggler.getAttribute('aria-label') || '') + ' ' + (toggler.getAttribute('title') || '')).toLowerCase();
            if (text.includes('abrir') || text.includes('open')) {
                return true;
            }

            return false;
        }

        function updateBubblePositionByDrawerState() {
            const bubble = document.getElementById('pulso-chat-bubble');
            const container = document.getElementById('pulso-chat-container');
            if (!bubble || !container) return;

            const collapsed = isBlocksDrawerCollapsed();
            bubble.classList.toggle('drawer-collapsed', collapsed);
            container.classList.toggle('drawer-collapsed', collapsed);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Mover el chat y la burbuja al body para que sean independientes del bloque
            var container = document.getElementById('pulso-chat-container');
            var bubble = document.getElementById('pulso-chat-bubble');

            // Ocultar visualmente el bloque de Pulso en el cajon lateral.
            var sourceEl = bubble || container;
            var pulsoBlock = sourceEl ? (sourceEl.closest('.block_pulso') || sourceEl.closest('.block')) : null;
            if (pulsoBlock) {
                pulsoBlock.style.display = 'none';
                pulsoBlock.setAttribute('aria-hidden', 'true');
            }

            if (container) document.body.appendChild(container);
            if (bubble) document.body.appendChild(bubble);

            // Ajustar posicion de burbuja cuando cambia el estado del cajon de bloques.
            updateBubblePositionByDrawerState();
            window.addEventListener('resize', updateBubblePositionByDrawerState);

            // Recalcular luego de cualquier click para cubrir toggles con tooltips/icons internos.
            document.addEventListener('click', function() {
                setTimeout(updateBubblePositionByDrawerState, 120);
                setTimeout(updateBubblePositionByDrawerState, 260);
            });

            const bodyObserver = new MutationObserver(function() {
                updateBubblePositionByDrawerState();
            });
            bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

            const rightToggle = document.querySelector('[data-action="toggle-drawer"][data-side="right"]');
            if (rightToggle) {
                const toggleObserver = new MutationObserver(function() {
                    updateBubblePositionByDrawerState();
                });
                toggleObserver.observe(rightToggle, { attributes: true, attributeFilter: ['aria-expanded', 'class', 'title', 'aria-label'] });
            }

            const rightDrawer = document.querySelector('#theme_boost-drawers-blocks, [data-region="right-hand-drawer"], .drawer-right');
            if (rightDrawer) {
                const drawerObserver = new MutationObserver(function() {
                    updateBubblePositionByDrawerState();
                });
                drawerObserver.observe(rightDrawer, { attributes: true, attributeFilter: ['class', 'style', 'aria-hidden'] });
            }
            
            // Dictado por voz (se auto-oculta si el navegador no lo soporta)
            initPulsoMic();

            // Listener para contador de caracteres en tiempo real
            const inputElement = document.getElementById('pulso-input');
            if (inputElement) {
                inputElement.addEventListener('input', updateCharCount);
                inputElement.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage(new Event('submit'));
                    }
                });
            }
        });
    </script>
    HTML;
    
    // Modo alumno / modo profesor: se ELIMINAN del HTML los bloques del otro rol
    // en vez de ocultarlos con CSS, para que las tarjetas de analítica no lleguen
    // siquiera al navegador del alumno.
    $strip = $isteacher ? 'STUDENT' : 'TEACHER';
    $html = preg_replace(
        '/<!--PULSO_' . $strip . '_ONLY_START-->.*?<!--PULSO_' . $strip . '_ONLY_END-->/s',
        '',
        $html
    );
    // Quitar los marcadores del rol que sí se renderiza.
    $html = preg_replace('/<!--PULSO_(TEACHER|STUDENT)_ONLY_(START|END)-->/', '', $html);

    // Bloque "Crear" (encargos a Epica): capability independiente de
    // teacher/student, se elimina del HTML igual que los otros bloques
    // cuando el centro se la ha quitado a este usuario.
    if (!$cancreate) {
        $html = preg_replace('/<!--PULSO_CREATE_ONLY_START-->.*?<!--PULSO_CREATE_ONLY_END-->/s', '', $html);
    }
    $html = preg_replace('/<!--PULSO_CREATE_ONLY_(START|END)-->/', '', $html);

    // Inyectar versión, nombre y curso (el bloque HTML es un nowdoc sin interpolación).
    $html = str_replace('%%PULSO_VERSION%%', s($pulso_release), $html);

    // El nombre ya solo se usa dentro del saludo (%%PULSO_GREETING%%).
    $firstname = trim((string)($USER->firstname ?? ''));

    // El saludo del profesor pregunta por el curso; el del alumno, por el
    // contenido: el anterior ofrecía notas y alumnos en riesgo, que ahora se le
    // niegan.
    if ($isteacher) {
        $greeting = '¡Hola, ' . ($firstname !== '' ? $firstname : 'profe')
            . '! ¿Qué quieres saber de tu curso?';
    } else {
        $greeting = ($firstname !== '' ? '¡Hola, ' . $firstname . '! ' : '¡Hola! ')
            . 'Pregúntame sobre el contenido del curso: pídeme un resumen, '
            . 'una explicación o dudas sobre los materiales.';
    }
    $html = str_replace('%%PULSO_GREETING%%', s($greeting), $html);

    try {
        $coursename = format_string(get_course($courseid)->fullname);
    } catch (\Throwable $e) {
        $coursename = '';
    }
    $html = str_replace('%%PULSO_COURSENAME%%', s($coursename), $html);

    // Retornar con variables inicializadas
    return $js_init . $html;
}
