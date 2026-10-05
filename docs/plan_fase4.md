# Fase 4 — Rediseño de Pulse AI y arquitectura cacheable (plan)

Estado: **plan, sin código tocado.** Maqueta: `docs/maqueta_v2.html` (ábrela en el navegador; el primer marco es interactivo).

## 0. Lo que he medido

| Dato | Valor |
|---|---|
| `chat_simple_view.php` | 7.087 líneas, 335 KB |
| CSS en línea | ≈ 72 KB (líneas 98–2575) |
| JS en línea | ≈ 222 KB (líneas 2822–7023), ~150 funciones y ~40 variables de módulo en ámbito global |
| Manejadores `onclick`/`oninput`/… en el HTML | 52, de ~25 funciones distintas; más los que genera el JS en cadenas |
| Terceros en cada página de curso | `fonts.googleapis.com` (`@import`, bloquea el render y envía la IP) y 4 usos de `media.awakelab.world` (isotipo) |
| `juego.php` | `@import` de Google Fonts (línea 87). **`epica_historial.php` no importa fuentes** (usa `system-ui`): el punto 9 solo afecta a `juego.php` |
| Pesos de Poppins usados | 400 (2), 500 (12), 600 (39). No se usa 700: bastan los tres woff2 que pides |
| Entorno de build | No hay Moodle ni `node_modules` en el repo; sí Node 24 en esta máquina. No hay `amd/`, `fonts/`, `pix/`, `styles.css` ni `thirdpartylibs.xml` |
| Galería actual | `api_create_status.php` devuelve solo 8; `retos_service::MIS_RETOS_MAX` limita retos |

## 1. Decisiones de diseño (las que más pesan; recomiendo lo marcado)

