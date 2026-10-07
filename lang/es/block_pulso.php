<?php
// Cadenas en castellano. REGLA: toda cadena nueva de lang/en lleva aquí su traducción.
$string['pluginname'] = 'Pulse AI';
$string['pulso:addinstance'] = 'Añadir un nuevo bloque Pulse';
$string['pulso:myaddinstance'] = 'Añadir un nuevo bloque Pulse a la página Mi Moodle';
// Ajustes (T2.1.4)
$string['setanthropickey'] = 'Clave de API de Anthropic (chat)';
$string['setanthropickey_desc'] = 'Introduce tu clave secreta de API de Anthropic (Claude). Se usa exclusivamente para generar las respuestas del chat (analítica y preguntas sobre el contenido).';
$string['setapikey'] = 'Clave de API de OpenAI (solo embeddings de RAG)';
$string['setapikey_desc'] = 'Introduce tu clave secreta de API de OpenAI. Se usa exclusivamente para generar los embeddings del índice de contenido (RAG); ya no se usa para las respuestas del chat.';
$string['setmodel'] = 'Modelo de IA (chat)';
$string['setmodel_desc'] = 'Elige el modelo de Claude que analizará los datos del curso y responderá a las preguntas.';

// === TEXTOS DE LA INTERFAZ DEL CHAT (T2.3.2) ===
$string['chat_title'] = 'Pulse Analytics AI';
$string['chat_subtitle'] = 'Pregúntame lo que quieras sobre tu curso';
$string['chat_welcome'] = '¡Te damos la bienvenida! Soy tu asistente de analítica del curso. Puedo ayudarte a entender las tasas de finalización, la evolución de las notas, la participación del alumnado e identificar a quienes están en riesgo. ¡Pregúntame lo que quieras!';

// === ÁREA DE ENTRADA ===
$string['chat_input_placeholder'] = 'Escribe una pregunta sobre la analítica de tu curso...';
$string['chat_char_counter'] = 'Caracteres';

// === EJEMPLOS (PROMPTS) ===
$string['chat_example_prompts'] = 'Ejemplos de preguntas:';
$string['chip_completion_analysis'] = 'Análisis de finalización';
$string['chip_grade_trends'] = 'Evolución de notas';
$string['chip_engagement_report'] = 'Participación del alumnado';
$string['chip_at_risk_students'] = 'Alumnado en riesgo';

// === CARGA Y ESTADO ===
$string['chat_loading'] = 'Cargando...';
$string['chat_thinking'] = 'Pensando...';

// === ERRORES ===
$string['error_no_apikey'] = 'La clave de API de OpenAI no está configurada. Configúrala en Administración del sitio → Extensiones → Bloques → Pulse AI.';
$string['error_no_apikey_anthropic'] = 'La clave de API de Anthropic no está configurada. Configúrala en Administración del sitio → Extensiones → Bloques → Pulse AI.';
$string['error_no_response'] = 'No se ha recibido respuesta del servicio de IA.';
$string['error_api_error'] = 'Error de la API: {$a}';
$string['error_invalid_course'] = 'Identificador de curso no válido.';
$string['error_no_permission'] = 'No tienes permiso para usar esta función.';
$string['error_timeout'] = 'La petición ha tardado demasiado. Vuelve a intentarlo.';
$string['error_refusal'] = 'La IA ha rechazado responder a esta petición por motivos de política. Prueba a reformular la pregunta.';
$string['error_api_connection'] = 'No se ha podido conectar con el servicio de IA. Vuelve a intentarlo en un momento.';
$string['error_api_response'] = 'El servicio de IA ha devuelto un error: {$a}';
$string['error_empty_response'] = 'El servicio de IA ha devuelto una respuesta vacía. Vuelve a intentarlo.';
$string['error_payload_encoding'] = 'No se ha podido preparar la petición. Empieza una conversación nueva («Nueva conversación») e inténtalo de nuevo.';

// === ACCESIBILIDAD ===
$string['send_message'] = 'Enviar mensaje';
$string['close_alert'] = 'Cerrar aviso';

