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
 * Presentación: el profesorado empieza una presentación de la Factoría de Épica desde Pulse (carta 18).
 *
 * @module     block_pulso/presentacion
 * @copyright  2026 Awakelab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['block_pulso/common'], function(C) {
    'use strict';

    var S = C.S;
    var cfg = C.cfg;
    var pulsoSetDisabled = C.pulsoSetDisabled;
    var pulsoEscapeAttr = C.pulsoEscapeAttr;
    var escapeHtmlText = C.escapeHtmlText;

    // ---- Presentación (api_presentacion.php) ----
    // Formulario (nivel 1 de la pila de Crear) y «Presentación preparada» (detalle, nivel 2). El código del
    // borrador vive SOLO aquí, en memoria: no va a la URL, ni a localStorage, ni al historial. Si la persona
    // sale del panel lo pierde (aceptado en v2.8.0): el borrador dura 24 h en Épica, pero Pulse no lo guarda.
    // Todo lo que se pinta (título, mensajes del servidor) se escapa.

    var PULSO_PRES_TEMA_MIN = 10;
    var PULSO_PRES_TEMA_MAX = 4000;
    var PULSO_PRES_TITULO_MAX = 200;
    var PULSO_PRES_DIAP_MIN = 3;
    var PULSO_PRES_DIAP_MAX = 30;

    var pulsoPresBorrador = '';
    var pulsoPresLastParams = null;

    function pulsoPresBorradorValido(codigo) {
        return typeof codigo === 'string' && /^[A-Za-z0-9_-]{16,64}$/.test(codigo);
    }


    function pulsoPresMsg(text) {
        const el = document.getElementById('pulso-pres-msg');
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
    }


    // El cuadro de «Tema» solo sale sin recurso.
    function pulsoPresSyncTema() {
        const select = document.getElementById('pulso-create-resource');
        const field = document.getElementById('pulso-pres-tema-field');
        if (!select || !field) return;
        field.hidden = (parseInt(select.value, 10) || 0) > 0;
    }


    function renderPresentacionForm(resources) {
        const body = document.getElementById('pulso-create-body');
        if (!body) return;

        let optionsHtml = '<option value="0">Sin recurso: escribir un tema</option>';
        (resources || []).forEach(function(r) {
            const flag = r.lowtext ? ' — texto escaso, resultado limitado' : '';
            optionsHtml += '<option value="' + r.cmid + '">'
                + escapeHtmlText(r.moduletypelabel) + ': ' + escapeHtmlText(r.name) + escapeHtmlText(flag)
                + '</option>';
        });

        body.innerHTML = ''
            + '<div class="pulso-create-field">'
            + '<label for="pulso-create-resource">Recurso</label>'
            + '<select id="pulso-create-resource">' + optionsHtml + '</select>'
            + '<div class="pulso-create-hint">Con un recurso, Pulse resume su contenido para la presentación.</div>'
            + '</div>'
            + '<div class="pulso-create-field" id="pulso-pres-tema-field">'
            + '<label for="pulso-pres-tema">Tema</label>'
            + '<textarea id="pulso-pres-tema" maxlength="' + PULSO_PRES_TEMA_MAX + '" placeholder="'
            + pulsoEscapeAttr('Por ejemplo: introducción a la fotosíntesis para 1.º de ESO') + '"></textarea>'
            + '<div class="pulso-create-hint">Entre ' + PULSO_PRES_TEMA_MIN + ' y ' + PULSO_PRES_TEMA_MAX
            + ' caracteres. Sin recurso, el contenido lo escribe la Factoría con lo que sabe del tema (no ve el material del curso).</div>'
            + '</div>'
            + '<div class="pulso-create-field">'
            + '<label for="pulso-pres-titulo">Título (opcional)</label>'
            + '<input type="text" id="pulso-pres-titulo" maxlength="' + PULSO_PRES_TITULO_MAX + '">'
            + '</div>'
            + '<div class="pulso-create-field">'
            + '<label for="pulso-pres-diap">Nº de diapositivas (opcional)</label>'
            + '<input type="number" id="pulso-pres-diap" min="' + PULSO_PRES_DIAP_MIN + '" max="' + PULSO_PRES_DIAP_MAX + '" inputmode="numeric">'
            + '<div class="pulso-create-hint">De ' + PULSO_PRES_DIAP_MIN + ' a ' + PULSO_PRES_DIAP_MAX
            + '. Vacío: las que pida el contenido.</div>'
            + '</div>'
            + '<div id="pulso-pres-msg" class="pulso-create-notice-inline danger" role="alert" hidden></div>'
            + '<button type="button" class="pulso-create-submit" id="pulso-create-submit-btn" data-pulso-action="submitPresentacion">'
            + 'Preparar presentación</button>';

        const select = document.getElementById('pulso-create-resource');
        if (select) select.addEventListener('change', pulsoPresSyncTema);
        pulsoPresSyncTema();
    }


    // Lee y valida el formulario. Devuelve los parámetros o null (con el aviso ya pintado).
    function pulsoPresLeerForm() {
        const select = document.getElementById('pulso-create-resource');
        const temaEl = document.getElementById('pulso-pres-tema');
        const tituloEl = document.getElementById('pulso-pres-titulo');
        const diapEl = document.getElementById('pulso-pres-diap');
        if (!select || !temaEl || !tituloEl || !diapEl) return null;

        const cmid = parseInt(select.value, 10) || 0;
        const tema = temaEl.value.trim();
        const titulo = tituloEl.value.trim();
        const diapRaw = diapEl.value.trim();

        if (cmid <= 0 && (tema.length < PULSO_PRES_TEMA_MIN || tema.length > PULSO_PRES_TEMA_MAX)) {
            pulsoPresMsg('Elige un recurso o escribe un tema de entre ' + PULSO_PRES_TEMA_MIN + ' y ' + PULSO_PRES_TEMA_MAX + ' caracteres.');
            temaEl.focus();
            return null;
        }
        if (titulo.length > PULSO_PRES_TITULO_MAX) {
            pulsoPresMsg('El título puede tener como máximo ' + PULSO_PRES_TITULO_MAX + ' caracteres.');
            tituloEl.focus();
            return null;
        }
        let diap = '';
        if (diapRaw !== '') {
            const n = /^\d{1,3}$/.test(diapRaw) ? parseInt(diapRaw, 10) : 0;
            if (n < PULSO_PRES_DIAP_MIN || n > PULSO_PRES_DIAP_MAX) {
                pulsoPresMsg('El número de diapositivas tiene que estar entre ' + PULSO_PRES_DIAP_MIN + ' y ' + PULSO_PRES_DIAP_MAX + '.');
                diapEl.focus();
                return null;
            }
            diap = String(n);
        }
        pulsoPresMsg('');
        return { cmid: cmid, tema: cmid > 0 ? '' : tema, titulo: titulo, diapositivas: diap };
    }


    function pulsoPresActions(html) {
        return '<div class="pulso-amp-actions">' + html + '</div>';
    }


    function renderPresentacionError(message, retryable) {
        const body = document.getElementById('pulso-create-body');
        if (!body) return;
        body.innerHTML = '<div class="pulso-amp">'
            + '<div class="pulso-create-notice-inline danger" role="alert">' + escapeHtmlText(message) + '</div>'
            + pulsoPresActions(
                (retryable && pulsoPresLastParams
                    ? '<button type="button" class="pulso-create-submit" data-pulso-action="pulsoPresReintentar">Reintentar</button>'
                    : '')
                + '<button type="button" class="pulso-create-submit" data-pulso-action="pulsoCreateBack">Volver al formulario</button>')
            + '</div>';
    }


    // «El borrador vale hasta las HH:MM» (hora local; «de mañana» si cae al día siguiente).
    function pulsoPresCaducaTexto(caduca) {
        const d = typeof caduca === 'string' ? new Date(caduca) : null;
        if (!d || isNaN(d.getTime())) return 'El borrador vale 24 horas.';
        const hora = d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        const hoy = new Date();
        const otroDia = d.getFullYear() !== hoy.getFullYear() || d.getMonth() !== hoy.getMonth() || d.getDate() !== hoy.getDate();
        return 'El borrador vale hasta las ' + hora + (otroDia ? ' de mañana' : '') + '.';
    }


    function renderPresentacionDone(data) {
        const body = document.getElementById('pulso-create-body');
        if (!body) return;
        const titulo = typeof data.titulo === 'string' ? data.titulo : '';
        body.innerHTML = '<div class="pulso-amp">'
            + '<div class="pulso-amp-head">Presentación preparada'
            + (titulo ? ': <strong>' + escapeHtmlText(titulo) + '</strong>' : '') + '</div>'
            + '<div class="pulso-create-notice-inline" role="status">'
            + escapeHtmlText(pulsoPresCaducaTexto(data.caduca))
            + ' Termínala en la Factoría de Épica: allí eliges el estilo, el color y las secciones.</div>'
            + pulsoPresActions(
                '<button type="button" class="pulso-create-submit" data-pulso-action="pulsoPresAbrir">'
                + 'Abrir en la Factoría<span aria-hidden="true"> ↗</span>' + C.PULSO_NEWTAB_SR + '</button>'
                + '<button type="button" class="pulso-create-submit" data-pulso-action="openCreatePanel" data-pulso-arg="presentacion">'
                + 'Preparar otra</button>')
            + '</div>';
    }


    function pulsoPresEnviar(params) {
        const btn = document.getElementById('pulso-create-submit-btn');
        const body = document.getElementById('pulso-create-body');
        if (!body || (btn && btn.disabled)) return;
        if (btn) pulsoSetDisabled(btn, true); // antidoble clic

        pulsoPresLastParams = params;
        pulsoPresBorrador = '';
        C.fn.pulsoCreateEnter('pres', 'presentacion'); // cambio de pantalla: invalida lo anterior
        const token = ++S.pulsoAmpToken;

        body.innerHTML = '<div class="pulso-amp-loading" role="status">'
            + '<span class="pulso-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
            + '<span>' + (params.cmid > 0 ? 'Preparando el contenido de la presentación… puede tardar hasta un minuto.'
                : 'Preparando el borrador…') + '</span></div>';

        const formData = new FormData();
        formData.append('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
        formData.append('courseid', cfg.courseid);
        formData.append('cmid', String(params.cmid));
        formData.append('tema', params.tema);
        // Lo opcional vacío no se manda: el servidor ya lo acepta ausente y el POST queda limpio.
        if (params.titulo !== '') formData.append('titulo', params.titulo);
        if (params.diapositivas !== '') formData.append('diapositivas', params.diapositivas);

        // Se lee el cuerpo como texto y se interpreta aparte: `r.json()` fallaba igual con una página de login,
        // un 504 de proxy o un fallo de red, y todo salía como «no se ha podido conectar». El segundo argumento
        // de `then` (no un `catch` al final) recoge SOLO los fallos de red: un error al pintar no se disfraza de red.
        fetch(cfg.apiPresentacionUrl, { method: 'POST', credentials: 'same-origin', body: formData })
            .then(function(r) {
                return r.text().then(function(text) {
                    return {
                        status: r.status,
                        ctype: (r.headers && r.headers.get('content-type')) || '',
                        url: r.url || '',
                        text: text
                    };
                });
            })
            .then(function(res) {
                if (token !== S.pulsoAmpToken) return; // la persona ya salió de esta pantalla
                let data = null;
                try {
                    data = JSON.parse(res.text);
                } catch (e) {
                    data = null;
                }
                if (data && typeof data === 'object' && !Array.isArray(data) && 'success' in data) {
                    if (data.success && pulsoPresBorradorValido(data.borrador)) {
                        pulsoPresBorrador = data.borrador;
                        renderPresentacionDone(data);
                    } else {
                        const code = data.error_code;
                        renderPresentacionError(
                            (typeof data.message === 'string' && data.message)
                                || 'No se ha podido preparar la presentación. Inténtalo de nuevo.',
                            code === 'busy' || code === 'network');
                    }
                    return;
                }
                // Ni JSON ni con la forma del endpoint: solo estado y content-type al log, nunca el cuerpo.
                C.pulsoLog('Pulse presentación: respuesta no válida, status', res.status, 'content-type', res.ctype);
                const raro = pulsoPresRespuestaRara(res);
                renderPresentacionError(raro.message, raro.retryable);
            }, function() {
                if (token !== S.pulsoAmpToken) return;
                renderPresentacionError('No se ha podido conectar para preparar la presentación. Inténtalo de nuevo.', true);
            });
    }


    // Texto según lo que llegó cuando NO es la respuesta JSON del endpoint.
    function pulsoPresRespuestaRara(res) {
        const esHtml = /html/i.test(res.ctype) || /^\s*<(!doctype|html)/i.test(res.text);
        const pareceLogin = /\/login\//.test(res.url)
            || (esHtml && /type=["']?password|id=["']?loginbtn|name=["']?logintoken/i.test(res.text));
        if (res.status === 401 || res.status === 403 || pareceLogin) {
            return { message: 'Tu sesión de Moodle ha caducado. Recarga la página y vuelve a intentarlo.', retryable: false };
        }
        if (res.status === 502 || res.status === 503 || res.status === 504) {
            return { message: 'El servidor ha tardado demasiado en responder. Inténtalo de nuevo en un momento.', retryable: true };
        }
        return { message: 'No se ha podido preparar la presentación. Inténtalo de nuevo.', retryable: false };
    }


    function submitPresentacion() {
        const params = pulsoPresLeerForm();
        if (params) pulsoPresEnviar(params);
    }


    function pulsoPresReintentar() {
        if (pulsoPresLastParams) pulsoPresEnviar(pulsoPresLastParams);
    }


    // «Abrir en la Factoría»: solo profesorado y solo con un borrador de la forma esperada; el POST lo hace
    // crear.js en el propio clic (pestaña nueva) hacia fuentes_puente.php, que firma el token en servidor.
    function pulsoPresAbrir() {
        if (cfg.isTeacher !== true || !pulsoPresBorradorValido(pulsoPresBorrador)) return;
        C.fn.pulsoAbrirFactoria(pulsoPresBorrador);
    }

    C.fn.renderPresentacionForm = renderPresentacionForm;
    C.fn.submitPresentacion = submitPresentacion;
    C.fn.pulsoPresReintentar = pulsoPresReintentar;
    C.fn.pulsoPresAbrir = pulsoPresAbrir;

    return {};
});
