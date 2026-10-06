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
 * Pestana Crear de Pulse AI: formulario, estado de una creacion, galeria e historial.
 *
 * @module     block_pulso/crear
 * @copyright  2026 Awakelab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['block_pulso/common'], function(C) {
    'use strict';

    var S = C.S;
    var cfg = C.cfg;
    var PULSO_NEWTAB_SR = C.PULSO_NEWTAB_SR;
    var pulsoAnnounce = C.pulsoAnnounce;
    var pulsoSetDisabled = C.pulsoSetDisabled;
    var pulsoEscapeAttr = C.pulsoEscapeAttr;
    var pulsoAmpIsHttps = C.pulsoAmpIsHttps;
    var escapeHtmlText = C.escapeHtmlText;



        // ========== CREAR INFOGRAFÍA (encargo a Epica) ==========
        // Es una PANTALLA propia, no un mensaje del chat: si se colara como
        // mensaje acabaría en el historial que viaja a Anthropic en cada
        // petición. #pulso-create-panel sustituye a la home/mensajes
        // alternando la clase 'pulso-showing-create' en #pulso-messages.
        // De momento no manda nada a Epica (eso es el paso 4): solo valida,
        // comprueba cupo y guarda el encargo como "pendiente".



        // Un único formulario parametrizado por herramienta, no una copia por
        // herramienta (CLAUDE.md, paso 1: "añadirlos es repetir este mismo
        // patrón"). pulsoCreateTool decide textos, si se pide formato y qué
        // envía submitCreate() — el desplegable de recursos y los cupos son
        // iguales para las dos (contador conjunto, CLAUDE.md paso 3).
        let pulsoCreateTool = 'infografia';


        // Tarjeta que abrió el panel: recibe el foco al cerrarlo.
        let pulsoCreateOpener = null;


        function pulsoCreateIsOpen() {
            const scroller = document.getElementById('pulso-scroll');
            return !!(scroller && scroller.classList.contains('pulso-showing-create'));
        }


        // Foco al título (h3 con tabindex="-1") de la pantalla nueva. Con `force` siempre (al abrir);
        // si no, solo cuando el foco se ha perdido (el elemento enfocado se repintó) o seguía dentro
        // del cuerpo del panel: nunca le quita el foco a quien está escribiendo en el chat.
        function pulsoCreateScreen(force) {
            const title = document.getElementById('pulso-create-title');
            if (!title || !pulsoCreateIsOpen()) return;
            const active = document.activeElement;
            const body = document.getElementById('pulso-create-body');
            if (force || !active || active === document.body || (body && body.contains(active))) {
                title.focus();
            }
        }


        const PULSO_CREATE_TOOL_LABELS = {
            infografia: {
                panelTitle: 'Crear infografía',
                promptLabel: '¿Qué infografía quieres?',
                promptPlaceholder: 'Ej: Una infografía que resuma las fases del proceso para repasarlas de un vistazo.',
                submitLabel: 'Crear infografía',
                noResourcesDefault: 'No hay recursos disponibles para crear una infografía en este curso.',
                noUsableReason: 'Ninguno de los recursos de este curso tiene texto que se pueda aprovechar para generar una infografía.'
            },
            gamificacion: {
                panelTitle: 'Crear juego',
                promptLabel: '¿Qué juego quieres?',
                promptPlaceholder: 'Ej: Un juego de emparejar términos con su definición, diez pares.',
                submitLabel: 'Crear juego',
                noResourcesDefault: 'No hay recursos disponibles para crear un juego en este curso.',
                noUsableReason: 'Ninguno de los recursos de este curso tiene texto que se pueda aprovechar para generar un juego.'
            },
            // Ampliación no es una creación de Épica: sin texto libre, sin formato
            // ni cupos de encargos (tiene sus propios topes en el servidor).
            ampliacion: {
                panelTitle: 'Ampliar recurso',
                submitLabel: 'Ampliar',
                noResourcesDefault: 'No hay recursos disponibles para ampliar en este curso.',
                noUsableReason: 'Ninguno de los recursos de este curso tiene texto que se pueda aprovechar para buscar vídeos y artículos.'
            },
            // Retos (Épica, vía api_retos.php): recurso O tema. Sin recursos sigue
            // siendo usable (solo tema), así que no hay textos de "sin recursos".
            retos: {
                panelTitle: 'Crear reto',
                submitLabel: 'Proponer retos'
            }
        };


        // Tres tipos de juego ya escritos (carta 9 de Épica, §2). Los textos de
        // "peticion" están copiados LITERALMENTE de la carta: cada detalle tiene un
        // motivo (§4) — "sobre el contenido de «{tema}»" para que el tema lo siga
        // poniendo el material, "hasta" para no obligar a inventar datos, "tocando,
        // sin arrastrar" para el móvil. No se retocan sin leer esa tabla. {tema} es
        // el nombre del recurso elegido (pulsoJuegoTema()).
        const PULSO_JUEGO_EJEMPLOS = [
            {
                id: 'preguntas',
                etiqueta: 'Preguntas con pista',
                descripcion: 'Un test de opción múltiple sobre {tema}',
                peticion: 'Un juego de preguntas de opción múltiple sobre el contenido de «{tema}»: hasta diez preguntas con cuatro respuestas cada una y una pista opcional por pregunta, que si se usa da menos puntos. Al responder, se dice si es correcta y por qué. Al terminar, la puntuación y un repaso de las preguntas falladas con su respuesta correcta.'
            },
            {
                id: 'emparejar',
                etiqueta: 'Emparejar conceptos',
                descripcion: 'Une cada concepto de {tema} con su definición',
                peticion: 'Un juego de emparejar sobre el contenido de «{tema}»: hasta ocho conceptos clave, cada uno con su definición o explicación tal como aparecen en el material. Se empareja tocando un concepto y después su definición, sin arrastrar, para que funcione igual con ratón, con teclado y en el móvil. Las parejas acertadas quedan fijadas y cada fallo resta puntos. Al terminar, la puntuación y la lista de todas las parejas correctas.'
            },
            {
                id: 'completar',
                etiqueta: 'Completar frases',
                descripcion: 'Elige la palabra que falta en frases de {tema}',
                peticion: 'Un juego de completar frases sobre el contenido de «{tema}»: hasta diez frases importantes del material a las que les falta una palabra clave, que hay que elegir entre tres opciones. Las opciones incorrectas son otras palabras del mismo material. Al responder, se enseña la frase completa. Al terminar, la puntuación y las frases falladas con su palabra correcta.'
            }
        ];


        // Último texto que puso un chip ({idx, text}) o null. Sirve para saber si el
        // usuario lo ha tocado: solo un ejemplo SIN editar se re-escribe al cambiar
        // de recurso. pulsoJuegoRenderedTema evita reconstruir los chips en cada input.
        let pulsoJuegoFill = null;

        let pulsoJuegoRenderedTema = null;


        // {tema} = name del recurso (campo del servidor, nunca el texto de la
        // <option>, que lleva el prefijo de tipo y el aviso de texto escaso) sin la
        // extensión final de archivo.
        function pulsoJuegoTema() {
            const select = document.getElementById('pulso-create-resource');
            if (!select) return '';
            const cmid = parseInt(select.value, 10);
            const resource = S.pulsoCreateResources.find(function(r) { return r.cmid === cmid; });
            if (!resource) return '';
            return String(resource.name || '').replace(/\.[A-Za-z]{2,5}$/, '').trim();
        }


        // split/join y no String.replace: el tema puede traer "$&" o similares.
        function pulsoJuegoFillText(text, tema) {
            return text.split('{tema}').join(tema);
        }


        // Pinta los chips (si el tema cambió) y los muestra solo con tema válido y el
        // cuadro vacío. resourceChanged: re-escribe un ejemplo sin editar con el
        // tema nuevo; si el usuario lo había editado, no lo toca.
        function pulsoJuegoRefresh(resourceChanged) {
            const box = document.getElementById('pulso-juego-ejemplos');
            const promptEl = document.getElementById('pulso-create-prompt');
            if (!box || !promptEl) return;

            const tema = pulsoJuegoTema();

            if (resourceChanged && pulsoJuegoFill) {
                if (promptEl.value === pulsoJuegoFill.text) {
                    if (tema) {
                        const text = pulsoJuegoFillText(PULSO_JUEGO_EJEMPLOS[pulsoJuegoFill.idx].peticion, tema);
                        promptEl.value = text;
                        pulsoJuegoFill.text = text;
                    } else {
                        promptEl.value = '';
                        pulsoJuegoFill = null;
                    }
                } else {
                    pulsoJuegoFill = null;
                }
            }

            if (tema !== pulsoJuegoRenderedTema) {
                pulsoJuegoRenderedTema = tema;
                let html = '';
                if (tema) {
                    html = '<div class="pulso-create-ejemplos-label">Prueba con:</div><div class="pulso-create-ejemplos-list">';
                    PULSO_JUEGO_EJEMPLOS.forEach(function(ej, i) {
                        const desc = pulsoJuegoFillText(ej.descripcion, tema);
                        html += '<button type="button" class="pulso-create-ejemplo" data-ejemplo="' + i + '" title="' + pulsoEscapeAttr(desc) + '">'
                            + '<span class="pulso-create-ejemplo-title">' + escapeHtmlText(ej.etiqueta) + '</span>'
                            + '<span class="pulso-create-ejemplo-desc">' + escapeHtmlText(desc) + '</span>'
                            + '</button>';
                    });
                    html += '</div>';
                }
                box.innerHTML = html;
            }

            box.style.display = (tema && promptEl.value.trim() === '') ? '' : 'none';
        }


        // Un clic rellena el cuadro y NADA más: ni submitCreate() ni ninguna petición.
        function pulsoJuegoPickEjemplo(idx) {
            const promptEl = document.getElementById('pulso-create-prompt');
            const ej = PULSO_JUEGO_EJEMPLOS[idx];
            const tema = pulsoJuegoTema();
            if (!promptEl || !ej || !tema) return;

            const text = pulsoJuegoFillText(ej.peticion, tema);
            promptEl.value = text;
            pulsoJuegoFill = { idx: idx, text: text };
            promptEl.focus();
            promptEl.setSelectionRange(text.length, text.length);
            pulsoJuegoRefresh(false);
        }


        function pulsoCreateSetTool(tool) {
            pulsoCreateTool = (tool === 'gamificacion' || tool === 'ampliacion' || tool === 'retos') ? tool : 'infografia';
            const titleEl = document.getElementById('pulso-create-title');
            if (titleEl) titleEl.textContent = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool].panelTitle;
        }


        function openCreatePanel(tool) {
            const messagesDiv = document.getElementById('pulso-scroll');
            const body = document.getElementById('pulso-create-body');
            if (!messagesDiv || !body) return;

            // Solo la primera vez: «Crear otro» y «Volver al formulario» se llaman desde dentro.
            if (!messagesDiv.classList.contains('pulso-showing-create')) {
                const active = document.activeElement;
                pulsoCreateOpener = (active && active !== document.body) ? active : null;
            }

            pulsoCreateSetTool(tool || pulsoCreateTool);
            stopCreatePolling();
            S.pulsoAmpToken++;
            messagesDiv.classList.add('pulso-showing-create');
            body.innerHTML = '<p class="pulso-create-hint">Cargando…</p>';
            pulsoCreateScreen(true);

            const params = new URLSearchParams();
            params.set('courseid', cfg.courseid);
            params.set('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            if (pulsoCreateTool === 'ampliacion' || pulsoCreateTool === 'retos') params.set('tool', pulsoCreateTool);

            fetch(cfg.apiCreateFormUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        renderCreateNotice(data.message || 'No se ha podido comprobar el cupo. Inténtalo de nuevo.');
                        return;
                    }
                    if (!data.quota_ok) {
                        renderCreateNotice(data.quota_message || 'Has alcanzado el límite de encargos.');
                        return;
                    }
                    // Retos sigue siendo usable sin recursos: queda la opción de solo tema.
                    if ((!data.resources || data.resources.length === 0) && pulsoCreateTool !== 'retos') {
                        renderCreateNoResources(data.noresourcesreason);
                        return;
                    }
                    S.pulsoCreateResources = data.resources || [];
                    renderCreateForm(data.resources);
                })
                .catch(function() {
                    renderCreateNotice('No se ha podido conectar para comprobar el cupo. Inténtalo de nuevo.');
                });
        }


        // opts.restoreFocus === false: al enviar un mensaje del chat el foco se queda en el campo de texto.
        function closeCreatePanel(opts) {
            stopCreatePolling();
            S.pulsoAmpToken++;
            const messagesDiv = document.getElementById('pulso-scroll');
            const wasOpen = pulsoCreateIsOpen();
            if (messagesDiv) {
                messagesDiv.classList.remove('pulso-showing-create');
            }
            if (wasOpen && !(opts && opts.restoreFocus === false)) {
                // Al cerrar, el foco vuelve a la tarjeta que lo abrió (o al campo de texto si ya no está).
                const opener = pulsoCreateOpener;
                if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus();
                if (!opener || document.activeElement !== opener) {
                    const input = document.getElementById('pulso-input');
                    if (input) input.focus();
                }
            }
            pulsoCreateOpener = null;
        }


        // La galería de "últimas infografías" se enseña siempre debajo,
        // tenga o no cupo el usuario ahora mismo: una lámina de ayer no deja
        // de existir porque hoy no queden encargos.
        function renderCreateNotice(text) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            const isamp = pulsoCreateTool === 'ampliacion';
            body.innerHTML = '<div class="pulso-create-notice"></div>' + (isamp ? '' : '<div id="pulso-create-gallery"></div>');
            body.querySelector('.pulso-create-notice').textContent = text;
            if (!isamp) loadCreateGallery();
        }


        function renderCreateNoResources(reason) {
            const labels = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool];
            const messages = {
                'not_indexed': 'El curso aún no se ha procesado. Espera a la próxima pasada (es nocturna) o pide a quien administre el sitio que ejecute la tarea «Indexar el contenido del curso».',
                'no_usable': labels.noUsableReason,
                'no_visible': 'No tienes acceso a ningún recurso indexado de este curso.'
            };
            renderCreateNotice(messages[reason] || labels.noResourcesDefault);
        }


        function renderCreateForm(resources) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;

            const labels = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool];
            const isjuego = pulsoCreateTool === 'gamificacion';
            const isamp = pulsoCreateTool === 'ampliacion';

            if (pulsoCreateTool === 'retos') {
                C.fn.renderRetosForm(resources);
                return;
            }

            let optionsHtml = '';
            resources.forEach(function(r) {
                const flag = r.lowtext ? ' — texto escaso, resultado limitado' : '';
                optionsHtml += '<option value="' + r.cmid + '">'
                    + escapeHtmlText(r.moduletypelabel) + ': ' + escapeHtmlText(r.name) + escapeHtmlText(flag)
                    + '</option>';
            });

            // El formato es solo de las infografías (CLAUDE.md, paso 3): un
            // juego no lleva ese campo, ni en el formulario ni en el envío.
            const formatFieldHtml = isjuego ? '' : (
                '<div class="pulso-create-field">'
                + '<label for="pulso-create-format">Formato</label>'
                + '<select id="pulso-create-format">'
                + '<option value="poster_2_3">Póster (2:3)</option>'
                + '<option value="square">Cuadrado</option>'
                + '<option value="landscape_3_2">Horizontal (3:2)</option>'
                + '</select>'
                + '</div>'
            );

            // Ampliación: solo recurso + botón. Sin aviso de cupo por sección ni
            // galería (una ampliación es compartida por recurso, no del usuario).
            if (isamp) {
                body.innerHTML = ''
                    + '<div class="pulso-create-field">'
                    + '<label for="pulso-create-resource">Recurso</label>'
                    + '<select id="pulso-create-resource">' + optionsHtml + '</select>'
                    + '</div>'
                    + '<button type="button" class="pulso-create-submit" id="pulso-create-submit-btn" data-pulso-action="submitAmpliacion">'
                    + escapeHtmlText(labels.submitLabel) + '</button>';
                return;
            }

            body.innerHTML = ''
                + '<div class="pulso-create-field">'
                + '<label for="pulso-create-resource">Recurso</label>'
                + '<select id="pulso-create-resource">' + optionsHtml + '</select>'
                + '<div class="pulso-create-hint" id="pulso-create-section-hint"></div>'
                + '</div>'
                + '<div class="pulso-create-field">'
                + '<label for="pulso-create-prompt">' + escapeHtmlText(labels.promptLabel) + '</label>'
                + '<textarea id="pulso-create-prompt" maxlength="4000" placeholder="' + pulsoEscapeAttr(labels.promptPlaceholder) + '"></textarea>'
                + (isjuego ? '<div class="pulso-create-ejemplos" id="pulso-juego-ejemplos" style="display:none"></div>' : '')
                + '</div>'
                + formatFieldHtml
                + '<button type="button" class="pulso-create-submit" id="pulso-create-submit-btn" data-pulso-action="submitCreate">' + escapeHtmlText(labels.submitLabel) + '</button>'
                + '<div id="pulso-create-gallery"></div>';

            const select = document.getElementById('pulso-create-resource');
            if (select) {
                select.addEventListener('change', updateCreateSectionHint);
                updateCreateSectionHint();
            }

            if (isjuego) {
                pulsoJuegoFill = null;
                pulsoJuegoRenderedTema = null;
                const promptEl = document.getElementById('pulso-create-prompt');
                const box = document.getElementById('pulso-juego-ejemplos');
                if (select) select.addEventListener('change', function() { pulsoJuegoRefresh(true); });
                if (promptEl) promptEl.addEventListener('input', function() { pulsoJuegoRefresh(false); });
                if (box) {
                    box.addEventListener('click', function(e) {
                        const chip = e.target.closest('[data-ejemplo]');
                        if (chip) pulsoJuegoPickEjemplo(parseInt(chip.getAttribute('data-ejemplo'), 10));
                    });
                }
                pulsoJuegoRefresh(false);
            }
            loadCreateGallery();
        }


        // Avisa del cupo por sección ANTES de que el usuario escriba su
        // petición (con los datos que ya trajo el GET, sin otra petición):
        // un tope que se descubre después de teclear se lee como una avería.
        function updateCreateSectionHint() {
            const select = document.getElementById('pulso-create-resource');
            const hint = document.getElementById('pulso-create-section-hint');
            const btn = document.getElementById('pulso-create-submit-btn');
            if (!select || !hint) return;

            const cmid = parseInt(select.value, 10);
            const resource = S.pulsoCreateResources.find(function(r) { return r.cmid === cmid; });
            if (!resource) return;

            if (resource.sectionused >= resource.sectionlimit) {
                hint.textContent = 'Ya has llegado al límite de encargos de hoy para la sección de este recurso ('
                    + resource.sectionlimit + '). Elige otro recurso.';
                hint.classList.add('warn');
                if (btn) pulsoSetDisabled(btn, true);
            } else {
                hint.textContent = '';
                hint.classList.remove('warn');
                if (btn) pulsoSetDisabled(btn, false);
            }
        }


        function submitCreate() {
            const select = document.getElementById('pulso-create-resource');
            const promptEl = document.getElementById('pulso-create-prompt');
            const formatEl = document.getElementById('pulso-create-format');
            const btn = document.getElementById('pulso-create-submit-btn');
            if (!select || !promptEl) return;

            const isjuego = pulsoCreateTool === 'gamificacion';
            const labels = PULSO_CREATE_TOOL_LABELS[pulsoCreateTool];

            const prompt = promptEl.value.trim();
            if (!prompt) {
                promptEl.focus();
                return;
            }

            if (btn) {
                pulsoSetDisabled(btn, true);
                btn.textContent = 'Guardando…';
            }

            const formData = new FormData();
            formData.append('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            formData.append('courseid', cfg.courseid);
            formData.append('cmid', select.value);
            formData.append('prompt', prompt);
            formData.append('tool', pulsoCreateTool);
            if (!isjuego && formatEl) {
                formData.append('format', formatEl.value);
            }

            fetch(cfg.apiCreateSubmitUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        const body = document.getElementById('pulso-create-body');
                        if (body) body.innerHTML = '<p class="pulso-create-hint">Encargo guardado. Comprobando estado…</p>';
                        startCreatePolling(data.encargoid);
                    } else {
                        renderCreateNotice(data.message || 'No se ha podido guardar el encargo. Inténtalo de nuevo.');
                        if (btn) {
                            pulsoSetDisabled(btn, false);
                            btn.textContent = labels.submitLabel;
                        }
                    }
                })
                .catch(function() {
                    renderCreateNotice('No se ha podido conectar para guardar el encargo. Inténtalo de nuevo.');
                    if (btn) {
                        pulsoSetDisabled(btn, false);
                        btn.textContent = labels.submitLabel;
                    }
                });
        }


        // ---- Estado del encargo (paso 4): sondeo del panel, nunca de Epica ----
        // El navegador solo habla con api_create_status.php; quien sondea a
        // Epica de verdad es la tarea adhoc (classes/epica_client.php). Una
        // lámina tarda ~150s con concurrencia 2 en Epica, así que con una
        // clase entera encargando a la vez el último puede esperar casi una
        // hora: nada de spinner bloqueante, solo sondeo de fondo con la misma
        // cortesía de 30 minutos que aplica la tarea (CLAUDE.md).
        let pulsoCreatePollTimer = null;

        let pulsoCreatePollStart = 0;

        let pulsoCreatePollPending = null;
   // encargo cuyo próximo sondeo está pendiente (timer o pestaña oculta)
        let pulsoCreateStatusKey = '';
       // «pantalla» pintada: si no cambia, solo se actualiza la píldora
        let pulsoCreateAnnounced = '';
       // último estado anunciado al lector de pantalla
        const PULSO_CREATE_POLL_MS = 7000;

        const PULSO_CREATE_POLL_WINDOW_MS = 30 * 60 * 1000;


        function stopCreatePolling() {
            if (pulsoCreatePollTimer) {
                clearTimeout(pulsoCreatePollTimer);
                pulsoCreatePollTimer = null;
            }
            pulsoCreatePollPending = null;
            C.fn.pulsoRetosReset(); // sondeo y refresco de Retos, antidoble clic
        }


        function startCreatePolling(encargoid) {
            stopCreatePolling();
            pulsoCreatePollStart = Date.now();
            pulsoCreateStatusKey = '';
            pulsoCreateAnnounced = '';
            pollCreateStatusOnce(encargoid);
        }


        function pollCreateStatusOnce(encargoid) {
            // Mismo patron que Retos: si el usuario pulsa «Volver», abre otra
            // herramienta o cierra el panel mientras la peticion esta en vuelo,
            // pulsoAmpToken cambia y la respuesta tardia no pinta ni reprograma.
            const token = S.pulsoAmpToken;
            const params = new URLSearchParams();
            params.set('courseid', cfg.courseid);
            params.set('encargoid', encargoid);
            params.set('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');

            fetch(cfg.apiCreateStatusUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (token !== S.pulsoAmpToken) return; // el usuario ya salió de esta pantalla
                    if (!data.success) {
                        stopCreatePolling();
                        renderCreateNotice(data.message || 'No se ha podido comprobar el estado del encargo.');
                        return;
                    }
                    renderCreateStatus(data.encargo);
                    if (!data.encargo.terminal) {
                        scheduleNextCreatePoll(encargoid);
                    } else {
                        // Estado terminal: la galeria se refresca UNA vez (hay una creacion nueva).
                        loadCreateGallery();
                    }
                })
                .catch(function() {
                    if (token !== S.pulsoAmpToken) return;
                    // Fallo de red puntual: no dar el encargo por perdido,
                    // seguir intentando mientras quede ventana.
                    scheduleNextCreatePoll(encargoid);
                });
        }


        function scheduleNextCreatePoll(encargoid) {
            if (Date.now() - pulsoCreatePollStart >= PULSO_CREATE_POLL_WINDOW_MS) {
                appendCreateStatusNotice(
                    'Esto está tardando más de lo normal. Hemos dejado de comprobarlo aquí automáticamente: '
                    + 'te avisaremos en cuanto esté ' + (pulsoCreateTool === 'gamificacion' ? 'listo' : 'lista') + '.', 'warn'
                );
                return;
            }
            // Pestaña en segundo plano: no se programa nada; se reanuda en visibilitychange.
            pulsoCreatePollPending = encargoid;
            if (document.hidden) return;
            pulsoCreatePollTimer = setTimeout(pulsoCreatePollTick, PULSO_CREATE_POLL_MS);
        }


        function pulsoCreatePollTick() {
            pulsoCreatePollTimer = null;
            if (document.hidden || pulsoCreatePollPending === null) return;
            const id = pulsoCreatePollPending;
            pulsoCreatePollPending = null;
            pollCreateStatusOnce(id);
        }


        // Una sola escucha para los dos sondeos (Crear cada 7 s, Retos cada 4 s): con la pestaña
        // oculta se paran los temporizadores y al volver se retoma enseguida lo que estaba pendiente.
        function pulsoOnVisibilityChange() {
            if (document.hidden) {
                if (pulsoCreatePollTimer) { clearTimeout(pulsoCreatePollTimer); pulsoCreatePollTimer = null; }
                if (S.pulsoRetosPollTimer) { clearTimeout(S.pulsoRetosPollTimer); S.pulsoRetosPollTimer = null; }
                if (S.pulsoRetosRefreshTimer) { clearTimeout(S.pulsoRetosRefreshTimer); S.pulsoRetosRefreshTimer = null; }
                return;
            }
            if (pulsoCreatePollPending !== null && !pulsoCreatePollTimer) {
                pulsoCreatePollTick();
            }
            if (S.pulsoRetosPollPending !== null && !S.pulsoRetosPollTimer) {
                const token = S.pulsoRetosPollPending;
                S.pulsoRetosPollPending = null;
                C.fn.pulsoRetosPoll(token);
            }
            if (S.pulsoRetosRefreshPending && !S.pulsoRetosRefreshTimer) {
                const p = S.pulsoRetosRefreshPending;
                S.pulsoRetosRefreshPending = null;
                C.fn.pulsoRetosRefrescar(p.token, p.codigo, p.started);
            }
        }


        function appendCreateStatusNotice(text, kind) {
            const container = document.querySelector('.pulso-create-status');
            if (!container) return;
            const div = document.createElement('div');
            div.className = 'pulso-create-notice-inline' + (kind ? ' ' + kind : '');
            div.textContent = text;
            container.appendChild(div);
        }


        function pulsoCreateStatusLabel(status, tool) {
            // Mismo criterio de género que notify_completion(): juego = masculino.
            const esjuego = tool === 'gamificacion';
            const labels = {
                pendiente: 'En preparación',
                encolado: 'En cola',
                trabajando: 'Generando',
                listo: esjuego ? 'Listo' : 'Lista',
                fallado: 'No se pudo generar',
                desconocido: 'No se pudo generar',
                ensayo: 'Modo de ensayo'
            };
            return labels[status] || status;
        }


        function pulsoCreateStatusPillClass(status) {
            if (status === 'listo') return 'success';
            if (status === 'fallado' || status === 'desconocido') return 'danger';
            if (status === 'ensayo') return 'warning';
            return 'neutral';
        }


        // Dos motivos que NO pueden dar el mismo mensaje: "cuota-agotada" es
        // el cupo propio del usuario ("vuelve a intentarlo en un rato");
        // "cuota-del-centro" es el cupo diario de TODO el centro, y esta
        // persona no ha gastado nada — decirle "has pedido varias seguidas"
        // la manda a buscar un error suyo que no existe. "material-ilegible"
        // es fallo nuestro, nunca del usuario.
        function pulsoCreateFailureMessage(encargo) {
            const motivo = String(encargo.motivo || '');
            const esjuego = encargo.tool === 'gamificacion';
            // "desconocido": Épica ya no reconoce el trabajo (p. ej. reinicio
            // del servicio a mitad de generación). El encargo SÍ cuenta en
            // nuestros topes, así que no se promete lo contrario.
            if (encargo.status === 'desconocido') {
                return (esjuego ? 'Este juego' : 'Esta infografía')
                    + ' se ha perdido en el servicio de generación. No es un error tuyo: crea '
                    + (esjuego ? 'uno nuevo.' : 'una nueva.');
            }
            if (/cuota-agotada/i.test(motivo)) {
                return 'Has pedido varias creaciones seguidas y se ha agotado tu cupo de generación. '
                    + 'Vuelve a intentarlo en unos minutos.';
            }
            if (/cuota-del-centro/i.test(motivo)) {
                return 'El centro ha alcanzado su límite de generaciones de hoy. No es nada que hayas hecho tú: '
                    + 'vuelve a intentarlo más tarde.';
            }
            if (/material-ilegible/i.test(motivo)) {
                return 'Hubo un problema para leer el material de este recurso. No es culpa tuya: lo estamos revisando.';
            }
            // Nunca se enseña el texto de relleno de marcar_fallo()
            // ("Sin motivo especificado.") ni un motivo vacío.
            if (!motivo.trim() || /^sin motivo especificado\.?$/i.test(motivo.trim())) {
                return 'No se ha podido generar. Prueba a crear un encargo nuevo.';
            }
            return 'No se ha podido generar ' + (esjuego ? 'el juego' : 'la infografía') + ': ' + motivo;
        }


        function renderCreateStatus(encargo) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;

            const esjuego = encargo.tool === 'gamificacion';
            pulsoCreateSetTool(esjuego ? 'gamificacion' : 'infografia');

            const pillClass = pulsoCreateStatusPillClass(encargo.status);
            const pillLabel = pulsoCreateStatusLabel(encargo.status, encargo.tool);

            // Estimación de juegos (carta 6 C3): posicion × 35s en cola, y
            // "casi listo" trabajando en vez del tiempo genérico de lámina.
            // Para infografías se mantiene el texto de siempre.
            let progressLine = '';
            if (encargo.status === 'encolado') {
                if (esjuego && encargo.posicion) {
                    const estSeconds = encargo.posicion * 35;
                    const estLabel = estSeconds < 60 ? (estSeconds + ' s') : (Math.round(estSeconds / 60) + ' min');
                    progressLine = 'Tienes ' + encargo.posicion + ' por delante · unos ' + estLabel;
                } else {
                    progressLine = encargo.posicion
                        ? ('Posición en cola: ' + encargo.posicion)
                        : 'Esperando turno en la cola de Épica.';
                }
            } else if (encargo.status === 'trabajando') {
                progressLine = esjuego
                    ? 'Casi listo, suele tardar alrededor de un minuto.'
                    : 'Generando la lámina… puede tardar un par de minutos.';
            } else if (encargo.status === 'pendiente') {
                progressLine = esjuego ? 'Generando tu juego…' : 'Generando tu infografía…';
            }

            // El sondeo llega cada 7 s: repintar todo hacía perder el foco al usuario de teclado y
            // obligaba al lector a releer la pantalla. La «pantalla» es el encargo sin la posición
            // en cola ni la marca de modificación; si no cambia, solo se actualizan la píldora y la
            // línea de progreso, que son lo único que se mueve (posición en cola, etc.).
            const slow = !encargo.terminal && (Date.now() - pulsoCreatePollStart) >= 120000;
            const keyObj = Object.assign({}, encargo);
            delete keyObj.posicion;
            delete keyObj.timemodified;
            const screenKey = JSON.stringify(keyObj) + '|' + (slow ? 1 : 0);
            // Lo que oye el lector de pantalla: solo el cambio («En cola. Tienes 2 por delante», «Lista»).
            const announceText = pillLabel + (progressLine ? '. ' + progressLine : '');

            if (screenKey === pulsoCreateStatusKey && body.querySelector('.pulso-create-status')) {
                const pill = document.getElementById('pulso-create-pill');
                if (pill) {
                    pill.className = 'pulso-status-pill ' + pillClass;
                    pill.textContent = pillLabel;
                }
                const prog = document.getElementById('pulso-create-progress');
                if (prog && progressLine) prog.textContent = progressLine;
                if (announceText !== pulsoCreateAnnounced) {
                    pulsoCreateAnnounced = announceText;
                    pulsoAnnounce(announceText);
                }
                return;
            }
            pulsoCreateStatusKey = screenKey;
            if (announceText !== pulsoCreateAnnounced) {
                pulsoCreateAnnounced = announceText;
                pulsoAnnounce(announceText);
            }

            let html = '<div class="pulso-create-status">'
                + '<div class="pulso-create-status-head">'
                + '<span class="pulso-status-pill ' + pillClass + '" id="pulso-create-pill">' + escapeHtmlText(pillLabel) + '</span>'
                + '</div>';

            if (progressLine) {
                html += '<p class="pulso-create-hint" id="pulso-create-progress">' + escapeHtmlText(progressLine) + '</p>';
            }

            // El servidor marca `delayed` si el encargo lleva más de 10 minutos en
            // «pendiente»: el cron de Moodle no lo está recogiendo (parado o atrasado).
            if (encargo.status === 'pendiente' && encargo.delayed) {
                html += '<div class="pulso-create-notice-inline warn">La cola de trabajos de este sitio va con retraso. '
                    + 'Te avisaremos cuando esté ' + (esjuego ? 'listo' : 'lista') + '.</div>';
            }

            // Pasados 2 minutos sin terminar, deja claro que no hace falta
            // seguir mirando: el aviso de mensajería ya lo manda
            // notify_completion() en servidor cuando el encargo termine.
            if (!encargo.terminal && (Date.now() - pulsoCreatePollStart) >= 120000) {
                html += '<p class="pulso-create-hint">Te avisaremos cuando esté ' + (esjuego ? 'listo' : 'lista') + '; puedes cerrar el chat.</p>';
            }

            // "motivo" en un estado NO terminal es el último error de red del
            // reintento (el servidor ya lo filtra a solo viewanalytics — ver
            // api_create_status.php). Es un aviso técnico de que se está
            // reintentando, no un fallo: el encargo sigue vivo.
            if (!encargo.terminal && encargo.motivo) {
                html += '<div class="pulso-create-notice-inline warn">Reintentando tras un error: '
                    + escapeHtmlText(encargo.motivo) + '</div>';
            }

            if (encargo.status === 'listo' && (encargo.imageurl || encargo.playurl)) {
                if (encargo.mock) {
                    html += '<div class="pulso-create-notice-inline warn">'
                        + (esjuego ? 'Este juego es de prueba' : 'Esta lámina es de prueba') + ', no una generación real.</div>';
                }
                if (encargo.verificado === false) {
                    html += '<div class="pulso-create-notice-inline warn">No hemos podido confirmar que '
                        + (esjuego ? 'este juego' : 'esta infografía') + ' trate de '
                        + (encargo.tema ? escapeHtmlText(encargo.tema) : 'el tema pedido') + '.</div>';
                }
                const avisosTxt = Array.isArray(encargo.avisos)
                    ? encargo.avisos.filter(function(a) { return typeof a === 'string' && a.trim() !== ''; })
                    : [];
                if (avisosTxt.length) {
                    html += '<div class="pulso-create-notice-inline warn">'
                        + avisosTxt.map(function(a) { return escapeHtmlText(a); }).join('; ') + '</div>';
                }

                if (esjuego) {
                    // El juego se juega FUERA del widget (juego.php), nunca
                    // en un iframe dentro del chat: es estrecho, y el juego
                    // corre en un marco aislado con su propia CSP/sandbox.
                    if (encargo.titulo) html += '<div class="pulso-create-image-title">' + escapeHtmlText(encargo.titulo) + '</div>';
                    if (encargo.tema) html += '<div class="pulso-create-image-tema">' + escapeHtmlText(encargo.tema) + '</div>';
                    html += '<div class="pulso-create-image-actions">'
                        + '<a href="' + pulsoEscapeAttr(encargo.playurl) + '" target="_blank" rel="noopener">Jugar' + PULSO_NEWTAB_SR + '</a>'
                        + '</div>';
                } else {
                    html += '<img class="pulso-create-image" src="' + pulsoEscapeAttr(encargo.imageurl) + '" alt="'
                        + pulsoEscapeAttr(encargo.titulo || 'Infografía generada') + '">';
                    if (encargo.titulo) html += '<div class="pulso-create-image-title">' + escapeHtmlText(encargo.titulo) + '</div>';
                    if (encargo.tema) html += '<div class="pulso-create-image-tema">' + escapeHtmlText(encargo.tema) + '</div>';
                    html += '<div class="pulso-create-image-actions">'
                        + '<a href="' + pulsoEscapeAttr(encargo.imageurl) + '" target="_blank" rel="noopener">Abrir a tamaño completo' + PULSO_NEWTAB_SR + '</a>'
                        + '<a href="' + pulsoEscapeAttr(encargo.downloadurl) + '">Descargar</a>'
                        + '</div>';
                }
            } else if (encargo.status === 'fallado' || encargo.status === 'desconocido') {
                html += '<div class="pulso-create-notice-inline danger">' + escapeHtmlText(pulsoCreateFailureMessage(encargo)) + '</div>'
                    + '<button type="button" class="pulso-create-submit" data-pulso-action="openCreatePanel" data-pulso-arg="' + pulsoCreateTool + '">Crear un encargo nuevo</button>';
            } else if (encargo.status === 'ensayo') {
                html += '<div class="pulso-create-notice-inline warn">Modo de ensayo activo: el sobre se construyó pero no se envió a Épica.</div>';
                if (encargo.sobre) {
                    html += '<details class="pulso-create-sobre"><summary>Ver sobre construido</summary>'
                        + '<pre>' + escapeHtmlText(JSON.stringify(encargo.sobre, null, 2)) + '</pre></details>';
                }
            }

            html += '<button type="button" class="pulso-create-back-link" data-pulso-action="openCreatePanel" data-pulso-arg="' + pulsoCreateTool + '">← Volver al formulario</button>'
                + '</div><div id="pulso-create-gallery"></div>';

            body.innerHTML = html;
            // La galeria NO se pide en cada sondeo (2 peticiones extra cada 7 s):
            // se repinta desde la ultima carga y solo se pide si nunca se cargo.
            // En estado terminal la pide pollCreateStatusOnce justo despues.
            if (pulsoGalleryCache) {
                renderCreateGallery(pulsoGalleryCache.encargos, pulsoGalleryCache.retos);
            } else if (!encargo.terminal && !pulsoGalleryLoading) {
                loadCreateGallery();
            }
        }


        let pulsoGalleryCache = null;
   // {encargos, retos} de la ultima carga correcta
        let pulsoGalleryLoading = false;

        let pulsoGallerySeq = 0;
        // solo vale la ultima peticion lanzada

        // La galería es un extra: si falla, no debe romper el resto del panel.
        function loadCreateGallery() {
            const seq = ++pulsoGallerySeq;
            pulsoGalleryLoading = true;
            const params = new URLSearchParams();
            params.set('courseid', cfg.courseid);
            params.set('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');

            // Infografías/juegos (api_create_status.php) y retos (api_retos.php
            // mis_retos, que no llama a Épica) se piden a la vez y se mezclan.
            // Cada fuente falla por separado: si una cae, se pinta la otra.
            const encargosP = fetch(cfg.apiCreateStatusUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) { return data.success ? (data.encargos || []) : null; })
                .catch(function() { return null; });
            // Sin Épica, api_retos.php contesta «no disponible» a todo: ni se pide.
            const retosP = (cfg.epicaAvailable === false)
                ? Promise.resolve([])
                : C.fn.pulsoRetosCall('mis_retos')
                    .then(function(d) { return d.ok && Array.isArray(d.retos) ? d.retos : []; });

            Promise.all([encargosP, retosP]).then(function(res) {
                if (seq !== pulsoGallerySeq) return;
                pulsoGalleryLoading = false;
                if (res[0] === null && !res[1].length) return;
                pulsoGalleryCache = { encargos: res[0] || [], retos: res[1] };
                renderCreateGallery(pulsoGalleryCache.encargos, pulsoGalleryCache.retos);
            }).catch(function() {
                if (seq === pulsoGallerySeq) pulsoGalleryLoading = false;
                /* la galería es un extra, no bloquea el panel */
            });
        }


        // Galería conjunta (paso 3): infografía y juego mezclados, más
        // recientes primero. Una infografía se ENSEÑA (miniatura real); un
        // juego se JUEGA, así que su tarjeta no tiene imagen (pluginfile.php
        // sigue bloqueando su filearea a propósito) — icono + título de
        // respaldo + etiqueta. Ninguna de las dos usa el cian como texto.
        // Retos (v1.27): tarjetas-enlace a Épica (pestaña nueva, sin iframe),
        // mezcladas por fecha con las demás; etiqueta «Reto» en slate.
        function renderCreateGallery(encargos, retos) {
            const container = document.getElementById('pulso-create-gallery');
            if (!container) return;
            retos = (retos || []).filter(function(r) { return pulsoAmpIsHttps(r.enlace); }).slice(0, 8);
            if (!encargos.length && !retos.length) {
                container.innerHTML = '<p class="pulso-create-hint">Aún no has creado nada en este curso.</p>' + pulsoHistorialFooter();
                return;
            }

            const items = encargos.map(function(e) { return { t: e.timecreated, e: e }; })
                .concat(retos.map(function(r) { return { t: r.creado, r: r }; }))
                .sort(function(a, b) { return b.t - a.t; });

            // El servidor ya filtra a status='listo' con fichero (ver
            // api_create_status.php), así que aquí no hay placeholder de
            // estado: todas las tarjetas son creaciones terminadas.
            let html = '<div class="pulso-create-gallery-title">Tus últimas creaciones</div><div class="pulso-create-gallery-grid">';
            items.forEach(function(it) {
                if (it.r) {
                    html += '<a class="pulso-create-gallery-item" href="' + pulsoEscapeAttr(it.r.enlace) + '" target="_blank" rel="noopener noreferrer">'
                        + '<span class="pulso-create-gallery-icon" aria-hidden="true">' + C.fn.pulsoRetosIconSvg('target') + '</span>'
                        + '<span class="pulso-create-gallery-item-title">' + escapeHtmlText(it.r.titulo || 'Reto') + '</span>'
                        + '<span class="pulso-create-gallery-tag">Reto</span>'
                        + '<span class="pulso-create-gallery-date">' + escapeHtmlText(new Date(it.r.creado * 1000).toLocaleDateString()) + '</span>'
                        + PULSO_NEWTAB_SR + '</a>';
                    return;
                }
                const e = it.e;
                const dateLabel = new Date(e.timecreated * 1000).toLocaleDateString();
                const esjuego = e.tool === 'gamificacion';
                const tag = esjuego ? 'Juego' : 'Infografía';
                // "tool" entra en un onclick: solo uno de los dos valores conocidos, y el id como entero.
                const toolSeguro = esjuego ? 'gamificacion' : 'infografia';
                html += '<button type="button" class="pulso-create-gallery-item" data-pulso-action="openCreateGalleryItem" data-pulso-arg="' + (parseInt(e.id, 10) || 0) + '" data-pulso-arg2="' + toolSeguro + '">';
                if (esjuego) {
                    html += '<span class="pulso-create-gallery-icon" aria-hidden="true">'
                        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                        + '<rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="12" x2="10" y2="12"/><line x1="8" y1="10" x2="8" y2="14"/>'
                        + '<line x1="15" y1="13" x2="15.01" y2="13"/><line x1="18" y1="11" x2="18.01" y2="11"/></svg>'
                        + '</span>'
                        + '<span class="pulso-create-gallery-item-title">' + escapeHtmlText(e.titulo || 'Juego') + '</span>';
                } else {
                    html += '<img src="' + pulsoEscapeAttr(e.imageurl) + '" alt="">'
                        + '<span class="pulso-create-gallery-item-title">' + escapeHtmlText(e.titulo || 'Infografía') + '</span>';
                }
                html += '<span class="pulso-create-gallery-tag">' + tag + '</span>'
                    + '<span class="pulso-create-gallery-date">' + escapeHtmlText(dateLabel) + '</span>'
                    + '</button>';
            });
            html += '</div>' + pulsoHistorialFooter();
            container.innerHTML = html;
        }


        // «Mi historial» (carta 10): solo alumnado (la UI no es control de acceso;
        // epica_historial.php ya lo exige). El token nunca pasa por este JS: se
        // crea un <form> POST con courseid + sesskey hacia nuestro endpoint, en
        // pestaña nueva y en el propio clic (sin bloqueo de ventanas emergentes).
        function pulsoHistorialFooter() {
            if (cfg.isTeacher !== false || cfg.epicaAvailable === false) return '';
            return '<p class="pulso-create-hint"><button type="button" class="pulso-historial-link" data-pulso-action="pulsoAbrirHistorial">Ver todo mi historial en Épica<span aria-hidden="true"> ↗</span>' + PULSO_NEWTAB_SR + '</button><br>'
                + 'Se abre en una pestaña nueva. Solo aparece lo creado desde el 5 de octubre de 2026.</p>';
        }


        function pulsoAbrirHistorial() {
            if (cfg.isTeacher !== false || !cfg.apiHistorialUrl) return;
            const form = document.createElement('form');
            form.method = 'post';
            form.action = cfg.apiHistorialUrl;
            form.target = '_blank';
            form.style.display = 'none';
            [['courseid', cfg.courseid], ['sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '']].forEach(function(p) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = p[0];
                input.value = String(p[1]);
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }


        function openCreateGalleryItem(encargoid, tool) {
            encargoid = parseInt(encargoid, 10) || 0;
            const messagesDiv = document.getElementById('pulso-scroll');
            const body = document.getElementById('pulso-create-body');
            if (!messagesDiv || !body) return;
            if (!messagesDiv.classList.contains('pulso-showing-create')) {
                const active = document.activeElement;
                pulsoCreateOpener = (active && active !== document.body) ? active : null;
            }
            pulsoCreateSetTool(tool);
            messagesDiv.classList.add('pulso-showing-create');
            body.innerHTML = '<p class="pulso-create-hint">Cargando…</p>';
            pulsoCreateScreen(true);
            // Si sigue en curso, se sondea igual que un encargo recién creado.
            startCreatePolling(encargoid);
        }
    C.fn.pulsoCreateIsOpen = pulsoCreateIsOpen;
    C.fn.pulsoCreateScreen = pulsoCreateScreen;
    C.fn.openCreatePanel = openCreatePanel;
    C.fn.closeCreatePanel = closeCreatePanel;
    C.fn.pulsoOnVisibilityChange = pulsoOnVisibilityChange;
    C.fn.loadCreateGallery = loadCreateGallery;
    C.fn.pulsoAbrirHistorial = pulsoAbrirHistorial;
    C.fn.submitCreate = submitCreate;
    C.fn.openCreateGalleryItem = openCreateGalleryItem;

    return {};
});