// === T2.6.1: Activar/desactivar por curso ===
$string['coursecontrol_heading'] = 'Control por curso';
$string['coursecontrol_heading_desc'] = 'Controla si Pulse está activado por defecto en todos los cursos.';
$string['enabled_by_default'] = 'Activado por defecto';
$string['enabled_by_default_desc'] = 'Si está marcado, Pulse estará activo en todos los cursos salvo que se desactive en alguno.';
$string['plugin_disabled_course'] = 'Pulse está desactivado en este curso.';

// === T2.6.2: Permisos de acceso a datos ===
$string['pulso:viewanalytics'] = 'Ver los datos de analítica de Pulse';
$string['pulso:usechat'] = 'Usar el chat de Pulse para preguntas sobre el contenido del curso';

// === Modo alumno (chat solo de contenido) ===
$string['student_analytics_denied'] = 'Esa información solo está disponible para el profesorado de este curso. Si tu pregunta era sobre el contenido del curso, vuelve a hacerla mencionando el material o la sección.';
$string['student_own_grades_denied'] = 'No puedo mostrar calificaciones desde el chat. Puedes consultar las tuyas en el libro de calificaciones del curso. Si tu pregunta era sobre el contenido del curso, vuelve a hacerla mencionando el material o la sección.';
$string['student_analytics_denied_title'] = 'Solo disponible para el profesorado';
$string['dataaccess_heading'] = 'Control de acceso a datos';
$string['dataaccess_heading_desc'] = 'Elige qué categorías de datos están disponibles para el análisis con IA. Desactivar una categoría impide que se envíe a la IA (Anthropic).';
$string['data_completion'] = 'Datos de finalización';
$string['data_completion_desc'] = 'Permitir el acceso a los datos de finalización del curso y de las actividades.';
$string['data_grades'] = 'Datos de calificaciones';
$string['data_grades_desc'] = 'Permitir el acceso al libro de calificaciones y a las puntuaciones de los cuestionarios.';
$string['data_logs'] = 'Registros de acceso';
$string['data_logs_desc'] = 'Permitir el acceso a los registros recientes de acceso de los usuarios.';

// === RAG: generación aumentada con recuperación ===
$string['rag_heading'] = 'Indexación de contenido (RAG)';
$string['rag_heading_desc'] = 'La generación aumentada con recuperación permite a la IA leer y razonar sobre el contenido didáctico real del curso (páginas, tareas, preguntas de cuestionario, libros, wikis). La tarea programada «Indexar el contenido del curso para RAG» debe ejecutarse al menos una vez antes de que esta función trabaje.';
$string['rag_enabled'] = 'Activar la indexación de contenido (RAG)';
$string['rag_enabled_desc'] = 'Si se activa, Pulse generará embeddings del contenido del curso e inyectará los fragmentos relevantes en cada consulta para que la IA pueda responder sobre el material (por ejemplo, explicar un ejercicio o resolver un problema del curso).';
$string['task_index_course_content'] = 'Indexar el contenido del curso para RAG (Pulse AI)';
$string['task_index_course_adhoc'] = 'Indexar un curso bajo demanda para RAG (Pulse AI)';

// === CACHÉS ===
$string['cachedef_coursecontext'] = 'Contexto unificado de analítica del curso que usa el chat de Pulse';

