# Historial de sesiones — block_pulso

## 2026-09-25 — epica_client ajustado al contrato real de local_awkepica (v1.20.3)

Primer encargo real (id 6) falló al desplegar v1.20.2: `TypeError:
local_awkepica\epica::firmar_por(): Argument #4 ($curso) must be of type string,
stdClass given` — `paso_pendiente()`/`paso_sondeo()` pasaban el objeto `$course`
entero en vez de `(string) $course->id`. Épica mandó `docs/local_awkepica_api.md`
(sacado de su propio código) con el contrato exacto, que hasta ahora se había
adivinado a base de suposiciones defensivas. Diff mostrado antes de aplicar, como
pidió Marcos.

- **El bug real era solo el `TypeError` de `firmar_por()`.** El resto del ajuste es
  reemplazar suposiciones defensivas por el contrato confirmado, no arreglos de
  fallos ya vistos.
- **`normalize_response()` habría leído `http=0` en un 202 real.** `pedir()`
  devuelve siempre `{errno, error, http, datos, crudo}` — `http`, no `httpcode` ni
  ninguna de las otras claves que probaba la versión defensiva. Con la forma
  antigua, un 202 de verdad se habría leído como `httpcode=0` (ninguna de las
  claves que buscaba existía) y el encargo se habría dado por fallado **con el
  trabajo ya aceptado por Épica** — el bug que el propio encargo pedía evitar,
  nunca reproducido porque ningún encargo real había llegado tan lejos todavía (el
  `TypeError` cortaba antes).
- **El criterio de "transitorio" se movió de dentro del `catch` a justo después de
  CADA `pedir()`.** Antes solo una excepción de transporte podía disparar un
  reintento; ahora un `pedir()` que vuelve con `errno`/`http=0`/`http>=500` (sin
  lanzar nada, que es lo normal según el contrato) también lo hace, vía
  `es_transitorio()`. Esto eliminó `manejar_excepcion_transporte()` y
  `extraer_http_de_excepcion()` enteras: ya no hace falta adivinar si una
  excepción "trae" un código HTTP dentro, porque `pedir()` no lanza para eso.
- **202 sin `datos['trabajo']`** pasó a ser fallo terminal explícito
  (`marcar_fallo()`), en vez de guardar `epica_job_id` como cadena vacía y dejar
  que el encargo se quedara sondeando un trabajo que nunca existió.
- **`retry_after()` leía claves que Épica nunca manda** (`retry_after`,
  `reintentar_en`, `Retry-After` de cabecera). El contrato dice que `pedir()` no
  expone cabeceras y que el 429 trae la espera en el cuerpo, en `esperaS`.
- **Precondiciones nuevas antes de firmar** (`verificar_precondiciones()`,
  compartida por los dos pasos): `!epica::configurado()` y usuario sin `email` —
  ninguna de las dos las comprueba `firmar_por()` por sí solo («quien llame,
  comprueba», dice el propio contrato).
- **Sin verificar contra Moodle/Épica real** (mismo bloqueo de siempre: sin PHP ni
  Moodle local en este entorno). Verificado balance de llaves/paréntesis con
  `node` sobre el fichero completo tras el cambio.

## 2026-09-24 — Tope a los reintentos de red del ciclo con Épica (v1.20.2)

Primer encargo real (id 6, `sanase-test` → `entorno-qa-2`) se quedó en `pendiente`
reintentando cada 60s sin motivo visible: los `catch(\Throwable)` de
`paso_pendiente()`/`paso_sondeo()` solo hacían `error_log()` y reencolaban sin
tope ni rastro en `mtrace()` (invisible en la salida del cron). Encontrado y
arreglado en la misma sesión, con diff mostrado antes de aplicar, como pidió
Marcos.

- **Tope real**: contador `error_count` (columna nueva) de excepciones
  SEGUIDAS; a las `CONSECUTIVE_ERROR_THRESHOLD` (10, ~10 min) el encargo pasa
  a `fallado`. Cualquier 202/200/429 lo pone a 0 — un 429 ya demuestra que la
  red funciona. Reglas completas en `CLAUDE.md` → paso 3.
