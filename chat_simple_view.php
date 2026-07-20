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
 */
function render_chat_simple($courseid, $context) {
    global $OUTPUT, $USER, $CFG;
    
    // Construir URL base correcta para AJAX
    $api_url = $CFG->wwwroot . '/blocks/pulso/api_chat.php';
    $stream_url = $CFG->wwwroot . '/blocks/pulso/api_chat_stream.php';

    // Leer la versión directamente de version.php (no de la BD) para que el
    // badge del header refleje siempre el código desplegado, incluso antes
    // de ejecutar la actualización de Moodle.
    $plugin = new stdClass();
    include(__DIR__ . '/version.php');
    $pulso_release = 'v' . ($plugin->release ?? $plugin->version ?? '?');

    // Inyectar variables globales JavaScript
    $js_init = <<<JSINIT
    <script>
        // Variables globales para AJAX
        window.courseid = {$courseid};
        window.apiUrl = '{$api_url}';
        window.streamApiUrl = '{$stream_url}';
        
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
           PULSO AI — Identidad Awakelab 2026, tema OSCURO
           Fondo casi negro con base azul profundo de marca; cian vivo como
           acento sobre oscuro (uso canónico de la paleta). Poppins.
           ================================================================== */
        .pulso-chat-bubble,
        .pulso-chat-container {
            --pulso-ink: #EDF1FA;                      /* texto principal (claro) */
            --pulso-deep: #012142;                     /* azul profundo de marca */
            --pulso-navy: #003670;                     /* azul profundo secundario */
            --pulso-slate: #A9B6D3;                    /* texto secundario */
            --pulso-muted: #72A3C4;                    /* texto terciario / hints */
            --pulso-cyan: #11EAEA;                     /* acento vivo */
            --pulso-cyan-soft: #D9FBFF;                /* cian pálido (texto) */
            --pulso-teal: #0ABCC9;                     /* acento funcional (focus) */
            --pulso-teal-ink: #19F7F1;                 /* marcadores sobre oscuro */
            --pulso-bg: #0B111C;                       /* fondo base casi negro */
            --pulso-surface: #151D2C;                  /* tarjetas / superficies */
            --pulso-surface-2: #1C2739;                /* hover / anidado */
            --pulso-line: rgba(226, 230, 242, 0.12);   /* bordes y divisores */
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
            top: 12px;
            bottom: 100px;
            right: 32px;
            width: min(460px, calc(100vw - 48px));
            min-width: 300px;
            min-height: 300px;
            max-width: calc(100vw - 48px);
            max-height: calc(100vh - 112px);
            z-index: 9999;
            background: var(--pulso-bg);
            border: 1px solid var(--pulso-line);
            border-radius: 16px;
            overflow: hidden;
            flex-direction: column;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.55);
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

        .pulso-chat-container.drawer-collapsed {
            bottom: 150px;
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
            background: rgba(226, 230, 242, 0.18);
            border-radius: 8px;
        }

        .pulso-chat-messages::-webkit-scrollbar-thumb:hover {
            background: rgba(226, 230, 242, 0.32);
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
            border: 1px solid rgba(17, 234, 234, 0.4);
            box-shadow: 0 0 18px rgba(17, 234, 234, 0.18);
        }

        .pulso-home-hello h5 {
            margin: 0;
            font-size: 1.02rem;
            font-weight: 600;
            font-family: var(--pulso-font);
            color: var(--pulso-ink);
            line-height: 1.4;
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
            background: rgba(226, 230, 242, 0.08);
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
            border-color: rgba(17, 234, 234, 0.55);
            background: var(--pulso-surface-2);
            transform: translateY(-1px);
        }

        .pulso-action-card:active {
            transform: translateY(0);
        }

        /* Sección de contenido del curso: tarjetas con tinte azul de marca. */
        .pulso-home-section.course .pulso-action-card {
            background: #14213A;
            border-color: rgba(78, 126, 165, 0.35);
        }

        .pulso-home-section.course .pulso-action-card:hover {
            border-color: rgba(17, 234, 234, 0.55);
            background: #182A4A;
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
            background: rgba(226, 230, 242, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pulso-slate);
        }

        .pulso-action-chevron svg {
            width: 12px;
            height: 12px;
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
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .pulso-message.ai .pulso-message-content {
            background: var(--pulso-surface);
            color: var(--pulso-ink);
            border: 1px solid var(--pulso-line);
            border-top-left-radius: 4px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
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
            color: var(--pulso-cyan-soft);
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
            grid-template-columns: auto 1fr;
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
            color: var(--pulso-cyan-soft);
            font-size: 0.85em;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .pulso-meta-value {
            color: var(--pulso-ink);
            line-height: 1.55;
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
            color: var(--pulso-cyan-soft);
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
            background: rgba(17, 234, 234, 0.1);
            color: var(--pulso-cyan-soft);
            border: 1px solid rgba(17, 234, 234, 0.25);
        }

        .pulso-activity-badge.resource {
            background: rgba(52, 211, 153, 0.12);
            color: #7EE2B8;
            border-color: rgba(52, 211, 153, 0.3);
        }

        .pulso-activity-badge.label {
            background: rgba(245, 212, 143, 0.12);
            color: #F5D48F;
            border-color: rgba(245, 212, 143, 0.3);
        }

        .pulso-activity-badge.page,
        .pulso-activity-badge.book,
        .pulso-activity-badge.wiki {
            background: rgba(196, 167, 255, 0.12);
            color: #C4A7FF;
            border-color: rgba(196, 167, 255, 0.3);
        }

        .pulso-activity-name {
            color: var(--pulso-ink);
            font-weight: 500;
            line-height: 1.45;
        }

        .pulso-formula {
            background: #0A1526;
            border: 1px solid var(--pulso-line);
            border-left: 3px solid var(--pulso-cyan);
            border-radius: 8px;
            padding: 10px 12px;
            color: var(--pulso-cyan-soft);
            font-family: Consolas, Monaco, "Courier New", monospace;
            font-size: 0.88em;
            line-height: 1.45;
            overflow-x: auto;
            white-space: nowrap;
            max-width: 100%;
        }

        .pulso-result-box {
            background: rgba(52, 211, 153, 0.1);
            border: 1px solid rgba(52, 211, 153, 0.25);
            border-left: 3px solid #34D399;
            color: #A7EFCE;
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

        .pulso-list-card.success { border-left-color: #34D399; }
        .pulso-list-card.info    { border-left-color: var(--pulso-cyan); }
        .pulso-list-card.warn    { border-left-color: #F5D48F; }
        .pulso-list-card.danger  { border-left-color: #F28B82; }

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
            border: 1px dashed rgba(226, 230, 242, 0.25);
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
            background: rgba(226, 230, 242, 0.04);
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
            box-shadow: 0 0 0 3px rgba(17, 234, 234, 0.15);
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
            background: rgba(226, 230, 242, 0.03);
        }

        .pulso-table tbody tr {
            transition: background 0.15s;
        }

        .pulso-table tbody tr:hover {
            background: rgba(17, 234, 234, 0.07);
        }

        .pulso-no-results td {
            padding: 20px;
            text-align: center;
            color: var(--pulso-muted);
        }

        .pulso-table-footer {
            padding: 10px 12px;
            background: rgba(226, 230, 242, 0.04);
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
            color: var(--pulso-cyan-soft);
            border: 1px solid var(--pulso-line);
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8em;
            font-weight: 500;
            font-family: var(--pulso-font);
            transition: border-color 0.2s, background 0.2s;
        }

        .pulso-export-btn:hover {
            border-color: rgba(17, 234, 234, 0.55);
            background: rgba(17, 234, 234, 0.08);
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

        .pulso-status-pill.success { background: rgba(52, 211, 153, 0.14); color: #7EE2B8; }
        .pulso-status-pill.danger  { background: rgba(242, 139, 130, 0.14); color: #F5A9A2; }
        .pulso-status-pill.warning { background: rgba(245, 212, 143, 0.14); color: #F5D48F; }
        .pulso-status-pill.neutral { background: rgba(226, 230, 242, 0.1); color: var(--pulso-slate); }

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
            color: var(--pulso-cyan-soft);
            font-weight: 500;
            white-space: normal;
            text-align: left;
            line-height: 1.4;
            max-width: 100%;
        }

        .pulso-followup-chip:hover {
            border-color: rgba(17, 234, 234, 0.55);
            background: var(--pulso-surface-2);
            transform: translateY(-1px);
        }

        .pulso-followup-chip:active {
            transform: translateY(0);
        }

        /* ========== INDICADOR "PENSANDO" (onda de pulso — firma Pulso) ========== */
        .pulso-loading {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            color: var(--pulso-slate);
            font-size: 0.84rem;
            font-weight: 500;
            background: var(--pulso-bg);
        }

        .pulso-loading.show {
            display: flex;
        }

        .pulso-pulsewave {
            color: var(--pulso-cyan);
            flex-shrink: 0;
        }

        .pulso-pulsewave polyline {
            stroke-dasharray: 90;
            stroke-dashoffset: 90;
            animation: pulso-wave 1.4s linear infinite;
        }

        @keyframes pulso-wave {
            to { stroke-dashoffset: -90; }
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
            border-color: rgba(17, 234, 234, 0.6);
            background: var(--pulso-surface-2);
            box-shadow: 0 0 0 3px rgba(17, 234, 234, 0.14);
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
            box-shadow: 0 0 16px rgba(17, 234, 234, 0.4);
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
            border-color: rgba(17, 234, 234, 0.5);
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
            0%   { box-shadow: 0 0 0 0 rgba(17, 234, 234, 0.5); }
            70%  { box-shadow: 0 0 0 10px rgba(17, 234, 234, 0); }
            100% { box-shadow: 0 0 0 0 rgba(17, 234, 234, 0); }
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
            .pulso-pulsewave polyline,
            .pulso-stream-cursor,
            .pulso-mic-btn.pulso-mic-recording,
            .pulso-action-card {
                animation: none !important;
                transition: none !important;
            }

            .pulso-message.ai .pulso-rich-answer > * {
                opacity: 1;
            }
        }

    </style>
    
    <!-- Botón circular flotante -->
    <button class="pulso-chat-bubble" id="pulso-chat-bubble" onclick="toggleChat()" title="Pulso AI — Asistente del curso" aria-label="Abrir el asistente Pulso AI">
        <img src="https://media.awakelab.world/MARCA_AWK26/awakelab_isotipo_fondo-oscuro_transparente.png" alt="" aria-hidden="true">
    </button>

    <div class="pulso-chat-container" id="pulso-chat-container" role="dialog" aria-label="Pulso AI, asistente del curso">
        <div class="pulso-chat-header" id="pulso-chat-header">
            <div class="pulso-header-brand">
                <img class="pulso-header-logo" src="https://media.awakelab.world/MARCA_AWK26/awakelab_isotipo_fondo-oscuro_transparente.png" alt="" aria-hidden="true">
                <div>
                    <h4>Pulso AI <span class="pulso-version-badge">%%PULSO_VERSION%%</span></h4>
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

        <div class="pulso-chat-messages" id="pulso-messages" role="log" aria-live="polite" aria-label="Conversación con Pulso AI">
            <div class="pulso-home" id="pulso-home">
                <div class="pulso-home-hello">
                    <div class="pulso-home-avatar" aria-hidden="true"></div>
                    <h5>¡Hola, %%PULSO_FIRSTNAME%%! ¿Qué quieres saber de tu curso?</h5>
                </div>

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
                    </div>
                </div>

                <div class="pulso-home-section course">
                    <div class="pulso-home-section-head">
                        <span class="pulso-home-section-title">Contenido del curso</span>
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
                    </div>
                </div>
            </div>
        </div>

        <div class="pulso-loading" id="pulso-loading" role="status">
            <svg class="pulso-pulsewave" viewBox="0 0 48 16" width="40" height="14" aria-hidden="true">
                <polyline points="0,8 12,8 17,3 23,13 29,5 33,8 48,8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span id="pulso-loading-text">Procesando...</span>
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

                html += '</div>';
                
                console.log('✅ HTML generado correctamente');
                return html;
            } catch (e) {
                console.warn('⚠️ Error al parsear JSON:', e.message);
                console.warn('⚠️ Texto que intentó parsear:', answer.substring(0, 200));
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

        // La pantalla de inicio (saludo + tarjetas) solo se muestra sin conversación.
        function setHomeVisible(visible) {
            const home = document.getElementById('pulso-home');
            if (home) {
                home.style.display = visible ? '' : 'none';
            }
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
            return formData;
        }

        // Procesamiento compartido de la respuesta completa (stream final / XHR).
        function handleChatResponse(message, response) {
            if (response.success && response.answer) {
                const showAnalysisSections = isAnalyticsQuestion(message);
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
                window.conversationHistory.push({role: 'assistant', content: response.answer});
                if (window.conversationHistory.length > 20) {
                    window.conversationHistory = window.conversationHistory.slice(-20);
                }
                try {
                    sessionStorage.setItem('pulso_history_' + window.courseid, JSON.stringify(window.conversationHistory));
                } catch(e) {
                    console.warn('⚠️ No se pudo guardar historial en sessionStorage');
                }
            } else {
                const errorMsg = response.message || 'No success flag';
                console.error('❌ Response not successful:', response);
                addMessage('⚠️ Error: ' + errorMsg, 'ai');
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
                    addMessage('⚠️ Error: ' + (data.message || 'desconocido'), 'ai');
                }
            }

            fetch(window.streamApiUrl, {
                method: 'POST',
                body: buildChatFormData(message),
                credentials: 'same-origin'
            })
            .then(function(res) {
                const ct = res.headers.get('content-type') || '';
                if (!res.ok || !res.body || ct.indexOf('text/event-stream') === -1) {
                    throw new Error('stream-unavailable');
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

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            handleChatResponse(message, response);
                        } catch (e) {
                            console.error('❌ JSON Parse Error:', e.message);
                            console.error('❌ Raw response was:', xhr.responseText);
                            addMessage('⚠️ Error procesando respuesta: ' + e.message, 'ai');
                        }
                    } else {
                        console.error('❌ HTTP Error:', xhr.status);
                        addMessage('⚠️ Error de conexión (HTTP ' + xhr.status + ')', 'ai');
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
        
        function showLoading(show, label) {
            const loading = document.getElementById('pulso-loading');
            const labelEl = document.getElementById('pulso-loading-text');
            if (labelEl) {
                labelEl.textContent = label || 'Procesando...';
            }
            if (show) {
                loading.classList.add('show');
            } else {
                loading.classList.remove('show');
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
    
    // Inyectar versión, nombre y curso (el bloque HTML es un nowdoc sin interpolación).
    $html = str_replace('%%PULSO_VERSION%%', s($pulso_release), $html);

    $firstname = trim((string)($USER->firstname ?? ''));
    $html = str_replace('%%PULSO_FIRSTNAME%%', s($firstname !== '' ? $firstname : 'profe'), $html);

    try {
        $coursename = format_string(get_course($courseid)->fullname);
    } catch (\Throwable $e) {
        $coursename = '';
    }
    $html = str_replace('%%PULSO_COURSENAME%%', s($coursename), $html);

    // Retornar con variables inicializadas
    return $js_init . $html;
}
