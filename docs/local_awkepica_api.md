# API pública de `local_awkepica` (1.1.0)

Para quien firma y envía encargos a Épica desde otro plugin de Moodle. Sacado de
`moodle/local/awkepica/classes/epica.php` y `classes/token.php`; lo que el código no dice, se
marca como tal.

## El fallo que estáis viendo

```
TypeError: local_awkepica\epica::firmar_por(): Argument #4 ($curso) must be of type string, stdClass given
```

Estáis pasando el objeto del curso (`$COURSE` o el registro de `course`). El cuarto parámetro es
**el `id` del curso convertido a cadena**: `(string) $COURSE->id`.

## 1. Firmas

Todo es `public static` en la clase `\local_awkepica\epica`.

```php
rol_de(string $capacidad, \context $contexto, $usuario = null): string
firmar_por(\stdClass $usuario, \context $contexto, string $capacidad, string $curso = ''): string
pedir(string $url, array $cuerpo, ?int $espera = null): array
api_url(string $ruta): string
```

- `rol_de()` devuelve `'docente'` si `has_capability($capacidad, $contexto, $usuario)` es cierto y
  `'estudiante'` en cualquier otro caso. `$usuario` admite la ficha o el id; con `null` mira al
  usuario de la sesión. Nunca devuelve `administrador`.
- `api_url('laminas/encargar')` devuelve `<base>/api/moodle/laminas/encargar`: quita las barras de
  los extremos de `$ruta` y la cuelga de la URL base del ajuste del sitio, sin barra final.

Otras funciones públicas que existen: `base_url()`, `plataforma()`, `secreto()`, `configurado(): bool`,
`url(string $ruta)`, `auth_url()`, `tools_url()`, `ajustes_url()`, `comprobar_url(array $p = [])`,
`autoenviar(string $destino, array $campos)` y `adoptar_ajustes(string $componente)`. Para enviar un
encargo solo os hacen falta `configurado()`, `firmar_por()`, `api_url()` y `pedir()`.

Constantes de `epica`:

| Constante | Valor | Para qué |
|---|---|---|
| `ESPERA_S` | `15` | Tope por omisión de `pedir()`, en segundos, para la petición entera |
| `ESPERA_LARGA_S` | `180` | La espera que hay que pasar cuando el cuerpo lleva material (un PDF en base64) |
| `CONEXION_S` | `8` | `CURLOPT_CONNECTTIMEOUT`, fijo |
| `COMPONENTE` | `'local_awkepica'` | |
| `CAPABILITY_SITIO` | `'moodle/site:config'` | Solo la usa la pantalla de comprobar la conexión |
| `AJUSTE_BASEURL`, `AJUSTE_PLATAFORMA`, `AJUSTE_SECRETO`, `BASEURL_DEFAULT` | | Nombres de ajustes y la URL por omisión; no hace falta tocarlos |

Constantes de `\local_awkepica\token` que pueden interesaros: `ROL_DOCENTE = 'docente'`,
`ROL_ESTUDIANTE = 'estudiante'`, `VIDA_S = 120`, `MAX_CURSO = 64`.

## 2. `firmar_por()`: qué va en `$curso` y qué lleva el token

**`$curso` es el `id` numérico del curso, como cadena.** El docblock lo dice así («el `id` del curso
desde el que se pulsó, si lo hay») y así lo pasan todos nuestros plugins. Ni `fullname`, ni
`shortname`, ni `idnumber`. Por ejemplo, en `mod/awkepicadeck/puente.php` y en `mod/awkepicareto/elegir.php`:

```php
$firmado = epica::firmar_por($USER, $contexto, deck::CAPABILITY_HERRAMIENTAS, (string) $course->id);
```

y en la tarea que encarga láminas (`mod/awkepicalamina/classes/task/generar.php`):

```php
$firmado = epica::firmar_por($usuario, $contexto, lamina::CAPABILITY_HERRAMIENTAS, (string) $instancia->course);
```

La **capacidad** es la de la herramienta que firma, no una del sitio: cada plugin pasa la suya (la
de láminas es `mod/awkepicalamina:usetools`). De ella sale el `rol`. Qué capacidad tiene que usar
un bloque de otro equipo es algo que el código no decide.

Claims de la carga, en este orden (`token::carga()`):

| Claim | Valor |
|---|---|
| `iss` | el código de plataforma del ajuste del sitio, en minúsculas |
| `sub` | `(string) $usuario->id` |
| `email` | `(string) $usuario->email`, recortado a 254 caracteres |
| `nombre` | `fullname($usuario)`, recortado a 120 |
| `rol` | `rol_de($capacidad, $contexto, $usuario)` → `docente` o `estudiante` |
| `curso` | `$curso` recortado a 64. **Solo va si no está vacío**: con `''` el claim no aparece |
| `iat` | `time()` |
| `exp` | `iat + 120`. No se puede cambiar |
| `jti` | 16 bytes aleatorios en base64url (22 caracteres). Cada llamada firma uno nuevo, y Épica rechaza el segundo uso |