- **Ajuste pedido tras ver el primer diff**: un 5xx de Épica extraído de la
  excepción (por si `epica::pedir()` lanza en un HTTP de error en vez de
  devolverlo, cosa que no se puede confirmar sin el código de
  `local_awkepica`) se trata como TRANSITORIO, igual que un timeout — no como
  un 4xx, que sí es terminal por `procesar_error_encargar()`/
  `procesar_error_sondeo()`. Un 5xx es un problema de ellos, no algo que
  "no mejora solo" represente bien.
- **`motivo` se reutilizó** para el último error transitorio en vez de crear
  una columna `last_error` — es seguro porque `api_create_status.php` ya lo
  expone en el payload y basta con gatearlo: mientras el encargo no es
  terminal, solo lo ve quien tiene `viewanalytics` (un alumno dueño no
  necesita la clase+mensaje de una excepción PHP).
- **Sin verificar contra Moodle/Épica real** (mismo bloqueo de siempre: sin
  PHP ni Moodle local en este entorno). Verificado balance de llaves/paréntesis
  con `node` en los ficheros PHP tocados y `install.xml` parseado como XML
  válido con PowerShell.

## 2026-09-23 — Revisión del sobre real de Épica: 4 arreglos + limpieza (v1.20.1)

Marcos activó el modo de ensayo y leyó el sobre real de un encargo (id 3): la
estructura cumple el contrato, pero encontró 2 fallos importantes y 2 menores.
Diff mostrado antes de aplicar, como pidió.

- **material.texto no era literal (el más importante).** `chunk_text()` guardaba
  en `block_pulso_full_text` el mismo texto que se pasa al chunking del RAG —
  que lleva encuadre añadido (nombre del módulo, "Archivo: X (mimetype)",
  "Contenido PDF extraído:", "Pregunta:"/"Feedback:" en quiz, "## Título" en
  book/wiki). Épica compara el material contra lo generado para verificar
  fidelidad, así que ese encuadre synthetic contaminaba la comparación.
  **Decisión de alcance, no pedida explícitamente así**: el encargo solo citaba
  el caso resource/PDF (líneas ~439/~516), pero el defecto es el mismo patrón en
  los 8 tipos de módulo — se arregló en los 8, no solo en resource, porque
  dejarlo a medias habría sido inconsistente (unos módulos literales, otros no)
  y porque el propio encargo decía "arréglalo en origen". `chunk_text()` ahora
  acepta un `$literal_text` opcional (null = comportamiento anterior, para no
  romper nada si algún día se llama desde otro sitio) que es lo que se guarda;
  `$text` (con encuadre) se sigue usando igual para el RAG, sin tocarlo. Casos
  no triviales: quiz conserva el texto de preguntas/feedback pero sin las
  etiquetas "Pregunta:"/"Feedback:" (solo servían de separador visual para el
  RAG); book/wiki conservan el título del capítulo/página (es contenido real
  del documento, no encuadre nuestro) pero sin el nombre del libro/wiki ni el
  "## "; resource cae a texto del intro cuando no hay ningún fichero con texto
  aprovechable (antes se habría quedado vacío). **Como el hash cambia para
  todos los recursos, hace falta reindexar** (cron nocturno, no urge).
- **Sección 0 quedaba fuera de `resolve_contexto_seccion()`.** Un recurso que
  vive en la sección general del curso salía con "Sección 0", resumen vacío y
  sin vecinas. Se quitó el `continue` que la saltaba; el nombre de cualquier
  sección (actual o vecina) usa ahora SIEMPRE `get_section_name()` en vez del
  marcador fijo "Sección N" — con nombre propio lo respeta, sin él da el
  nombre por defecto del formato del curso (p. ej. "Tema 2"), y la sección 0
  ya tiene vecina siguiente.
- **`extraido_por` de PDF sin versión.** `lib/pdfparser/` se vendorizó sin
  `composer.json` ni ningún fichero de versión — la librería no expone su
  versión en tiempo de ejecución y no quedó registrada la exacta. Decisión de
  Marcos: usar `'smalot/pdfparser (vendorizada 2026-07-14)'` (la fecha del
  commit `b7b08c4`, verificable con `git log`) en vez de inventar un número de
  versión. Nuevo fichero `lib/pdfparser/VERSION` con la misma nota. **Pendiente
  de confirmar la versión exacta** si algún día hace falta (no bloqueante:
  Épica solo necesita saber que la extracción es mecánica y con qué
  herramienta, y eso ya lo dice el string).
