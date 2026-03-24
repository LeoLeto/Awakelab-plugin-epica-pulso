<?php
$string['pluginname'] = 'Pulso AI';
$string['pulso:addinstance'] = 'Add a new Pulso block';
$string['pulso:myaddinstance'] = 'Add a new Pulso block to the My Moodle page';
// Settings strings for T2.1.4
$string['setapikey'] = 'OpenAI API Key';
$string['setapikey_desc'] = 'Enter your secret API key from OpenAI. This will be stored securely and used to process analytics queries.';
$string['setmodel'] = 'AI Model';
$string['setmodel_desc'] = 'Choose the OpenAI model to use for analyzing course data.';

// === CHAT QUERY UI STRINGS (T2.3.2) ===
$string['chat_title'] = 'Pulso Analytics AI';
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
$string['error_no_response'] = 'No response received from the AI service.';
$string['error_api_error'] = 'API Error: {$a}';
$string['error_invalid_course'] = 'Invalid course ID.';
$string['error_no_permission'] = 'You do not have permission to use this feature.';
$string['error_timeout'] = 'Request timed out. Please try again.';

// === ACCESSIBILITY ===
$string['send_message'] = 'Send message';
$string['close_alert'] = 'Close alert';

// === T2.6.1: Per-course enable/disable ===
$string['coursecontrol_heading'] = 'Course Control';
$string['coursecontrol_heading_desc'] = 'Control whether Pulso is enabled by default for all courses.';
$string['enabled_by_default'] = 'Enabled by default';
$string['enabled_by_default_desc'] = 'If checked, Pulso will be active on all courses unless explicitly disabled per course.';
$string['plugin_disabled_course'] = 'Pulso AI is not enabled for this course.';

// === T2.6.2: Data access permission controls ===
$string['pulso:viewanalytics'] = 'View Pulso analytics data';
$string['dataaccess_heading'] = 'Data Access Controls';
$string['dataaccess_heading_desc'] = 'Choose which data categories are available for AI analysis. Disabling a category will prevent it from being sent to OpenAI.';
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
$string['rag_enabled_desc'] = 'When enabled, Pulso will embed course content and inject relevant fragments into each query so the AI can answer questions about course material (e.g. explain an exercise, solve a problem from the course).';
$string['task_index_course_content'] = 'Index course content for RAG (Pulso AI)';