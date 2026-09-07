# Historial de sesiones — block_pulso

## 2026-09-07 — Conversación proactiva (v1.15.0)

Implementada la idea que quedaba pendiente de la ronda 5. Las reglas que deben persistir
están en `CLAUDE.md` ("Conversación proactiva — dos caminos, y el defecto es callarse");
aquí, las decisiones y lo que costó encontrar:

- **Se ataca por los dos caminos porque el prompt no cubre el chat entero.** La regla
  `## INICIATIVA` gobierna solo las respuestas del LLM; las de una actividad concreta se
  construyen desde la BD sin modelo (verificado en el QA: el nº de preguntas de un
  cuestionario acertaba incluso con la conversación contaminada). El propio ejemplo que
  motivó la idea —el cuestionario— se resuelve por ruta directa, así que sin tocar
  `build_direct_followups()` la funcionalidad no habría existido donde se pidió.
- **El texto de la regla se comprimió de ~750 a ~280 tokens** tras preguntar el coste. Se
  paga SIN CACHEAR en cada respuesta del LLM: ~$0,56 por mil mensajes en `claude-sonnet-5`
  ($2/MTok de input; ojo, $3 es Sonnet **4.6**, otro modelo). Se recortaron ejemplos
  duplicados y explicaciones, no criterio: los cuatro casos de "cállate" siguen enteros.
  Solo la pagan las dos llamadas de respuesta principal — `answer_document_question()` y
  `summarize_document_text()` llevan su propio system prompt corto, y los follow-ups de
  Haiku también.
- **`sed -i` aplanó los CRLF de `system_prompt_designer.php` y eso solo habría invalidado
  el prefijo cacheado**, sin cambiar una palabra del prompt. El fichero es CRLF en el
  árbol de trabajo y LF en el índice (`core.autocrlf=true`, sin `.gitattributes`), y el
  prompt base es un nowdoc: sus finales de línea son bytes del prompt. El parche se aplicó
  con un script que preserva el EOL de cada fichero. Detalle a recordar: un md5 medido en
  Windows NO sirve para verificar el del servidor, que tiene LF.
- **Verificado con pruebas de lógica** (PHP 8.3 portátil del scratchpad, script en
  `test_proactividad.php` del scratchpad de la sesión): 181 comprobaciones, 0 fallos —
  texto de los dos prompts base intacto contra `git HEAD`, la regla fuera del bloque
  cacheado y `FORMATO DE SALIDA` todavía al final, ausencia de datos de grupo en la
  variante de alumno, las 12 ramas de sugerencias con su nombre dentro, ≥2 sugerencias
  contextuales para el alumno en cuestionario/tarea/recurso (y la tercera filtrada), y los
  13 casos de `is_teacher_only_query()` de las tres capas.
- **Sin verificar en Moodle**: que el ofrecimiento sale cuando aporta y NO en respuestas
  de dato puntual, que no se repite en turnos seguidos, y que a un alumno no se le ofrece
  nada del grupo. Y sigue pendiente comprobar `cache_read_input_tokens > 0` desde el
  segundo mensaje después de este cambio.

**Pendiente decidido, no hecho — mover la mitad invariable de la regla a los prompts
base.** El criterio y la forma (~200 tokens, idénticos para todos) podrían ir dentro de
`generate_system_prompt()` / `generate_student_system_prompt()`, dejando en el bloque
dinámico solo la línea de rol: la regla pasaría a costar 0,1x en lugar de precio completo.
Condición de entrada, en este orden:

1. Que el texto esté calibrado contra el Moodle real (mientras se itere, tenerlo en el
   bloque dinámico es gratis; dentro del base, cada retoque invalida el prefijo).
2. **Medir el prompt base del ALUMNO con `count_tokens`** — esto es lo que puede estar
   costando dinero ya. Estimado por caracteres en ~950 tokens, justo por debajo del mínimo
   cacheable de 1.024 de `claude-sonnet-5`, así que probablemente **no se cachea desde
   v1.10.0, en silencio y sin ningún error** (`CLAUDE.md` lo daba por sabido con ~860
   tokens, pero nunca se midió). Si está por debajo, meterle esos ~200 tokens lo cruza el
   umbral y el prompt de alumno empezaría a cachearse por primera vez: ahorra más que la
   propia regla. No se pudo medir en la sesión — no hay credenciales de la API en el
   entorno de desarrollo (la clave vive en la config de Moodle del servidor).