- **Opcionales vacíos (`alumno.grupo`, `contexto.resumen`) se omiten** en vez
  de mandarse como `""`, en `build_envelope()`/`resolve_contexto_seccion()`.
- **Limpieza de los encargos 1 y 2**, huérfanos en "pendiente" desde antes de
  v1.19.0 (cuando `dispatch_to_epica()` era un no-op): confirmado en
  `epica_ciclo_adhoc.php` que no hay ningún barrido periódico que los recoja —
  solo se encola la tarea al crear el encargo o desde un paso anterior de la
  propia tarea — así que estaban huérfanos para siempre, no solo lentos. Paso
  de upgrade (`db/upgrade.php`, versión 2026092306) que marca como `fallado`
  (no borra, para no perder el registro). **Primer criterio propuesto (umbral
  de antigüedad) rechazado por Marcos antes de aplicar**: los dos huérfanos se
  crearon el mismo día que se despliega el upgrade, así que un umbral tipo
  "más de 1 día" no los habría tocado nunca; y bajarlo habría confundido un
  `pendiente` legítimo reintentando un 429 (puede llevar horas ahí) con uno
  huérfano. El criterio real que se aplicó: `pendiente` en
  `block_pulso_encargos` SIN ninguna fila en `task_adhoc` (`classname LIKE
  '%epica_ciclo_adhoc'`, `component = 'block_pulso'`) cuyo `customdata`
  decodificado (JSON, no `LIKE` sobre el texto) tenga ese `encargoid` — el
  mismo shape que usan `dispatch_to_epica()` y el propio
  `epica_ciclo_adhoc::execute()` al reencolarse
  (`set_custom_data(['encargoid' => $id])`).
- **No se pudo ejecutar `php -l`**: sin PHP ni red en este entorno (el intento
  de descargar el zip portátil de windows.php.net dio 0 bytes — sin acceso a
  internet). Verificado a mano releyendo cada bloque tocado y con un conteo de
  llaves/paréntesis en `node` contra el `git diff` (balance idéntico al de
  antes de los cambios). **Sin verificar de verdad hasta que alguien con PHP
  local o Moodle real lo confirme.**

## 2026-09-23 — Épica paso 4: panel de estado, galería, aviso (v1.20.0)

Última pieza de la integración con Épica. Antes de esto el usuario encargaba y
solo veía «Encargo guardado»; ahora el panel sondea el estado, muestra la
lámina cuando llega (o el motivo si falla), enseña las últimas infografías del
usuario en el curso, y se manda un aviso de mensajería de Moodle al terminar.
Las reglas que deben persistir están en `CLAUDE.md` → "Integración con Épica —
paso 4"; aquí lo que se decidió sobre la marcha y lo que queda sin verificar.

- **Bug real encontrado al revisar el esquema antes de tocarlo**:
  `db/install.xml` llevaba desde v1.19.0 sin las columnas que
  `db/upgrade.php` añadía por `ALTER TABLE` (`epica_plataforma`, `mock`,
  `verificado`, `avisos`, `titulo`, `tema`, `arquetipo`, `filename`,
  `motivo`, `sobre_json`…) — una instalación NUEVA de Moodle se habría
  quedado con la tabla del paso 1, sin nada del paso 3. Corregido de paso:
  `install.xml` ahora describe el esquema completo (incluida `notified`, la
  columna de este paso), en el mismo orden en que `upgrade.php` las inserta
  (todas antes de `timecreated`/`timemodified`, por los `AFTER` encadenados
  de los `ALTER TABLE`).
- **"Aviso cuando el usuario ya no está mirando" se simplificó a propósito**:
  no hay forma barata de saber desde una tarea de cron sin estado si el panel
  sigue abierto en el navegador de alguien. Se manda el aviso de mensajería
  SIEMPRE al llegar a un estado terminal real (listo/fallado/desconocido,
  nunca ensayo), tenga o no el panel abierto — si lo tiene, es una
  notificación de más en la campana, nunca un aviso emergente que interrumpa.
  Documentado como decisión consciente, no como algo pendiente de arreglar.
