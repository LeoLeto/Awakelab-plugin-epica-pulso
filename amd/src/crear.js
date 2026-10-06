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
 * Pestana Crear de Pulse AI: formulario, estado de una creacion, «Mis creaciones» e historial.
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



        // ========== PESTAÑA CREAR (infografía, juego, retos, ampliación) ==========
        // Es una PESTAÑA propia (#pulso-panel-crear), no un mensaje del chat: si se colara como
        // mensaje acabaría en el historial que viaja a Anthropic en cada petición. Dentro tiene
        // dos pantallas hermanas que se alternan con `hidden`: #pulso-create-root (la lista de
        // herramientas) y #pulso-create-panel (la herramienta abierta: formulario, estado…).
        // Lo que se ve dentro del panel lo manda una PILA de pantallas (pulsoCreateStack):
        //   lista (pila vacía) → formulario → estado / retos / resultado de la ampliación
        //   lista (pila vacía) → «Mis creaciones» → estado de una creación / propuesta de retos
        // «Volver» desapila UNA pantalla; no vuelve siempre a la lista.
        // De momento no manda nada a Epica (eso es el paso 4): solo valida,
        // comprueba cupo y guarda el encargo como "pendiente".



        // Un único formulario parametrizado por herramienta, no una copia por
        // herramienta (CLAUDE.md, paso 1: "añadirlos es repetir este mismo
        // patrón"). pulsoCreateTool decide textos, si se pide formato y qué
        // envía submitCreate() — el desplegable de recursos y los cupos son
        // iguales para las dos (contador conjunto, CLAUDE.md paso 3).
        let pulsoCreateTool = 'infografia';

        // ---- Pila de pantallas de Crear (fase 4, paso 6) ----
        // Vacía = la lista de herramientas (#pulso-create-root). Con elementos, el último es la
        // pantalla visible en #pulso-create-panel. Solo hay dos niveles:
        //   [formulario(tool)]                 profundidad 1   (o [mine]: «Mis creaciones»)
        //   [formulario(tool), detalle]        profundidad 2; detalle = status | retos | amp
        //   [mine, detalle]                    detalle = status | retos (una creación o propuesta abierta)
        // Los tres «detalle» son del mismo nivel: pasar de uno a otro (o entre dos estados de
        // la misma herramienta, p. ej. Retos: espera → seis retos → reto elegido) REEMPLAZA, no
        // apila, así que «Volver» desde cualquiera de ellos va al formulario.
        // Cada elemento es una descripción ({kind, tool}), no un DOM: al desapilar la pantalla se
        // vuelve a pintar (el formulario vuelve a pedir el cupo, que habrá cambiado).
        let pulsoCreateStack = [];

        const PULSO_CREATE_TOOLS = ['infografia', 'gamificacion', 'ampliacion', 'retos'];

        function pulsoCreateNormTool(tool) {
            return PULSO_CREATE_TOOLS.indexOf(tool) >= 0 ? tool : 'infografia';
        }

        function pulsoCreateTop() {
            return pulsoCreateStack.length ? pulsoCreateStack[pulsoCreateStack.length - 1] : null;
        }

        // «Volver» dice a dónde va: al formulario (o a «Mis creaciones») desde un detalle, a la lista desde
        // el nivel base.
        function pulsoCreateSyncBack() {
            const btn = document.getElementById('pulso-create-back');
            if (!btn) return;
            let label = 'Volver a la lista de herramientas de creación';
            if (pulsoCreateStack.length > 1) {
                label = pulsoCreateStack[0].kind === 'mine' ? 'Volver a Mis creaciones' : 'Volver al formulario';
            }
            btn.setAttribute('aria-label', label);
        }

        // Se entra en una pantalla de detalle (estado, Retos, resultado de Ampliar) desde el formulario
        // o desde otro detalle. La misma pantalla de nuevo = nada (los repintados de un mismo estado no
        // son un cambio de pantalla y no deben invalidar el token de los sondeos en marcha).
        function pulsoCreateEnter(kind, tool) {
            const top = pulsoCreateTop();
            if (top && top.kind === kind) {
                top.tool = tool;
                return;
            }
            stopCreatePolling();
            S.pulsoAmpToken++;
            if (top && top.kind !== 'form' && top.kind !== 'mine') {
                pulsoCreateStack[pulsoCreateStack.length - 1] = { kind: kind, tool: tool };
            } else if (top) {
                pulsoCreateStack.push({ kind: kind, tool: tool });
            } else {
                pulsoCreateStack = [{ kind: 'form', tool: tool }, { kind: kind, tool: tool }];
            }
            pulsoCreateSyncBack();
        }


        // ¿Hay una herramienta abierta (pantalla de la herramienta, no la lista)?
        function pulsoCreateIsOpen() {
            const panel = document.getElementById('pulso-create-panel');
            return !!(panel && !panel.hidden);
        }


        // Foco al título (h3 con tabindex="-1") de la pantalla nueva. Con `force` siempre (al abrir);
        // si no, solo cuando el foco se ha perdido (el elemento enfocado se repintó) o seguía dentro
        // del cuerpo del panel: nunca le quita el foco a quien está escribiendo en el chat.
        // Solo con la pestaña Crear activa: un repintado por un sondeo con otra pestaña delante no
        // mueve el foco (y el panel oculto no podría recibirlo).
        function pulsoCreateScreen(force) {
            const title = document.getElementById('pulso-create-title');
            if (!title || !pulsoCreateIsOpen() || S.pulsoTab !== 'crear') return;
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


        // Muestra la pantalla de una herramienta (y oculta la lista) y cambia a la pestaña Crear.
        // Cambiar de pantalla DENTRO de Crear invalida los sondeos (token); cambiar de pestaña no.
        function pulsoCreateShowTool() {
            const root = document.getElementById('pulso-create-root');
            const panel = document.getElementById('pulso-create-panel');
            if (root) root.hidden = true;
            if (panel) panel.hidden = false;
            C.fn.pulsoSelectTab('crear', { focus: false });
        }


        // Entra en el formulario de una herramienta con la pila reiniciada (desde la lista, o «empezar
        // una creación nueva» desde un estado: ya no hay nada que desapilar).
        function openCreatePanel(tool) {
            pulsoCreateStack = [{ kind: 'form', tool: pulsoCreateNormTool(tool || pulsoCreateTool) }];
            pulsoCreateRenderForm();
        }


        // Repinta el nivel base de la pila: «Mis creaciones» o el formulario de una herramienta.
        function pulsoCreateRenderTop() {
            const top = pulsoCreateTop();
            if (top && top.kind === 'mine') {
                pulsoCreateRenderMine();
            } else {
                pulsoCreateRenderForm();
            }
        }


        // Pinta el formulario que está en la cima de la pila: pide el cupo/recursos al servidor.
        function pulsoCreateRenderForm() {
            const body = document.getElementById('pulso-create-body');
            const top = pulsoCreateTop();
            if (!body || !top) return;

            pulsoCreateSetTool(top.tool);
            stopCreatePolling();
            S.pulsoAmpToken++;
            pulsoCreateSyncBack();
            pulsoCreateShowTool();
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
                        renderCreateNotice(data.quota_message || 'Has alcanzado el límite de creaciones.');
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


        // «Volver»: desapila UNA pantalla (no sale de la pestaña Crear). Es un cambio de pantalla
        // dentro de Crear: para el sondeo de la pantalla que se deja y descarta cualquier respuesta
        // en vuelo (token). NO cancela ni borra nada en el servidor: una creación en curso sigue y
        // se recupera después desde «Mis creaciones» (una propuesta de Retos sin elegir también);
        // basta con dejar de mirarla. Los detalles vuelven al formulario o a «Mis creaciones» (que se
        // repintan); el nivel base, a la lista.
        function pulsoCreateBack() {
            if (!pulsoCreateStack.length) return;
            const leaving = pulsoCreateStack.pop();
            stopCreatePolling();
            S.pulsoAmpToken++;
            if (pulsoCreateStack.length) {
                pulsoCreateRenderTop();
                return;
            }
            const root = document.getElementById('pulso-create-root');
            const panel = document.getElementById('pulso-create-panel');
            if (panel) panel.hidden = true;
            if (root) root.hidden = false;
            pulsoCreateSyncBack();
            if (S.pulsoTab !== 'crear') return;
            // El foco vuelve a la fila de la que se venía (herramienta o «Mis creaciones»); si ya no está
            // en la lista, al título.
            const row = root ? root.querySelector('[data-pulso-tool="' + leaving.tool + '"]') : null;
            const target = row || document.getElementById('pulso-create-root-title');
            if (target) target.focus();
        }


        // Ya no hay galería bajo el aviso: lo creado antes se ve en «Mis creaciones».
        function renderCreateNotice(text) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-create-notice"></div>';
            body.querySelector('.pulso-create-notice').textContent = text;
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
            // entrada en «Mis creaciones» (una ampliación es compartida por recurso, no del usuario).
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
                + '<button type="button" class="pulso-create-submit" id="pulso-create-submit-btn" data-pulso-action="submitCreate">' + escapeHtmlText(labels.submitLabel) + '</button>';

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
                hint.textContent = 'Ya has llegado al límite de creaciones de hoy para la sección de este recurso ('
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
                        pulsoCreateEnter('status', pulsoCreateTool);
                        const body = document.getElementById('pulso-create-body');
                        if (body) body.innerHTML = '<p class="pulso-create-hint">Creación guardada. Comprobando estado…</p>';
                        startCreatePolling(data.encargoid, true);
                    } else {
                        renderCreateNotice(data.message || 'No se ha podido guardar la creación. Inténtalo de nuevo.');
                        if (btn) {
                            pulsoSetDisabled(btn, false);
                            btn.textContent = labels.submitLabel;
                        }
                    }
                })
                .catch(function() {
                    renderCreateNotice('No se ha podido conectar para guardar la creación. Inténtalo de nuevo.');
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
        let pulsoCreateSawPending = false;
        // el encargo visto estuvo en curso: al terminar, «Mis creaciones» se refresca
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


        // nuevo = true cuando acaba de crearse (puede llegar a estado terminal en el primer sondeo y entonces
        // «Mis creaciones» tiene una creación más); false al reabrir una existente (no hay nada que refrescar
        // si ya estaba terminada).
        function startCreatePolling(encargoid, nuevo) {
            stopCreatePolling();
            pulsoCreateSawPending = !!nuevo;
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
                        renderCreateNotice(data.message || 'No se ha podido comprobar el estado de la creación.');
                        return;
                    }
                    renderCreateStatus(data.encargo);
                    if (!data.encargo.terminal) {
                        pulsoCreateSawPending = true;
                        scheduleNextCreatePoll(encargoid);
                    } else if (pulsoCreateSawPending) {
                        // Estado terminal tras haber estado en curso: la caché de «Mis creaciones» se
                        // refresca UNA vez (hay una creación nueva), no en cada sondeo.
                        pulsoCreateSawPending = false;
                        loadMisCreaciones();
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
            // Pestaña del navegador o de Pulse en segundo plano: no se programa nada; se reanuda
            // en pulsoSyncPolling() al volver (visibilitychange o cambio de pestaña).
            pulsoCreatePollPending = encargoid;
            if (C.pollsPaused()) return;
            pulsoCreatePollTimer = setTimeout(pulsoCreatePollTick, PULSO_CREATE_POLL_MS);
        }


        function pulsoCreatePollTick() {
            pulsoCreatePollTimer = null;
            if (C.pollsPaused() || pulsoCreatePollPending === null) return;
            const id = pulsoCreatePollPending;
            pulsoCreatePollPending = null;
            pollCreateStatusOnce(id);
        }


        // Una sola función para los sondeos (Crear cada 7 s, Retos cada 4 s y el refresco del título
        // del reto): con la pestaña del navegador oculta O con la pestaña de Pulse distinta de Crear se
        // paran los temporizadores (el pendiente queda guardado) y al volver se retoma enseguida lo
        // que estaba pendiente. La llaman visibilitychange y pulsoSelectTab().
        function pulsoSyncPolling() {
            if (C.pollsPaused()) {
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
                return 'No se ha podido generar. Prueba a crear una nueva.';
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
                        : 'Esperando turno en la cola de generación.';
                }
            } else if (encargo.status === 'trabajando') {
                progressLine = esjuego
                    ? 'Casi listo, suele tardar alrededor de un minuto.'
                    : 'Generando la infografía… puede tardar un par de minutos.';
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
                        + (esjuego ? 'Este juego es de prueba' : 'Esta infografía es de prueba') + ', no una generación real.</div>';
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
                    + '<button type="button" class="pulso-create-submit" data-pulso-action="openCreatePanel" data-pulso-arg="' + pulsoCreateTool + '">Empezar una creación nueva</button>';
            } else if (encargo.status === 'ensayo') {
                html += '<div class="pulso-create-notice-inline warn">Modo de ensayo activo: el sobre se construyó pero no se envió al servicio de generación.</div>';
                if (encargo.sobre) {
                    html += '<details class="pulso-create-sobre"><summary>Ver sobre construido</summary>'
                        + '<pre>' + escapeHtmlText(JSON.stringify(encargo.sobre, null, 2)) + '</pre></details>';
                }
            }

            html += '</div>';

            body.innerHTML = html;
        }


        // ---- «Mis creaciones» (fase 4, paso 7) ----
        // ÚNICA galería: infografías, juegos y retos del usuario en el curso (más las propuestas de retos
        // aún sin elegir), más recientes primero. Es el nivel base de la pila (`{kind:'mine'}`) y cada
        // creación que se abre desde ella es un detalle encima: «Volver» regresa aquí. Ampliar recurso NO
        // entra (es una búsqueda cacheada por recurso, no una creación del usuario).
        // La caché del cliente se pinta al entrar y se refresca en segundo plano; se pide al entrar en la
        // pantalla y al llegar un estado terminal, NUNCA en cada sondeo (2 peticiones cada vez).
        const PULSO_MINE_FILTERS = [['all', 'Todo'], ['infografia', 'Infografías'], ['juego', 'Juegos'], ['reto', 'Retos']];

        let pulsoMineFilter = 'all';

        let pulsoGalleryCache = null;
   // {encargos, retos, propuestas, partial} de la ultima carga correcta
        let pulsoGallerySeq = 0;
        // solo vale la ultima peticion lanzada

        // Plantilla inerte (<template>) del pie de historial: solo existe en el HTML del alumnado con Épica
        // ({{^isteacher}}{{#epica}}), así que quien no debe verlo no la recibe. La UI no es control de
        // acceso (lo es epica_historial.php); el chequeo de cfg es una segunda red.
        function pulsoMineHistorialHtml() {
            if (cfg.isTeacher !== false || cfg.epicaAvailable === false) return '';
            const tpl = document.getElementById('pulso-mine-historial-tpl');
            return tpl ? tpl.innerHTML : '';
        }


        function openMisCreaciones() {
            if (cfg.epicaAvailable === false) return;
            pulsoMineFilter = 'all';
            pulsoCreateStack = [{ kind: 'mine', tool: 'mine' }];
            pulsoCreateRenderMine();
        }


        // Pinta la pantalla (esqueleto + caché si la hay) y lanza la carga. Cambio de pantalla dentro de
        // Crear: invalida los sondeos (token) igual que el formulario.
        function pulsoCreateRenderMine() {
            const body = document.getElementById('pulso-create-body');
            if (!body || !pulsoCreateTop()) return;

            const titleEl = document.getElementById('pulso-create-title');
            if (titleEl) titleEl.textContent = 'Mis creaciones';
            stopCreatePolling();
            S.pulsoAmpToken++;
            pulsoCreateSyncBack();
            pulsoCreateShowTool();

            body.innerHTML = '<div class="pulso-mine-filters" role="group" aria-label="Filtrar por tipo">'
                + PULSO_MINE_FILTERS.map(function(f) {
                    return '<button type="button" class="pulso-mine-filter" aria-pressed="' + (f[0] === pulsoMineFilter ? 'true' : 'false')
                        + '" data-pulso-action="pulsoMineFilter" data-pulso-arg="' + f[0] + '">' + f[1] + '</button>';
                }).join('')
                + '</div>'
                + '<p class="pulso-mine-count" id="pulso-mine-count" role="status" aria-live="polite"></p>'
                + '<div id="pulso-mine-msg"></div>'
                + '<ul class="pulso-mine-list" id="pulso-mine-list"></ul>'
                + '<p class="pulso-create-hint">Aquí salen las creaciones que has hecho en este curso. Ampliar un recurso no se guarda: es una búsqueda.</p>'
                + pulsoMineHistorialHtml();
            pulsoCreateScreen(true);

            if (pulsoGalleryCache) {
                renderMineList();
            } else {
                pulsoMineMessage('<p class="pulso-create-hint">Cargando…</p>');
            }
            loadMisCreaciones();
        }


        // Solo con «Mis creaciones» en la cima de la pila: el cuerpo de una pantalla que se dejó (p. ej. al
        // volver a la lista de herramientas) sigue en el DOM, oculto, y una respuesta tardía no debe tocarlo.
        function pulsoMineActive() {
            const top = pulsoCreateTop();
            return !!(top && top.kind === 'mine');
        }


        function pulsoMineMessage(html) {
            const el = document.getElementById('pulso-mine-msg');
            if (el && pulsoMineActive()) el.innerHTML = html;
        }


        // Pide infografías/juegos (api_create_status.php) y retos + propuestas (api_retos.php mis_retos,
        // que no llama a Épica) a la vez. Cada fuente falla por separado: si una cae se conserva lo último
        // que se supo de ella y se avisa. Sin Épica, api_retos.php contesta «no disponible»: ni se pide.
        function loadMisCreaciones() {
            const seq = ++pulsoGallerySeq;
            const params = new URLSearchParams();
            params.set('courseid', cfg.courseid);
            params.set('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');

            const encargosP = fetch(cfg.apiCreateStatusUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) { return data.success ? (data.encargos || []) : null; })
                .catch(function() { return null; });
            const retosP = (cfg.epicaAvailable === false)
                ? Promise.resolve({ retos: [], propuestas: [] })
                : C.fn.pulsoRetosCall('mis_retos').then(function(d) {
                    return d.ok ? {
                        retos: Array.isArray(d.retos) ? d.retos : [],
                        propuestas: Array.isArray(d.propuestas) ? d.propuestas : []
                    } : null;
                });

            Promise.all([encargosP, retosP]).then(function(res) {
                if (seq !== pulsoGallerySeq) return;
                const prev = pulsoGalleryCache;
                if (res[0] === null && res[1] === null) {
                    if (!prev) {
                        pulsoMineMessage('<div class="pulso-create-notice-inline warn">No hemos podido cargar tus creaciones.</div>'
                            + '<button type="button" class="pulso-create-secondary" data-pulso-action="loadMisCreaciones">Reintentar</button>');
                    }
                    return;
                }
                pulsoGalleryCache = {
                    encargos: res[0] !== null ? res[0] : (prev ? prev.encargos : []),
                    retos: res[1] !== null ? res[1].retos : (prev ? prev.retos : []),
                    propuestas: res[1] !== null ? res[1].propuestas : (prev ? prev.propuestas : []),
                    partial: res[0] === null || res[1] === null
                };
                renderMineList(); // no hace nada si la pantalla ya no está
            }).catch(function() {
                /* la carga es un extra: sin respuesta se queda lo que ya hubiera pintado */
            });
        }


        // Todas las creaciones de la caché en una lista, con su tipo para el filtro. Un reto es un enlace
        // a Épica (solo https); una propuesta sin elegir se reabre en Pulse con la MISMA propuesta.
        function pulsoMineItems() {
            const c = pulsoGalleryCache;
            const items = [];
            c.encargos.forEach(function(e) {
                items.push({ t: Number(e.timecreated) || 0, kind: e.tool === 'gamificacion' ? 'juego' : 'infografia', e: e });
            });
            c.retos.filter(function(r) { return pulsoAmpIsHttps(r.enlace); }).forEach(function(r) {
                items.push({ t: Number(r.creado) || 0, kind: 'reto', r: r });
            });
            c.propuestas.forEach(function(p) {
                items.push({ t: Number(p.creado) || 0, kind: 'reto', p: p });
            });
            return items.sort(function(x, y) { return y.t - x.t; });
        }


        const PULSO_MINE_ICONS = {
            juego: '<rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="12" x2="10" y2="12"/><line x1="8" y1="10" x2="8" y2="14"/>'
                + '<line x1="15" y1="13" x2="15.01" y2="13"/><line x1="18" y1="11" x2="18.01" y2="11"/>',
            infografia: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>'
        };


        function pulsoMineInfo(title, kindLabel, t) {
            return '<span class="pulso-mine-info"><span class="pulso-mine-title">' + escapeHtmlText(title) + '</span>'
                + '<span class="pulso-mine-meta">' + escapeHtmlText(kindLabel) + ' · '
                + escapeHtmlText(new Date(t * 1000).toLocaleDateString()) + '</span></span>';
        }


        function pulsoMineItemHtml(it) {
            if (it.r) {
                return '<a class="pulso-mine-item" href="' + pulsoEscapeAttr(it.r.enlace) + '" target="_blank" rel="noopener noreferrer">'
                    + '<span class="pulso-mine-thumb" aria-hidden="true">' + C.fn.pulsoRetosIconSvg('target') + '</span>'
                    + pulsoMineInfo(it.r.titulo || 'Reto', 'Reto', it.t) + PULSO_NEWTAB_SR + '</a>';
            }
            if (it.p) {
                const lista = it.p.estado === 'listo';
                return '<button type="button" class="pulso-mine-item" data-pulso-action="pulsoRetosAbrirPropuesta" data-pulso-arg="' + (parseInt(it.p.id, 10) || 0) + '">'
                    + '<span class="pulso-mine-thumb" aria-hidden="true">' + C.fn.pulsoRetosIconSvg('target') + '</span>'
                    + pulsoMineInfo('Propuesta de retos · ' + (lista ? 'Lista para elegir' : 'En preparación'), 'Reto', it.t) + '</button>';
            }
            const e = it.e;
            const esjuego = it.kind === 'juego';
            // Solo dos herramientas conocidas: el id como entero y la herramienta de una lista cerrada.
            const thumb = esjuego
                ? '<span class="pulso-mine-thumb" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + PULSO_MINE_ICONS.juego + '</svg></span>'
                : '<span class="pulso-mine-thumb" aria-hidden="true"><img src="' + pulsoEscapeAttr(e.imageurl) + '" alt=""></span>';
            return '<button type="button" class="pulso-mine-item" data-pulso-action="openCreateGalleryItem" data-pulso-arg="' + (parseInt(e.id, 10) || 0)
                + '" data-pulso-arg2="' + (esjuego ? 'gamificacion' : 'infografia') + '">'
                + thumb + pulsoMineInfo(e.titulo || (esjuego ? 'Juego' : 'Infografía'), esjuego ? 'Juego' : 'Infografía', it.t) + '</button>';
        }


        // Repinta SOLO la lista, el recuento y el aviso (no el cuerpo entero): así el foco del usuario sobre
        // un filtro no se pierde y el observador de foco de Crear (hijos directos del cuerpo) no salta.
        function renderMineList() {
            const list = document.getElementById('pulso-mine-list');
            const count = document.getElementById('pulso-mine-count');
            if (!list || !count || !pulsoGalleryCache || !pulsoMineActive()) return;

            const all = pulsoMineItems();
            const shown = all.filter(function(it) { return pulsoMineFilter === 'all' || it.kind === pulsoMineFilter; });
            list.innerHTML = shown.map(function(it) {
                return '<li data-pulso-type="' + it.kind + '">' + pulsoMineItemHtml(it) + '</li>';
            }).join('');
            count.textContent = shown.length + (shown.length === 1 ? ' creación' : ' creaciones');

            let msg = '';
            if (pulsoGalleryCache.partial) {
                msg += '<div class="pulso-create-notice-inline warn">No hemos podido cargar todas tus creaciones. Vuelve a entrar en un momento.</div>';
            }
            if (!shown.length) {
                msg += '<p class="pulso-create-hint">' + (all.length ? 'Todavía no tienes nada de este tipo.' : 'Aún no has creado nada en este curso.') + '</p>';
            }
            pulsoMineMessage(msg);
        }


        // Filtro por tipo: botones con aria-pressed; el recuento (role="status") anuncia «N creaciones».
        function pulsoMineFilterSet(filter) {
            pulsoMineFilter = PULSO_MINE_FILTERS.some(function(f) { return f[0] === filter; }) ? filter : 'all';
            document.querySelectorAll('#pulso-create-body .pulso-mine-filter').forEach(function(btn) {
                btn.setAttribute('aria-pressed', btn.getAttribute('data-pulso-arg') === pulsoMineFilter ? 'true' : 'false');
            });
            renderMineList();
        }


        // «Mi historial» (carta 10): solo alumnado (la UI no es control de acceso;
        // epica_historial.php ya lo exige). El token nunca pasa por este JS: se
        // crea un <form> POST con courseid + sesskey hacia nuestro endpoint, en
        // pestaña nueva y en el propio clic (sin bloqueo de ventanas emergentes).
        // El botón vive al pie de «Mis creaciones» (plantilla pulso-mine-historial-tpl).
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
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            pulsoCreateSetTool(tool);
            pulsoCreateEnter('status', pulsoCreateTool);
            pulsoCreateShowTool();
            body.innerHTML = '<p class="pulso-create-hint">Cargando…</p>';
            pulsoCreateScreen(true);
            // Si sigue en curso, se sondea igual que un encargo recién creado.
            startCreatePolling(encargoid, false);
        }
    C.fn.pulsoCreateIsOpen = pulsoCreateIsOpen;
    C.fn.pulsoCreateScreen = pulsoCreateScreen;
    C.fn.openCreatePanel = openCreatePanel;
    C.fn.openMisCreaciones = openMisCreaciones;
    C.fn.pulsoMineFilter = pulsoMineFilterSet;
    C.fn.loadMisCreaciones = loadMisCreaciones;
    C.fn.pulsoCreateSetTool = pulsoCreateSetTool;
    C.fn.pulsoCreateBack = pulsoCreateBack;
    C.fn.closeCreatePanel = pulsoCreateBack; // nombre anterior de la acción
    C.fn.pulsoCreateEnter = pulsoCreateEnter;
    C.fn.pulsoSyncPolling = pulsoSyncPolling;
    C.fn.pulsoAbrirHistorial = pulsoAbrirHistorial;
    C.fn.submitCreate = submitCreate;
    C.fn.openCreateGalleryItem = openCreateGalleryItem;

    return {};
});
