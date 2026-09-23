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
$string['plugin_disabled_course'] = 'Pulse AI is not enabled for this course.';

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

// === Creation requests to Epica (infographics; v1.18.0) ===
$string['pulso:createactivity'] = 'Request a creation (infographic) from Pulse';
$string['creationquota_heading'] = 'Creation requests (Epica) — quotas';
$string['creationquota_heading_desc'] = 'Anti-abuse limits for creation requests sent through Pulse (infographics today; challenges and presentations in future versions). These count REQUESTS, not finished creations — Epica shares its queue across tools.';
$string['quota_user_section_day'] = 'Per user, section and day';
$string['quota_user_section_day_desc'] = 'Maximum creation requests a single user can submit for resources in the same course section, per day.';
$string['quota_user_course_day'] = 'Per user and day, in a course';
$string['quota_user_course_day_desc'] = 'Maximum creation requests a single user can submit within one course, per day.';
$string['quota_course_hour'] = 'Per course and hour';
$string['quota_course_hour_desc'] = 'Maximum creation requests a single course can generate in a rolling hour.';
$string['quota_course_day_floor'] = 'Per course and day — minimum';
$string['quota_course_day_floor_desc'] = 'Minimum daily requests allowed per course, regardless of enrolment size (see the multiplier setting below for the actual formula: the higher of this floor and enrolled users × multiplier).';
$string['quota_course_day_multiplier'] = 'Per course and day — multiplier per enrolled user';
$string['quota_course_day_multiplier_desc'] = 'Multiplied by the course\'s enrolled users to get the daily cap (e.g. 1.5 means 150 enrolled users allow up to 225 requests/day). The effective limit is the higher of this and the floor above.';
$string['quota_teacher_day'] = 'Per teacher and day (all courses)';
$string['quota_teacher_day_desc'] = 'Maximum creation requests a single teaching-staff member can submit per day, across ALL their courses.';