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
 * Prompts preestablecidos del chat (v2.14.0): sugerencias mientras se escribe, panel de opciones y
 * catálogo de la home. Lo que se envía es el identificador del prompt y sus parámetros; el texto que
 * recibe el modelo lo monta el servidor (classes/chat_presets.php).
 *
 * @module     block_pulso/presets
 * @copyright  2026 Awakelab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['block_pulso/common'], function(C) {
    'use strict';

    var cfg = C.cfg;
    var esc = C.escapeHtmlText;
    var escAttr = C.pulsoEscapeAttr;

    // Catálogo y opciones del curso (api_presets.php). null = aún no pedido o falló: sin él, el chat
    // es de texto libre y no se sugiere nada.
    var data = null;
    var loading = null;
    var index = [];      // [{preset, labelWords, keyWords}]
    var shown = [];      // prompts de la lista abierta
    var active = -1;     // opción resaltada con el teclado (-1 = ninguna: Enter envía texto libre)
    var current = null;  // prompt del panel abierto

    var MAX_SUGGESTIONS = 5;

    // Palabras que no dicen qué se pide: no cuentan ni a favor ni en contra.
    var STOP = {};
    ('a al algo con dame de del dime el en es esta este favor haz hazme hacer hay la las lo los me mi mis necesito o '
        + 'para por podrias puedes pulse que quiero quisiera se sobre su sus tu un una unas unos y').split(' ')
        .forEach(function(w) { STOP[w] = true; });

    // Etiqueta de cada parámetro que es un id del curso, lista de la que sale y aviso si está vacía.
    var COURSE_PARAMS = {
        unit: {label: 'Unidad', list: 'units', empty: 'No he encontrado unidades con material en este curso.'},
        resource: {label: 'Recurso', list: 'resources', empty: 'Todavía no hay recursos con texto legible en este curso.'},
        quiz: {label: 'Cuestionario', list: 'quizzes', empty: 'Este curso no tiene cuestionarios visibles.'},
        activity: {label: 'Actividad', list: 'activities', empty: 'Este curso no tiene tareas ni cuestionarios visibles.'}
    };


    // Minúsculas, sin tildes ni signos: lo mismo para lo tecleado y para el catálogo.
    function norm(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
    }


    function words(text) {
        return norm(text).split(' ').filter(function(w) { return w !== '' && !STOP[w]; });
    }


    function el(id) {
        return document.getElementById(id);
    }


    function buildIndex() {
        index = (data.presets || []).map(function(p) {
            return {preset: p, labelWords: words(p.label), keyWords: words(p.keywords)};
        });
    }


    // ¿Casa una palabra tecleada con una del catálogo? Igual, prefijo (lo que se está escribiendo:
    // «resu» → «resumen») o al revés con raíz larga («explicamelo» → «explica»).
    function wordMatches(typed, word, isLast) {
        if (typed === word) return true;
        var min = isLast ? 2 : 3;
        if (typed.length >= min && word.indexOf(typed) === 0) return true;
        return word.length >= 5 && typed.indexOf(word) === 0;
    }


    // Prompts que casan con lo tecleado, del mejor al peor. TODAS las palabras con significado tienen
    // que casar: una frase larga y libre no sugiere nada, que es lo que se quiere.
    function match(text) {
        if (!data) return [];
        var typed = words(text).filter(function(w) { return !/^\d+$/.test(w); });
        if (!typed.length || typed.length > 6) return [];
        var open = !/\s$/.test(text); // la última palabra sigue escribiéndose

        var scored = [];
        index.forEach(function(entry, order) {
            var score = 0;
            var ok = typed.every(function(w, i) {
                var isLast = open && i === typed.length - 1;
                if (entry.labelWords.some(function(x) { return wordMatches(w, x, isLast); })) {
                    score += 2;
                    return true;
                }
                if (entry.keyWords.some(function(x) { return wordMatches(w, x, isLast); })) {
                    score += 1;
                    return true;
                }
                return false;
            });
            if (ok) scored.push({preset: entry.preset, score: score, order: order});
        });
        scored.sort(function(a, b) { return b.score - a.score || a.order - b.order; });
        return scored.slice(0, MAX_SUGGESTIONS).map(function(s) { return s.preset; });
    }


    function presetById(id) {
        var found = null;
        ((data && data.presets) || []).forEach(function(p) {
            if (p.id === id) found = p;
        });
        return found;
    }


    // Pide el catálogo una sola vez. Falla en silencio: sin catálogo no hay sugerencias y ya está.
    function load() {
        if (data) return Promise.resolve(data);
        if (loading) return loading;
        if (!cfg.apiPresetsUrl || !window.fetch) return Promise.resolve(null);

        var form = new FormData();
        form.append('courseid', cfg.courseid || 0);
        form.append('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
        loading = fetch(cfg.apiPresetsUrl, {method: 'POST', body: form, credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (!json || !json.success || !Array.isArray(json.presets)) {
                    throw new Error('respuesta no valida');
                }
                data = json;
                buildIndex();
                return data;
            })
            .catch(function(e) {
                C.pulsoLog('Pulso presets:', e);
                loading = null;
                return null;
            });
        return loading;
    }


    // ========== SUGERENCIAS MIENTRAS SE ESCRIBE ==========

    function setActive(i) {
        var list = el('pulso-preset-suggest');
        var input = el('pulso-input');
        active = i;
        if (!list) return;
        Array.prototype.forEach.call(list.children, function(li, n) {
            li.setAttribute('aria-selected', n === i ? 'true' : 'false');
            li.classList.toggle('is-active', n === i);
        });
        if (input) {
            if (i >= 0) input.setAttribute('aria-activedescendant', 'pulso-preset-opt-' + i);
            else input.removeAttribute('aria-activedescendant');
        }
    }


    function hideSuggestions() {
        var list = el('pulso-preset-suggest');
        var input = el('pulso-input');
        shown = [];
        active = -1;
        if (list) {
            list.hidden = true;
            list.innerHTML = '';
        }
        if (input) {
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }
    }


    function renderSuggestions() {
        var list = el('pulso-preset-suggest');
        var input = el('pulso-input');
        if (!list || !input || current) return;

        var found = match(input.value);
        if (!found.length) {
            hideSuggestions();
            return;
        }
        var wasEmpty = shown.length === 0;
        shown = found;
        list.innerHTML = found.map(function(p, i) {
            return '<li role="option" id="pulso-preset-opt-' + i + '" class="pulso-preset-option" aria-selected="false" '
                + 'data-pulso-action="pulsoPresetPick" data-pulso-arg="' + escAttr(p.id) + '">'
                + '<span class="pulso-preset-option-label">' + esc(p.label) + '</span>'
                + (p.params && p.params.length ? '<span class="pulso-preset-option-more">Opciones</span>' : '')
                + '</li>';
        }).join('');
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(-1);
        if (wasEmpty) {
            C.pulsoAnnounce(found.length === 1
                ? 'Hay un prompt sugerido. Flecha abajo para elegirlo.'
                : 'Hay ' + found.length + ' prompts sugeridos. Flecha abajo para elegir.');
        }
    }


    function onInput() {
        if (!data) {
            load().then(function(d) {
                if (d) renderSuggestions();
            });
            return;
        }
        renderSuggestions();
    }


    // Teclado del cuadro de texto con la lista abierta. Se registra ANTES que el Enter de chat.js:
    // con una opción resaltada, Enter la elige y no envía lo tecleado.
    function onKeydown(e) {
        if (!shown.length || e.isComposing || e.keyCode === 229) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(active + 1 >= shown.length ? 0 : active + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(active <= 0 ? shown.length - 1 : active - 1);
        } else if (e.key === 'Enter' && !e.shiftKey && active >= 0) {
            e.preventDefault();
            e.stopImmediatePropagation();
            pick(shown[active].id);
        } else if (e.key === 'Escape') {
            // Escape cierra la lista, no el chat (el del contenedor mira defaultPrevented).
            e.preventDefault();
            hideSuggestions();
        }
    }


    // ========== PANEL DE OPCIONES ==========

    // Si lo tecleado decía «unidad 2», la unidad 2 sale ya elegida.
    function typedSectionId() {
        var input = el('pulso-input');
        var m = input ? norm(input.value).match(/\b(\d{1,2})\b/) : null;
        if (!m) return null;
        var n = parseInt(m[1], 10);
        var hit = null;
        (data.options.units || []).forEach(function(s) {
            if (parseInt(s.num, 10) === n) hit = s.id;
        });
        return hit;
    }


    function optionsHtml(items, selected) {
        var html = '';
        var group = null;
        items.forEach(function(item) {
            var g = item.group || '';
            if (g !== group) {
                if (group !== null && group !== '') html += '</optgroup>';
                if (g !== '') html += '<optgroup label="' + escAttr(g) + '">';
                group = g;
            }
            html += '<option value="' + escAttr(String(item.id)) + '"'
                + (String(item.id) === String(selected) ? ' selected' : '') + '>' + esc(item.label) + '</option>';
        });
        if (group !== null && group !== '') html += '</optgroup>';
        return html;
    }


    function fieldHtml(name) {
        var id = 'pulso-preset-f-' + name;
        if (COURSE_PARAMS[name]) {
            var def = COURSE_PARAMS[name];
            var items = (data.options && data.options[def.list]) || [];
            if (!items.length) {
                return '<p class="pulso-preset-empty" data-pulso-preset-empty="1">' + esc(def.empty) + '</p>';
            }
            return '<div class="pulso-create-field"><label for="' + id + '">' + esc(def.label) + '</label>'
                + '<select id="' + id + '" data-pulso-preset-param="' + name + '">'
                + optionsHtml(items, name === 'unit' ? typedSectionId() : null) + '</select></div>';
        }
        if (name === 'topic') {
            return '<div class="pulso-create-field"><label for="' + id + '">Concepto</label>'
                + '<input type="text" id="' + id + '" data-pulso-preset-param="topic" maxlength="'
                + (parseInt(data.topicmax, 10) || 120) + '" autocomplete="off" placeholder="Escribe el concepto que quieres entender"></div>';
        }
        var choice = (data.choices || {})[name];
        if (!choice) return '';
        return '<div class="pulso-create-field"><label for="' + id + '">' + esc(choice.label) + '</label>'
            + '<select id="' + id + '" data-pulso-preset-param="' + name + '">'
            + choice.options.map(function(o) {
                return '<option value="' + escAttr(o.value) + '"' + (o.value === choice.default ? ' selected' : '') + '>'
                    + esc(o.label) + '</option>';
            }).join('') + '</select></div>';
    }


    function openPanel(preset) {
        var panel = el('pulso-preset-panel');
        if (!panel) return;
        hideSuggestions();
        current = preset;

        var fields = preset.params.map(fieldHtml).join('');
        panel.innerHTML = '<div class="pulso-preset-head">'
            + '<h4 class="pulso-preset-title" id="pulso-preset-title" tabindex="-1">' + esc(preset.label) + '</h4>'
            + '<button type="button" class="pulso-preset-close" data-pulso-action="pulsoPresetCancel" aria-label="Cerrar las opciones del prompt">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>'
            + '</button></div>'
            + '<div class="pulso-preset-fields">' + fields + '</div>'
            + '<p class="pulso-preset-error" id="pulso-preset-error" role="alert" hidden></p>'
            + '<div class="pulso-preset-actions">'
            + '<button type="button" class="pulso-preset-send" id="pulso-preset-send" data-pulso-action="pulsoPresetSend">Enviar</button>'
            + '</div>';
        panel.hidden = false;

        // Lo tecleado ya hizo su trabajo (sugerir y, si traía número, elegir la unidad): el cuadro
        // se vacía para que no parezca que también se va a enviar. El evento pone al día el
        // contador y el botón de enviar de chat.js.
        var input = el('pulso-input');
        if (input && input.value !== '') {
            input.value = '';
            input.dispatchEvent(new Event('input', {bubbles: true}));
        }

        var blocked = !!panel.querySelector('[data-pulso-preset-empty]');
        C.pulsoSetDisabled(el('pulso-preset-send'), blocked);

        // El foco va al primer campo; si no hay ninguno que rellenar, al título.
        var first = panel.querySelector('[data-pulso-preset-param]');
        (first || el('pulso-preset-title')).focus();
    }


    function closePanel(focusInput) {
        var panel = el('pulso-preset-panel');
        current = null;
        if (panel) {
            panel.hidden = true;
            panel.innerHTML = '';
        }
        if (focusInput) {
            var input = el('pulso-input');
            if (input) input.focus();
        }
    }


    function panelError(text) {
        var box = el('pulso-preset-error');
        if (!box) return;
        box.textContent = text;
        box.hidden = text === '';
    }


    // Envía el prompt del panel: el texto que se ve en la conversación es la etiqueta con lo elegido;
    // lo que decide la respuesta son `id` y `params`, que el servidor vuelve a validar.
    function sendPanel() {
        var panel = el('pulso-preset-panel');
        if (!panel || !current) return;
        var params = {};
        var parts = [];
        var missing = null;

        Array.prototype.forEach.call(panel.querySelectorAll('[data-pulso-preset-param]'), function(field) {
            var name = field.getAttribute('data-pulso-preset-param');
            var value = String(field.value || '').trim();
            if (value === '' && !missing) missing = field;
            params[name] = value;
            if (field.tagName === 'SELECT') {
                var opt = field.options[field.selectedIndex];
                if (opt) parts.push(opt.textContent);
            } else {
                parts.push('«' + value + '»');
            }
        });

        if (missing) {
            panelError('Falta rellenar un campo.');
            missing.focus();
            return;
        }

        var text = current.label.replace(/[¿?]/g, '') + (parts.length ? ': ' + parts.join(' · ') : '');
        if (C.fn.pulsoSendPreset(text, {id: current.id, params: params})) {
            closePanel(true);
        }
    }


    // Elegir un prompt (de la lista, de la home o con el teclado). Sin parámetros se envía ya.
    function pick(id) {
        load().then(function(d) {
            var preset = d ? presetById(id) : null;
            if (!preset) {
                // Sin catálogo (fallo de red): el chat sigue valiendo con texto libre.
                C.pulsoAnnounce('No se han podido cargar las opciones. Escribe tu pregunta.');
                var input = el('pulso-input');
                if (input) input.focus();
                return;
            }
            if (!preset.params || !preset.params.length) {
                hideSuggestions();
                C.fn.pulsoSendPreset(preset.label, {id: preset.id, params: {}});
                return;
            }
            // Las opciones se abren en Preguntar, que es donde vive el cuadro de texto.
            C.fn.pulsoSelectTab('ask', {focus: false});
            openPanel(preset);
        });
    }


    // ========== UNIDADES SIN RASTREAR (v2.17.0) ==========
    // Solo profesorado y solo con Épica (cfg.apiUnidadesUrl vacío si no). Se pide al ABRIR el chat por primera
    // vez, no al cargar la página: es lo único de la home que pregunta a Épica, y no hace falta en cada vista.

    var untrackedLoaded = false;

    var MOD_WORDS = {
        scorm: 'SCORM', url: 'enlace', resource: 'archivo', page: 'página', book: 'libro', lesson: 'lección',
        h5pactivity: 'H5P', folder: 'carpeta', imscp: 'paquete IMS'
    };

    function untrackedStoreKey() {
        return 'pulso:untracked:' + (cfg.courseid || 0);
    }

    // Lo que se cerró con la ✕ se queda cerrado mientras la lista de unidades sea la misma.
    function untrackedDismissed(signature) {
        try {
            return window.localStorage && window.localStorage.getItem(untrackedStoreKey()) === signature;
        } catch (e) {
            return false;
        }
    }

    function untrackedModules(unit) {
        var words = [];
        (unit.modules || []).forEach(function(m) {
            var w = MOD_WORDS[m.modname];
            if (w && words.indexOf(w) === -1) words.push(w);
        });
        return words.length ? 'Solo tiene ' + words.join(', ') : '';
    }

    function renderUntracked(units) {
        var box = el('pulso-untracked');
        if (!box) return;
        var signature = units.map(function(u) { return u.key; }).join(',');
        if (!units.length || untrackedDismissed(signature)) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }
        var title = units.length === 1
            ? 'Hay una unidad que Pulse no puede leer'
            : 'Hay ' + units.length + ' unidades que Pulse no puede leer';
        var html = '<div class="pulso-untracked-head">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
            + '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
            + '<h4 class="pulso-untracked-title">' + esc(title) + '</h4>'
            + '<button type="button" class="pulso-untracked-close" data-pulso-action="pulsoUntrackedDismiss" aria-label="Ocultar este aviso">'
            + '<span aria-hidden="true">×</span></button></div>'
            + '<p class="pulso-untracked-text">Su contenido está en paquetes, vídeos o enlaces de los que no se puede sacar texto, '
            + 'así que Pulse no puede resumirlas ni contestar con su material. Sube a Épica un documento o un vídeo con ese '
            + 'contenido y Pulse lo usará. Este aviso solo lo ve el profesorado.</p>'
            + '<ul class="pulso-untracked-list">';
        units.forEach(function(u) {
            var sub = untrackedModules(u);
            html += '<li class="pulso-untracked-item"><span class="pulso-untracked-unit">'
                + '<span class="pulso-untracked-name">' + esc(u.title) + '</span>'
                + (sub ? '<span class="pulso-untracked-sub">' + esc(sub) + '</span>' : '')
                + '</span><button type="button" class="pulso-untracked-btn" data-pulso-action="pulsoAbrirFuentesUnidad"'
                + ' data-pulso-arg="' + escAttr(u.key) + '">Subir material<span class="pulso-sr-only"> para ' + esc(u.title)
                + ' (se abre en una pestaña nueva)</span></button></li>';
        });
        box.innerHTML = html + '</ul>';
        box.setAttribute('data-signature', signature);
        box.hidden = false;
    }

    function loadUntracked() {
        if (untrackedLoaded || !cfg.apiUnidadesUrl || !window.fetch || !el('pulso-untracked')) return;
        untrackedLoaded = true;
        var form = new FormData();
        form.append('courseid', cfg.courseid || 0);
        form.append('sesskey', cfg.sesskey || (window.M && M.cfg && M.cfg.sesskey) || '');
        fetch(cfg.apiUnidadesUrl, {method: 'POST', body: form, credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (!json || !json.success || !Array.isArray(json.units)) throw new Error('respuesta no valida');
                renderUntracked(json.units.filter(function(u) {
                    return u && typeof u.key === 'string' && /^u[0-9]{1,12}$/.test(u.key) && typeof u.title === 'string';
                }));
            })
            .catch(function(e) {
                // En silencio: el aviso es una ayuda, no una página que tenga que salir.
                C.pulsoLog('Pulso unidades sin rastrear:', e);
            });
    }

    function dismissUntracked() {
        var box = el('pulso-untracked');
        if (!box) return;
        try {
            if (window.localStorage) window.localStorage.setItem(untrackedStoreKey(), box.getAttribute('data-signature') || '');
        } catch (e) {
            // Sin almacenamiento se oculta solo en esta vista.
        }
        box.hidden = true;
    }


    // Arranque: lo llama chat.js ANTES de registrar su propio teclado del cuadro de texto.
    function boot() {
        var input = el('pulso-input');
        var panel = el('pulso-preset-panel');
        if (!input || !el('pulso-preset-suggest')) return;

        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onKeydown);
        input.addEventListener('focus', function() { load(); });
        // Al salir del cuadro la lista se cierra, pero después del clic sobre una opción.
        input.addEventListener('blur', function() { setTimeout(hideSuggestions, 180); });
        // El clic sobre una opción no le quita el foco al cuadro de texto.
        el('pulso-preset-suggest').addEventListener('mousedown', function(e) { e.preventDefault(); });

        if (panel) {
            panel.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closePanel(true);
                } else if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') {
                    e.preventDefault();
                    sendPanel();
                }
            });
        }
    }

    C.fn.pulsoPresetsBoot = boot;
    C.fn.pulsoUntrackedLoad = loadUntracked;
    C.fn.pulsoUntrackedDismiss = dismissUntracked;
    C.fn.pulsoPresetPick = pick;
    C.fn.pulsoPresetSend = sendPanel;
    C.fn.pulsoPresetCancel = function() { closePanel(true); };
    C.fn.pulsoPresetHide = hideSuggestions;
    C.fn.pulsoPresetReset = function() {
        hideSuggestions();
        closePanel(false);
    };

    // Para pruebas: el emparejamiento es una función pura sobre el catálogo.
    return {
        norm: norm,
        match: match,
        setData: function(d) {
            data = d;
            buildIndex();
        }
    };
});
