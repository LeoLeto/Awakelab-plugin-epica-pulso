# Épica · carta 11 a Pulso AI · los colores de cada centro

> **Dónde vive esto.** Undécima vuelta con **Pulso AI** (`block_pulso`). Es una **petición nuestra**:
> que el tema de Pulse tenga unas pocas variables que rellena Épica. Trae **una puerta nueva**,
> `POST /api/moodle/tema`, y no cambia ninguna de las que ya llamáis. La lógica está en
> `backend/src/tema-pulse.ts` y la migración es la `019`. Las referencias son de `dev` a 06-10-2026.

---

**Para Marcos (Pulso AI).**

Contacto Épica: Chema · jose.piquero@awakelab.dev
Fecha: 06-10-2026

> **En dos líneas:** queremos que cada centro vea Pulse **con sus colores corporativos**, y que esos
> colores los elijamos nosotros, centro a centro, desde el panel de administración de Épica.
>
> **El tema es vuestro**: cómo lo montéis, también. Lo que os pedimos es que **unas pocas variables
> las rellene Épica** —de partida, dos— y que nos digáis cuáles queréis, porque vosotros conocéis
> vuestra interfaz y nosotros no.

---

## 1. El reparto

| Es vuestro | Es de Épica |
|---|---|
| El tema entero: maqueta, tipografía, modo claro u oscuro, estados, sombras | Los colores representativos de cada centro |
| Cómo se derivan los demás tonos: el hover, el fondo suave, el borde | El valor de las variables que nos dejéis |
| El nombre de vuestras variables CSS | El nombre de cada variable en el contrato |
| **El tema por defecto**: lo que se ve cuando Épica no manda un color | Decidir, centro a centro, si hay colores y cuáles |

Un centro al que no le hemos puesto colores se ve **exactamente como hoy**: Épica contesta
`tema: null` y Pulse usa los suyos. Y lo mismo variable a variable: la que no os mandemos, es la
vuestra.

## 2. Las variables, y lo que os preguntamos

De partida, **dos**:

| Clave | Para qué la pensamos |
|---|---|
| `principal` | El color de la marca del centro: la cabecera, el botón principal, el mensaje de quien escribe |
| `acento` | El segundo color de la marca, para los detalles: enlaces, foco, indicadores |

**La lista definitiva es vuestra.** Si os hace falta separar la cabecera del botón, o un color para
las burbujas del chat, decídnoslo. Añadir una variable de nuestro lado es una línea, sin migración:
la ponemos en el catálogo y desde ese día se puede elegir en el panel. Los centros que no la tengan
puesta seguirán recibiendo la vuestra. Por cada una necesitamos:

- **la clave**, corta, en minúsculas y con guiones si hacen falta (`cabecera`, `burbuja-propia`);
- **para qué la usáis**, en una frase: es lo que leerá quien elija el color en nuestro panel.

## 3. La puerta: `POST /api/moodle/tema`

**El mismo token de siempre**, con el mismo secreto. El cuerpo lleva un solo campo:

```json
{ "token": "<el token firmado>" }
```

- **Vale cualquier usuario y cualquier rol.** Desde una tarea programada no hay nadie delante:
  firmad con el administrador del sitio, que tiene que tener un correo válido en su ficha: el paso 9
  lo exige, como siempre. Esta puerta no crea cuenta, no abre sesión y no pone cookie: se para en el
  paso 9.
- **No comprueba el contrato de ninguna herramienta** (el paso 8): leer unos colores no gasta, así
  que funciona igual tenga el centro contratada Infografías, Gamificación o nada.
- **El `curso` sobra**: el tema es del centro, no de un curso.
- **No hay cuota ni cupo.** Cada llamada gasta su `jti`, como siempre: un token nuevo por llamada,
  y nunca el mismo en un reintento.

Con `local_awkepica` son tres líneas:

```php
use local_awkepica\epica;

$token = epica::firmar_por(get_admin(), \context_system::instance(), 'block/pulso:createactivity');
$r = epica::pedir(epica::api_url('tema'), ['token' => $token]);
// $r['http'] === 200 y $r['datos']['tema'] es null o el objeto de abajo.
```

`api_url('tema')` es `<base>/api/moodle/tema`. Antes, `epica::configurado()`: sin el ajuste del
sitio no hay a quién preguntar, y lo que toca es vuestro tema.

### La respuesta

Así contesta un centro con los dos colores puestos. Es la respuesta de nuestras pruebas, con el
centro cambiado por el vuestro:

```json
{
  "plataforma": { "codigo": "sanase-test", "nombre": "Sanase Test" },
  "tema": {
    "version": "0e8d4794",
    "actualizado": "2026-10-06T10:17:09.189Z",
    "colores": {
      "principal": { "valor": "#003670", "texto": "#ffffff" },
      "acento":    { "valor": "#0fced3", "texto": "#000000" }
    }
  }
}
```

