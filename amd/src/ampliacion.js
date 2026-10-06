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
 * Ampliar recurso: videos y articulos relacionados con un recurso del curso.
 *
 * @module     block_pulso/ampliacion
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



        // ---- Ampliar recurso: vídeos + artículos (api_ampliacion.php) ----
        // Misma pantalla y mismo desplegable que Crear. TODO lo que viene de
        // fuera (títulos, canales, autores, revistas, tema, avisos) se escapa;
        // las URLs solo se pintan si son https://, y no se incrusta YouTube.



        function pulsoAmpFormatViews(n) {
            n = Number(n) || 0;
            if (n <= 0) return '';
            if (n >= 1000000) {
                return (n / 1000000).toLocaleString('es-ES', { maximumFractionDigits: 1 }) + ' M de visualizaciones';
            }
            return n.toLocaleString('es-ES') + (n === 1 ? ' visualización' : ' visualizaciones');
        }


        function pulsoAmpFormatDuration(seconds) {
            const s = Math.round(Number(seconds) || 0);
            if (s <= 0) return '';
            if (s < 60) return s + ' s';
            const m = Math.round(s / 60);
            if (m < 60) return m + ' min';
            const h = Math.floor(m / 60);
            const r = m % 60;
            return h + ' h' + (r ? ' ' + r + ' min' : '');
        }


        function pulsoAmpCard(url, inner) {
            if (pulsoAmpIsHttps(url)) {
                return '<a class="pulso-amp-card" href="' + pulsoEscapeAttr(url) + '" target="_blank" rel="noopener noreferrer">' + inner + PULSO_NEWTAB_SR + '</a>';
            }
            return '<div class="pulso-amp-card">' + inner + '</div>';
        }


        function pulsoAmpVideoCard(v) {
            const title = v.titulo || 'Sin título';
            const thumbOk = typeof v.miniatura === 'string' && v.miniatura.indexOf('https://i.ytimg.com/') === 0;
            const thumb = thumbOk
                ? '<img class="pulso-amp-thumb" src="' + pulsoEscapeAttr(v.miniatura) + '" alt="' + pulsoEscapeAttr(title)
                    + '" loading="lazy" referrerpolicy="no-referrer">'
                : '<span class="pulso-amp-thumb pulso-amp-thumb-empty" aria-hidden="true">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="3"/><polygon points="10 9 15 12 10 15 10 9"/></svg></span>';
            const meta = [v.canal || '', pulsoAmpFormatViews(v.vistas), pulsoAmpFormatDuration(v.duracion)].filter(Boolean);
            return pulsoAmpCard(v.url, thumb
                + '<span class="pulso-amp-card-text">'
                + '<span class="pulso-amp-card-title">' + escapeHtmlText(title) + '</span>'
                + (meta.length ? '<span class="pulso-amp-card-meta">' + escapeHtmlText(meta.join(' · ')) + '</span>' : '')
                + '</span>');
        }


        // «En inglés»: solo si el articulo trae idioma y no es el del curso.
        function pulsoAmpLangLabel(code) {
            if (!code) return 'En otro idioma';
            try {
                const name = new Intl.DisplayNames(['es'], { type: 'language' }).of(code);
                if (name && name !== code) return 'En ' + name;
            } catch (e) { /* navegador sin Intl.DisplayNames */ }
            return 'En otro idioma';
        }


        function pulsoAmpArticleCard(a, courseLang) {
            const title = a.titulo || 'Sin título';
            const otherLang = a.idioma && courseLang && a.idioma !== courseLang;
            const line = [a.autores || '', a.anio ? String(a.anio) : '', a.revista || ''].filter(Boolean);
            const cites = Number(a.citas) || 0;
            return pulsoAmpCard(a.url,
                '<span class="pulso-amp-card-text">'
                + '<span class="pulso-amp-card-title">' + escapeHtmlText(title) + '</span>'
                + (line.length ? '<span class="pulso-amp-card-meta">' + escapeHtmlText(line.join(' · ')) + '</span>' : '')
                + (cites > 0 ? '<span class="pulso-amp-card-meta">' + escapeHtmlText(cites.toLocaleString('es-ES') + (cites === 1 ? ' cita' : ' citas')) + '</span>' : '')
                + (a.acceso_abierto ? '<span class="pulso-amp-tag">Acceso abierto</span>' : '')
                + (otherLang ? '<span class="pulso-amp-tag pulso-amp-tag-lang">' + escapeHtmlText(pulsoAmpLangLabel(a.idioma)) + '</span>' : '')
                + '</span>');
        }


        function pulsoAmpActions(primaryLabel) {
            return '<div class="pulso-amp-actions">'
                + '<button type="button" class="pulso-create-submit" data-pulso-action="openCreatePanel" data-pulso-arg="ampliacion">' + escapeHtmlText(primaryLabel) + '</button>'
                + '<button type="button" class="pulso-create-back-link" data-pulso-action="closeCreatePanel">Volver</button>'
                + '</div>';
        }


        function renderAmpliacionError(message) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            body.innerHTML = '<div class="pulso-amp">'
                + '<div class="pulso-create-notice-inline danger">' + escapeHtmlText(message) + '</div>'
                + pulsoAmpActions('Ampliar otro recurso')
                + '</div>';
        }


        function renderAmpliacion(data) {
            const body = document.getElementById('pulso-create-body');
            if (!body) return;
            const videos = Array.isArray(data.videos) ? data.videos : [];
            const articulos = Array.isArray(data.articulos) ? data.articulos : [];
            const avisos = Array.isArray(data.avisos) ? data.avisos : [];

            let html = '<div class="pulso-amp">';
            if (data.tema) {
                html += '<div class="pulso-amp-head">Para ampliar: <strong>' + escapeHtmlText(data.tema) + '</strong></div>';
            }
            if (videos.length) {
                html += '<div><div class="pulso-amp-section-title">Vídeos</div><div class="pulso-amp-list">'
                    + videos.map(pulsoAmpVideoCard).join('') + '</div></div>';
            }
            if (articulos.length) {
                html += '<div><div class="pulso-amp-section-title">Artículos académicos</div><div class="pulso-amp-list">'
                    + articulos.map(function (a) { return pulsoAmpArticleCard(a, data.idioma_curso); }).join('') + '</div></div>';
            }
            avisos.forEach(function(a) {
                html += '<div class="pulso-create-notice-inline">' + escapeHtmlText(a) + '</div>';
            });
            if (!videos.length && !articulos.length && !avisos.length) {
                html += '<div class="pulso-create-notice-inline">No hemos encontrado contenido para ampliar este recurso.</div>';
            }
            html += '<div class="pulso-amp-footnote">'
                + (cfg.isTeacher
                    ? 'Contenido externo seleccionado automáticamente. Revísalo antes de usarlo en clase.'
                    : 'Contenido externo seleccionado automáticamente.')
                + '</div>'
                + pulsoAmpActions('Ampliar otro recurso')
                + '</div>';
            body.innerHTML = html;
        }


        function submitAmpliacion() {
            const select = document.getElementById('pulso-create-resource');
            const btn = document.getElementById('pulso-create-submit-btn');
            const body = document.getElementById('pulso-create-body');
            if (!select || !body || (btn && btn.disabled)) return;
            if (btn) pulsoSetDisabled(btn, true);

            const cmid = parseInt(select.value, 10);
            const resource = S.pulsoCreateResources.find(function(r) { return r.cmid === cmid; });
            const name = resource ? resource.name : '';
            const token = ++S.pulsoAmpToken;

            body.innerHTML = '<div class="pulso-amp-loading" role="status">'
                + '<span class="pulso-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
                + '<span>Buscando vídeos y artículos sobre ' + escapeHtmlText(name) + '…</span></div>';

            const formData = new FormData();
            formData.append('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
            formData.append('courseid', cfg.courseid);
            formData.append('cmid', String(cmid));

            fetch(cfg.apiAmpliacionUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (token !== S.pulsoAmpToken) return; // el usuario ya salió de esta pantalla
                    if (data && data.success) {
                        renderAmpliacion(data);
                    } else {
                        renderAmpliacionError((data && data.message) || 'No se ha podido ampliar el recurso. Inténtalo de nuevo.');
                    }
                })
                .catch(function() {
                    if (token !== S.pulsoAmpToken) return;
                    renderAmpliacionError('No se ha podido conectar para ampliar el recurso. Inténtalo de nuevo.');
                });
        }
    C.fn.submitAmpliacion = submitAmpliacion;

    return {};
});
