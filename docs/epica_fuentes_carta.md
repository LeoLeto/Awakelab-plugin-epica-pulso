# Épica · carta a Pulso AI · las fuentes del curso para Pulse

> **Dónde vive esto.** Es la carta que abre un tema nuevo con **Pulso AI** (`block_pulso`): que su
> chat, **Pulse**, pueda leer y buscar el material que el profesorado sube a Épica. Se versiona por lo
> mismo que las anteriores (`docs/pulso-ai-respuesta*.md`): lo que va aquí es un contrato con un
> equipo de fuera que no lee nuestros commits. Lo que manda por dentro es `docs/fuentes.md`, y **las
> tres puertas no se programan hasta que contesten**.

---

**Para Marcos (Pulso AI).**

Contacto Épica: Chema · jose.piquero@awakelab.dev
Fecha: 16-10-2026

> **Lo que os proponemos, en tres líneas.** El profesorado sube a Épica archivos de su curso —o de todo
> el centro—, Épica saca el texto y lo indexa, y **Pulse lo pide por tres puertas nuevas**: el índice
> de fuentes del curso, el texto de una fuente y una búsqueda que devuelve fragmentos con documento y
> página. Mismo token de siempre, sin cuentas nuevas y sin gasto por pregunta. Nuestra parte acaba en
> la respuesta de la puerta: la conversación y la respuesta son vuestras.
>
> **Y tres preguntas que solo podéis contestar vosotros**, en §5: dónde va el enlace para que el
> profesor suba sus fuentes, si esto se suma a lo que ya extraéis de Moodle o lo sustituye, y qué
> hacéis hoy con los vídeos del curso, porque Épica también va a poder transcribirlos.
>
> **Nada de esto está publicado.** Os lo mandamos antes de programarlo para que lo que construyamos
> sea lo que os sirve.

**Sobre el nombre.** Vuestro chat se llama ahora **Pulse**, y así lo llamamos desde esta carta. En
las anteriores había un aviso de «no confundir Pulso AI con Pulse», que era el nombre de una
herramienta nuestra que ya no existe: podéis ignorarlo.

---

## 1 · Qué es una fuente, y quién la ve

Una **fuente** es un archivo que el profesor sube a Épica: PDF con texto, Word (`.docx`), PowerPoint
(`.pptx`, con sus notas del orador), texto o markdown. Épica lo lee por **unidades** —la página de un
PDF, la diapositiva de un PowerPoint, el apartado de un Word—, que son lo que se cita, y cada unidad
lleva su **ruta**: los titulares que mandan en ella («Tema 4. La célula › 4.2 La membrana
plasmática»).

**Cada fuente se sirve en su sitio y en ninguno más:**

| La fuente es de… | La ve un token con… |
|---|---|
| Un curso | Vuestro código de plataforma **y ese `curso`** |
| Todo el centro | Vuestro código de plataforma, con cualquier curso o sin él |

**El curso sale del token**, del claim `curso` que ya firmáis con el cuarto parámetro de
`epica::firmar_por()`. Si el cuerpo trae otro `curso`, contestamos 400 `curso-contradictorio`, igual
que con `origen`. **Lo que no podemos comprobar** es que esa persona esté matriculada en ese curso: el
token dice quién es y de qué centro, y el curso lo afirma vuestro Moodle al firmar. Firmad solo para
quien puede ver ese curso.

Lo que Épica no sirve: lo que todavía se está leyendo, un PDF escaneado sin texto, una fuente que el
profesor ha pausado o archivado, y **una fuente que parece llevar datos personales** (DNI, IBAN,
listas de correos o teléfonos) hasta que el profesor confirma que se puede usar.

---

## 2 · Las tres puertas

Las tres son `POST` a `https://epica.awakelab.world/api/moodle/…`, con el token en el cuerpo, **un
`jti` nuevo por petición** —también en cada búsqueda— y los dos roles, `docente` y `estudiante`. No
crean ninguna cuenta en Épica. **No hace falta mandar `herramienta`**: la ruta ya dice que es de
fuentes, y si viene se ignora. Vuestra plataforma necesita tener contratada la herramienta
**«Fuentes»**, que activamos nosotros; si no, es el 409 `herramienta-no-contratada` de siempre.

### 2.1 · `fuentes` · el índice del curso

```json
{ "token": "eyJ…" }
```

