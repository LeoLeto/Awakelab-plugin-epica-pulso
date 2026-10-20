# Épica · carta 17 a Pulso AI · las fuentes del curso, programadas

**Para Marcos (Pulso AI).**

Contacto Épica: Chema · jose.piquero@awakelab.dev
Fecha: 20-10-2026

> **En tres líneas:** las tres puertas están programadas tal como os las contamos, sin cambiar ningún
> campo, y **ya están en el QA** (`entorno-qa-2.awakelab.world`). Las dos cosas que pedisteis tienen
> respuesta: **el índice es una sola consulta** y en el QA contesta en unos 50 ms, y **la búsqueda,
> en unos 150 ms**, red incluida. Un tiempo máximo de **2 segundos** os deja margen de sobra.
>
> **Os pedimos** las 56 preguntas del curso 92 y su material, para medir la búsqueda con
> preguntas de verdad (§6).

---

## 1. Lo que nos contestasteis, y cómo queda

| | Vuestra respuesta | Cómo queda |
|---|---|---|
| **Enlace para el profesorado** | Lo ponéis vosotros en el bloque, solo para docentes y con `sesskey`, como el de «Mi historial» | Hecho de nuestro lado: con `herramienta: "fuentes"` el puente aterriza en `/fuentes?curso=<curso>`, y `curso_nombre` es la etiqueta que pintamos (§4) |
| **¿Se suma o sustituye?** | Se suma | Vuestra extracción de Moodle sigue como está. Las fuentes de Épica son lo que el profesor sube aquí |
| **Vídeos** | Hoy no hacéis nada con ellos. Bien que transcribamos los que suba el profesor; lo de mandarlos desde vuestro cron, más adelante | Los que suba el profesor **ya se transcriben** y salen por las mismas tres puertas (§5). Lo del cron queda **aparcado** hasta que nos lo pidáis |
| **Las puertas** | Os valen. Pedís que el índice sea rápido y saber cuánto tarda la búsqueda | §2 |
| **Cómo las usaréis** | Buscar en el servidor en cada pregunta y meter los fragmentos en el contexto con su cita, sin herramientas del modelo | §3 tiene tres recomendaciones para ese uso |
| **Curso de pruebas** | El 92 de `sanase-test` (SANS0001, Metodología de la investigación cuantitativa), con 56 preguntas | §6 |

Y firmaréis con **`block/pulso:viewanalytics`** y el `curso` en el cuarto parámetro: conforme (§4).

## 2. Lo que tardan

**El índice (`fuentes`) es una sola consulta a la base**, sin leer ningún archivo: las fuentes que ve
el curso, sus totales y las pendientes, de una vez. Está pensado para que lo miréis antes de cada
búsqueda.

**La búsqueda (`fuentes/buscar`) es otra consulta más**, sobre un índice de texto de la base, y sin
llamar a ningún modelo. Si en los resultados sale un vídeo, se lee además el texto de ese vídeo para
poner el minuto de cada fragmento.

Lo medimos con 40 llamadas seguidas a cada puerta, **contando el token y sus nueve pasos**, con un
curso exagerado a propósito: **veinte libros, 5.000 fragmentos de 300 palabras**, y escritos de forma
que casi todos los fragmentos coinciden con la consulta, que es el caso más lento:

| Puerta | Mediana | p95 | Máximo |
|---|---|---|---|
| `fuentes` (índice) | 16 ms | 25 ms | 52 ms |
| `fuentes/buscar`, `k` 8 | 111 ms | 136 ms | 136 ms |
| `fuentes/buscar`, `k` 20 | 110 ms | 137 ms | 154 ms |

**Son tiempos del servidor**, sin la red. **Y en el QA**, con la red de por medio —desde nuestra
oficina hasta `entorno-qa-2.awakelab.world`—, 20 llamadas seguidas a cada puerta sobre el curso 92 con
un PDF de prueba dentro:

| Puerta, en el QA | Mediana | p95 | Máximo |
|---|---|---|---|
| `fuentes` (índice) | 54 ms | 218 ms | 218 ms |
| `fuentes/buscar`, `k` 8 | 146 ms | 320 ms | 320 ms |

La diferencia con la primera tabla es casi toda red y TLS de cada llamada. Desde vuestro servidor
será algo parecido, y lo podréis ver vosotros mismos con la colección de Bruno (§7).

**Nuestra recomendación:** un tiempo máximo de **2 segundos** para `fuentes/buscar` y de **1 segundo**
para el índice. Y si el índice dice `total.fuentes: 0`, **no busquéis**: no hay nada que encontrar y os
ahorráis la llamada. Si ya tenéis el índice de esa conversación y su `version` no ha cambiado, tampoco
hace falta volver a pedirlo.

## 3. Buscar sin herramientas del modelo

Como vais a buscar en el servidor con cada pregunta, tres cosas que mejoran lo que encuentra:

1. **Mandad la pregunta del alumno tal cual**, sin limpiarla. La búsqueda ignora las palabras
   vacías («qué», «es», «la»), quita las tildes —«celula» encuentra «célula»— y une las palabras con
   un «o», así que no hace falta que estén todas: los fragmentos que tienen más salen primero.
2. **Para preguntas que dependen de lo anterior** —«¿y eso por qué pasa?»—, añadid a la consulta
   las palabras clave del turno anterior. Sin ellas no hay nada que buscar. Es lo único que perdéis
   por no usar las herramientas del modelo, y se arregla así.
