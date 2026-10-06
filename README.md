# Pulse AI (block_pulso)

Bloque de Moodle con el asistente de curso Pulse AI (chat con IA, creación de infografías, juegos y retos, ampliación de recursos).

## Desarrollo del JavaScript (AMD)

El JavaScript del chat vive en `amd/src/*.js` (módulos AMD de Moodle). **Moodle sirve `amd/build/`, no `amd/src/`**
(salvo con `cachejs` apagado), así que el `build` se commitea y hay que regenerarlo en cada cambio de JS.

### Opción A: script del repo (no necesita Moodle)

```
cd tools
npm install
npm run build        # genera amd/build/*.min.js y .map con terser
node build-amd.mjs --check   # sale con error si amd/build no coincide con amd/src
```

El script nombra cada `define()` como `block_pulso/<módulo>`: Moodle une varios módulos en una sola respuesta
(`lib/requirejs.php`) y para eso el `define` tiene que llevar nombre.

### Opción B: grunt (dentro de un checkout de Moodle)

Copia o enlaza el plugin en `blocks/pulso` de un Moodle ≥ 4.1 con `npm install` hecho y ejecuta, desde la raíz de Moodle:

```
npx grunt amd --root=blocks/pulso
```

Genera el mismo `amd/build/` (babel + terser). Úsalo si la integración continua del proyecto comprueba que
`build` coincide con `src` con la herramienta oficial; el resultado de las dos opciones es equivalente pero no
idéntico byte a byte.

### Despliegue

Tras desplegar CSS o JS nuevos: **Administración del sitio → Desarrollo → Purgar cachés** (el CSS del tema y los módulos
AMD se cachean por revisión). Si cambia `db/`, pasar además por Notificaciones.