Alternativas evaluadas y descartadas para lo mismo: un **segundo breakpoint** de caché
(permitido, hasta 4, y el mínimo se mide sobre el prefijo acumulado — pero obliga a subir
iniciativa y conversación por delante del RAG, mismo ahorro sin el bonus del alumno);
**condicionar** la inyección de la regla (es el patrón que la propia doc de Anthropic marca
como invalidador silencioso, y una regla intermitente da comportamiento inconsistente); y
el **`{"role":"system"}` a mitad de conversación**, que sería el sitio ideal pero **no
está soportado en `claude-sonnet-5`** (400) — solo en Opus 5/4.8 y la familia Fable/Mythos.
Si algún día cambia el modelo por defecto del plugin, esa pasa a ser la mejor opción.

## 2026-09-07 — Ronda 5: QA verificado en Moodle (56/56) + idea de conversación proactiva

Ejecutadas las 56 preguntas de la matriz en el curso SANS0001 (id 92) contra v1.14.1, en
el Moodle real. Matriz cerrada: **41 Bien / 3 Regular / 1 Mal / 11 No aplica** (la
evaluación empezó en 17/5/26/7). No queda ninguna pregunta sin reprobar. Lo que la
verificación real desmintió del "hecho en código":

- **#3 contaminación: NO cerrado.** La pregunta que va justo DESPUÉS de una respuesta de
  analítica sigue devolviendo la tabla anterior (P38 tras "¿cuántos accesos?", P44 tras
  "How many students passed?"). Quitar las filas de `data` del digest no bastó. Dato
  nuevo que acota el sitio: en la MISMA conversación contaminada, "¿cuántas preguntas
  tiene el CUESTIONARIO TIPO TEST?" acertó — y esa va por ruta directa sin LLM. Luego lo
  que se contamina es la **ruta del LLM**, no el historial en sí.
- **#5 ordinales: NO cerrado.** "Dame el enunciado del primero", tras consultar MATERIAL 1
  y MIC Tema 2, devuelve el enunciado de la primera pregunta del cuestionario. Y da
  **exactamente la misma respuesta sin ningún documento previo en el historial**: prueba
  de que `ordinal_unresolved_answer()` no se alcanza y el ordinal se resuelve contra el
  quiz antes de mirar el historial.
- **#1, #2 y #4: cerrados y verificados en Moodle.** Analítica por su ruta (nota media
  9.18/10, % aprobados, matriculados, nota de un alumno), listado completo de sección,
  "tema N" como sección, contenido real de los PDF y enunciados literales. Los cuatro
  casos de control del `CLAUDE.md` pasan **seguidos en una misma conversación**.
- **P46 mejoró a medias**: ya no presenta el "Foro de dudas" como si fuera la respuesta,
  pero tampoco avisa de que la actividad no existe. Con "la tarea T1" (P48) sí lo hace y
  encima enumera las reales, así que `build_missing_activity_notice()` no cubre el fraseo
  "¿de qué trata la actividad X?".
- **P55/P56 → No aplica**: SANS0001 no tiene ningún SCORM (0 enlaces a `/mod/scorm/`).
  Hace falta otro curso para poder evaluarlas.

**Idea pendiente — conversación proactiva** (sin implementar; el prompt de arreglo está en
`PROMPTS_PROACTIVIDAD.md`). Marcos quiere que el chat tenga iniciativa en general, no solo
en un caso: "como si hablaras con una persona de verdad". Su ejemplo era el cuestionario
(leer los enunciados y ofrecer el temario para repasar), pero el objetivo es que ofrezca
ayuda siempre que pueda. Restricción de diseño que hay que tener presente: la regla del
prompt solo gobierna las respuestas del LLM, y el propio ejemplo del cuestionario se
responde por **ruta directa desde la BD sin pasar por el modelo** (se vio en el QA:
"¿cuántas preguntas tiene el CUESTIONARIO TIPO TEST?" acertaba incluso con la
conversación contaminada). Así que hay que tocar dos sitios: la regla en el bloque
dinámico y las sugerencias de `build_direct_followups()`. **Decisión de producto tomada:
el ofrecimiento NO debe salir en todas las respuestas** — por defecto no se ofrece nada, y
solo aparece si hay un siguiente paso concreto que el usuario querría y Pulso puede
cumplirlo. En respuestas de dato puntual ("tiene 15 preguntas") debe callarse: si sale
siempre es ruido y alarga todas las respuestas.

