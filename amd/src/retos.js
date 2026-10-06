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
 * Retos: proponer, elegir y seguir un reto generado por Epica.
 *
 * @module     block_pulso/retos
 * @copyright  2026 Awakelab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['block_pulso/common'], function(C) {
    'use strict';

    var S = C.S;
    var cfg = C.cfg;
    var PULSO_NEWTAB_SR = C.PULSO_NEWTAB_SR;
    var pulsoSetDisabled = C.pulsoSetDisabled;
    var pulsoEscapeAttr = C.pulsoEscapeAttr;
    var pulsoAmpIsHttps = C.pulsoAmpIsHttps;
    var escapeHtmlText = C.escapeHtmlText;



        // ---- Crear reto (api_retos.php, v1.27) ----
        // Formulario y pantallas dentro de la pila de Crear (ver crear.js): el formulario es el nivel 1
        // y todo lo que viene después (espera, seis retos, reto elegido, errores finales) es UN solo
        // detalle: «Volver» desde cualquiera de ellos va al formulario. Retos se llama desde la
        // petición web (carta 8 §1): el navegador sondea NUESTRO endpoint cada 4 s y
        // nunca habla con Épica. Todo lo que viene de Épica se escapa (atributos con
        // pulsoEscapeAttr), los enlaces solo si son https:// y no hay ningún iframe:
        // el reto se abre siempre en pestaña nueva (Épica sirve X-Frame-Options: DENY).
        const PULSO_RETOS_POLL_MS = 4000;

        const PULSO_RETOS_SLOW_S = 120;
        // límite de espera, contado desde el primer "trabajando"
        const PULSO_RETOS_REFRESH_MS = 45000;
  // primera petición del título final del reto escrito
        const PULSO_RETOS_REFRESH_RETRY_MS = 20000;
  // reintentos mientras Épica siga en-cola/trabajando
        const PULSO_RETOS_REFRESH_MAX_MS = 180000;
   // tope total desde que se pinta el reto
        const PULSO_RETO_PROPIO_MIN = 8;

        const PULSO_RETO_PROPIO_MAX = 140;


        let pulsoRetosBusy = false;
            // antidoble clic: una sola acción en vuelo
        let pulsoRetosCourseBusy = false;

        let pulsoRetosPropuestaId = 0;

        let pulsoRetosList = [];
               // los seis retos; se elige por ÍNDICE, el id nunca va a un atributo
        let pulsoRetosGrace = 0;
               // "Seguir esperando": trabajando_desde ya tolerado
        let pulsoRetosLastSeen = 0;

        let pulsoRetosLastParams = null;
       // para «Volver a intentarlo» (propuesta nueva)
        let pulsoRetosRetryFn = null;
          // «Reintentar» repite la MISMA acción

      // token del sondeo pendiente (pestaña oculta)

   // {token, codigo, started} del refresco de título pendiente

        // Los doce nombres fijos de icono de Épica; uno desconocido cae en 'flag'.
        const PULSO_RETOS_ICONS = {
            wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            chart: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            network: '<rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/>',
            users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            lightbulb: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
            code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            cpu: '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
            target: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            briefcase: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            leaf: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
            flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>'
        };


        // Texto de Épica → HTML: el escape va SIEMPRE antes; luego solo **negrita**, nada más de markdown.
        function pulsoRetosMd(text) {
            return escapeHtmlText(text).replace(/\*\*([^*\r\n]+?)\*\*/g, '<strong>$1</strong>');
        }


        function pulsoRetosIconSvg(name) {
            const key = String(name || '').toLowerCase();
            const path = Object.prototype.hasOwnProperty.call(PULSO_RETOS_ICONS, key) ? PULSO_RETOS_ICONS[key] : PULSO_RETOS_ICONS.flag;
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
        }


        // Inicial / Intermedio / Avanzado con los colores de estado del tema claro.
        function pulsoRetosDiff(raw) {
            const key = String(raw || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            const known = { inicial: ['Inicial', 'ok'], basico: ['Inicial', 'ok'], intermedio: ['Intermedio', 'warn'], avanzado: ['Avanzado', 'danger'] };
            if (Object.prototype.hasOwnProperty.call(known, key)) return { label: known[key][0], cls: known[key][1] };
            return raw ? { label: String(raw), cls: '' } : null;
        }


        // Llamada única a api_retos.php. Nunca rechaza: devuelve el JSON del servidor
        // ({ok:true,…} o {ok:false,error,mensaje,…}) o un error de red reintentable.
        function pulsoRetosCall(accion, fields) {
            const fd = new FormData();
            fd.append('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            fd.append('courseid', cfg.courseid);
            fd.append('accion', accion);
            Object.keys(fields || {}).forEach(function(k) { fd.append(k, fields[k]); });
            return fetch(cfg.apiRetosUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function(r) {
                    return r.json().catch(function() {
                        return { ok: false, error: 'respuesta-invalida', mensaje: 'La respuesta del servidor no es válida. Inténtalo de nuevo.', reintentable: true };
                    });
                })
                .catch(function() {
                    return { ok: false, error: 'sin-conexion', mensaje: 'No se ha podido conectar. Comprueba tu conexión e inténtalo de nuevo.', reintentable: true };
                });
        }


        // Al salir del panel o abrir otra pantalla (la llama stopCreatePolling):
        // para sondeo y refresco, libera el antidoble clic e invalida con
        // pulsoAmpToken cualquier respuesta tardía.
        function pulsoRetosReset() {
            if (S.pulsoRetosPollTimer) { clearTimeout(S.pulsoRetosPollTimer); S.pulsoRetosPollTimer = null; }
            if (S.pulsoRetosRefreshTimer) { clearTimeout(S.pulsoRetosRefreshTimer); S.pulsoRetosRefreshTimer = null; }
            S.pulsoRetosPollPending = null;
            S.pulsoRetosRefreshPending = null;
            pulsoRetosBusy = false;
            pulsoRetosCourseBusy = false;
            pulsoRetosRetryFn = null;
            S.pulsoAmpToken++;
        }


        // Antidoble clic: con una acción en vuelo se desactivan TODAS las de la pantalla.
        function pulsoRetosSetBusy(busy) {
            pulsoRetosBusy = busy;
            document.querySelectorAll('#pulso-create-body [data-retos-action]').forEach(function(el) { pulsoSetDisabled(el, busy); });
        }


        const PULSO_RETOS_BANNER_ACTIONS = {
            retry: ['Reintentar', 'pulsoRetosRetry'],
            wait: ['Seguir esperando', 'pulsoRetosSeguirEsperando']
        };


        // Aviso en #pulso-retos-msg (texto siempre con textContent). action: 'retry' | 'wait'.
        function pulsoRetosBanner(text, kind, action) {
            const el = document.getElementById('pulso-retos-msg');
            if (!el) return;
            if (!text) { el.innerHTML = ''; return; }
            const act = action ? PULSO_RETOS_BANNER_ACTIONS[action] : null;
            el.innerHTML = '<div class="pulso-create-notice-inline ' + (kind || '') + '"></div>'
                + (act ? '<button type="button" class="pulso-create-secondary" data-pulso-action="' + act[1] + '">' + act[0] + '</button>' : '');
            el.firstChild.textContent = text;
        }


        // Se decide por `error`, nunca por el texto. Solo se reescribe lo que tiene
        // texto propio; el resto (topes propios, herramienta-no-contratada,
        // epica-no-disponible, encargo-sin-tema…) usa el mensaje del servidor.
        function pulsoRetosErrorText(d) {
            const msg = (d && typeof d.mensaje === 'string' && d.mensaje) ? d.mensaje : 'No hemos podido completar la operación. Inténtalo de nuevo.';
            switch (d && d.error) {
                case 'cuota-agotada': {
                    const s = Number(d.esperaS) || 0;
                    return s > 0 ? 'Has pedido varios seguidos; espera ' + Math.max(1, Math.ceil(s / 60)) + ' min.'
                                 : 'Has pedido varios seguidos; espera unos minutos.';
                }
                case 'cuota-del-centro': return 'El centro ha alcanzado el límite de hoy. No es nada que hayas hecho tú.';
                case 'material-ilegible': return 'No hemos podido leer este recurso; prueba con otro o con un tema.';
                case 'propuesta-desconocida': return 'Esta propuesta ya no está disponible.';
                case 'reto-desconocido': return 'Ese reto no estaba entre los propuestos. Elige otro de la lista.';
                default: return msg;
            }
        }


        function pulsoRetosHandleError(d) {
            const code = d && d.error;
            if (code === 'peticion-en-curso') return; // ya hay una en vuelo: en silencio
            if (code === 'propuesta-desconocida') {
                pulsoRetosRenderEnd('danger', pulsoRetosErrorText(d), 'Proponer retos de nuevo', 'form');
                return;
            }
            pulsoRetosBanner(pulsoRetosErrorText(d), 'danger',
                (d && d.reintentable === true && typeof pulsoRetosRetryFn === 'function') ? 'retry' : null);
        }


        function pulsoRetosRetry() {
            const fn = pulsoRetosRetryFn;
            if (pulsoRetosBusy || typeof fn !== 'function') return;
            pulsoRetosBanner('');
            fn();
        }


        // --- Formulario ---
        function renderRetosForm(resources) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;

            let optionsHtml = '<option value="0">Sin recurso, solo un tema</option>';
            resources.forEach(function(r) {
                const flag = r.lowtext ? ' — texto escaso, resultado limitado' : '';
                optionsHtml += '<option value="' + r.cmid + '">'
                    + escapeHtmlText(r.moduletypelabel) + ': ' + escapeHtmlText(r.name) + escapeHtmlText(flag)
                    + '</option>';
            });

            body.innerHTML = ''
                + '<div class="pulso-create-field">'
                + '<label for="pulso-create-resource">Recurso</label>'
                + '<select id="pulso-create-resource" data-retos-action>' + optionsHtml + '</select>'
                + '</div>'
                + '<div class="pulso-create-field">'
                + '<label for="pulso-retos-tema">Tema (opcional)</label>'
                + '<textarea id="pulso-retos-tema" data-retos-action maxlength="500" placeholder="'
                + pulsoEscapeAttr('Por ejemplo: muestreo estratificado en una encuesta sobre ocio juvenil') + '"></textarea>'
                + '<div class="pulso-create-hint">Obligatorio si eliges «Sin recurso». Con un recurso, acota los retos dentro de su contenido.</div>'
                + '</div>'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<button type="button" class="pulso-create-submit" id="pulso-retos-propose-btn" data-retos-action data-pulso-action="pulsoRetosProponer">Proponer retos</button>'
                + '<button type="button" class="pulso-create-back-link" data-pulso-action="pulsoRetosVerCurso">Ver todos los retos del curso' + PULSO_NEWTAB_SR + '</button>';
        }


        function pulsoRetosProponer() {
            const select = document.getElementById('pulso-create-resource');
            const temaEl = document.getElementById('pulso-retos-tema');
            if (!select || !temaEl || pulsoRetosBusy) return;
            const cmid = parseInt(select.value, 10) || 0;
            const tema = temaEl.value.trim();
            if (!cmid && !tema) {
                pulsoRetosBanner('Elige un recurso o escribe un tema para los retos.', 'warn');
                temaEl.focus();
                return;
            }
            const params = {};
            if (cmid > 0) params.cmid = String(cmid);
            if (tema) params.tema = tema;
            pulsoRetosEnviarProponer(params, 'pulso-retos-propose-btn');
        }


        // «Proponer otros»: SOLO `propuesta` (con recurso o tema sería encargo-ambiguo).
        function pulsoRetosOtros() {
            pulsoRetosEnviarProponer({ propuesta: String(pulsoRetosPropuestaId) }, 'pulso-retos-others-btn');
        }


        // «Volver a intentarlo» tras un fallado: es una propuesta nueva.
        function pulsoRetosVolverAIntentar() {
            if (!pulsoRetosLastParams) { C.fn.openCreatePanel('retos'); return; }
            pulsoRetosEnviarProponer(pulsoRetosLastParams, 'pulso-retos-end-btn');
        }


        // Una propuesta nueva (gasta cupo): un solo vuelo a la vez. La pantalla
        // actual se queda como está hasta tener respuesta (así un 409 ignorado
        // no deja nada roto); solo el botón pulsado cambia de texto.
        function pulsoRetosEnviarProponer(params, btnId) {
            if (pulsoRetosBusy) return;
            pulsoRetosSetBusy(true);
            const token = S.pulsoAmpToken;
            const btn = document.getElementById(btnId);
            const label = btn ? btn.textContent : '';
            if (btn) btn.textContent = 'Enviando…';
            pulsoRetosBanner('');
            pulsoRetosRetryFn = function() { pulsoRetosEnviarProponer(params, btnId); };

            pulsoRetosCall('proponer', params).then(function(d) {
                if (token !== S.pulsoAmpToken) return; // el usuario ya salió de esta pantalla
                // proponer devuelve el id PLANO en `propuesta` (el sondeo lo devuelve anidado).
                const id = Number(d.propuesta && typeof d.propuesta === 'object' ? d.propuesta.id : d.propuesta) || 0;
                if (!d.ok || !id) {
                    pulsoRetosSetBusy(false);
                    if (btn) btn.textContent = label;
                    pulsoRetosHandleError(d.ok ? { error: 'respuesta-inesperada', mensaje: 'No hemos recibido el identificador de la propuesta. Inténtalo de nuevo.' } : d);
                    return;
                }
                pulsoRetosSetBusy(false);
                pulsoRetosPropuestaId = id;
                pulsoRetosLastParams = params;
                pulsoRetosGrace = 0;
                pulsoRetosLastSeen = 0;
                pulsoRetosRenderWaiting();
                pulsoRetosShowStatus(d.estado, d.posicion);
                pulsoRetosRetryFn = function() { pulsoRetosPoll(S.pulsoAmpToken); };
                // Entrar en la espera es un cambio de pantalla (token nuevo desde el formulario): el
                // sondeo se arma con el token VIGENTE, no con el de la petición que acaba de volver.
                pulsoRetosSchedulePoll(S.pulsoAmpToken);
            });
        }


        // Abre una propuesta que YA existe (desde «Mis creaciones»): la MISMA propuesta, nunca un `proponer`
        // nuevo (gastaría otra unidad de cupo). Es un detalle de la pila: «Volver» regresa a «Mis creaciones».
        // El sondeo normal hace el resto: en cola/trabajando espera sin límite en cola, lista pinta los seis.
        function pulsoRetosAbrirPropuesta(id) {
            id = parseInt(id, 10) || 0;
            if (!id || !document.getElementById('pulso-create-body')) return;
            C.fn.pulsoCreateSetTool('retos');
            pulsoRetosPropuestaId = id;
            // Sin parámetros de una propuesta anterior: «Volver a intentarlo» (si acaba fallando) lleva al
            // formulario y no repite otra propuesta distinta de la que se abrió.
            pulsoRetosLastParams = null;
            pulsoRetosGrace = 0;
            pulsoRetosLastSeen = 0;
            pulsoRetosRenderWaiting();
            C.fn.pulsoCreateScreen(true);
            // Entrar en el detalle ha subido el token: se arma con el VIGENTE.
            pulsoRetosPoll(S.pulsoAmpToken);
        }


        // --- Esperando las propuestas ---
        function pulsoRetosRenderWaiting() {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            C.fn.pulsoCreateEnter('retos', 'retos');
            body.innerHTML = '<div class="pulso-retos">'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<div class="pulso-amp-loading" role="status">'
                + '<span class="pulso-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
                + '<span id="pulso-retos-status">Preparando tus retos…</span></div>'
                + '</div>';
        }


        function pulsoRetosShowStatus(estado, posicion) {
            const el = document.getElementById('pulso-retos-status');
            if (!el) return;
            const n = Number(posicion) || 0;
            el.textContent = estado === 'trabajando' ? 'Escribiendo seis retos para ti…'
                : (n > 0 ? 'Hay ' + n + ' por delante…' : 'Preparando tus retos…');
        }


        function pulsoRetosSchedulePoll(token) {
            // Con la pestaña del navegador oculta o la de Pulse en otra que no sea Crear no se
            // programa nada; pulsoSyncPolling() lo retoma al volver.
            S.pulsoRetosPollPending = token;
            if (C.pollsPaused()) return;
            S.pulsoRetosPollTimer = setTimeout(function() {
                S.pulsoRetosPollTimer = null;
                if (C.pollsPaused()) return;
                S.pulsoRetosPollPending = null;
                pulsoRetosPoll(token);
            }, PULSO_RETOS_POLL_MS);
        }


        // NO se corta mientras esté `en-cola` (carta 8 §1: cortar y volver a pulsar
        // encola otra propuesta y gasta otra unidad del cupo). El límite de 2 min
        // cuenta desde el primer `trabajando` (trabajando_desde) y solo ofrece
        // «Seguir esperando», que reanuda la MISMA propuesta.
        function pulsoRetosPoll(token) {
            S.pulsoRetosPollTimer = null;
            if (token !== S.pulsoAmpToken) return;
            pulsoRetosRetryFn = function() { pulsoRetosPoll(S.pulsoAmpToken); };

            pulsoRetosCall('propuesta', { propuesta: String(pulsoRetosPropuestaId) }).then(function(d) {
                if (token !== S.pulsoAmpToken) return;
                if (!d.ok) { pulsoRetosHandleError(d); return; } // el sondeo se detiene; «Reintentar» lo reanuda
                const p = d.propuesta || {};

                if (p.estado === 'listo') { pulsoRetosRenderPropuestas(p); return; }
                if (p.estado === 'fallado') {
                    pulsoRetosRenderEnd('danger', 'No hemos podido preparar los retos' + (p.motivo ? ': ' + p.motivo : '.'), 'Volver a intentarlo', 'retry');
                    return;
                }
                if (p.estado === 'desconocido') {
                    pulsoRetosRenderEnd('warn', 'Esta propuesta ya no está disponible.', 'Volver al formulario', 'form');
                    return;
                }

                pulsoRetosShowStatus(p.estado, p.posicion);
                if (p.estado === 'trabajando') {
                    pulsoRetosLastSeen = Number(p.trabajando_desde) || 0;
                    if (pulsoRetosLastSeen - pulsoRetosGrace >= PULSO_RETOS_SLOW_S) {
                        pulsoRetosBanner('Está tardando más de lo normal.', 'warn', 'wait');
                        return;
                    }
                }
                pulsoRetosSchedulePoll(token);
            });
        }


        function pulsoRetosSeguirEsperando() {
            pulsoRetosGrace = pulsoRetosLastSeen; // otros 2 min a partir de ahora
            pulsoRetosBanner('');
            pulsoRetosPoll(S.pulsoAmpToken);
        }


        // Pantalla final de error/aviso: act 'retry' = propuesta nueva con los mismos datos; 'form' = formulario.
        function pulsoRetosRenderEnd(kind, text, label, act) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-retos">'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<button type="button" class="pulso-create-submit" id="pulso-retos-end-btn" data-retos-action data-pulso-action="' + (act === 'retry' ? 'pulsoRetosVolverAIntentar' : 'openCreatePanel') + '"'
                + (act === 'retry' ? '' : ' data-pulso-arg="retos"') + '>' + escapeHtmlText(label) + '</button>'
                + '</div>';
            pulsoRetosBanner(text, kind);
        }


        // --- Las seis propuestas ---
        function pulsoRetosChip(text) {
            return '<span class="pulso-retos-chip">' + escapeHtmlText(text) + '</span>';
        }


        function pulsoRetosRenderPropuestas(p) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            pulsoRetosList = Array.isArray(p.retos) ? p.retos : [];
            if (!pulsoRetosList.length) {
                pulsoRetosRenderEnd('danger', 'No hemos recibido ningún reto. Inténtalo de nuevo.', 'Volver a intentarlo', 'retry');
                return;
            }
            const doc = p.documento || {};
            const temas = Array.isArray(doc.temas) ? doc.temas : [];

            let html = '<div class="pulso-retos">'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<div><div class="pulso-retos-doc-title">' + escapeHtmlText(doc.titulo || 'Retos para ti') + '</div>'
                + (doc.resumen ? '<div class="pulso-retos-note">' + pulsoRetosMd(doc.resumen) + '</div>' : '')
                + (temas.length ? '<div class="pulso-retos-chips">' + temas.map(pulsoRetosChip).join('') + '</div>' : '')
                + '</div>'
                + '<div class="pulso-create-hint">Elige el reto que quieras hacer.</div>';

            pulsoRetosList.forEach(function(r, i) {
                const diff = pulsoRetosDiff(r.dificultad);
                const minutos = Number(r.minutos) || 0;
                const comps = Array.isArray(r.competencias) ? r.competencias : [];
                html += '<button type="button" class="pulso-reto-card" data-retos-action data-pulso-action="pulsoRetosElegir" data-pulso-arg="' + i + '">'
                    + '<span class="pulso-reto-top">'
                    + '<span class="pulso-reto-icon" aria-hidden="true">' + pulsoRetosIconSvg(r.icono) + '</span>'
                    + '<span class="pulso-reto-title">' + escapeHtmlText(r.titulo) + '</span></span>'
                    + (r.descripcion ? '<span class="pulso-reto-desc">' + pulsoRetosMd(r.descripcion) + '</span>' : '')
                    + '<span class="pulso-reto-meta">'
                    + (diff ? '<span class="pulso-reto-diff ' + diff.cls + '">' + escapeHtmlText(diff.label) + '</span>' : '')
                    + (minutos > 0 ? '<span class="pulso-reto-min">' + minutos + ' min</span>' : '')
                    + comps.map(pulsoRetosChip).join('')
                    + '</span>'
                    + '<span class="pulso-reto-cta">Elegir este reto</span>'
                    + '</button>';
            });

            html += '<button type="button" class="pulso-create-secondary" id="pulso-retos-others-btn" data-retos-action data-pulso-action="pulsoRetosOtros">Proponer otros</button>'
                + '<button type="button" class="pulso-create-secondary" id="pulso-retos-idea-btn" data-retos-action aria-expanded="false" aria-controls="pulso-retos-own" data-pulso-action="pulsoRetosToggleIdea">¿Tienes otra idea?</button>'
                + '<div class="pulso-retos-own" id="pulso-retos-own" hidden>'
                + '<label class="pulso-retos-note" for="pulso-retos-own-text">Cuéntanos qué reto quieres (entre ' + PULSO_RETO_PROPIO_MIN + ' y ' + PULSO_RETO_PROPIO_MAX + ' caracteres)</label>'
                + '<textarea id="pulso-retos-own-text" data-retos-action maxlength="' + PULSO_RETO_PROPIO_MAX + '" data-pulso-input="pulsoRetosOwnCount"></textarea>'
                + '<span class="pulso-retos-own-count" id="pulso-retos-own-count">0/' + PULSO_RETO_PROPIO_MAX + '</span>'
                + '<button type="button" class="pulso-create-submit" id="pulso-retos-own-btn" data-retos-action data-pulso-action="pulsoRetosElegirPropio">Crear mi reto</button>'
                + '</div>'
                + '</div>';
            body.innerHTML = html;
        }


        function pulsoRetosToggleIdea() {
            const box = document.getElementById('pulso-retos-own');
            const btn = document.getElementById('pulso-retos-idea-btn');
            if (!box || pulsoRetosBusy) return;
            box.hidden = !box.hidden;
            if (btn) btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
            if (!box.hidden) {
                const ta = document.getElementById('pulso-retos-own-text');
                if (ta) ta.focus();
            }
        }


        function pulsoRetosOwnCount() {
            const ta = document.getElementById('pulso-retos-own-text');
            const out = document.getElementById('pulso-retos-own-count');
            if (ta && out) out.textContent = ta.value.length + '/' + PULSO_RETO_PROPIO_MAX;
        }


        // --- Elegir ---
        function pulsoRetosElegir(i) {
            i = parseInt(i, 10);
            const r = pulsoRetosList[i];
            if (!r || !r.id) return;
            const card = document.querySelectorAll('.pulso-reto-card')[i];
            pulsoRetosEnviarElegir({ reto: String(r.id) }, card ? card.querySelector('.pulso-reto-cta') : null);
        }


        function pulsoRetosElegirPropio() {
            const ta = document.getElementById('pulso-retos-own-text');
            if (!ta || pulsoRetosBusy) return;
            const text = ta.value.trim();
            if (text.length < PULSO_RETO_PROPIO_MIN) {
                pulsoRetosBanner('Describe tu reto con al menos ' + PULSO_RETO_PROPIO_MIN + ' caracteres.', 'warn');
                ta.focus();
                return;
            }
            if (text.length > PULSO_RETO_PROPIO_MAX) {
                pulsoRetosBanner('Tu reto es demasiado largo (máximo ' + PULSO_RETO_PROPIO_MAX + ' caracteres).', 'warn');
                return;
            }
            pulsoRetosEnviarElegir({ propio: text }, document.getElementById('pulso-retos-own-btn'));
        }


        // elegir devuelve el resultado PLANO: {codigo, enlace, enlace_curso, titulo}.
        function pulsoRetosEnviarElegir(fields, labelEl) {
            if (pulsoRetosBusy) return;
            pulsoRetosSetBusy(true);
            const token = S.pulsoAmpToken;
            const label = labelEl ? labelEl.textContent : '';
            if (labelEl) labelEl.textContent = 'Creando tu reto…';
            pulsoRetosBanner('');
            pulsoRetosRetryFn = function() { pulsoRetosEnviarElegir(fields, labelEl); };

            pulsoRetosCall('elegir', Object.assign({ propuesta: String(pulsoRetosPropuestaId) }, fields)).then(function(d) {
                if (token !== S.pulsoAmpToken) return;
                if (!d.ok || !pulsoAmpIsHttps(d.enlace)) {
                    pulsoRetosSetBusy(false);
                    if (labelEl) labelEl.textContent = label;
                    pulsoRetosHandleError(d.ok ? { error: 'respuesta-inesperada', mensaje: 'No hemos podido obtener el enlace del reto. Inténtalo de nuevo.' } : d);
                    return;
                }
                pulsoRetosSetBusy(false);
                pulsoRetosRenderDone(d, token);
            });
        }


        // --- El reto elegido ---
        function pulsoRetosRenderDone(d, token) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-retos-done">'
                + '<div class="pulso-retos-done-head">¡Tu reto está listo!</div>'
                + '<div class="pulso-reto-title" id="pulso-retos-done-title">' + escapeHtmlText(d.titulo || 'Reto') + '</div>'
                + '<div id="pulso-retos-done-stats" class="pulso-retos-note"></div>'
                + '<div id="pulso-retos-msg" class="pulso-retos-msg"></div>'
                + '<a class="pulso-create-submit pulso-retos-open" href="' + pulsoEscapeAttr(d.enlace) + '" target="_blank" rel="noopener noreferrer">Abrir reto' + PULSO_NEWTAB_SR + '</a>'
                + '<div class="pulso-retos-note">Se abre en una pestaña nueva. Si lo abres enseguida verás "Creando tu reto": tarda menos de un minuto.</div>'
                + (pulsoAmpIsHttps(d.enlace_curso)
                    ? '<a class="pulso-create-secondary" href="' + pulsoEscapeAttr(d.enlace_curso) + '" target="_blank" rel="noopener noreferrer">Ver retos del curso' + PULSO_NEWTAB_SR + '</a>'
                    : '')
                + '<button type="button" class="pulso-create-secondary" data-pulso-action="openCreatePanel" data-pulso-arg="retos">Crear otro reto</button>'
                + '</div>';
            // Hay un reto elegido nuevo: «Mis creaciones» se refresca (solo su caché, no se pinta aquí).
            C.fn.loadMisCreaciones();

            // Sin bloquear nada: a los ~45 s, y luego cada 20 s mientras no esté listo, hasta 3 min en total.
            const codigo = String(d.codigo || '');
            if (codigo) {
                const started = Date.now();
                pulsoRetosScheduleRefresh(token, codigo, started, PULSO_RETOS_REFRESH_MS);
            }
        }


        // El refresco del título final también llama a Épica: se pausa igual que el sondeo.
        function pulsoRetosScheduleRefresh(token, codigo, started, ms) {
            S.pulsoRetosRefreshPending = { token: token, codigo: codigo, started: started };
            if (C.pollsPaused()) return;
            S.pulsoRetosRefreshTimer = setTimeout(function() {
                S.pulsoRetosRefreshTimer = null;
                if (C.pollsPaused()) return;
                const p = S.pulsoRetosRefreshPending;
                S.pulsoRetosRefreshPending = null;
                if (p) pulsoRetosRefrescar(p.token, p.codigo, p.started);
            }, ms);
        }


        function pulsoRetosRefrescar(token, codigo, started) {
            S.pulsoRetosRefreshTimer = null;
            if (token !== S.pulsoAmpToken) return;
            pulsoRetosCall('refrescar', { codigo: codigo }).then(function(d) {
                if (token !== S.pulsoAmpToken || !d.ok) return; // es un extra: si falla, no se dice nada
                // Aún en cola/trabajando: otra vuelta a los 20 s si cabe en los 3 min totales.
                if (d.estado !== 'listo' && d.estado !== 'fallado' && d.estado !== 'desconocido'
                        && Date.now() - started + PULSO_RETOS_REFRESH_RETRY_MS <= PULSO_RETOS_REFRESH_MAX_MS) {
                    pulsoRetosScheduleRefresh(token, codigo, started, PULSO_RETOS_REFRESH_RETRY_MS);
                    return;
                }
                const title = document.getElementById('pulso-retos-done-title');
                if (title && d.titulo) title.textContent = d.titulo;
                // intentos/nota solo llegan si quien pregunta tiene viewanalytics: nunca al alumno.
                const stats = document.getElementById('pulso-retos-done-stats');
                if (stats && typeof d.intentos === 'number') {
                    const n = d.intentos;
                    stats.textContent = n + (n === 1 ? ' intento' : ' intentos')
                        + (d.ultimaPuntuacion === null || d.ultimaPuntuacion === undefined ? ' · aún sin nota' : ' · última nota ' + d.ultimaPuntuacion);
                }
                if (d.estado === 'fallado') {
                    pulsoRetosBanner('No se ha podido crear este reto' + (d.motivo ? ': ' + d.motivo : '.'), 'danger');
                }
            });
        }


        // «Ver todos los retos del curso»: la lista vive en Épica, no se pinta aquí.
        // Se abre una pestaña EN el clic (si no, el navegador bloquea el pop-up tras el
        // fetch) y se le pone el enlace al llegar; sin permiso, se ofrece un enlace.
        function pulsoRetosVerCurso() {
            if (pulsoRetosCourseBusy || pulsoRetosBusy) return;
            pulsoRetosCourseBusy = true;
            const token = S.pulsoAmpToken;
            pulsoRetosRetryFn = pulsoRetosVerCurso;
            pulsoRetosBanner('');
            const win = window.open('', '_blank');
            if (win) win.opener = null; // la página de Épica no debe poder tocar la pestaña de Moodle

            pulsoRetosCall('curso').then(function(d) {
                if (token !== S.pulsoAmpToken) { if (win) win.close(); return; }
                pulsoRetosCourseBusy = false;
                if (d.ok && pulsoAmpIsHttps(d.enlace)) {
                    if (win) {
                        win.location.href = d.enlace;
                    } else {
                        const el = document.getElementById('pulso-retos-msg');
                        if (el) el.innerHTML = '<a class="pulso-create-secondary" href="' + pulsoEscapeAttr(d.enlace)
                            + '" target="_blank" rel="noopener noreferrer">Abrir los retos del curso' + PULSO_NEWTAB_SR + '</a>';
                    }
                    return;
                }
                if (win) win.close();
                if (d.ok) {
                    pulsoRetosBanner('Todavía no hay una página de retos para este curso.', 'warn');
                } else {
                    pulsoRetosHandleError(d);
                }
            });
        }
    C.fn.pulsoRetosIconSvg = pulsoRetosIconSvg;
    C.fn.pulsoRetosCall = pulsoRetosCall;
    C.fn.pulsoRetosReset = pulsoRetosReset;
    C.fn.renderRetosForm = renderRetosForm;
    C.fn.pulsoRetosPoll = pulsoRetosPoll;
    C.fn.pulsoRetosAbrirPropuesta = pulsoRetosAbrirPropuesta;
    C.fn.pulsoRetosRefrescar = pulsoRetosRefrescar;
    C.fn.pulsoRetosRetry = pulsoRetosRetry;
    C.fn.pulsoRetosSeguirEsperando = pulsoRetosSeguirEsperando;
    C.fn.pulsoRetosProponer = pulsoRetosProponer;
    C.fn.pulsoRetosVerCurso = pulsoRetosVerCurso;
    C.fn.pulsoRetosVolverAIntentar = pulsoRetosVolverAIntentar;
    C.fn.pulsoRetosElegir = pulsoRetosElegir;
    C.fn.pulsoRetosOtros = pulsoRetosOtros;
    C.fn.pulsoRetosToggleIdea = pulsoRetosToggleIdea;
    C.fn.pulsoRetosOwnCount = pulsoRetosOwnCount;
    C.fn.pulsoRetosElegirPropio = pulsoRetosElegirPropio;

    return {};
});
