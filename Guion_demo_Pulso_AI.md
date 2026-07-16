# Guion de demostración — Pulso AI

**Plugin de Moodle · asistente de analítica y contenido con IA**
Versión mostrada: 1.1.6 · Duración estimada: 10–15 min

---

## 0. Antes de empezar (preparación, no se enseña)

- Ten abierto un **curso con datos reales**: con notas de un cuestionario, alumnos matriculados y al menos un **PDF de texto** (p. ej. una hoja de problemas o unos apuntes).
- Abre el chat de Pulso AI (el globo flotante). Comprueba que arriba pone **v1.1.6**.
- **Empieza en chat limpio** (Ctrl+F5) al cambiar de bloque temático, para que las respuestas salgan nítidas.
- Al nombrar un recurso, usa su **nombre completo** tal cual aparece en el curso.
- Ten a mano el curso donde el botón **"En riesgo"** dé resultados (alumnos con progreso bajo).

---

## 1. Qué es (30 segundos de pitch)

> "Pulso AI es un asistente dentro del propio curso de Moodle. El profesor o tutor le pregunta en lenguaje natural y responde al instante sobre **dos cosas**: la **analítica del curso** (notas, participación, alumnos en riesgo) y el **contenido** (resúmenes, dudas sobre los materiales). Sin salir del curso, sin exportar datos, sin saber SQL."

---

## 2. Conoce el curso (estructura y resumen)

**Escribe:**
- `¿Qué contenidos hay en el curso?`
- `Resúmeme el curso`

**Qué verá el cliente:** el listado completo de secciones y actividades, y un resumen claro del curso.

**Frase de apoyo:** *"Lo primero que hace es entender el curso: su estructura y de qué trata. Útil para un vistazo rápido o para presentárselo a un alumno nuevo."*

---

## 3. Analítica del curso (el punto fuerte — dedícale tiempo)

**Escribe, una a una:**
- `¿Cuántos alumnos hay matriculados?`
- `¿Cuál es la nota media del curso?`
- `¿Qué porcentaje de aprobados hay en el curso?`
- `¿Cuántos alumnos han terminado el curso completo?`

**Qué verá el cliente:** respuestas con **número claro + tabla + insights + recomendaciones**, y botones para **exportar a Excel / CSV**.

**Frase de apoyo:** *"Aquí está el valor: preguntas en español y te da la métrica, la interpreta y sugiere acciones. Y si quieres, te lo llevas a Excel con un clic."*

### Alumnos en riesgo (con botón — muy visual)

**Haz clic en el botón `En riesgo`** (barra inferior del chat).

**Qué verá el cliente:** lista de alumnos en riesgo con **motivo, progreso, calificación y acción recomendada** por alumno.

**Frase de apoyo:** *"Detección temprana: te dice quién puede abandonar y qué hacer con cada uno. Esto es lo que ahorra tiempo al tutor."*

> *(Menciona los otros botones: Completitud, Notas, Engagement — misma idea, un clic.)*

---

## 4. Métricas de una actividad concreta

**Escribe:**
- `¿Cuántas preguntas tiene el cuestionario [NOMBRE COMPLETO DEL CUESTIONARIO]?`
- `¿Cuál es la nota media del cuestionario [NOMBRE COMPLETO]?`

**Qué verá el cliente:** datos exactos de esa actividad (nº de preguntas, media, intentos, completados).

**Frase de apoyo:** *"No solo el curso en conjunto: puede bajar al detalle de cualquier actividad concreta."*

---

## 5. Entiende el contenido de los materiales (IA + lectura de PDF)

**Con un PDF de texto en el curso, escribe:**
- `Dame el enunciado del primer problema del [NOMBRE DEL PDF]`
- (seguimiento) `Hazme un resumen de ese pdf`
- (seguimiento) `¿Qué dice ese pdf sobre [un tema del documento]?`

**Qué verá el cliente:** el asistente **lee el PDF** y responde sobre su contenido; y en el seguimiento entiende que "ese pdf" es el de antes.

**Frase de apoyo:** *"Además de datos, entiende los materiales: resume un documento, saca un enunciado, responde dudas sobre el contenido. Y mantiene el hilo de la conversación."*

---

## 6. En cualquier idioma (opcional, efecto bonito)

**Escribe:**
- `What is this course about?`

**Qué verá el cliente:** responde en inglés, en el idioma de la pregunta.

**Frase de apoyo:** *"Responde en el idioma en que le hablas — útil para centros con alumnado internacional."*

---

## 7. Cierre — para qué sirve, en una frase

> "En resumen: Pulso AI convierte el curso de Moodle en algo que puedes **preguntar**. Ahorra tiempo al profesor en el seguimiento (notas, riesgo, participación) y ayuda al alumno con el contenido. Todo dentro del curso, con la información del propio Moodle, y exportable cuando hace falta."

**Beneficios a subrayar:**
- Cero curva de aprendizaje: se pregunta en lenguaje natural.
- Analítica accionable (no solo números: insights y recomendaciones).
- Detección de alumnos en riesgo.
- Comprensión de los materiales (resúmenes, dudas).
- Exportación a Excel/CSV.
- Multiidioma.

---

## Anexo — Qué NO preguntar en la demo (para evitar fallos en directo)

Estas funciones están en desarrollo; mejor no mostrarlas todavía:

- Referencias por **posición**: "dame el primero", "resume el segundo" (en curso de arreglo).
- Contenido de archivos **Office** (.docx / .pptx): aún no lee su interior.
- Contenido de **foros** y de **PDF escaneados** (sin texto).
- "**Qué contiene** el recurso X" (a secas): responde con los datos del archivo, no el contenido — usa mejor "dame el enunciado de…" o "hazme un resumen de…".
- Nombres de actividad **muy cortos** o **rankings** de alumnos por nombre.

**Regla de oro para la demo:** si una respuesta sale rara, pulsa **Ctrl+F5** (limpia el contexto) y repite la pregunta con el **nombre completo** del recurso.
