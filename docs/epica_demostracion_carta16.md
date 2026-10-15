# Épica · carta 16 a Pulso AI · el modo demostración de un centro

> **Dónde vive esto.** Decimosexta vuelta con **Pulso AI** (`block_pulso`). Es un **aviso nuestro**:
> desde `/admin` podemos dejar a un centro **24 horas sin cupos de uso**, y queremos que Pulse pueda
> soltar los suyos durante ese rato. **No hay puertas nuevas** y las que ya llamáis siguen
> contestando igual: lo único que cambia es un **campo nuevo**, `demostracion`, en la respuesta de
> `POST /api/moodle/tema`. La lógica está en `backend/src/cuotas.ts`, el campo en
> `backend/src/tema-pulse.ts` y la migración es la `022`. Las referencias son de `dev` a 15-10-2026.

---

**Para Marcos (Pulso AI).**

Contacto Épica: Chema · jose.piquero@awakelab.dev
Fecha: 15-10-2026

> **En dos líneas:** cuando enseñamos Épica a un centro no queremos que la demostración se pare con
> un «habéis llegado al cupo», así que hemos añadido un **modo demostración**: 24 horas sin nuestros
> cupos para ese centro.
>
> **Si vuestros topes de curso siguen puestos, cortan ellos**, así que os decimos en la puerta del
> tema si el centro está en demostración y hasta cuándo, para que podáis soltar los vuestros a la vez.

---

## 1. Qué es el modo demostración

- **Lo enciende alguien de Awakelab desde nuestro panel**, centro a centro. Ni el centro ni Pulse
  pueden encenderlo.
- **Dura 24 horas y se apaga solo.** No guardamos un «sí/no» sino una fecha de fin: no hace falta que
  nadie se acuerde de apagarlo después de la reunión.
- **Se puede terminar antes**, y también **alargar**: pulsar otra vez vuelve a poner 24 horas desde
  ese momento.

## 2. Qué cambia en nuestras puertas mientras dura

| Freno | Fuera de la demostración | En demostración |
|---|---|---|
| **Cupo del centro**: 100 láminas, 50 juegos y 100 retos al día | 429 `cuota-del-centro` | **No topa** |
| **Cuota por persona**: 3 cada 10 minutos, solo a quien firma como `estudiante` | 429 `cuota-agotada` | **No topa** |
| **Correcciones de un reto**: 5 por intento y 200 por reto | 429 en la página del reto | **No topa** |

Tres cosas que **no** cambian:

- **El sobre y el resto de la tabla de fallos.** Un token caducado sigue siendo un 401, un material
  ilegible sigue siendo un 400, y así todo lo demás: solo dejan de salir los tres 429 de arriba.
- **Seguimos contando.** Lo gastado durante la demostración cuenta para el cupo del día. Si la
  demostración acaba a media tarde y el centro ya lleva 140 láminas, el resto del día es un 429
  `cuota-del-centro`. Es lo mismo que haría vuestro código con cualquier 429 que os llegue.
- **El ritmo de la cola.** Seguimos generando con la misma concurrencia. Sin cuota por persona, una
  clase entera pulsando a la vez **espera más**, pero no recibe un error. En la respuesta del sondeo
  veréis la posición en la cola, como siempre.

## 3. Cómo os enteráis: el campo `demostracion` en la puerta del tema

Es la puerta que ya llama vuestra tarea programada cada hora (carta 11). La respuesta gana un campo
**al lado de `tema`**. Así contesta un centro en demostración:

```json
{
  "plataforma": { "codigo": "sanase-test", "nombre": "Sanase Test" },
  "tema": null,
  "demostracion": { "hasta": "2026-10-16T13:16:42.512Z" }
}
```

| Campo | Qué es |
|---|---|
| `demostracion` | **`null`** si el centro no está en demostración. Así contestarán casi todos, casi siempre |
| `hasta` | Cuándo se apaga, en ISO 8601 y en UTC. **Siempre está en el futuro** cuando el campo llega: una demostración ya caducada contesta `null` |