- **Gap heredado del paso 3, apuntado como SIGUIENTE PASO por decisión de
  Marcos, no arreglado en este commit**: los mensajes de error diferenciados
  que pedía el encargo (`cuota-agotada` vs `cuota-del-centro` vs
  `material-ilegible` vs genérico) están listos en el cliente
  (`pulsoCreateFailureMessage()`), pero hoy los dos motivos de cuota nunca
  llegan a aparecer en un encargo `fallado` real: `epica_client::
  procesar_error_encargar()` trata cualquier 429 como transitorio y lo
  reencola sin límite de reintentos ni de tiempo total (el corte de 30
  minutos es solo de sondeo en PRIMER PLANO del navegador; la tarea sigue
  sondeando en segundo plano indefinidamente). **Prioridad distinta entre los
  dos, según Marcos**: `cuota-agotada` no urge (cupo propio, reintentar hasta
  que le toque no es grave); `cuota-del-centro` sí, porque el límite es de
  TODO el centro y puede tardar hasta el día siguiente en liberarse — el
  usuario ve su encargo en `pendiente` sin explicación durante horas, con el
  mensaje correcto ya escrito y sin poder llegar a mostrarse. Próxima vez
  que se toque esto: dar por terminado (`fallado`) un `cuota-del-centro` tras
  un tope de reintentos/tiempo, sin tocar el trato que recibe
  `cuota-agotada`.
- **La galería es solo de los encargos DEL PROPIO usuario en el curso**, no
  un listado de todo el curso para el profesorado (el encargo pedía "sus
  últimas infografías", no una vista de clase) — se sirve del mismo
  `api_create_status.php` sin `encargoid`, limitado a 8 filas.
- **El `sobre` del modo de ensayo solo viaja en el JSON a quien tiene
  `viewanalytics`**, aunque el encargo lo haya hecho un alumno: es la señal
  de verificación contra el contrato de Épica pensada para el profesorado
  técnico, no algo que un alumno necesite ver. Ahora mismo hay un encargo
  real en estado `ensayo` (de la sesión anterior) que debería verse en el
  panel en cuanto esto se despliegue — sirve de primera prueba de humo.
- **Verificado**: sintaxis PHP de los 9 ficheros tocados (PHP 8.3 portátil del
  scratchpad), `install.xml` como XML bien formado, sintaxis del bloque JS
  completo de `chat_simple_view.php` con `node --check`, y pruebas de lógica
  aisladas (node) de los helpers puros nuevos (`pulsoCreateStatusLabel`,
  `pulsoCreateStatusPillClass`, `pulsoCreateFailureMessage` — en particular,
  que el mensaje de `cuota-agotada` y el de `cuota-del-centro` no se
  confunden entre sí).
- **Sin verificar, todo bloqueado por lo mismo de siempre (sin Moodle real ni
  secreto de Épica en este entorno)**: la actualización de Moodle crea
  `notified` sin errores; el sondeo real del panel contra un encargo que pasa
  por encolado→trabajando→listo; que la notificación de mensajería llega y
  con el enlace correcto; que un alumno no puede leer el estado ni el PNG de
  un encargo ajeno (403 real, no solo la lógica); y que el sobre de ensayo
  que ya existe en la BD se ve bien formateado en el panel.

## 2026-09-23 — Ciclo con Épica: firmar, encargar, sondear, recoger (v1.19.0)

Tercer paso de la integración (los dos primeros: texto completo v1.17.0, bloque «Crear» +
cupos v1.18.0). Construye el ciclo entero server-side: `classes/epica_client.php` (sobre +
HTTP + reglas de cadencia/errores) y `classes/task/epica_ciclo_adhoc.php` (la tarea, un
paso por ejecución), enchufado en `creation_quota::dispatch_to_epica()` que hasta ahora era
un no-op a propósito. Las reglas que deben persistir están en `CLAUDE.md` → "Integración
con Épica — paso 3"; aquí lo que es propio de esta sesión y lo que queda sin verificar.

**No se ha podido probar contra Épica de verdad — no hay secreto de producción ni acceso a
`local_awkepica` en este entorno.** Todo lo de abajo se verificó con pruebas de lógica pura
(PHP 8.3 portátil del scratchpad, `mbstring` activado a mano — mismo patrón que sesiones
anteriores): 21 comprobaciones sobre los métodos privados de `epica_client` vía
`ReflectionClass` (truncado de material por frontera de párrafo/palabra y nunca a mitad de
una, cadencia de sondeo con los topes de 10s/60s y el paso a cadencia de fondo tras 30
minutos, `retry_after` con y sin valor explícito, `normalize_response` con array y
`stdClass` de entrada). Sintaxis de los 10 ficheros tocados verificada con `php -l`.

**Decisiones tomadas sin que el encargo las precisara, y por qué:**

- **`epica::pedir()` no está documentado en este repo.** `local_awkepica` es una
  dependencia externa (no vendorizada aquí) y no había ni `docs/bruno/` ni
  `resultados.md` disponibles en el entorno de esta sesión para confirmar el shape exacto
  de su valor de retorno. `normalize_response()` se escribió defensivamente (acepta array
  o `stdClass`, body ya decodificado o en bruto) precisamente para no depender de acertar
  ese detalle a la primera. **Cuando llegue el secreto de producción, lo primero que hay
  que verificar en real es esta función.**
- **El cuerpo de `/api/moodle/laminas/encargo` (sondeo) se asume `{token, trabajo}`.** El
  contrato que se nos dio detalla el cuerpo de `/encargar` con precisión pero no el de
  `/encargo`; `trabajo` es el único identificador que devuelve `/encargar`, así que es la
  suposición más razonable, pero **sin verificar**.
- **`alumno.intento`** se interpretó como "cuántas veces ha pedido ya este mismo usuario
  una infografía de este mismo recurso, +1" (cuenta encargos previos por
  `userid`+`cmid`), no como intento de cuestionario — el contrato no lo define y esta
  lectura es la única que tiene sentido con los demás campos de `alumno`.