// === Creaciones con Épica (infografías y juegos; v1.18.0) ===
$string['pulso:createactivity'] = 'Pedir una creación (infografía) a Pulse';
$string['creationquota_heading'] = 'Creaciones (Épica) — cupos';
$string['creationquota_heading_desc'] = 'Límites antiabuso de las creaciones enviadas desde Pulse (hoy infografías y juegos). Cuentan las CREACIONES pedidas, no las terminadas: Épica comparte su cola entre herramientas.';
$string['quota_user_section_day'] = 'Por usuario, sección y día';
$string['quota_user_section_day_desc'] = 'Máximo de creaciones que un mismo usuario puede enviar al día para recursos de una misma sección del curso.';
$string['quota_user_course_day'] = 'Por usuario y día, en un curso';
$string['quota_user_course_day_desc'] = 'Máximo de creaciones que un mismo usuario puede enviar al día dentro de un curso.';
$string['quota_course_hour'] = 'Por curso y hora';
$string['quota_course_hour_desc'] = 'Máximo de creaciones que un mismo curso puede generar en una hora móvil.';
$string['quota_course_day_floor'] = 'Por curso y día — mínimo';
$string['quota_course_day_floor_desc'] = 'Mínimo de creaciones diarias permitidas por curso, sea cual sea su número de matriculados (la fórmula real es el mayor entre este mínimo y los matriculados × el multiplicador del ajuste siguiente).';
$string['quota_course_day_multiplier'] = 'Por curso y día — multiplicador por matriculado';
$string['quota_course_day_multiplier_desc'] = 'Se multiplica por los usuarios matriculados en el curso para obtener el tope diario (p. ej. 1,5 con 150 matriculados permite hasta 225 creaciones al día). El límite efectivo es el mayor entre este valor y el mínimo anterior.';
$string['quota_teacher_day'] = 'Por docente y día (todos los cursos)';
$string['quota_teacher_day_desc'] = 'Máximo de creaciones que un mismo miembro del profesorado puede enviar al día, sumando TODOS sus cursos.';

// === Ampliación de recursos: YouTube + OpenAlex (v1.24.0) ===
$string['ampliacion_heading'] = 'Ampliación de recursos — vídeos y artículos';
$string['ampliacion_heading_desc'] = 'Para un recurso del curso, Pulse busca 2 vídeos de YouTube y 2 artículos de OpenAlex sobre su tema. Se genera una ampliación por recurso y versión de su texto, compartida por todo el curso (en caché), porque la búsqueda de YouTube está limitada a unas 100 búsquedas al día para todo el proyecto. A YouTube/OpenAlex solo se envían las consultas generadas, nunca el texto del recurso ni datos de usuarios. Sin clave de YouTube solo se ofrecen artículos, y viceversa.';
$string['youtube_api_key'] = 'Clave de la API de datos de YouTube';
$string['youtube_api_key_desc'] = 'Clave de la API de datos de YouTube v3. search.list cuesta 100 unidades de cuota; videos.list cuesta 1.';
$string['openalex_api_key'] = 'Clave de la API de OpenAlex';
$string['openalex_api_key_desc'] = 'Clave de la API de OpenAlex (obligatoria desde febrero de 2026; el plan gratuito da unas 1.000 búsquedas al día).';
$string['ampliacion_max_dia_sitio'] = 'Ampliaciones nuevas al día (todo el sitio)';
$string['ampliacion_max_dia_sitio_desc'] = 'Máximo de ampliaciones NUEVAS generadas al día en todo el sitio; las de caché no cuentan. Mantenlo por debajo de tu cuota diaria de búsquedas de YouTube (por defecto 80 de ~100).';
$string['ampliacion_max_dia_usuario'] = 'Ampliaciones nuevas por usuario y día';
$string['ampliacion_max_dia_usuario_desc'] = 'Máximo de ampliaciones NUEVAS que un mismo usuario puede lanzar al día; las de caché no cuentan.';
$string['ampliacion_ttl_dias'] = 'Vida de la caché (días)';
$string['ampliacion_ttl_dias_desc'] = 'Pasados estos días, una ampliación en caché se regenera la próxima vez que alguien la pida. Un texto de recurso modificado genera siempre una nueva.';

