# Pulse AI (block_pulso)

Bloque de Moodle con el asistente de curso **Pulse AI**: chat con IA sobre el curso (analítica para el profesorado, contenido para
el alumnado) y una pestaña **Crear** con infografías, juegos, retos y ampliación de recursos.

- **Preguntar**: chat con respuestas de Claude (Anthropic), con datos de analítica del curso (solo profesorado) y con el contenido
  indexado del curso (RAG). El alumnado solo accede a contenido, nunca a datos del grupo.
- **Crear**: infografías, juegos y retos (generados por el servicio externo Épica, opcional), y «Ampliar recurso» (vídeos y artículos
  relacionados). «Mis creaciones» reúne lo creado por cada persona en el curso.

## Requisitos

- Moodle 4.1 o superior (`$plugin->requires = 2022111800`) y PHP compatible con esa versión.
- Una clave de Anthropic para el chat. Opcionales: clave de OpenAI (embeddings del RAG), claves de YouTube y OpenAlex (Ampliar recurso).
- **`local_awkepica` es opcional.** Sin él, Pulse funciona igual; solo se ocultan infografías, juegos, retos y «Mi historial en Épica».

## Instalación

1. Copiar la carpeta del plugin a `blocks/pulso` (el nombre de carpeta debe ser `pulso`).
2. Administración del sitio → Notificaciones, para instalar o actualizar.
3. Purgar cachés (ver «Despliegue»).
4. Configurar en Administración del sitio → Plugins → Bloques → Pulse AI y añadir el bloque a los cursos.

## Ajustes principales

Todos en la página de ajustes del bloque; las claves se guardan en la configuración de Moodle y no se muestran en ningún diagnóstico.

- Clave de Anthropic y modelo del chat; clave de OpenAI (solo embeddings); activación del RAG; acceso a datos (notas, finalización, registros).
- Activación por defecto en los cursos y topes de uso (mensajes por minuto y día, cupos de creación, límites de Ampliación y Retos).
- Épica (opcional): URL base (`https://`) y modo de ensayo.
- **Diagnóstico**: `diagnostico.php` (solo administración, enlazado desde los ajustes) comprueba Anthropic, OpenAI, YouTube, OpenAlex, Épica,
  el cron y la indexación.

## Desarrollo del JavaScript (AMD)

El JavaScript vive en `amd/src/*.js` (módulos AMD de Moodle). **Moodle sirve `amd/build/`, no `amd/src/`** (salvo con `cachejs` apagado),
así que el `build` se commitea y hay que regenerarlo en cada cambio de JS.

### Opción A: script del repo (no necesita Moodle)

```
cd tools
npm install
npm run build                # genera amd/build/*.min.js y .map con terser
node build-amd.mjs --check   # sale con error si amd/build no coincide con amd/src
```

El script nombra cada `define()` como `block_pulso/<módulo>`: Moodle une varios módulos en una sola respuesta (`lib/requirejs.php`) y para
eso el `define` tiene que llevar nombre.

### Opción B: grunt (dentro de un checkout de Moodle)

Copia o enlaza el plugin en `blocks/pulso` de un Moodle ≥ 4.1 con `npm install` hecho y ejecuta, desde la raíz de Moodle:

```
npx grunt amd --root=blocks/pulso
```

Genera el mismo `amd/build/` (babel + terser); el resultado de las dos opciones es equivalente pero no idéntico byte a byte.

## Despliegue

Cada cambio de código sube `$plugin->version` y `$plugin->release` en `version.php`. Al desplegar:

- **Purgar cachés** (Administración del sitio → Desarrollo → Purgar cachés) si cambian plantillas (`templates/`), `styles.css` o `amd/build/`:
  Moodle cachea plantillas, CSS del tema y módulos AMD por revisión.
- **Notificaciones** solo si cambia `db/` (esquema, capabilities, cachés, tareas, mensajes).

## Terceros

Declarados en `thirdpartylibs.xml`:

- **Poppins** (OFL-1.1), en `fonts/`, servida desde el plugin (no se carga nada de Google Fonts).
- **smalot/pdfparser** (LGPL-3.0), en `lib/pdfparser/`, para extraer texto de PDF.

Excepción: las miniaturas de vídeo de «Ampliar recurso» se cargan de `i.ytimg.com` solo cuando la persona pide una ampliación.

## Más documentación

Reglas de arquitectura y decisiones: `CLAUDE.md`. Historial de lo que costó cada paso: `memory/session-history.md`.
