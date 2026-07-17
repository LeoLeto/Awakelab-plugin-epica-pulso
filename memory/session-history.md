# Historial de sesiones — block_pulso

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

**Pendiente**: el bug backlog #1–#6 de CLAUDE.md (evaluación jul-2026) sigue SIN empezar
— el trabajo de esta sesión fue migración + estabilidad + UI, no el backlog.

**Convención de flujo**: Marcos quiere commit+push a origin/main tras CADA cambio, sin
preguntar. Mensajes de commit ≤10 palabras. Versioning rule en cada cambio.