## 2026-09-07 — Ronda 4: contaminación residual y "no hay match" (v1.14.0 → v1.14.1)

Evaluación de 34 preguntas (41 Bien / 2 Regular / 4 Mal) en `PROMPTS_ARREGLO_v1.14.md`,
sin versionar. Dos raíces, un commit cada una. Reglas persistentes en `CLAUDE.md`; aquí
lo que costó encontrar:

- **Una parte del bug crítico la introduje yo en v1.12.0.** El digest del historial en
  el JS (`pulsoHistoryDigest`) unía las partes con punto y aplastaba los saltos de
  línea, mientras el del servidor los conservaba. Como el historial que se envía es el
  del cliente (el de `$SESSION` no persiste tras `write_close()`), las anclas
  `^Recurso:` / `^Seccion:` desaparecían y `^Cuestionario:\s*(.+)$` capturaba la frase
  entera como nombre del recurso: el history-hint la pegaba a la pregunta y la ruta
  directa dejaba de reconocerla. Verificado ejecutando la función con node antes de
  tocar nada. Lección: si una función existe duplicada en PHP y en JS, hay que
  probar **las dos**; probé solo la de PHP en su día.
- **La asimetría "solo falla después de analítica" tenía una explicación concreta**: el
  digest incluía filas de `data`, y en una respuesta de analítica esas filas son una
  tabla de métricas con el mismo aspecto que el formato de salida que exige el prompt.
  El modelo la leía como plantilla a continuar. Tras una respuesta de contenido no
  pasaba porque su digest es prosa. Fuera las filas → digest de analítica de 81
  caracteres en vez de ~350.
- **El "Foro de dudas" como respuesta a "la actividad IA" no lo pude reproducir en
  código**: ni el matcher exacto ni el difuso lo eligen (comprobado con los nombres
  reales del curso). La hipótesis que queda es que la ruta directa devolvía `null` y
  la recuperación semántica traía el fragmento "menos malo", que el modelo presentó
  como respuesta inventándole las cifras. Por eso el arreglo no es solo endurecer el
  matcher: hay un aviso explícito de "esa actividad no existe" en el contexto, y queda
  un `debugging()` con la actividad elegida y su puntuación para confirmarlo en el
  sitio real.
- **Verificado**: 26 comprobaciones nuevas de historial/ordinales/matcher, 13 del aviso
  de actividad inexistente (con `get_fast_modinfo` simulado), 7 del digest del cliente
  en node, más las suites anteriores (16 + 12 + 17 + 16 + 6 + modo alumno 17/17 y
  19/19). El md5 del prompt base del profesorado sigue intacto.
- **Sin verificar en Moodle**: los 5 pasos de la comprobación obligatoria del prompt 1
  en una misma conversación, y las dos del prompt 2. Y hay una decisión de producto que
  conviene mirar en la demo: el aviso de "no existe" salta también cuando el usuario
  abrevia el nombre de una actividad que sí existe (dirá que no existe y ofrecerá las
  reales, en vez de adivinar).

## 2026-09-04 — Los 6 arreglos de la evaluación del curso SANS0001 (v1.11.1 → v1.13.3)

Evaluación de 34 preguntas sobre el curso id 92 (`PROMPTS_ARREGLO_v1.11.1.md`, sin
versionar). Un commit por bug, en el orden 6 → 2 → 3 → 4 → 5 → 1. Las reglas que
deben persistir están en `CLAUDE.md` ("El historial NUNCA contiene JSON" y
"Enrutado: sección vs recurso vs metadatos"); aquí, solo el diagnóstico que costó
encontrar y lo que queda sin verificar:

- **El bug crítico (repetía la respuesta anterior) NO era el history-hint.** El hint
  ya estaba bien acotado desde v1.9.x (con "dame un ranking de notas de los alumnos"
  ni se activa, por `is_course_analytics_query`). La causa real: el historial
  guardaba el JSON crudo de cada respuesta y `prepare_history()` lo cortaba a 500
  caracteres, así que los turnos de asistente eran objetos JSON **sin cerrar**; con
  el prompt exigiendo "tu respuesta COMPLETA debe ser solo el objeto JSON", el modelo
  continuaba ese objeto en lugar de responder. Explica que fuese intermitente, que
  empeorase con los turnos y que se arreglase recargando.
