# Historial de sesiones — block_pulso

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
