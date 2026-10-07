<?php
$string['pluginname'] = 'Pulse AI';
$string['pulso:addinstance'] = 'Add a new Pulse block';
$string['pulso:myaddinstance'] = 'Add a new Pulse block to the My Moodle page';
// Settings strings for T2.1.4
$string['setanthropickey'] = 'Anthropic API Key (chat)';
$string['setanthropickey_desc'] = 'Enter your secret API key from Anthropic (Claude). Used exclusively to generate the chat answers (analytics and content Q&A).';
$string['setapikey'] = 'OpenAI API Key (RAG embeddings only)';
$string['setapikey_desc'] = 'Enter your secret API key from OpenAI. Used exclusively to generate embeddings for the RAG content index — it is no longer used for chat answers.';
$string['setmodel'] = 'AI Model (chat)';
$string['setmodel_desc'] = 'Choose the Claude model to use for analyzing course data and answering questions.';

// === CHAT QUERY UI STRINGS (T2.3.2) ===
$string['chat_title'] = 'Pulse Analytics AI';
$string['chat_subtitle'] = 'Ask me anything about your course';
$string['chat_welcome'] = 'Welcome! I\'m your course analytics assistant. I can help you understand completion rates, grade trends, student engagement, and identify at-risk students. Ask me anything!';

// === INPUT AREA ===
$string['chat_input_placeholder'] = 'Type a question about your course analytics...';
$string['chat_char_counter'] = 'Characters';

// === EXAMPLE CHIPS (Prompts) ===
$string['chat_example_prompts'] = 'Example prompts:';
$string['chip_completion_analysis'] = 'Completion Analysis';
$string['chip_grade_trends'] = 'Grade Trends';
$string['chip_engagement_report'] = 'Student Engagement';
$string['chip_at_risk_students'] = 'At-Risk Students';

// === LOADING & STATUS ===
$string['chat_loading'] = 'Loading...';
$string['chat_thinking'] = 'Thinking...';

// === ERRORS ===
$string['error_no_apikey'] = 'OpenAI API key is not configured. Please set it in Site Administration → Plugins → Blocks → Pulse AI.';
$string['error_no_apikey_anthropic'] = 'Anthropic API key is not configured. Please set it in Site Administration → Plugins → Blocks → Pulse AI.';
$string['error_no_response'] = 'No response received from the AI service.';
$string['error_api_error'] = 'API Error: {$a}';
$string['error_invalid_course'] = 'Invalid course ID.';
$string['error_no_permission'] = 'You do not have permission to use this feature.';
$string['error_timeout'] = 'Request timed out. Please try again.';
$string['error_refusal'] = 'The AI declined to answer this request for policy reasons. Try rephrasing your question.';
$string['error_api_connection'] = 'Could not reach the AI service. Please try again in a moment.';
$string['error_api_response'] = 'The AI service returned an error: {$a}';
$string['error_empty_response'] = 'The AI service returned an empty response. Please try again.';
$string['error_payload_encoding'] = 'The request could not be prepared. Start a new conversation ("Nueva conversación") and try again.';

// === ACCESSIBILITY ===
$string['send_message'] = 'Send message';
$string['close_alert'] = 'Close alert';

// === T2.6.1: Per-course enable/disable ===
$string['coursecontrol_heading'] = 'Course Control';
$string['coursecontrol_heading_desc'] = 'Control whether Pulse is enabled by default for all courses.';
$string['enabled_by_default'] = 'Enabled by default';
$string['enabled_by_default_desc'] = 'If checked, Pulse will be active on all courses unless explicitly disabled per course.';
$string['plugin_disabled_course'] = 'Pulse is disabled in this course.';

// === T2.6.2: Data access permission controls ===
$string['pulso:viewanalytics'] = 'View Pulse analytics data';
$string['pulso:usechat'] = 'Use the Pulse chat for course content questions';

