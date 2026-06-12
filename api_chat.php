<?php
/**
 * API Endpoint: Chat Query Processing
 * Task T2.3.4: Implement OpenAI call with dynamic data injection
 * 
 * Recibe query del usuario y retorna respuesta de OpenAI
 * con contexto del curso inyectado dinámicamente
 * 
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Permitir AJAX
define('AJAX_SCRIPT', true);

// Incluir Moodle config
require_once(__DIR__ . '/../../config.php');

// Incluir clases necesarias
require_once(__DIR__ . '/classes/data_retriever.php');
require_once(__DIR__ . '/classes/openai_connector.php');
require_once(__DIR__ . '/classes/system_prompt_designer.php');
require_once(__DIR__ . '/classes/rag_retriever.php');

use block_pulso\data_retriever;
use block_pulso\openai_connector;
use block_pulso\system_prompt_designer;
use block_pulso\rag_retriever;

// Validar que es AJAX y obtener parámetros
$courseid = optional_param('courseid', 0, PARAM_INT);
$user_query = optional_param('user_query', '', PARAM_RAW);
$conversation_history = optional_param('conversation_history', '[]', PARAM_RAW);

// Headers JSON
header('Content-Type: application/json; charset=utf-8');

// Respuesta por defecto
$response = [
    'success' => false,
    'message' => 'Error desconocido',
    'answer' => null,
    'tokens_used' => 0
];

try {
    // Necesario para session (T2.5.3)
    global $SESSION, $USER;
    
    // ============================================================
    // VALIDACIONES BÁSICAS
    // ============================================================
    
    if (empty($courseid) || $courseid <= 0) {
        throw new Exception('Invalid courseid provided');
    }
    
    if (empty($user_query)) {
        throw new Exception('Empty query provided');
    }
    
    // Validar que el curso existe
    $course = get_course($courseid);
    if (!$course) {
        throw new Exception('Course not found');
    }
    
    // ============================================================
    // T2.6.1: VERIFICAR SI PULSO ESTÁ HABILITADO PARA ESTE CURSO
    // ============================================================
    
    $course_enabled = get_config('block_pulso', 'enabled_course_' . $courseid);
    $default_enabled = get_config('block_pulso', 'enabled_by_default');
    if ($course_enabled !== false) {
        $is_enabled = (bool)$course_enabled;
    } else {
        $is_enabled = ($default_enabled === false) ? true : (bool)$default_enabled;
    }
    if (!$is_enabled) {
        throw new Exception('Pulso is disabled for this course');
    }
    
    // ============================================================
    // T2.6.2: VERIFICAR PERMISOS DEL USUARIO
    // ============================================================
    
    require_login($course);
    $context = context_course::instance($courseid);
    if (!has_capability('block/pulso:viewanalytics', $context)) {
        throw new Exception('You do not have permission to use analytics');
    }
    
    // ============================================================
    // 1. OBTENER CONTEXTO DEL CURSO (F2.2 - data_retriever)
    // ============================================================
    
    // T2.6.2: Respetar toggles de categorías de datos
    $data_completion = get_config('block_pulso', 'data_completion');
    $data_grades = get_config('block_pulso', 'data_grades');
    $data_logs = get_config('block_pulso', 'data_logs');
    
    $retriever = new data_retriever();
    $course_context = $retriever->get_unified_course_context($courseid, 7);
    
    // Filtrar categorías de datos según configuración de admin
    if (isset($course_context['analytics'])) {
        if (empty($data_completion) && $data_completion !== false) {
            // Default is enabled
        } else if ($data_completion === '0') {
            $course_context['analytics']['course_completions'] = [];
            $course_context['analytics']['course_completions_count'] = 0;
            $course_context['analytics']['module_completions'] = [];
            $course_context['analytics']['module_completions_count'] = 0;
        }
        if ($data_grades === '0') {
            $course_context['analytics']['grades_and_quizzes'] = [];
            $course_context['analytics']['grades_and_quizzes_count'] = 0;
        }
        if ($data_logs === '0') {
            $course_context['access_logs'] = [];
        }
    }
    
    if ($course_context['status'] !== 'success') {
        throw new Exception('Failed to retrieve course data: ' . $course_context['message']);
    }
    
    // ============================================================
    // 2. RAG: Retrieve relevant course content chunks
    // ============================================================

    $rag_result = rag_retriever::get_context_and_diagnostics_for_query($courseid, $user_query);
    $rag_context = $rag_result['context'] ?? '';
    $rag_diagnostics = $rag_result['diagnostics'] ?? [];

    // ============================================================
    // 3. INYECTAR DATOS EN SYSTEM PROMPT
    // ============================================================

    $system_prompt = system_prompt_designer::generate_prompt_with_context_and_rag(
        $course_context,
        $rag_context
    );

    // ============================================================
    // 3. PREPARAR HISTORIAL DE CONVERSACIÓN (T2.5.3)
    // ============================================================
    
    // Decodificar historial enviado desde el cliente
    $client_history = [];
    try {
        $client_history = json_decode($conversation_history, true) ?? [];
        if (!is_array($client_history)) {
            $client_history = [];
        }
    } catch (Exception $e) {
        $client_history = [];
    }
    
    // Recuperar historial de sesión PHP (persistente entre recargas)
    $session_key = 'pulso_chat_history_' . $courseid . '_' . $USER->id;
    $session_history = isset($SESSION->$session_key) ? $SESSION->$session_key : [];
    
    // Usar historial del cliente si tiene más mensajes, sino el de sesión
    $history = count($client_history) >= count($session_history) ? $client_history : $session_history;
    
    // Validar estructura de cada mensaje
    $history = array_filter($history, function($msg) {
        return is_array($msg) && isset($msg['role']) && isset($msg['content'])
            && in_array($msg['role'], ['user', 'assistant']);
    });
    $history = array_values($history);
    
    // Token budget: limitar historial a últimos 10 intercambios (20 mensajes)
    // Cada mensaje ~100-200 tokens, 20 msgs ≈ 2000-4000 tokens (dentro de budget)
    $max_history_messages = 20;
    if (count($history) > $max_history_messages) {
        $history = array_slice($history, -$max_history_messages);
    }
    
    // Truncar contenido de mensajes largos en historial (max 500 chars cada uno)
    $max_content_length = 500;
    foreach ($history as &$msg) {
        if (strlen($msg['content']) > $max_content_length) {
            $msg['content'] = substr($msg['content'], 0, $max_content_length) . '...[truncated]';
        }
    }
    unset($msg);

    // Evitar contradicciones: si hay contexto RAG actual, no reutilizar
    // respuestas antiguas del asistente que decían que no tenía acceso.
    if (!empty($rag_context)) {
        $history = array_values(array_filter($history, function($msg) {
            if (($msg['role'] ?? '') !== 'assistant') {
                return true;
            }
            $c = mb_strtolower((string)($msg['content'] ?? ''), 'UTF-8');
            if (strpos($c, 'no tengo acceso') !== false) return false;
            if (strpos($c, 'no dispongo de acceso') !== false) return false;
            if (strpos($c, 'no hay acceso') !== false) return false;
            if (strpos($c, 'falta de acceso') !== false) return false;
            return true;
        }));
    }

    // Direct Moodle-backed answer path for course/section/resource queries.
    $direct_query = $user_query;
    $qnorm = mb_strtolower(trim($user_query), 'UTF-8');
    // Detectar si el usuario pregunta por contenido específico de un documento (enunciado, problema, ejercicio...).
    $isContentSpecificQuery = (bool)preg_match('/enunciado|primer\s+problem[ao]|\bproblema\s+\d+|primer\s+ejercicio|ejercicio\s*\d+|mu[eé]strame\s+(el|la|los|las|un)\b|soluci[oó]n\s+del\b|dame\s+(un|el|la|los)\s+\w+|qu[eé]\s+preguntas?|pregunta\s+\d+/u', $qnorm);
    // Detectar una referencia implícita a un recurso/pdf discutido previamente.
    $isSummaryOfPrevious = (bool)preg_match('/resumen\s+(del|de\s+ese|de\s+este|de\s+el)\s+(pdf|recurso|archivo|documento)/u', $qnorm);
    $refersToKnownResource = (bool)preg_match('/\b(ese|este|el|del|de\s+ese|de\s+este)\s+(pdf|recurso|archivo)\b/u', $qnorm);
    $asksAboutPrevious = (bool)preg_match('/\b(en\s+qu[eé]\s+consiste|de\s+qu[eé]\s+(va|trata)|qu[eé]\s+(dice|contiene))\b/u', $qnorm);
    // Detectar referencia a una actividad discutida previamente: "este cuestionario", "ese quiz", "esta tarea", "este foro", etc.
    $refersToActivity = (bool)preg_match('/\b(este|ese|esta|esa|el|la|del|de\s+este|de\s+esta|de\s+ese|de\s+esa)\s+(cuestionario|quiz|examen|tarea|assignment|foro|forum|p[aá]gina|page|etiqueta|label|libro|book|url|enlace|actividad)\b/u', $qnorm);
    // Preguntas sobre una actividad sin especificar nombre: "cuántas preguntas tiene", "cuántos intentos", "cuántos alumnos lo han completado"
    $asksAboutActivity = (bool)preg_match('/\bcu[aá]ntas?\s+(preguntas?|intentos|alumnos|estudiantes)|qui[eé]n(es)?\s+(ha|han)\s+(completado|hecho|realizado|entregado)|nota\s+media|calificaci[oó]n\s+media|tiene\s+este|tiene\s+esta|tiempo\s+l[ií]mite/u', $qnorm);
    // "hazme un resumen" / "haz un resumen" / "resúmelo" sin especificar qué → buscar en historial.
    $bareSummaryRequest = (bool)preg_match('/\b(hazme|haz|dame|quiero)\s+(un\s+)?resum/u', $qnorm)
        || (preg_match('/\bresum/u', $qnorm) && !preg_match('/\b(secci[oó]n|seccion|curso)\b/u', $qnorm));
    // No usar history hint cuando el usuario ya especifica una sección concreta.
    $mentionsSection = (bool)preg_match('/\bsecci[oó]n\s+\d+|\bseccion\s+\d+|\ben\s+la\s+secci[oó]n|\ben\s+la\s+seccion/u', $qnorm);
    // No usar history hint cuando el usuario ya nombra un recurso concreto: "archivo spark", "pdf tema2", "resource spark", etc.
    $alreadyNamesResource = (bool)preg_match(
        '/\b(pdf|archivo|documento|recurso|resource)\s+(?!que\b|este\b|ese\b|esta\b|esa\b|del\b|de\b|la\b|el\b|las\b|los\b|un\b|una\b|anterior\b|previo\b|pasado\b|mismo\b)\S+/u',
        $qnorm
    );
    // También detectar cuando el query pide resumen DE un nombre concreto: "resumen de spark", "resumen del spark".
    if (!$alreadyNamesResource) {
        $alreadyNamesResource = (bool)preg_match(
            '/\bresum\w*\s+(de|del)\s+(?!este\b|ese\b|esta\b|esa\b|el\b|la\b|los\b|las\b|un\b|una\b|anterior\b|previo\b|curso\b|secci)\S+/u',
            $qnorm
        );
    }
    $needsHistoryHint = !$mentionsSection && !$alreadyNamesResource && ($isContentSpecificQuery || $isSummaryOfPrevious || $refersToKnownResource || $asksAboutPrevious || $bareSummaryRequest || $refersToActivity || $asksAboutActivity);

    if ($needsHistoryHint) {
        // Determinar qué tipo de recurso busca el usuario en el historial.
        $wantsPdf = (bool)preg_match('/\b(pdf|archivo|documento|recurso)\b/u', $qnorm);
        $wantsQuiz = (bool)preg_match('/\b(cuestionario|quiz|examen)\b/u', $qnorm);
        $wantsAssign = (bool)preg_match('/\b(tarea|assignment)\b/u', $qnorm);
        $wantsSpecificType = $wantsPdf || $wantsQuiz || $wantsAssign;

        // Función auxiliar para extraer info de un mensaje del historial.
        $extractFromMsg = function($msg) {
            if (!is_array($msg) || ($msg['role'] ?? '') !== 'assistant') {
                return null;
            }
            $raw = (string)($msg['content'] ?? '');
            $decoded = json_decode($raw, true);
            $searchText = is_array($decoded) && isset($decoded['content'])
                ? (string)$decoded['content']
                : $raw;

            $resourceName = '';
            $sectionName = '';
            $type = 'resource'; // por defecto es pdf/recurso

            if (preg_match('/^Recurso:\s*(.+)$/mi', $searchText, $mres)) {
                $resourceName = trim((string)$mres[1]);
                $type = 'resource';
            }
            if (preg_match('/^Seccion:\s*(.+)$/mi', $searchText, $msec)) {
                $sectionName = trim((string)$msec[1]);
            }
            // Fallback: extraer del título JSON.
            if ($resourceName === '' && is_array($decoded) && isset($decoded['title'])) {
                $title = (string)$decoded['title'];
                if (preg_match('/^Cuestionario:\s*(.+)$/i', $title, $mqt)) {
                    $resourceName = trim($mqt[1]);
                    $type = 'quiz';
                } else if (preg_match('/^Tarea:\s*(.+)$/i', $title, $mqt)) {
                    $resourceName = trim($mqt[1]);
                    $type = 'assign';
                } else if (preg_match('/^Foro:\s*(.+)$/i', $title, $mqt)) {
                    $resourceName = trim($mqt[1]);
                    $type = 'forum';
                } else if (preg_match('/^P[aá]gina:\s*(.+)$/i', $title, $mqt)) {
                    $resourceName = trim($mqt[1]);
                    $type = 'page';
                } else if (preg_match('/^URL:\s*(.+)$/i', $title, $mqt)) {
                    $resourceName = trim($mqt[1]);
                    $type = 'url';
                } else if (preg_match('/^Libro:\s*(.+)$/i', $title, $mqt)) {
                    $resourceName = trim($mqt[1]);
                    $type = 'book';
                } else if (preg_match('/^Carpeta:\s*(.+)$/i', $title, $mqt)) {
                    $resourceName = trim($mqt[1]);
                    $type = 'folder';
                } else if (preg_match('/^Recurso\s+(.+)$/i', $title, $mtitle)) {
                    $resourceName = trim($mtitle[1]);
                    $type = 'resource';
                } else if (preg_match('/^Resumen del recurso\s+(.+)$/i', $title, $mtitle)) {
                    $resourceName = trim($mtitle[1]);
                    $type = 'resource';
                }
            }
            if ($resourceName === '') {
                return null;
            }
            return ['name' => $resourceName, 'section' => $sectionName, 'type' => $type];
        };

        $foundResource = null;
        $fallbackResource = null;

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $info = $extractFromMsg($history[$i] ?? null);
            if ($info === null) {
                continue;
            }
            // Guardar primer fallback (por si no hay match de tipo).
            if ($fallbackResource === null) {
                $fallbackResource = $info;
            }
            // Si el usuario pide un tipo específico, buscar solo ese tipo.
            if ($wantsSpecificType) {
                if ($wantsPdf && $info['type'] === 'resource') {
                    $foundResource = $info;
                    break;
                }
                if ($wantsQuiz && $info['type'] === 'quiz') {
                    $foundResource = $info;
                    break;
                }
                if ($wantsAssign && $info['type'] === 'assign') {
                    $foundResource = $info;
                    break;
                }
                // Tipo no coincide, seguir buscando.
                continue;
            }
            // Sin tipo específico → tomar el primero encontrado.
            $foundResource = $info;
            break;
        }

        // Si no se encontró del tipo deseado, usar fallback.
        if ($foundResource === null && $fallbackResource !== null) {
            $foundResource = $fallbackResource;
        }

        if ($foundResource !== null) {
            $resourceName = $foundResource['name'];
            $sectionName = $foundResource['section'];
            $resourceType = $foundResource['type'];
            if ($isContentSpecificQuery && $resourceType === 'resource') {
                $direct_query .= ' del pdf ' . $resourceName;
            } else if ($resourceType === 'quiz') {
                $direct_query .= ' cuestionario ' . $resourceName;
            } else if ($resourceType === 'assign') {
                $direct_query .= ' tarea ' . $resourceName;
            } else {
                $direct_query .= ' ' . $resourceName;
            }
            if ($sectionName !== '' && strpos($qnorm, 'seccion') === false && strpos($qnorm, 'sección') === false) {
                $direct_query .= ' seccion ' . $sectionName;
            }
        }
    }

    $direct_course_answer = rag_retriever::resolve_direct_course_query($courseid, $direct_query);
    if ($direct_course_answer !== null) {
        // Modo contenido: responder pregunta concreta sobre el texto de un PDF.
        if (!empty($direct_course_answer['content_mode']) && !empty($direct_course_answer['raw_content_source'])) {
            try {
                $contentConnector = new openai_connector();
                $aiAnswer = $contentConnector->answer_document_question(
                    (string)$direct_course_answer['raw_content_source'],
                    $user_query,
                    500
                );
                if ($aiAnswer !== '') {
                    $direct_course_answer['content'] = $aiAnswer;
                }
            } catch (Exception $e) {
                // Mantener el placeholder si la llamada a la IA falla.
            }
            unset($direct_course_answer['raw_content_source']);
        }

        if (!empty($direct_course_answer['summary_mode']) && !empty($direct_course_answer['raw_summary_source'])) {
            try {
                $summaryConnector = new openai_connector();
                $aiSummary = $summaryConnector->summarize_document_text(
                    (string)$direct_course_answer['raw_summary_source'],
                    $user_query,
                    220
                );

                if ($aiSummary !== '') {
                    $direct_course_answer['content'] = 'Resumen: ' . $aiSummary;
                }
            } catch (Exception $e) {
                // Keep deterministic fallback summary if AI summarization fails.
            }

            unset($direct_course_answer['raw_summary_source']);
        }

        $answer = json_encode($direct_course_answer, JSON_UNESCAPED_UNICODE);
        $isDocumentAnswer = !empty($direct_course_answer['content_mode']) || !empty($direct_course_answer['summary_mode']);

        $followup_questions = [];
        $answerTitle = $direct_course_answer['title'] ?? '';
        $isQuizAnswer = (bool)preg_match('/^Cuestionario:/i', $answerTitle);
        $isAssignAnswer = (bool)preg_match('/^Tarea:/i', $answerTitle);
        $isForumAnswer = (bool)preg_match('/^Foro:/i', $answerTitle);
        $isGenericActivity = (bool)preg_match('/^(Página|URL|Libro|Carpeta|Glosario|Wiki|Encuesta|Feedback|Lección):/i', $answerTitle);

        if ($isDocumentAnswer && $isContentSpecificQuery) {
            $followup_questions = [
                '¿Puedes mostrarme el siguiente apartado?',
                '¿Qué otros contenidos tiene este archivo?',
                '¿Puedes hacer un resumen del archivo completo?'
            ];
        } else if ($isQuizAnswer) {
            $followup_questions = [
                '¿Cuántas preguntas tiene este cuestionario?',
                '¿Puedes mostrarme la primera pregunta?',
                '¿Cuántos alumnos han completado este cuestionario?'
            ];
        } else if ($isAssignAnswer) {
            $followup_questions = [
                '¿Cuántos alumnos han entregado esta tarea?',
                '¿Cuál es la calificación media de esta tarea?',
                '¿Qué otras tareas hay en el curso?'
            ];
        } else if ($isForumAnswer) {
            $followup_questions = [
                '¿Cuántas discusiones tiene este foro?',
                '¿Qué otros foros hay en el curso?',
                '¿Qué contenidos hay en este curso?'
            ];
        } else if ($isGenericActivity) {
            $followup_questions = [
                '¿Cuántos alumnos han completado esta actividad?',
                '¿Qué otros contenidos hay en esta sección?',
                '¿Qué contenidos hay en este curso?'
            ];
        } else if ($isDocumentAnswer || preg_match('/pdf|recurso|archivo|resource/u', $qnorm)) {
            // Extraer nombre del recurso para preguntas contextualizadas.
            $resName = '';
            if (preg_match('/^Resumen del recurso\s+(.+)$/i', $answerTitle, $_rn)) {
                $resName = trim($_rn[1]);
            } else if (preg_match('/^Recurso\s+(.+)$/i', $answerTitle, $_rn)) {
                $resName = trim($_rn[1]);
            }
            $refLabel = $resName !== '' ? '"' . $resName . '"' : 'este archivo';
            $followup_questions = [
                '¿Qué temas principales trata ' . $refLabel . '?',
                '¿Puedes explicarme con más detalle el contenido de ' . $refLabel . '?',
                '¿Quieres un resumen más corto en 2 líneas?'
            ];
        } else if (strpos($qnorm, 'seccion') !== false || strpos($qnorm, 'sección') !== false) {
            $followup_questions = [
                '¿Cuántas secciones tiene este curso?',
                '¿Cómo se llama este curso?',
                '¿Qué actividades hay en otra sección?'
            ];
        } else if (preg_match('/contenidos?.*curso|qu[eé]\s+hay\s+en\s+(este|el)\s+curso/u', $qnorm)) {
            $followup_questions = [
                '¿Qué contenidos hay en una sección concreta?',
                '¿Puedes darme un resumen de algún PDF del curso?',
                '¿Cuántas secciones tiene este curso?'
            ];
        } else {
            $followup_questions = [
                '¿Cuántas secciones tiene este curso?',
                '¿Qué contenidos hay dentro de una sección concreta?',
                '¿Cómo se llama este curso?'
            ];
        }

        $history[] = ['role' => 'user', 'content' => $user_query];
        $history[] = ['role' => 'assistant', 'content' => $answer];
        if (count($history) > $max_history_messages) {
            $history = array_slice($history, -$max_history_messages);
        }
        $SESSION->$session_key = $history;

        $response = [
            'success' => true,
            'message' => 'Query estructural/rescurso resuelto directamente desde Moodle',
            'answer' => $answer,
            'tokens_used' => 0,
            'model' => 'direct-moodle-query',
            'schema_valid' => true,
            'schema_data' => $direct_course_answer,
            'rag_diagnostics' => $rag_diagnostics,
            'followup_questions' => $followup_questions,
            'history_length' => count($history),
            'course_id' => $courseid,
            'course_name' => $course_context['course_context']['course_name'] ?? $course->fullname,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    // ============================================================
    // 4. LLAMAR A OPENAI CON GPT-4 + CONTEXTO DINÁMICO
    // ============================================================
    
    $connector = new openai_connector();
    $ai_response = $connector->send_analytics_query_with_schema(
        $user_query,
        $course_context,
        $history,
        800,  // max_tokens para respuesta JSON
        $system_prompt
    );
    
    if (!$ai_response) {
        throw new Exception('No response from OpenAI API');
    }
    
    // ============================================================
    // 5. PARSEAR Y VALIDAR RESPUESTA JSON
    // ============================================================
    
    $answer = $ai_response['answer'];

    // Strip markdown code fences the AI sometimes wraps around JSON.
    $answer = trim($answer);
    if (preg_match('/^\s*```[a-z]*\s*/i', $answer)) {
        $answer = preg_replace('/^\s*```[a-z]*\s*/i', '', $answer);
        $answer = preg_replace('/\s*```\s*$/i', '', $answer);
        $answer = trim($answer);
    }
    
    // Intentar parsear como JSON
    $schema_data = system_prompt_designer::validate_response($answer);
    
    // ============================================================
    // 6. GENERAR PREGUNTAS DE SEGUIMIENTO (T2.4.12)
    // ============================================================
    
    $followup_questions = [];
    try {
        $followup_questions = $connector->generate_followup_questions(
            $user_query,
            $answer,
            $course_context
        );
        error_log('Follow-up questions generated: ' . count($followup_questions) . ' questions');
    } catch (Exception $e) {
        // Si falla la generación de preguntas, continuar sin ellas
        error_log('Follow-up questions generation failed: ' . $e->getMessage());
    }
    
    if (empty($followup_questions)) {
        error_log('No follow-up questions were generated or returned');
    }
    
    // ============================================================
    // 7. RETORNAR RESPUESTA AL CLIENTE
    // ============================================================
    
    // ============================================================
    // T2.5.3: GUARDAR HISTORIAL EN SESIÓN PHP
    // ============================================================
    
    // Agregar el intercambio actual al historial
    $history[] = ['role' => 'user', 'content' => $user_query];
    $history[] = ['role' => 'assistant', 'content' => $answer];
    
    // Aplicar mismo límite antes de guardar
    if (count($history) > $max_history_messages) {
        $history = array_slice($history, -$max_history_messages);
    }
    
    // Persistir en sesión PHP
    $SESSION->$session_key = $history;
    
    $response = [
        'success' => true,
        'message' => 'Query procesado exitosamente',
        'answer' => $answer,
        'tokens_used' => $ai_response['tokens_used'] ?? 0,
        'model' => $ai_response['model'] ?? 'gpt-4o',
        'schema_valid' => $schema_data ? true : false,
        'schema_data' => $schema_data,
        'rag_diagnostics' => $rag_diagnostics,
        'followup_questions' => $followup_questions,
        'history_length' => count($history),
        'course_id' => $courseid,
        'course_name' => $course_context['course_context']['course_name'] ?? $course->fullname,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
} catch (\Throwable $e) {
    // Manejo de errores
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'answer' => null,
        'tokens_used' => 0,
        'error_code' => get_class($e)
    ];
}

// Enviar respuesta JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