- **La caché MUC quedó descartada** como causa: solo guarda el contexto de curso, no
  respuestas, y su clave ya incluía el rol desde v1.10.0.
- El "coge el primer recurso de la sección" (`array_values($resources)[0]`) era el
  responsable real de responder con UN PDF a un listado de sección: la sección se
  identificaba bien. Buscar bugs de "matcher" en el matcher no habría dado con él.
- **Verificado con pruebas de lógica** (PHP 8.3 portátil del scratchpad, `mbstring`
  activado a mano): 16 comprobaciones del digest de historial (incluye que el
  history-hint sigue localizando el recurso del turno anterior — el digest une con
  salto de línea justo por eso), 12 de sección vs recurso, 17 de intención
  contenido/metadatos, 16 del enrutado de "resúmeme el curso" y 6 de reparación de
  JSON con el fragmento roto real capturado en la evaluación. El md5 del prompt base
  del profesorado sigue intacto.
- **Sin verificar en Moodle**: las 34 preguntas hay que volver a pasarlas en el curso
  92. En especial, los 4 casos de control del bug 3 y que
  `cache_read_input_tokens` siga > 0 desde el segundo mensaje.

## 2026-09-02 — Modo alumno: el chat se abre a estudiantes solo para contenido (v1.10.0)

Antes de esto, un alumno no podía usar el plugin en absoluto (`block_pulso.php`
exigía `viewanalytics`). Ahora entra con la capability nueva `block/pulso:usechat`
y tiene un chat de CONTENIDO, con la analítica cerrada en servidor. Las reglas
resultantes (dos capabilities, tres capas, prompt de alumno, qué queda fuera de
alcance) están en `CLAUDE.md` → "Modo alumno"; aquí solo lo que no se deduce del
código:

**Decisiones de producto tomadas con Marcos**:
- El alumno **no ve nada del grupo**, y tampoco sus propios datos individuales.
  Se descartó a propósito la opción "solo mis notas": la regla "nada del grupo" es
  coherente y más fácil de defender en reunión que una lista de excepciones.
  `total_students` / `total_enrolled_users` caen con ella.
- Ante la duda, **negar**: privacidad > cobertura. Para que un falso positivo no
  deje al usuario en vía muerta delante de un cliente, el mensaje de negativa
  **invita a reformular** mencionando el material o la sección. Si pregunta por sus
  propias notas, el mensaje cambia y le redirige al libro de calificaciones.

**Cómo se validó** (no hay Moodle ni PHP local; PHP 8.3 portátil en el scratchpad,
con `mbstring` activado a mano vía `php.ini` — sin él, `mb_strtolower` no existe y
nada de esto se puede probar):
- Detector contra las **56 preguntas reales** de `Pulso_AI_matriz_evaluacion.xlsx`
  (parseado el xlsx con SimpleXML, sin librerías): 24 negadas, y son exactamente
  las 24 de analítica; las 32 de contenido pasan.
- **33 variantes de alumno** escritas a mano (15 que deben negarse, 18 de contenido
  con palabras trampa como "la nota al pie", "nota de crédito", "media aritmética",
  "promedio de ventas del ejercicio 4"): 15/15 y 18/18.
- **16 casos frontera** que cazaron 4 fallos reales antes de dar el cambio por
  bueno: "cuántas respuestas tiene la pregunta 3" y "cuántos intentos me quedan" se
  negaban sin motivo; "cuántos han visto el vídeo", "compárame con el resto de la
  clase" y "show me the students at risk" se colaban.
- Render por rol: 17 comprobaciones sobre el HTML generado (el alumno recibe 8
  tarjetas, ninguna de analítica; el profesor 14 y su saludo intacto).
- Prompt: 19 comprobaciones (sin `grades_and_quizzes`/`course_completions`/
  `access_logs`/`total_students` en el prompt del alumno) y **md5 del prompt base
  del profesor idéntico al de HEAD** (normalizando CRLF, que `git show` convierte a
  LF: si no se normaliza, los hashes parecen distintos sin serlo).

**Pendiente de verificación real**: probarlo en el Moodle de Marcos con un usuario
ESTUDIANTE y otro PROFESOR. Las pruebas de arriba son de lógica, no de integración.

## 2026-07-16/17 — Migración a Anthropic + estabilización + rediseño UI (v1.1.10 → v1.4.1)