- **`alumno.grupo`** sale de `groups_get_user_groups()` (grupos de Moodle, primero que
  encuentre); si el alumno no pertenece a ninguno, va vacío. El `contexto` de sección se
  manda siempre precisamente para que un `grupo` vacío no deje a Épica sin nada de dónde
  tirar.
- **Manejo de errores no cubierto explícitamente por la tabla del contrato:** un 401
  `reusado` o un fallo de red se tratan como transitorios (reencolar con backoff, nunca
  más de una vez por los mismos datos gracias a que cada paso firma un token nuevo); 400
  `material-ilegible`/`material-excesivo`/`origen-contradictorio`, 403 y 409 se tratan
  como fallo terminal inmediato (`fallado`, sin reintento) porque no son transitorios ni
  se arreglan solos.
- **El artefacto se guarda en contexto de CURSO**, no de bloque: un encargo no está atado
  a una instancia de bloque concreta (puede haber varias instancias de Pulso en un curso,
  o ninguna visible ya para cuando se recoge la lámina), así que el contexto de curso es
  el único que sobrevive de forma estable. `lib.php`/`block_pulso_pluginfile()` es
  fichero nuevo — el plugin no tenía ninguno hasta ahora.
- **`$plugin->dependencies` con `local_awkepica => 2026090902`** (1.1.0), el número que
  Épica confirmó en `RESPUESTA_A_EPICA_4.md`.

**Pendiente real, todo bloqueado por lo mismo (sin secreto ni entorno de Épica):**
probar el ciclo completo con `epica_dry_run` activado contra un encargo real y comprobar
a mano que el `sobre_json` registrado coincide con el contrato; luego, con el secreto,
repetir sin ensayo y verificar los cuatro estados (`encolado`→`trabajando`→`listo`, y un
`fallado` provocado a propósito) y que el PNG se sirve por `pluginfile.php` con los
permisos correctos (dueño del encargo sí, otro alumno no, profesor del curso sí).

## 2026-09-23 — Bloque «Crear infografía» (Épica paso 1): pantalla + cupos (v1.18.0)