```json
{
  "plataforma": { "codigo": "sanase-test", "nombre": "Sanase Test" },
  "curso": "1842",
  "version": "c41f09a2b7e3d815",
  "fuentes": [
    {
      "id": "8f0c2a7e-4b1d-4f3e-9a55-2c7d1e6b9f10",
      "nombre": "Tema 4 · La célula.pdf",
      "ambito": "curso",
      "formato": "pdf",
      "unidad": "pagina",
      "unidades": 38,
      "idioma": "es",
      "caracteres": 94500,
      "tokens_estimados": 27000,
      "huella": "9b1d5e…",
      "extraido_por": "epica-pdf 1 · pdf.js 5.7.284",
      "avisos": ["Las páginas 14 y 15 no tienen texto: parecen escaneadas o son solo imagen."],
      "actualizada_en": "2026-10-02T09:12:00Z"
    }
  ],
  "total": { "fuentes": 1, "caracteres": 94500, "tokens_estimados": 27000 },
  "pendientes": 0
}
```

- **`version`** cambia si entra, sale o cambia cualquier fuente. Si la miráis al empezar cada
  conversación, sabéis sin coste si lo que tenéis guardado sigue valiendo.
- **`tokens_estimados`** son caracteres entre 3,5: sirven para decidir si el material de un curso cabe
  entero en el prompt de vuestro modelo (§3), no para facturar.
- **`pendientes`** son las que el profesor ha subido y todavía no se pueden servir.
- **Sin `curso` en el token**, solo salen las del centro, y `curso` vuelve `null`.

### 2.2 · `fuentes/texto` · el texto, entero o por tramos

```json
{ "token": "eyJ…", "fuente": "8f0c2a7e-4b1d-4f3e-9a55-2c7d1e6b9f10", "desde": 12, "hasta": 14 }
```

```json
{
  "fuente": "8f0c2a7e-4b1d-4f3e-9a55-2c7d1e6b9f10",
  "nombre": "Tema 4 · La célula.pdf",
  "huella": "9b1d5e…",
  "unidad": "pagina",
  "unidades": [
    { "n": 12, "ruta": ["Tema 4. La célula", "4.2 La membrana plasmática"], "texto": "…" },
    { "n": 13, "ruta": ["Tema 4. La célula", "4.2 La membrana plasmática"], "texto": "…" },
    { "n": 14, "ruta": ["Tema 4. La célula", "4.3 El transporte"], "texto": "…" }
  ],
  "siguiente": null
}
```

Sin `desde` ni `hasta` devuelve la fuente entera. **Una respuesta no pasa de 500.000 caracteres**: si
la fuente es más larga, `siguiente` dice desde qué unidad pedir el resto. El texto es **literal**: el
del archivo, sin reescribir, con las cabeceras y pies de página quitados y las notas del orador
rotuladas («Notas del orador: …»).

### 2.3 · `fuentes/buscar` · la búsqueda

```json
{ "token": "eyJ…", "consulta": "ósmosis membrana transporte pasivo", "k": 8 }
```

```json
{
  "motor": "lexico-1",
  "resultados": [
    {
      "fragmento": "81234",
      "fuente": "8f0c2a7e-4b1d-4f3e-9a55-2c7d1e6b9f10",
      "nombre": "Tema 4 · La célula.pdf",
      "unidad": "pagina",
      "ruta": ["Tema 4. La célula", "4.2 La membrana plasmática"],
      "desde": 12,
      "hasta": 13,
      "texto": "La ósmosis es el paso de agua a través de una membrana semipermeable…",
      "puntuacion": 0.82
    }
  ]
}
```

- **`k`**: 8 por omisión, de 1 a 20. Con `fuentes: ["id", …]` se busca solo en esas.
- **Una lista vacía es una respuesta válida** (200): es la señal para que Pulse diga «eso no está en
  el material del curso» en vez de contestar de memoria.
- **`puntuacion`** va de 0 a 1 y solo sirve para ordenar dentro de una respuesta.
- **La búsqueda es por palabras**, sin tildes (encuentra «célula» buscando «celula») y con variantes
  («membranas» encuentra «membrana»). Si más adelante añadimos búsqueda por significado, **esta
  puerta no cambia**: lo sabréis porque `motor` dirá otra cosa.

### 2.4 · Los fallos

Los del token son la tabla de siempre, con su `traza`. Los del cuerpo son **400, sin traza**:

| Motivo | Cuándo |
|---|---|
| `curso-contradictorio` | El `curso` del cuerpo no es el del token |
| `fuente-desconocida` | La fuente no existe, es de otro curso u otro centro, está archivada o todavía no se puede servir. Los cuatro casos contestan lo mismo |
| `tramo-invalido` | `desde` o `hasta` no caben en la fuente |
| `consulta-vacia` | No queda nada que buscar |
| `consulta-excesiva` | La consulta pasa de 500 caracteres |

