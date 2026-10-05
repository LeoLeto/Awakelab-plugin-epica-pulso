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
- Both endpoints call `\core\session\manager::write_close()` **antes de contexto, RAG
  y Anthropic** (v1.31.0; hasta v1.30 iba después de `get_rag()`, que llama a OpenAI con
  hasta 60 s de timeout y congelaba las demás pestañas): primero `prepare_history()` (lo
  único que lee `$SESSION`), luego `write_close()`, luego contexto + RAG y por último
  `drop_no_access_replies()` (el filtro de contradicciones RAG, que necesita el contexto).
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

## Invariantes que NO se pueden romper (v1.8.2–1.8.5)

Cuatro fallos críticos arreglados tras la auditoría del 2026-08-04. Las reglas
que los evitan son poco intuitivas, así que quedan escritas aquí:

- **Nunca cortar texto con `substr`/`strlen`** en nada que acabe en un payload de
  Anthropic (historial, respuestas, texto de PDF). Los contenidos son UTF-8 en
  español: cortar por bytes parte un acento en dos, `json_encode()` devuelve
  `false`, se POSTea un cuerpo vacío y la API responde 400 **en cada mensaje
  siguiente** hasta que el usuario limpia la conversación. Usar siempre `mb_substr`
  / `mb_strlen`. El payload se serializa solo vía
  `anthropic_connector::encode_payload()` (aplica `JSON_INVALID_UTF8_SUBSTITUTE`
  y falla ruidosamente); no llamar a `json_encode()` a pelo para las peticiones.
- **La indexación RAG jamás corre dentro de la petición de chat.** Extraer PDFs +
  embeddings tarda minutos y cuesta dinero. `rag_retriever` solo puede *encolar*
  (`request_background_index()` → tarea adhoc `index_course_adhoc`), nunca llamar
  a `index_course()` en línea; esa función es exclusiva de las dos tareas de cron.
  El throttle `INDEX_REQUEST_THROTTLE` (6 h, marca en config `lastindexqueue_N`)
  existe porque un curso sin fragmentos recuperables se reencolaría en cada
  mensaje. Efecto secundario aceptado: un curso recién creado no tiene contexto
  RAG hasta que pase el cron.
- **Los cmid sintéticos de los chunks que no son de un módulo** (`course_meta`,
  `course_section`) se calculan SOLO con `content_extractor::course_meta_cmid()` /
  `course_section_cmid()` (espaciado `SYNTHETIC_CMID_STRIDE`). El esquema anterior
  (`-$courseid` para metadatos) chocaba con la sección 4 del curso `id/1000`, y
  como el upsert de `embedding_manager` buscaba por `(cmid, chunk_index)` sin
  `courseid`, **sobrescribía filas de otro curso**. El filtro del upsert debe
  incluir siempre `courseid`.
- **El `system` va en BLOQUES, no en un string, y el orden importa** (v1.9.0). El
  prompt base (~4.500-5.100 tokens, idéntico para todos los cursos y profesores)
  lleva `cache_control: {type: ephemeral}` en
  `system_prompt_designer::generate_system_blocks()`. El caché de Anthropic es un
  match de **prefijo**: cualquier byte que cambie antes del breakpoint lo invalida
  todo, así que en el bloque cacheado **solo** puede ir texto invariable. Las reglas
  RAG y el JSON de analítica van después *aunque parte de su texto sea fijo*, porque
  son **condicionales** y una sección condicional dentro del bloque cacheado crearía
  una entrada distinta por combinación. Dos trampas silenciosas: (a) el mínimo
  cacheable depende del modelo y **no es monótono** — 1024 tokens en `claude-sonnet-5`
  y `claude-opus-4-8`, pero **4096 en `claude-haiku-4-5`**, y por debajo del umbral el
  caché no se crea sin dar ningún error (hoy hay solo ~10% de margen sobre el 4096, así
  que recortar el prompt base tiene un coste oculto); (b) con caché activo
  `input_tokens` cuenta **solo el resto no cacheado**, así que `tokens_used` suma
  `input + output + cache_creation + cache_read` — no volver a sumar solo input+output.
  Para comprobar que funciona: `cache_read_input_tokens` en la respuesta debe ser > 0 a
  partir del segundo mensaje; si es 0 siempre, algo está rompiendo el prefijo estable.
  Y una tercera trampa, esta descubierta el 2026-09-07 al editar el fichero: **el
  prefijo cacheado depende de los FINALES DE LÍNEA del checkout**. El prompt base es un
  nowdoc, así que sus `\r\n` o `\n` son bytes del prompt; el repo tiene
  `core.autocrlf=true` y ningún `.gitattributes`, de modo que
  `system_prompt_designer.php` está en el índice con LF y en el árbol de Windows con
  CRLF. Consecuencias: (a) **nunca editar ese fichero con `sed -i`** ni con nada que
  reescriba el fichero entero — aplana los CRLF y eso solo, sin tocar una palabra,
  invalida el prefijo de ese entorno; (b) el md5 del prompt base solo es comparable
  dentro de un mismo estilo de checkout, así que un md5 medido en Windows no sirve para
  verificar el del servidor (que tiene LF); (c) dev y producción usan, por diseño, dos
  entradas de caché distintas — cada una estable en su entorno, que es lo que importa.
- **El historial NUNCA contiene JSON** (v1.12.0). Antes se guardaba la respuesta
  cruda (el JSON del esquema) y `prepare_history()` la cortaba a 500 caracteres: el
  payload acababa con turnos de asistente que eran objetos JSON **sin cerrar**, y con
  el prompt exigiendo "tu respuesta COMPLETA debe ser solo el objeto JSON", el modelo
  continuaba ese objeto en vez de responder a la pregunta nueva — devolvía la
  respuesta anterior entera. Ahora todo turno de asistente pasa por
  `chat_pipeline::history_digest()` (título + summary + content + unas filas de
  `data`, en TEXTO), tanto al guardar como al recibir el historial del cliente (el
  `sessionStorage` de los usuarios ya tenía JSON sucio) y también en el JS
  (`pulsoHistoryDigest()`). Dos detalles que hay que respetar si se toca: el digest
  une las partes con **salto de línea, no con punto**, porque el history-hint localiza
  el recurso del turno anterior con anclas de línea (`^Recurso:`, `^Seccion:`); y un
  JSON que no decodifica se limpia con `strip_json_noise()` en vez de guardarse tal
  cual. La regla "responde solo a la última pregunta" vive en el bloque **dinámico**
  del prompt (`build_dynamic_prompt_section`), no en el base, para no invalidar el
  prefijo cacheado.
- **El digest del historial NO lleva filas de `data`, y el del cliente tiene que
  respetar los saltos de línea** (v1.14.0). Dos trampas del digest, las dos vistas en
  producción: (a) las filas de `data` de una respuesta de analítica son una tabla de
  métricas con el MISMO aspecto que el formato de salida exigido en el prompt, y el
  modelo la trataba como plantilla a continuar — tras una respuesta de analítica, la
  pregunta siguiente se contestaba repitiendo la tabla anterior; ahora el digest es
  solo `title` + `summary` + `content`. (b) El digest del JS (`pulsoHistoryDigest`)
  unía las partes con punto y aplastaba los `\n`, así que las anclas `^Recurso:` /
  `^Seccion:` desaparecían y `^Cuestionario:\s*(.+)$` capturaba la FRASE ENTERA como
  nombre del recurso: el history-hint pegaba esa frase a la pregunta y la ruta directa
  dejaba de reconocerla. El digest del cliente y el del servidor deben producir
  exactamente lo mismo, con saltos de línea. Como el `sessionStorage` de los usuarios
  ya contiene digests sucios, `sanitize_history_name()` corta el nombre en el primer
  punto/salto y descarta lo que no parece un nombre (>120 caracteres, otro campo
  detrás): esa red no se puede quitar.
- **Los tres endpoints exigen `require_sesskey()`** (`api_chat.php`,
  `api_chat_stream.php`, `toggle_course.php`) y el orden de validación es
  autenticar → sesskey → permisos → `check_enabled()`. `check_enabled()` no puede
  volver a subir antes de `require_login()` (filtraba a anónimos si Pulso estaba
  activo en un curso). El cliente manda el token desde `window.pulsoSesskey` en
  `buildChatFormData()`: cualquier endpoint nuevo debe recibirlo por ahí.

## Modo alumno — dos capabilities, tres capas (v1.10.0)

Desde v1.10.0 un ALUMNO puede usar el chat, pero solo para CONTENIDO. Las reglas:

- **Dos capabilities, no una.** `block/pulso:usechat` (student + profesorado) es el
  permiso mínimo: renderiza el bloque y admite la petición en los dos endpoints.
  `block/pulso:viewanalytics` (solo editingteacher/teacher/manager) es lo que
  habilita cualquier dato del grupo. Un endpoint nuevo pide `usechat` y calcula el
  rol con `chat_pipeline::user_can_view_analytics()` (memoizada por petición); no
  volver a comprobar `viewanalytics` a mano en sitios nuevos.
- **La UI no es un control de acceso.** `render_chat_simple($courseid, $context,
  $isteacher)` elimina del HTML el bloque del otro rol (marcadores
  `<!--PULSO_TEACHER_ONLY_START/END-->` y `<!--PULSO_STUDENT_ONLY_*-->`, borrados con
  `preg_replace`) y expone `window.pulsoIsTeacher` solo para adaptar textos. El
  bloqueo real está en las tres capas de servidor de abajo. Si añades un bloque
  para un rol, envuélvelo en esos marcadores; si añades un texto de capacidades en
  el JS, recuerda que el del otro rol SÍ viaja en el fuente (es copy estático, sin
  dato de curso: aceptado a propósito).
- **Capa 1 — el gate.** `chat_pipeline::is_teacher_only_query()` corta la pregunta
  en los dos endpoints ANTES de contexto, RAG y Anthropic (coste 0, dato 0) y
  responde con `teacher_only_refusal()`. Detector deliberadamente conservador
  (privacidad > cobertura), calibrado contra las 56 preguntas de la matriz + un
  juego de variantes de alumno: patrones inequívocos que niegan solos, más
  co-ocurrencia grupo+métrica y agregado+participación. `hoy` y `todos` quedaron
  FUERA a propósito (falsos positivos de contenido); si tocas las regex, vuelve a
  pasar las pruebas antes de dar por bueno el cambio.
- **Capa 2 — el contexto.** `get_unified_course_context($courseid, $daysback,
  $includeanalytics)` con `false` **no ejecuta** las consultas de analítica ni los
  recuentos de personas: el dato no se lee de la BD, no es un filtro posterior. La
  clave de la caché MUC incluye el rol (`$courseid` vs `$courseid . ':nostats'`) —
  sin eso, la versión sin analítica de un alumno se serviría a un profesor. Y
  `total_students`/`total_enrolled_users` no se dan al alumno (decisión de producto:
  "nada del grupo", más fácil de explicar que una lista de excepciones).
- **Capa 3 — la ruta directa.** `build_quiz_answer()`, `build_assign_answer()` y
  `build_generic_activity_answer()` responden desde la BD **sin pasar por el LLM**,
  así que consultan el permiso en el punto donde construyen el dato y devuelven
  `chat_pipeline::teacher_only_payload()` o ponen los contadores a 0/null. Lo que un
  alumno sí sigue viendo de una actividad: nombre, descripción, fechas, nº de
  preguntas e intentos PERMITIDOS (configuración pública).
- **El prompt base del profesorado no cambia ni un byte** — su prefijo cacheado
  sigue siendo el mismo (verificado por md5). El alumno tiene su propio bloque base
  (`generate_student_system_prompt()`), también marcado con `cache_control`, pero con
  ~860 tokens está por debajo del mínimo de 1024: no se cachea, en silencio y sin
  coste extra. Si algún día se alarga, empezará a cachearse solo.
- Las sugerencias de seguimiento del alumno se filtran con
  `filter_student_followups()` **además** de pedírselas al modelo con el prompt de
  alumno: el catálogo determinista propone "¿cuántos alumnos han entregado…?" y no
  se le ofrece a alguien a quien se le va a negar.

Fuera de alcance a propósito en esta versión: el alumno no ve NI SUS PROPIOS datos
individuales (se le redirige al libro de calificaciones). Si se quiere "solo mis
notas", es una cuarta capa nueva, no un relajamiento de las tres actuales.

## Enrutado: sección vs recurso vs metadatos (v1.13.x)

Tres reglas de desempate en `rag_retriever`, salidas de la evaluación del
2026-09-04 (curso SANS0001). Si se toca una, hay que volver a pasar los cuatro
casos de control: listado de sección, "¿qué hay en el tema N?", resumen de un
recurso por nombre y nº de preguntas de un cuestionario.