`tema` y `demostracion` **no dependen el uno del otro**: un centro puede tener colores y no estar en
demostración, o al revés, como en el ejemplo.

## 4. Lo que os proponemos hacer

1. **Guardad `hasta` con lo demás que guarda la tarea**, y borradlo cuando llegue `null`.
2. **Al comprobar vuestro tope de curso, mirad la hora contra lo guardado**: si `hasta` todavía no ha
   pasado, el centro está en demostración. Así **volvéis a vuestro tope en cuanto acaba**, sin esperar
   a que pase la tarea.
3. **Lo que hagáis con vuestro tope es cosa vuestra**: quitarlo del todo, multiplicarlo… Lo único que
   os pedimos es que, mientras dure, no corte antes que nosotros.
4. **El botón «Sincronizar con Épica» sirve igual para esto.** Quien vaya a hacer la demostración
   puede pulsarlo justo después de que la encendamos y no esperar hasta una hora.

Con `local_awkepica`, sobre el código de la carta 11:

```php
$r = epica::pedir(epica::api_url('tema'), ['token' => $token]);
if ($r['http'] === 200) {
    $demo = $r['datos']['demostracion'] ?? null;
    $hasta = ($demo && !empty($demo['hasta'])) ? strtotime($demo['hasta']) : 0;
    set_config('demostracion_hasta', (int) $hasta, 'block_pulso');
}

// Al comprobar el tope del curso:
$endemostracion = (int) get_config('block_pulso', 'demostracion_hasta') > time();
```

Un fallo, un timeout o un `http` 0 **no tocan lo guardado**, como con los colores (carta 11 §4).

### Lo que puede pasar

| Pasa | Qué ocurre |
|---|---|
| La terminamos antes de `hasta` | Os enteráis en la siguiente sincronización. Mientras tanto vuestro tope sigue suelto y el nuestro ya ha vuelto, así que el que puede cortar es un 429 `cuota-del-centro` nuestro, que ya sabéis pintar |
| La alargamos | La siguiente sincronización os trae el `hasta` nuevo |
| Aún no hemos publicado esto | El campo no llega. Leedlo con `?? null` y no cambia nada |

## 5. Compatibilidad

**Es un campo nuevo y nada más.** Si vuestro código ignora los campos que no conoce, hasta que lo
uséis todo funciona igual. Ninguna clave cambia de nombre, ningún código de fallo cambia y no hay
nada nuevo que firmar.

## 6. Cuándo lo tenéis delante

**Está en `dev` y todavía no se ha publicado**: ni en el QA (`entorno-qa-2.awakelab.world`) ni en
producción. Lleva migración (la `022`), así que va con la siguiente publicación. **Os avisamos el
día que el QA lo tenga**, y desde ese día podemos poner vuestro centro de pruebas en demostración
cuando nos lo pidáis.

## 7. Lo que necesitamos que nos contestéis

1. **¿Os vale enteraros por la puerta del tema**, o preferís que el campo llegue también en otro
   sitio, como la respuesta de cada encargo?
2. **¿Qué vais a hacer con vuestro tope** durante la demostración? Es para escribirlo en nuestro
   contrato y no contaros algo distinto a lo que hacéis.
3. **¿Queréis un correo cada vez que pongamos un centro vuestro en demostración?** Con el campo no
   hace falta, pero si lo preferís lo hacemos.

## 8. Para probarlo

La petición **01 de la carpeta `tema/`** de Bruno ya comprueba el campo: que llegue, y que sea `null`
o un `hasta` en el futuro.

```
npx --yes @usebruno/cli@4.1.0 run tema -r --env qa --env-var secreto="EL_SECRETO"
```

Hasta que el QA lo tenga, ese test sale en rojo, porque el campo no llega todavía. Para ver un
`hasta` de verdad, pedidnos que pongamos vuestro centro de pruebas en demostración.
