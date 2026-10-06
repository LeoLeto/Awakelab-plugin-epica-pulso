<?php
/**
 * CHAT SIMPLE VIEW - PHP + HTML (el CSS vive en styles.css y el JS en amd/src/*.js)
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
    global $OUTPUT, $USER, $CFG, $PAGE;

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

    // ¿Hay Épica en este sitio? Solo presentación: los endpoints api_create_submit,
    // api_retos y epica_historial lo vuelven a comprobar.
    require_once(__DIR__ . '/classes/epica_client.php');
    $epicaavailable = \block_pulso\epica_client::disponible();

    // La configuracion que antes viajaba en variables globales window.* va al modulo AMD
    // (amd/src/chat.js). Solo para adaptar la UI: el servidor decide que datos se devuelven.
    $PAGE->requires->js_call_amd('block_pulso/chat', 'init', [[
        'courseid' => (int)$courseid,
        'apiUrl' => $api_url,
        'streamApiUrl' => $stream_url,
        'apiCreateFormUrl' => $create_form_url,
        'apiCreateSubmitUrl' => $create_submit_url,
        'apiCreateStatusUrl' => $create_status_url,
        'apiAmpliacionUrl' => $ampliacion_url,
        'apiRetosUrl' => $retos_url,
        'apiHistorialUrl' => $historial_url,
        'sesskey' => $pulso_sesskey,
        'isTeacher' => !empty($isteacher),
        'epicaAvailable' => (bool)$epicaavailable,
    ]]);
    
    // HTML y CSS combinados
    $html = <<<'HTML'
    
    <!-- Botón circular flotante -->
    <button class="pulso-chat-bubble" id="pulso-chat-bubble" data-pulso-action="toggleChat" title="Pulse AI — Asistente del curso" aria-label="Abrir el asistente Pulse AI" aria-expanded="false" aria-controls="pulso-chat-container">
        <img src="%%PULSO_ISOTIPO%%" alt="" aria-hidden="true">
    </button>

    <div class="pulso-chat-container" id="pulso-chat-container" role="region" aria-label="Pulse AI">
        <div class="pulso-chat-header" id="pulso-chat-header">
            <div class="pulso-header-brand">
                <img class="pulso-header-logo" src="%%PULSO_ISOTIPO%%" alt="" aria-hidden="true">
                <div>
                    <h2>Pulse AI%%PULSO_VERSION_BADGE%%</h2>
                    <span class="pulso-header-sub"><span class="pulso-status-dot" aria-hidden="true"></span>Asistente del curso</span>
                </div>
            </div>
            <div class="header-controls">
                <button class="header-btn" id="pulso-clear-btn" title="Nueva conversación" aria-label="Empezar una conversación nueva" data-pulso-action="clearConversation">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 9 8 9"/></svg>
                </button>
                <button type="button" class="header-btn" id="pulso-expand-btn" title="Ampliar" aria-label="Ampliar el chat" data-pulso-action="toggleChatSize">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                </button>
                <button class="header-btn" id="pulso-minimize-btn" title="Minimizar" aria-label="Minimizar el chat" data-pulso-action="toggleChat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
            </div>
        </div>

        <div class="pulso-chat-messages" id="pulso-scroll">
            <div class="pulso-home" id="pulso-home">
                <div class="pulso-home-hello">
                    <div class="pulso-home-avatar" aria-hidden="true"></div>
                    <h3>%%PULSO_GREETING%%</h3>
                </div>

                <button type="button" class="pulso-home-help-btn" data-pulso-action="showCapabilities" aria-label="Descubre qué puede hacer Pulse">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span>¿Qué puede hacer Pulse?<span class="pulso-home-help-sub">Descúbrelo en 10 segundos</span></span>
                </button>

                <!--PULSO_TEACHER_ONLY_START-->
                <div class="pulso-home-section">
                    <div class="pulso-home-section-head">
                        <h3 class="pulso-home-section-title">Analítica del curso</h3>
                        <span class="pulso-home-context-chip">%%PULSO_COURSENAME%%</span>
                    </div>
                    <div class="pulso-home-grid">
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Cuál es la tasa de completitud?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                            <span class="pulso-action-label">Completitud</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Cuáles son las notas promedio?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="20" x2="6" y2="14"/><line x1="12" y1="20" x2="12" y2="8"/><line x1="18" y1="20" x2="18" y2="4"/></svg>
                            <span class="pulso-action-label">Notas medias</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Qué estudiantes están en riesgo?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span class="pulso-action-label">Alumnos en riesgo</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Cuál es el engagement?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span class="pulso-action-label">Participación</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Cuáles son los mejores alumnos del curso?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span class="pulso-action-label">Mejores alumnos</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Qué alumnos llevan más de una semana sin acceder?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span class="pulso-action-label">Alumnos inactivos</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="Dame un panorama general del curso: tasa de completitud, nota media y cuántos alumnos están en riesgo.">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                            <span class="pulso-action-label">Panorama del curso</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Cuáles son los resultados de los cuestionarios del curso: nota media, número de intentos y cuántos alumnos aprobaron?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="m9 13 2 2 4-4"/></svg>
                            <span class="pulso-action-label">Resultados de cuestionarios</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Cuál es el estado de las entregas de las tareas: cuántas entregadas, cuántas pendientes y cuántas sin calificar?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                            <span class="pulso-action-label">Estado de entregas</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                    </div>
                </div>
                <!--PULSO_TEACHER_ONLY_END-->

                <div class="pulso-home-section course">
                    <div class="pulso-home-section-head">
                        <h3 class="pulso-home-section-title">Contenido del curso</h3>
                        <!--PULSO_STUDENT_ONLY_START--><span class="pulso-home-context-chip">%%PULSO_COURSENAME%%</span><!--PULSO_STUDENT_ONLY_END-->
                    </div>
                    <div class="pulso-home-grid">
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿De qué trata este curso?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <span class="pulso-action-label">Resumen del curso</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Qué actividades y recursos tiene el curso?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                            <span class="pulso-action-label">Actividades y recursos</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Qué secciones tiene el curso?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            <span class="pulso-action-label">Secciones</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Qué cuestionarios hay en el curso?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span class="pulso-action-label">Cuestionarios</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Qué tareas hay en el curso?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>
                            <span class="pulso-action-label">Tareas del curso</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <!--PULSO_STUDENT_ONLY_START-->
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="¿Qué materiales y documentos hay disponibles en el curso para estudiar?">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            <span class="pulso-action-label">Materiales de estudio</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="Explícame de qué trata la sección 1 del curso y qué contenidos incluye.">
                            <svg class="pulso-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                            <span class="pulso-action-label">Explícame un tema</span>
                            <span class="pulso-action-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </button>
                        <button class="pulso-action-card" data-pulso-action="askPreset" data-pulso-arg="Resume los conceptos principales del material del curso para repasar.">
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
                        <h3 class="pulso-home-section-title">Crear</h3>
                        <!--PULSO_STUDENT_ONLY_START--><!--PULSO_EPICA_ONLY_START--><button type="button" class="pulso-historial-link head" data-pulso-action="pulsoAbrirHistorial">Mi historial<span aria-hidden="true"> ↗</span><span class="pulso-sr-only"> (se abre en una pestaña nueva)</span></button><!--PULSO_EPICA_ONLY_END--><!--PULSO_STUDENT_ONLY_END-->
                    </div>
                    <div class="pulso-create-cta-row">
                        <!--PULSO_EPICA_ONLY_START-->
                        <button type="button" class="pulso-create-cta" data-pulso-action="openCreatePanel" data-pulso-arg="infografia">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Crear infografía</span>
                                <span class="pulso-create-cta-sub">A partir de un recurso del curso</span>
                            </span>
                        </button>
                        <button type="button" class="pulso-create-cta" data-pulso-action="openCreatePanel" data-pulso-arg="gamificacion">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="12" x2="10" y2="12"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="15" y1="13" x2="15.01" y2="13"/><line x1="18" y1="11" x2="18.01" y2="11"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Crear juego</span>
                                <span class="pulso-create-cta-sub">A partir de un recurso del curso</span>
                            </span>
                        </button>
                        <!--PULSO_EPICA_ONLY_END-->
                        <button type="button" class="pulso-create-cta" data-pulso-action="openCreatePanel" data-pulso-arg="ampliacion">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Ampliar recurso</span>
                                <span class="pulso-create-cta-sub">Vídeos y artículos sobre un recurso del curso</span>
                            </span>
                        </button>
                        <!--PULSO_EPICA_ONLY_START-->
                        <button type="button" class="pulso-create-cta" data-pulso-action="openCreatePanel" data-pulso-arg="retos">
                            <span class="pulso-create-cta-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                            </span>
                            <span class="pulso-create-cta-text">
                                <span class="pulso-create-cta-title">Crear reto</span>
                                <span class="pulso-create-cta-sub">Un caso práctico que corrige la IA</span>
                            </span>
                        </button>
                        <!--PULSO_EPICA_ONLY_END-->
                    </div>
                </div>
                <!--PULSO_CREATE_ONLY_END-->
            </div>

            <!--PULSO_CREATE_ONLY_START-->
            <div class="pulso-create-panel" id="pulso-create-panel">
                <div class="pulso-create-head">
                    <button type="button" class="pulso-create-back" data-pulso-action="closeCreatePanel" aria-label="Volver a la pantalla de inicio">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <h3 id="pulso-create-title" tabindex="-1">Crear infografía</h3>
                </div>
                <div class="pulso-create-body" id="pulso-create-body">
                    <p class="pulso-create-hint">Cargando…</p>
                </div>
            </div>
            <!--PULSO_CREATE_ONLY_END-->

            <!-- Región en vivo SOLO para mensajes: la home y el panel Crear quedan fuera. -->
            <div class="pulso-log" id="pulso-messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="Conversación con Pulse AI"></div>
        </div>

        <div class="pulso-offline" id="pulso-offline" hidden>Sin conexión. Tus preguntas se enviarán cuando vuelva internet.</div>
        <!-- Avisos breves para lector de pantalla (estado de un encargo, conexión, límite de caracteres). -->
        <div class="pulso-sr-only" id="pulso-live-status" role="status" aria-live="polite" aria-atomic="true"></div>

        <div class="pulso-chat-input-area">
            <form id="pulso-chat-form">
                <div class="pulso-input-group">
                    <input
                        type="text"
                        id="pulso-input"
                        placeholder="Pregunta sobre el curso..."
                        maxlength="500"
                        autocomplete="off"
                        aria-label="Escribe tu pregunta sobre el curso"
                    />
                    <button type="button" id="pulso-mic-btn" class="pulso-mic-btn" style="display:none;" aria-label="Dictar pregunta por voz" aria-pressed="false" title="Dictar por voz" data-pulso-action="toggleMic">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-2.08A7 7 0 0 0 19 12h-2Z"/></svg>
                    </button>
                    <button type="submit" class="pulso-send-btn" id="pulso-send-btn" aria-label="Enviar pregunta" aria-disabled="true">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21.85 12 3.4 3.6l-.01 6.53L15 12 3.39 13.87z"/></svg>
                    </button>
                </div>
            </form>
            <div class="pulso-char-count" id="pulso-char-count-box" aria-hidden="true" hidden>
                <span id="pulso-char-count">0</span>/500
            </div>
        </div>
    </div>
    
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

    // Herramientas de Épica (infografía, juego, retos, «Mi historial»): solo si Épica está
    // disponible en este sitio (v1.31.0). Sin ella se quitan del HTML —no se ocultan con
    // CSS— y queda solo «Ampliar recurso». Los endpoints lo vuelven a exigir.
    if (!$epicaavailable) {
        $html = preg_replace('/<!--PULSO_EPICA_ONLY_START-->.*?<!--PULSO_EPICA_ONLY_END-->/s', '', $html);
    }
    $html = preg_replace('/<!--PULSO_EPICA_ONLY_(START|END)-->/', '', $html);

    // Isotipo desde pix/ (antes de media.awakelab.world: una peticion a un tercero en cada pagina).
    $html = str_replace('%%PULSO_ISOTIPO%%', s($OUTPUT->image_url('isotipo-oscuro', 'block_pulso')->out(false)), $html);

    // Inyectar versión, nombre y curso (el bloque HTML es un nowdoc sin interpolación).
    // La insignia de versión es para quien administra el curso (viewanalytics): sirve para
    // comprobar qué build corre. El alumnado no la ve (a él no le dice nada).
    $versionbadge = $isteacher
        ? ' <span class="pulso-version-badge">' . s($pulso_release) . '</span>'
        : '';
    $html = str_replace('%%PULSO_VERSION_BADGE%%', $versionbadge, $html);

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

    return $html;
}