- **El listado de sección gana al match de recurso.** `is_section_listing_query()`
  (referencia a sección/tema/unidad/bloque + cuantificador de listado: "qué hay
  en", "qué actividades", "qué contiene", "lista") corta el match por nombre de
  actividad y responde con `build_section_listing_answer()`. Sin eso, "¿qué
  actividades hay en la sección RECURSOS?" contestaba con UN PDF: la sección se
  identificaba bien y luego `resolve_direct_resource_in_section_query()` devolvía
  `array_values($resources)[0]`. Ese "coge el primero" ahora solo se aplica si la
  sección tiene UN único recurso. Si la pregunta pide contenido o una acción
  ("resume", "el enunciado de", "qué dice"), no hay cuantificador de listado y gana
  el recurso — que es lo que se quiere.
- **"tema N" / "unidad N" / "bloque N" es la sección N.** Fase 2b de
  `find_matching_section_for_query()`, después del match por nombre exacto (si
  existe una sección llamada literalmente "Tema 2", gana ella). Sin esto, "¿qué hay
  en el tema 2?" enganchaba un recurso llamado "MIC Tema 2".
- **Metadatos solo para identificación o ubicación.** `is_identification_query()`
  (que incluye `is_location_query()`) decide cuándo la respuesta correcta es
  nombre/archivo/formato. Para todo lo demás, si la pregunta nombra un recurso y
  pide algo de su contenido —incluidos los fraseos de
  `is_resource_content_question()`: "qué dice", "qué explica", "qué información
  hay", "de qué habla", "según el documento"— se abre el documento
  (`content_mode`). Si no hay texto extraíble, se dice explícitamente en vez de
  devolver metadatos que parecen una respuesta.
- Las **stopwords del matcher difuso** incluyen ya las genéricas de nombre de
  recurso (`material`, `recurso`, `documento`, `archivo`, `contenido`, `unidad`…):
  un PDF llamado "MATERIAL 1" no puede engancharse por la palabra "material" de la
  pregunta. Y desde v1.14.1 el difuso empareja por **palabra completa**, no por
  subcadena (`preg_match` con límites, no `strpos`): las siglas y los nombres de 1-3
  caracteres ("IA", "T1") solo pueden ganar por match exacto de nombre completo.
- **"No hay match" es una respuesta válida.** Si la pregunta nombra una actividad con
  etiqueta de tipo delante ("la actividad IA", "el foro de novedades") y ninguna
  actividad del curso se llama así (`query_mentions_any_activity_name()` con
  `get_fast_modinfo`), `build_missing_activity_notice()` mete en el contexto un aviso
  que obliga al modelo a decir que no existe y a ofrecer las reales. Sin él, la
  recuperación semántica devolvía los fragmentos "menos malos" (el foro del curso) y
  el modelo los presentaba como respuesta, inventándose sus cifras. Y una referencia
  ordinal que el historial no puede satisfacer ("dame el enunciado del primero" sin
  recursos previos) se responde con `ordinal_unresolved_answer()` — nunca se deja
  seguir a la ruta directa, que acababa emparejando por palabra clave.
- **"Resúmeme el curso" es contenido, no analítica.** La ruta directa ya devolvía
  `null` (`is_course_about_query()`), pero el prompt base es de analítica educativa
  y el modelo contestaba con secciones, matriculados y tasa de aprobación. Ahora
  `generate_system_blocks()` recibe el `$user_query` y añade al bloque **dinámico**
  una sección que obliga a responder con el temario (y permite al profesorado UNA
  sola línea de contexto analítico; al alumno, ninguna).

## Conversación proactiva — dos caminos, y el defecto es callarse (v1.15.0)

El chat ofrece el siguiente paso útil cuando aporta. La decisión de producto, que es lo
que hay que respetar antes que cualquier detalle: **por defecto NO se ofrece nada**. El
ofrecimiento solo aparece si hay un siguiente paso concreto que el usuario querría Y se
puede cumplir con el contexto que ya está delante; si no, se responde y se calla. Un
ofrecimiento en cada respuesta es ruido, alarga todas las respuestas y la gente deja de
leer la última línea. En una respuesta de dato puntual ("tiene 15 preguntas", "hay 4
secciones") **no puede salir**.

La regla se implementa en DOS sitios porque el chat tiene dos caminos, y una regla en el
prompt no cubre el otro:

- **El ofrecimiento viaja en su PROPIO campo, `next_step`** (v1.15.2), y esto es un
  invariante, no un detalle de estilo. La v1.15.0 pedía la frase "como última frase de
  `content`" y salió **0 ofrecimientos en 10 respuestas** en el QA. Dos causas apiladas,
  las dos estructurales: (a) **`content` NO existe en el esquema de salida del LLM**
  (`{type,title,summary,data,insights,recommendations,language,confidence}`) — es un campo
  de los payloads de la *ruta directa*, y la regla de `FORMATO DE SALIDA` prohíbe
  cualquier campo fuera del esquema, así que se le estaba pidiendo algo imposible; (b)
  aunque lo hubiera emitido, `formatAIResponse()` solo pinta `content` si
  `type === 'text'`, y `insights`/`recommendations` solo si `isAnalyticsQuestion(message)`
  — o sea que **ningún campo del esquema se pinta en los cuatro layouts**: en una
  respuesta de analítica (`type: table`) `content` es invisible por diseño, y en una de
  contenido lo son las recomendaciones. Reglas que se derivan: `next_step` se declara en
  el bloque **dinámico** (no en el base: no toca el prefijo cacheado), se autoriza
  **explícitamente** en `FORMATO DE SALIDA` (sin esa excepción la regla es incumplible),
  se pinta como bloque aparte **sin depender de `type` ni de `isAnalyticsQuestion`**, y
  entra en el digest del historial (`history_digest()` y `pulsoHistoryDigest()`, las dos)
  porque la regla de "no repitas el ofrecimiento dos turnos seguidos" necesita ver el del
  turno anterior — y sin `content` en el esquema, el digest de una respuesta del modelo
  sería solo título + resumen. **Si algún día se añade otro campo a la respuesta, la
  pregunta que hay que hacerse es la misma: ¿está en el esquema, lo autoriza el formato,
  y lo pinta el frontend en TODOS los layouts?**
- **Respuestas del LLM** → sección `## INICIATIVA` en `build_dynamic_prompt_section()`.
  Va en el bloque **dinámico**, nunca en el base: depende del rol, y una sección
  condicional dentro del bloque cacheado crearía una entrada de caché por combinación.
  Está redactada al mínimo (~420 tokens con la autorización de `next_step` incluida, unos
  $0,84 por mil mensajes en `claude-sonnet-5` a $2/MTok de input) porque **se paga sin
  cachear en cada respuesta que pasa por el LLM**: antes de añadirle una línea, quita
  otra. Solo la pagan las dos llamadas de respuesta principal —
  `answer_document_question()` y `summarize_document_text()` llevan su propio system
  prompt corto, y los follow-ups de Haiku también. Diferencia por rol: al docente, seguimiento
  del grupo; al alumno, repaso y materiales, con la prohibición de datos del grupo
  aplicada también al ofrecimiento (una sugerencia es una vía de fuga igual que una
  respuesta). El ofrecimiento va DENTRO del JSON, como última frase de `content`.
- **Rutas directas** → `chat_pipeline::build_direct_followups()`. No pasan por el LLM, así
  que sus sugerencias son su única forma de ofrecer un siguiente paso. Dos reglas al
  redactarlas: (a) meter el NOMBRE de la actividad, porque al pulsarlas el texto vuelve a
  entrar por el pipeline y el matcher lo necesita; (b) cada rama debe dejar al menos DOS
  sugerencias que sobrevivan a `filter_student_followups()` — si se cayeran todas, el
  alumno recibiría el catálogo genérico y perdería el seguimiento contextual. La tercera
  sugerencia de las ramas de actividad es de seguimiento del grupo a propósito: útil al
  docente, filtrada al alumno. El nombre se saca del título del payload con
  `direct_answer_activity_name()`, que devuelve '' para los títulos de listado (un
  "Contenido de la sección X" metido en una sugerencia la deja irreconocible para el
  matcher).

Y un desempate de la misma tanda: **un listado no es un material**. El nombre de una
sección ("RECURSOS") mete "recurso" en `qnorm`, así que los listados (`Contenido de la
sección X`, `Secciones del curso`) se resuelven ANTES de la rama de documento.

## Bug backlog — evaluación jul-2026 (arreglar en este orden)

**OJO: esta sección está desactualizada** — #1 a #5 y casi todo #6 ya se arreglaron
antes de la migración a Anthropic. El estado real está en
`memory/session-history.md`; lo único vivo de #6 es el modo "resumen de unidad"
sobre SCORM (P55/P56).

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

## Enlaces directos a actividades (v1.11.0)

Las respuestas directas que resuelven una actividad o recurso llevan un campo
`link` en el payload (`['url' => ..., 'label' => ...]`), que `chat_simple_view.php`
pinta como botón "Ir a…" (`.pulso-goto-link`) al final de la respuesta. Reglas:

- La URL se construye SIEMPRE en el servidor con `moodle_url` desde
  `rag_retriever::build_activity_link()`. **Nunca** la genera el LLM (se las
  inventa).
- Ese helper comprueba `get_fast_modinfo($courseid)->get_cm($cmid)->uservisible`:
  si la actividad está oculta o restringida para el usuario, **no** devuelve
  enlace. Es lo que evita que el modo alumno se salte la visibilidad.
- `attach_activity_link()` decora el payload en los cuatro constructores
  (`build_quiz_answer`, `build_assign_answer`, `build_generic_activity_answer` y
  la rama de recursos de `resolve_direct_resource_query`). Si la pregunta es de
  ubicación/acceso (`is_location_query()`), añade además la línea
  `Ubicacion: Seccion N: Nombre` y el texto determinista de "cómo usarlo"
  (`activity_usage_hint()`, sin coste de IA).
- El payload entero se serializa con `json_encode($direct_course_answer)` en
  `chat_pipeline::resolve_direct_answer()`, así que cualquier campo nuevo llega
  al frontend como `data.<campo>` sin tocar los endpoints.
- El botón se pinta como campo aparte, NO dentro de
  `formatRichTextResponse()` (que escapa el bloque entero una sola vez y
  convertiría el `<a>` en texto literal).

## Tema claro (variante B) — paleta del chat (v1.16.0)

Desde v1.16.0 el chat es de tema CLARO: cuerpo claro, cabecera azul profunda
(`#003670`) que se mantiene oscura como ancla de marca. Todo vive en las variables
`--pulso-*` definidas en `.pulso-chat-bubble, .pulso-chat-container` dentro de
`chat_simple_view.php`, más una pasada de `rgba()` y hex sueltos que NO pasaban por
esas variables (ver abajo). Paleta activa:

| Variable | Valor | Papel |
|---|---|---|
| `--pulso-bg` | `#FFFFFF` | fondo del panel |
| `--pulso-surface` | `#F7F9FD` | tarjetas, sugerencias |
| `--pulso-surface-2` | `#EDF1FA` | cabecera de tabla, chips |
| `--pulso-deep` / `--pulso-navy` | `#003670` | cabecera del chat, botones de acción |
| `--pulso-ink` | `#27334F` | texto principal |
| `--pulso-slate` | `#34547A` | texto secundario |
| `--pulso-muted` | `#3B6996` | etiquetas pequeñas |
| `--pulso-line` | `#DCE3F2` | separadores y bordes |
| `--pulso-cyan` / `--pulso-teal` / `--pulso-teal-ink` | `#0B93AA` | acento (iconos, bordes, foco — nunca texto) |
| `--pulso-cyan-soft` | `#D9FBFF` | cian claro de marca (fondo del bloque de siguiente paso) |

Estados (sustituyen a los pasteles del tema oscuro, ilegibles sobre blanco):
success `#0F7A57` (5,33), warning `#8A6100` (5,54), danger `#B3261E` (6,54). Para
fondos tenues de píldoras, sube la alfa del `rgba()` lo justo para que se note sobre
blanco; el color del texto es siempre el de esta tabla, nunca el pastel antiguo.

**El cian deja de ser color de texto en claro — invariante, no detalle de estilo.**
Sobre fondo blanco: `#11EAEA`→1,50, `#19F7F1`→1,34, `#0ABCC9`→2,32 (inservibles),
`#0B93AA`→3,64 (solo iconos/bordes/foco/fondos de botón, mínimo AA no-texto),
`#4E7EA5`→4,33 (insuficiente para texto), `#3B6996`→5,76, `#34547A`→7,79,
`#003670`→11,92, `#27334F`→12,55 (correctos para texto). Si necesitas un color
"de marca pero no cian" para texto sobre fondo claro (p.ej. un badge que no encaja
en success/warning/danger), usa `#34547A` — está en la paleta oficial y da 7,79;
NO inventes un tono fuera de paleta (pasó una vez con un morado para badges
`page/book/wiki` en el propio recolor a v1.16.0 — se corrigió antes de mergear).

**Sobre fondo cian NO hay ningún texto que cumpla AA — ni blanco ni un azul
oscuro.** Medido para el botón "Ir a…" (`.pulso-goto-link`, antes fondo
`var(--pulso-cyan)` + texto `var(--pulso-deep)`): blanco sobre `#0B93AA` da 3,64;
`#01264C` (el azul casi-negro de la paleta) sobre `#0B93AA` da 4,17 — los dos por
debajo del 4,5 que exige texto normal. Por eso un botón de ACCIÓN con texto (no un
icono suelto, que solo necesita 3:1) va siempre en `#003670` con texto blanco
(11,92 de contraste), nunca en fondo cian. El cian como fondo solo es seguro para
iconos/SVG sin texto encima (ahí aplica el umbral no-texto de 3:1, que si cumple).

## Integración con Épica — paso 4: panel de estado, galería y aviso (v1.20.0)

Última pieza del ciclo: que el usuario vea el progreso de su encargo y reciba la
infografía, en vez del «Encargo guardado» estático del paso 1. Vive en
`api_create_status.php` (endpoint nuevo) + las funciones `renderCreateStatus`/
`loadCreateGallery`/`pollCreateStatusOnce` de `chat_simple_view.php` + un
`notify_completion()` nuevo en `classes/epica_client.php`.

- **El navegador NUNCA habla con Épica.** `api_create_status.php` solo lee la
  fila de `block_pulso_encargos` — quien sondea a Épica de verdad sigue siendo
  solo la tarea adhoc. El panel sondea NUESTRO endpoint cada 7 s y deja de
  hacerlo a los 30 minutos (`PULSO_CREATE_POLL_WINDOW_MS`), la misma cortesía
  que ya aplica `epica_client::FOREGROUND_WINDOW_S` en el servidor: pasada esa
  ventana, el aviso pasa a depender solo de la notificación de mensajería.
- **`avisos` llega de Épica como array de OBJETOS, no de strings** (visto en producción:
  «[object Object]» en el panel). `recoger_lamina()` lo sigue guardando CRUDO en JSON (fuente
  de verdad) y la normalización es al LEER, en `api_create_status.php`
  (`pulso_normalize_avisos()`): string → trim; objeto → primer valor string no vacío de
  `texto`, `mensaje`, `message`, `detalle`, `descripcion`, `aviso`, `motivo`; sin ninguna →
  se descarta (nunca el JSON del objeto); 300 caracteres (`mb_substr`), máx. 5, `[]` si no
  queda ninguno. Así vale también para filas ya guardadas. El cliente pinta solo strings
  (segunda red). Si Épica usa otra clave, añadirla a esa lista, no tocar `recoger_lamina()`.
- **Nunca se devuelve el base64 de la imagen.** El endpoint solo da la URL de
  `pluginfile.php` (vista y descarga, generadas con
  `moodle_url::make_pluginfile_url(..., $forcedownload)`), que revalida el
  acceso ella misma en `block_pulso_pluginfile()` (lib.php). Mismo criterio de
  acceso en el endpoint: dueño del encargo o quien tenga `viewanalytics` en el
  curso — un alumno que no hizo el encargo no puede consultar su estado ni ver
  su PNG.
- **El `sobre` (envelope JSON del modo de ensayo) solo viaja a quien tiene
  `viewanalytics`**, nunca al alumno aunque sea el dueño del encargo: es la
  señal de verificación contra el contrato de Épica, no algo que un alumno
  necesite ver. Se pinta en el panel dentro de un `<details>` plegable.
- **Un aviso por encargo, mandado desde `epica_client::notify_completion()`**,
  llamado desde `recoger()` (listo/fallado sin imagen) y `marcar_fallo()`
  (fallado/desconocido) — nunca desde el paso que deja un encargo en
  `ensayo` (termina en el mismo tick en que se crea; el usuario sigue delante
  de la pantalla, y no es una generación real). La columna `notified` en
  `block_pulso_encargos` es la garantía explícita de que se manda una sola
  vez: aunque un encargo terminal nunca se reprocesa (invariante ya
  documentada arriba), depender solo de eso habría dejado el "una vez" sin
  red de seguridad si esa invariante cambiara algún día. Usa el proveedor de
  mensajes `epica_encargo` (`db/messages.php`, nuevo — recuerda pasar por
  Notificaciones al desplegar).
  **Simplificación consciente**: no hay forma barata de saber desde una tarea
  de cron sin estado si el usuario "sigue mirando" el panel, así que el aviso
  se manda siempre al llegar a un estado terminal real, tenga o no el panel
  abierto. Si lo tiene abierto, es una notificación de más en la campana, no
  un aviso emergente — coste aceptado.
- **Los cuatro mensajes de error de la sección "Cuidado con" del encargo
  original son responsabilidad del CLIENTE** (`pulsoCreateFailureMessage()`),
  no del servidor: inspecciona el texto de `motivo` (que sí viaja tal cual)
  y distingue `cuota-agotada` (cupo propio, "vuelve a intentarlo en unos
  minutos") de `cuota-del-centro` (cupo del centro entero, "no es nada que
  hayas hecho tú" — la persona no ha gastado nada) de `material-ilegible`
  (fallo nuestro, nunca del usuario) del genérico (motivo de Épica sin
  adornos + botón para crear un encargo nuevo, nunca reintentar el mismo).
  **Gap conocido, apuntado como SIGUIENTE PASO — no arreglar sin que lo pida
  otro encargo**: hoy `cuota-agotada`/`cuota-del-centro` nunca llegan a
  aparecer en un `fallado` real, porque `epica_client::
  procesar_error_encargar()` trata CUALQUIER 429 como transitorio y lo
  reencola indefinidamente con el `retry_after` que dé Épica, sin límite de
  reintentos ni de tiempo total (el corte a los 30 min es solo de PRIMER
  PLANO del navegador; la tarea sigue sondeando en segundo plano). Revisado
  con Marcos: con `cuota-agotada` no urge (es el cupo del propio usuario, y
  reintentar solo hasta que le toque no es grave); con `cuota-del-centro` SÍ
  importa, porque el límite es de TODO el centro y puede tardar hasta el día
  siguiente en liberarse — mientras tanto el usuario ve su encargo en
  `pendiente` sin ninguna explicación, y el mensaje correcto
  (`pulsoCreateFailureMessage()`) ya está escrito pero nunca llega a
  mostrarse porque el encargo jamás pasa a `fallado`. Cuando se aborde: dar
  por terminado un `cuota-del-centro` tras un tope de reintentos o de tiempo
  (no necesariamente `cuota-agotada`, que puede seguir reintentando sin
  problema), y entonces sí que el mapeo de mensajes del cliente ya funciona
  sin tocarlo.
- **La galería ("últimas infografías") es SOLO de los encargos propios del
  usuario en el curso** (`api_create_status.php` sin `encargoid`) — no un
  listado de todo el curso para el profesorado; eso seguiría exigiendo
  comprobar `viewanalytics` por fila, no por vista completa, y no lo pidió el
  encargo. Se enseña siempre debajo del formulario, del aviso de cupo/sin
  recursos y de la vista de progreso: una lámina de ayer no deja de existir
  porque hoy no queden encargos o cupo.
- **La galería enseña SOLO infografías de verdad**: la consulta de
  `api_create_status.php` (rama sin `encargoid`) filtra en el SERVIDOR por
  `status = 'listo' AND filename IS NOT NULL AND filename <> ''`, además de
  `courseid`+`userid`. Un encargo `pendiente`/`encolado`/`trabajando`/
  `fallado`/`desconocido`/`ensayo` no sale ahí — ese seguimiento es cosa del
  panel de progreso (`renderCreateStatus`), no de la galería. `filename` es
  `char` en `install.xml`, así que la comparación es SQL directo, sin
  `sql_isnotempty()`. Consecuencia en el frontend: como el servidor ya
  garantiza imagen, `renderCreateGallery()` ya no pinta un placeholder con
  píldora de estado (se quitó junto con el CSS `.pulso-create-gallery-placeholder`,
  que se quedó sin uso) y una galería vacía muestra un texto explícito en vez
  de no pintar nada.
- **`db/install.xml` estaba desincronizado con `db/upgrade.php` desde
  v1.19.0**: las columnas del paso 3 (`epica_plataforma`, `mock`,
  `verificado`, `avisos`, `titulo`, `tema`, `arquetipo`, `filename`,
  `motivo`, `sobre_json`…) se añadieron con `ALTER TABLE` en `upgrade.php`
  pero nunca se reflejaron en `install.xml` — una instalación NUEVA se habría
  quedado sin ellas. Corregido en este paso de paso: `install.xml` ahora
  describe el esquema completo de una instalación fresca (paso 3 + `notified`
  de este paso), y `upgrade.php` sigue siendo el único camino para las
  instalaciones que ya existían. Si se toca esta tabla otra vez, comprobar
  SIEMPRE los dos ficheros a la vez — ese es el bug a no repetir.

## Gamificación — paso 1: ciclo con Épica reutilizado para juegos HTML (v1.21.0)

Segunda herramienta de Épica. **Un solo ciclo** (`classes/epica_client.php` +
`classes/task/epica_ciclo_adhoc.php`) sirve a las dos: firmar, encargar,
sondear y recoger son exactamente el mismo código, con la misma cadencia y el
mismo criterio de transitorio/tope de errores. Lo que cambia por herramienta
se decide con `$encargo->tool` (`creation_quota::TOOL_INFOGRAFIA` /
`TOOL_GAMIFICACION`), nunca duplicando el ciclo. Sin botón ni UI todavía —eso
es el paso 2— y sin página de juego, puente ni CSP —eso es el paso 3—.

- **La entrega va en la RAÍZ, al revés que la lámina.** Un `listo` de
  `/api/moodle/juegos/encargo` trae `html`, `titulo`, `tema`, `verificado`,
  `puntua`, `mock` sueltos en la raíz de la respuesta; la lámina sigue
  anidada bajo `lamina`. Son decisiones de dos momentos distintos de Épica y
  no se van a unificar, así que `epica_client::recoger()` despacha por
  `$encargo->tool` a `recoger_lamina()` o `recoger_juego()` — no hay una
  forma común que sirva a las dos.
- **`verificado: true` con `tema` vacío significa "no se comprobó", no "está
  bien"** (para juegos, igual que para láminas): se guarda como `null`, y
  solo es `1`/`0` de verdad cuando `tema` no está vacío. `puntua` es una
  columna PROPIA de esta herramienta (int nullable, `null` para infografías o
  antes de "listo") — no reutiliza `verificado`, son dos señales distintas
  aunque las dos vengan como booleano de Épica.
- **`html` vacío es fallo terminal**, con el mismo espíritu que
  `diagnosticar_listo_sin_imagen()`: `diagnosticar_listo_sin_html()` registra
  la FORMA de la respuesta (claves de primer/segundo nivel, tipo y
  LONGITUD del campo `html`) y nunca su contenido — ni un fragmento del
  marcado, aunque sea corto. El HTML lo escribió un modelo a partir de
  material que puede ser del centro; no hay "preview seguro" como el de la
  imagen (30 bytes de base64 no dicen nada, 30 caracteres de HTML sí).
- **Se guarda LIMPIO, exactamente como llega**, en una filearea PROPIA
  (`juego`, no `encargo`: ese nombre es del PNG) — `guardar_juego()`. El
  puente de puntuación y la CSP se inyectan al SERVIRLO (paso 3), nunca en
  el fichero guardado: si se inyectaran aquí, cambiar el puente algún día
  obligaría a regenerar todos los juegos ya guardados.
- **`block_pulso_pluginfile()` no sirve la filearea `juego` en este paso, y
  no hace falta ningún cambio para lograrlo**: el filtro ya existente
  (`$filearea !== 'encargo'` → 404) bloquea cualquier filearea que no sea
  `encargo`, `juego` incluida. Es a propósito y está documentado en el
  código: el HTML lo escribió un modelo, y servirlo sin `sandbox`/CSP
  ejecutaría su JavaScript con el origen y la sesión de Moodle de quien abra
  el enlace. Habilitarla es currar el paso 3 completo (puente + CSP +
  iframe `sandbox="allow-scripts"`), no añadir una filearea a una lista.
- **El sobre es forma 1 únicamente** (`peticion` + `material` + `contexto` +
  `curso` + `alumno`): sin `formato` (es propio de la lámina), sin
  `plantilla` ni `juego_actual` (formas 2/3, fuera de alcance). Dos recortes
  que Épica pidió para las **dos** herramientas a la vez (carta 6 §B4):
  **`curso.nombre_corto` desaparece del sobre de las dos** (no lo lee
  ninguna) y **`curso.materia` no se manda nunca** (la categoría de Moodle
  no es una materia real; se omite en vez de mandar un dato falso — el día
  que un centro configure una materia de verdad en un campo propio, se
  añade, no antes).
- **`alumno.intento` cuenta por herramienta**, no solo por recurso:
  `resolve_intento()` añade `tool` al filtro. Un alumno con tres infografías
  previas de un recurso que pide su primer JUEGO sobre ese mismo recurso es
  intento 1, no 4 — son catálogos de intentos independientes (misma lógica
  que las cuotas independientes de Épica, carta 5 §6.1).
- **`herramienta: "gamificacion"` va también en el sondeo**, no solo al
  encargar (carta 5 §3.3 lo pide explícitamente para esta herramienta). El
  de láminas NO lo lleva y no se le añade: es un sobre ya probado en
  producción y "lo demás no cambia" es parte del contrato de este paso.
- **La galería de infografías filtra por `tool = 'infografia'`** en
  `api_create_status.php` — sin este filtro, en cuanto exista el primer
  juego `listo` (que también tiene `filename` relleno, solo que en su propia
  filearea) empezaría a colarse en la galería de infografías. La galería de
  juegos es del paso 2, con su propia UI y su propia consulta.
- **`notify_completion()` distingue género, no solo la palabra**: "el juego"
  (masculino) vs. "la infografía" (femenino) cambia también el adjetivo
  (listo/lista) y el artículo (ábrelo/ábrela) — sustituir solo el sustantivo
  habría dejado mensajes gramaticalmente rotos.
- **Los cupos siguen siendo un contador conjunto** (carta 5 §9, decisión ya
  tomada del lado de Épica): `creation_quota::record_encargo()` no filtra por
  `tool` al contar, así que el mismo contador protege a las dos herramientas
  hoy. Cuando el piloto necesite separarlos, es una decisión de producto
  nueva, no un bug de este paso.
- **`api_create_submit.php` acepta `tool`** (`infografia` por defecto, para
  no romper al cliente actual, que no manda ese parámetro todavía). Con
  `gamificacion`, `format` no se pide ni se valida — se guarda como cadena
  vacía, que la columna ya admite (`NOTNULL DEFAULT ''`), así que no hizo
  falta ningún cambio de esquema para ese campo.

## Gamificación — paso 2: jugar un juego de forma segura (v1.22.0)

El HTML de un juego lo escribió un modelo a partir de material del curso.
`juego_html.php` es la **ÚNICA vía** para servirlo — `block_pulso_pluginfile()`
sigue bloqueando la filearea `juego` **para siempre**, no como algo pendiente
de un paso futuro (ver `lib.php`). Reglas que no se pueden romper:

- **La CSP va en DOS sitios, con roles distintos.** El `<meta http-equiv=
  "Content-Security-Policy">` que `juego_html.php` inyecta en el `<head>`
  cierra la red del propio documento (sin `fetch`, sin fuentes externas,
  nada que no sea `data:`/`blob:` inline); pero una etiqueta `<meta>` **no
  puede** llevar la directiva `sandbox`. Por eso la cabecera HTTP real manda
  `Content-Security-Policy: sandbox allow-scripts; …`: es lo único que
  garantiza un origen opaco (`window.origin === "null"`, sin cookies, sin
  storage) aunque alguien abra `juego_html.php?id=N` directamente en una
  pestaña, **fuera** del iframe de `juego.php`. Sin la cabecera, abrir la URL
  a pelo ejecutaría el HTML con el origen real de Moodle.
- **La lista de permisos del `sandbox` del iframe es CERRADA:** solo
  `allow-scripts`. Nunca `allow-same-origin` (rompe el aislamiento entero:
  el juego podría leer `document.cookie`/`sesskey` de Moodle), nunca
  `allow-forms`/`allow-popups`/`allow-modals`/`allow-top-navigation` (el
  prompt de Gamificación ya prohíbe `alert`/`confirm`/`prompt`/`localStorage`
  desde el 29-09 — carta 6 §A3 —, así que un juego bien generado no los
  necesita; añadir el permiso "por si acaso" es la fuga que un juego mal
  generado sí explotaría).
- **La escucha de `postMessage` valida por `event.source === iframe.
  contentWindow`, nunca por `event.origin`.** Dentro de un `sandbox` sin
  `allow-same-origin` el origen del juego es la cadena `"null"` — comparar
  contra eso no distingue nada real de un mensaje falsificado por otra
  ventana. `event.source` sí identifica la ventana concreta del iframe.
- **El puente (`window.reportAwakegameScore`) se inyecta en `juego_html.php`,
  no se guarda en el fichero.** `guardar_juego()` (paso 1) sigue guardando el
  HTML limpio tal cual llega de Épica; si el puente cambiara de forma
  algún día, cambiaría en este endpoint sin tener que regenerar ningún
  juego ya guardado. Si el HTML ya trajera un bloque
  `PULSO_PLATFORM_BRIDGE_START…END` (no debería, Épica lo entrega limpio),
  se quita antes de meter el nuestro — nunca se apila un segundo puente.
- **Sin nota, en ningún sitio** (carta 6 §B1, decisión ya cerrada): la
  puntuación se pinta en `juego.php` con JavaScript y no hay ninguna
  petición al servidor con ese número. El marcador (y su `<script>` de
  escucha) solo se pintan si `puntua = 1`; con `0` o `null`, ninguno de los
  dos — no tiene sentido escuchar un mensaje que el juego no va a mandar.
- **`verificado` sigue siendo tri-state en la interfaz, no solo en la
  BD:** `false` es el único caso que se avisa («No hemos podido confirmar
  que este juego trate de…», con esas palabras — carta 5 §3.4); `null`
  (no se comprobó) no pinta nada, ni un aviso ni una nota — un aviso ahí
  sería ruido sobre algo que nunca se intentó comprobar.
- **`api_create_status.php` separa completamente los dos payloads**: una
  infografía sigue con `imageurl`/`downloadurl` de `pluginfile.php`, sin
  ningún campo nuevo; un juego lleva `playurl` (a `juego.php`) y **nunca**
  `imageurl`/`downloadurl` — esa URL apuntaría a la filearea `juego`, que
  `pluginfile.php` bloquea a propósito. `puntua` solo se añade al payload de
  un juego, no al de una infografía. **`tool` sí viaja en los dos** desde el
  paso 3 (antes solo en el de un juego): la galería conjunta lo necesita para
  distinguir tarjeta e icono sin adivinarlo por la ausencia de `imageurl`.
- **`juego.php`/`juego_html.php` comparten el mismo criterio de acceso que
  `block_pulso_pluginfile()`** (dueño del encargo o `viewanalytics` en el
  curso) y el mismo orden: existe → `require_login()` → acceso → `tool`/
  `status`. Los dos últimos dan el MISMO 404 (`send_file_not_found()`) para
  no distinguir "no es tuyo" de "no está listo" a quien no tiene acceso de
  todas formas. `juego_html.php` no exige sesskey: es el `src` de un
  `<iframe>`, una petición GET idempotente sin efectos.
- **`juego.php`/`juego_html.php` NO comprueban `createactivity` ni `check_enabled()`, a
  propósito** (v1.27.2). Un juego ya generado es del usuario: sigue jugable aunque el
  centro le quite la capability al rol o desactive Pulse en el curso. Esas dos puertas
  gobiernan CREAR (encargar gasta cupo y cuesta dinero), no ver lo ya creado. El control de
  acceso de verdad es dueño-o-`viewanalytics` + `status`/`tool`. No "arreglarlo" añadiendo
  las comprobaciones.
- **Ninguna operación de texto sobre el HTML corta por bytes.** La
  inyección de la meta CSP y del puente usa `mb_stripos`/`mb_strripos`/
  `mb_substr` (nunca `strpos`/`substr` a secas): el HTML puede traer
  acentos y otro UTF-8 real, y cortar a mitad de un carácter multibyte
  corrompería el documento entero (mismo motivo que la regla ya existente
  para el material que se manda a Épica).

## Gamificación — paso 3: «Crear juego» en el bloque Crear (v1.23.0)

Añade el segundo CTA a la interfaz repitiendo el patrón de infografías —el
paso 1 ya lo dejó dicho: "añadirlos es repetir este mismo patrón, no
descomentar algo ya puesto"—. Reglas que deben persistir:

- **Un único formulario parametrizado por herramienta, nunca una copia.**
  `pulsoCreateTool` (`'infografia'`/`'gamificacion'`) decide en
  `chat_simple_view.php` los textos (`PULSO_CREATE_TOOL_LABELS`), si se pide
  el selector de formato (solo infografías) y qué manda `submitCreate()`
  (antes `submitCreateInfografia()`, renombrada al dejar de ser exclusiva de
  una herramienta). El desplegable de recursos, el cupo por sección y el
  resto de cupos son el mismo código sin cambios: son un contador conjunto
  (carta 6 B5) y las dos herramientas comparten `get_resources_context()`.
- **El juego se juega FUERA del widget, siempre.** El botón «Jugar» de
  `renderCreateStatus()` abre `playurl` (`juego.php`) con
  `target="_blank" rel="noopener"` — nunca un `<iframe>` del juego dentro del
  panel de chat: el panel es estrecho y el juego ya corre en su propio marco
  aislado con CSP/`sandbox` (paso 2). Para una infografía se mantiene la
  vista actual (imagen + "Abrir a tamaño completo"/"Descargar").
- **La galería es conjunta, con `tool` en cada fila.** `api_create_status.php`
  quitó el filtro `tool = 'infografia'` de la rama sin `encargoid`: ahora
  devuelve los últimos 8 encargos `listo` con fichero de las DOS
  herramientas, más recientes primero. Consecuencia en el payload
  (`pulso_status_encargo_payload()`): **`tool` viaja para las dos** (antes
  solo para un juego) — sin ese campo el frontend no podría decidir tarjeta
  ni icono en la galería mezclada. `puntua`/`playurl` siguen siendo
  exclusivos de un juego.
- **Título de respaldo del juego, calculado en SERVIDOR, no en el cliente.**
  Mismo criterio que `juego.php`: si `titulo` viene vacío, `tema` → nombre
  del recurso (`get_fast_modinfo()->get_cm($cmid)->name`) → `"Juego"`.
  Calculado una vez en `pulso_status_encargo_payload()` y reutilizado tanto
  en el detalle del encargo como en la galería — evita reimplementar la
  regla en JavaScript y que las dos vistas puedan divergir.
- **Tarjeta de juego sin imagen, a propósito.** `pluginfile.php` sigue
  bloqueando la filearea `juego` (paso 1), así que un juego nunca lleva
  `imageurl`/`downloadurl`: su tarjeta en la galería es icono de gamepad +
  título de respaldo + fecha, no una miniatura. Etiqueta «Juego»/«Infografía»
  en cada tarjeta con `#34547A` — nunca cian como color de texto (regla del
  tema claro, sigue aplicando).
- **Estimación de espera de un juego, de la carta 6 (C3), no inventada:**
  en cola, `posición × 35 s`; trabajando, «Casi listo, suele tardar
  alrededor de un minuto». Para infografías se mantienen los textos de
  siempre (carta de láminas, ~150 s). El aviso genérico a los 2 minutos sin
  terminar («Te avisaremos cuando esté listo; puedes cerrar el chat») es
  independiente de la herramienta y solo un texto — el aviso real ya lo
  manda `notify_completion()` (paso 4) al llegar a un estado terminal.
- **Reabrir con la herramienta correcta.** «Crear un encargo nuevo», «←
  Volver al formulario» y abrir un ítem de la galería
  (`openCreateGalleryItem(id, tool)`) propagan el `tool` del encargo que se
  está viendo, nunca vuelven a `'infografia'` por defecto — si no, un alumno
  que falla creando un juego y pulsa «Crear un encargo nuevo» aterrizaría en
  el formulario de infografía sin darse cuenta.

## Cómo se trabaja este repo con prompts

El trabajo entra por prompts escritos para Claude Code, uno por paso, y **cada paso en una
sesión nueva**. Funciona porque la fuente de verdad es el código más este fichero y
`memory/session-history.md`, no la conversación: una sesión limpia lee los dos y arranca
sabiendo lo mismo que la anterior. Con ficheros de 6.000 líneas, una sesión larga acumula
contexto que ya no aplica y compite por atención con lo que importa.

Dos reglas que se derivan:

- **Todo prompt empieza pidiendo leer `CLAUDE.md` y `memory/session-history.md`**, y
  cualquier otro fichero que ese cambio concreto necesite (el contrato con Épica, la matriz
  de QA, el documento de una fase). Sin eso, una sesión nueva reinventa decisiones que ya
  están tomadas y vuelve a pisar trampas documentadas: los `substr` por bytes, la
  indexación dentro de la petición, el `courseid` en el upsert, el cian como color de texto.
- **Un paso no se parte en dos sesiones.** Mientras se itera sobre el mismo cambio —diff,
  ajuste, reaplicar— se sigue en la misma; lo que se separa son los pasos, no las vueltas
  dentro de un paso.

Y el cierre de cada paso es siempre el mismo: enseñar el diff antes de aplicar, bump de
`version.php`, y documentar en este fichero lo que sea regla permanente y en
`memory/session-history.md` lo que costó encontrar.

## Integración con Épica — paso 1: bloque «Crear» (v1.18.0)

Épica genera infografías a partir del texto de un recurso del curso (guardado desde
v1.17.0 en `block_pulso_full_text`). Este paso construye solo la parte de Pulse: pantalla
de encargo + cupos + tabla. **Todavía no se manda nada a Épica** — eso es el paso 4, y el
punto de entrada ya queda marcado (`creation_quota::dispatch_to_epica()`, no-op a
propósito).

- **Alumnado y profesorado, los dos.** Decisión de producto: la puerta de Épica acepta
  encargos de alumno. Por eso el bloque «Crear» vive FUERA de los marcadores
  `<!--PULSO_TEACHER_ONLY_*-->`/`<!--PULSO_STUDENT_ONLY_*-->`, en sus propios
  `<!--PULSO_CREATE_ONLY_START/END-->`, con la capability nueva
  `block/pulso:createactivity` (contexto de curso, `CAP_ALLOW` para
  student/teacher/editingteacher/manager — hace lo mismo que hoy haría no tener
  capability, y existe solo para que un centro se la pueda quitar al alumnado desde la
  interfaz de Moodle sin tocar código). Se comprueba en `block_pulso.php` igual que
  `viewanalytics`, y los dos endpoints nuevos (`api_create_form.php`,
  `api_create_submit.php`) la vuelven a exigir con `require_capability()`: la UI no es
  control de acceso, igual que en modo alumno.
- **v1 tiene UN botón** (infografías). Retos/presentaciones son v2+; no se ha dejado hueco
  de "tres botones" en el HTML ni en la capability — añadirlos es repetir este mismo
  patrón, no descomentar algo ya puesto.
- **Es una pantalla, no un mensaje de chat.** `#pulso-create-panel` es hermano de
  `#pulso-home` dentro de `#pulso-messages` y se alterna con una clase
  (`pulso-showing-create` en `#pulso-messages`) que oculta todo lo demás — si el
  formulario se colara como mensaje, entraría en el historial que viaja a Anthropic en
  cada petición siguiente.
- **El desplegable tiene DOS filtros obligatorios**, los dos en
  `creation_quota::get_resources_context()`/`filter_visible()`: `usable=1` de
  `full_text_store::get_available_resources()` (un recurso sin texto da un encargo vacío)
  y `get_fast_modinfo()->uservisible` (mismo criterio que
  `rag_retriever::build_activity_link()` — sin esto, un alumno vería en el desplegable el
  nombre de un recurso oculto o restringido, y el nombre ya es información). Lista vacía
  → mensaje explícito con motivo real (`full_text_store::has_any_indexed()` distingue
  "el curso aún no se indexó" de "no hay texto aprovechable"; un tercer motivo,
  `no_visible`, cubre el caso — no contemplado en el encargo original pero real — de que
  todo lo indexado esté oculto/restringido para ESE usuario).
- **Los topes cuentan ENCARGOS, no infografías** (`block_pulso_encargos`, sin filtrar por
  `tool` al contar): cuando llegue la v2 con retos, el mismo contador protege a los dos,
  porque Épica avisó de que la cola es compartida entre herramientas. Son 5, todos
  ajustes de plugin (`settings.php`, pendientes de aprobación de negocio, no
  constantes): usuario+sección+día, usuario+curso+día, curso+hora (ventana móvil, no reloj
  de pared), curso+día (`max(suelo, matriculados × multiplicador)`, los dos también
  configurables) y docente+día — esta última es la única que NO filtra por curso: cuenta
  los encargos del docente en TODOS sus cursos, porque la de usuario+curso ya lo protege
  dentro de uno solo.
- **El cupo de sección se avisa ANTES de escribir, sin una petición aparte.** El GET que
  arma el desplegable (`api_create_form.php`) ya devuelve, por cada recurso, su consumo de
  hoy de esa sección (`sectionused`/`sectionlimit`); el JS lo usa al `change` del
  desplegable para avisar o bloquear el envío en cuanto se elige el recurso, no al
  enviarlo — un tope que se descubre después de teclear se lee como una avería. El resto
  de cupos (los que no dependen del recurso) se comprueban al pulsar el botón, antes de
  mostrar el formulario. Los dos se REVALIDAN en servidor al enviar
  (`api_create_submit.php`): nunca confiar en el cupo ya mostrado, que puede haberse
  quedado desfasado, ni en el `cmid` tal cual llega del cliente (se recalcula contra
  `get_resources_context()` de nuevo).
- **`block_pulso_encargos` está diseñada para el paso 4, no solo para contar**: además de
  `courseid`/`cmid`/`sectionnum`/`userid`/`tool`/`timecreated`/`status`, guarda `prompt` y
  `format` (lo que el paso 4 tendrá que enviar) y una columna `epica_job_id` ya reservada
  (nula hasta entonces) para el identificador de trabajo que devuelva Épica.
- **El cian sigue sin ser color de texto.** El CTA «Crear infografía» usa fondo
  `--pulso-navy` sólido con texto blanco (regla del tema claro); el cian solo aparece en
  el icono, para diferenciarlo visualmente de las tarjetas-pregunta de los otros dos
  bloques sin romper la regla de contraste.

## Integración con Épica — paso 3: firmar, encargar, sondear, recoger (v1.19.0)

El ciclo completo con Épica vive en `classes/epica_client.php` (sobre + HTTP + reglas) y
`classes/task/epica_ciclo_adhoc.php` (la tarea que lo orquesta), encolado desde
`creation_quota::dispatch_to_epica()`. Ninguno de los dos lo llama nunca una petición web
— lo dice el propio nombre del paso: firmar, encargar y sondear son trabajo de cron.

- **Un paso por ejecución, nunca `sleep()`.** Una lámina tarda minutos y la concurrencia
  de Épica es 2, compartida con otras herramientas: si la tarea durmiera hasta el
  siguiente sondeo, una clase entera encargando a la vez deja el cron bloqueado casi una
  hora por encargo. Cada ejecución de `epica_ciclo_adhoc` hace UN paso
  (`epica_client::procesar_paso()`) y, si toca seguir, se reencola a sí misma con
  `set_next_run_time()` para la cadencia que corresponda. `status` (columna ya existente
  desde el paso 1) es la máquina de estados: `pendiente` → firma y llama a `encargar`,
  pasa a `encolado`; `encolado`/`trabajando` → sondea una vez; `listo`/`fallado`/
  `desconocido`/`ensayo` son terminales y **no se vuelven a tocar jamás** (`fallado` y
  `desconocido` no se reintentan: si el problema fuera transitorio, Épica los habría
  respondido como `en-cola`).
- **Cadencia guiada por `posicion`, no por reloj**, y con cortesía de 30 minutos: en-cola
  espera `max(10s, posicion×20s)` con tope 60s; trabajando cada 15s; pasados 30 minutos sin
  resolverse, la tarea deja de sondear en primer plano y pasa a un sondeo de fondo cada 5
  minutos (el trabajo sigue vivo y recogible 7 días — dejar de sondear rápido no lo pierde).
  Si la posición no baja en 3 sondeos seguidos, se deja constancia en el log; no cambia el
  comportamiento, es solo para poder ver que hay cola real si alguien pregunta.
- **Token nuevo en CADA llamada, incluidos todos los sondeos** (`local_awkepica`, nunca
  firma manual). El `jti` se gasta al recibirlo — reenviar el mismo cuerpo da 401
  `reusado` —, así que cada paso de la tarea firma su propio token justo antes de usarlo,
  nunca reutiliza uno de un paso anterior. Y `epica::ESPERA_LARGA_S` (180s) en TODAS las
  llamadas del ciclo, no solo en el sondeo que se espera que sea el último: el `listo` cae
  en el que cae y trae el PNG de ~1,7 MB dentro; con una espera corta ahí se ve un error de
  cURL donde había una lámina terminada.
- **El rol se firma con `block/pulso:viewanalytics` en contexto de CURSO**
  (`epica_client::CAPABILITY_ROL` vía `epica::rol_de()`/`firmar_por()`), nunca con
  `is_siteadmin()` ni un rol de sitio — un profesor de un curso no debe firmar como
  docente en otro. **NO con `createactivity` (corregido en v1.29.0, no revertir):**
  `rol_de()` devuelve `docente` si el usuario TIENE la capability, y `createactivity`
  tiene `CAP_ALLOW` para `student` (es el permiso de CREAR, no de ser docente), así que
  todo el alumnado salía firmado como `docente`: Épica nunca aplicó los ritmos de alumno
  y la puerta `/api/auth/alumno` (carta 10) habría dado 403 `rol-sin-permiso`.
  `createactivity` sigue siendo solo el permiso para encargar (endpoints y UI).
- **`caracteres` y `extraido_por` del material salen TAL CUAL de
  `block_pulso_full_text`** (el tamaño ORIGINAL, no el recortado): es la referencia que
  hace honesto el flag `truncado`. Solo `texto` se recorta, con `mb_substr` (nunca
  `substr`) y por frontera de párrafo o, en su defecto, de palabra — jamás a mitad de una:
  Épica compara el material contra el original para verificar fidelidad, y una frase
  cortada envenena esa comparación. Objetivo de recorte 100.000 caracteres aunque el
  contrato admita hasta 300.000 (no mejora el PNG y encarece la generación).
- **`block_pulso_full_text.texto` (y por tanto `material.texto`) es SOLO el texto
  literal del documento — nunca el encuadre que se añade para el RAG** (nombre del
  módulo, "Archivo: X (mimetype)", "Contenido PDF extraído:"...). `content_extractor
  ::chunk_text()` recibe DOS textos: `$text` (con encuadre, para trocear y embeber —
  no tocar) y un `$literal_text` opcional que es lo que se guarda de verdad en
  `block_pulso_full_text`; si se omite, se guarda `$text` tal cual (ver `extract_label`,
  que ya era literal de origen). Si se añade un tipo de módulo nuevo o se toca uno
  existente, hay que construir su `$literal_text` sin ningún prefijo/etiqueta que no
  esté en el documento original — Épica compara el material contra lo generado, y ese
  encuadre synthetic (detectado en la revisión del sobre real, 2026-09-23) contaminaba
  la comparación. Extraído de un fichero real (docx/pptx/pdf/texto plano/notebook):
  literal = el contenido devuelto por el extractor, sin envolver. Cambiar esto cambia el
  `content_hash` de cada fila — hace falta reindexar.
- **`extraido_por` de PDF usa `content_extractor::PDFPARSER_VENDORED_VERSION`**
  (`'smalot/pdfparser (vendorizada 2026-07-14)'`), no un string suelto: `lib/pdfparser/`
  se vendorizó sin `composer.json` ni fichero de versión, así que la librería no expone
  su versión en tiempo de ejecución. Se usa la fecha del commit que la vendorizó
  (`b7b08c4`, verificable con `git log`) en vez de inventar un número — ver
  `lib/pdfparser/VERSION`. Si algún día se sabe la versión real de origen, actualizar
  la constante Y el fichero VERSION a la vez.
- **El `contexto` de sección (nombre, resumen, vecinas) se manda SIEMPRE**, haya material
  aprovechable o no: es lo que sostiene el tema si el material falla o si el recurso ya no
  es válido para generar nada. Incluye la sección 0 (antes se saltaba explícitamente, y
  un recurso que vive ahí salía con "Sección 0" y sin vecinas) y el nombre de cualquier
  sección (actual o vecina) usa SIEMPRE `get_section_name()`, nunca un marcador fijo en
  castellano — con nombre propio lo respeta, sin él da el nombre por defecto del formato
  del curso. `resumen`/`grupo` (en `alumno`) se OMITEN del JSON cuando están vacíos, en
  vez de mandarse como `""`.
- **La lámina va anidada bajo `data.lamina`, no en la raíz** (confirmado por
  Épica contra su propio código: `backend/src/laminas.ts:469`,
  `encargos.ts:191` y `:389`). La raíz de un "listo" solo lleva
  `plataforma`/`estado`; `imagen`, `titulo`, `subtitulo`, `tema`,
  `verificado` (true/false/null), `prompt`, `inventario`, `arquetipo`,
  `formato`, `idioma`, `notas`, `avisos`, `mock` van dentro de `lamina`.
  `recoger()` no guarda `prompt`/`inventario` (no hacen falta y pueden ser
  largos), y `verificado` es tri-state: `null` significa "no se comprobó el
  tema contra el material", no "false" — se guarda como `null`, nunca como 0.
- **Cuando "listo" no trae imagen válida, `recoger()` registra la FORMA de la
  respuesta (claves de primer/segundo nivel, tipo del campo imagen), nunca su
  contenido** — jamás el base64 ni el `crudo` completo. Épica confirma que un
  "listo" sin imagen no debería darse nunca (si la imagen desaparece,
  contestan "desconocido" en su lugar), así que si este diagnóstico vuelve a
  saltar es señal de que algo ha cambiado, no un caso ya resuelto.
- **El PNG jamás entra en el historial del chat ni en un log.** Se decodifica de base64 y
  se guarda con la File API de Moodle (`component=block_pulso`, `filearea=encargo`,
  `itemid=` id de la fila del encargo, en el contexto de CURSO — un encargo no está atado
  a una instancia de bloque concreta) y se sirve por `pluginfile.php` vía
  `block_pulso_pluginfile()` en `lib.php`, con acceso solo para quien hizo el encargo o
  quien tiene `viewanalytics` en ese curso. `mock` se mira siempre: si llega `true` el PNG
  es de relleno pero válido, se guarda y se enseña igual (queda marcado para decirlo en la
  interfaz cuando el paso 4 la construya).
- **Modo de ensayo (`block_pulso/epica_dry_run`, apagado por defecto).** Con el ajuste
  activo, el paso `pendiente` construye el sobre completo y lo registra en
  `sobre_json`, pero nunca firma ni llama a Épica — el encargo pasa directo a un estado
  terminal propio (`ensayo`). Sirve para verificar el payload exacto contra el contrato
  antes de tener el secreto de producción configurado en `local_awkepica`, sin gastar
  cuota. El sobre en ensayo no lleva `token` (firmar de verdad requiere el secreto, que en
  ensayo puede no estar configurado).
- **`epica::pedir()` SÍ está documentado — `docs/local_awkepica_api.md`**, sacado
  del código de `local_awkepica` por el equipo de Épica (v1.20.3, tras un `TypeError`
  real en el primer encargo: ver `memory/session-history.md`). Devuelve SIEMPRE un
  array con 5 claves fijas — `errno`, `error`, `http`, `datos` (array\|null, el cuerpo
  ya decodificado), `crudo` — así que `epica_client::normalize_response()` ya no
  normaliza nada de forma defensiva: lee `http`/`datos` tal cual. Y `firmar_por()`
  quiere el **`id` del curso como cadena** (`(string) $course->id`), nunca el objeto
  `$course`/`$COURSE` — pasar el objeto es el `TypeError` de más arriba.
- **Un fallo de transporte tiene tope, y el criterio de "transitorio" es el de
  Épica, no uno inventado** (v1.20.2, ajustado en v1.20.3 al contrato real). El
  criterio, igual que usan los demás plugins de `local_awkepica`:
  `!empty(errno) || http === 0 || http >= 500`. Se comprueba con
  `epica_client::es_transitorio()` justo después de CADA `pedir()` (no solo dentro de
  un `catch`), y también dentro de los dos `catch(\Throwable)` de
  `paso_pendiente()`/`paso_sondeo()` (`firmar_por()` puede lanzar `RuntimeException`
  por UTF-8 inválido; `pedir()` en sí no lanza). Ambos caminos van a
  `reintentar_o_fallar_transitorio()`, que cuenta fallos transitorios **SEGUIDOS** en
  la columna `error_count` (un 4xx normal de Épica — `material-ilegible`,
  `rol-sin-permiso`… — NO pasa por aquí: es terminal a la primera vía
  `procesar_error_encargar()`/`procesar_error_sondeo()`) y da el encargo por `fallado`
  al llegar a `CONSECUTIVE_ERROR_THRESHOLD` (10, ~10 minutos a 60s por intento).
  Cualquier 202/200/429 correcto lo pone a 0 (un 429 ya demuestra que la red
  funciona, solo es cupo). `retry_after()` lee `datos['esperaS']` (Épica no pasa
  cabeceras por `pedir()`, `Retry-After` no llega). Un **202 sin `datos['trabajo']`**
  es fallo terminal explícito, no un `epica_job_id` vacío colándose como si el
  encargo se hubiera aceptado. `motivo` se reutiliza para guardar el último error
  transitorio (sin columna nueva para esto) **mientras el encargo no es
  terminal**, y `api_create_status.php` lo expone solo a quien tiene
  `viewanalytics` en ese caso — un alumno dueño del encargo no necesita ver
  la clase y el mensaje de una excepción PHP. Una vez terminal, `motivo` es
  la razón real del fallo y se enseña como siempre (dueño o `viewanalytics`).
- **Antes de firmar, dos comprobaciones que `firmar_por()` no hace por sí solo**
  (`epica_client::verificar_precondiciones()`, «quien llame, comprueba» según el
  propio contrato): `!epica::configurado()` → fallado con motivo claro (sin eso,
  firma igual con `iss` vacío o sin secreto, y Épica lo rechaza sin decir por qué);
  usuario sin `email` → fallado con motivo claro (el token sale sin claim `email`,
  y sin ella Épica tampoco tiene con quién contactar).

## Gamificación — paso 4: tres juegos de partida (carta 9) (v1.28.0)

Debajo del cuadro de la petición de «Crear juego», tres botones («Prueba con:») que
rellenan el cuadro con un juego ya escrito. Solo cliente (`chat_simple_view.php`:
`PULSO_JUEGO_EJEMPLOS`, `pulsoJuegoRefresh()`, `pulsoJuegoPickEjemplo()`). Sin servidor,
sin `db/`, sin sobre, sin cuotas. Reglas que deben persistir:

- **Los textos de `peticion` son LITERALES de la carta 9 §2** y no se retocan: cada detalle
  tiene motivo (§4). «sobre el contenido de «{tema}»» y no «sobre {tema}» porque el motor deja
  que la petición cambie el tema y un nombre como «Unidad 3» no es un tema; «hasta» diez/ocho
  porque el motor no puede inventar datos con un material corto; «tocando, sin arrastrar»
  porque arrastrar falla en móvil; opciones incorrectas del mismo material para que no se
  cuelen datos inventados. Si el QA con el modelo real demuestra que un tipo sale mal con un
  tema, se cambia el texto de la carta con Épica, no el contrato ni el código de aquí.
- **`{tema}` = `name` del recurso** de `pulsoCreateResources` (por `cmid`, nunca el texto de la
  `<option>`), que ya no lleva el prefijo de tipo («Archivo: ») ni el sufijo «— texto escaso»
  (los pinta `renderCreateForm()`). Se le quita la extensión final con `/\.[A-Za-z]{2,5}$/`.
  Se sustituye con `split/join`, no `String.replace` (un tema con `$&` se interpretaría).
- **Un clic rellena y NO envía.** Pone el texto en `.value` (nunca `innerHTML`), foco con el
  cursor al final y nada más: ni `submitCreate()` ni petición. Se manda lo que haya en el
  cuadro, tal cual. **No hay campo `tipo`** en el POST ni en el sobre: Épica no lo lee y un
  campo que el servidor no lee hace creer que hace algo.
- **Solo con recurso válido y cuadro vacío** (`trim()`): al escribir desaparecen, al vaciar
  vuelven (`input`). El cupo de sección sigue mandando: `updateCreateSectionHint()` desactiva
  el envío sin esconder los chips (rellenar no gasta nada).
- **Cambio de recurso con el ejemplo SIN editar** → se re-escribe con el nuevo tema (si no, se
  enviaría un juego del tema anterior sobre el material nuevo). `pulsoJuegoFill` guarda el
  último texto puesto por un chip; solo si el cuadro es EXACTAMENTE eso se sustituye. Editado,
  no se toca.
- **El mismo tipo dos veces sobre el mismo recurso** ya sale con `alumno.intento` 2, 3…:
  `resolve_intento()` cuenta por `userid + cmid + tool` (carta 6 B3). No requirió cambios.
- Los textos son castellano fijo, como el resto del bloque Crear; el idioma del juego lo decide
  `curso.idioma` del sobre. Nada de esto aparece en infografía, ampliación ni retos.

## Ampliación de recursos — paso 1 (v1.24.0)

Herramienta NUESTRA (no pasa por Épica ni `epica_client`): dado un recurso ya indexado
(`block_pulso_full_text`, `usable=1`), Haiku saca el tema y se buscan 2 vídeos de YouTube y
2 artículos de OpenAlex. Lógica en `classes/ampliacion_service.php`, endpoint
`api_ampliacion.php` (POST). Sin UI todavía (paso 2). Reglas que deben persistir:

- **La caché por `content_hash` es la pieza central, no una optimización.** `search.list` de
  YouTube cuesta 100 unidades y el proyecto tiene ~100 búsquedas al día EN TOTAL. Una fila de
  `block_pulso_ampliaciones` por `(courseid, cmid, content_hash)` (índice único), compartida por
  todos los usuarios del curso. `listo` < `ampliacion_ttl_dias` (30) → se sirve sin llamar a
  nada; texto reindexado → otro hash → otra ampliación. Un `fallado` se cachea como máximo 1 h.
  La regeneración actualiza la fila EN SITIO y renueva `timecreated` (es lo que cuentan los
  topes); las filas de hashes viejos solo se borran si son de días anteriores (las de hoy siguen
  contando en los topes).
- **Topes propios, independientes de infografías/juegos** (no escriben en
  `block_pulso_encargos`): `ampliacion_max_dia_sitio` (80, deja margen a las 100 búsquedas) y
  `ampliacion_max_dia_usuario` (5), solo ampliaciones NUEVAS (la caché no cuenta). Cuentan filas
  generadas hoy, `fallado` incluidas (un fallo también pudo gastar una búsqueda). Un candado
  (`lock_config`, por recurso) evita que dos peticiones simultáneas gasten dos búsquedas.
- **Orden del endpoint**: autenticar → sesskey → `createactivity` → `check_enabled` → el `cmid`
  se RECALCULA contra `creation_quota::get_resources_context()` (visible + usable; nunca el del
  cliente) → `session\manager::write_close()` → servicio. Caché → tope → generar, en ese orden.
- **Qué viaja a las APIs**: solo las consultas generadas (`query_videos`, `query_articulos`).
  Nunca el texto del recurso ni datos del usuario. Haiku (`FAST_MODEL`, vía
  `anthropic_connector::send_fast_query()`, que usa `encode_payload()`) recibe nombre del
  recurso, sección y 6.000 caracteres (`mb_substr`); su system prompt es propio, no toca el
  prompt base cacheado, y declara el texto del recurso como material, no instrucciones.
- **Claves por cabecera, nunca en la URL** (`X-Goog-Api-Key`, `Authorization: Bearer` de
  OpenAlex — la doc de OpenAlex las da por equivalentes a `?api_key=`). `scrub()` las borra de
  cualquier mensaje antes de guardarlo o devolverlo; el cliente nunca ve claves ni respuestas
  crudas. Sin una clave, la herramienta sigue con la otra fuente y un aviso; sin ninguna, el
  endpoint responde "no configurada". Cada fuente falla por separado (`listo` + aviso); solo si
  fallan TODAS las configuradas es `fallado`.
- **Semántica de `videos_json`/`articulos_json`**: `NULL` = fuente no consultada o fallida; `[]` =
  consultada sin resultados. Los avisos de un `listo` viajan en `motivo` (una línea por aviso) para
  que la caché los devuelva tal cual; en un `fallado`, `motivo` es la causa.
- **Juez antes de elegir (v1.24.2)** — la relevancia/popularidad sola no garantiza el tema (v1.24.1
  aún dejó «A Survey of Corporate Governance» en un recurso de muestreo). Cada fuente genera hasta
  `CANDIDATES` (10) candidatos que pasan los filtros; una 2ª llamada a Haiku (`judge()`, prompt
  propio, títulos como DATOS) devuelve los índices que tratan claramente del tema Y encajan con el
  público; solo los aprobados siguen al criterio de abajo (`pick_videos()`/`pick_articles()`).
  Devuelve los que haya (<2 es válido); 0 aprobados → aviso «No hemos encontrado vídeos/artículos
  claramente relacionados con este recurso.» y `listo`, no fallo. Si el juez falla se usa la
  selección sin juez (`error_log`, sin aviso al usuario). **OpenAlex**: búsqueda SEMÁNTICA,
  `search.semantic=<frase>&filter=type:article|review,has_abstract:true&per_page=10` (embeddings
  sobre título+abstract; máx. 2.000 caracteres, 1 req/s, un solo parámetro de búsqueda por
  petición). NO `search=` (busca también en fulltext y su `relevance_score` pondera las citas: de
  ahí «Corporate Governance» en un recurso de muestreo) ni `filter=title_and_abstract.search`
  (deprecado). `query_articulos` = UNA o DOS frases en inglés que describan el tema académico
  (no lista de palabras), `mb_substr` a 500, solo URL-encode; **vacía si el recurso no tiene base
  académica** (manual de uso, avisos, normativa): entonces no se consulta OpenAlex. Se ordena por
  `relevance_score` (similitud, no sesgada por citas).
  Cambiar prompts o criterio exige un upgrade que borre la caché (2026100101).
- **Criterio de selección: relevancia primero, popularidad después** (v1.24.1; la primera
  prueba real eligió «The CES-D Scale», 53.814 citas y fuera de tema, y dos vídeos para
  profesores en un manual de alumnado, por ser los más vistos de 10). Solo los
  `RELEVANCE_POOL` (5) primeros por relevancia que pasen los filtros compiten por
  popularidad; nunca se elige entre los 10. **YouTube**: `search.list` (10, ya en orden de
  relevancia, `safeSearch=strict`, `videoEmbeddable`, `regionCode=ES`, `relevanceLanguage` =
  idioma del curso) + `videos.list` (1 unidad, que NO conserva el orden: se recorre en el de
  `search.list`); los 5 primeros ≥ 120 s (sin Shorts), y de ellos los 2 con más `viewCount`,
  prefiriendo canales distintos (por `channelId`) mientras el pool lo permita. **OpenAlex**:
  `type:article|review,has_abstract:true`, por `relevance_score`, los 5 primeros aprobados
  y de ellos los 2 de más citas; "a igualdad aproximada" = un artículo en acceso abierto pesa
  ×1,2. **Público**: Haiku deduce a quién va el recurso (alumnado/profesorado/general) y lo
  refleja en `query_videos` ("para estudiantes"…) salvo que sea general; `query_articulos`
  sigue siendo académica, sin público. URLs validadas (solo `https://www.youtube.com/watch?v=`,
  `https://i.ytimg.com/`, `https://doi.org/`, `https://openalex.org/` o la de acceso abierto
  `https://`); lo externo (títulos, canales, autores) se guarda tal cual y **se escapa al pintarlo**
  (paso 2). Cambiar este criterio o el prompt deja obsoletas las filas ya cacheadas: hace falta
  un paso de upgrade que las borre (como el de 2026093003).
- **Artículos en el idioma del CURSO primero (v1.25.1).** Haiku devuelve además
  `query_articulos_local` (la misma descripción en el idioma del curso; vacía exactamente cuando
  `query_articulos` lo está — el servidor lo fuerza). Curso no inglés: 1ª búsqueda
  `search.semantic=<local>&filter=language:<iso>,type:article|review,has_abstract:true`, con juez
  y criterio de siempre; si quedan < 2 aprobados, 2ª búsqueda en inglés SIN filtro de idioma
  (descartando URLs ya elegidas) para completar, siempre con los del idioma del curso delante.
  Curso en inglés: una sola búsqueda. Si la local falla, se sigue con la inglesa. Verificado en
  la doc de OpenAlex (help.openalex.org/api/semantic-search): `language` se combina con
  `search.semantic`; solo `last_known_institutions.country_code` y `cited_by_count` no. Entre las
  dos búsquedas se espera hasta 1,1 s (límite de 1 req/s). Cada artículo guarda `idioma` (campo
  `language` de OpenAlex, validado `^[a-z]{2,3}$`, `''` si falta). **El idioma es el del CURSO**
  (`course_language()`, el mismo de `relevanceLanguage` de YouTube), nunca el del usuario: la
  caché es compartida por recurso. `from_row()` devuelve `idioma_curso` (calculado al leer, no
  guardado) y el cliente pinta la etiqueta «En inglés» (`Intl.DisplayNames`, es) solo si
  `idioma` está y difiere de él, en `#34547A`. Upgrade 2026100103 vacía la caché.
- **Hueco RGPD documentado**: la tabla guarda `userid` (quién la generó) pero el plugin NO tiene
  `classes/privacy/provider.php`, así que no hay metadata/export/borrado ni para esta tabla ni
  para las demás. Pendiente como tarea propia; tampoco hay limpieza de filas al borrar un curso.

## Ampliación de recursos — paso 2: «Ampliar recurso» en el bloque Crear (v1.25.0)

Tercer CTA del bloque Crear que llama a `api_ampliacion.php` y pinta vídeos + artículos.
Casi todo cliente (`chat_simple_view.php`); en servidor solo `api_create_form.php` (ver abajo). Sin `db/`. Reglas:

- **Mismo panel y mismo desplegable, no uno nuevo.** `pulsoCreateTool = 'ampliacion'`
  reutiliza `#pulso-create-panel`, `openCreatePanel()` y `api_create_form.php`. El
  formulario es solo recurso + «Ampliar» (sin texto libre ni formato). Misma capability
  (`createactivity`) y mismos marcadores `PULSO_CREATE_ONLY_*`. La fila de CTA pasó de
  `flex` a rejilla de 2 columnas (con el cuarto CTA de Retos, v1.27, es 2×2; 1 columna a ≤420px).
- **Los cupos de Épica no aplican, tampoco en servidor.** No se pinta el aviso de cupo por
  sección ni se bloquea por él. `api_create_form.php` acepta `tool=ampliacion` (el cliente lo
  manda al abrir el panel): salta `check_general_quota()` (`quota_ok` siempre true) y pide
  `get_resources_context(..., $withusage=false)`, con los mismos filtros visible+usable pero sin
  `sectionused`/`sectionlimit`. Sin ese parámetro el flujo de infografías/juegos es el de siempre.
  Ampliación tiene sus propios topes en `api_ampliacion.php`.
- **No se incrusta YouTube**: ni iframe ni reproductor. Cada tarjeta es un `<a>` a
  `url` con `target="_blank" rel="noopener noreferrer"`.
- **Todo lo externo se escapa** (títulos, canales, autores, revistas, tema, avisos) con
  `escapeHtmlText`; para valores de atributo se usa `pulsoEscapeAttr()`, porque
  `escapeHtmlText` (vía `innerHTML`) NO escapa comillas y un título con `"` rompería
  `alt`/`href`; **cualquier valor de atributo del bloque Crear usa `pulsoEscapeAttr`** (7 usos
  de `escapeHtmlText` en atributos del estado/galería/formulario se migraron en v1.25.0). URLs solo si empiezan por `https://`; miniaturas solo si empiezan por
  `https://i.ytimg.com/` (si no, hueco con icono), con `loading="lazy"` y
  `referrerpolicy="no-referrer"`. Segunda red tras la validación del servidor.
- **Una sección vacía no pinta cabecera**; en su lugar sale el aviso que venga en
  `avisos`. «Acceso abierto» en success `#0F7A57`; sin cian como texto.
- **Sin galería y sin historial.** Una ampliación no es una creación del usuario (es
  compartida por recurso y cacheada por `content_hash`), así que no entra en «Tus últimas
  creaciones», y el panel no es un mensaje de chat.
- **Peticiones en vuelo** se invalidan con `pulsoAmpToken` (se incrementa al abrir/cerrar
  el panel): si el usuario pulsa «Volver» mientras busca, la respuesta tardía no pisa la
  pantalla que esté viendo.

## Retos — paso 1: servidor, desde la petición web (v1.26.0)

Tercera herramienta de Épica: el usuario elige un recurso o escribe un tema, Épica propone SEIS
retos, elige uno (o escribe el suyo) y Épica devuelve un ENLACE donde se resuelve y se corrige.
Lógica en `classes/retos_service.php`, errores en `classes/reto_error.php`, endpoint
`api_retos.php` (POST, una `accion`: `proponer`, `propuesta`, `elegir`, `refrescar`, `curso`,
`mis_retos`). Sin UI todavía (paso 2). Contrato: `docs/epica_retos_carta7.md`; **la carta 8 manda
sobre la 7**. Reglas que deben persistir:

- **Retos se llama desde la PETICIÓN WEB, no desde una tarea adhoc — y SOLO Retos.** Es la
  excepción aprobada por Épica (carta 8 §1): sus puertas contestan enseguida (`proponer` no
  llama al modelo: valida el token, guarda el material y encola; lo lento ocurre en su cola) y
  el usuario está delante esperando para elegir. La regla de la carta 5 (adhoc, un paso por
  ejecución) **sigue valiendo para láminas y juegos y no se toca**. El navegador sondea
  NUESTRO endpoint; nunca habla con Épica. Orden del endpoint: `require_login` → sesskey →
  `createactivity` → `check_enabled` → `write_close` → Épica. `epica_dry_run` NO aplica a Retos
  (llama siempre de verdad).
- **Se reutiliza `epica_client`, no se copia**: los métodos que Retos necesita pasaron de
  `private` a `public` (`resolve_material`, `resolve_contexto_seccion`, `resolve_grupo`,
  `es_transitorio`, `describir_transitorio`, `normalize_response`, `retry_after`, `endpoint`) y
  la comprobación de precondiciones se extrajo a `precondiciones_error()` (sin efectos; la
  versión adhoc la envuelve con `marcar_fallo`). Cualquier cambio en cómo se construye el material
  (literal, recorte por párrafo a ~100.000, `truncado`, `extraido_por`) afecta a las tres
  herramientas a la vez — es lo que se quiere.
- **Token nuevo en CADA llamada, también en cada sondeo**, firmado con `(string)$course->id` y la
  capability `epica_client::CAPABILITY_ROL` (`viewanalytics`, NO `createactivity`: ver la regla
  del rol en «Integración con Épica — paso 3») en contexto de curso. Un fallo transitorio (`errno ≠ 0 || http === 0
  || http >= 500`, o una excepción al firmar/pedir) se devuelve como error recuperable
  (`epica-no-disponible`, 503, `reintentable: true`) **sin cambiar el estado guardado**: reintentar
  es firmar otro token, no hay contador de errores seguidos como en la tarea adhoc (aquí reintenta
  el cliente). Espera larga (`ESPERA_LARGA_S`) solo en `proponer` CON material; el resto, la corta.
- **El sondeo NO se corta mientras la propuesta esté `en-cola`** (carta 8 §1): cortar a los 2 min
  y que el alumno vuelva a pulsar encola una segunda propuesta y gasta otra unidad del cupo (el
  cupo se gasta al admitir). El límite de 2 minutos del cliente cuenta desde el PRIMER
  `trabajando` — `timetrabajando` se guarda una sola vez y `accion=propuesta` devuelve
  `trabajando_desde` (segundos). `listo`/`fallado`/`desconocido` son terminales también en
  nuestra tabla: una vez ahí no se vuelve a llamar a Épica.
- **Ningún id del cliente se usa sin comprobar que la fila es de ESE usuario y ESE curso**:
  `cargar_propuesta()` filtra por `id`+`userid`+`courseid` y una ajena da el mismo
  `propuesta-desconocida` que una inexistente; `refrescar` busca el `codigo` con `userid`+
  `courseid`. El `cmid` se RECALCULA contra `get_resources_context(..., false)` (visible + usable).
  `elegir` solo acepta un `reto` que esté entre los seis GUARDADOS (`retos_json`): uno inventado
  es `reto-desconocido`, **nunca "el primero"**. «Proponer otros» exige `propuesta` SOLA (con
  recurso o tema es `encargo-ambiguo`) y hereda `cmid`/`tema` del padre; solo se admite sobre una
  propuesta `listo` y de menos de 7 días (la caducidad de Épica, comprobada antes de gastar cupo).
- **Dos capas de tope, las dos ANTES de llamar a Épica.** Propios (ajustes, independientes de
  Épica/infografías/juegos/ampliación; se cuentan filas de HOY en nuestras tablas):
  `retos_max_propuestas_usuario_dia` (6; cuentan también los «otros»), `retos_max_elegidos_usuario_dia`
  (3) y `retos_max_elegidos_curso_dia` (40). El de propuestas es por usuario en TODOS los cursos.
  Y el ritmo de Épica (carta 8 §3): **proponer, «otros» y elegir comparten 3 cada 10 minutos para
  estudiantes** con una sola llave — no lo replicamos, lo recibimos como 429 `cuota-agotada` y se
  devuelve con `esperaS` (del cuerpo, `pedir()` no pasa cabeceras). `cuota-del-centro` (100/día del
  centro, proponer y elegir por separado) lleva OTRO mensaje a propósito: no es culpa de quien
  pidió. Avisar a Épica antes de una actividad de clase entera. Un **candado por usuario**
  (`lock_config`) rechaza la segunda petición simultánea (`peticion-en-curso`, 409): proponer dos
  veces son dos propuestas y elegir dos veces son dos retos, y los dos gastan cupo (doble clic).
- **Los errores de Épica se traducen por `motivo`, nunca por el texto** (`traducir_error()`):
  `encargo-sin-tema`, `encargo-ambiguo`, `propuesta-desconocida` (también caducada; en `elegir`
  marca además nuestra fila como `desconocido`), `reto-desconocido`, `material-excesivo`,
  `material-ilegible`, `origen-contradictorio` (400), `herramienta-no-contratada` (409),
  `cuota-agotada`/`cuota-del-centro` (429). Un motivo desconocido sale como 502 con el motivo
  saneado. La `traza` de Épica, cuando viene, va a `error_log` junto a nuestro id — nunca material
  ni token.
- **`titulo` vs `titulo_final`.** El `titulo` que devuelve `elegir` es el del reto PROPUESTO (el reto
  aún no se ha escrito); al escribirlo, el modelo le pone otro, y ese es el que enseñan la página
  del reto y `retos/curso`. `accion=refrescar` lo guarda en `titulo_final` cuando el reto está
  `listo` (el enlace vale desde el primer momento; no hace falta esperar). `mis_retos` y `refrescar`
  devuelven `titulo_final` si existe y, si no, `titulo`.
- **La nota es del reto, no de quien lo pidió, y solo se da al profesorado** (carta 8 §2):
  `intentos`/`analizados`/`ultimaPuntuacion` son de CUALQUIERA que abrió el enlace (el intento lo
  crea el navegador y no viaja a Pulse), así que `refrescar` los incluye solo si
  `chat_pipeline::user_can_view_analytics()`; al alumno, solo estado, título y enlace. `intentos`
  cuenta intentos que pidieron corrección, no personas. En Pulse no existen notas del alumno.
- **Lo que viene de Épica se guarda con solo los campos conocidos y tipos coaccionados**
  (`normalizar_retos()`/`normalizar_documento()`, `texto_corto()` con `mb_*`) y se escapa al
  pintarlo (paso 2, con `pulsoEscapeAttr` en atributos). **Los enlaces se validan**
  (`enlace_epica()`): solo `https://` y exactamente el host de `epica_base_url` (sin userinfo, sin
  sufijos tipo `host.evil.com`), máx. 512 caracteres; uno inválido en `elegir` es
  `respuesta-inesperada` y no se guarda nada; en `curso` se descarta esa fila. La lista de
  `curso` incluye retos de TODO el curso (de cualquier alumno, `origen` = clase, nunca quién):
  es decisión de Épica, no nuestra.
- **Tablas** `block_pulso_reto_propuestas` (una fila por `proponer`, incluidos los «otros»; se
  inserta DESPUÉS del 202, así que un fallo no cuenta para los topes) y `block_pulso_retos` (una por
  `elegir`; `codigo` único). `install.xml` y `upgrade.php` (2026100104) describen lo mismo — si se
  tocan, los dos a la vez. Sin `privacy\provider` (el hueco RGPD del plugin sigue abierto).

## Retos — paso 2: «Crear reto» en el bloque Crear (v1.27.0)

Cuarto CTA del bloque Crear, solo cliente (`chat_simple_view.php`, funciones `pulsoRetos*`) más una
línea en `api_create_form.php`. Habla únicamente con `api_retos.php`. Sin `db/`. Reglas que deben
persistir:

- **Mismo panel y mismo desplegable** (`pulsoCreateTool = 'retos'`, `renderRetosForm()`); la rejilla
  de CTA es 2×2 (1 columna a ≤420 px; se quitó la regla del «último impar a todo el ancho»).
  `api_create_form.php` trata `tool=retos` igual que `ampliacion` (sin cupos de Épica ni
  `sectionused`). **Sin recursos el formulario sigue siendo usable** (opción «Sin recurso, solo un
  tema»): `openCreatePanel()` no pinta el aviso de «no hay recursos» para `retos`.
- **El sondeo no se corta en cola.** `accion=propuesta` cada 4 s; `en-cola` se sondea SIN límite
  (carta 8 §1: cortar y volver a pulsar encola otra propuesta y gasta otra unidad del cupo del centro).
  El tope de 2 min cuenta desde `trabajando_desde` y, al superarlo, solo ofrece **«Seguir esperando»**,
  que reanuda la MISMA propuesta (`pulsoRetosGrace` guarda el `trabajando_desde` ya tolerado, para que
  no vuelva a cortar al instante). Nunca hay un botón que proponga de nuevo sin querer;
  «Volver a intentarlo» solo existe tras `fallado` (propuesta nueva a propósito).
- **Formas distintas por acción** (fácil de confundir): `proponer` devuelve el id PLANO en
  `propuesta` (número); `accion=propuesta` devuelve el estado ANIDADO en `propuesta`; `elegir`
  devuelve plano `{codigo, enlace, enlace_curso, titulo}`.
- **Antidoble clic:** `pulsoRetosSetBusy()` desactiva TODAS las acciones de la pantalla
  (`data-retos-action`) mientras hay una en vuelo; las acciones que gastan cupo (`proponer`,
  «otros», `elegir`) no rehacen la pantalla hasta tener respuesta, así que un 409
  `peticion-en-curso` se ignora en silencio (se restaura el botón y no se pinta nada).
  `stopCreatePolling()` llama a `pulsoRetosReset()`: para temporizadores, libera el busy e
  **incrementa `pulsoAmpToken`**, que invalida cualquier respuesta tardía (mismo token que Ampliación).
- **Errores: se decide por `error`, nunca por el texto.** Solo se reescribe el texto de
  `cuota-agotada` (con `esperaS`), `cuota-del-centro`, `material-ilegible`, `propuesta-desconocida`
  (pantalla final) y `reto-desconocido`; el resto usa el `mensaje` del servidor. «Reintentar» solo
  sale si `reintentable === true` y repite la MISMA acción (`pulsoRetosRetryFn`).
- **El reto se elige por ÍNDICE** (`pulsoRetosList[i]`): el `id` que vino de Épica no va a ningún
  atributo ni `onclick`; solo viaja en el POST. Todo texto de Épica con `escapeHtmlText`, atributos
  con `pulsoEscapeAttr`, enlaces solo `https://`. **Ningún iframe**: el reto se abre en pestaña
  nueva (`target="_blank" rel="noopener noreferrer"`; Épica sirve `X-Frame-Options: DENY`).
- **Markdown en textos de Épica (v1.27.1):** descripciones y resumen del documento pueden traer
  `**negrita**` (visto: «**Tabla 1**»). `pulsoRetosMd()` escapa SIEMPRE antes (`escapeHtmlText`) y
  después convierte solo `**texto**` en `<strong>`; nada más de markdown. Todo texto largo de Épica
  que se pinte en este panel pasa por ahí (hoy: descripción de tarjeta y `documento.resumen`).
- **Título final (v1.27.1: con reintentos):** tras elegir se muestra el título del reto PROPUESTO;
  a los ~45 s (`PULSO_RETOS_REFRESH_MS`) una llamada a `accion=refrescar` lo cambia por
  `titulo_final`, y mientras devuelva en-cola/trabajando se repite cada 20 s
  (`PULSO_RETOS_REFRESH_RETRY_MS`) hasta `listo`/`fallado`/`desconocido` o 3 min en total desde que
  se pinta el reto (`PULSO_RETOS_REFRESH_MAX_MS`). Una sola llamada dejaba el título propuesto y sin
  línea de intentos si el reto tardaba más. Se corta si cambia `pulsoAmpToken`; sin bloquear nada y
  en silencio si falla. **«N intentos · última nota X» solo si la respuesta trae
  `intentos`** — el servidor solo lo manda con `viewanalytics` (nota agregada de cualquiera que
  abrió el reto, nunca del alumno que lo pidió), así que el cliente no decide el rol, solo pinta
  lo que llega.
- **Galería conjunta:** `loadCreateGallery()` pide a la vez `api_create_status.php` y
  `accion=mis_retos` (esta no llama a Épica) y mezcla por fecha; cada fuente falla por separado. La
  tarjeta de reto es un `<a>` a su enlace (pestaña nueva), etiqueta «Reto» en `#34547A`.
- **«Ver todos los retos del curso»** llama a `accion=curso` y abre su `enlace`; no se pinta la lista.
  Como el navegador bloquea un `window.open` tras un `fetch`, se abre la pestaña EN el clic y se le
  pone la URL al llegar (si el navegador la bloquea, se ofrece un enlace).

## QA general de Épica — reglas permanentes (v1.27.2)

- **El sondeo de infografías/juegos lleva token, como Retos.** `pollCreateStatusOnce()`
  captura `pulsoAmpToken` al lanzar y sale en `.then`/`.catch` si ya no coincide
  (`stopCreatePolling()` lo incrementa vía `pulsoRetosReset()`). Sin eso, una respuesta
  tardía pintaba encima de Retos o de otra pantalla y reprogramaba el sondeo.
- **La galería NO se pide en cada sondeo.** `renderCreateStatus()` repinta desde
  `pulsoGalleryCache` y solo pide si nunca se cargó (y el encargo no es terminal);
  `pollCreateStatusOnce()` la refresca UNA vez al llegar a estado terminal. Cada carga son 2
  peticiones (`api_create_status` + `mis_retos`); `pulsoGallerySeq` descarta respuestas
  de cargas ya superadas.
- **El `motivo` TERMINAL nunca lleva detalle técnico.** Al tope de fallos transitorios,
  `reintentar_o_fallar_transitorio()` guarda un motivo genérico («No hemos podido conectar
  con el servicio de generación…») y deja clase y mensaje de la excepción solo en
  `error_log`: el motivo terminal lo ve el alumno en el panel y en la notificación. (Filas
  anteriores a v1.27.2 pueden conservar el texto técnico.)
- **`epica_base_url` solo `https://`**: `epica_client::base_url_error()` se comprueba en
  `precondiciones_error()` (ciclo adhoc y Retos) y, como red, `endpoint()` lanza. El token y
  el material no pueden viajar sin cifrar. El valor por defecto no cambia.
- **El aviso de `notify_completion()` es atómico**: se reclama (`UPDATE … SET notified = 1
  WHERE id = ? AND notified = 0`) ANTES de mandar, bajo un candado por encargo (Moodle no
  devuelve filas afectadas en `execute()`, y `notified` es int(1): no admite un valor
  único por proceso). Si el mensaje falla, queda `notified = 1`: se prefiere perder un
  aviso a mandarlo dos veces.
- **Un encargo cuya tarea adhoc no se pudo encolar** (`dispatch_to_epica()`) pasa a
  `fallado` con `notified = 1`: si no, quedaba `pendiente` para siempre gastando cupo.
- **`api_create_submit.php` serializa «comprobar cupo + insertar» con un `lock_config` por
  usuario** (doble envío ya no pasa el tope en 1). `paso_pendiente()` comprueba que
  `json_encode()` del sobre no dé `false` antes de firmar (UTF-8 inválido = cuerpo vacío).
- **`recoger_lamina()` aplica «`tema` vacío ⇒ `verificado = null`»**, como `recoger_juego()`.
  El panel avisa de `verificado === false` con el texto de la carta 5 §3.4 (el mismo de
  `juego.php`), nunca con «Épica marcó…».
- **`.pulso-chat-container.drawer-collapsed` redefine `height`/`max-height`/`min-height`**
  descontando su `bottom` real (150 px + 12 de margen): si se cambia un `bottom`, hay que
  cambiar su `max-height` a la vez, o la cabecera se sale por arriba en ventanas bajas.
- **Retos**: `enlace_epica()` compara también el puerto; `consultar_propuesta()` marca la
  fila `desconocido` ante un 4xx `propuesta-desconocida` (igual que `elegir`).

## Historial del alumno en Épica (carta 10) (v1.29.0)

Botón «Mi historial»: lleva al ALUMNO a `/mis-recursos` de Épica (sus láminas, juegos y retos
pedidos por Pulse; solo mirar). Contrato: `docs/epica_historial_carta10.md`. Reglas:

- **Puerta `/api/auth/alumno`, solo alumnado.** Un token de docente ahí es 403 `rol-sin-permiso`,
  así que `epica_historial.php` NO firma nada si el usuario tiene `viewanalytics` (ni si
  `rol_de()` no sale `estudiante`): pinta «El historial de Épica es para el alumnado…». El
  botón solo se pinta con `window.pulsoIsTeacher === false` (la UI no es control de acceso; lo es
  el endpoint) y la home lo mete en `PULSO_STUDENT_ONLY_*` anidado dentro de `PULSO_CREATE_ONLY_*`
  (los `preg_replace` de marcadores no se pisan: el de rol se aplica antes y los quita enteros).
- **Token NUEVO por POST y nunca en una URL ni en un log.** Se firma en `epica_historial.php`
  justo antes de pintar (vale 120 s, un solo uso) y viaja en el CUERPO de un `<form method=post>`
  autoenviado hacia `epica_client::endpoint('/api/auth/alumno')`, con `Cache-Control: no-store`
  y `Referrer-Policy: no-referrer`. No se usa `epica::autoenviar()` (no verificado su código). Un
  fallo al firmar loguea clase+mensaje y al navegador solo le da un texto genérico.
- **Pestaña nueva abierta en el clic**: el JS (`pulsoAbrirHistorial()`) crea un `<form>` POST
  `target=_blank` hacia NUESTRO endpoint con `courseid` + `sesskey`, lo envía y lo quita. Así no hay
  bloqueo de ventanas y el token nunca pasa por JS. Sin iframes ni historial dentro de Pulse.
- **Lo anterior al 05-10-2026 no aparece** (Épica no guardaba de quién era): el panel de Crear lo
  avisa bajo la galería. El `sub` (id de Moodle) y el `iss` deben seguir estables o el historial
  sale vacío. La sesión de Épica dura 2 h; se vuelve a pulsar el botón.
- **El rol firmado sale de `viewanalytics`, no de `createactivity`** (hallazgo de esta versión;
  ver la regla en «Integración con Épica — paso 3»).

## Auditoría de UX, fase 1 — visibilidad, diagnóstico y errores (v1.30.0)

Reglas permanentes salidas de la auditoría; las tres primeras son de seguridad/privacidad.

- **El RAG filtra por visibilidad AL RECUPERAR, nunca al indexar.** El índice sigue teniendo
  todos los módulos (el profesorado con acceso ve lo oculto). `classes/course_visibility.php`
  es la única fuente: `cm_visible()` / `section_visible()` / `chunk_visible()` (mismo criterio
  que `build_activity_link()`: `get_fast_modinfo()->uservisible`, falla CERRADO si no se puede
  comprobar) y `filter_instances()` para registros de tablas de módulo. En
  `embedding_manager::find_relevant_chunks()` y su variante léxica se filtra **después de
  puntuar y antes de recortar al top-K** (si no, un recurso oculto muy relevante se come
  huecos). Fragmentos sintéticos: `course_meta` siempre visible; `course_section` según la
  sección, y además `scrub_section_text()` quita del texto de una sección visible las líneas
  `- [tipo] nombre` de actividades ocultas (el indexador las metió todas). La **ruta directa**
  filtra igual: `resolve_direct_resource_query` (todas las colecciones), los 4 resolutores
  por sección, `list_section_activities`, las secciones ocultas (`$realSections`),
  `query_mentions_any_activity_name` (una actividad oculta «no existe» para quien no la ve) y los
  lectores de `block_pulso_content_chunks` / `get_live_resource_text`. **Cualquier lector nuevo
  de chunks o de `block_pulso_full_text` para un usuario final debe pasar por
  `course_visibility`.** Limitación conocida: la línea «Actividades en esta seccion: N» del
  fragmento de sección sigue contando las ocultas.
- **`rag_diagnostics` solo viaja con `viewanalytics`** (`chat_pipeline::client_rag_diagnostics()`
  en los dos endpoints; al alumno, `[]`). Lleva nombres de fragmentos y estado del índice. Su
  `message` nunca lleva el texto de una excepción (va a `error_log`).
- **Errores: por `error_code`, nunca texto técnico al usuario.** `classes/pulso_error.php` es el
  patrón único de todos los endpoints AJAX (`api_chat`, `api_chat_stream`, `api_ampliacion`,
  `api_create_form/submit/status`, `toggle_course`): respuesta `{success:false, error_code,
  message, detail?}`. `message` y `detail` son SIEMPRE texto escrito por nosotros; `detail` (causa
  genérica) solo para `viewanalytics`/`moodle/site:config`. Cualquier otro Throwable → `unknown`
  + `error_log`; **prohibido `getMessage()` de un Throwable cualquiera al cliente**. Códigos:
  `busy` (429/529/5xx), `config` (clave/saldo/modelo/configuración), `network`, `session`,
  `access`, `disabled`, `bad_request`, `empty`, `refusal`, `encoding`, `unknown`, más
  `quota` (con mensaje propio). Un servicio nuevo lanza `pulso_error` con texto para persona; no
  `\Exception` (se vería como `unknown`). `require_session()` va ANTES de `get_course()` y se usa
  `pulso_error::require_sesskey()` en vez de `require_sesskey()` (código `session`, no
  `invalidsesskey`). El cliente elige el texto por `error_code` en `pulsoFailureMessage()`
  (`PULSO_ERROR_TEXTS`); si se añade un código, va en `pulso_error`, `lang/{en,es}` (`err_*`) y ahí.
- **Stream: el cliente NO cae al XHR ante un JSON `success:false`.** La fase 1 de
  `api_chat_stream.php` (sesión, sesskey, permiso, desactivado) contesta 4xx con JSON; el cliente lo
  lee con cualquier código y lo pinta. El fallback a `api_chat.php` queda solo para fallo de red,
  404 y 405. `api_chat.php` ya no devuelve 500 genérico por esas causas.
- **`ampliacion_service` y `epica_client`**: los avisos/motivos que ve la persona son genéricos
  («No hemos podido buscar vídeos ahora mismo»; `MOTIVO_SERVICIO_NO_DISPONIBLE` /
  `MOTIVO_SERVICIO_RECHAZO`); el detalle (HTTP, `quotaExceeded`, `local_awkepica…`) va a `error_log`. El
  mensaje «sin contenido disponible» usa «el juego»/«la infografía» según `tool`. Una fila
  `fallado` de ampliación ya NO devuelve su `motivo` guardado (las anteriores a 1.30.0 pueden
  llevar códigos HTTP): texto fijo.
- **`block_pulso.php`**: con Pulse desactivado o sin `usechat` el bloque no pinta nada; solo
  quien tiene `moodle/course:update` ve «Pulse está desactivado en este curso».
- **`lang/es/block_pulso.php` es obligatorio**: toda cadena nueva de `lang/en` lleva aquí su
  traducción (mismas claves; comprobable con un diff de claves).

## Auditoría de UX, fase 2 — funcionar (o decir por qué no) en cualquier plataforma (v1.31.0)

Reglas permanentes. Tocan `db/caches.php` (definición nueva `chatrate`): hay que pasar por Notificaciones.

- **Épica es OPCIONAL.** `version.php` ya no declara `local_awkepica` en `dependencies`: Pulse se
  instala en una plataforma sin Épica. `epica_client::disponible()` = `class_exists('\local_awkepica\epica')`
  + `configurado()` + `base_url_error() === null` (memoizado por petición) es la ÚNICA puerta:
  **ninguna referencia a `\local_awkepica\...` fuera de código que ya haya pasado por `disponible()` o
  `class_exists()`** (`precondiciones_error()` ya lo comprueba por sí mismo; la espera larga sale de
  `epica_client::espera_larga()`, que cae a 180 s si el plugin no está). Sin Épica: `render_chat_simple()`
  quita del HTML (marcadores `PULSO_EPICA_ONLY_START/END`, no CSS) los CTA de infografía, juego, retos y
  «Mi historial» —«Ampliar recurso» sigue— y expone `window.pulsoEpicaAvailable` (el cliente salta
  `mis_retos`); `api_create_form` (todo menos `tool=ampliacion`), `api_create_submit`, `api_retos` y
  `epica_historial` contestan «La creación de contenidos no está disponible en este sitio» **sin
  insertar nada** (antes el encargo se guardaba, gastaba cupo y fallaba en el cron). `epica_base_url`
  ya NO tiene valor por defecto (antes apuntaba al QA: una producción mal configurada habría mandado
  material al QA); vacío = no disponible; solo `https://` o vacío (validado al guardar).
- **El texto completo NO depende de OpenAI ni del RAG.** `rag_retriever::index_course()` guarda
  `block_pulso_full_text` SIEMPRE y en su propio try; los fragmentos + embeddings solo si
  `embeddings_wanted()` (RAG activado Y clave de OpenAI) y en otro try (un fallo de embeddings no pierde
  el texto: antes `new embedding_manager()` lanzaba sin clave ANTES de guardarlo y en una instalación nueva
  Crear decía siempre «no hay recursos»). Las dos tareas de cron se saltan solo si
  `text_extraction_wanted()` es falso: RAG apagado Y sin Épica Y sin claves de Ampliación. El aviso de lista
  vacía `not_indexed` del panel dice «el curso aún no se ha procesado…» (esperar a la pasada nocturna o
  que el administrador ejecute la tarea). `request_background_index()` sigue siendo solo del camino RAG.
- **Tope del chat por persona** (`classes/chat_rate_limiter.php`): `chat_max_por_minuto_usuario` (6,
  ventana móvil) y `chat_max_por_dia_usuario` (150), 0 = sin límite; contador en MUC (definición
  `chatrate`, TTL 1 día). Se comprueba tras `check_enabled()` y ANTES de contexto, RAG y Anthropic en los
  dos endpoints; error `rate_limited` / `rate_limited_day` (HTTP 429, texto en `err_rate_limited*`). **Falla
  ABIERTO** (caché sin registrar o caída): un limitador no puede tumbar lo que protege. Cuenta también
  rutas directas y denegaciones por rol. MUC no es atómico entre procesos: candado corto por usuario.
- **Streaming (`anthropic_connector`)**: `event: error` (p. ej. `overloaded_error` con HTTP 200) se trata
  como un 529 — reintento si aún no se emitió ningún token; con texto ya emitido, `moodle_exception`
  `error_api_response` con debuginfo `HTTP 529 (tipo)` que `pulso_error` clasifica «busy». **Sin
  `message_stop` + error de cURL = error de red**, no se devuelve lo acumulado como si fuera completo
  (antes se reparaba un JSON a medias). `stop_reason = max_tokens` → `truncated: true` en el payload
  (`final`/JSON): el cliente avisa «La respuesta se ha cortado», NO la guarda en el historial (ni servidor
  ni `conversationHistory`) y no pide sugerencias. Reintentos: respeta `retry-after` (tope 10 s,
  cabecera por `CURLOPT_HEADERFUNCTION`/`get_raw_response()`), y UN reintento de errores de cURL de
  conexión (6, 7, 35, 52, 55, 56; no el 28) si no se emitió nada. Timeout de la llamada no-stream 110 s.
- **Timeouts y proxies**: servidor `core_php_time_limit::raise(180)` en los endpoints de chat; cliente
  `AbortController` de 150 s en el stream (al saltar: «La respuesta está tardando demasiado…», NUNCA
  cae al XHR: repetiría la petición) y `xhr.timeout = 110000`; `sendMessageXHR` también pone
  `pulsoSending`. SSE: además de `X-Accel-Buffering: no`, `Content-Encoding: identity` y
  `apache_setenv('no-gzip')`, y `: ping` (comentario SSE que el cliente ignora) entre las fases
  contexto/RAG, en las esperas de reintento y reenviando los `ping` de Anthropic (`$onping`). El
  embedding de la CONSULTA del chat tiene timeout de 8 s (`embedding_manager::QUERY_TIMEOUT`; hay
  fallback léxico); el de indexación sigue en 60 s.
- **Ampliación con presupuesto de tiempo** (`TIME_BUDGET_S` = 45): `send_fast_query(..., $deadline)` y
  `http_get_json` acotan cada llamada a lo que queda; con < 10 s se salta el juez (selección sin juez) y
  se responde con lo que haya. Un fallo TRANSITORIO (red, 429/5xx/529, presupuesto agotado:
  `is_transient()`) se guarda con la marca `[transitorio] ` al inicio de `motivo` (sin columna nueva) y se
  cachea 5 minutos (`TRANSIENT_FAILURE_TTL`) en vez de 1 h. El código de la `RuntimeException` de
  `http_get_json` es el HTTP (0 = red/presupuesto): no cambiarlo sin tocar `is_transient()`.
- **Diagnóstico para administración** (`diagnostico.php`, `require_admin()`, enlazada desde los ajustes;
  lógica en `classes/diagnostics.php`): Anthropic, OpenAI, YouTube (`videos.list`, 1 unidad), OpenAlex,
  Épica, cron (`MAX(lastruntime)` de `task_scheduled`), tarea de indexación, tareas adhoc de
  `block_pulso`, y cursos con el bloque sin texto completo. Todo por el `\curl` de Moodle (proxy);
  `check_api_key.php` / `check_anthropic_key.php` ahora son envoltorios de la misma clase. **Nunca
  muestra claves** (`diagnostics::scrub()`). Los ajustes numéricos validan `>= 0` (`set_validate_function`).
- **Cron parado visible**: `api_create_status` marca `delayed` si un encargo lleva > 10 min en
  `pendiente`; el panel dice «La cola de trabajos de este sitio va con retraso…». (El hueco conocido del
  429 `cuota-del-centro` también deja encargos en `pendiente`: el aviso es neutro a propósito.)

## Dev notes

- No PHP installed locally: lint with the portable PHP in the session scratchpad
  (download `php-8.3-nts` zip from windows.php.net) or Docker (`php:8.2-cli`)
  if the daemon is running.
- The big JS blob lives inside a nowdoc heredoc in `chat_simple_view.php` —
  `${...}` template literals are safe there; the small `JSINIT` heredoc DOES
  interpolate PHP variables.
- Language: UI and answers are Spanish-first; keep new user-facing strings in
  Spanish and add lang strings to `lang/en/block_pulso.php`.