**Migración chat a Anthropic (v1.2.0)**: `openai_connector.php` → `anthropic_connector.php`
(Messages API, `claude-sonnet-5` principal / `claude-haiku-4-5` follow-ups). Embeddings RAG
siguen en OpenAI (`embedding_manager.php`, intocado). Settings: `anthropic_key` nueva +
`openai_key` reetiquetada "solo embeddings"; `check_anthropic_key.php` nuevo.

**Cadena de bugs post-migración y sus causas reales** (detalle en CLAUDE.md → "Anthropic
API constraints"):
1. JSON crudo en UI → prompt sin regla de formato; se añadió extracción robusta de JSON
   (llaves balanceadas) en `clean_answer()` + regla "FORMATO DE SALIDA" + prefill `{`.
2. `error_api_response` en todo → el prefill assistant NO está soportado por
   claude-sonnet-5 (400). Se retiró el prefill (v1.2.4). Diagnóstico: se descubrió que el
   4º arg de `moodle_exception` es `$a`, no debuginfo (5º).
3. JSON crudo otra vez → truncación por `max_tokens` (JSON pretty-printed de Claude).
   Fix: max_tokens 800→2000→3000 + JSON compacto obligatorio en prompt (v1.2.5).
4. `&quot;` literales → doble `escapeHtml` en el fallback del frontend (v1.2.5).
5. JSON crudo con `"data":[[` → glitch del modelo; `repair_json_object()` en
   `chat_pipeline` repara [[/truncados/comas colgantes con validación json_decode (v1.3.1).

**Rediseño UI (v1.3.0 → v1.4.1)**: identidad Awakelab 2026 (Poppins, azules profundos,
cian sobre oscuro, isotipo), tema OSCURO estilo Phia con pantalla de inicio
(`#pulso-home`): saludo con nombre del profesor + tarjetas de acción predefinidas en 2
secciones (Analítica del curso / Contenido del curso). Indicador "pensando" = onda de
pulso ECG en cian (firma del producto). Tablas oscuras con píldoras de estado, etiquetas
de columnas traducidas (`PULSO_FIELD_LABELS`/`pulsoFieldLabel`). Accesibilidad: aria,
focus-visible, prefers-reduced-motion. Botón "Nueva conversación" en header.

**Estado real del bug backlog #1–#6** (corregido 2026-08-03 leyendo git log + código; la
nota anterior "sigue SIN empezar" era incorrecta — el backlog se atacó ANTES de la
migración a Anthropic, en los commits `24fcc60`…`da3cda5`):

| Bug | Commit | Estado en código |
|---|---|---|
| #1 keywords analíticas en `is_pdf_content_query` | `24fcc60` | hecho (regex ya sin `nota media`/`cuántos alumnos`…) |
| #2 matcher engancha por palabra genérica | `5d12e1c` | hecho (`match_activity_by_name_fuzzy`: stopwords, ≥2 hits o cobertura total, números como discriminador) |
| #3 contaminación history-hint | `03013ba` | hecho (`build_direct_query` exige anáfora/ordinal + `is_course_analytics_query` corta el hint) |
| #4 extracción PDF (CID/Identity-H) | `b7b08c4` | hecho SIN tocar servidor: `smalot/pdfparser` vendorizado en `lib/pdfparser/` + validación `is_extracted_text_useful()`; `pdftotext` queda como fallback opcional |
| #5 referencias ordinales | `8286f63` | hecho (`detect_ordinal_reference` + `list_distinct_resources_in_history`) |
| #6 mejoras | `e01bda0`, `4585a41`, `da3cda5` | docx/pptx ✓, ranking con permisos ✓, indexación SCORM ✓ — **FALTA** el modo "resumen/explicación de unidad" acotado al SCORM actual (P55/P56): `rag_retriever` no tiene ninguna rama scorm |

**No verificado**: ninguno de esos fixes se ha re-validado ejecutando de nuevo las 56
preguntas de `Pulso_AI_matriz_evaluacion.xlsx`. "Hecho en código" ≠ "cerrado".

**Pendiente real**: (a) re-ejecutar la evaluación de 56 preguntas y actualizar la matriz;
(b) modo alumno sobre SCORM (P55/P56); (c) opcional, mejorar RAG con Voyage AI + reranker
(análisis en `Embeddings_A_vs_B.md`, decisión tomada = opción A, OpenAI para embeddings).

**Convención de flujo**: Marcos quiere commit+push a origin/main tras CADA cambio, sin
preguntar. Mensajes de commit ≤10 palabras. Versioning rule en cada cambio.
