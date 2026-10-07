# Pendientes de Pulse AI (tras la carta 12, v2.2.0 — 07-10-2026)

## En cola (cartas de Épica, por hacer)
- [x] **Carta 11 — colores de cada centro** (v2.1.0, hecho en código; falta probarlo en sanase-test con un tema real). **Contestar a Chema las 4 preguntas de §6**: variables `principal` y `acento` y para qué las usamos; `texto` nos sirve pero lo comprobamos y, si no da 4,5:1, lo calculamos; sin modo oscuro (mismo color); tarea cada hora + botón. Avisarle de que su acento de ejemplo `#0fced3` (1,7:1 sobre blanco) lo ignoramos, y de que pedimos acentos con >= 3:1 sobre blanco.
- [x] **Carta 12 — retos con la sesión del alumno** (v2.2.0, hecho en código; falta probarlo con Épica en QA cuando lo despliegue y con una cuenta de alumno real). **Contestar las 3 preguntas de §4**: (1) el enlace del reto se enseña al elegirlo, en «Mis creaciones» y en «Ver todos los retos del curso»; (2) sí queremos el `destino` de la lista del curso; (3) sí nos vale que, si el token falla, el alumno llegue al reto sin identificar. Avisarles de que firmamos con `viewanalytics` (no `createactivity`) y de que el profesorado sigue con el enlace a secas.

## Pendientes técnicos
- [ ] **Privacy provider (RGPD)**: el plugin no tiene `classes/privacy/provider.php`; sin metadata/export/borrado de las tablas con `userid`, ni limpieza al borrar un curso.
- [ ] **429 `cuota-del-centro`**: el encargo se queda en «pendiente» sin explicación; cortar tras un tope de reintentos/tiempo para que salga el mensaje que ya existe.
- [ ] **SCORM (P55/P56)**: indexar el contenido de los SCORM y el modo «resumen/explicación de unidad».
- [ ] **Propuesta madre de «Proponer otros»**: sigue saliendo como «Lista para elegir» en Mis creaciones hasta que caduca (falta enlace padre→hija).
- [ ] **`confirm()` de «Nueva conversación»**: cambiarlo por un aviso dentro del chat.

## Fuera del plugin
- [ ] **Google Fonts del tema Moove**: lo pide el tema, no Pulse; quitarlo de la configuración del tema si se quiere cero terceros.
- [ ] Restringir la clave de YouTube por IP del servidor y rotar el secreto de `local_awkepica`.
- [ ] Actualizar las guías de profesorado y alumnado al diseño 2.0 (pestañas, Mis creaciones).
- [ ] Probar «Mi historial en Épica» con una cuenta de alumno real.