// === Student mode (content-only chat) ===
$string['student_analytics_denied'] = 'That information is only available to the teaching staff of this course. If your question was about the course content, ask it again mentioning the material or the section.';
$string['student_own_grades_denied'] = 'I cannot show grades from the chat. You can check your own grades in the course gradebook. If your question was about the course content, ask it again mentioning the material or the section.';
$string['student_analytics_denied_title'] = 'Only available to teaching staff';
$string['dataaccess_heading'] = 'Data Access Controls';
$string['dataaccess_heading_desc'] = 'Choose which data categories are available for AI analysis. Disabling a category will prevent it from being sent to the AI (Anthropic).';
$string['data_completion'] = 'Completion data';
$string['data_completion_desc'] = 'Allow access to course and activity completion data.';
$string['data_grades'] = 'Grades data';
$string['data_grades_desc'] = 'Allow access to gradebook and quiz score data.';
$string['data_logs'] = 'Access logs';
$string['data_logs_desc'] = 'Allow access to recent user access log data.';

// === RAG: Retrieval-Augmented Generation ===
$string['rag_heading'] = 'Content Indexing (RAG)';
$string['rag_heading_desc'] = 'Retrieval-Augmented Generation lets the AI read and reason about the actual didactic content of the course (pages, assignments, quiz questions, books, wikis). The scheduled task "Index course content for RAG" must run at least once before this feature works.';
$string['rag_enabled'] = 'Enable RAG content indexing';
$string['rag_enabled_desc'] = 'When enabled, Pulse will embed course content and inject relevant fragments into each query so the AI can answer questions about course material (e.g. explain an exercise, solve a problem from the course).';
$string['task_index_course_content'] = 'Index course content for RAG (Pulse AI)';
$string['task_index_course_adhoc'] = 'Index a single course on demand for RAG (Pulse AI)';

// === CACHES ===
$string['cachedef_coursecontext'] = 'Unified course analytics context used by the Pulse chat';

// === Creations with Epica (infographics and games; v1.18.0) ===
$string['pulso:createactivity'] = 'Request a creation (infographic) from Pulse';
$string['creationquota_heading'] = 'Creations (Epica) — quotas';
$string['creationquota_heading_desc'] = 'Anti-abuse limits for creations sent through Pulse (infographics and games today). These count creations requested, not finished ones — Epica shares its queue across tools.';
$string['quota_user_section_day'] = 'Per user, section and day';
$string['quota_user_section_day_desc'] = 'Maximum creations a single user can submit for resources in the same course section, per day.';
$string['quota_user_course_day'] = 'Per user and day, in a course';
$string['quota_user_course_day_desc'] = 'Maximum creations a single user can submit within one course, per day.';
$string['quota_course_hour'] = 'Per course and hour';
$string['quota_course_hour_desc'] = 'Maximum creations a single course can generate in a rolling hour.';
$string['quota_course_day_floor'] = 'Per course and day — minimum';
$string['quota_course_day_floor_desc'] = 'Minimum daily creations allowed per course, regardless of enrolment size (see the multiplier setting below for the actual formula: the higher of this floor and enrolled users × multiplier).';
$string['quota_course_day_multiplier'] = 'Per course and day — multiplier per enrolled user';
$string['quota_course_day_multiplier_desc'] = 'Multiplied by the course\'s enrolled users to get the daily cap (e.g. 1.5 means 150 enrolled users allow up to 225 creations/day). The effective limit is the higher of this and the floor above.';
$string['quota_teacher_day'] = 'Per teacher and day (all courses)';
$string['quota_teacher_day_desc'] = 'Maximum creations a single teaching-staff member can submit per day, across ALL their courses.';