// === Retos de Épica: topes diarios propios (v1.26.0) ===
$string['retos_heading'] = 'Retos (Épica) — topes diarios propios';
$string['retos_heading_desc'] = 'Pulse pide a Épica seis retos, la persona elige uno y Épica devuelve un enlace donde se resuelve y se corrige. Estos topes son independientes de los de Épica y de los de infografías, juegos y ampliación, y se comprueban ANTES de llamar a Épica. Además, Épica limita al alumnado a 3 peticiones (proponer, «proponer otros» y elegir, conjuntas) cada 10 minutos, y al centro a 100 propuestas/elecciones al día.';
$string['retos_max_propuestas_usuario_dia'] = 'Propuestas por usuario y día';
$string['retos_max_propuestas_usuario_dia_desc'] = 'Máximo de propuestas de retos (incluidos los «proponer otros») que un mismo usuario puede pedir al día, sumando todos los cursos.';
$string['retos_max_elegidos_usuario_dia'] = 'Retos elegidos por usuario y día';
$string['retos_max_elegidos_usuario_dia_desc'] = 'Máximo de retos que un mismo usuario puede elegir al día (cada uno crea un reto en Épica), sumando todos los cursos.';
$string['retos_max_elegidos_curso_dia'] = 'Retos elegidos por curso y día';
$string['retos_max_elegidos_curso_dia_desc'] = 'Máximo de retos elegidos en un mismo curso al día, entre todas las personas. Mantenlo muy por debajo del límite diario de todo el centro en Épica.';

// === Ciclo con Épica: firmar, encargar, sondear, recoger (v1.19.0) ===
$string['task_epica_ciclo_adhoc'] = 'Un paso del ciclo de creación con Épica (Pulse AI)';
$string['epica_heading'] = 'Integración con Épica — ciclo de creación';
$string['epica_heading_desc'] = 'Adónde envía Pulse las creaciones (infografías y juegos) y cómo habla con Épica la tarea en segundo plano. Todo el ciclo (firmar, encargar, sondear, recoger) se ejecuta en una tarea adhoc: nunca ocurre dentro de una petición web.';
$string['epica_base_url'] = 'URL base de Épica';
$string['epica_base_url_desc'] = 'URL base del entorno de Épica al que se envían las creaciones (p. ej. el entorno de QA durante el piloto y producción cuando esté autorizado). La tarea le añade las rutas fijas de la API. Debe empezar por https://. VACÍA = Épica no disponible: no se ofrecen infografías, juegos, retos ni historial (Ampliar recurso sigue funcionando).';
$string['epica_dry_run'] = 'Modo de ensayo (construir el sobre, no enviarlo)';
$string['epica_dry_run_desc'] = 'Si se activa, la tarea construye el sobre completo de cada creación pendiente y lo guarda, pero nunca firma un token ni llama a Épica: la creación queda en su propio estado terminal («ensayo»). Sirve para verificar la forma del contenido antes de que local_awkepica tenga un secreto de producción configurado, o para probar el ciclo sin gastar cupo de Épica. Desactivado por defecto.';

// === Ciclo con Épica: panel de estado + aviso (v1.20.0) ===
$string['messageprovider:epica_encargo'] = 'Una creación (infografía o juego) está lista o ha fallado';

