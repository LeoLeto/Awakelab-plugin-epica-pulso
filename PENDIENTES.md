# Pendientes de Pulse AI (tras v2.3.2 — 07-10-2026)

## En cola (cartas de Épica, por hacer)
- [x] **Carta 11 — colores de cada centro** (v2.1.0, hecho en código; falta probarlo en sanase-test con un tema real). **Contestar a Chema las 4 preguntas de §6**: variables `principal` y `acento` y para qué las usamos; `texto` nos sirve pero lo comprobamos y, si no da 4,5:1, lo calculamos; sin modo oscuro (mismo color); tarea cada hora + botón. Avisarle de que su acento de ejemplo `#0fced3` (1,7:1 sobre blanco) lo ignoramos, y de que pedimos acentos con >= 3:1 sobre blanco.
- [x] **Carta 12 — retos con la sesión del alumno** (v2.2.0, hecho en código; falta probarlo con Épica en QA cuando lo despliegue y con una cuenta de alumno real). **Contestar las 3 preguntas de §4**: (1) el enlace del reto se enseña al elegirlo, en «Mis creaciones» y en «Ver todos los retos del curso»; (2) sí queremos el `destino` de la lista del curso; (3) sí nos vale que, si el token falla, el alumno llegue al reto sin identificar. Avisarles de que firmamos con `viewanalytics` (no `createactivity`) y de que el profesorado sigue con el enlace a secas.
- [ ] **Pasar a Chema `docs/epica_tema_claves_v2.md` y pedir que añada las claves (`cabecera`, `boton`, `burbuja`) a su catálogo y editor.** Después, ver en sanase-test el tema `#ff6a00` (cabecera `#c75300` con texto blanco) y la vista previa (`/blocks/pulso/tema_preview.php`), con un tema oscuro, uno amarillo y uno cian claro. Al desplegar v2.5.0, purgar cachés (plantilla + CSS).

## Pendientes técnicos
- [x] **Privacy provider (RGPD)** (v2.3.0, hecho en código; falta verificar en sanase-test: Registro de privacidad de plugins y una exportación de datos de una persona de prueba; y ejecutar `tests/privacy/provider_test.php` en un Moodle).
- [x] **429 `cuota-del-centro`** (v2.3.1, hecho en código): terminal a la primera; el resto de 429 reintenta con tope de 6 h; la notificación traduce los códigos. Falta verlo con un 429 real de Épica en QA.
- [ ] **SCORM (P55/P56)**: indexar el contenido de los SCORM y el modo «resumen/explicación de unidad».
- [x] **Propuesta madre de «Proponer otros»** (v2.3.2, hecho en código): `padreid` + `NOT EXISTS` de hija viva. Las filas anteriores siguen saliendo hasta caducar (≤ 7 días). Al desplegar, Notificaciones.
- [x] **`confirm()` de «Nueva conversación»** (v2.3.3, hecho en código): barra de confirmación dentro del chat. Al desplegar, purgar cachés.

## Fuera del plugin
- [x] **Los datos que Épica guarda del alumno (historial por `sub`) no se borran desde Moodle** (v2.4.0, carta 14, hecho en código): tarea adhoc `epica_borrar_alumno_adhoc` desde `delete_data_for_user()` (gancho: contexto de usuario) y desde `user_deleted`. Al desplegar, Notificaciones. Falta probarlo en sanase-test con una solicitud de borrado real y **contestar a Chema §5: un alumno por llamada nos vale** (y contarle que el borrado de la cuenta va por el evento `user_deleted`).
- [ ] **Google Fonts del tema Moove**: lo pide el tema, no Pulse; quitarlo de la configuración del tema si se quiere cero terceros.
- [ ] Restringir la clave de YouTube por IP del servidor y rotar el secreto de `local_awkepica`.
- [ ] Actualizar las guías de profesorado y alumnado al diseño 2.0 (pestañas, Mis creaciones).
- [x] Probar «Mi historial en Épica» con una cuenta de alumno real (Chema lo comprobó el 07-10-2026: láminas, juegos y retos salen en su historial; el fallo era el «Cambiar rol»).