// === Resource expansion: YouTube + OpenAlex (v1.24.0) ===
$string['ampliacion_heading'] = 'Resource expansion — videos and articles';
$string['ampliacion_heading_desc'] = 'For a course resource, Pulse finds 2 YouTube videos and 2 OpenAlex articles on its topic. One expansion is generated per resource and text version and shared by the whole course (cached), because YouTube search is limited to about 100 searches per day for the whole project. Only the generated search queries are sent to YouTube/OpenAlex — never the resource text or user data. Without a YouTube key only articles are offered, and vice versa.';
$string['youtube_api_key'] = 'YouTube Data API key';
$string['youtube_api_key_desc'] = 'YouTube Data API v3 key. search.list costs 100 quota units; videos.list costs 1.';
$string['openalex_api_key'] = 'OpenAlex API key';
$string['openalex_api_key_desc'] = 'OpenAlex API key (required since February 2026; the free tier gives about 1,000 searches per day).';
$string['ampliacion_max_dia_sitio'] = 'New expansions per day (whole site)';
$string['ampliacion_max_dia_sitio_desc'] = 'Maximum NEW expansions generated per day across the site; cached ones do not count. Keep it below your daily YouTube search quota (default 80 of ~100).';
$string['ampliacion_max_dia_usuario'] = 'New expansions per user and day';
$string['ampliacion_max_dia_usuario_desc'] = 'Maximum NEW expansions a single user can trigger per day; cached ones do not count.';
$string['ampliacion_ttl_dias'] = 'Cache lifetime (days)';
$string['ampliacion_ttl_dias_desc'] = 'After this many days a cached expansion is regenerated the next time someone asks for it. A changed resource text always generates a new one.';

// === Epica Retos (challenges): own daily limits (v1.26.0) ===
$string['retos_heading'] = 'Challenges (Epica) — own daily limits';
$string['retos_heading_desc'] = 'Pulse asks Epica for six challenges, the user picks one and Epica returns a link where it is solved and graded. These limits are independent of Epica\'s own limits and of the infographic, game and expansion limits, and are checked BEFORE calling Epica. Epica additionally limits students to 3 requests (propose, "propose others" and pick together) every 10 minutes, and the centre to 100 proposals/picks per day.';
$string['retos_max_propuestas_usuario_dia'] = 'Proposals per user and day';
$string['retos_max_propuestas_usuario_dia_desc'] = 'Maximum challenge proposals (including "propose others") one user can request per day, across all courses.';
$string['retos_max_elegidos_usuario_dia'] = 'Challenges picked per user and day';
$string['retos_max_elegidos_usuario_dia_desc'] = 'Maximum challenges one user can pick (each creates a challenge in Epica) per day, across all courses.';
$string['retos_max_elegidos_curso_dia'] = 'Challenges picked per course and day';
$string['retos_max_elegidos_curso_dia_desc'] = 'Maximum challenges picked in a single course per day, by everyone. Keep it well below Epica\'s centre-wide daily limit.';

// === Epica cycle: sign, order, poll, collect (v1.19.0) ===
$string['task_epica_ciclo_adhoc'] = 'One step of the Epica creation cycle (Pulse AI)';
$string['epica_heading'] = 'Epica integration — creation cycle';
$string['epica_heading_desc'] = 'Where Pulse sends creations (infographics and games) and how the background task talks to Epica. The whole cycle (sign, order, poll, collect) runs in an adhoc task — it never happens inside a web request.';
$string['epica_base_url'] = 'Epica base URL';
$string['epica_base_url_desc'] = 'Base URL of the Epica environment to send creations to (e.g. the QA environment while piloting, production once cleared). The task appends the fixed API paths to this. Must start with https://. EMPTY = Epica unavailable: no infographics, games, challenges or history are offered (Expand resource keeps working).';
$string['epica_dry_run'] = 'Dry-run mode (build the envelope, never send it)';
$string['epica_dry_run_desc'] = 'When enabled, the task builds the full request envelope for each pending creation and stores it, but never signs a token or calls Epica — the request is left in its own terminal state ("ensayo"). Use this to verify the payload shape before local_awkepica has a production secret configured, or to test the cycle without spending Epica quota. Off by default.';

// === Epica cycle: status panel + notification (v1.20.0) ===
$string['messageprovider:epica_encargo'] = 'A creation (infographic or game) is ready or failed';

