# Épica · carta 14 a Pulso AI · el «Cambiar rol», `viewanalytics` y borrar a un alumno

> **Dónde vive esto.** Decimocuarta vuelta con **Pulso AI** (`block_pulso`). Contesta a vuestra
> respuesta a la carta 13 y a los dos pendientes que nos recordasteis. Trae **una puerta nueva**,
> `POST /api/moodle/alumno/borrar`, y no cambia ninguna de las que ya llamáis. El contrato está en
> `docs/historial-alumno.md` §4 y §5.4, y la lógica en `backend/src/historial.ts`
> (`borrarAlumnoPorPuerta`). Sin migración. Las referencias son de `dev` a 08-10-2026.
>
> **Desplegado en el QA el 08-10-2026** (`dev` en `3360b2e`): la puerta ya contesta en
> `entorno-qa-2.awakelab.world`.

---

**Para Marcos (Pulso AI).**

Contacto Épica: Chema · jose.piquero@awakelab.dev
Fecha: 08-10-2026

> **En dos líneas:** el fallo de la carta 13 era el «Cambiar rol», como decíais: con la cuenta de
> alumno de verdad ya sale todo en su historial. Tenéis razón con `viewanalytics`, y ya están
> corregidas las cartas.
>
> **Para el borrado, os damos una puerta**: la llamáis desde vuestro proveedor de privacidad cuando
> en Moodle se apruebe una solicitud, y borra en el momento todo lo que Épica guarda de ese alumno.
> **Ya está en el QA**: la podéis probar hoy.

---

## 1. La carta 13: era la prueba

Vuestra explicación cuadra con todo lo que vimos. En la **petición web**, `has_capability` mira la
sesión, y la sesión lleva el «Cambiar rol a… Estudiante»: el reto sale con `estudiante`. En la
**tarea adhoc** no hay sesión, así que la ficha del administrador se evalúa con sus roles de verdad:
un administrador del sitio tiene todas las capacidades y sale `docente`, con el mismo `sub` y el
mismo `iss`. El reto entró en el historial y la lámina y el juego no. **No hay nada que cambiar ni
en vuestro lado ni en el nuestro.**

**Comprobado el 07-10-2026 con una cuenta de alumno de verdad**: las láminas, los juegos y los retos
salen en su historial.

**Y una consecuencia para vuestras pruebas en general:** el «Cambiar rol» no vale para nada que
pase por una tarea. Con él, las láminas y los juegos de «alumno» salen como del profesorado, sin
historial y **sin cuota**. Como alumno de verdad, la cuota sí se nota: **3 láminas y 3 juegos cada
10 minutos** (cartas 2 y 5), así que un `429 cuota-agotada` probando seguido es lo correcto.

## 2. `viewanalytics`: tenéis razón

La capacidad es vuestra: **Épica no la mira**, solo lee el `rol` del token. Con `createactivity`,
que el alumnado también tiene, todo alumno saldría `docente`. Hemos corregido el ejemplo en las
**cartas 10, 11, 12 y 13**, con una nota en la cabecera de cada una.

Lo único que hay que vigilar con cualquier capacidad es el otro lado: **todo el profesorado tiene
que tenerla en sus cursos**. Un profesor sin `viewanalytics` firmaría como `estudiante`. Sus
encargos contarían en la cuota de alumno, publicar le daría un 403 y entraría a un historial en
lugar de a Épica. Si hay profesores sin permiso de edición en algún curso, comprobad que la tengan.

## 3. Borrar los datos de un alumno: `POST /api/moodle/alumno/borrar`

Hasta hoy la única forma era que nos lo pidierais y lo borráramos nosotros con un guion. Eso deja
cada solicitud esperando a una persona, y en Moodle la solicitud ya tiene su circuito: la
**API de privacidad** (`tool_dataprivacy`). Así que os damos una puerta para llamarla desde vuestro
proveedor, en `delete_data_for_user` y `delete_data_for_users`, cuando la solicitud se apruebe.

### La llamada

```php
use local_awkepica\epica;

$token = epica::firmar_por(get_admin(), \context_system::instance(), 'block/pulso:viewanalytics');
$r = epica::pedir(epica::api_url('alumno/borrar'), [
    'token'      => $token,
    'sub_alumno' => (string) $userid,
]);
// $r['http'] === 200 y $r['datos']['habia_datos'] dice si había algo que borrar.
```

