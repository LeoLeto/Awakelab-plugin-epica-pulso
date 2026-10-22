# Épica · carta 18 a Pulso AI · la Factoría empezada en Pulse

**Para Marcos (Pulso AI).**

Contacto Épica: Chema · jose.piquero@awakelab.dev
Fecha: 22-10-2026

> **En tres líneas:** os proponemos que el profesor pueda **crear una presentación de la Factoría desde
> Pulse**. Elige el tema en el chat, Pulse nos deja el contenido por **una puerta nueva**,
> `factoria/borrador`, y abre Épica por el puente de siempre. La Factoría arranca **en el paso del
> estilo**, con el contenido ya dentro.
>
> **Está programada y probada en nuestro entorno, y todavía no en el QA**: antes queremos saber si el
> sobre os vale. Os pedimos eso y tres decisiones más (§8).

---

## 1. El recorrido

La Factoría son seis pasos: **contenido**, estilo, color, secciones, medios y generar. El primero —de
dónde sale lo que va a contar la presentación— es el que mejor se hace en el chat, porque ahí está la
conversación con el profesor y el material del curso. Así que ese paso pasa a ser vuestro, y los otros
cinco siguen en Épica:

```
Pulse (chat)                                    Épica
────────────                                    ─────
el profesor elige el tema
POST /api/moodle/factoria/borrador   ───────►   guarda el borrador 24 horas
          ◄──────────────────────────  201      { "borrador": "<32 caracteres>" }
botón «Abrir en la Factoría»:
formulario a /api/auth/moodle        ───────►   entra con su cuenta, como desde Fuentes
  herramienta=factoria, borrador=…              y abre la Factoría en el paso del estilo
```

Es el mismo puente que el enlace de Fuentes (carta 17, §4): **solo para el profesorado**, con
`sesskey` y un token nuevo en cada petición. Lo único nuevo es la puerta del borrador y un campo más en
el formulario.

## 2. La puerta: `POST /api/moodle/factoria/borrador`

El sobre de siempre: el token en el cuerpo, JSON, y la plataforma del `iss`. Comprueba que el centro
tenga contratada la **Factoría**; si no, es el 409 `herramienta-no-contratada` de siempre. **No crea
ninguna cuenta y no llama a ningún modelo**: guarda el borrador y contesta en milisegundos.

| Campo | Tipo | |
|---|---|---|
| `token` | texto | Obligatorio. **Rol `docente`**, firmado con `block/pulso:viewanalytics` y el `curso` en el cuarto parámetro |
| `contenido` | texto | El contenido ya escrito, en markdown o en texto con sus titulares. Hasta 200.000 caracteres |
| `tema` | texto | O el tema, de 10 a 4.000 caracteres, para que el contenido lo escribamos nosotros |
| `titulo` | texto | Opcional, hasta 200. El título de la presentación cuando el contenido no empieza por `# Título` |
| `diapositivas` | número | Opcional, de 3 a 30 |

**Exactamente uno de `contenido` y `tema`** (en §4 os contamos cuál os recomendamos). La respuesta es
un **201**:

```json
{
  "plataforma": { "codigo": "sanase-test", "nombre": "…" },
  "borrador": "34svzwBkMNJV5Wqfcs5obVALWbTV4mqO",
  "forma": "contenido",
  "caduca": "2026-10-23T08:45:23.039Z"
}
```

**Los fallos**, además de los del token con su traza:

| HTTP | `motivo` | Cuándo |
|---|---|---|
| 403 | `rol-sin-permiso` | El token declara `estudiante` |
| 400 | `encargo-ambiguo` | Vienen `contenido` y `tema` a la vez |
| 400 | `cuerpo-incompleto` | No viene ninguno de los dos, o el tema tiene menos de 10 caracteres |
| 400 | `material-excesivo` | El contenido pasa de 200.000 caracteres, o el tema de 4.000 |
| 429 | `cuota-agotada` | Más de 10 borradores de la misma persona en 10 minutos. Con `Retry-After` |

Como en las demás puertas, estos cinco van **sin traza**: el token estaba bien y lo que hay que revisar
es el cuerpo. El `error` de cada uno es una frase que se le puede enseñar al profesor.

## 3. La entrada: un campo más en el formulario

El formulario que ya mandáis para Fuentes, con estos dos campos:

| Campo | |
|---|---|
| `herramienta` | `factoria` |
| `borrador` | El código que devolvió la puerta |

- **Si todo va bien**, el profesor llega a la Factoría **en el paso del estilo**, con un aviso arriba:
  «El contenido llega de Pulse».
- **Si el borrador ya no vale** —han pasado más de 24 horas, o lo creó otra persona—, el profesor
  **entra igual** y la Factoría se lo dice, con las tres formas de empezar de siempre. El borrador
  nunca impide entrar.
- **Un token de estudiante no entra**, como en Fuentes: 403 `rol-sin-permiso`.
- **Si llamáis a la entrada pidiendo JSON** —es lo que hace la colección de Bruno—, en vez del 303 la
  respuesta trae `"borrador": { "reclamado": true }`, o `false` si no se pudo recoger.

**El código del borrador no basta para abrirlo.** Solo lo puede recoger **la misma persona que lo
creó**: el mismo `sub` y el mismo centro. Y una vez dentro, solo lo lee su cuenta. Si el código se
filtra, sin la sesión de ese profesor no sirve para nada.

## 4. ¿Contenido o tema?

Las dos formas acaban en el mismo sitio, pero no salen igual:

- **Con `contenido`**, Épica **respeta el texto tal cual**: lo parte en secciones por sus titulares y
  maqueta eso, sin reescribirlo, igual que si el profesor hubiera soltado un documento. **Es la que os
  recomendamos cuando la presentación tiene que salir del material del curso**, porque el material lo
  tenéis vosotros en la conversación.
- **Con `tema`**, el contenido lo escribe nuestro modelo al abrirse la Factoría, en unos treinta
  segundos y con el profesor delante. **Pero no ve el material del curso**: escribe sobre el tema con lo
  que sabe. Sirve para «una introducción a la fotosíntesis para 1.º de ESO», no para «el tema 4 de mis
  apuntes».

Para que el `contenido` se parta bien:

```markdown
# La célula
Lo básico para 1.º ESO

## 1. Qué es
La unidad básica de la vida: todo ser vivo está hecho de células.

## 2. Sus partes
- Membrana: la separa del exterior.
- Citoplasma: donde ocurre casi todo.
- Núcleo: guarda el ADN.
```

- **El `#` de la primera línea es el título** de la presentación, y la línea corta que lo sigue, **con
  una línea en blanco detrás**, el subtítulo.
- **Cada titular que viene después es una sección**, sea `##` o `###`: no se anidan. Un titular sin
  texto debajo no cuenta.
- El texto va en párrafos o en listas, y **se maqueta tal cual**: si hay que resumir, resumidlo
  antes de mandarlo.

El profesor podrá desmarcar, renombrar o reordenar las secciones en Épica antes de generar.
Sin número de diapositivas, el tamaño lo dicta el contenido; con `diapositivas`, el motor resume o
desarrolla hasta caber.

## 5. El enlace en vuestro bloque

Con la API de `local_awkepica` que ya usáis (`docs/local-awkepica-api.md`). Una página vuestra,
como la del enlace de Fuentes:

```php
use local_awkepica\epica;

require_login($course);
$contexto = \context_course::instance($course->id);
require_capability('block/pulso:viewanalytics', $contexto);
require_sesskey();

// $contenido (o $tema) y $titulo salen de vuestra conversación, leídos en el servidor:
// nunca por la URL.

// 1. El borrador, de servidor a servidor. Token nuevo: vale 120 s y un solo uso.
$r = epica::pedir(epica::api_url('factoria/borrador'), [
    'token'        => epica::firmar_por($USER, $contexto, 'block/pulso:viewanalytics', (string) $course->id),
    'titulo'       => $titulo,       // opcional
    'contenido'    => $contenido,    // o 'tema' => $tema, nunca los dos
    'diapositivas' => 8,             // opcional, de 3 a 30
]);

if ($r['http'] !== 201) {
    // $r['datos']['error'] es una frase para el profesor; $r['datos']['motivo'], el código de §2.
    throw new \moodle_exception('generalexceptionmessage', 'error', '',
        $r['datos']['error'] ?? 'Épica no contesta ahora mismo.');
}

// 2. La entrada, en el navegador del profesor. Otro token nuevo.
epica::autoenviar(epica::auth_url(), [
    'token'       => epica::firmar_por($USER, $contexto, 'block/pulso:viewanalytics', (string) $course->id),
    'herramienta' => 'factoria',
    'borrador'    => $r['datos']['borrador'],
]);
```

**También podéis hacerlo en dos momentos**: crear el borrador cuando el profesor confirma el tema en el
chat, guardar el código en vuestra conversación, y que el botón «Abrir en la Factoría» haga solo la
segunda mitad. Así, si nuestra puerta contesta un 400, lo veis en el chat y no en una página en blanco.
El borrador dura 24 horas.

**El botón, solo para el profesorado**: enseñadlo cuando
`epica::rol_de('block/pulso:viewanalytics', $contexto)` dé `docente`, como el de Fuentes.

## 6. Lo que pasa después en Épica

El profesor sigue en la Factoría: elige el estilo, el color, repasa las secciones, decide si quiere
imágenes, narración y música, y genera. La presentación queda **en su biblioteca de Épica, en el centro
desde el que entró**, con su enlace público para verla o meterla en un curso.

**Lo que hoy no hay**: que Pulse sepa qué presentación salió de un borrador. Si queréis enseñarla en el
chat cuando esté hecha, decídnoslo (§8) y lo pensamos juntos: sería una puerta más.

## 7. Calendario

- **Programada y probada en nuestro entorno**: las dos formas, los cinco fallos, el borrador recogido
  por otra persona, el borrador caducado y el token de estudiante en las dos llamadas.
- **Os mandamos con esta carta la carpeta `factoria/` de la colección de Bruno**: quince peticiones
  —los dos borradores, los cinco fallos, la entrada y el borrador de otra persona—, con un token nuevo,
  `tokenOtroDocente`. Ninguna llama a un modelo. La hemos pasado entera contra nuestro entorno; contra el
  QA funcionará cuando la subamos:

  ```
  npx --yes @usebruno/cli@4.1.0 run factoria -r --env qa --env-var secreto="EL_SECRETO"
  ```

- **La subimos al QA cuando nos confirméis el sobre**, y os avisamos.
- **Hasta que nos digáis que os vale, la podemos cambiar**: por eso os la contamos antes de subirla.

## 8. Lo que os pedimos

1. **¿Os vale el sobre** de §2 y §3, o queréis cambiar algún campo?
2. **¿Mandaréis `contenido` o `tema`?** Os recomendamos `contenido` cuando la presentación salga del
   material del curso (§4).
3. **¿En un paso o en dos?** Es decir, el borrador y la entrada en la misma página, o el borrador
   desde el chat y el botón aparte (§5).
4. **¿Queréis saber en el chat qué presentación salió?** (§6).