Segundo paso de la integración con Épica (el primero fue guardar el texto completo,
v1.17.0). Este construye solo el lado de Pulse: pantalla de encargo, capability nueva,
cupos anti-abuso y la tabla `block_pulso_encargos`. Todavía no manda nada a Épica —eso es
el paso 4, con el punto de entrada ya marcado y sin cuerpo
(`creation_quota::dispatch_to_epica()`)—. Las reglas que deben persistir están en
`CLAUDE.md` → "Integración con Épica — paso 1"; aquí lo que se decidió sobre la marcha:

- **El cupo de sección se resuelve sin una petición extra.** La instrucción original solo
  decía "cupo antes de escribir", pero el cupo por sección solo se puede evaluar una vez
  elegido el recurso —dentro del formulario—, y validarlo recién al ENVIAR habría sido
  exactamente el "se descubre después de teclear" que se quería evitar. Solución: el GET
  que arma el desplegable ya calcula, por cada recurso, el consumo de hoy de su sección
  (`creation_quota::attach_section_usage()`), y el JS avisa/bloquea al `change` del
  desplegable —antes de que el usuario escriba una sola palabra—, sin ningún viaje de red
  adicional. Se revalida igualmente en servidor al enviar (nunca confiar en un cupo ya
  mostrado ni en el `cmid` del cliente).
- **Tercer motivo de "lista vacía" no contemplado en el encargo**: además de "no indexado"
  y "sin texto aprovechable", puede pasar que TODO lo indexado esté oculto/restringido
  para ese usuario en concreto (`no_visible`). Con un solo recurso indexado y una
  restricción de acceso puesta a un alumno, los otros dos motivos habrían dado un mensaje
  engañoso ("aún no se indexó" cuando sí se indexó). Se añadió sin que lo pidiera el
  encargo porque era un caso real y barato de cubrir.
- **La tabla de encargos lleva `prompt` y `format` desde ya**, no solo lo mínimo para
  contar (`courseid`/`cmid`/`sectionnum`/`userid`/`tool`/`timecreated`/`status`): son
  literalmente el contenido del encargo, y sin ellos el paso 4 no tendría qué enviarle a
  Épica. `epica_job_id` queda reservado (NULL) para entonces.
- **La capability es de escritura (`captype: write`)**, distinta de `usechat`/
  `viewanalytics` (ambas `read`): crea una fila nueva, no solo consulta.
- **No verificado en Moodle todavía** (sin acceso a una instancia en esta sesión): que el
  alumno vea el bloque «Crear» sin ver «Analítica»; que un recurso con restricción de
  acceso puesta a propósito no aparezca en el desplegable de un alumno; que la
  actualización de Moodle cree `block_pulso_encargos` sin errores; y los 5 cupos con
  datos reales (en especial el de curso+hora con ventana móvil, y el de curso+día con el
  cálculo de matriculados). Revisar también, con Moodle real, si `require_sesskey()`
  acepta el sesskey pasado por querystring en el GET de `api_create_form.php` igual que
  lo hace por POST en los demás endpoints (la lectura interna de Moodle no distingue
  GET/POST, pero no se ha podido probar en vivo).

## 2026-09-23 — Texto completo por módulo para Épica: tabla nueva (v1.17.0)

Primer paso de la integración con Épica, y no depende de ella: guardar el texto
ENTERO extraído de cada módulo (para el campo `material.texto` del futuro envío),
además de los fragmentos que ya se guardan para RAG.

**Por qué no se reconstruye concatenando `chunk_text`**: los fragmentos de
`block_pulso_content_chunks` SE SOLAPAN (`CHUNK_OVERLAP=200`), así que unirlos
duplicaría ~200 caracteres en cada unión — inaceptable porque Épica compara el
material contra el original para verificar fidelidad literal. Se captura en su
lugar en `content_extractor::chunk_text()`, que ya recibe el texto completo justo
antes de trocearlo: cero coste de extracción extra.

**`usable` se calcula en el momento de extraer, no al leer.** Un PDF escaneado
deja como texto final el aviso "No se pudo leer el texto de este PDF…", que por sí
solo pasaría la heurística de `is_extracted_text_useful()` (es prosa española
normal). Por eso `extract_resource()`/`extract_scorm()` calculan `usable` sobre el
texto REAL extraído (antes de anteponer el aviso de fallo) y se lo pasan ya
resuelto a `chunk_text()`; el resto de tipos de módulo dejan que `chunk_text()`
aplique la heurística sobre el texto final (no hay riesgo de aviso-como-contenido
fuera de PDF/SCORM).

