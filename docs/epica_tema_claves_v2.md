# Pulse → Épica · claves nuevas del tema (respuesta a la carta 11)

**Para Chema (Épica).** De Marcos (Pulso AI). Pulse v2.5.0.

> **En dos líneas:** el formato de `POST /api/moodle/tema` **no cambia**; las claves nuevas van dentro
> de `colores`, como las dos de ahora. Os pedimos añadirlas a vuestro catálogo y al editor del panel.

## Las cinco claves

Todas **opcionales**. Cada una con `valor` (`#rrggbb`, minúsculas) y, si queréis, `texto` (`#ffffff` o
`#000000`), igual que `principal` y `acento` hoy.

| Clave | Qué pinta en Pulse (para el texto del panel de Épica) |
|---|---|
| `principal` | El color de la marca del centro. **Base de la que se deriva todo lo que no mandéis** |
| `acento` | Foco del teclado, bordes e iconos, indicador de la pestaña activa |
| `cabecera` | Fondo de la cabecera del chat y de la burbuja flotante |
| `boton` | Botones de acción, filtros y pestañas activos, cabecera de las tablas |
| `burbuja` | Los mensajes que escribe la persona |

## Cómo se resuelven

- **Basta con `principal`.** Lo que no mandéis (`cabecera`, `boton`, `burbuja`) lo derivamos de él.
  Si mandáis `cabecera`, `boton` o `burbuja`, respetamos ese color tal cual: es vuestra elección.
- **Comprobamos el contraste nosotros** (WCAG 2.x) y ajustamos si hace falta:
  - Las superficies grandes que derivamos del `principal` se **oscurecen sin cambiar el tono** lo justo
    para que el texto blanco se lea (4,5:1). Un naranja `#ff6a00` sale como un naranja tostado
    (`#c75300`) con letras blancas, no como un naranja chillón con letras negras. Un principal ya
    oscuro (`#003670`) queda igual.
  - Si mandáis `cabecera`, `boton` o `burbuja` explícitos, usamos vuestro `texto` si da 4,5:1 sobre el
    fondo; si no, el blanco o el negro que mejor se lea.
  - El `acento` que mandéis se usa si da 3:1 sobre blanco, sobre la cabecera y sobre el botón; si no, lo
    ignoramos y usamos uno derivado del `principal`. Un cian claro como `#0fced3` no pasa nunca.
- **Un color de centro nunca es texto sobre fondo blanco** en Pulse (es siempre un fondo o un detalle).
- Una clave que no esté en esta lista se ignora, y un valor que no sea `#rrggbb` también.
- Sin ninguna clave válida, Pulse se ve como siempre.

## Vista previa

En el administrador del sitio: **Administración del sitio → Plugins → Bloques → Pulse AI → «Vista
previa de los colores del centro»** (`/blocks/pulso/tema_preview.php`). Enseña Pulse por defecto y con
los colores del centro, una tabla con cada variable resuelta (valor, contraste, si es vuestra o
derivada) y un formulario para probar colores sin guardar nada.

## Lo que os pedimos

1. **Añadir las cinco claves a vuestro catálogo** y al editor del panel, con el texto de la tabla de
   arriba como ayuda para quien elige el color.
2. Que `principal` sea la que se pida primero: con ella sola Pulse queda bien.