- **`sub_alumno`** es el `id` de Moodle del alumno, **el mismo que va en el `sub` de sus tokens**.
  Mejor como texto; un entero también vale.
- **El token lo firma el centro, como docente.** Con `get_admin()` y el contexto del sistema sale
  `docente`, que es lo que pide la puerta. **Un token de estudiante contesta 403**: un alumno no
  borra el historial de nadie, ni siquiera el suyo, porque en Moodle esa solicitud la aprueba otra
  persona.
- **El centro sale del `iss`**, como siempre: un Moodle no puede borrar a los alumnos de otro.
- **Un token nuevo por llamada**, como siempre: 120 segundos y un solo uso, y nunca el mismo en un
  reintento.
- **No comprueba el contrato de ninguna herramienta.** Borrar no gasta, y un centro que ya no tenga
  una herramienta tiene que poder borrar lo que se guardó con ella.
- **Un alumno por llamada.** Para `delete_data_for_users`, una llamada por cada `userid` de la lista.

### La respuesta

```json
{
  "plataforma": { "codigo": "sanase-test", "nombre": "…" },
  "habia_datos": true,
  "borrado": { "historial": 3, "copias": 2, "sesiones": 1, "retos_contestados": 1 }
}
```

**Un alumno del que no guardamos nada contesta lo mismo, con `habia_datos: false`** y los cuatro
números a cero. No es un 404 a propósito: lo llama una tarea que reintenta, y para ella «no había
nada» es una solicitud cumplida. **Borrar dos veces es seguro.**

| Si | Contesta |
|---|---|
| Todo bien, haya datos o no | **200** con lo de arriba |
| El token es de estudiante | **403 `rol-sin-permiso`**, sin traza |
| Falta `sub_alumno` | **400 `cuerpo-incompleto`**, y la frase lo nombra |
| El token no se puede comprobar | La tabla de siempre, con su `traza` |

### Lo que borra, y lo que no

**Borra**, de ese alumno de ese centro: **su historial** (las láminas, los juegos y los retos que
pidió), **las copias** de sus láminas y sus juegos, **sus sesiones** abiertas y **lo que contestó en
los retos, con sus correcciones**. Es lo mismo que hacía el guion.

**No borra:**

- **Los retos que eligió.** Están en la lista de su curso, que es de toda la clase, y la ficha de un
  reto no dice quién lo eligió.
- **La bitácora de entradas**, que guarda el `sub` de cada entrada y se borra sola a los **90
  días**.
- **Lo que guardéis vosotros** del lado de Pulse.

Y **borrar no es vetar**: si ese `sub` vuelve a mandar un token, o termina un encargo que estaba en
marcha, Épica lo vuelve a guardar. De la llamada no queda apuntado el `sub` en ningún sitio:
guardarlo para decir que se borró sería no haberlo borrado.

**No hay borrado por curso.** El historial de un alumno es de todos sus cursos de ese centro, así
que `delete_data_for_all_users_in_context` no tiene nada que llamar en Épica.

### Para probarlo

La carpeta `historial/` de Bruno gana cuatro peticiones, de la **08** a la **11**: un token de alumno
recibe 403, sin `sub_alumno` es un 400, el centro borra al alumno 99010 —que entró en la 01— y
borrarlo otra vez es un 200 sin datos.

```
npx --yes @usebruno/cli@4.1.0 run historial -r --env qa --env-var secreto="EL_SECRETO"
```

**La 10 borra de verdad** al 99010 de ese centro. Es el alumno de prueba de la colección, y la
siguiente pasada lo vuelve a crear en la 01.

## 4. Lo que no cambia

- **Ninguna de las puertas que ya llamáis.** Los encargos, el historial y el tema siguen igual.
- **El guion sigue existiendo** para lo que no llegue por Moodle: un correo nos vale igual.

## 5. Lo que necesitamos que nos contestéis

**¿Os vale un alumno por llamada**, o en `delete_data_for_users` os llegan listas tan largas que
preferís mandarlas de una vez?