**`extraido_por`** junta los métodos reales que dieron texto en un recurso con
varios ficheros (`smalot/pdfparser`, `pdftotext X.Y -layout` — versión detectada
con `pdftotext -v`, cacheada por proceso —, `docx:ziparchive`…), no un valor fijo.

Tabla `block_pulso_full_text` (upsert por `courseid`+`cmid` con `content_hash`
para no reescribir sin cambios, mismo patrón que `embedding_manager`) en clase
nueva `classes/full_text_store.php`, enganchada en
`rag_retriever::index_course()`/`delete_course_index()` — corre solo desde las
tareas de cron, nunca en una petición de chat. `full_text_store::get_available_resources($courseid)`
da los cmid con texto aprovechable (`usable=1`, `cmid>0`) para el futuro
desplegable de "enviar a Épica".

## 2026-09-23 — Rebranding visible: Pulso AI → Pulse AI (v1.16.1)

Renombre de marca SOLO en lo que lee el usuario: `pluginname` y demás valores de
`lang/en/block_pulso.php` (las claves `pulso:*` NO se tocan — son el nombre real de
las capabilities), los textos de cabecera/burbuja/capacidades de
`chat_simple_view.php`, y la línea de auto-identificación del prompt de alumno en
`classes/system_prompt_designer.php` ("Eres Pulso AI" → "Eres Pulse AI" — no estaba
en el encargo original pero es lo que el modelo le dice al alumno que es, así que
cuenta como texto visible). El componente `block_pulso`, las capabilities
`block/pulso:*`, la tabla `block_pulso_content_chunks`, el namespace, los
`error_log('Pulso: …')` de servidor y todo el CSS/JS `pulso-*`/`Pulso*` quedan
igual a propósito: cambiarlos rompería ajustes/capabilities/tabla existentes en
Moodle o sería una regresión visual gratuita sin ningún beneficio (nadie los lee).

## 2026-09-07 — Camino A verificado, y el fallo se cuenta al usuario (v1.15.3)

QA de v1.15.2 en el curso 92: **el camino A funciona**. Ofrece en las dos respuestas
`table` (el layout donde era imposible), se calla en los tres datos puntuales, no repite
dos turnos seguidos, los cuatro casos de control pasan y los ofrecimientos son concretos y
anclados en datos reales, sin inventar materiales. La conversación proactiva queda cerrada
en sus dos caminos.

**Pendiente NO perseguido (decisión de Marcos): "Error: No success flag" en una tanda**, en
la pregunta del cuestionario como tercera tras dos de analítica. No reproducible ni con la
misma secuencia ni con contexto limpio → apunta a algo transitorio (fallo de API o carrera
al enviar muy seguido). La causa raíz queda sin investigar **a propósito**. Si reaparece,
lo primero es activar `$CFG->block_pulso_log_raw_answer` y `window.pulsoDebug`, que ya
están en el código apagados.

Lo que sí se arregló es la **degradación**, que era mala con independencia de la causa. La
rama de fallo del cliente colapsaba TRES situaciones y las tres acababan mostrando texto de
desarrollador:

- `success:false` con `message` = `'Error: ' . $e->getMessage()` (los dos endpoints) → se
  pintaba doblemente prefijado: "Error: Error: …", con el texto crudo de la excepción.
- `success:true` con `answer` vacío → el `message` del payload es "Query procesado
  exitosamente", que como texto de error no tiene ningún sentido.
- Una respuesta sin `success` ni `message` → el literal **"No success flag"**, que es lo
  que vio Marcos.

Ahora las tres pasan por `pulsoFailureMessage()`, compartido con el evento `error` del SSE
(que tenía el mismo doble prefijo y un "desconocido" igual de inútil): mensaje en es/en con
qué hacer —reintentar y, si se repite, «Nueva conversación»—, el detalle del servidor solo
cuando es un error de verdad (con `success:true` se suprime el boilerplate) y sin el
prefijo "Error:" duplicado. El detalle técnico se queda en `console.error`.

