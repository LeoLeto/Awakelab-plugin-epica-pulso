# Épica · carta 12 a Pulso AI · los retos corregidos, en el historial

> **Dónde vive esto.** Duodécima vuelta con **Pulso AI** (`block_pulso`). Es una **petición nuestra**:
> que el botón de abrir un reto pase por la misma entrada que «Mi historial». **No trae ninguna puerta
> nueva**: añade un campo opcional, `destino`, a `POST /api/auth/alumno` (carta 10), y **corrige lo
> que os dijimos allí** de que no había destino que pasar. No cambia ningún sobre de `/api/moodle/*`.
> El contrato entero está en `docs/historial-alumno.md` §1.1 y §5. La migración es la `020`. Las
> referencias son de `dev` a 06-10-2026.
>
> **Pendiente de desplegar en el QA.** Os avisamos cuando esté.

---

**Para Marcos (Pulso AI).**

Contacto Épica: Chema · jose.piquero@awakelab.dev
Fecha: 06-10-2026

> **En dos líneas:** el historial del alumno en Épica enseña ya **los retos que ha contestado, con la
> nota y la corrección**, y el alumno lo encuentra igual con cualquier token suyo y desde cualquier
> navegador.
>
> **Lo que os pedimos es cambiar un enlace por un formulario.** Donde hoy le dais al alumno el
> `enlace` de un reto, firmad un token y autoenviadlo a `/api/auth/alumno` con
> `destino: "/reto/<código>"`, igual que «Mi historial». Sin eso el reto funciona como hoy, pero
> anónimo: Épica no sabe de quién es la corrección.

---

## 1. Qué cambia para el alumno

- **Épica guarda al alumno** la primera vez que firmáis un token suyo, por cualquier puerta —una
  lámina, un reto, su historial—, y lo reencuentra con cada token siguiente del mismo `sub`. Del
  alumno se guarda **el `sub` y nada más**, como hasta ahora: ni nombre ni correo, y sigue sin cuenta.
- **Si abre un reto con su sesión**, lo que conteste y su corrección quedan en **su** historial, y
  el reto es el mismo en el ordenador del aula y en el de su casa. El historial enseña, por cada
  reto, la nota y el nivel de la **última** corrección y un botón para verla entera.
- **También los retos de su curso** que abra así —elegidos por un compañero o por el profesor—, como
  «De tu curso». Cada alumno tiene su propio intento: nadie ve las respuestas de otro.

## 2. El botón «Abrir el reto»

En la misma pestaña nueva que ya abrís para «Mi historial», con un campo más:

```php
use local_awkepica\epica;

$token = epica::firmar_por($USER, $contexto, 'block/pulso:createactivity', $curso);
epica::autoenviar(epica::url('/api/auth/alumno'), [
    'token'   => $token,
    'destino' => '/reto/' . $codigo,
]);
```

- **`$codigo`** es el de 32 caracteres que os devuelve `retos/elegir` (`codigo`), o el de cada reto
  de `retos/curso`. Si solo tenéis el `enlace`, vale su ruta: `parse_url($enlace, PHP_URL_PATH)`.
- **Un token nuevo por clic**, como siempre: 120 segundos y un solo uso.
- **Solo para alumnos.** Un token de docente en esta entrada sigue siendo **403**; al profesorado
  dadle el `enlace` a secas, como hasta ahora.

### `destino`, sus tres formas

| `destino` | Adónde llega |
|---|---|
| (sin el campo) o `/mis-recursos` | Su historial, como en la carta 10 |
| `/reto/<código>` | Ese reto, con su sesión: lo que conteste va a su historial |
| `/reto/curso/<llave>` | La lista de retos de su curso (la `enlace` de `retos/curso`); cualquier reto que abra desde ahí también va a su historial |

**No es una URL libre**, por lo mismo que os contamos en la carta 10: son tres formas cerradas, y
cualquier otra cosa —una URL completa, otro dominio, una ruta distinta— **se ignora** y el alumno
llega a su historial.

### Si la entrada falla

Un token caducado, un secreto rotado: **el alumno llega al reto igual**, sin identificar y con un
aviso de que lo que haga no se guardará en su historial. El reto se abre por su código, y no tiene
sentido que se quede sin hacerlo por algo que se arregla volviendo a pulsar. Con `Accept:
application/json` la respuesta es la de siempre, más el `destino` al que habría ido.

## 3. Lo que no cambia

- **El `enlace` a secas sigue funcionando**, sin token y sin sesión, como desde la carta 7. Es lo
  que hay que darle a un profesor, y lo que verá un alumno si el botón no está.
- **«Mi historial»** no cambia.
- **Las cinco puertas de `/api/moodle/retos/*`** no cambian.
- **El `sub` tiene que ser estable** —el `id` de Moodle, nunca el `username`—, que ya os pedimos para
  la cuota. Ahora es también lo que hace que el alumno se reencuentre.

## 4. Lo que necesitamos que nos contestéis

1. **¿Dónde enseñáis hoy el enlace de un reto** en el chat —al elegirlo, en la lista del curso, los
   dos—? Es donde iría el botón.
2. **¿Queréis el `destino` de la lista del curso**, o solo el de cada reto?
3. **¿Os vale que, si el token falla, el alumno llegue al reto sin identificar?** La alternativa es
   mandarlo a su historial con el error, y entonces se queda sin el reto.
