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
 * Utilidades comunes del chat de Pulse AI: estado compartido, configuracion, escape de HTML y delegacion de eventos.
 *
 * @module     block_pulso/common
 * @copyright  2026 Awakelab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    var S = {};
    var cfg = {};
    var fn = {};
    S.pulsoCreateResources = [];
    S.pulsoAmpToken = 0;
    S.pulsoRetosPollTimer = null;
    S.pulsoRetosRefreshTimer = null;
    S.pulsoRetosPollPending = null;
    S.pulsoRetosRefreshPending = null;
    // Pestaña activa de la cabecera: 'ask' (Preguntar) o 'crear'. Sin pestañas (sin createactivity) siempre 'ask'.
    S.pulsoTab = 'ask';

        // ========== HELPERS DE FASE 3 (UX, v1.32.0) ==========

        // Los sondeos de Crear y Retos solo corren con la pestaña del navegador visible Y la
        // pestaña «Crear» de Pulse activa. Si no, se PAUSAN (el pendiente se guarda), no se cancelan.
        function pollsPaused() {
            return document.hidden || S.pulsoTab !== 'crear';
        }


        // Los console.log de depuración solo salen con window.pulsoDebug = true.
        function pulsoLog() {
            if (window.pulsoDebug && window.console) console.log.apply(console, arguments);
        }


        // Texto oculto para enlaces que abren pestaña nueva (la «↗» va con aria-hidden).
        const PULSO_NEWTAB_SR = '<span class="pulso-sr-only"> (se abre en una pestaña nueva)</span>';


        // El desplazamiento del chat vive en #pulso-scroll: envuelve la home y el registro de
        // mensajes (#pulso-messages, la única región en vivo). Con otra pestaña activa su panel
        // está oculto y no hay dónde desplazar: se anota y se hace al volver a Preguntar.
        function pulsoScrollToEnd() {
            const scroller = document.getElementById('pulso-scroll');
            if (!scroller) return;
            if (S.pulsoTab !== 'ask') {
                scroller.setAttribute('data-pulso-stick', '1');
                return;
            }
            scroller.scrollTop = scroller.scrollHeight;
        }


        // Aviso breve para lector de pantalla: región role="status" propia, FUERA del registro.
        // Se vacía y se rellena después para que repetir el mismo texto vuelva a anunciarse.
        function pulsoAnnounce(text) {
            const el = document.getElementById('pulso-live-status');
            if (!el) return;
            el.textContent = '';
            setTimeout(function() { el.textContent = text; }, 60);
        }


        // disabled + aria-disabled siempre juntos.
        function pulsoSetDisabled(el, flag) {
            if (!el) return;
            el.disabled = !!flag;
            el.setAttribute('aria-disabled', flag ? 'true' : 'false');
        }

        
        function escapeHtml(unsafe) {
            return String(unsafe || '')
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }


        // escapeHtmlText no escapa comillas: para CUALQUIER valor de atributo (href, src, alt, placeholder…) usar esta.
        function pulsoEscapeAttr(text) {
            return escapeHtmlText(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }


        function pulsoAmpIsHttps(url) {
            return typeof url === 'string' && url.indexOf('https://') === 0;
        }


        function escapeHtmlText(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

    // Delegacion de eventos: sustituye a los onclick/oninput/onkeyup en linea.
    // Los elementos llevan data-pulso-action|input|keyup="nombre" y data-pulso-arg / data-pulso-arg2.
    var EVENT_ATTRS = {click: 'data-pulso-action', input: 'data-pulso-input', keyup: 'data-pulso-keyup'};
    var bindActions = function(root) {
        Object.keys(EVENT_ATTRS).forEach(function(type) {
            var attr = EVENT_ATTRS[type];
            root.addEventListener(type, function(e) {
                var el = e.target && e.target.closest ? e.target.closest('[' + attr + ']') : null;
                if (!el || !root.contains(el) || el.disabled) {
                    return;
                }
                var action = fn[el.getAttribute(attr)];
                if (typeof action === 'function') {
                    action(el.getAttribute('data-pulso-arg'), el.getAttribute('data-pulso-arg2'), el, e);
                }
            });
        });
    };

    return {
        S: S,
        cfg: cfg,
        fn: fn,
        bindActions: bindActions,
        pulsoLog: pulsoLog,
        pollsPaused: pollsPaused,
        PULSO_NEWTAB_SR: PULSO_NEWTAB_SR,
        pulsoScrollToEnd: pulsoScrollToEnd,
        pulsoAnnounce: pulsoAnnounce,
        pulsoSetDisabled: pulsoSetDisabled,
        escapeHtml: escapeHtml,
        pulsoEscapeAttr: pulsoEscapeAttr,
        pulsoAmpIsHttps: pulsoAmpIsHttps,
        escapeHtmlText: escapeHtmlText
    };
});
