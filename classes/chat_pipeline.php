<?php
/**
 * Chat pipeline: shared request-processing logic for the chat endpoints.
 *
 * Hosts everything api_chat.php and api_chat_stream.php have in common:
 *  - Course enablement check
 *  - Analytics context retrieval (with short-lived MUC cache + row caps)
 *  - RAG retrieval
 *  - Conversation history assembly and hygiene
 *  - History-hint resolution ("ese pdf", "este cuestionario", ...)
 *  - Direct Moodle-backed answer resolution (with optional streaming callback)
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/data_retriever.php');
require_once(__DIR__ . '/anthropic_connector.php');
require_once(__DIR__ . '/system_prompt_designer.php');
require_once(__DIR__ . '/rag_retriever.php');

class chat_pipeline {

    /** Token budget: limitar historial a últimos 10 intercambios (20 mensajes). */
    const MAX_HISTORY_MESSAGES = 20;

    /** Truncar contenido de mensajes largos en historial (max 500 chars cada uno). */
    const MAX_HISTORY_CONTENT_LENGTH = 500;

    /** Seconds the unified course context stays valid in the application cache. */
    const CONTEXT_CACHE_TTL = 120;

    /** Cap per analytics array injected into the prompt (protects the context window). */
    const MAX_ANALYTICS_ROWS = 250;

    /**
     * T2.6.1: Verificar si Pulso está habilitado para este curso.
     *
     * @param int $courseid
     * @throws \Exception si está deshabilitado
     */
    public static function check_enabled(int $courseid): void {
        $course_enabled = get_config('block_pulso', 'enabled_course_' . $courseid);
        $default_enabled = get_config('block_pulso', 'enabled_by_default');
        if ($course_enabled !== false) {
            $is_enabled = (bool)$course_enabled;
        } else {
            $is_enabled = ($default_enabled === false) ? true : (bool)$default_enabled;
        }
        if (!$is_enabled) {
            throw new \Exception('Pulso is disabled for this course');
        }
    }

    /**
     * Obtener contexto del curso (F2.2 - data_retriever) con cache corta y
     * filtrado por toggles de categorías de datos (T2.6.2).
     *
     * @param int $courseid
     * @return array
     * @throws \Exception si falla la recuperación
     */
    public static function get_course_context(int $courseid): array {
        $course_context = null;
        $cache = null;

        // Short-lived application cache: analytics context is course-wide and
        // rebuilding it runs several heavy queries on every chat message.
        try {
            $cache = \cache::make('block_pulso', 'coursecontext');
            $cached = $cache->get($courseid);
            if (is_array($cached)
                    && isset($cached['time'], $cached['payload'])
                    && (time() - $cached['time']) < self::CONTEXT_CACHE_TTL) {
                $course_context = $cached['payload'];
            }
        } catch (\Throwable $e) {
            // Cache definition not registered yet (plugin not upgraded) — retrieve directly.
            $cache = null;
        }

        if ($course_context === null) {
            $retriever = new data_retriever();
            $course_context = $retriever->get_unified_course_context($courseid, 7);
            if ($cache !== null && ($course_context['status'] ?? '') === 'success') {
                try {
                    $cache->set($courseid, ['time' => time(), 'payload' => $course_context]);
                } catch (\Throwable $e) {
                    // Never break the chat because of a cache store issue.
                }
            }
        }

        if (($course_context['status'] ?? '') !== 'success') {
            throw new \Exception('Failed to retrieve course data: ' . ($course_context['message'] ?? 'unknown'));
        }

        // T2.6.2: Respetar toggles de categorías de datos (applied AFTER caching,
        // so the cached payload stays toggle-independent).
        $data_completion = get_config('block_pulso', 'data_completion');
        $data_grades = get_config('block_pulso', 'data_grades');
        $data_logs = get_config('block_pulso', 'data_logs');

        if (isset($course_context['analytics'])) {
            if ($data_completion === '0') {
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

            // Cap each analytics array so huge courses can't blow up the prompt
            // (token cost + context-window limits). Counts keep the real totals
            // and a *_truncated flag tells the model data was capped.
            foreach (['course_completions', 'grades_and_quizzes', 'module_completions'] as $key) {
                if (isset($course_context['analytics'][$key])
                        && is_array($course_context['analytics'][$key])
                        && count($course_context['analytics'][$key]) > self::MAX_ANALYTICS_ROWS) {
                    $course_context['analytics'][$key] = array_slice(
                        $course_context['analytics'][$key], 0, self::MAX_ANALYTICS_ROWS
                    );
                    $course_context['analytics'][$key . '_truncated'] = true;
                }
            }
        }

        // Privacidad (RGPD/LOPD): solo quien puede calificar el curso ve datos
        // identificables por alumno. Se comprueba SIEMPRE aqui (nunca en la cache
        // compartida por curso) porque depende del usuario de la peticion actual.
        $permctx = \context_course::instance($courseid);
        $canseegrades = has_capability('moodle/grade:viewall', $permctx);
        $course_context = self::apply_privacy_redaction($course_context, $canseegrades);

        return $course_context;
    }

    /**
     * Sustituye los datos identificables por alumno por agregados cuando el
     * usuario actual no tiene permiso para ver notas individuales
     * (moodle/grade:viewall). Con permiso, se deja el detalle por alumno y se
     * añade un ranking pre-calculado y ordenado.
     *
     * @param array $course_context
     * @param bool  $canseegrades
     * @return array
     */
    private static function apply_privacy_redaction(array $course_context, bool $canseegrades): array {
        if (!isset($course_context['analytics'])) {
            return $course_context;
        }

        $course_context['analytics']['individual_data_visible'] = $canseegrades;

        if ($canseegrades) {
            $course_context['analytics']['student_ranking_by_average_grade'] =
                self::build_student_ranking($course_context['analytics']['grades_and_quizzes'] ?? []);
            return $course_context;
        }

        $course_context['analytics']['course_completions'] =
            self::aggregate_completions($course_context['analytics']['course_completions'] ?? []);
        $course_context['analytics']['grades_and_quizzes'] =
            self::aggregate_grades($course_context['analytics']['grades_and_quizzes'] ?? []);
        $course_context['analytics']['module_completions'] =
            self::aggregate_module_completions($course_context['analytics']['module_completions'] ?? []);
        if (isset($course_context['access_logs']) && is_array($course_context['access_logs'])) {
            $course_context['access_logs'] = self::aggregate_access_logs($course_context['access_logs']);
        }

        return $course_context;
    }

    /**
     * Ranking de alumnos por nota media (agregada entre todos sus items
     * calificados), ordenado de mayor a menor. Solo se usa cuando el usuario
     * tiene permiso para ver notas individuales.
     *
     * @param array $gradeRows
     * @return array
     */
    private static function build_student_ranking(array $gradeRows): array {
        $byStudent = [];
        foreach ($gradeRows as $row) {
            $uid = $row['userid'] ?? null;
            $pct = $row['percentage'] ?? null;
            if ($uid === null || $pct === null) {
                continue;
            }
            if (!isset($byStudent[$uid])) {
                $byStudent[$uid] = [
                    'name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
                    'sum' => 0.0,
                    'count' => 0,
                ];
            }
            $byStudent[$uid]['sum'] += (float)$pct;
            $byStudent[$uid]['count']++;
        }

        $ranking = [];
        foreach ($byStudent as $s) {
            $ranking[] = [
                'name' => $s['name'],
                'average_percentage' => round($s['sum'] / $s['count'], 2),
            ];
        }
        usort($ranking, function ($a, $b) {
            return $b['average_percentage'] <=> $a['average_percentage'];
        });

        return $ranking;
    }

    /**
     * @param array $rows
     * @return array
     */
    private static function aggregate_completions(array $rows): array {
        $total = count($rows);
        $completed = 0;
        foreach ($rows as $row) {
            if (!empty($row['is_completed'])) {
                $completed++;
            }
        }
        return [
            'aggregate_only' => true,
            'total_students' => $total,
            'completed_count' => $completed,
            'completed_percentage' => $total > 0 ? round(($completed / $total) * 100, 2) : null,
        ];
    }

    /**
     * @param array $rows
     * @return array
     */
    private static function aggregate_grades(array $rows): array {
        $total = count($rows);
        $passed = 0;
        $withGrade = 0;
        $sumPercentage = 0.0;
        foreach ($rows as $row) {
            $pct = $row['percentage'] ?? null;
            if ($pct !== null) {
                $sumPercentage += (float)$pct;
                $withGrade++;
            }
            if (!empty($row['is_passed'])) {
                $passed++;
            }
        }
        return [
            'aggregate_only' => true,
            'total_grade_records' => $total,
            'average_percentage' => $withGrade > 0 ? round($sumPercentage / $withGrade, 2) : null,
            'passed_count' => $passed,
            'passed_percentage' => $total > 0 ? round(($passed / $total) * 100, 2) : null,
        ];
    }

    /**
     * @param array $rows
     * @return array
     */
    private static function aggregate_module_completions(array $rows): array {
        $total = count($rows);
        $byStatus = [];
        foreach ($rows as $row) {
            $status = $row['completion_status'] ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }
        return [
            'aggregate_only' => true,
            'total_records' => $total,
            'by_status' => $byStatus,
        ];
    }

    /**
     * @param array $logs
     * @return array
     */
    private static function aggregate_access_logs(array $logs): array {
        $distinctUsers = [];
        foreach ($logs as $log) {
            if (isset($log['user_id'])) {
                $distinctUsers[$log['user_id']] = true;
            }
        }
        return [
            'aggregate_only' => true,
            'total_entries' => count($logs),
            'distinct_active_students' => count($distinctUsers),
        ];
    }

    /**
     * RAG: Retrieve relevant course content chunks.
     *
     * @param int $courseid
     * @param string $user_query
     * @return array ['context' => string, 'diagnostics' => array]
     */
    public static function get_rag(int $courseid, string $user_query): array {
        $rag_result = rag_retriever::get_context_and_diagnostics_for_query($courseid, $user_query);
        return [
            'context' => $rag_result['context'] ?? '',
            'diagnostics' => $rag_result['diagnostics'] ?? []
        ];
    }

    /**
     * Preparar historial de conversación (T2.5.3): merge cliente/sesión,
     * validación de estructura, truncado y filtro de contradicciones RAG.
     *
     * NOTE: reads $SESSION; call BEFORE \core\session\manager::write_close().
     *
     * @param int $courseid
     * @param string $conversation_history JSON enviado por el cliente
     * @param string $rag_context
     * @return array
     */
    public static function prepare_history(int $courseid, string $conversation_history, string $rag_context): array {
        global $SESSION, $USER;

        $client_history = json_decode($conversation_history, true) ?? [];
        if (!is_array($client_history)) {
            $client_history = [];
        }

        // Recuperar historial de sesión PHP (persistente entre recargas).
        $session_key = self::session_key($courseid);
        $session_history = isset($SESSION->$session_key) ? $SESSION->$session_key : [];
        if (!is_array($session_history)) {
            $session_history = [];
        }

        // Usar historial del cliente si tiene más mensajes, sino el de sesión.
        $history = count($client_history) >= count($session_history) ? $client_history : $session_history;

        // Validar estructura de cada mensaje.
        $history = array_filter($history, function($msg) {
            return is_array($msg) && isset($msg['role']) && isset($msg['content'])
                && in_array($msg['role'], ['user', 'assistant']);
        });
        $history = array_values($history);

        if (count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        }

        // Truncar contenido de mensajes largos en historial.
        foreach ($history as &$msg) {
            if (strlen($msg['content']) > self::MAX_HISTORY_CONTENT_LENGTH) {
                $msg['content'] = substr($msg['content'], 0, self::MAX_HISTORY_CONTENT_LENGTH) . '...[truncated]';
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

        return $history;
    }

    /**
     * Agregar el intercambio actual al historial, aplicar límite y persistir en sesión.
     * (La escritura en $SESSION es un no-op si la sesión ya fue cerrada con
     * write_close(); el cliente mantiene su propia copia en sessionStorage.)
     *
     * @param int $courseid
     * @param array $history
     * @param string $user_query
     * @param string $answer
     * @return array historial actualizado
     */
    public static function save_history(int $courseid, array $history, string $user_query, string $answer): array {
        global $SESSION;

        $history[] = ['role' => 'user', 'content' => $user_query];
        $history[] = ['role' => 'assistant', 'content' => $answer];
        if (count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        }

        $session_key = self::session_key($courseid);
        $SESSION->$session_key = $history;

        return $history;
    }

    /**
     * @param int $courseid
     * @return string
     */
    private static function session_key(int $courseid): string {
        global $USER;
        return 'pulso_chat_history_' . $courseid . '_' . $USER->id;
    }

    /**
     * Construir el query "directo" enriquecido con pistas del historial
     * ("ese pdf", "este cuestionario", "hazme un resumen"...).
     *
     * @param string $user_query
     * @param array $history
     * @return array ['direct_query' => string, 'qnorm' => string, 'is_content_specific' => bool]
     */
    public static function build_direct_query(string $user_query, array $history): array {
        $direct_query = $user_query;
        $qnorm = mb_strtolower(trim($user_query), 'UTF-8');
        // Detectar si el usuario pregunta por contenido específico de un documento (enunciado, problema, ejercicio...).
        $isContentSpecificQuery = (bool)preg_match('/enunciado|primer\s+problem[ao]|\bproblema\s+\d+|primer\s+ejercicio|ejercicio\s*\d+|mu[eé]strame\s+(el|la|los|las|un)\b|soluci[oó]n\s+del\b|dame\s+(un|el|la|los)\s+\w+|qu[eé]\s+preguntas?|pregunta\s+\d+/u', $qnorm);
        // Referencias explícitas al curso o a una sección: en esos casos la
        // pregunta NO es sobre un recurso previo del historial.
        $refersToCourse = (bool)preg_match('/\b(secci[oó]n|seccion|curso)\b/u', $qnorm);
        // Detectar una referencia implícita a un recurso/pdf discutido previamente.
        $isSummaryOfPrevious = (bool)preg_match('/resumen\s+(del|de\s+ese|de\s+este|de\s+el)\s+(pdf|recurso|archivo|documento)/u', $qnorm);
        $refersToKnownResource = (bool)preg_match('/\b(ese|este|el|del|de\s+ese|de\s+este)\s+(pdf|recurso|archivo)\b/u', $qnorm);
        $asksAboutPrevious = !$refersToCourse
            && (bool)preg_match('/\b(en\s+qu[eé]\s+consiste|de\s+qu[eé]\s+(va|trata)|qu[eé]\s+(dice|contiene))\b/u', $qnorm);
        // Detectar referencia a una actividad discutida previamente: "este cuestionario", "ese quiz", "esta tarea", "este foro", etc.
        $refersToActivity = (bool)preg_match('/\b(este|ese|esta|esa|el|la|del|de\s+este|de\s+esta|de\s+ese|de\s+esa)\s+(cuestionario|quiz|examen|tarea|assignment|foro|forum|p[aá]gina|page|etiqueta|label|libro|book|url|enlace|actividad)\b/u', $qnorm);
        // Preguntas sobre una actividad sin especificar nombre: "cuántas preguntas tiene", "cuántos intentos", "cuántos alumnos lo han completado"
        $asksAboutActivity = (bool)preg_match('/\bcu[aá]ntas?\s+(preguntas?|intentos|alumnos|estudiantes)|qui[eé]n(es)?\s+(ha|han)\s+(completado|hecho|realizado|entregado)|nota\s+media|calificaci[oó]n\s+media|tiene\s+este|tiene\s+esta|tiempo\s+l[ií]mite/u', $qnorm);
        // "hazme un resumen" / "haz un resumen" / "resúmelo" sin especificar qué → buscar en historial.
        // Only when the query does NOT already refer to the course explicitly.
        $bareSummaryRequest = !$refersToCourse && (
            (bool)preg_match('/\b(hazme|haz|dame|quiero)\s+(un\s+)?resum/u', $qnorm)
            || (bool)preg_match('/\bresum/u', $qnorm)
        );
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
        // También detectar cuando el usuario nombra otra ACTIVIDAD concreta (no solo recurso):
        // "cuestionario tipo test", "tarea de matemáticas", "foro de dudas"... — prioridad al
        // nombre citado en el propio mensaje, no arrastrar el recurso del historial.
        if (!$alreadyNamesResource) {
            $alreadyNamesResource = (bool)preg_match(
                '/\b(cuestionario|quiz|examen|tarea|assignment|foro|forum)\s+(?!que\b|este\b|ese\b|esta\b|esa\b|del\b|de\b|la\b|el\b|las\b|los\b|un\b|una\b|anterior\b|previo\b|pasado\b|mismo\b)\S+/u',
                $qnorm
            );
        }
        // Pregunta de analitica de CURSO (nota media, matriculados, en riesgo, ranking...):
        // nunca debe arrastrar un recurso visto en un turno anterior.
        $isAnalyticsQuery = rag_retriever::is_course_analytics_query($qnorm);
        // Limitar el hint a continuaciones CLARAS: referencia anaforica explicita
        // ("ese", "este", "el anterior", "de ese"...) — si no hay, no se infiere continuidad.
        $hasAnaphoricReference = (bool)preg_match(
            '/\b(ese|esa|eso|este|esta|esto|el\s+mismo|la\s+misma|el\s+anterior|la\s+anterior' .
            '|lo\s+anterior|anteriormente\s+mencionad\w*|de\s+ese|de\s+esta|de\s+este|de\s+esa' .
            '|del\s+anterior|de\s+la\s+anterior)\b/u',
            $qnorm
        );
        // Referencia ORDINAL a la posicion de un recurso ya visto ("el primero",
        // "el segundo", "el ultimo"...) — cuenta tambien como continuacion clara.
        $hasOrdinalReference = self::detect_ordinal_reference($qnorm) !== null;
        $needsHistoryHint = !$isAnalyticsQuery
            && !$mentionsSection
            && !$alreadyNamesResource
            && ($hasAnaphoricReference || $asksAboutPrevious || $hasOrdinalReference)
            && ($isContentSpecificQuery || $isSummaryOfPrevious || $refersToKnownResource || $asksAboutPrevious || $bareSummaryRequest || $refersToActivity || $asksAboutActivity || $hasOrdinalReference);

        if ($needsHistoryHint) {
            $foundResource = self::find_resource_in_history($history, $qnorm);
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

        return [
            'direct_query' => $direct_query,
            'qnorm' => $qnorm,
            'is_content_specific' => $isContentSpecificQuery
        ];
    }

    /**
     * Buscar en el historial el último recurso/actividad mencionado por el asistente.
     *
     * @param array $history
     * @param string $qnorm
     * @return array|null ['name' =>, 'section' =>, 'type' =>]
     */
    private static function find_resource_in_history(array $history, string $qnorm): ?array {
        // Determinar qué tipo de recurso busca el usuario en el historial.
        $wantsPdf = (bool)preg_match('/\b(pdf|archivo|documento|recurso)\b/u', $qnorm);
        $wantsQuiz = (bool)preg_match('/\b(cuestionario|quiz|examen)\b/u', $qnorm);
        $wantsAssign = (bool)preg_match('/\b(tarea|assignment)\b/u', $qnorm);
        $wantsSpecificType = $wantsPdf || $wantsQuiz || $wantsAssign;

        // Referencia por POSICION ("el primero", "el segundo", "el ultimo"...):
        // resolver sobre la lista ordenada de recursos distintos, no por "mas reciente".
        $ordinal = self::detect_ordinal_reference($qnorm);
        if ($ordinal !== null) {
            $ordered = self::list_distinct_resources_in_history($history, $wantsPdf, $wantsQuiz, $wantsAssign);
            if (empty($ordered)) {
                return null;
            }
            $idx = $ordinal['fromEnd'] ? (count($ordered) - $ordinal['n']) : ($ordinal['n'] - 1);
            return $ordered[$idx] ?? null;
        }

        $foundResource = null;
        $fallbackResource = null;

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $info = self::extract_resource_from_message($history[$i] ?? null);
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

        return $foundResource;
    }

    /**
     * Detecta una referencia ORDINAL a la posicion de un recurso en el historial
     * ("el primero", "el segundo", "el ultimo", "el penultimo"...). Excluye los
     * ordinales que se refieren a contenido DENTRO de un documento ya resuelto
     * ("el primer problema", "el segundo ejercicio", "la primera pregunta") — esos
     * no son recursos distintos, son posiciones dentro del recurso ya identificado.
     *
     * @param string $qnorm
     * @return array|null ['fromEnd' => bool, 'n' => int] posicion 1-based
     */
    private static function detect_ordinal_reference(string $qnorm): ?array {
        if (!preg_match(
            '/\b(primer[oa]?|segund[oa]|tercer[oa]?|cuart[oa]|quint[oa]|[uú]ltim[oa]|pen[uú]ltim[oa])\b(?!\s*(problema|ejercicio|pregunta))/u',
            $qnorm,
            $m
        )) {
            return null;
        }

        $word = $m[1];
        $ordinalMap = [
            'primer' => 1, 'primero' => 1, 'primera' => 1,
            'segundo' => 2, 'segunda' => 2,
            'tercer' => 3, 'tercero' => 3, 'tercera' => 3,
            'cuarto' => 4, 'cuarta' => 4,
            'quinto' => 5, 'quinta' => 5,
        ];
        if (isset($ordinalMap[$word])) {
            return ['fromEnd' => false, 'n' => $ordinalMap[$word]];
        }
        if (in_array($word, ['ultimo', 'último', 'ultima', 'última'], true)) {
            return ['fromEnd' => true, 'n' => 1];
        }
        if (in_array($word, ['penultimo', 'penúltimo', 'penultima', 'penúltima'], true)) {
            return ['fromEnd' => true, 'n' => 2];
        }
        return null;
    }

    /**
     * Lista, en orden cronologico y sin duplicados, los recursos/actividades
     * mencionados en el historial — usada para resolver referencias ordinales
     * ("el primero", "el segundo", "el ultimo").
     *
     * @param array $history
     * @param bool $wantsPdf
     * @param bool $wantsQuiz
     * @param bool $wantsAssign
     * @return array lista de ['name' =>, 'section' =>, 'type' =>]
     */
    private static function list_distinct_resources_in_history(array $history, bool $wantsPdf, bool $wantsQuiz, bool $wantsAssign): array {
        $wantsSpecificType = $wantsPdf || $wantsQuiz || $wantsAssign;
        $seen = [];
        $ordered = [];
        foreach ($history as $msg) {
            $info = self::extract_resource_from_message($msg);
            if ($info === null) {
                continue;
            }
            if ($wantsSpecificType) {
                if ($wantsPdf && $info['type'] !== 'resource') {
                    continue;
                }
                if ($wantsQuiz && $info['type'] !== 'quiz') {
                    continue;
                }
                if ($wantsAssign && $info['type'] !== 'assign') {
                    continue;
                }
            }
            $key = $info['type'] . '|' . mb_strtolower($info['name'], 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ordered[] = $info;
        }
        return $ordered;
    }

    /**
     * Extraer info de recurso/actividad de un mensaje del historial.
     *
     * @param mixed $msg
     * @return array|null
     */
    private static function extract_resource_from_message($msg): ?array {
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
    }

    /**
     * Direct Moodle-backed answer path for course/section/resource queries.
     *
     * @param int $courseid
     * @param string $user_query   Pregunta original del usuario
     * @param array $qinfo         Output of build_direct_query()
     * @param callable|null $ondelta Optional callback fn(string $textdelta) — when
     *                              provided, the AI sub-calls (document QA/summary)
     *                              stream their tokens through it.
     * @return array|null ['answer' => string(json), 'schema_data' => array, 'followup_questions' => array]
     */
    public static function resolve_direct_answer(int $courseid, string $user_query, array $qinfo, ?callable $ondelta = null): ?array {
        $direct_course_answer = rag_retriever::resolve_direct_course_query($courseid, $qinfo['direct_query']);
        if ($direct_course_answer === null) {
            return null;
        }

        $qnorm = $qinfo['qnorm'];
        $isContentSpecificQuery = $qinfo['is_content_specific'];

        // Modo contenido: responder pregunta concreta sobre el texto de un PDF.
        if (!empty($direct_course_answer['content_mode']) && !empty($direct_course_answer['raw_content_source'])) {
            try {
                $contentConnector = new anthropic_connector();
                $aiAnswer = $contentConnector->answer_document_question(
                    (string)$direct_course_answer['raw_content_source'],
                    $user_query,
                    500,
                    $ondelta
                );
                if ($aiAnswer !== '') {
                    $direct_course_answer['content'] = $aiAnswer;
                }
            } catch (\Exception $e) {
                // Mantener el placeholder si la llamada a la IA falla.
            }
            unset($direct_course_answer['raw_content_source']);
        }

        if (!empty($direct_course_answer['summary_mode']) && !empty($direct_course_answer['raw_summary_source'])) {
            try {
                $summaryConnector = new anthropic_connector();
                $aiSummary = $summaryConnector->summarize_document_text(
                    (string)$direct_course_answer['raw_summary_source'],
                    $user_query,
                    220,
                    $ondelta
                );
                if ($aiSummary !== '') {
                    $direct_course_answer['content'] = 'Resumen: ' . $aiSummary;
                }
            } catch (\Exception $e) {
                // Keep deterministic fallback summary if AI summarization fails.
            }
            unset($direct_course_answer['raw_summary_source']);
        }

        $answer = json_encode($direct_course_answer, JSON_UNESCAPED_UNICODE);
        $followup_questions = self::build_direct_followups($direct_course_answer, $qnorm, $isContentSpecificQuery);

        return [
            'answer' => $answer,
            'schema_data' => $direct_course_answer,
            'followup_questions' => $followup_questions
        ];
    }

    /**
     * Preguntas de seguimiento deterministas para respuestas directas.
     *
     * @param array $direct_course_answer
     * @param string $qnorm
     * @param bool $isContentSpecificQuery
     * @return array
     */
    public static function build_direct_followups(array $direct_course_answer, string $qnorm, bool $isContentSpecificQuery): array {
        $isDocumentAnswer = !empty($direct_course_answer['content_mode']) || !empty($direct_course_answer['summary_mode']);
        $answerTitle = $direct_course_answer['title'] ?? '';
        $isQuizAnswer = (bool)preg_match('/^Cuestionario:/i', $answerTitle);
        $isAssignAnswer = (bool)preg_match('/^Tarea:/i', $answerTitle);
        $isForumAnswer = (bool)preg_match('/^Foro:/i', $answerTitle);
        $isGenericActivity = (bool)preg_match('/^(Página|URL|Libro|Carpeta|Glosario|Wiki|Encuesta|Feedback|Lección):/i', $answerTitle);

        if ($isDocumentAnswer && $isContentSpecificQuery) {
            return [
                '¿Puedes mostrarme el siguiente apartado?',
                '¿Qué otros contenidos tiene este archivo?',
                '¿Puedes hacer un resumen del archivo completo?'
            ];
        }
        if ($isQuizAnswer) {
            return [
                '¿Cuántas preguntas tiene este cuestionario?',
                '¿Puedes mostrarme la primera pregunta?',
                '¿Cuántos alumnos han completado este cuestionario?'
            ];
        }
        if ($isAssignAnswer) {
            return [
                '¿Cuántos alumnos han entregado esta tarea?',
                '¿Cuál es la calificación media de esta tarea?',
                '¿Qué otras tareas hay en el curso?'
            ];
        }
        if ($isForumAnswer) {
            return [
                '¿Cuántas discusiones tiene este foro?',
                '¿Qué otros foros hay en el curso?',
                '¿Qué contenidos hay en este curso?'
            ];
        }
        if ($isGenericActivity) {
            return [
                '¿Cuántos alumnos han completado esta actividad?',
                '¿Qué otros contenidos hay en esta sección?',
                '¿Qué contenidos hay en este curso?'
            ];
        }
        if ($isDocumentAnswer || preg_match('/pdf|recurso|archivo|resource/u', $qnorm)) {
            // Extraer nombre del recurso para preguntas contextualizadas.
            $resName = '';
            if (preg_match('/^Resumen del recurso\s+(.+)$/i', $answerTitle, $_rn)) {
                $resName = trim($_rn[1]);
            } else if (preg_match('/^Recurso\s+(.+)$/i', $answerTitle, $_rn)) {
                $resName = trim($_rn[1]);
            }
            $refLabel = $resName !== '' ? '"' . $resName . '"' : 'este archivo';
            return [
                '¿Qué temas principales trata ' . $refLabel . '?',
                '¿Puedes explicarme con más detalle el contenido de ' . $refLabel . '?',
                '¿Quieres un resumen más corto en 2 líneas?'
            ];
        }
        if (strpos($qnorm, 'seccion') !== false || strpos($qnorm, 'sección') !== false) {
            return [
                '¿Cuántas secciones tiene este curso?',
                '¿Cómo se llama este curso?',
                '¿Qué actividades hay en otra sección?'
            ];
        }
        if (preg_match('/contenidos?.*curso|qu[eé]\s+hay\s+en\s+(este|el)\s+curso/u', $qnorm)) {
            return [
                '¿Qué contenidos hay en una sección concreta?',
                '¿Puedes darme un resumen de algún PDF del curso?',
                '¿Cuántas secciones tiene este curso?'
            ];
        }
        return [
            '¿Cuántas secciones tiene este curso?',
            '¿Qué contenidos hay dentro de una sección concreta?',
            '¿Cómo se llama este curso?'
        ];
    }

    /**
     * Strip markdown code fences the AI sometimes wraps around JSON.
     *
     * @param string $answer
     * @return string
     */
    public static function clean_answer(string $answer): string {
        $answer = trim($answer);
        if (preg_match('/^\s*```[a-z]*\s*/i', $answer)) {
            $answer = preg_replace('/^\s*```[a-z]*\s*/i', '', $answer);
            $answer = preg_replace('/\s*```\s*$/i', '', $answer);
            $answer = trim($answer);
        }
        return $answer;
    }
}