// === User-facing chat/endpoint errors (v1.30.0) — chosen by a stable error_code, never by API text ===
$string['err_busy'] = 'Pulse is very busy right now. Try again in a minute.';
$string['err_config'] = 'Pulse is not available right now. Let your teacher know.';
$string['err_network'] = 'The connection was cut. Please try again.';
$string['err_session'] = 'Your session has expired. Reload the page.';
$string['err_access'] = 'You do not have access to Pulse in this course.';
$string['err_disabled'] = 'Pulse is disabled in this course.';
$string['err_bad_request'] = 'That request is not valid. Write another question and try again.';
$string['err_empty'] = 'Pulse could not answer this time. Please try again.';
$string['err_refusal'] = 'Pulse cannot answer this request. Try rephrasing your question.';
$string['err_encoding'] = 'The request could not be prepared. Start a new conversation ("Nueva conversación") and try again.';
$string['err_unknown'] = 'Something went wrong. Try again in a moment; if it keeps happening, press "Nueva conversación".';
$string['err_detail_nokey_anthropic'] = 'The Anthropic API key is not configured.';
$string['err_detail_nokey_openai'] = 'The OpenAI API key is not configured.';
$string['err_detail_badkey'] = 'The Anthropic API key is not valid or lacks permission.';
$string['err_detail_nocredit'] = 'The Anthropic account has no credit left.';
$string['err_detail_badmodel'] = 'The configured model does not exist or is not available.';
$string['err_detail_rejected'] = 'The AI service rejected the request (configuration or model). Check the server log.';
$string['err_rate_limited'] = 'You have sent a lot of questions in a row. Wait a minute.';
$string['err_rate_limited_day'] = 'You have reached today\'s question limit. You can continue tomorrow.';
$string['err_unavailable'] = 'Content creation is not available on this site.';
$string['err_setting_nonneg'] = 'Must be a number greater than or equal to 0.';
$string['err_setting_https'] = 'Must be an address starting with https:// (or left empty).';
$string['cachedef_chatrate'] = 'Per-user chat question counter (rate limit)';
$string['chatlimit_heading'] = 'Chat usage limit';
$string['chatlimit_heading_desc'] = 'Per-person question cap, so a whole class or a script cannot exhaust the organisation\'s Anthropic limit for every course. 0 = no limit.';
$string['chat_max_por_minuto_usuario'] = 'Questions per minute and user';
$string['chat_max_por_minuto_usuario_desc'] = 'Maximum questions one person can send to the chat in a rolling one-minute window. 0 = no limit.';
$string['chat_max_por_dia_usuario'] = 'Questions per day and user';
$string['chat_max_por_dia_usuario_desc'] = 'Maximum questions one person can send to the chat per day. 0 = no limit.';
$string['diagnostico_heading'] = 'Diagnostics';
$string['diagnostico_heading_desc'] = 'Checks keys, external services, cron and courses without indexed text.';
$string['diagnostico_link'] = 'Open the diagnostics page';
$string['task_sync_tema'] = 'Sync the center colors with Epica (Pulse AI)';
$string['tema_heading'] = 'Center colors (Epica)';
$string['tema_heading_desc'] = 'Each center sees Pulse in its own colors, chosen in Epica. A scheduled task asks Epica every hour and stores the answer; pages only read what is stored. With no theme, Pulse looks the usual way.';
$string['tema_estado_con'] = 'Current theme: version {$a->version}, last synchronized {$a->fecha}.';
$string['tema_estado_sin'] = 'No theme stored: Pulse uses its own colors.';
$string['tema_sync_button'] = 'Sync the colors with Epica';
$string['tema_msg_sin_epica'] = 'Epica is not available on this site, so there is nothing to synchronize.';
$string['tema_msg_sin_correo'] = 'The site administrator has no email address, so the request to Epica cannot be signed. Add one to the administrator profile.';
$string['tema_msg_error_red'] = 'Could not reach Epica. The last stored colors are kept.';
$string['tema_msg_error_rechazo'] = 'Epica rejected the request. Check the platform and secret in local_awkepica. The last stored colors are kept.';
$string['tema_msg_error_respuesta'] = 'Epica answered in an unexpected way. The last stored colors are kept.';
$string['tema_msg_guardado'] = 'Colors synchronized (version {$a}).';
$string['tema_msg_sin_cambios'] = 'The colors are already up to date (version {$a}).';
$string['tema_msg_borrado'] = 'This center has no colors in Epica any more: Pulse is back to its own colors.';
$string['tema_msg_sin_tema'] = 'This center has no colors in Epica: Pulse uses its own colors.';