Encabezado literal `{"alg":"HS256","typ":"JWT"}`, firma HMAC-SHA256 con el secreto del ajuste,
todo en base64url sin relleno. Devuelve el token compacto `encabezado.carga.firma`.

Qué **no** hace, según el código:
- No comprueba que la ficha tenga correo: eso lo tiene que comprobar quien llama («Quien llame, comprueba»).
- No comprueba que el sitio esté configurado. Si no lo está, firma igual con `iss` vacío o sin
  secreto, y Épica lo rechazará. Llamad antes a `epica::configurado()`.
- Lanza `\RuntimeException` si la carga no se puede pasar a JSON (texto que no es UTF-8 válido).
  Es la única excepción propia.

Como el token caduca a los 120 s y vale un solo uso, hay que firmarlo justo antes de cada `pedir()`
y no reutilizarlo en un reintento.

## 3. `pedir()`: qué devuelve

Hace un `POST` con el `\curl` de Moodle (`lib/filelib.php`) y estas cabeceras: `Content-Type:
application/json` y `Accept: application/json`. El cuerpo es `json_encode($cuerpo)`, sin
redirecciones (`CURLOPT_FOLLOWLOCATION = 0`), con `CURLOPT_TIMEOUT` igual a `$espera` (mínimo 1) o 15
y `CURLOPT_CONNECTTIMEOUT` igual a 8.

**Devuelve siempre un array** con cinco claves:

| Clave | Tipo | Contenido |
|---|---|---|
| `errno` | int | `$curl->get_errno()`: 0 si cURL no falló |
| `error` | string | `$curl->error` |
| `http` | int | `http_code` de la respuesta, **0** si no la hubo |
| `datos` | array\|null | el cuerpo decodificado con `json_decode(..., true)` si es un objeto o array JSON; `null` si no lo es |
| `crudo` | string | el cuerpo tal cual |

- **No devuelve cabeceras.** `Retry-After` no llega a quien llama. Épica, en el 429 de la cuota,
  manda la misma cifra también en el cuerpo, en `esperaS` (`backend/src/server.ts`), así que
  se lee en `$respuesta['datos']['esperaS']`.
- **Un 4xx o 5xx de Épica se devuelve, no se lanza.** Viene en `http`, y el cuerpo de un fallo es
  `{ error, motivo, traza?, esperaS? }` en `datos`.
- **Un timeout o un error de cURL tampoco lanza**: sale como `errno` distinto de 0, `error` con el
  texto y, normalmente, `http = 0`. Nuestros plugins lo tratan como pasajero así:
  `!empty($r['errno']) || $r['http'] === 0 || $r['http'] >= 500`.
- `pedir()` no lanza ninguna excepción propia. No comprueba lo que devuelve `json_encode($cuerpo)`:
  si fallara, se mandaría un cuerpo vacío. Esto el código no lo trata, así que el cuerpo tiene que
  ir en UTF-8 válido.

Respuesta buena de `laminas/encargar`: **HTTP 202** con `datos['trabajo']` (el identificador que
se sondea después en `laminas/encargo`) y `datos['posicion']` (cuántos hay delante en la cola). Es lo
que comprueba nuestro plugin: `http === 202 && !empty(datos['trabajo'])`.

## 4. Ejemplo mínimo: firmar y encargar una lámina desde un bloque

```php
use local_awkepica\epica;

global $USER, $COURSE;

if (!epica::configurado()) {
    // El sitio no tiene puesto el código de plataforma o el secreto.
    return;
}
if (empty($USER->email)) {
    // Sin correo no hay token que firmar.
    return;
}

$contexto = \context_course::instance($COURSE->id);

$token = epica::firmar_por(
    $USER,
    $contexto,
    'block/pulso:LA_VUESTRA',      // vuestra capacidad: de ella sale docente/estudiante
    (string) $COURSE->id           // el id, como cadena. NO el objeto del curso
);

$r = epica::pedir(epica::api_url('laminas/encargar'), [
    'token'       => $token,
    'herramienta' => 'infografias',   // sin este campo, Épica comprueba el contrato de la Factoría
    'peticion'    => 'Infografía sobre el ciclo del agua',
    'formato'     => 'poster_2_3',     // poster_2_3 | square | landscape_3_2
    // 'material' => ['nombre' => 'tema.pdf', 'tipo' => 'application/pdf', 'datos' => base64_encode($pdf)],
], epica::ESPERA_LARGA_S);            // la larga si va material; sin material basta con omitirla

if ($r['errno'] || $r['http'] === 0 || $r['http'] >= 500) {
    // Red, timeout o Épica caída: reintentable, con un token NUEVO.
} else if ($r['http'] === 202 && !empty($r['datos']['trabajo'])) {
    $trabajo = $r['datos']['trabajo'];   // se sondea en epica::api_url('laminas/encargo')
} else {
    // 4xx: $r['datos']['motivo'], ['error'] y, si es 429, ['esperaS'].
}
```

El contrato completo del cuerpo de `laminas/encargar` (las cuatro formas, `contexto`, material como
texto extraído, `curso`/`alumno`, cuotas) no está en `local_awkepica`, sino en `docs/infografias.md`
y en `docs/pulso-ai-respuesta.md`.