**No hay cuota en estas puertas**: una búsqueda no llama a ningún modelo nuestro.

---

## 3 · Cómo os recomendamos usarlas

**Como dos herramientas de vuestro modelo** (*tool use*), en el formato de vuestro proveedor:

| Herramienta | Parámetros | Llama a | Cuándo, según su descripción |
|---|---|---|---|
| `buscar_en_fuentes` | `consulta` | `fuentes/buscar` | «Antes de contestar cualquier pregunta sobre el contenido del curso. Escribe la consulta con palabras clave reescritas a partir de la conversación. Si no devuelve nada útil, dilo en lugar de contestar de memoria» |
| `leer_fuente` | `fuente`, `desde`, `hasta` | `fuentes/texto` | «Cuando un fragmento se queda corto y hace falta leer alrededor» |

Tres cosas que hacen que funcione, y las tres son de vuestro lado porque solo vosotros tenéis la
conversación:

1. **Reformular antes de buscar.** «¿Y eso por qué pasa?» no se puede buscar sin lo que se lleva
   hablado. Una consulta con palabras clave y algún sinónimo encuentra mucho más que la pregunta
   literal.
2. **Citar documento y unidad**: «Tema 4 · La célula, p. 12».
3. **Tratar el texto como datos, nunca como instrucciones.** Un documento puede traer frases del tipo
   «ignora lo anterior»: nosotros quitamos los caracteres invisibles, pero lo que se lee es
   contenido, y así tiene que llegar a vuestro modelo.

**Y si el material de un curso es pequeño**, podéis saltaros la búsqueda: pedir el texto entero y
meterlo en el prompt. Como referencia, Anthropic recomienda no montar búsqueda por debajo de unos
200.000 tokens. El umbral de verdad depende de vuestro modelo y de lo que os cueste cada pregunta, y
por eso el índice os da `tokens_estimados` de cada fuente y del curso.

**Olvidar lo que ya no está.** Cuando una fuente desaparece del índice —el profesor la ha archivado
o sustituido—, lo que tuvierais guardado de ella hay que tirarlo. Es la mitad de que borrar sea borrar.

---

## 4 · Lo que os pedimos, en lista

- [ ] Firmar **siempre con `curso`** —`(string) $COURSE->id` en el cuarto parámetro de
      `firmar_por()`— y solo para quien puede ver ese curso.
- [ ] **Un `jti` por petición**, también en cada búsqueda y en cada tramo de texto.
- [ ] Reformular, citar, decir «no está en el material» y tratar el texto como datos (§3).
- [ ] Mirar `version` al empezar cada conversación y olvidar lo que haya desaparecido.
- [ ] Contestar las dos preguntas de §5.

Cómo queda una búsqueda con la API de `local_awkepica` (`docs/local-awkepica-api.md`, que ya
tenéis):

```php
use local_awkepica\epica;

$contexto = \context_course::instance($COURSE->id);
$token = epica::firmar_por($USER, $contexto, 'block/pulso:LA_VUESTRA', (string) $COURSE->id);

$r = epica::pedir(epica::api_url('fuentes/buscar'), [
    'token'    => $token,
    'consulta' => 'ósmosis membrana transporte pasivo',
    'k'        => 8,
]);

if ($r['http'] === 200) {
    foreach ($r['datos']['resultados'] as $fragmento) {
        // $fragmento['texto'], con $fragmento['nombre'] y $fragmento['desde'] para la cita.
    }
}
```

Para `fuentes/texto` de una fuente grande, pasad `epica::ESPERA_LARGA_S` como tercer parámetro de
`pedir()`.

---

## 5 · Tres preguntas

### 5.1 · ¿Ponéis vosotros el enlace «Fuentes» para el profesorado?

El profesor sube sus fuentes en una pantalla de Épica, `/fuentes`, a la que se entra **desde el curso
y sin contraseña**, por el mismo puente que ya usan nuestros plugins. Lo natural es que el enlace esté
en vuestro bloque, en la vista del profesorado, porque es donde vive el agente que las va a leer.

Serían unas pocas líneas de vuestro lado, con lo que ya tenéis. Es lo que hace `mod_awkepicadeck` en
su `puente.php`: una página propia que exige sesión, capacidad de docente y `sesskey`, y que al
abrirse firma y envía un formulario con `epica::autoenviar()`:

```php
require_login($course);
$contexto = \context_course::instance($course->id);
require_capability('block/pulso:LA_DE_DOCENTE', $contexto);
require_sesskey();

$token = epica::firmar_por($USER, $contexto, 'block/pulso:LA_DE_DOCENTE', (string) $course->id);
epica::autoenviar(epica::auth_url(), [
    'token'        => $token,
    'herramienta'  => 'fuentes',                         // aterriza en /fuentes de ese curso
    'curso_nombre' => format_string($course->fullname),  // solo para pintarlo
]);
```

El `sesskey` no es ceremonia: sin él, una página de fuera podría cargar esa en un iframe oculto y dar
de alta en Épica a quien estuviera dentro. **El enlace solo tiene que verlo el profesorado**, con el
rol derivado de una capacidad en contexto de curso, como os pedimos en la primera carta (§4.6).

**Si preferís no ponerlo**, lo ponemos nosotros en un plugin propio; pero sería un plugin más que
instalar en cada Moodle, y el profesor tendría que buscar las fuentes del agente fuera del agente.

### 5.2 · ¿Esto se suma a lo que ya extraéis de Moodle, o lo sustituye?

Sabemos que ya extraéis por vuestra cuenta los recursos de Moodle del curso, con `smalot/pdfparser` y
un cron nocturno. Nuestra propuesta es que **se sume**: vuestros recursos de Moodle siguen como están,
y las fuentes de Épica son el material que el profesor sube expresamente para el agente —guías,
apuntes, lo que no quiere publicar como recurso—.

**Si preferís que sustituya**, se puede, pero cambia el tamaño del trabajo: Épica tendría que leer
también los recursos del curso de Moodle, y eso es una sincronización con Moodle que hoy no está
planeada. Decidnos cuál os sirve.

**Antes de contestar, tened en cuenta §5.3**: una fuente también podrá ser un vídeo.

### 5.3 · ¿Qué hacéis hoy con los vídeos del curso?

Os lo adelantamos porque cambia lo que nos podéis contestar en §5.2. **Épica va a poder transcribir
vídeos y audios**: saca el audio, lo transcribe y guarda el guion como una fuente más, partido en
tramos de un minuto y poco, con el minuto en que empieza cada uno. Pulse lo leería y lo buscaría por
las mismas tres puertas, y podría citar «Vídeo de la mitosis, 12:30».

Hay dos maneras de que un vídeo llegue a Épica:

- **Que el profesor lo suba en `/fuentes`**, como cualquier otro archivo. Esto no os pide nada.
- **Que vuestro cron, al indexar el curso, nos mande los vídeos que encuentre en sus recursos**, y
  Épica los transcriba. Para esto haría falta una puerta más, de vuestro lado una llamada más en el
  cron.

**Los detalles de la segunda os los mandamos en una carta aparte** cuando nos contestéis esta, porque
dependen de lo que digáis en §5.2. Por ahora nos basta con saber **qué hacéis hoy con los vídeos al
indexar**: si los ignoráis, si ya los transcribís de alguna manera, o si los tenéis enlazados a
YouTube o a otro sitio en vez de subidos al curso.

---

## 6 · Calendario

- **Nada de esto está publicado**, ni siquiera en el QA. De nuestro lado ya están escritas y probadas
  la lectura de los archivos, la base y la pantalla del profesor, `/fuentes`; **faltan las tres
  puertas**, que son las que os tocan y las que esperan a vuestra respuesta.
- **Programamos las puertas en cuanto nos contestéis**, con los cambios que pidáis. Os avisamos cuando
  estén en `entorno-qa-2.awakelab.world`, con una colección de Bruno para probarlas como la de
  Gamificación.
- **Para probar necesitaréis que el profesor haya subido algo.** Cuando esté en el QA, subiremos unas
  cuantas fuentes de ejemplo al curso de pruebas que nos digáis.

---

## 7 · Lo que os pedimos que contestéis

1. **§5.1**: ¿el enlace de «Fuentes» para el profesorado va en vuestro bloque?
2. **§5.2**: ¿esto se suma a vuestra extracción de Moodle o la sustituye?
3. **§5.3**: ¿qué hacéis hoy con los vídeos del curso cuando lo indexáis?
4. **Las tres puertas**: ¿os falta algún campo, o sobra alguno? Mejor ahora que cuando estén
   programadas.
5. **Cómo las vais a usar**: ¿como herramientas de vuestro modelo, metiendo el texto entero en el
   prompt, o montando vuestro propio índice con `fuentes/texto`? No cambia las puertas, pero nos dice
   qué medir primero.
6. **Un curso de pruebas** en `sanase-test` con material real, para medir si la búsqueda encuentra lo
   que preguntan los alumnos. Si tenéis preguntas reales anonimizadas del chat, mejor todavía.