// === Errores de chat y endpoints para la persona (v1.30.0) — se eligen por error_code estable, nunca por texto de una API ===
$string['err_busy'] = 'Pulse está muy ocupado ahora mismo. Prueba en un minuto.';
$string['err_config'] = 'Pulse no está disponible ahora mismo. Avisa a tu profesorado.';
$string['err_network'] = 'Se ha cortado la conexión. Vuelve a intentarlo.';
$string['err_session'] = 'Tu sesión ha caducado. Recarga la página.';
$string['err_access'] = 'No tienes acceso a Pulse en este curso.';
$string['err_disabled'] = 'Pulse está desactivado en este curso.';
$string['err_bad_request'] = 'No se ha podido entender la petición. Escribe otra pregunta e inténtalo de nuevo.';
$string['err_empty'] = 'Pulse no ha podido responder esta vez. Vuelve a intentarlo.';
$string['err_refusal'] = 'Pulse no puede responder a esta petición. Prueba a reformular la pregunta.';
$string['err_encoding'] = 'No se ha podido preparar la petición. Empieza una conversación nueva («Nueva conversación») e inténtalo de nuevo.';
$string['err_unknown'] = 'Algo ha fallado. Vuelve a intentarlo en un momento; si sigue igual, pulsa «Nueva conversación».';
$string['err_detail_nokey_anthropic'] = 'Falta configurar la clave de Anthropic.';
$string['err_detail_nokey_openai'] = 'Falta configurar la clave de OpenAI.';
$string['err_detail_badkey'] = 'La clave de Anthropic no es válida o no tiene permisos.';
$string['err_detail_nocredit'] = 'La cuenta de Anthropic no tiene saldo.';
$string['err_detail_badmodel'] = 'El modelo configurado no existe o no está disponible.';
$string['err_detail_rejected'] = 'El servicio de IA ha rechazado la petición (configuración o modelo). Revisa el log del servidor.';
$string['err_rate_limited'] = 'Has enviado muchas preguntas seguidas. Espera un minuto.';
$string['err_rate_limited_day'] = 'Has llegado al límite de preguntas de hoy. Podrás seguir mañana.';
$string['err_unavailable'] = 'La creación de contenidos no está disponible en este sitio.';
$string['err_setting_nonneg'] = 'Debe ser un número mayor o igual que 0.';
$string['err_setting_https'] = 'Debe ser una dirección que empiece por https:// (o dejarse vacía).';
$string['cachedef_chatrate'] = 'Contador de preguntas del chat por usuario (límite de ritmo)';
$string['chatlimit_heading'] = 'Límite de uso del chat';
$string['chatlimit_heading_desc'] = 'Tope de preguntas por persona, para que una clase entera o un script no agoten el límite de la organización en Anthropic para todos los cursos. 0 = sin límite.';
$string['chat_max_por_minuto_usuario'] = 'Preguntas por minuto y usuario';
$string['chat_max_por_minuto_usuario_desc'] = 'Máximo de preguntas que una misma persona puede enviar al chat en una ventana móvil de un minuto. 0 = sin límite.';
$string['chat_max_por_dia_usuario'] = 'Preguntas por día y usuario';
$string['chat_max_por_dia_usuario_desc'] = 'Máximo de preguntas que una misma persona puede enviar al chat en un día. 0 = sin límite.';
$string['diagnostico_heading'] = 'Diagnóstico';
$string['diagnostico_heading_desc'] = 'Comprueba claves, servicios externos, cron y cursos sin texto indexado.';
$string['diagnostico_link'] = 'Abrir la página de diagnóstico';
$string['task_sync_tema'] = 'Sincronizar los colores del centro con Épica (Pulse AI)';
$string['tema_heading'] = 'Colores del centro (Épica)';
$string['tema_heading_desc'] = 'Cada centro ve Pulse con sus colores, elegidos en Épica. Una tarea programada pregunta a Épica cada hora y guarda la respuesta; las páginas solo leen lo guardado. Sin tema, Pulse se ve como siempre.';
$string['tema_estado_con'] = 'Tema actual: versión {$a->version}, última sincronización {$a->fecha}.';
$string['tema_estado_sin'] = 'No hay tema guardado: Pulse usa sus colores.';
$string['tema_sync_button'] = 'Sincronizar los colores con Épica';
$string['tema_msg_sin_epica'] = 'Épica no está disponible en este sitio, así que no hay nada que sincronizar.';
$string['tema_msg_sin_correo'] = 'La persona que administra el sitio no tiene correo electrónico, así que no se puede firmar la petición a Épica. Añádelo en su perfil.';
$string['tema_msg_error_red'] = 'No se ha podido contactar con Épica. Se mantienen los últimos colores guardados.';
$string['tema_msg_error_rechazo'] = 'Épica ha rechazado la petición. Revisa la plataforma y el secreto de local_awkepica. Se mantienen los últimos colores guardados.';
$string['tema_msg_error_respuesta'] = 'Épica ha contestado de una forma inesperada. Se mantienen los últimos colores guardados.';
$string['tema_msg_guardado'] = 'Colores sincronizados (versión {$a}).';
$string['tema_msg_sin_cambios'] = 'Los colores ya estaban al día (versión {$a}).';
$string['tema_msg_borrado'] = 'Este centro ya no tiene colores en Épica: Pulse vuelve a los suyos.';
$string['tema_msg_sin_tema'] = 'Este centro no tiene colores en Épica: Pulse usa los suyos.';
