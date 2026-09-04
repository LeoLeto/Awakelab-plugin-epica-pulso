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

    /**
     * Tope de caracteres de la pregunta del usuario.
     *
     * El cliente ya limita el textarea a 500, pero eso solo protege del usuario
     * despistado: los endpoints reciben `user_query` como PARAM_RAW, así que una
     * petición hecha a mano podía enviar megas de texto y pagarlos en tokens. Se
     * recorta en servidor, que es el único sitio donde el límite es real.
     */
    const MAX_QUERY_LENGTH = 2000;

    /**
     * Recorta la pregunta del usuario al tope permitido.
     *
     * @param string $user_query
     * @return string
     */
    public static function sanitize_query(string $user_query): string {
        $trimmed = trim($user_query);
        if (mb_strlen($trimmed, 'UTF-8') <= self::MAX_QUERY_LENGTH) {
            return $trimmed;
        }
        // mb_substr, no substr: cortar bytes deja UTF-8 inválido (ver la regla de
        // encode_payload en anthropic_connector).
        return mb_substr($trimmed, 0, self::MAX_QUERY_LENGTH, 'UTF-8');
    }

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
     * Memo por petición de la capability de analítica, indexado por curso.
     *
     * @var array<int,bool>
     */
    private static $analyticscap = [];

    /**
     * ¿El usuario actual puede ver datos analíticos de ESTE curso?
     *
     * Única fuente de verdad del "modo alumno": todo lo que exponga datos del
     * grupo (notas, completitud, intentos, accesos, recuentos de alumnos) tiene
     * que consultarla antes de construir el dato, no solo antes de pintarlo.
     *
     * @param int $courseid
     * @return bool
     */
    public static function user_can_view_analytics(int $courseid): bool {
        if (!array_key_exists($courseid, self::$analyticscap)) {
            self::$analyticscap[$courseid] = has_capability(
                'block/pulso:viewanalytics',
                \context_course::instance($courseid)
            );
        }
        return self::$analyticscap[$courseid];
    }

    /**
     * Obtener contexto del curso (F2.2 - data_retriever) con cache corta y
     * filtrado por toggles de categorías de datos (T2.6.2).
     *
     * Sin `block/pulso:viewanalytics` (alumnos) el contexto se construye SIN
     * analítica: las consultas pesadas no se ejecutan siquiera, así que no hay
     * dato del grupo que se pueda filtrar por accidente al prompt.
     *
     * @param int $courseid
     * @return array
     * @throws \Exception si falla la recuperación
     */
    public static function get_course_context(int $courseid): array {
        $course_context = null;
        $cache = null;
        $canviewanalytics = self::user_can_view_analytics($courseid);

        // La clave de caché DEBE distinguir el rol: la versión sin analítica de
        // un alumno no puede servirse a un profesor (ni al revés).
        $cachekey = $canviewanalytics ? (string)$courseid : $courseid . ':nostats';

        // Short-lived application cache: analytics context is course-wide and
        // rebuilding it runs several heavy queries on every chat message.
        try {
            $cache = \cache::make('block_pulso', 'coursecontext');
            $cached = $cache->get($cachekey);
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
            $course_context = $retriever->get_unified_course_context($courseid, 7, $canviewanalytics);
            if ($cache !== null && ($course_context['status'] ?? '') === 'success') {
                try {
                    $cache->set($cachekey, ['time' => time(), 'payload' => $course_context]);
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

        // Modo alumno: fuera cualquier resto analítico y los recuentos de
        // personas. total_students/total_enrolled_users son dato de gestión, así
        // que la regla queda coherente: nada del grupo.
        if (!$canviewanalytics) {
            $course_context['analytics'] = [
                'course_completions' => [],
                'course_completions_count' => 0,
                'grades_and_quizzes' => [],
                'grades_and_quizzes_count' => 0,
                'module_completions' => [],
                'module_completions_count' => 0,
                'analytics_available' => false,
            ];
            $course_context['access_logs'] = [];
            unset($course_context['metadata']['total_students'],
                  $course_context['metadata']['total_enrolled_users']);
        }

        // Privacidad (RGPD/LOPD): solo quien puede calificar el curso ve datos
        // identificables por alumno. Se comprueba SIEMPRE aqui (nunca en la cache
        // compartida por curso) porque depende del usuario de la peticion actual.
        // Tercera capa: con el modo alumno no queda dato que redactar, pero se
        // mantiene para quien tiene analítica sin poder calificar.
        $permctx = \context_course::instance($courseid);
        $canseegrades = has_capability('moodle/grade:viewall', $permctx);
        $course_context = self::apply_privacy_redaction($course_context, $canseegrades);

        return $course_context;
    }

    /**
     * Detecta preguntas cuya respuesta es dato del PROFESORADO: notas, medias,
     * completitud, entregas, intentos, accesos, alumnos en riesgo, rankings o
     * recuentos de alumnos.
     *
     * Criterio deliberadamente CONSERVADOR (privacidad > cobertura): ante la
     * duda se niega. Para no atrapar preguntas legítimas de contenido que
     * mencionan "nota" o "actividad" de pasada, buena parte de los patrones
     * exigen co-ocurrencia: un término de grupo (alumnos, clase, compañeros) con
     * uno de métrica, o un término agregado ("ha habido", "de media", "esta
     * semana") con uno de participación. Solo lo inequívocamente docente niega
     * por sí solo.
     *
     * Calibrado contra las 56 preguntas de Pulso_AI_matriz_evaluacion.xlsx más
     * un juego de variantes de alumno; ver memory/session-history.md.
     *
     * @param string $query
     * @return bool
     */
    public static function is_teacher_only_query(string $query): bool {
        $q = mb_strtolower(trim($query), 'UTF-8');
        if ($q === '') {
            return false;
        }

        // El detector ya existente de analítica de curso (nota media, matriculados,
        // en riesgo, ranking, % aprobados, "cuántos alumnos"...).
        if (rag_retriever::is_course_analytics_query($q)) {
            return true;
        }

        // Inequívocamente docente: no necesita co-ocurrencia.
        $teacherOnly = '/\ben\s+riesgo\b|\branking\b|\bengagement\b|tasa\s+de\s+completitud'
            . '|mejor(es)?\s+(alumn|estudiant)|peor(es)?\s+(alumn|estudiant)'
            . '|sin\s+acceder|no\s+han\s+(accedido|entrado)|llevan\s+.*sin\s+(acceder|entrar)'
            . '|inactiv[oa]s?\b|abandon|libro\s+de\s+calificaciones|informe\s+de\s+(notas|calificaciones)'
            . '|listado\s+de\s+(notas|calificaciones)|panorama\s+general\s+del\s+curso'
            . '|porcentaje\s+de\s+(aprobad|suspend|entregad|complet)'
            . '|cu[aá]nt[oa]s?\s+(han|ha)\s+(complet|entregad|aprobad|suspend|realizad|acced|visto|visualizad|le[ií]do|descargad)'
            . '|comp[aá]ra(me|rme|ci[oó]n)?\s+.*(clase|resto|compa[nñ]er|media|grupo)|resto\s+de\s+(la\s+)?clase'
            . '|qui[eé]n(es)?\s+(se\s+)?(no\s+)?(ha|han)\s+(complet|entregad|aprobad|suspend|realizad|acced|hecho|conectad|le[ií]do)'
            . '|estad[ií]sticas\s+(del|de\s+la|de\s+los)?\s*(curso|clase|actividad|cuestionario|tarea)'
            // "de media" en una pregunta de contenido es rarísimo; en una de
            // analítica es la forma habitual de pedir un promedio del grupo.
            . '|\bde\s+media\b'
            // Recuentos de actividad del grupo. Se excluye la configuración
            // pública de la actividad ("cuántos intentos permite", "cuántos me
            // quedan"), que un alumno sí puede consultar. "respuestas" queda
            // fuera de esta lista porque "cuántas respuestas tiene la pregunta 3"
            // es contenido; el caso agregado lo cazan las reglas de abajo.
            . '|cu[aá]nt[oa]s?\s+(entregas|intentos|accesos)\b(?!\s+(permite|permitid|permiten|puede|se\s+permit|quedan|me\s+quedan))'
            // Inglés: la matriz incluye preguntas en inglés (P42/P43).
            . '|how\s+many\s+students|which\s+students|who\s+(has|have)\s+(completed|submitted|passed|accessed)'
            . '|students?\s+(passed|failed)|average\s+grade|at.risk\s+students|students?\s+at.risk'
            . '|who\s+(is|are)\s+at.risk|completion\s+rate/u';
        if (preg_match($teacherOnly, $q)) {
            return true;
        }

        // Notas propias: fuera de alcance en esta versión (el alumno las tiene en
        // el libro de calificaciones del curso).
        if (self::asks_own_grades($q)) {
            return true;
        }

        // Co-ocurrencia grupo + métrica. OJO: "todos" NO cuenta como término de
        // grupo — "la mejor forma de hacer todos los ejercicios" es contenido.
        $group = '/\balumn|\bestudiant|compa[nñ]er|participant|matricul|\bclase\b|\bgrupo\b|\bgente\b/u';
        $metric = '/complet|entregad|entrega\b|entregas\b|aprobad|suspend|\bnota|calificaci|puntuaci'
            . '|intento|acced|acceso|progres|avance|participaci|asistenc|media\b|promedio|porcentaje'
            . '|cu[aá]ntos|\bmejor\b|\bpeor\b|nombres?\b/u';
        if (preg_match($group, $q) && preg_match($metric, $q)) {
            return true;
        }

        // Co-ocurrencia agregado + participación: capta el engagement del grupo
        // sin nombrar a los alumnos ("¿cuál es la actividad de las últimas
        // semanas?", "¿cuánta gente ha accedido esta semana?").
        // "hoy" queda FUERA a propósito: "¿qué actividad de la sección 2 tengo
        // que hacer hoy?" es contenido. Los casos con "hoy" que sí son analítica
        // ("¿quién se ha conectado hoy?", "¿cuántos accesos hoy?") ya los cazan
        // los patrones inequívocos de arriba.
        $aggregate = '/\bha\s+habido\b|\bse\s+han\b|\btotal(es)?\b|\bpromedio\b'
            . '|[uú]ltim[ao]s?\s+(d[ií]as|semanas|meses)|\best[ae]\s+(mes|semana|a[nñ]o)\b/u';
        $engagement = '/\bintentos?\b|\bentregas?\b|\baccesos?\b|conexion|conectad|\brespuestas\b'
            . '|completad|participaci|\bactividad\s+de\b/u';
        if (preg_match($aggregate, $q) && preg_match($engagement, $q)) {
            return true;
        }

        return false;
    }

    /**
     * ¿Pregunta el usuario por SUS propias notas/progreso?
     *
     * Se niega igual en esta versión (decisión de alcance), pero con un mensaje
     * distinto: redirigir al libro de calificaciones es más útil que decirle que
     * el dato es del profesorado.
     *
     * @param string $q Consulta ya en minúsculas
     * @return bool
     */
    public static function asks_own_grades(string $q): bool {
        return (bool)preg_match(
            '/\bmis\s+(notas|calificaciones|resultados|entregas|intentos)\b'
            . '|\bmi\s+(nota|calificaci[oó]n|progreso|avance|media|expediente)\b'
            . '|(qu[eé]|cu[aá]l).*\b(he|tengo)\b.*\b(sacado|nota|aprobad|suspend)'
            . '|\bc[oó]mo\s+(voy|llevo)\b/u',
            $q
        );
    }

    /**
     * Respuesta de negativa educada para un alumno que pregunta datos del
     * profesorado. Misma forma de payload que las respuestas directas, para que
     * la UI la pinte como una tarjeta normal.
     *
     * Se resuelve ANTES de tocar contexto, RAG o Anthropic: coste cero y ningún
     * dato expuesto. El mensaje invita a reformular para que un falso positivo
     * sobre una pregunta de contenido no deje al usuario en vía muerta.
     *
     * @param string $user_query
     * @return array ['answer' => string(JSON), 'schema_data' => array, 'followup_questions' => array]
     */
    public static function teacher_only_refusal(string $user_query): array {
        $payload = self::teacher_only_payload($user_query);

        return [
            'answer' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'schema_data' => $payload,
            'followup_questions' => self::student_content_followups(),
        ];
    }

    /**
     * Payload (forma de respuesta directa) de la negativa por rol. Separado de
     * teacher_only_refusal() porque rag_retriever lo devuelve tal cual desde la
     * ruta directa, donde el JSON y los follow-ups los arma el llamador.
     *
     * @param string $user_query
     * @return array
     */
    public static function teacher_only_payload(string $user_query): array {
        $q = mb_strtolower(trim($user_query), 'UTF-8');

        if (self::asks_own_grades($q)) {
            $title = 'No puedo mostrar calificaciones';
            $content = 'No puedo mostrar calificaciones desde el chat. Tus notas están en '
                . 'el libro de calificaciones del curso. Si tu pregunta era sobre el '
                . 'contenido, vuelve a preguntármela mencionando el material o la sección.';
        } else {
            $title = 'Solo disponible para el profesorado';
            $content = 'Esa información solo está disponible para el profesorado del curso. '
                . 'Si tu pregunta era sobre el contenido, vuelve a preguntármela mencionando '
                . 'el material o la sección.';
        }

        return [
            'type' => 'text',
            'title' => $title,
            'content' => $content,
        ];
    }

    /**
     * Sugerencias de contenido para el alumno (nunca analítica).
     *
     * @return array
     */
    public static function student_content_followups(): array {
        return [
            '¿De qué trata este curso?',
            '¿Qué materiales hay en el curso?',
            '¿Puedes resumirme una sección concreta?',
        ];
    }

    /**
     * Quita de una lista de preguntas sugeridas las que pedirían datos del
     * profesorado. Se aplica a las sugerencias del alumno, vengan del catálogo
     * determinista o del modelo rápido.
     *
     * @param array $questions
     * @return array
     */
    public static function filter_student_followups(array $questions): array {
        $clean = [];
        foreach ($questions as $question) {
            if (!is_string($question) || trim($question) === '') {
                continue;
            }
            if (self::is_teacher_only_query($question)) {
                continue;
            }
            $clean[] = $question;
        }
        if (empty($clean)) {
            return self::student_content_followups();
        }
        return array_slice($clean, 0, 3);
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
        $failed = 0;
        $withGrade = 0;
        $sumPercentage = 0.0;
        foreach ($rows as $row) {
            $pct = $row['percentage'] ?? null;
            if ($pct !== null) {
                $sumPercentage += (float)$pct;
                $withGrade++;
            }
            // is_passed puede ser null = "no se puede saber" (sin nota de corte
            // configurada). El porcentaje de aprobados debe calcularse SOLO sobre
            // los items que si tienen corte, no sobre el total.
            $ispassed = $row['is_passed'] ?? null;
            if ($ispassed === null) {
                continue;
            }
            if ((int)$ispassed === 1) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $withPassMark = $passed + $failed;

        return [
            'aggregate_only' => true,
            'total_grade_records' => $total,
            'average_percentage' => $withGrade > 0 ? round($sumPercentage / $withGrade, 2) : null,
            'passed_count' => $passed,
            'failed_count' => $failed,
            'records_with_pass_mark' => $withPassMark,
            'passed_percentage' => $withPassMark > 0 ? round(($passed / $withPassMark) * 100, 2) : null,
        ];
    }

    /**
     * @param array $rows
     * @return array
     */
    private static function aggregate_module_completions(array $rows): array {
        $total = count($rows);
        $byStatus = [];
        $completed = 0;
        foreach ($rows as $row) {
            $status = $row['completion_status'] ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            // 'completed', 'completed_pass' y 'completed_fail' son todos
            // actividad completada — ver data_retriever::get_module_completions().
            if (!empty($row['is_completed'])) {
                $completed++;
            }
        }
        return [
            'aggregate_only' => true,
            'total_records' => $total,
            'completed_count' => $completed,
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
        global $SESSION;

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

        // Validar estructura de cada mensaje. El historial viene del cliente
        // (sessionStorage), asi que no se asume nada: 'content' debe ser un
        // string NO vacio — la Messages API de Anthropic rechaza con 400 los
        // bloques de contenido vacios, y strlen() sobre un array seria un
        // TypeError fatal en PHP 8.
        $history = array_filter($history, function($msg) {
            return is_array($msg) && isset($msg['role']) && isset($msg['content'])
                && in_array($msg['role'], ['user', 'assistant'], true)
                && is_string($msg['content']) && trim($msg['content']) !== '';
        });
        $history = array_values($history);

        if (count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        }

        // Las respuestas del asistente pasan SIEMPRE por history_digest(): se
        // guardan como texto, nunca como JSON. Se aplica tambien aqui (no solo al
        // guardar) porque el sessionStorage de los usuarios ya contiene JSON crudo
        // de versiones anteriores, y un JSON cortado a la mitad en un turno de
        // asistente hacia que el modelo continuase la respuesta anterior en vez de
        // contestar la pregunta nueva (bug critico del 2026-09-04).
        //
        // El recorte usa mb_* obligatoriamente: con strlen()/substr() el corte cae
        // a mitad de un caracter multibyte (cualquier acento del español) y deja
        // UTF-8 invalido en el historial; entonces json_encode() del payload
        // devuelve false, se envia un cuerpo vacio a Anthropic y la API responde
        // 400 en CADA mensaje siguiente hasta que el usuario limpia la conversacion.
        foreach ($history as &$msg) {
            if (($msg['role'] ?? '') === 'assistant') {
                $msg['content'] = self::history_digest($msg['content']);
                continue;
            }
            if (mb_strlen($msg['content'], 'UTF-8') > self::MAX_HISTORY_CONTENT_LENGTH) {
                $msg['content'] = mb_substr($msg['content'], 0, self::MAX_HISTORY_CONTENT_LENGTH, 'UTF-8')
                    . '...[truncated]';
            }
        }
        unset($msg);

        // history_digest() puede dejar vacio un turno (respuesta que era solo un
        // JSON ilegible): la Messages API rechaza con 400 los bloques vacios.
        $history = array_values(array_filter($history, function ($msg) {
            return trim((string)($msg['content'] ?? '')) !== '';
        }));

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
        // El historial guarda TEXTO, nunca el JSON de la respuesta: ver
        // history_digest().
        $history[] = ['role' => 'assistant', 'content' => self::history_digest($answer)];
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
     * Convierte una respuesta del asistente en TEXTO plano y corto para el
     * historial.
     *
     * Por qué existe (bug crítico de la evaluación del 2026-09-04): el historial
     * guardaba el JSON crudo de cada respuesta, y `prepare_history()` corta a
     * MAX_HISTORY_CONTENT_LENGTH, así que cualquier respuesta larga (un ranking,
     * los alumnos en riesgo) quedaba en el payload como un objeto JSON **sin
     * cerrar**. Con el prompt exigiendo "tu respuesta COMPLETA debe ser solo el
     * objeto JSON", el modelo veía un turno de asistente con un JSON a medias del
     * mismo esquema y, en vez de responder a la pregunta nueva, lo continuaba:
     * reemitía la respuesta anterior entera. Con la conversación limpia no pasaba,
     * de ahí que se arreglase recargando.
     *
     * Guardando texto, no queda JSON que continuar (y se gastan menos tokens).
     *
     * @param string $answer Respuesta tal cual se envió al cliente (JSON o texto)
     * @return string
     */
    public static function history_digest(string $answer): string {
        $answer = trim($answer);
        if ($answer === '') {
            return '';
        }

        $json = self::extract_json_object($answer);
        if ($json === null) {
            // Ya es texto: solo se limpian fences de markdown.
            $plain = trim(preg_replace('/^```[a-z]*|```$/mu', '', $answer));
            return self::shorten_for_history($plain);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            // JSON invalido (el modelo emite alguno malformado de vez en cuando):
            // se guarda como texto legible, nunca como JSON roto.
            return self::shorten_for_history(self::strip_json_noise($answer));
        }

        $parts = [];
        foreach (['title', 'summary', 'content'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $parts[] = trim($data[$key]);
            }
        }

        // 'data' puede ser tabla, lista o párrafos: se aplanan unas pocas filas
        // para que el modelo recuerde de qué se habló, no para reproducirlo.
        if (!empty($data['data']) && is_array($data['data'])) {
            $rows = [];
            foreach (array_slice($data['data'], 0, 5) as $row) {
                if (is_string($row)) {
                    $rows[] = trim($row);
                    continue;
                }
                if (!is_array($row)) {
                    continue;
                }
                $values = [];
                foreach ($row as $value) {
                    if (is_scalar($value) && trim((string)$value) !== '') {
                        $values[] = trim((string)$value);
                    }
                }
                if (!empty($values)) {
                    $rows[] = implode(' · ', $values);
                }
            }
            if (!empty($rows)) {
                $parts[] = implode('; ', $rows);
            }
        }

        if (empty($parts)) {
            return self::shorten_for_history(self::strip_json_noise($answer));
        }

        // Se unen con salto de línea, NO con punto: el history-hint localiza el
        // recurso del turno anterior con anclas de línea ("Recurso: X",
        // "Seccion: N"), y aplastar los saltos lo dejaría ciego.
        return self::shorten_for_history(implode("\n", $parts));
    }

    /**
     * Deja legible un JSON que no se pudo decodificar: fuera llaves, corchetes,
     * comillas y separadores. Sirve para que un JSON roto del modelo nunca acabe
     * en el historial invitando al modelo a continuarlo.
     *
     * @param string $text
     * @return string
     */
    private static function strip_json_noise(string $text): string {
        $text = strip_tags($text);
        $text = preg_replace('/^```[a-z]*|```$/mu', '', $text);
        $text = str_replace(['{', '}', '[', ']', '"'], ' ', $text);
        $text = preg_replace('/\s*,\s*/u', ', ', $text);
        $text = preg_replace('/\s*:\s*/u', ': ', $text);
        return trim($text);
    }

    /**
     * Normaliza espacios y recorta al tope del historial. Conserva los saltos de
     * línea (ver history_digest()). mb_* obligatorio (invariante de CLAUDE.md).
     *
     * @param string $text
     * @return string
     */
    private static function shorten_for_history(string $text): string {
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = preg_replace('/\n{2,}/u', "\n", $text);
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > self::MAX_HISTORY_CONTENT_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_HISTORY_CONTENT_LENGTH, 'UTF-8') . '...';
        }
        return $text;
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
        // Fallback: extraer del título. Con el historial en texto (ver
        // history_digest()) el título es la PRIMERA LÍNEA del mensaje, así que se
        // acepta cualquiera de las dos fuentes.
        $title = '';
        if (is_array($decoded) && isset($decoded['title'])) {
            $title = (string)$decoded['title'];
        } else {
            $firstline = strtok($searchText, "\n");
            $title = $firstline === false ? '' : trim($firstline);
        }
        if ($resourceName === '' && $title !== '') {
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

        // El catálogo determinista propone cosas como "¿cuántos alumnos han
        // entregado esta tarea?": a un alumno no se le ofrece lo que se le va a
        // negar.
        if (!self::user_can_view_analytics($courseid)) {
            $followup_questions = self::filter_student_followups($followup_questions);
        }

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
     * Extrae el primer objeto JSON balanceado ({...}) de un texto, ignorando
     * cualquier preámbulo/postámbulo (fences de markdown, frases tipo "Aquí
     * tienes:", etc.) que el modelo pueda añadir alrededor. El escaneo respeta
     * el contenido de los strings JSON (comillas y escapes) para no cortar mal
     * si hay '{' o '}' sueltos dentro de un valor de texto.
     *
     * @param string $text
     * @return string|null El substring del objeto JSON, o null si no se encontró
     *                      uno balanceado.
     */
    private static function extract_json_object(string $text): ?string {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $in_string = false;
        $escaped = false;
        $len = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                } else if ($ch === '\\') {
                    $escaped = true;
                } else if ($ch === '"') {
                    $in_string = false;
                }
                continue;
            }

            if ($ch === '"') {
                $in_string = true;
            } else if ($ch === '{') {
                $depth++;
            } else if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Limpiar la respuesta de la IA para quedarnos solo con el objeto JSON,
     * aunque venga envuelto en fences de markdown y/o con texto antes o
     * después (algunos modelos añaden preámbulos tipo "Aquí tienes:" pese a
     * las instrucciones del system prompt).
     *
     * @param string $answer
     * @return string
     */
    public static function clean_answer(string $answer): string {
        $answer = trim($answer);

        $extracted = self::extract_json_object($answer);
        if ($extracted !== null) {
            json_decode($extracted, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $extracted;
            }
        }

        // El texto contiene un JSON roto (array anidado accidental, par
        // clave/valor huérfano, truncado, comas colgantes): intentar repararlo
        // antes de rendirse. Sin esto, el frontend recibe el JSON crudo y lo pinta
        // como texto plano.
        $repaired = self::repair_json_object($extracted !== null ? $extracted : $answer);
        if ($repaired !== null) {
            // Traza para medir cuántas respuestas necesitan reparación. Solo se
            // escribe con el modo depuración de Moodle activo.
            if (function_exists('debugging')) {
                debugging('block_pulso: JSON del modelo reparado en clean_answer()', DEBUG_DEVELOPER);
            }
            return $repaired;
        }

        if (function_exists('debugging')) {
            debugging('block_pulso: JSON del modelo irreparable; se devuelve texto', DEBUG_DEVELOPER);
        }

        // Fallback: si no se encontró un objeto JSON válido, al menos quitar
        // fences de markdown si estaban al principio/final del texto.
        if (preg_match('/^\s*```[a-z]*\s*/i', $answer)) {
            $answer = preg_replace('/^\s*```[a-z]*\s*/i', '', $answer);
            $answer = preg_replace('/\s*```\s*$/i', '', $answer);
            $answer = trim($answer);
        }
        return $answer;
    }

    /**
     * Intenta reparar un JSON casi-válido producido por el modelo. Cubre las
     * clases de error observadas en producción:
     *  - Array anidado accidental en "data" ("data":[[{...}] cerrado con un
     *    solo corchete) — visto con claude-sonnet-5 en respuestas tipo tabla.
     *  - Comas colgantes antes de un cierre.
     *  - JSON truncado por max_tokens (string/llaves/corchetes sin cerrar).
     * Cada candidato de reparación se valida con json_decode antes de
     * aceptarse; si ninguno es válido devuelve null (el llamador conserva el
     * texto original).
     *
     * @param string $text Texto que contiene (o casi contiene) un objeto JSON.
     * @return string|null JSON válido reparado, o null si no se pudo.
     */
    private static function repair_json_object(string $text): ?string {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $json = substr($text, $start);

        // Variantes base: tal cual, y con el "[[" accidental aplanado.
        $variants = [$json];
        $flat = preg_replace('/("data"\s*:\s*)\[\s*\[/', '$1[', $json, 1);
        if ($flat !== null && $flat !== $json) {
            $variants[] = $flat;
        }

        // Par clave/valor huérfano: el modelo empieza `"clave":` y, en lugar del
        // valor, abre otro par — visto en producción en un ranking:
        //   "rank":"4","name":"rank":"3","name":"MARIED ROMELIA..."
        // Si el "valor" de un par va seguido de ':', en realidad era una clave: se
        // descarta la clave huérfana anterior y se conserva el par bueno. Es la
        // reparación más conservadora posible (no inventa valores) y, como todas,
        // solo se acepta si json_decode la valida.
        foreach ($variants as $variant) {
            $fixed = $variant;
            for ($i = 0; $i < 20; $i++) {
                $next = preg_replace('/"[^"\\\\]*"\s*:\s*("[^"\\\\]*"\s*:)/', '$1', $fixed, 1);
                if ($next === null || $next === $fixed) {
                    break;
                }
                $fixed = $next;
            }
            if ($fixed !== $variant) {
                $variants[] = $fixed;
            }
        }

        // Por cada variante, probar también su versión con cierres añadidos.
        $candidates = [];
        foreach ($variants as $v) {
            $candidates[] = $v;
            $closed = self::close_unbalanced_json($v);
            if ($closed !== null) {
                $candidates[] = $closed;
            }
        }

        foreach ($candidates as $candidate) {
            $noTrailingCommas = preg_replace('/,\s*([\]\}])/', '$1', $candidate);
            foreach ([$candidate, $noTrailingCommas] as $c) {
                if (!is_string($c) || $c === '') {
                    continue;
                }
                json_decode($c, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $c;
                }
            }
        }

        return null;
    }

    /**
     * Cierra las estructuras abiertas de un JSON truncado: termina el string
     * sin cerrar si lo hay, poda restos colgantes (",", '"clave":') y añade
     * los ']' / '}' que falten según la pila de aperturas.
     *
     * @param string $json Texto que empieza en '{'.
     * @return string|null Candidato cerrado, o null si ya estaba balanceado.
     */
    private static function close_unbalanced_json(string $json): ?string {
        $stack = [];
        $in_string = false;
        $escaped = false;
        $len = strlen($json);

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                } else if ($ch === '\\') {
                    $escaped = true;
                } else if ($ch === '"') {
                    $in_string = false;
                }
                continue;
            }
            if ($ch === '"') {
                $in_string = true;
            } else if ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
            } else if ($ch === '}' || $ch === ']') {
                array_pop($stack);
            }
        }

        if (empty($stack) && !$in_string) {
            return null; // Ya balanceado: no hay nada que cerrar.
        }

        $result = $json;
        if ($in_string) {
            $result .= '"';
        }

        // Podar restos colgantes tras el último valor completo.
        $result = rtrim($result);
        $result = preg_replace('/,\s*"[^"]*"\s*:\s*$/', '', $result);   // ,"clave": colgante
        $result = preg_replace('/([\{\[,])\s*"[^"]*"$/', '$1', $result); // "clave" sin ':' colgante
        $result = rtrim($result);
        if (substr($result, -1) === ',') {
            $result = substr($result, 0, -1);
        } else if (substr($result, -1) === ':') {
            $result = preg_replace('/"[^"]*"\s*:\s*$/', '', $result);
            $result = rtrim($result);
        }

        while (!empty($stack)) {
            $open = array_pop($stack);
            $result .= ($open === '{') ? '}' : ']';
        }

        return $result;
    }
}