// Privacy API (v2.3.0).
$string['privacy:metadata:block_pulso_encargos'] = 'Creations (infographics and games) a person asked Pulse to generate.';
$string['privacy:metadata:block_pulso_ampliaciones'] = 'Resource expansions (videos and articles). The row is a cache shared by the course; only who generated it is personal.';
$string['privacy:metadata:block_pulso_reto_propuestas'] = 'Challenge proposals a person asked for.';
$string['privacy:metadata:block_pulso_retos'] = 'Challenges a person chose.';
$string['privacy:metadata:userid'] = 'The ID of the person.';
$string['privacy:metadata:courseid'] = 'The ID of the course.';
$string['privacy:metadata:tool'] = 'The tool used (infographic or game).';
$string['privacy:metadata:prompt'] = 'What the person asked for, as they wrote it.';
$string['privacy:metadata:status'] = 'The state of the request.';
$string['privacy:metadata:titulo'] = 'The title of the result.';
$string['privacy:metadata:tema'] = 'The topic, written by the person or extracted from the resource.';
$string['privacy:metadata:motivo'] = 'The reason for a failure, when there is one.';
$string['privacy:metadata:retos_json'] = 'The six challenges proposed to the person.';
$string['privacy:metadata:enlace'] = 'The link to the challenge.';
$string['privacy:metadata:propio'] = 'Whether the challenge was written by the person.';
$string['privacy:metadata:timecreated'] = 'When the row was created.';
$string['privacy:metadata:timemodified'] = 'When the row was last modified.';
$string['privacy:metadata:core_files'] = 'Pulse stores the generated infographic (PNG) and game (HTML) as files in the course context.';
$string['privacy:metadata:core_message'] = 'Pulse sends a notification when a creation is ready or has failed.';
$string['privacy:metadata:anthropic'] = 'Chat questions are answered by Anthropic (Claude). The data below is sent with each question.';
$string['privacy:metadata:anthropic:question'] = 'The text of the question.';
$string['privacy:metadata:anthropic:history'] = 'A text digest of the earlier turns of the conversation.';
$string['privacy:metadata:anthropic:role'] = 'Whether the person is teaching staff or a student.';
$string['privacy:metadata:anthropic:coursecontext'] = 'The course content. For teaching staff only, also analytics: participants names, grades, completion and access logs.';
$string['privacy:metadata:openai'] = 'OpenAI only creates embeddings for the chat search (RAG).';
$string['privacy:metadata:openai:question'] = 'The text of the question, to find the relevant course content.';
$string['privacy:metadata:openai:coursecontent'] = 'The course content, when it is indexed.';
$string['privacy:metadata:epica'] = 'Creations, challenges and the history of the student are generated by the Epica service. The data below is sent with each request.';
$string['privacy:metadata:epica:sub'] = 'The Moodle ID of the person.';
$string['privacy:metadata:epica:email'] = 'The email address of the person.';
$string['privacy:metadata:epica:nombre'] = 'The full name of the person.';
$string['privacy:metadata:epica:rol'] = 'Whether the person is teaching staff or a student.';
$string['privacy:metadata:epica:curso'] = 'The ID and name of the course.';
$string['privacy:metadata:epica:peticion'] = 'What the person asked for, plus the resource text it is based on.';
$string['privacy:metadata:epica:grupo'] = 'The group of the person in the course, if any.';
$string['privacy:metadata:epica:intento'] = 'How many times the person has asked for the same resource.';
$string['privacy:export:creaciones'] = 'Creations';
$string['privacy:export:propuestas'] = 'Challenge proposals';
$string['privacy:export:retos'] = 'Challenges';
$string['privacy:export:ampliaciones'] = 'Resource expansions generated';
