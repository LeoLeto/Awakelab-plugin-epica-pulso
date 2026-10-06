// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Chat de Pulse AI: ventana flotante, envio de mensajes (SSE con respaldo XHR), historial, dictado y arranque.
 *
 * @module     block_pulso/chat
 * @copyright  2026 Awakelab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['block_pulso/common', 'block_pulso/format', 'block_pulso/crear', 'block_pulso/retos', 'block_pulso/ampliacion'], function(C) {
    'use strict';

    var S = C.S;
    var cfg = C.cfg;
    var pulsoLog = C.pulsoLog;
    var pulsoScrollToEnd = C.pulsoScrollToEnd;
    var pulsoAnnounce = C.pulsoAnnounce;
    var escapeHtmlText = C.escapeHtmlText;


        
        function setMessage(text) {
            document.getElementById('pulso-input').value = text;
            updateCharCount();
        }


        // Tarjeta de acción de la pantalla de inicio: enviar pregunta predefinida.
        function askPreset(question) {
            const input = document.getElementById('pulso-input');
            input.value = question;
            updateCharCount();
            sendMessage(new Event('submit'));
            input.focus(); // la tarjeta desaparece al enviar: el foco no se pierde
        }


        // Botón "¿Qué puede hacer Pulso?": explicación instantánea (sin LLM),
        // formateada como lista, para que un recién llegado lo entienda rápido.
        function showCapabilities() {
            setHomeVisible(false);

            // Dos versiones: el alumno no puede pedir analítica, así que no se le
            // ofrece (se le rechazaría en servidor).
            const isTeacher = cfg.isTeacher !== false;
            let html;
            let examples;

            if (isTeacher) {
                html = ''
                    + '<div class="pulso-rich-answer">'
                    + '<p>Soy <strong>Pulse AI</strong>, tu asistente del curso. Esto es lo que puedo hacer por ti:</p>'
                    + '<ul class="pulso-rich-bullets">'
                    + '<li>📊 <strong>Analítica del curso:</strong> tasa de completitud, notas medias, ranking de mejores alumnos y nivel de participación.</li>'
                    + '<li>⚠️ <strong>Alerta temprana:</strong> detecto alumnos en riesgo o que llevan días sin acceder.</li>'
                    + '<li>📚 <strong>Contenido del curso:</strong> resumen, secciones, actividades, recursos y cuestionarios.</li>'
                    + '<li>📄 <strong>Documentos:</strong> leo y resumo PDFs y materiales, y respondo sobre lo que dicen.</li>'
                    + '<li>🎤 <strong>Voz o texto:</strong> pregúntame en lenguaje natural escribiendo o usando el micrófono.</li>'
                    + '</ul>'
                    + '<p>👉 Toca una tarjeta de la pantalla de inicio o prueba con una de estas preguntas:</p>'
                    + '</div>';
                examples = [
                    '¿Cuál es la tasa de completitud del curso?',
                    '¿Qué estudiantes están en riesgo?',
                    '¿De qué trata este curso?'
                ];
            } else {
                html = ''
                    + '<div class="pulso-rich-answer">'
                    + '<p>Soy <strong>Pulse AI</strong>, tu asistente de estudio. Esto es lo que puedo hacer por ti:</p>'
                    + '<ul class="pulso-rich-bullets">'
                    + '<li>📚 <strong>Contenido del curso:</strong> te cuento de qué trata, qué secciones tiene y qué materiales hay en cada una.</li>'
                    + '<li>📄 <strong>Resúmenes y explicaciones:</strong> leo los PDFs y materiales del curso y te los resumo o te los explico paso a paso.</li>'
                    + '<li>❓ <strong>Dudas de estudio:</strong> pregúntame sobre lo que dice el material y te lo aclaro con ejemplos.</li>'
                    + '<li>🗂️ <strong>Actividades:</strong> te digo qué cuestionarios y tareas hay, sus instrucciones y sus fechas.</li>'
                    + '<li>🎤 <strong>Voz o texto:</strong> pregúntame en lenguaje natural escribiendo o usando el micrófono.</li>'
                    + '</ul>'
                    + '<p>Las notas y los datos de la clase los gestiona el profesorado, así que eso no te lo puedo dar. '
                    + 'Tus propias calificaciones están en el libro de calificaciones del curso.</p>'
                    + '<p>👉 Toca una tarjeta de la pantalla de inicio o prueba con una de estas preguntas:</p>'
                    + '</div>';
                examples = [
                    '¿De qué trata este curso?',
                    '¿Qué materiales hay en el curso?',
                    'Explícame de qué trata la sección 1 del curso.'
                ];
            }

            addMessage(html, 'ai', true);
            showFollowupQuestions(examples);
        }


        // Historial de sessionStorage: se reenvía al modelo en cada petición, así que tiene que
        // verse. Son digests de TEXTO (nunca JSON), por eso se pintan escapados (escapeHtmlText) y
        // sin formato de respuesta, bajo un separador.
        function pulsoRenderHistory() {
            const log = document.getElementById('pulso-messages');
            const hist = S.conversationHistory;
            if (!log || !Array.isArray(hist) || !hist.length || log.querySelector('.pulso-message')) return;

            const divider = document.createElement('div');
            divider.className = 'pulso-history-divider';
            divider.textContent = 'Conversación anterior';
            log.appendChild(divider);

            hist.forEach(function(turn) {
                if (!turn || typeof turn.content !== 'string' || turn.content.trim() === '') return;
                const el = document.createElement('div');
                el.className = 'pulso-message ' + (turn.role === 'user' ? 'user' : 'ai') + ' pulso-message-history';
                const content = document.createElement('div');
                content.className = 'pulso-message-content';
                content.innerHTML = escapeHtmlText(turn.content);
                el.appendChild(content);
                log.appendChild(el);
            });
            setHomeVisible(false);
        }


        // La pantalla de inicio (saludo + tarjetas) solo se muestra sin conversación.
        function setHomeVisible(visible) {
            const home = document.getElementById('pulso-home');
            if (home) {
                home.style.display = visible ? '' : 'none';
            }
        }

        
        // El contador solo aparece cerca del tope (desde 400 de 500) y el lector de pantalla oye
        // «Quedan N caracteres» en unos umbrales (no en cada tecla). maxlength ya corta lo tecleado.
        const PULSO_CHAR_MAX = 500;

        const PULSO_CHAR_SHOW_AT = 400;

        const PULSO_CHAR_ANNOUNCE_AT = [450, 480, 490, 500];

        let pulsoCharAnnounced = 0;


        function updateCharCount() {
            const input = document.getElementById('pulso-input');
            if (!input) return;
            const count = input.value.length;
            const box = document.getElementById('pulso-char-count-box');
            const num = document.getElementById('pulso-char-count');
            if (num) num.textContent = count;
            if (box) {
                box.hidden = count < PULSO_CHAR_SHOW_AT;
                box.classList.toggle('is-near', count >= 480);
            }
            if (count < PULSO_CHAR_SHOW_AT) pulsoCharAnnounced = 0;
            PULSO_CHAR_ANNOUNCE_AT.forEach(function(threshold) {
                if (count >= threshold && pulsoCharAnnounced < threshold) {
                    pulsoCharAnnounced = threshold;
                    const left = PULSO_CHAR_MAX - count;
                    pulsoAnnounce(left > 0 ? 'Quedan ' + left + ' caracteres' : 'Has llegado al límite de ' + PULSO_CHAR_MAX + ' caracteres');
                }
            });
            pulsoUpdateSendState();
        }


        // ========== DICTADO POR VOZ (Web Speech API, transcripción en cliente) ==========

        let pulsoRecognition = null;
   // instancia de SpeechRecognition (una sola)
        let pulsoMicRecording = false;
 // ¿grabando ahora mismo?
        let pulsoMicBase = '';
         // texto ya escrito antes de empezar a dictar

        function initPulsoMic() {
            const btn = document.getElementById('pulso-mic-btn');
            if (!btn) return;

            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) {
                // Navegador sin soporte (p. ej. Firefox): dejar el botón oculto.
                return;
            }

            pulsoRecognition = new SR();
            pulsoRecognition.lang = 'es-ES';
            pulsoRecognition.interimResults = true;
            pulsoRecognition.continuous = false;
            pulsoRecognition.maxAlternatives = 1;

            pulsoRecognition.onresult = function(event) {
                const input = document.getElementById('pulso-input');
                if (!input) return;
                let transcript = '';
                for (let i = 0; i < event.results.length; i++) {
                    transcript += event.results[i][0].transcript;
                }
                let combined = (pulsoMicBase + transcript).slice(0, 500);
                input.value = combined;
                updateCharCount();
            };

            pulsoRecognition.onerror = function(event) {
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    pulsoSystemMessage('El micrófono está bloqueado. Permite el acceso en tu navegador para dictar.');
                }
                // 'no-speech' / 'aborted' se ignoran silenciosamente.
                stopPulsoMic();
            };

            pulsoRecognition.onend = function() {
                stopPulsoMic();
            };

            // Soportado: mostrar el botón.
            btn.style.display = '';
        }


        function toggleMic() {
            if (!pulsoRecognition) return;
            if (pulsoMicRecording) {
                pulsoRecognition.stop();
                return;
            }
            const input = document.getElementById('pulso-input');
            // Conservar lo ya escrito y añadir un espacio de separación si hace falta.
            pulsoMicBase = input && input.value ? (input.value.replace(/\s+$/, '') + ' ') : '';
            try {
                pulsoRecognition.start();
            } catch (err) {
                // start() lanza si ya estaba activo; reintentar limpio.
                return;
            }
            pulsoMicRecording = true;
            const btn = document.getElementById('pulso-mic-btn');
            if (btn) {
                btn.classList.add('pulso-mic-recording');
                btn.setAttribute('aria-pressed', 'true');
                btn.setAttribute('aria-label', 'Detener dictado');
            }
            if (input) input.focus();
        }


        function stopPulsoMic() {
            if (!pulsoMicRecording) return;
            pulsoMicRecording = false;
            const btn = document.getElementById('pulso-mic-btn');
            if (btn) {
                btn.classList.remove('pulso-mic-recording');
                btn.setAttribute('aria-pressed', 'false');
                btn.setAttribute('aria-label', 'Dictar pregunta por voz');
            }
        }


        function detectLanguage(text) {
            // Palabras clave en inglés
            const englishKeywords = ['are', 'students', 'grades', 'how', 'what', 'completion', 'quiz', 'assignments', 'progress', 'performance', 'risk', 'going', 'doing', 'activity', 'engagement'];
            
            // Palabras clave en español
            const spanishKeywords = ['estudiantes', 'notas', 'tasa', 'cuál', 'qué', 'riesgo', 'están', 'tareas', 'progreso', 'desempeño', 'actividad', 'completitud', 'compromiso'];
            
            const lowerText = text.toLowerCase();
            
            // Contar coincidencias
            const englishCount = englishKeywords.filter(word => lowerText.includes(word)).length;
            const spanishCount = spanishKeywords.filter(word => lowerText.includes(word)).length;
            
            // Retornar idioma detectado
            return englishCount > spanishCount ? 'en' : 'es';
        }


        function isAnalyticsQuestion(text) {
            const q = (text || '').toLowerCase();
            const analyticsKeywords = [
                'analitica', 'analítica', 'analytics',
                'completitud', 'completion', 'progress', 'progreso',
                'nota', 'notas', 'grade', 'grades', 'calificacion', 'calificación',
                'engagement', 'participacion', 'participación',
                'riesgo', 'at risk', 'abandono',
                'promedio', 'average', 'porcentaje', 'tasa',
                'usuarios', 'estudiantes', 'accesos', 'logs'
            ];

            return analyticsKeywords.some(k => q.includes(k));
        }

        
        // ========== ENVÍO DE MENSAJES (streaming SSE + fallback XHR) ==========

        let pulsoSending = false;

        // Topes de tiempo del cliente (v1.31.0): el servidor llama a Anthropic con 120 s
        // (stream) / 110 s (XHR) y tiene 180 s de límite de ejecución.
        const PULSO_STREAM_TIMEOUT_MS = 150000;

        const PULSO_XHR_TIMEOUT_MS = 110000;

        let streamBubble = null;

        let typingBubble = null;


        // Estado del envío en UN sitio: la bandera, el botón (aria-disabled: sigue enfocable) y
        // aria-busy del registro (el lector no anuncia a trozos mientras llega la respuesta).
        function pulsoSetSending(flag) {
            pulsoSending = !!flag;
            const log = document.getElementById('pulso-messages');
            if (log) log.setAttribute('aria-busy', pulsoSending ? 'true' : 'false');
            pulsoUpdateSendState();
        }


        function pulsoUpdateSendState() {
            const btn = document.getElementById('pulso-send-btn');
            const input = document.getElementById('pulso-input');
            if (!btn || !input) return;
            const empty = input.value.trim() === '';
            btn.setAttribute('aria-disabled', (pulsoSending || empty) ? 'true' : 'false');
        }


        function pulsoSetOffline(show) {
            const banner = document.getElementById('pulso-offline');
            if (!banner) return;
            const wasHidden = banner.hidden;
            banner.hidden = !show;
            if (show && wasHidden) pulsoAnnounce(banner.textContent);
        }


        function sendMessage(e) {
            if (e && e.preventDefault) e.preventDefault();
            if (pulsoSending) return;
            if (pulsoMicRecording && pulsoRecognition) pulsoRecognition.stop();
            const input = document.getElementById('pulso-input');
            const message = input.value.trim();

            // Campo vacío: el botón ya se ve desactivado; sin aviso emergente.
            if (!message) return;

            // Sin conexión: la pregunta se queda en el cuadro y el aviso persistente lo explica.
            if (navigator.onLine === false) {
                pulsoSetOffline(true);
                return;
            }

            // Con el panel Crear abierto, el mensaje y su respuesta quedarían ocultos (y entrarían
            // en el historial sin verse): se vuelve al chat ANTES de enviar.
            if (C.fn.pulsoCreateIsOpen()) C.fn.closeCreatePanel({ restoreFocus: false });

            setHomeVisible(false);
            addMessage(message, 'user');
            input.value = '';
            updateCharCount();

            dispatchMessage(message);
        }


        // Lanza la petición (stream o XHR). La usa también «Reintentar», que reenvía el MISMO
        // mensaje sin volver a pintarlo ni tocar lo que haya en el cuadro de texto.
        function dispatchMessage(message) {
            pulsoAnnounce('Pulse está preparando la respuesta');
            showLoading(true);

            // Streaming (estilo ChatGPT) cuando el navegador lo soporta;
            // si no, o si el endpoint de streaming falla, XHR clásico.
            if (window.fetch && window.ReadableStream && window.TextDecoder) {
                sendMessageStream(message);
            } else {
                sendMessageXHR(message);
            }
        }


        function buildChatFormData(message) {
            const formData = new FormData();
            formData.append('courseid', cfg.courseid || 2);
            formData.append('user_query', message);
            formData.append('conversation_history', JSON.stringify(S.conversationHistory || []));
            // Obligatorio: los endpoints validan con require_sesskey().
            formData.append('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            return formData;
        }


        // El historial guarda TEXTO, nunca el JSON de la respuesta: un JSON
        // recortado en un turno de asistente hacía que el modelo continuase la
        // respuesta anterior en vez de contestar la pregunta nueva. El servidor
        // vuelve a limpiarlo igualmente (chat_pipeline::history_digest()), esto
        // evita generar historiales sucios nuevos.
        function pulsoHistoryDigest(answer) {
            const raw = String(answer || '').trim();
            if (!raw) return '';
            if (raw[0] !== '{' && raw[0] !== '[') {
                return pulsoTrimDigest(raw);
            }
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                // JSON inválido o cortado: quedarse con el texto plano que se vea.
                return pulsoTrimDigest(raw.replace(/[{}\[\]"]/g, ' '));
            }
            const parts = [];
            // 'next_step' al FINAL y en la misma posición que en el digest de PHP
            // (chat_pipeline::history_digest): los dos tienen que producir exactamente
            // lo mismo. Va incluido porque la regla de INICIATIVA prohíbe repetir el
            // ofrecimiento dos turnos seguidos, y el modelo necesita ver el anterior.
            ['title', 'summary', 'content', 'next_step'].forEach(function(key) {
                if (typeof data[key] === 'string' && data[key].trim()) {
                    parts.push(data[key].trim());
                }
            });
            // Las filas de 'data' NO entran en el digest: en una respuesta de
            // analítica son una tabla de métricas con el mismo aspecto que el
            // formato de salida que se le pide al modelo, y el modelo la
            // continuaba en vez de responder a la pregunta nueva. Para resolver
            // referencias posteriores ("ese pdf") solo hacen falta título,
            // resumen y las anclas de línea que van en 'content'.
            return pulsoTrimDigest(parts.join('\n'));
        }


        // Recorta conservando los SALTOS DE LÍNEA: las anclas "Recurso:" /
        // "Seccion:" del historial se localizan con anclas de línea, así que
        // aplastarlos deja al history-hint ciego (y hace que el "título" pase a
        // ser toda la frase).
        function pulsoTrimDigest(text) {
            return String(text || '')
                .replace(/[^\S\n]+/g, ' ')
                .replace(/\n{2,}/g, '\n')
                .trim()
                .slice(0, 500);
        }


        // Mensaje al usuario cuando una respuesta no se puede usar. Lo comparten el
        // evento 'error' del SSE y la rama de fallo de handleChatResponse, que es la
        // que en el QA de 1.15.2 mostró "Error: No success flag" — un literal de
        // desarrollador que no le dice nada a nadie.
        //
        // El texto se elige por `error_code` (estable, lo manda el servidor), NUNCA por
        // `message`: el mensaje del servidor es para quien consuma la API y ya no se
        // pinta nunca — antes se añadía como «Detalle: …» y llegaban a la pantalla
        // textos crudos de Anthropic/OpenAI. Solo se añade `detail` (causa genérica
        // escrita por nosotros), y el servidor lo manda únicamente a profesorado/admin.
        const PULSO_ERROR_TEXTS = {
            busy: 'Pulse está muy ocupado ahora mismo. Prueba en un minuto.',
            config: 'Pulse no está disponible ahora mismo. Avisa a tu profesorado.',
            network: 'Se ha cortado la conexión. Vuelve a intentarlo.',
            session: 'Tu sesión ha caducado. Recarga la página.',
            access: 'No tienes acceso a Pulse en este curso.',
            disabled: 'Pulse está desactivado en este curso.',
            bad_request: 'No se ha podido entender la petición. Escribe otra pregunta e inténtalo de nuevo.',
            empty: 'Pulse no ha podido responder esta vez. Vuelve a intentarlo.',
            refusal: 'Pulse no puede responder a esta petición. Prueba a reformular la pregunta.',
            encoding: 'No se ha podido preparar la petición. Empieza una conversación nueva («Nueva conversación») e inténtalo de nuevo.',
            rate_limited: 'Has enviado muchas preguntas seguidas. Espera un minuto.',
            rate_limited_day: 'Has llegado al límite de preguntas de hoy. Podrás seguir mañana.',
            unavailable: 'La creación de contenidos no está disponible en este sitio.',
            timeout: 'La respuesta está tardando demasiado. Vuelve a intentarlo.',
            truncated: 'La respuesta se ha cortado. Pídeme que la continúe o haz una pregunta más concreta.',
            interrupted: 'La respuesta se interrumpió. Inténtalo de nuevo.',
            unknown: 'Algo ha fallado. Vuelve a intentarlo en un momento; si sigue igual, pulsa «Nueva conversación».'
        };


        // Códigos en los que reenviar el mismo mensaje tiene sentido; el resto pide otra acción
        // (recargar, reformular, empezar de nuevo, esperar a mañana).
        const PULSO_RETRYABLE = ['busy', 'network', 'timeout', 'empty', 'unknown', 'rate_limited', 'interrupted'];


        // Todos los textos del cliente van en castellano (antes algunos cambiaban a inglés por
        // navigator.language). Si algún día hay multidioma, el idioma saldrá de Moodle vía JSINIT.
        function pulsoFailureMessage(payload) {
            const code = (payload && typeof payload.error_code === 'string') ? payload.error_code : '';
            let text = Object.prototype.hasOwnProperty.call(PULSO_ERROR_TEXTS, code) ? PULSO_ERROR_TEXTS[code] : PULSO_ERROR_TEXTS.unknown;
            const detail = (payload && typeof payload.detail === 'string') ? payload.detail.trim() : '';
            if (detail !== '') {
                text += ' ' + detail;
            }
            return text;
        }


        // Burbuja de error: SVG decorativo (aria-hidden) + «Error:» + el texto del código. Si
        // procede, lleva «Reintentar», que reenvía el MISMO mensaje (la pregunta ya no se pierde
        // aunque el campo se haya vaciado al enviar). textOverride sustituye al texto por código.
        function addErrorMessage(payload, message, textOverride) {
            const log = document.getElementById('pulso-messages');
            if (!log) return;
            const code = (payload && typeof payload.error_code === 'string') ? payload.error_code : 'unknown';

            const el = document.createElement('div');
            el.className = 'pulso-message ai pulso-error-bubble';
            const content = document.createElement('div');
            content.className = 'pulso-message-content';

            const head = document.createElement('div');
            head.className = 'pulso-error-head';
            head.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
            const txt = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = 'Error:';
            txt.appendChild(strong);
            txt.appendChild(document.createTextNode(' ' + (textOverride || pulsoFailureMessage(payload))));
            head.appendChild(txt);
            content.appendChild(head);

            if (message && PULSO_RETRYABLE.indexOf(code) !== -1) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'pulso-error-retry';
                retry.textContent = 'Reintentar';
                retry.addEventListener('click', function() {
                    if (pulsoSending) return;
                    if (navigator.onLine === false) {
                        pulsoSetOffline(true);
                        return;
                    }
                    el.remove();
                    dispatchMessage(message);
                    const input = document.getElementById('pulso-input');
                    if (input) input.focus();
                });
                content.appendChild(retry);
            }

            el.appendChild(content);
            log.appendChild(el);
            pulsoScrollToEnd();
        }


        // Aviso del sistema dentro del chat (sustituye a los alert()). Si el panel Crear está
        // abierto se vuelve al chat: el mensaje quedaría oculto detrás.
        function pulsoSystemMessage(text) {
            if (C.fn.pulsoCreateIsOpen()) C.fn.closeCreatePanel({ restoreFocus: false });
            addErrorMessage({ error_code: 'custom' }, null, text);
        }


        // Procesamiento compartido de la respuesta completa (stream final / XHR).
        function handleChatResponse(message, response) {
            if (response.success && response.answer) {
                const showAnalysisSections = isAnalyticsQuestion(message);
                // Diagnóstico opcional (window.pulsoDebug = true en la consola): el JSON
                // ya limpio y QUÉ secciones se van a pintar. Sirve para localizar en qué
                // campo viene una frase que no aparece en pantalla: 'content' solo se
                // pinta si type === 'text', e 'insights'/'recommendations' solo si la
                // pregunta parece de analítica (isAnalyticsQuestion), así que un texto
                // en el campo equivocado se descarta en silencio.
                if (window.pulsoDebug) {
                    let parsed = null;
                    try { parsed = JSON.parse(response.answer); } catch (e) { parsed = null; }
                    pulsoLog('[pulso] JSON final:', response.answer);
                    pulsoLog('[pulso] campos:', parsed ? Object.keys(parsed) : '(no es JSON)',
                        '| type:', parsed && parsed.type,
                        '| showAnalysisSections:', showAnalysisSections,
                        '| se pinta content:', !!(parsed && parsed.content && parsed.type === 'text'),
                        '| se pintan insights/recomendaciones:', showAnalysisSections);
                }
                const formattedAnswer = C.fn.formatAIResponse(response.answer, showAnalysisSections);
                addMessage(formattedAnswer, 'ai', true);

                // La respuesta se cortó por max_tokens (v1.31.0): se avisa y NO entra en
                // el historial (un turno cortado, reenviado al modelo, hacía que lo
                // continuara en vez de contestar la pregunta nueva). Sin sugerencias.
                if (response.truncated) {
                    addErrorMessage({error_code: 'truncated'});
                    return;
                }

                // Mostrar preguntas sugeridas (T2.4.12) — en streaming pueden
                // llegar después como evento 'followups'.
                if (response.followup_questions && response.followup_questions.length > 0) {
                    showFollowupQuestions(response.followup_questions);
                }

                // T2.5.3: Guardar en historial para conversación futura
                if (!S.conversationHistory) {
                    S.conversationHistory = [];
                }
                S.conversationHistory.push({role: 'user', content: message});
                S.conversationHistory.push({role: 'assistant', content: pulsoHistoryDigest(response.answer)});
                if (S.conversationHistory.length > 20) {
                    S.conversationHistory = S.conversationHistory.slice(-20);
                }
                try {
                    sessionStorage.setItem('pulso_history_' + cfg.courseid, JSON.stringify(S.conversationHistory));
                } catch(e) {
                    console.warn('⚠️ No se pudo guardar historial en sessionStorage');
                }
            } else {
                // Este else colapsa TRES situaciones distintas y las tres acababan
                // mostrando texto de desarrollador al usuario (visto en el QA de
                // 1.15.2: "Error: No success flag", que no dice nada):
                //  - success:false con 'message' = "Error: <texto crudo de la
                //    excepción>" (api_chat.php / api_chat_stream.php) → se pintaba
                //    doblemente prefijado, "Error: Error: ...".
                //  - success:true pero 'answer' vacío → el 'message' del payload es
                //    "Query procesado exitosamente", que como error no tiene sentido.
                //  - una respuesta sin 'success' ni 'message' → el literal
                //    "No success flag".
                // Ahora al usuario se le dice qué hacer y el detalle técnico se queda
                // en la consola. El detalle del servidor solo se añade cuando es un
                // error de verdad (success:false con mensaje), nunca el "procesado
                // exitosamente" de una respuesta vacía.
                // Este turno NO entra en el historial, y así debe seguir: un turno
                // fallido en el historial se le reenvía al modelo en la pregunta
                // siguiente.
                console.error('❌ Respuesta no utilizable:', response);
                addErrorMessage(response, message);
            }
        }


        // ---------- Burbuja de respuesta en vivo ----------

        function ensureStreamBubble() {
            if (streamBubble && streamBubble.isConnected) {
                return streamBubble;
            }
            const messagesDiv = document.getElementById('pulso-messages');
            const messageEl = document.createElement('div');
            messageEl.className = 'pulso-message ai';
            // Los trozos del stream NO se anuncian: el lector oye la respuesta final una sola vez.
            messageEl.setAttribute('aria-hidden', 'true');
            const contentEl = document.createElement('div');
            contentEl.className = 'pulso-message-content';
            const textEl = document.createElement('span');
            textEl.className = 'pulso-stream-text';
            const cursorEl = document.createElement('span');
            cursorEl.className = 'pulso-stream-cursor';
            contentEl.appendChild(textEl);
            contentEl.appendChild(cursorEl);
            messageEl.appendChild(contentEl);
            messagesDiv.appendChild(messageEl);
            streamBubble = messageEl;
            return messageEl;
        }


        function updateStreamBubble(text) {
            if (!text) return;
            const bubble = ensureStreamBubble();
            const textEl = bubble.querySelector('.pulso-stream-text');
            if (textEl) textEl.textContent = text;
            const messagesDiv = document.getElementById('pulso-messages');
            pulsoScrollToEnd();
        }


        function removeStreamBubble() {
            if (streamBubble && streamBubble.parentNode) {
                streamBubble.parentNode.removeChild(streamBubble);
            }
            streamBubble = null;
        }


        // Extraer texto legible de una respuesta parcial. Las respuestas del
        // modelo son JSON: mientras llegan tokens vamos mostrando los valores
        // de texto (title, summary, párrafos...) en vez del JSON crudo.
        function extractStreamPreview(accum) {
            let s = String(accum || '').replace(/^\s*```[a-z]*\s*/i, '').replace(/\s*```\s*$/, '');
            if (!/^\s*[\[{]/.test(s)) {
                return s; // texto plano (respuestas de documento) → mostrar tal cual
            }
            // Sin 'title': el render final ya no muestra títulos cuando hay
            // cuerpo, así evitamos previsualizar texto que luego desaparece.
            const keys = ['summary', 'paragraph', 'párrafo', 'parrafo', 'text', 'texto', 'content', 'contenido', 'description', 'descripción', 'descripcion'];
            const re = new RegExp('"(' + keys.join('|') + ')"\\s*:\\s*"((?:[^"\\\\]|\\\\.)*)', 'g');
            const parts = [];
            let m;
            while ((m = re.exec(s)) !== null) {
                const v = m[2]
                    .replace(/\\n/g, '\n')
                    .replace(/\\"/g, '"')
                    .replace(/\\\\/g, '\\');
                if (v.trim()) parts.push(v.trim());
            }
            return parts.join('\n\n');
        }


        // ---------- Streaming SSE ----------

        function sendMessageStream(message) {
            pulsoSetSending(true);
            let accum = '';
            let gotFinal = false;
            let receivedAny = false;

            // Tope de ~150 s para todo el stream (el servidor tiene 180 s de límite y la
            // llamada a Anthropic 120 s): si salta, aviso claro y se libera el envío.
            // Sin esto, una conexión colgada dejaba la interfaz bloqueada para siempre.
            let timedOut = false;
            const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            const timeoutTimer = ctrl ? setTimeout(function() {
                timedOut = true;
                ctrl.abort();
            }, PULSO_STREAM_TIMEOUT_MS) : null;
            function clearStreamTimer() {
                if (timeoutTimer) clearTimeout(timeoutTimer);
            }

            function handleSseEvent(raw) {
                let eventName = 'message';
                const dataLines = [];
                raw.split('\n').forEach(function(line) {
                    if (line.indexOf('event:') === 0) {
                        eventName = line.slice(6).trim();
                    } else if (line.indexOf('data:') === 0) {
                        dataLines.push(line.slice(5).trim());
                    }
                });
                if (!dataLines.length) return;
                let data;
                try {
                    data = JSON.parse(dataLines.join('\n'));
                } catch (err) {
                    return;
                }
                receivedAny = true;

                if (eventName === 'status') {
                    showLoading(true, data.stage === 'generating' ? 'Generando respuesta...' : 'Analizando datos del curso...');
                } else if (eventName === 'delta') {
                    accum += (data.text || '');
                    const preview = extractStreamPreview(accum);
                    if (preview) {
                        showLoading(false);
                        updateStreamBubble(preview);
                    }
                } else if (eventName === 'final') {
                    gotFinal = true;
                    showLoading(false);
                    removeStreamBubble();
                    handleChatResponse(message, data);
                } else if (eventName === 'followups') {
                    if (data.questions && data.questions.length > 0) {
                        showFollowupQuestions(data.questions);
                    }
                } else if (eventName === 'error') {
                    gotFinal = true;
                    showLoading(false);
                    removeStreamBubble();
                    console.error('❌ Evento error del stream:', data);
                    addErrorMessage(data, message);
                }
            }

            fetch(cfg.streamApiUrl, {
                method: 'POST',
                body: buildChatFormData(message),
                credentials: 'same-origin',
                signal: ctrl ? ctrl.signal : undefined
            })
            .then(function(res) {
                const ct = res.headers.get('content-type') || '';
                if (ct.indexOf('text/event-stream') === -1) {
                    // El servidor rechazó la petición ANTES de abrir el stream (sesión
                    // caducada, sesskey, permiso, curso desactivado...) con un JSON
                    // {success:false, error_code}. Se lee con CUALQUIER código HTTP y NO se
                    // reintenta por el endpoint clásico: fallaría igual y duplicaría la
                    // petición. El fallback queda solo para 404/405 y fallos de red.
                    if (res.status === 404 || res.status === 405 || !res.body) {
                        throw new Error('stream-unavailable');
                    }
                    return res.text().then(function(body) {
                        let data = null;
                        try { data = JSON.parse(body); } catch (err) { data = null; }
                        gotFinal = true;
                        showLoading(false);
                        removeStreamBubble();
                        handleChatResponse(message, (data && typeof data === 'object') ? data : {success: false});
                    });
                }
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buf = '';
                function pump() {
                    return reader.read().then(function(r) {
                        if (r.done) return;
                        buf += decoder.decode(r.value, {stream: true});
                        let idx;
                        while ((idx = buf.indexOf('\n\n')) !== -1) {
                            handleSseEvent(buf.slice(0, idx));
                            buf = buf.slice(idx + 2);
                        }
                        return pump();
                    });
                }
                return pump();
            })
            .then(function() {
                clearStreamTimer();
                pulsoSetSending(false);
                if (!gotFinal) {
                    removeStreamBubble();
                    if (!receivedAny) {
                        // El servidor no habló SSE → endpoint clásico.
                        sendMessageXHR(message);
                    } else {
                        showLoading(false);
                        addErrorMessage({error_code: 'interrupted'}, message);
                    }
                }
            })
            .catch(function(err) {
                clearStreamTimer();
                pulsoSetSending(false);
                removeStreamBubble();
                if (gotFinal) return;
                if (timedOut) {
                    // Nunca al endpoint clásico: repetiría la petición que ya agotó el tiempo.
                    showLoading(false);
                    addErrorMessage({error_code: 'timeout'}, message);
                } else if (!receivedAny) {
                    console.warn('⚠️ Streaming no disponible, usando endpoint clásico:', err.message);
                    sendMessageXHR(message);
                } else {
                    // Corte de red a mitad de respuesta: aviso y el envío queda libre.
                    showLoading(false);
                    addErrorMessage({error_code: 'network'}, message);
                }
            });
        }


        // ---------- Fallback XHR clásico (api_chat.php) ----------

        function sendMessageXHR(message) {
            // También bloquea el envío (antes solo lo hacía el stream: con el fallback se
            // podían lanzar dos preguntas a la vez).
            pulsoSetSending(true);
            showLoading(true);
            const xhr = new XMLHttpRequest();
            let finished = false;

            // Cierra la petición UNA vez (load/timeout/error/abort son excluyentes pero
            // conviene no depender de ello): libera el envío y quita el «escribiendo».
            function finish() {
                if (finished) return false;
                finished = true;
                pulsoSetSending(false);
                showLoading(false);
                return true;
            }

            // Tope de 110 s (el servidor llama a Anthropic con 110 s y tiene 180 s de límite).
            xhr.timeout = PULSO_XHR_TIMEOUT_MS;

            xhr.onload = function() {
                if (!finish()) return;
                pulsoLog('📡 AJAX Status:', xhr.status);

                // El cuerpo se lee con cualquier código: el servidor contesta 4xx/5xx
                // con {success:false, error_code} y el texto se elige por ese código.
                let response = null;
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (e) {
                    console.error('❌ Respuesta no JSON (HTTP ' + xhr.status + '):', xhr.responseText);
                }
                if (response && typeof response === 'object') {
                    handleChatResponse(message, response);
                } else {
                    addErrorMessage({error_code: 'unknown'}, message);
                }
            };
            xhr.ontimeout = function() {
                if (!finish()) return;
                addErrorMessage({error_code: 'timeout'}, message);
            };
            xhr.onerror = xhr.onabort = function() {
                if (!finish()) return;
                addErrorMessage({error_code: 'network'}, message);
            };

            xhr.open('POST', cfg.apiUrl, true);
            xhr.send(buildChatFormData(message));
        }

        
        function addMessage(text, sender, isHtml = false) {
            const messagesDiv = document.getElementById('pulso-messages');
            const messageEl = document.createElement('div');
            messageEl.className = 'pulso-message ' + sender;
            
            const contentEl = document.createElement('div');
            contentEl.className = 'pulso-message-content';
            
            if (isHtml) {
                contentEl.innerHTML = text;
            } else {
                contentEl.textContent = text;
            }
            
            messageEl.appendChild(contentEl);
            messagesDiv.appendChild(messageEl);
            
            // Auto scroll
            pulsoScrollToEnd();
        }

        
        // Burbuja "escribiendo" estilo WhatsApp: se muestra mientras el bot
        // piensa y desaparece en cuanto llegan los primeros tokens / la respuesta.
        function showTyping() {
            if (typingBubble && typingBubble.isConnected) return;
            const messagesDiv = document.getElementById('pulso-messages');
            if (!messagesDiv) return;
            const messageEl = document.createElement('div');
            messageEl.className = 'pulso-message ai pulso-typing';
            // Decorativa: el aviso al lector sale de pulsoAnnounce() al enviar.
            messageEl.setAttribute('aria-hidden', 'true');
            const contentEl = document.createElement('div');
            contentEl.className = 'pulso-message-content';
            const dots = document.createElement('span');
            dots.className = 'pulso-typing-dots';
            dots.setAttribute('aria-hidden', 'true');
            dots.innerHTML = '<span></span><span></span><span></span>';
            contentEl.appendChild(dots);
            messageEl.appendChild(contentEl);
            messagesDiv.appendChild(messageEl);
            typingBubble = messageEl;
            pulsoScrollToEnd();
        }


        function hideTyping() {
            if (typingBubble && typingBubble.parentNode) {
                typingBubble.parentNode.removeChild(typingBubble);
            }
            typingBubble = null;
        }


        function showLoading(show, label) {
            if (show) {
                showTyping();
            } else {
                hideTyping();
            }
        }

        
        function showFollowupQuestions(questions) {
            if (!questions || questions.length === 0) return;

            // Frontend safety net: descartar sugerencias inválidas como '?'
            const validQuestions = questions.filter(function(q) {
                if (typeof q !== 'string') return false;
                const s = q.trim();
                if (!s) return false;
                if (/^[¿?\s]+$/.test(s)) return false;
                if (s.length < 12) return false;
                return true;
            }).slice(0, 3);

            if (validQuestions.length === 0) return;
            
            const messagesDiv = document.getElementById('pulso-messages');
            const containerEl = document.createElement('div');
            containerEl.className = 'pulso-followup-container';
            
            // Crear chip para cada pregunta sugerida
            validQuestions.forEach(function(question) {
                const chip = document.createElement('button');
                chip.className = 'pulso-followup-chip';
                chip.type = 'button';
                chip.textContent = question;
                chip.title = question;
                
                // Al hacer click, poblar el input y enviar
                chip.addEventListener('click', function() {
                    const input = document.getElementById('pulso-input');
                    input.value = question;
                    updateCharCount();
                    
                    // Enviar automáticamente
                    const form = document.getElementById('pulso-chat-form');
                    form.dispatchEvent(new Event('submit'));
                    input.focus();
                });
                
                containerEl.appendChild(chip);
            });
            
            // Agregar contenedor al final del chat
            messagesDiv.appendChild(containerEl);
            
            // Auto scroll
            pulsoScrollToEnd();
        }

        
        // ========== FLOATING WINDOW LOGIC ==========
        
        const floatingState = {
            isOpen: false,
            isDragging: false,
            dragOffsetX: 0,
            dragOffsetY: 0
        };

        
        let pulsoChatWide = false;


        function toggleChat() {
            const container = document.getElementById('pulso-chat-container');
            const bubble = document.getElementById('pulso-chat-bubble');
            const header = document.getElementById('pulso-chat-header');

            if (!floatingState.isOpen) {
                // Abrir chat
                floatingState.isOpen = true;
                container.classList.add('is-open');
                bubble.style.display = 'none';
                bubble.setAttribute('aria-expanded', 'true');

                // Convert auto height (from top+bottom pins) to explicit px so CSS resize works.
                container.style.height = container.offsetHeight + 'px';
                container.style.width  = container.offsetWidth  + 'px';

                // Clamp size to viewport whenever the user drags the resize handle.
                if (!container._resizeObserver && window.ResizeObserver) {
                    container._resizeObserver = new ResizeObserver(function() {
                        var maxH = window.innerHeight - 112;
                        var maxW = window.innerWidth  - 48;
                        if (container.offsetHeight > maxH) container.style.height = maxH + 'px';
                        if (container.offsetWidth  > maxW) container.style.width  = maxW + 'px';
                    });
                    container._resizeObserver.observe(container);
                }

                // Hacer draggable
                header.addEventListener('mousedown', startDrag);

                // Al abrir, foco al campo de texto
                setTimeout(function() {
                    var input = document.getElementById('pulso-input');
                    if (input && floatingState.isOpen) input.focus();
                }, 100);

                // Scroll al final de los mensajes
                pulsoScrollToEnd();

                pulsoLog('✅ Chat abierto');
            } else {
                // Minimizar a burbuja
                floatingState.isOpen = false;
                if (pulsoMicRecording && pulsoRecognition) pulsoRecognition.stop();
                container.classList.remove('is-open');
                container.style.cssText = '';
                pulsoChatWide = false;
                pulsoSyncSizeButton();
                bubble.style.display = 'flex';
                bubble.setAttribute('aria-expanded', 'false');

                // Si hay mensajes de conversación, marcar burbuja
                if (S.conversationHistory && S.conversationHistory.length > 0) {
                    bubble.classList.add('has-chat');
                }

                header.removeEventListener('mousedown', startDrag);

                // Al minimizar/cerrar, el foco vuelve a la burbuja (si no, se pierde en el vacío).
                bubble.focus();
                pulsoLog('✅ Chat minimizado a burbuja');
            }
        }


        // Ampliar / Reducir (640 px ↔ 90 vw): hasta ahora solo se redimensionaba con el ratón
        // (resize: both). Al ampliar se suelta la posición arrastrada para que no se salga de la pantalla.
        function toggleChatSize() {
            const container = document.getElementById('pulso-chat-container');
            if (!container || !floatingState.isOpen) return;
            pulsoChatWide = !pulsoChatWide;
            const spare = container.classList.contains('drawer-collapsed') ? 180 : 130;
            container.style.left = '';
            container.style.top = '';
            container.style.right = '';
            container.style.bottom = '';
            if (pulsoChatWide) {
                container.style.width = Math.round(window.innerWidth * 0.9) + 'px';
                container.style.height = Math.max(300, window.innerHeight - spare) + 'px';
            } else {
                container.style.width = Math.min(640, window.innerWidth - 48) + 'px';
                container.style.height = Math.min(600, window.innerHeight - spare) + 'px';
            }
            pulsoSyncSizeButton();
        }


        function pulsoSyncSizeButton() {
            const btn = document.getElementById('pulso-expand-btn');
            if (!btn) return;
            const label = pulsoChatWide ? 'Reducir el chat' : 'Ampliar el chat';
            btn.setAttribute('aria-label', label);
            btn.title = pulsoChatWide ? 'Reducir' : 'Ampliar';
            btn.innerHTML = pulsoChatWide
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
        }


        function clearConversation() {
            const hasHistory = S.conversationHistory && S.conversationHistory.length > 0;
            if (hasHistory && !confirm('¿Empezar una conversación nueva? Se borrará el historial de este chat.')) {
                return;
            }

            S.conversationHistory = [];
            try {
                sessionStorage.removeItem('pulso_history_' + cfg.courseid);
            } catch (e) {
                // sessionStorage no disponible — no pasa nada, el estado en memoria ya está limpio.
            }

            // Quitar solo los mensajes, el separador del historial y las sugerencias del registro
            // (#pulso-messages). La home y el panel Crear viven fuera de él y se conservan.
            const messagesDiv = document.getElementById('pulso-messages');
            if (messagesDiv) {
                messagesDiv.querySelectorAll('.pulso-message, .pulso-followup-container, .pulso-history-divider').forEach(function(el) {
                    el.remove();
                });
            }
            removeStreamBubble();
            showLoading(false);
            setHomeVisible(true);

            const bubble = document.getElementById('pulso-chat-bubble');
            if (bubble) {
                bubble.classList.remove('has-chat');
            }

            const input = document.getElementById('pulso-input');
            if (input) {
                input.focus();
            }
        }


        function startDrag(e) {
            if (e.button !== 0) return;
            if (!floatingState.isOpen) return;
            // A pantalla completa (móvil) no se arrastra.
            if (window.matchMedia && window.matchMedia('(max-width: 480px)').matches) return;
            
            e.preventDefault();
            
            const container = document.getElementById('pulso-chat-container');
            floatingState.isDragging = true;
            
            floatingState.dragOffsetX = e.clientX - container.offsetLeft;
            floatingState.dragOffsetY = e.clientY - container.offsetTop;
            
            document.addEventListener('mousemove', doDrag);
            document.addEventListener('mouseup', stopDrag);
        }


        function doDrag(e) {
            if (!floatingState.isDragging) return;
            
            e.preventDefault();
            
            const container = document.getElementById('pulso-chat-container');
            
            // Calcular nueva posición y limitar dentro de la pantalla
            let newX = e.clientX - floatingState.dragOffsetX;
            let newY = e.clientY - floatingState.dragOffsetY;
            newX = Math.max(0, Math.min(newX, window.innerWidth - container.offsetWidth));
            newY = Math.max(0, Math.min(newY, window.innerHeight - container.offsetHeight));
            
            container.style.left = newX + 'px';
            container.style.top = newY + 'px';
            container.style.right = 'auto';
            container.style.bottom = 'auto';
        }

        
        function stopDrag() {
            floatingState.isDragging = false;
            document.removeEventListener('mousemove', doDrag);
            document.removeEventListener('mouseup', stopDrag);
        }


        function isBlocksDrawerCollapsed() {
            const body = document.body;

            // 1) Deteccion por clases globales en body (si existen).
            if (body.classList.contains('drawer-open-right') || body.classList.contains('drawer-right-open')) {
                return false;
            }
            if (body.classList.contains('drawer-closed-right') || body.classList.contains('drawer-right-closed')) {
                return true;
            }

            // 2) Deteccion por toggle oficial de Moodle Boost.
            const rightToggle = document.querySelector('[data-action="toggle-drawer"][data-side="right"]');
            if (rightToggle) {
                const expanded = rightToggle.getAttribute('aria-expanded');
                if (expanded !== null) {
                    return expanded === 'false';
                }
            }

            // 3) Deteccion por estado visible del drawer derecho.
            const rightDrawer = document.querySelector('#theme_boost-drawers-blocks, [data-region="right-hand-drawer"], .drawer-right');
            if (rightDrawer) {
                const ariaHidden = rightDrawer.getAttribute('aria-hidden');
                if (ariaHidden !== null) {
                    return ariaHidden === 'true';
                }

                const drawerClasses = rightDrawer.className || '';
                if (/\bshow\b|\bopen\b|\bvisible\b/i.test(drawerClasses)) {
                    return false;
                }
                if (/\bcollapsed\b|\bhidden\b/i.test(drawerClasses)) {
                    return true;
                }

                const styles = window.getComputedStyle(rightDrawer);
                if (styles.display === 'none' || styles.visibility === 'hidden') {
                    return true;
                }
            }

            // 4) Fallback por texto del control (locale ES/EN y con/sin tilde).
            const toggler = document.querySelector(
                '[data-target*="blocks"], [aria-label*="cajón de bloques" i], [aria-label*="cajon de bloques" i], [title*="cajón de bloques" i], [title*="cajon de bloques" i], [aria-label*="block drawer" i], [title*="block drawer" i]'
            );

            if (!toggler) {
                return false;
            }

            const text = ((toggler.getAttribute('aria-label') || '') + ' ' + (toggler.getAttribute('title') || '')).toLowerCase();
            if (text.includes('abrir') || text.includes('open')) {
                return true;
            }

            return false;
        }


        function updateBubblePositionByDrawerState() {
            const bubble = document.getElementById('pulso-chat-bubble');
            const container = document.getElementById('pulso-chat-container');
            if (!bubble || !container) return;

            const collapsed = isBlocksDrawerCollapsed();
            bubble.classList.toggle('drawer-collapsed', collapsed);
            container.classList.toggle('drawer-collapsed', collapsed);
        }

        
        function pulsoBoot() {
            // Mover el chat y la burbuja al body para que sean independientes del bloque
            var container = document.getElementById('pulso-chat-container');
            var bubble = document.getElementById('pulso-chat-bubble');

            // Ocultar visualmente el bloque de Pulso en el cajon lateral.
            var sourceEl = bubble || container;
            var pulsoBlock = sourceEl ? (sourceEl.closest('.block_pulso') || sourceEl.closest('.block')) : null;
            if (pulsoBlock) {
                pulsoBlock.style.display = 'none';
                pulsoBlock.setAttribute('aria-hidden', 'true');
            }

            if (container) document.body.appendChild(container);
            if (bubble) document.body.appendChild(bubble);

            // Delegacion de eventos (sustituye a los onclick/oninput/onsubmit en linea).
            if (container) C.bindActions(container);
            if (bubble) C.bindActions(bubble);
            var chatForm = document.getElementById('pulso-chat-form');
            if (chatForm) chatForm.addEventListener('submit', sendMessage);

            // Ajustar posicion de burbuja cuando cambia el estado del cajon de bloques.
            updateBubblePositionByDrawerState();
            window.addEventListener('resize', updateBubblePositionByDrawerState);

            // Recalcular luego de cualquier click para cubrir toggles con tooltips/icons internos.
            document.addEventListener('click', function() {
                setTimeout(updateBubblePositionByDrawerState, 120);
                setTimeout(updateBubblePositionByDrawerState, 260);
            });

            const bodyObserver = new MutationObserver(function() {
                updateBubblePositionByDrawerState();
            });
            bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

            const rightToggle = document.querySelector('[data-action="toggle-drawer"][data-side="right"]');
            if (rightToggle) {
                const toggleObserver = new MutationObserver(function() {
                    updateBubblePositionByDrawerState();
                });
                toggleObserver.observe(rightToggle, { attributes: true, attributeFilter: ['aria-expanded', 'class', 'title', 'aria-label'] });
            }

            const rightDrawer = document.querySelector('#theme_boost-drawers-blocks, [data-region="right-hand-drawer"], .drawer-right');
            if (rightDrawer) {
                const drawerObserver = new MutationObserver(function() {
                    updateBubblePositionByDrawerState();
                });
                drawerObserver.observe(rightDrawer, { attributes: true, attributeFilter: ['class', 'style', 'aria-hidden'] });
            }
            
            // Dictado por voz (se auto-oculta si el navegador no lo soporta)
            initPulsoMic();

            // Listener para contador de caracteres en tiempo real
            const inputElement = document.getElementById('pulso-input');
            if (inputElement) {
                inputElement.addEventListener('input', updateCharCount);
                // keydown (keypress está obsoleto) y sin enviar mientras se compone con un IME
                // (japonés, chino, acentos muertos): Enter confirma la composición, no envía.
                inputElement.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
                        e.preventDefault();
                        sendMessage(new Event('submit'));
                    }
                });
            }
            updateCharCount();

            // Escape dentro del chat lo cierra y devuelve el foco a la burbuja.
            if (container) {
                container.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && floatingState.isOpen && !e.defaultPrevented) {
                        e.preventDefault();
                        toggleChat();
                    }
                });
            }

            // Tras repintar el panel Crear (innerHTML) el elemento enfocado desaparece y el foco se
            // pierde: se lleva al título de la nueva pantalla. Un repintado parcial (la píldora de
            // estado) no toca los hijos directos, así que no mueve el foco.
            const createBodyEl = document.getElementById('pulso-create-body');
            if (createBodyEl && window.MutationObserver) {
                new MutationObserver(function() { C.fn.pulsoCreateScreen(); }).observe(createBodyEl, { childList: true });
            }

            // Historial de la sesión: se pinta, no solo se reenvía al modelo.
            pulsoRenderHistory();

            // Sin conexión: aviso persistente en el chat.
            if (navigator.onLine === false) pulsoSetOffline(true);
            window.addEventListener('offline', function() { pulsoSetOffline(true); });
            window.addEventListener('online', function() {
                pulsoSetOffline(false);
                pulsoAnnounce('Conexión recuperada');
            });

            // Los sondeos (Crear 7 s, Retos 4 s) se pausan con la pestaña en segundo plano.
            document.addEventListener('visibilitychange', C.fn.pulsoOnVisibilityChange);
        }

    /**
     * Punto de entrada (js_call_amd). Recibe la configuracion que antes viajaba en window.*.
     *
     * @param {Object} config courseid, URLs de los endpoints, sesskey, isTeacher, epicaAvailable
     */
    var init = function(config) {
        Object.keys(config || {}).forEach(function(k) {
            cfg[k] = config[k];
        });
        // Historial de la sesion (sessionStorage persiste entre recargas).
        try {
            var savedHistory = sessionStorage.getItem('pulso_history_' + cfg.courseid);
            S.conversationHistory = savedHistory ? JSON.parse(savedHistory) : [];
        } catch (e) {
            S.conversationHistory = [];
        }
        S.tableState = S.tableState || {};
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', pulsoBoot);
        } else {
            pulsoBoot();
        }
    };
    C.fn.toggleChat = toggleChat;
    C.fn.clearConversation = clearConversation;
    C.fn.toggleChatSize = toggleChatSize;
    C.fn.showCapabilities = showCapabilities;
    C.fn.askPreset = askPreset;
    C.fn.toggleMic = toggleMic;

    return {init: init};
});