Dos cosas que hay que mantener si se toca: el turno fallido **no entra en el historial**
(si entrara, se le reenviaría al modelo en la pregunta siguiente), y el mensaje cita el
label real del botón, «Nueva conversación», también en la versión inglesa — la UI es
español-first y decirle "New conversation" mandaría a buscar un botón que no existe.

Verificado con los seis payloads que los endpoints pueden emitir de verdad, ejecutando el
helper real extraído del fichero con node, y en los dos idiomas (`navigator.language` hay
que inyectarlo con `Object.defineProperty` y en procesos separados: Node no deja
reasignar `navigator` dos veces en el mismo proceso).

## 2026-09-07 — El ofrecimiento no salía nunca: campo propio `next_step` (v1.15.2)

QA de v1.15.0 en el curso 92: los 4 casos de control pasan, ninguna regresión, las
sugerencias de las rutas directas funcionan de punta a punta (el caso cuestionario →
temario incluido)… y **0 ofrecimientos en 10 respuestas** por el camino del LLM, incluidas
4 donde la regla aplicaba. La mitad de "cállate" funcionaba perfecta. Diagnóstico entero
desde el código, sin necesitar el log:

- **Le estaba pidiendo al modelo un campo que el propio prompt le prohíbe.** La regla decía
  "la última frase de `content`", y `content` **no existe en el esquema de salida del
  LLM** — es un campo de los payloads de la ruta directa. Con `FORMATO DE SALIDA`
  exigiendo "únicamente el objeto JSON del schema", el modelo no tenía dónde ponerlo.
  Explica el 0/10 y explica por qué la mitad de "cállate" sí funcionaba: esa no necesita
  ningún campo.
- **Y lo introduje al comprimir la regla.** La versión de 750 tokens decía "última frase de
  `content` *(o del último párrafo de `data` si el cuerpo va ahí)*". Al bajar a 282 quité
  el paréntesis, que era la única referencia a un campo que sí existe. Lección: al
  comprimir un prompt, lo que parece redundancia puede ser la única vía viable — comprobar
  contra el esquema, no contra la lectura.
- **Segundo fallo apilado, independiente del prompt:** aunque el modelo lo hubiera
  emitido, `formatAIResponse()` solo pinta `content` si `type === 'text'`, e
  `insights`/`recommendations` solo si `isAnalyticsQuestion(message)`. En las dos
  respuestas de analítica del QA (`type: table`) `content` era invisible por diseño; en
  las dos de contenido lo eran las recomendaciones. **No había ningún campo del esquema
  que se pintara en los cuatro layouts.** Por eso el arreglo no es renombrar un campo: es
  darle uno propio y pintarlo al margen de `type` y de `isAnalyticsQuestion`.
- **Consecuencia que había que arreglar de paso:** `next_step` entra en el digest del
  historial (PHP **y** JS, misma posición) porque la regla de "no repitas el ofrecimiento
  dos turnos seguidos" necesita ver el del turno anterior — y como el esquema no tiene
  `content`, el digest de una respuesta del modelo era solo título + resumen. Sin esto, el
  primer efecto visible del arreglo habrían sido ofrecimientos repetidos.
- **Coste:** la regla pasa de ~282 a ~420 tokens (~$0,84 por mil mensajes). Los ~140 de
  más son el nombre del campo, la instrucción de omitirlo cuando no toca, el "nada fuera
  del objeto JSON", un ejemplo concreto de `next_step` y la autorización en
  `FORMATO DE SALIDA`. Se recortó la redundancia dos veces antes de cerrar (de 407 a 374
  en la sección).
- **La instrumentación se queda en el código, apagada**: `$CFG->block_pulso_log_raw_answer`
  (servidor, respuesta cruda antes de `clean_answer()` — `error_log` y no `debugging()`,
  que en el endpoint SSE rompería el stream) y `window.pulsoDebug` (cliente, JSON final +
  qué secciones se van a pintar). Sirven para la próxima.
- **Verificado**: 215 comprobaciones, 0 fallos, incluida la **paridad del digest de PHP con
  el del cliente ejecutando el JS real con node** (invariante de v1.14.0) y que el texto de
  los dos prompts base sigue intacto contra `git HEAD`. v1.15.1 nunca llegó al servidor:
  hay que desplegar **1.15.2**, que lo contiene todo.

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