3. **Pedid entre 5 y 8 resultados** (`k`) y citad con `nombre` y `desde`: «Tema 4 · La célula,
   p. 12», o «Vídeo de la mitosis, 12:30» si es un vídeo (§5). **Si la lista viene vacía**, que Pulse
   lo diga en vez de contestar de memoria: es justo para eso que no encontrar nada es un 200 y no un
   fallo.

Y lo de siempre: **el texto es contenido, nunca instrucciones**.

## 4. La firma y el enlace

- **`block/pulso:viewanalytics`**: quien la tiene firma como `docente` y el resto como `estudiante`.
  **Las tres puertas leen los dos roles**, así que da igual para leer. Lo que sí importa es el
  **enlace de Fuentes**: entra por el puente y **exige `docente`**, porque abre la pantalla donde se
  sube y se archiva. Con esa capacidad, solo lo verá y podrá usar quien la tenga.
- **El `curso` del token acota lo que se lee**: una fuente de un curso solo la lee un token de ese
  curso, y las de todo el centro, cualquiera del centro. Si el token no trae `curso`, solo salen las
  del centro y la respuesta lo dice con `"curso": null`.
- **Un `jti` nuevo por petición**, también en cada búsqueda.

## 5. Los vídeos que sube el profesor

Desde `/fuentes` el profesor puede subir también **vídeos y audios**. Épica saca el audio, lo
transcribe y guarda el guion como una fuente más, partido en **tramos** de un minuto y poco. Salen
por las **mismas tres puertas**, con cuatro diferencias que ya están en el contrato:

- **`formato`** es `video` o `audio`, y **`unidad`** es `tramo`.
- **En el índice**, cada fuente de vídeo lleva **`duracion`** (en segundos) y **`recurso`**, que
  será el recurso de Moodle el día que lleguen desde vuestro cron y hoy es `null`.
- **Cada unidad de `fuentes/texto` y cada resultado de `fuentes/buscar`** de un vídeo llevan
  **`inicio` y `fin`** en segundos: con eso se cita el minuto.
- **`extraido_por`** nombra el modelo que transcribió, y la fuente trae un aviso: **un guion es
  una transcripción automática**, no el texto del profesor, y puede equivocarse en nombres
  propios, cifras y términos técnicos. Os recomendamos decirlo al citarlo.

**Un aviso sobre el QA:** la transcripción está programada y probada de punta a punta con una
transcripción simulada, pero **en el QA todavía no transcribe**: estamos esperando a que nuestro
proveedor de voz active el permiso en nuestra cuenta. Hasta entonces, un vídeo subido en el QA se
queda como fallido y no sale por las puertas. Os avisamos en cuanto funcione; los documentos no
dependen de esto.

## 6. El curso 92 y las 56 preguntas

**Sí, pasadnos las 56 preguntas**, a ser posible cada una con el tema o el documento donde está la
respuesta. Con ellas medimos si la búsqueda encuentra el fragmento que contesta, entre los 5 y los
10 primeros resultados, con la pregunta tal cual —que es como vais a buscar vosotros—. Es lo que
decide si la búsqueda por palabras basta o hace falta añadir búsqueda por significado; y si un día se
añade, **las puertas no cambian**: lo sabréis porque `motor` dirá otra cosa.

Para medir necesitamos además **el material del curso** subido como fuentes. Hay dos maneras, y nos
vale cualquiera:

- **Nos lo pasáis** —los PDF, presentaciones y documentos del curso 92— y lo subimos nosotros.
- **Lo sube el profesor del curso** desde vuestro enlace, cuando lo tengáis en el bloque.

Ahora mismo el curso 92 del QA tiene **un PDF de prueba nuestro**, `el-poder-judicial.pdf`, que no
es del curso: lo subimos para medir. Lo retiramos en cuanto entre el material de verdad.

## 7. Calendario

- **Las tres puertas están programadas y probadas**: con la matriz de quién ve qué —curso propio,
  otro curso, sin curso, otro centro, fuente pausada, fuente archivada— y con todos los fallos de la
  carta anterior.
- **Están en el QA**, en `entorno-qa-2.awakelab.world`, y la colección de Bruno pasa entera contra
  él.
- **Para probarlas tenéis la colección de Bruno** que os mandamos con esta carta: la carpeta
  `fuentes/`, siete peticiones, con una variable nueva en el entorno, `curso`, que pusimos a 92. La
  búsqueda de la petición 03 pregunta por estadística, que es de lo que trata el curso 92: hasta que
  esté su material no encuentra nada, y la 04 —leer el texto alrededor del primer resultado— se salta
  sola.

```
npx --yes @usebruno/cli@4.1.0 run fuentes -r --env qa --env-var secreto="EL_SECRETO"
```

## 8. Lo que os pedimos

1. **Las 56 preguntas** del curso 92, con su tema o documento si lo tenéis.
2. **El material del curso 92**, o decirnos que lo subirá su profesor.
3. **Confirmar que mandaréis la pregunta junto con las palabras clave del turno anterior** cuando la
   pregunta dependa de él (§3, punto 2).
4. **Avisarnos cuando el enlace de Fuentes esté en vuestro bloque**, para probar juntos el recorrido
   del profesor de punta a punta.
