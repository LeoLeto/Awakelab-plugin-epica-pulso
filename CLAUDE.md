# block_pulso — Moodle AI analytics chat (Pulso AI)

## Versioning rule (MANDATORY — apply on EVERY change)

Every code change, however small, must bump BOTH values in `version.php`:

- `$plugin->version` — Moodle build number, format `YYYYMMDDXX` (increment `XX` for
  same-day changes).
- `$plugin->release` — semver string (`1.1.1` → `1.1.2`). Patch for fixes/tweaks,
  minor for new features.

The release is shown as a badge next to the chat title so the user can verify which
build is running. `chat_simple_view.php` reads `version.php` directly from disk
(placeholder `%%PULSO_VERSION%%`), so the badge updates on deploy without running
the Moodle upgrade. A DB upgrade (Site Administration → Notifications) is only
needed when `db/` files change (install.xml, upgrade.php, caches.php, tasks.php…),
but the `$plugin->version` bump is still mandatory every time.

## Architecture (chat request path)

- `chat_simple_view.php` — floating chat UI (inline HTML/CSS/JS, rendered by
  `block_pulso.php`). Sends messages to `api_chat_stream.php` via fetch + SSE
  (ChatGPT-style token streaming, progressive preview of partial JSON) and falls
  back automatically to `api_chat.php` (XHR/JSON) if streaming is unavailable.
  Design (v1.4+): DARK theme with Awakelab 2026 brand (deep blues bg, vivid cyan
  accents on dark, Poppins via Google Fonts, isotipo logo from
  media.awakelab.world); Phia-style home screen (`#pulso-home`) with preset
  action cards (`askPreset()`) in two sections (Analítica / Contenido; each card
  injects a full natural-language question into the pipeline — phrase new ones to
  hit analytics/structural intents, not generic single words, to avoid backlog
  bug #2's activity-matcher misfire) that
  hides on first message and returns via the "Nueva conversación" header button
  (`clearConversation()` — removes only `.pulso-message`/followups, NOT the home
  node). The home also has a "¿Qué puede hacer Pulso?" button
  (`showCapabilities()`) that renders a hardcoded, client-side capabilities list
  (no LLM/no cost, always well-formatted) + example follow-up chips, for quick
  onboarding of new users. Table/card field keys are translated via
  `PULSO_FIELD_LABELS` + `pulsoFieldLabel()` fallback. A meta-row value that is a
  run-on of ≥2 questions (e.g. the greeting's "preguntas que puedes hacerme") is
  rendered as a `<ul>` list (`pulsoSplitQuestions()` in `renderMetaRow`) instead
  of the 2-column `key|value` grid, which clipped/broke in narrow chat widths. Design tokens are CSS vars (`--pulso-*`) on
  `.pulso-chat-container`.
  Voice input (v1.5+): mic button (`#pulso-mic-btn`, `toggleMic()`) does
  client-side speech-to-text via the Web Speech API (`SpeechRecognition`,
  `lang='es-ES'`, interim results streamed into `#pulso-input`). No backend / no
  API key. Auto-hidden (`initPulsoMic()` shows it only if the API exists — so
  Firefox just doesn't get the button) and only works in a secure context
  (HTTPS or localhost). Stops on send; friendly es/en alert if mic permission is
  blocked. Typing indicator (v1.6+): while the bot thinks, a WhatsApp-style AI
  bubble with three animated cyan dots (`showTyping()`/`hideTyping()`, driven by
  the existing `showLoading()` calls) appears in the message flow and is removed
  as soon as the first stream tokens / final answer arrive. This replaced the old
  `#pulso-loading` status bar (element and its `.pulso-loading`/
  `.pulso-pulsewave` CSS both removed in v1.8.1).
- `api_chat_stream.php` — SSE endpoint. Events: `status`, `delta`, `final`
  (same JSON shape as api_chat.php), `followups` (deferred, off the critical
  path), `error`.
- `api_chat.php` — classic JSON endpoint (fallback). Same behavior, follow-ups
  generated synchronously.
- `classes/chat_pipeline.php` — shared logic for both endpoints: enablement
  check, analytics context (2-min MUC cache + 250-row caps), RAG retrieval,
  history hygiene, history-hint ("ese pdf"), direct Moodle-backed answer routing.
- `classes/rag_retriever.php` — direct structural answers (sections, resources,
  quizzes…) + RAG chunk retrieval. Semantic course-level questions
  ("¿de qué trata el curso?") must return `null` from the direct path so the
  LLM answers with RAG context (`is_course_about_query()`).
- `classes/anthropic_connector.php` — Anthropic (Claude) calls for ALL chat
  answers: `claude-sonnet-5` (configurable via `block_pulso/model`, options
  also include `claude-opus-4-8` and `claude-haiku-4-5`) for main answers,
  `FAST_MODEL` (`claude-haiku-4-5`) for follow-up questions,
  `stream_query_with_context()` for SSE streaming (parses Anthropic's own SSE
  event types: `message_start`, `content_block_delta`, `message_delta`,
  `message_stop`). Uses `block_pulso/anthropic_key`, NOT `openai_key`.
- `classes/embedding_manager.php` — still uses OpenAI (`block_pulso/openai_key`,
  `text-embedding-3-small`) exclusively for RAG embeddings. This is the ONLY
  remaining use of the OpenAI key in the plugin — do not repurpose it for chat.
- Both endpoints call `\core\session\manager::write_close()` before calling the
  AI so the Moodle session lock doesn't freeze the user's other tabs.
  Server-side `$SESSION` history writes after that point don't persist — the
  client's sessionStorage copy is the source of truth.

### Anthropic API constraints (learned the hard way — do NOT regress)

- `claude-sonnet-5` (and other Claude 4.x/5 models) REJECTS assistant message
  prefill with 400 invalid_request_error ("The conversation must end with a
  user message"). Never append a `role: assistant` message to force JSON output
  — JSON purity is enforced via the "FORMATO DE SALIDA" system-prompt rule in
  `system_prompt_designer.php` plus the balanced-brace extraction in
  `chat_pipeline::clean_answer()`.
- `claude-sonnet-5` / `claude-opus-4-8` reject non-default `temperature` /
  `top_p` / `top_k` with 400. Omit them in main-answer payloads.
  `claude-haiku-4-5` (follow-ups) does accept `temperature`.
- Claude pretty-prints JSON by default (unlike gpt-4o) — much longer output.
  Main answers use `max_tokens` 3000 (800/2000 truncated long rankings
  mid-array → invalid JSON → raw-text fallback in the UI) and the system prompt
  demands compact single-line JSON, flat `data` array, capping at ~10 items.
- The model occasionally emits malformed JSON anyway (seen live:
  `"data":[[{...}]` — double bracket closed once). `chat_pipeline::clean_answer()`
  runs a validated repair pass (`repair_json_object()`): flatten accidental
  `[[`, strip trailing commas, close truncated strings/brackets — each candidate
  must pass `json_decode` or the original text is kept.
- `moodle_exception` signature: 4th arg is `$a` (lang-string interpolation),
  5th is `$debuginfo`. API error details must go in the 5th.
- Frontend note: `formatRichTextResponse()` in `chat_simple_view.php` escapes
  HTML ONCE for the whole block; helpers it calls (`renderMetaRow`,
  `extractFinalAnswerBlock`) receive already-escaped text — re-escaping there
  renders literal `&quot;`.

## Bug backlog — evaluación jul-2026 (arreglar en este orden)

Evaluación de 56 preguntas reales (resultados y prompts de arreglo detallados en
`Pulso_AI_matriz_evaluacion.xlsx`, pestaña "Preguntas"). Los fallos se agrupan en
6 bugs raíz. Recuerda la Versioning rule (bump `version.php`) en CADA arreglo, y
mantén los strings de usuario en español (+ `lang/en/block_pulso.php`).

**#1 (crítico, empezar por aquí). Keywords analíticas en `is_pdf_content_query`.**
`classes/rag_retriever.php`, función `is_pdf_content_query()` (~línea 1622). La
regex incluye keywords ANALÍTICAS (`nota media`, `calificación media|promedio`,
`cuántos alumnos|estudiantes`, `quién ha completado`, `cuántos intentos`). Eso hace
que preguntas de analítica se clasifiquen como "contenido de documento"
(`isContentIntent=true`) y se respondan sobre un PDF → "el documento no proporciona
información sobre la nota media", incluso en cursos CON notas. Comprobado: `%
aprobados` (sin esas keywords) sí funciona por analítica. Fix: quitar esas keywords
de la regex. No romper: la nota media de UN quiz/tarea concretos debe seguir yendo
por la rama `$asksGradeData` de `build_quiz_answer()`/`build_assign_answer()`; el
contenido real de un PDF ("dame el enunciado del problema 1") debe seguir en
content_mode. Afecta: P12, P15, P16, P25, P28, P29, P45, P52.

**#2 (alta). El matcher engancha por palabra genérica.**
`classes/rag_retriever.php`, `match_activity_by_name()`. Empareja un recurso/actividad
cuando comparte UNA sola palabra genérica con la pregunta ("alumno", "nota", "resumen",
"estudiante", "investigación"…) y devuelve ese PDF al azar. Rompe preguntas sueltas,
los seguimientos conversacionales y hasta los botones (En riesgo/Notas), porque los
botones inyectan una pregunta en lenguaje natural que pasa por el mismo pipeline. Fix:
subir el umbral (puntuar el nombre completo, usar números/ordinales como discriminador,
ampliar stopwords, priorizar la sección mencionada); si no hay match claro NO devolver
recurso; dar prioridad a las intenciones analíticas sobre el match de actividad. Afecta:
P4, P6, P30, P31, P32, P33, P34, P39, P53, P54.

**#3 (media). Contaminación de contexto / history-hint.**
`classes/chat_pipeline.php`, `build_direct_query()` / `find_resource_in_history()`. El
history-hint arrastra el último recurso visto aunque la nueva pregunta nombre otra cosa
(reproducido: con chat sucio devuelve recursos viejos; Ctrl+F5 lo arregla). Fix: si el
mensaje nombra explícitamente una actividad/recurso, prioridad al nombre y no aplicar el
hint; limitar el hint a continuaciones claras ("ese", "este", "el anterior"); ampliar
`$alreadyNamesResource` para nombres de tarea. Afecta: P14, P37, P38, P50, P51.

**#4 (media, config de servidor). Extracción de PDF rota.**
`classes/content_extractor.php`, `extract_pdf_text()`. El parser naïve no lee PDFs con
fuentes CID TrueType / Identity-H (confirmado en sandbox: `pdftotext`/poppler SÍ extrae
el texto, `pypdf` y el parser del plugin no; NO hace falta OCR). Fix: instalar
`poppler-utils` (pdftotext) o `smalot/pdfparser` en el servidor y asegurar que esa
estrategia se usa; revisar el orden de estrategias (el parser naïve puede colar basura
>20 chars antes de llegar a pdftotext). Para PDFs realmente escaneados, mensaje claro.
Afecta: P22, P23, P24.

**#5 (baja). Referencias por posición ("el primero/segundo") no se resuelven.**
`classes/chat_pipeline.php`. "dame el enunciado del primero" se interpreta por keyword y
engancha una actividad llamada "Resumen"/etc. Fix: resolver ordinales al N-ésimo recurso
del historial reciente. Afecta: P51.

**#6 (mejoras de funcionalidad).** Extracción de contenido Office `.docx`/`.pptx` en
`content_extractor.php` (P39); ranking de alumnos por nota respetando privacidad (P34);
e **indexación del contenido de los SCORM** (`extract_module()` no soporta `scorm`, por
eso no puede resumir ni explicar el material de un SCORM; es la principal ventaja del
plugin Phia) + modo "resumen/explicación de unidad" orientado al alumno acotado al SCORM
actual (P55, P56).

## Dev notes

- No PHP installed locally: lint with the portable PHP in the session scratchpad
  (download `php-8.3-nts` zip from windows.php.net) or Docker (`php:8.2-cli`)
  if the daemon is running.
- The big JS blob lives inside a nowdoc heredoc in `chat_simple_view.php` —
  `${...}` template literals are safe there; the small `JSINIT` heredoc DOES
  interpolate PHP variables.
- Language: UI and answers are Spanish-first; keep new user-facing strings in
  Spanish and add lang strings to `lang/en/block_pulso.php`.