| Campo | Qué es |
|---|---|
| `tema` | **`null`** si el centro no tiene colores en Épica. Entonces, vuestro tema por defecto |
| `colores` | **Solo los que se han elegido.** Nunca viene vacío: si no hay ninguno, `tema` es `null` |
| `valor` | El color, **siempre `#rrggbb`**, seis cifras y en minúsculas |
| `texto` | `#ffffff` o `#000000`: el que mejor se lee **encima** de `valor`. Pasa **siempre** de 4,5:1, el mínimo AA para texto normal, sea cual sea el color de marca. Para el texto o el icono de un botón con fondo `principal`. Si preferís calcularlo vosotros, ignoradlo |
| `version` | Ocho hexadecimales que cambian en cuanto cambia cualquier color. Comparadla con la guardada para no repintar nada si es la misma, o para romper la caché de vuestra hoja de estilos |
| `actualizado` | Cuándo se tocaron los colores por última vez, en ISO 8601 |

Los fallos son **la tabla de siempre** (`docs/identidad.md` §10), con su `traza`. Los dos que vais a
ver si algo está mal configurado: **401 `enlace-invalido`** —código de plataforma o secreto— y
**409 `plataforma-suspendida`**.

## 4. Cuándo preguntar: nunca al pintar una página

Es la regla que cumplen todos nuestros plugins: **ninguna página de Moodle espera a que Épica
conteste.** Así que:

1. **Una tarea programada** —cada hora va sobrado— pregunta y **guarda** la respuesta: en la
   configuración del bloque o en la caché de aplicación de Moodle.
2. **Un botón en vuestros ajustes**, algo como «Sincronizar los colores con Épica», para no esperar a
   la tarea cuando el centro acaba de pedir un cambio.
3. **Al pintar, leéis lo guardado**, y nada más.

Y qué hacer con cada respuesta:

| Pasa | Qué hacéis |
|---|---|
| 200 con un tema | Lo guardáis, si `version` es distinta de la guardada |
| 200 con `tema: null` | **Borráis lo guardado**: el centro ha vuelto a vuestro tema |
| Un fallo, un timeout o `http` 0 | **Os quedáis con lo último que guardasteis**. Si nunca hubo nada, vuestro tema |

Un cambio en nuestro panel tarda en verse lo que tarde vuestra tarea, o el botón. Para colores
corporativos, que cambian una vez al año, es de sobra.

## 5. Al pintar: validad antes de inyectar

Lo que os mandamos acaba dentro de una hoja de estilos. Épica **garantiza** `#rrggbb` —se comprueba
dos veces, en el código y en la base—, pero un valor que llega de otro servidor no debería entrar en
un `<style>` sin mirarlo. Comprobad la forma y, si no casa, **ignorad esa variable**:

```php
$css = '';
foreach (($tema['colores'] ?? []) as $clave => $color) {
    if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $clave)) {
        continue;
    }
    foreach (['valor' => '', 'texto' => '-texto'] as $campo => $sufijo) {
        $v = $color[$campo] ?? '';
        if (preg_match('/^#[0-9a-f]{6}$/', $v)) {
            $css .= "--pulse-{$clave}{$sufijo}:{$v};";
        }
    }
}
// .block_pulso { ...$css... }
```

Dos sugerencias, vuestras para decidir:

- **Acotad las variables a la raíz de vuestro bloque** y no a `:root`, para no tocar el tema de
  Moodle del centro.
- **Un color de marca no tiene por qué leerse como texto sobre vuestro fondo.** Un cian corporativo
  sobre blanco da menos de 2:1. Si vais a usar `acento` para enlaces o texto sobre fondo claro,
  comprobad el contraste, o pedidnos una variable para eso.

## 6. Lo que necesitamos que nos contestéis

1. **La lista de variables**: clave y para qué la usáis (§2). Hasta que nos la digáis, son `principal`
   y `acento`.
2. **¿Os sirve `texto`**, o lo calculáis vosotros?
3. **¿Pulse tiene modo oscuro?** Nuestra propuesta es el mismo color de marca en los dos y que
   ajustéis vosotros lo que haga falta; si necesitáis una variante por modo, decídnoslo y la
   planteamos.
4. **Cada cuánto vais a preguntar.** No hay cupo; es para saberlo.

## 7. Para probarlo

La colección de Bruno gana la carpeta **`tema/`**: tres peticiones que no encargan nada.

```
npx --yes @usebruno/cli@4.1.0 run tema -r --env qa --env-var secreto="EL_SECRETO"
```

| | |
|---|---|
| 01 | El tema de vuestro centro: 200, y `tema` es `null` o un objeto con la forma de §3 |
| 02 | Con un token de alumno, lo mismo: leer colores vale con cualquier rol |
| 03 | Sin token no contesta nada: 400 `token-ausente`, con traza |

La pasamos contra un Épica local con dos centros, uno con colores y otro sin ellos: **3 de 3
peticiones, 7 de 7 tests y 4 de 4 aserciones en los dos**. Para ver la 01 con colores en el QA,
pedídnoslo y se los ponemos a vuestro centro de pruebas.