1. **Pestañas, no panel superpuesto.** Dos `role="tab"` en la cabecera. Cada pestaña es un `role="tabpanel"` hermano; el chat conserva DOM y conversación al cambiar. El cuadro de texto vive **solo** en Preguntar (en Crear no tiene sentido y evita que se envíe un mensaje con Crear a medias). Flechas ←/→, Inicio y Fin cambian de pestaña (patrón ARIA «tabs con activación automática»).
2. **Cambiar de pestaña pausa el sondeo, no lo cancela** *(recomendado)*. Hoy `closeCreatePanel()` incrementa `pulsoAmpToken` y mata todo. Con pestañas eso perdería el estado de una infografía en curso o de unos retos `en-cola` (y la regla «Retos no se corta en cola» sigue en pie). Se reutiliza el mecanismo de `pulsoOnVisibilityChange()` (guardar el sondeo pendiente y retomarlo al volver). El `pulsoAmpToken` solo se invalida al **cambiar de pantalla dentro de Crear** o cerrar el chat.
3. **La pila de Crear** (`root → form → status`, y `root → mine`) es un array de pantallas con «Volver»; reemplaza a `openCreatePanel/closeCreatePanel` y a la clase `pulso-showing-create`. Cada pantalla se pinta con `innerHTML` en `#pulso-crear-body` (misma regla de foco: título `h3` con `tabindex=-1`, `MutationObserver` solo sobre hijos directos).
4. **Escape sigue cerrando el chat** (como ahora); «Volver» es un botón, no una tecla, para no tener dos significados de Escape.
5. **«Ampliar recurso» no es una creación** (no se guarda, es una búsqueda cacheada por recurso). Va en la lista de herramientas, pero **no entra en «Mis creaciones»** (que son infografías, juegos y retos, como pides).
6. **Home de Preguntar:** 4 sugerencias destacadas por rol + «Ver más ideas» (`aria-expanded`, desplegable con el resto agrupado) + «¿Qué puede hacer Pulse?».
   - Profesorado: Panorama del curso, Alumnos en riesgo, Notas medias, Estado de entregas. Al desplegar: Analítica (5) y Contenido del curso (5).
   - Alumnado: Resumen del curso, Explícame un tema, Repaso rápido, Materiales de estudio. Al desplegar: Contenido (4).
   - Las preguntas siguen siendo las **mismas frases completas** de hoy (regla de la home / backlog #2). No se inventa ninguna nueva.
7. **Mis creaciones:** pantalla propia con filtros por tipo (`aria-pressed`), recuento anunciado por `role=status`, y «Mi historial en Épica ↗» para el alumnado (sale de la home y del pie del panel). Es la única galería: se retira la de debajo de cada formulario. Requiere **un cambio de servidor**: `api_create_status.php` pasa de 8 a una página mayor (propuesta 24, con `tool` opcional) y `MIS_RETOS_MAX` se alinea. Se cachea en cliente y se refresca al entrar y al llegar un estado terminal (se mantiene la regla «la galería NO se pide en cada sondeo»).
8. **Marcadores de rol.** Para el HTML estático nuevo (cabecera, pestañas, home, lista de herramientas) propongo **plantilla Mustache** (`templates/chat.mustache`) con `{{#isteacher}}`/`{{#cancreate}}`/`{{#epica}}`: el HTML que no toca **sigue sin llegar al navegador**, y es lo idiomático en Moodle. Hasta ese paso (4) se mantienen los marcadores `<!--PULSO_…-->` tal cual para no mezclar riesgos. Si prefieres no introducir Mustache, se queda con marcadores: funciona igual.
9. **Terminología:** «Pulse AI» (cabecera, `lang`) y «Pulse» (textos); «creación» (no «encargo»); «infografía» (no «lámina»); «Épica» solo en «Mi historial en Épica ↗» y en el aviso de que el historial anterior al 5-oct está allí. **No se renombran** identificadores internos (`block_pulso_encargos`, `encargoid`, `pulso-`…): solo texto visible. Hoy hay 94 apariciones de «encargo» y 5 de «lámina» en el fichero, y aún queda «Pulso» en el CSS/comentarios y en `lang`; se revisa también `lang/{en,es}`, `notify_completion()` y `juego.php`.
10. **Tamaño:** «Ampliar» pasa a un único estado ancho (≈ `min(960px, 90vw)` × casi toda la altura) que reutiliza `toggleChatSize()`; en ≤ 480 px pantalla completa y sin botón (ya hecho). **La redefinición de `.drawer-collapsed` debe seguir a la par** (regla de QA 1.27.2).

## 2. Arquitectura cacheable (decisiones técnicas)

- **`styles.css` del bloque.** Moodle lo mete en el CSS del tema, así que se cachea y se sirve comprimido, **pero se carga en todas las páginas del sitio**, no solo en las de curso. Mitigación: todo con prefijo `.pulso-`/`#pulso-`, sin selectores de elemento globales, y minificado en la entrega (≈ 15 KB comprimido). Al desplegar hay que **purgar cachés** (el CSS no cambia hasta que cambie la revisión del tema).
- **Fuentes:** `fonts/poppins-{400,500,600}.woff2` (subconjunto latino, ≈ 8–10 KB cada uno) y `@font-face` con `font-display: swap` y `src: url([[font:block_pulso|poppins-400.woff2]])` (Moodle lo resuelve a `font.php`). Poppins es OFL: se añade `fonts/OFL.txt` y un `thirdpartylibs.xml` (hoy no existe; también debería listar `lib/pdfparser`). Sin `@import`, sin `<link>` a terceros.
- **Logos:** copiar el isotipo (oscuro y claro) a `pix/` y referenciar con `[[pix:block_pulso|isotipo-oscuro]]` en CSS y `$OUTPUT->image_url()` en HTML. Reescalar (hoy se pintan a 16–30 px: PNG de 96 px de lado y @2x bastan). El logo completo no se usa hoy; se copia solo si la nueva cabecera lo pide.
- **JS → AMD.** `amd/src/chat.js` + `amd/build/chat.min.js` (+ `.map`). Inline solo: `$PAGE->requires->js_call_amd('block_pulso/chat', 'init', [$cfg])` con `courseid`, URLs de los 6 endpoints, `sesskey`, `isTeacher`, `epicaAvailable`, `canCreate`, versión. **Sin `window.*`**: todo el estado pasa a variables del módulo. **Sin `onclick`**: listeners delegados con `data-action="…"` en el contenedor (también para las cadenas HTML que genera el JS). El `conversationHistory` deja de ser global.
- **Build.** El camino «oficial» es `grunt amd --root=blocks/pulso` dentro de un checkout de Moodle 4.1+ (babel + terser). Como aquí no hay Moodle, **dejo un script** `tools/build-amd.mjs` (terser, sin dependencias de Moodle) y documentado en `README.md` cómo regenerar con grunt. Es la parte con más riesgo operativo: el `build/` hay que **commitear** (Moodle no compila en el servidor) y verificar en un entorno con grunt antes de 2.0.0 (los CI de plugins comparan `build/` con `src/`).
- **Trocear el módulo.** Un único `chat.js` de ~4.000 líneas es mantenible solo en apariencia. Propongo partir en el paso 2 por costuras que ya existen y no comparten estado: `format.js` (formateo de respuesta, tablas, exportar CSV/Excel: funciones puras, ~1.200 líneas), `chat.js` (envío, streaming, historial, errores), y en los pasos 6–7 `crear.js` (pila, formularios, estado, galería), `retos.js` y `ampliacion.js`. Si prefieres no trocear, el paso 2 sale más corto pero el fichero sigue siendo un monolito.
- **RGPD / Lighthouse.** Tras esto, una página de curso no hace ninguna petición a terceros. **Excepción honesta:** las miniaturas de vídeo de Ampliación (`i.ytimg.com`) se cargan solo cuando el usuario pide una ampliación (ya con `referrerpolicy=no-referrer`); no afecta a la carga de la página, pero conviene nombrarlo en la ficha RGPD.

## 3. Pasos (cada uno en sesión nueva, con su bump y su commit)

| # | Versión | Qué | Riesgo | Cómo se verifica |
|---|---|---|---|---|
| 1 | 1.33.0 | CSS → `styles.css`; `fonts/` + `@font-face`; logos a `pix/`; `juego.php` sin Google Fonts; `thirdpartylibs.xml`. **Sin cambios visuales.** | Bajo-medio: los `[[font:]]`/`[[pix:]]` solo existen en `styles.css`; la caché del tema | Capturas antes/después; DevTools sin dominios externos; purgar cachés |
| 2 | 1.34.0 | JS → AMD (`chat.js`, `format.js`) + `js_call_amd` + `data-action` delegado; sin `window.*`; script de build | **Alto**: es el paso mecánico más grande | Batería jsdom (adaptada a cargar el módulo) con los mismos ~60 casos de fase 3 + recorrido manual de las 4 herramientas |
| 3 | 1.35.0 | Terminología única (cliente, `lang`, `notify_completion`, `juego.php`) | Bajo | `grep` de «encargo»/«lámina»/«Pulso» en texto visible = 0 |
| 4 | 1.36.0 | **Cabecera con pestañas** + shell nuevo (Mustache o marcadores) + estado de vista; composer solo en Preguntar; pausa de sondeos al cambiar de pestaña | Medio | Teclado (flechas, Inicio/Fin, Tab, Escape), foco, lector, sondeo de una infografía y de Retos en cola cruzando pestañas |
| 5 | 1.37.0 | Home corta por rol + «Ver más ideas» | Bajo | Rol profesor y alumno; el HTML de alumno **no contiene** las tarjetas de analítica |
| 6 | 1.38.0 | Pestaña Crear: lista de herramientas + pila con «Volver»; el formulario/estado/Retos/Ampliación pasan a la pila | Medio-alto (toca los cuatro flujos y las reglas de foco/tokens) | Las 4 herramientas de punta a punta (dry-run incluido) |
| 7 | 1.39.0 | **Mis creaciones** + filtros; cambio de servidor (límite/`tool`); se retira la galería de bajo cada formulario; «Mi historial en Épica» y su aviso aquí | Medio | Alumno con creaciones de las 3 clases; sin Épica no sale ni Retos ni historial |
| 8 | 1.40.0 | Errores con «Reintentar» + «Copiar pregunta» (`navigator.clipboard` con *fallback* y aviso por `pulsoAnnounce`); «Ampliar» ancho; separador de recarga ya existente | Bajo | Simular `busy`, `network`, `empty`; copiar en HTTP/HTTPS |
| 9 | **2.0.0** | Reescribir `CLAUDE.md` (secciones obsoletas), `memory/`, README de build; verificación final | — | Lighthouse + recorrido de teclado/lector de la fase 3 |

Orden pensado para que **los pasos 1–3 no cambien comportamiento** (cualquier regresión es del refactor, no del diseño) y los 4–8 se prueben sobre una base ya cacheable.

## 4. Reglas de CLAUDE.md que toca cada paso

Leyenda: **R** = se reescribe (queda obsoleta tal como está) · **M** = se mantiene y hay que respetarla · **A** = se adapta.

**Paso 1 (CSS/fuentes/logos)**
- R «Architecture · `chat_simple_view.php` — … inline HTML/CSS/JS … Poppins via Google Fonts, isotipo desde media.awakelab.world».
- M Tema claro (paleta, cian no es texto, ningún texto sobre cian, botones de acción `#003670`), «Texto ≥ 0.75rem», botones desactivados `#72A3C4`+`#27334F`.
- A «`.drawer-collapsed` redefine height/max-height/min-height…»: viaja a `styles.css` con el mismo cálculo.
- A «Versioning rule»: el badge sigue leyendo `version.php`, pero llegará por `cfg` (paso 2).

**Paso 2 (AMD)**
- R «Dev notes: el blob JS vive en un nowdoc; `${…}` es seguro; `JSINIT` sí interpola».
- R «El cliente manda el token desde `window.pulsoSesskey` en `buildChatFormData()`»: pasa a `cfg.sesskey` (y `M.cfg.sesskey` de respaldo). Mantener: **todo endpoint nuevo recibe el token por ahí.**
- R Estado global `window.courseid`, `window.pulsoIsTeacher`, `window.pulsoEpicaAvailable`, `window.conversationHistory`, `window.pulsoDebug`.
- M Escape de HTML **una sola vez** (`formatRichTextResponse` y helpers), `pulsoEscapeAttr` en todo atributo, `pulsoRetosMd`, URLs solo `https://`, enlaces por índice y no por id.
- M Digest del historial idéntico en servidor (`history_digest`) y cliente (`pulsoHistoryDigest`), con saltos de línea y sin filas de `data` (invariantes v1.12/v1.14).
- M Sondeos con token (`pulsoAmpToken`) y por los helpers `schedule…` (nunca `setTimeout` suelto).

**Paso 3 (terminología)**
- M «Todo el texto del cliente va en castellano» y `lang/es` obligatorio con las mismas claves que `lang/en`.
- A Textos citados en reglas («Encargo guardado», «Crear un encargo nuevo», «lámina es de prueba»): se actualizan junto con el texto.
- M El 429 `cuota-del-centro` conserva su mensaje distinto («no es nada que hayas hecho tú»).

**Paso 4 (pestañas/shell)**
- R «Es una pantalla, no un mensaje de chat. `#pulso-create-panel` es hermano de `#pulso-home` dentro de `#pulso-scroll`… clase `pulso-showing-create`».
- R «Enviar desde el chat con Crear abierto cierra Crear antes»: ya no puede pasar (composer solo en Preguntar).
- A «La región en vivo es SOLO para mensajes»: `#pulso-messages` sigue siendo la única región en vivo; los paneles de pestaña no lo son. `pulsoAnnounce()` y `#pulso-live-status` se mantienen.
- A «Los sondeos se pausan con la pestaña oculta»: se amplía a «pestaña de Pulse distinta de Crear».
- A Teclado: se añade el patrón de `tablist`; Escape y el foco a la burbuja se mantienen; `aria-expanded`/`aria-controls` de la burbuja.
- M La UI no es control de acceso: **los marcadores/secciones de rol, de `createactivity` y de Épica siguen quitando HTML del servidor**, no ocultándolo con CSS.
- M «Insignia de versión solo con `viewanalytics`».

**Paso 5 (home)**
- M «Las preguntas de la home son frases completas que entran por el pipeline» (backlog #2) y `askPreset()`.
- M Modo alumno: el HTML del alumno no lleva tarjetas de analítica; el texto de capacidades del otro rol viaja en el JS como copy estático (aceptado).
- A El saludo y `showCapabilities()` (hardcodeado) se mantienen; solo cambia dónde se pinta.

**Paso 6 (pestaña Crear / pila)**
- R «Mismo panel y mismo desplegable… `pulsoCreateTool`» (Ampliación paso 2, Retos paso 2): el panel único desaparece; el formulario sigue siendo uno solo parametrizado por herramienta.
- R La «rejilla de 2 columnas / 2×2 de CTA» (Ampliación y Retos): sustituida por lista.
- A «Foco al título en cada pantalla de Crear» y «Una pantalla nueva de Crear = repintar `#…body` con `innerHTML`»: se mantiene con el nuevo contenedor.
- M **Retos:** sin corte en cola, «Seguir esperando», antidoble clic (`pulsoRetosSetBusy`), elegir por índice, errores por `error` y no por texto, título final con reintentos, sin iframes, pestaña nueva con `rel=noopener`.
- M **Infografías/juegos:** `pollCreateStatusOnce` con token, `renderCreateStatus` repinta solo si cambia la clave de pantalla, `pulsoCreateFailureMessage`, el juego se juega **fuera** del widget (`playurl`, `target=_blank`).
- M Ampliación: sin iframe de YouTube, todo escapado, peticiones en vuelo invalidadas con token.
- M Cupos (`sectionused`/`sectionlimit`, aviso al cambiar el recurso), revalidados en servidor.
- M Ejemplos de juego («Prueba con:»): textos literales de la carta 9, un clic rellena y no envía.
- M `closeCreatePanel`: al volver, el foco regresa al elemento que abrió (`pulsoCreateOpener`) — se traslada a «Volver».

**Paso 7 (Mis creaciones)**
- R «La galería… se enseña siempre debajo del formulario… SOLO de los encargos propios» → pantalla propia; **se mantiene lo de «solo propios del usuario en el curso»**, el filtro del servidor `status='listo' AND filename<>''`, y que `playurl` va sin `imageurl` para juegos.
- A «La galería NO se pide en cada sondeo» / `pulsoGalleryCache` / `pulsoGallerySeq`: se conserva para Mis creaciones.
- A «Historial del alumno en Épica (carta 10)»: el botón se mueve de la home a Crear; sigue **solo** con `pulsoIsTeacher === false`, dentro de los marcadores de alumno y de Épica; token nuevo por POST, nunca en URL; pestaña nueva abierta en el clic; el aviso de «anterior al 05-10-2026» pasa al pie de Mis creaciones.
- M Sin Épica: no hay infografía, juego, retos ni historial; `mis_retos` no se pide.
- A Ampliación: «Sin galería y sin historial» se mantiene (no entra en Mis creaciones).

**Paso 8 (errores / tamaño)**
- A «Errores con reintento»: `addErrorMessage` + `PULSO_RETRYABLE`; se añade «Copiar pregunta». «Reintentar» sigue usando `dispatchMessage(message)` sin repintar la burbuja del usuario.
- M El texto de error sale por `error_code` (`PULSO_ERROR_TEXTS`); nunca texto técnico del servidor.
- M Móvil ≤ 480 px = pantalla completa con `!important` (estilos en línea de `toggleChat()`/arrastre); `min-width: min(300px, 100vw − 32px)`.
- M Historial en `sessionStorage` pintado con separador y `escapeHtmlText`.

**Paso 9 (2.0.0)**
- Reescribir en `CLAUDE.md` las secciones R de arriba, la de «Dev notes», y la de «Architecture».
- Dejar en `memory/session-history.md` lo que costó (qué se rompió al partir el módulo, cómo se genera `build/`).

## 5. Qué NO se toca

Servidor de chat, pipeline, RAG, topes, `epica_client`/ciclo adhoc, endpoints de Crear (salvo el límite de la galería del paso 7), `juego_html.php` y su CSP/`sandbox`, `block_pulso_pluginfile()`.

## 6. Verificación final (paso 9)

- **Lighthouse** sobre la página del curso (móvil y escritorio): sin recursos que bloqueen el renderizado procedentes del bloque; 0 peticiones a dominios de terceros al cargar; transferencia del bloque (CSS + AMD) reducida de ~295 KB en línea a recursos cacheables.
- Mismo **recorrido de teclado y lector de pantalla** de la fase 3 (NVDA), más las pestañas.
- Las **4 herramientas de Crear**, chat de profesorado, chat de alumnado y «Mi historial» funcionan igual que antes.
- Lo que **no** puedo probar aquí (sin Moodle ni navegador): contraste real, foco visible, 320 px y zoom 200 %, grunt y la carga del `build/`, `font.php`/`[[pix:]]`. Quedará pendiente en sanase-test, como en las fases 2 y 3.

## 7. Qué necesito de ti antes del paso 1

1. ¿OK a las decisiones 2 (pausar, no cancelar), 5 (Ampliar fuera de «Mis creaciones») y 8 (Mustache desde el paso 4)?
2. ¿Trocear el módulo AMD (recomendado) o un único `chat.js`?
3. Los ficheros de **Poppins woff2** (OFL) y los **isotipos** a incluir: ¿los descargo de la fuente oficial (Google Fonts / fontsource) y de `media.awakelab.world` durante el paso 1? Son descargas externas puntuales.
4. ¿Hay un Moodle de desarrollo con Node/grunt donde generar `amd/build` para validar el script de `tools/build-amd.mjs`?
