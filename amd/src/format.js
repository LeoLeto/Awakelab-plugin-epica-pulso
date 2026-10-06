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
 * Formato de las respuestas del chat: texto enriquecido, tablas (ordenar, filtrar, exportar) y listas.
 *
 * @module     block_pulso/format
 * @copyright  2026 Awakelab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['block_pulso/common'], function(C) {
    'use strict';

    var S = C.S;
    var pulsoLog = C.pulsoLog;
    var escapeHtml = C.escapeHtml;



        function formatAIResponse(answer, showAnalysisSections = true) {
            try {
                let jsonStr = answer.trim();
                
                // Si está envuelto en markdown code block (```json ... ```)
                if (/^\s*```/.test(jsonStr)) {
                    // Remover markdown code block — handle any whitespace/newlines around fences
                    jsonStr = jsonStr.replace(/^\s*```[a-z]*\s*/i, '').replace(/\s*```\s*$/i, '').trim();
                    pulsoLog('📌 Limpiado markdown code block');
                }
                
                // Si está envuelto en comillas extra, removerlas
                if ((jsonStr.startsWith('"') && jsonStr.endsWith('"')) ||
                    (jsonStr.startsWith("'") && jsonStr.endsWith("'"))) {
                    jsonStr = jsonStr.slice(1, -1);
                    pulsoLog('📌 Limpiado comillas extra');
                }

                // Si viene texto extra antes/despues del JSON, extraer solo el bloque {...}
                const firstBrace = jsonStr.indexOf('{');
                const lastBrace = jsonStr.lastIndexOf('}');
                if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
                    jsonStr = jsonStr.slice(firstBrace, lastBrace + 1).trim();
                }
                
                // Intentar parsear como JSON
                const data = JSON.parse(jsonStr);
                pulsoLog('✅ JSON parseado:', data);
                
                // ========== MANEJO DE ERRORES ==========
                if (data.status === 'insufficient_data' || data.status === 'error') {
                    console.warn('⚠️ La IA retornó status:', data.status);
                    // Intentar mostrar mensaje útil en vez de solo error
                    if (data.message) {
                        return `<div class="pulso-empty">${escapeHtml(data.message)}</div>`;
                    }
                    return `<div class="pulso-empty">No se encontraron datos de analítica para este curso. Asegúrate de que el seguimiento de completitud y las calificaciones estén configurados en Moodle.</div>`;
                }
                
                if (!data || !data.type) {
                    // No tiene el campo type requerido
                    console.warn('⚠️ JSON sin campo type:', data);
                    // Si es otro formato de respuesta, mostrar como texto
                    return formatRichTextResponse(answer);
                }
                
                let html = '<div class="pulso-rich-answer">';

                // Simplificación: cuando la respuesta tiene cuerpo real
                // (content/data), el título y los resúmenes boilerplate solo
                // duplican información antes de la respuesta — no renderizarlos.
                const hasBody = (function() {
                    if (Array.isArray(data.data) && data.data.length > 0) return true;
                    if (data.content) {
                        if (Array.isArray(data.content)) return data.content.length > 0;
                        if (typeof data.content === 'object') return Object.keys(data.content).length > 0;
                        return String(data.content).trim() !== '';
                    }
                    return false;
                })();
                const boilerplateSummaryRe = /^(he\s+(localizado|encontrado|recuperado|obtenido|listado)\b|contenido de la etiqueta\b|resumen del (curso|recurso|pdf|archivo)\b)/i;

                // Título: solo cuando no hay cuerpo (es lo único que hay que mostrar).
                if (data.title && !hasBody) {
                    html += `<div class="pulso-rich-title">${escapeHtml(String(data.title))}</div>`;
                }

                // Resumen: mantenerlo como dato clave en tablas/listas (salvo
                // boilerplate); en respuestas narrativas el contenido ya lo repite.
                const summaryText = data.summary ? String(data.summary).trim() : '';
                const showSummary = summaryText !== '' && (
                    !hasBody
                    || ((data.type === 'table' || data.type === 'list') && !boilerplateSummaryRe.test(summaryText))
                );
                if (showSummary) {
                    html += `<div class="pulso-rich-summary">${formatRichTextResponse(summaryText, true)}</div>`;
                }
                
                // Datos según tipo
                if (data.type === 'table' && data.data && Array.isArray(data.data)) {
                    html += formatAsTable(data.data, data.title);
                } else if (data.type === 'list' && data.data && Array.isArray(data.data)) {
                    html += formatAsList(data.data);
                } else if (data.type === 'text') {
                    if (data.content) {
                        if (Array.isArray(data.content)) {
                            // Extraer texto plano de arrays de objetos tipo {paragraph: "..."}
                            const textParts = data.content.map(item => {
                                if (typeof item === 'string') return item;
                                if (typeof item === 'object' && item !== null) {
                                    return Object.values(item).map(v => String(v)).join(' ');
                                }
                                return String(item);
                            });
                            html += formatRichTextResponse(textParts.join('\n\n'));
                        } else if (typeof data.content === 'object') {
                            const texts = Object.values(data.content).map(v => String(v));
                            html += formatRichTextResponse(texts.join('\n\n'));
                        } else {
                            html += formatRichTextResponse(String(data.content));
                        }
                    }
                    // Fallback: si type es text pero también hay data como array,
                    // extract text from content-wrapper objects and render via formatRichTextResponse.
                    if (data.data && Array.isArray(data.data) && data.data.length > 0) {
                        const contentOnlyKeys = ['paragraph', 'párrafo', 'parrafo', 'text', 'texto', 'content', 'contenido', 'summary', 'resumen', 'conclusion', 'conclusión', 'introduction', 'introducción', 'analysis', 'análisis', 'observation', 'observación', 'comment', 'comentario', 'response', 'respuesta', 'answer', 'description', 'descripción', 'descripcion'];
                        const textItems = [];
                        let allText = true;
                        for (const item of data.data) {
                            if (typeof item === 'string') {
                                textItems.push(item);
                            } else if (typeof item === 'object' && item !== null) {
                                const keys = Object.keys(item);
                                if (keys.every(k => contentOnlyKeys.includes(k.toLowerCase()))) {
                                    textItems.push(Object.values(item).map(v => String(v)).join(' '));
                                } else {
                                    allText = false;
                                    break;
                                }
                            } else {
                                allText = false;
                                break;
                            }
                        }
                        if (allText && textItems.length > 0) {
                            html += formatRichTextResponse(textItems.join('\n\n'));
                        } else {
                            html += formatAsList(data.data);
                        }
                    }
                } else if (data.data && Array.isArray(data.data)) {
                    // Tipo desconocido pero hay data como array
                    html += formatAsList(data.data);
                }
                
                // Insights
                if (showAnalysisSections && data.insights && Array.isArray(data.insights) && data.insights.length > 0) {
                    html += '<div class="pulso-insights">';
                    html += '<div class="pulso-section-label">Insights</div>';
                    html += '<ul>';
                    data.insights.forEach(insight => {
                        html += `<li>${escapeHtml(String(insight))}</li>`;
                    });
                    html += '</ul></div>';
                }

                // Recommendations
                if (showAnalysisSections && data.recommendations && Array.isArray(data.recommendations) && data.recommendations.length > 0) {
                    html += '<div class="pulso-recos">';
                    html += '<div class="pulso-section-label">Recomendaciones</div>';
                    html += '<ul>';
                    data.recommendations.forEach(rec => {
                        html += `<li>${escapeHtml(String(rec))}</li>`;
                    });
                    html += '</ul></div>';
                }

                // Ofrecimiento del siguiente paso (campo 'next_step', v1.15.2). Se pinta
                // como bloque APARTE y SIEMPRE que venga, sin depender de data.type ni de
                // showAnalysisSections: en v1.15.0 la regla pedía la frase dentro de
                // 'content', que solo se pinta si type === 'text', así que en las
                // respuestas de analítica (type table/list) era invisible por diseño; y
                // 'recommendations' se oculta entero cuando la pregunta no parece de
                // analítica. Con campo propio no hay layout en el que se pierda.
                // Se escapa aquí y no pasa por formatRichTextResponse() (que escapa el
                // bloque entero una sola vez), igual que el enlace de abajo.
                const nextStep = (data.next_step !== undefined && data.next_step !== null)
                    ? String(data.next_step).trim()
                    : '';
                if (nextStep !== '') {
                    html += `<div class="pulso-next-step">${escapeHtml(nextStep)}</div>`;
                }

                // Enlace directo a la actividad/recurso. La URL la construye SIEMPRE el
                // servidor con moodle_url y solo llega si el usuario puede verla
                // (uservisible), asi que aqui solo hay que pintarla. Se escapa por si
                // acaso y se trata como campo aparte para no pasar por
                // formatRichTextResponse() (que escapa el bloque entero una sola vez).
                if (data.link && data.link.url) {
                    const linkUrl = String(data.link.url);
                    const linkLabel = data.link.label ? String(data.link.label) : 'Abrir en el curso';
                    html += `<div class="pulso-goto-wrap">`
                        + `<a class="pulso-goto-link" href="${escapeHtml(linkUrl)}">`
                        + `<span class="pulso-goto-icon">↗</span>${escapeHtml(linkLabel)}`
                        + `</a></div>`;
                }

                html += '</div>';
                
                pulsoLog('✅ HTML generado correctamente');
                return html;
            } catch (e) {
                console.warn('⚠️ Error al parsear JSON:', e.message);
                console.warn('⚠️ Texto que intentó parsear:', answer.substring(0, 200));

                // Red de seguridad: si el texto SIGUE pareciendo JSON (empieza por
                // { o [) y no se ha podido parsear ni reparar en servidor, no se
                // pinta en crudo — el usuario vería un objeto JSON en la burbuja.
                // Ocurre raras veces, cuando el modelo emite JSON malformado.
                const looksLikeJson = /^\s*[\{\[]/.test(String(answer || ''));
                if (looksLikeJson) {
                    console.warn('⚠️ JSON no renderizable, se muestra aviso al usuario. Respuesta completa:', answer);
                    return '<div class="pulso-empty">No he podido formatear la respuesta. '
                        + 'Inténtalo de nuevo o reformula la pregunta.</div>';
                }

                // Si no es JSON, retornar como está
                return formatRichTextResponse(answer);
            }
        }


        function formatRichTextResponse(text, inline = false) {
            let raw = String(text || '').replace(/\r\n/g, '\n').trim();
            if (!raw) {
                return '';
            }

            // Strip structural-only prefixes (paragraph:, text:, resumen:, etc.)
            // BEFORE line splitting so the cleaned text gets full formatting.
            const contentPrefixRe = /^(paragraph|párrafo|parrafo|text|texto|content|contenido|summary|resumen|conclusion|conclusión|conclusiones|introduction|introducción|introduccion|analysis|análisis|analisis|observation|observación|observacion|comment|comentario|response|respuesta|answer):\s+/i;
            // Process each line, stripping prefixes per-line.
            raw = raw.split('\n').map(function(line) {
                let l = line;
                while (contentPrefixRe.test(l)) {
                    l = l.replace(contentPrefixRe, '');
                }
                return l;
            }).join('\n');

            const isResourceDetailLayout = /(^|\n)\s*recurso\s*:/i.test(raw)
                && /(^|\n)\s*archivo\s*:/i.test(raw)
                && /(^|\n)\s*tipo\s*:/i.test(raw);

            // Forzar salto visual antes de pasos numerados pegados en una sola línea.
            raw = raw.replace(/\s(?=(\d+)\.\s+\*\*)/g, '\n');
            raw = raw.replace(/\s(?=(\d+)\.\s+[A-ZÁÉÍÓÚÑ¿])/g, '\n');

            const formulas = [];
            raw = raw.replace(/\\\[([\s\S]*?)\\\]/g, function(_, expr) {
                const id = formulas.length;
                formulas.push(prettifyFormula(expr));
                return '@@PULSO_FORMULA_' + id + '@@';
            });

            raw = escapeHtml(raw);
            raw = raw.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            raw = raw.replace(/\n{3,}/g, '\n\n');

            // Solo convertir a pasos cuando el texto parezca realmente procedural.
            if (!isResourceDetailLayout && !/\n\s*\d+\.\s+/m.test(raw) && shouldFormatAsSteps(raw)) {
                raw = toNumberedSteps(raw);
            }

            const lines = raw.split('\n');
            const totalNumberedSteps = lines.filter(function(line) {
                return /^\s*\d+\.\s+/.test(line);
            }).length;
            const chunks = [];
            let inOl = false;
            let inUl = false;
            let bulletContainerType = '';
            let stepCounter = 0;
            let lastStepNumber = 0;
            let inMetaGroup = false;

            function closeLists() {
                if (inMetaGroup) {
                    chunks.push('</div>');
                    inMetaGroup = false;
                }
                if (inOl) {
                    chunks.push('</ol>');
                    inOl = false;
                }
                if (inUl) {
                    chunks.push(bulletContainerType === 'activity' ? '</div>' : '</ul>');
                    inUl = false;
                    bulletContainerType = '';
                }
            }

            lines.forEach(function(line) {
                const trimmed = line.trim();

                if (!trimmed) {
                    closeLists();
                    return;
                }

                const olMatch = trimmed.match(/^(\d+)\.\s+(.+)$/);
                if (olMatch) {
                    if (isResourceDetailLayout) {
                        closeLists();
                        chunks.push('<p class="pulso-rich-paragraph">' + highlightResultPhrases(trimmed) + '</p>');
                        return;
                    }

                    // If the numbered item has a bold title (e.g. "**Gamificación**: Introduce..."),
                    // render as an advice card instead of a procedural step.
                    const bodyText = olMatch[2];
                    const adviceMatch = bodyText.match(/^<strong>([^<]+)<\/strong>\s*:\s*(.+)$/);
                    if (adviceMatch) {
                        closeLists();
                        if (!inMetaGroup) {
                            chunks.push('<div class="pulso-meta-card">');
                            inMetaGroup = true;
                        }
                        chunks.push('<div class="pulso-card-item"><div class="pulso-card-item-title"><span>💡</span> ' + adviceMatch[1] + '</div><div class="pulso-card-item-body">' + highlightResultPhrases(adviceMatch[2]) + '</div></div>');
                        return;
                    }

                    if (inUl) {
                        chunks.push('</ul>');
                        inUl = false;
                    }
                    if (!inOl) {
                        chunks.push('<ol class="pulso-rich-steps">');
                        inOl = true;
                    }

                    const parsedStep = parseInt(olMatch[1], 10);
                    if (!Number.isNaN(parsedStep) && parsedStep > 0) {
                        stepCounter = parsedStep;
                    } else {
                        stepCounter = lastStepNumber + 1;
                    }
                    lastStepNumber = stepCounter;

                    chunks.push('<li><span class="pulso-step-badge">Paso ' + stepCounter + '</span><div class="pulso-step-body">' + renderStepBody(olMatch[2], stepCounter, totalNumberedSteps) + '</div></li>');
                    return;
                }

                // Detect bold-title lines like "<strong>Title</strong>: description"
                // that aren't inside a numbered list — render as advice cards.
                const boldTitleMatch = trimmed.match(/^<strong>([^<]+)<\/strong>\s*:\s*(.+)$/);
                if (boldTitleMatch && !inOl) {
                    closeLists();
                    chunks.push('<div class="pulso-card-item"><div class="pulso-card-item-title"><span>💡</span> ' + boldTitleMatch[1] + '</div><div class="pulso-card-item-body">' + highlightResultPhrases(boldTitleMatch[2]) + '</div></div>');
                    return;
                }

                const ulMatch = trimmed.match(/^[-•]\s+(.+)$/);
                if (ulMatch) {
                    if (inOl) {
                        chunks.push('</ol>');
                        inOl = false;
                    }
                    const activityItem = renderActivityItem(ulMatch[1]);
                    if (activityItem) {
                        if (!inUl) {
                            chunks.push('<div class="pulso-activity-list">');
                            inUl = true;
                            bulletContainerType = 'activity';
                        } else if (bulletContainerType !== 'activity') {
                            chunks.push('</ul><div class="pulso-activity-list">');
                            bulletContainerType = 'activity';
                        }
                        chunks.push(activityItem);
                        return;
                    }
                    if (!inUl) {
                        chunks.push('<ul class="pulso-rich-bullets">');
                        inUl = true;
                        bulletContainerType = 'bullet';
                    } else if (bulletContainerType !== 'bullet') {
                        chunks.push('</div><ul class="pulso-rich-bullets">');
                        bulletContainerType = 'bullet';
                    }
                    chunks.push('<li>' + highlightResultPhrases(ulMatch[1]) + '</li>');
                    return;
                }

                closeLists();

                if (/@@PULSO_FORMULA_\d+@@/.test(trimmed)) {
                    chunks.push(trimmed);
                    return;
                }

                if (/^(respuesta final|resultado final|en resumen|conclusi[oó]n)/i.test(trimmed)) {
                    chunks.push('<div class="pulso-result-box">' + highlightResultPhrases(trimmed) + '</div>');
                    return;
                }

                const metaRow = renderMetaRow(trimmed);
                if (metaRow) {
                    if (!inMetaGroup) {
                        chunks.push('<div class="pulso-meta-card">');
                        inMetaGroup = true;
                    }
                    // If this is a body row (pulso-card-item-body), merge it into the
                    // preceding card-item by re-opening the container.
                    if (metaRow.indexOf('pulso-card-item-body') !== -1 && chunks.length > 0) {
                        const lastIdx = chunks.length - 1;
                        if (chunks[lastIdx].indexOf('pulso-card-item"') !== -1 && chunks[lastIdx].endsWith('</div>')) {
                            // Title ends with </div></div> — strip the outer closing </div>
                            // so the body sits inside the card-item, then re-close it.
                            const stripped = chunks[lastIdx].replace(/<\/div>\s*$/, '');
                            chunks[lastIdx] = stripped;
                            chunks.push(metaRow + '</div>');
                            return;
                        }
                    }
                    chunks.push(metaRow);
                    return;
                }

                if (inMetaGroup) {
                    chunks.push('</div>');
                    inMetaGroup = false;
                }

                chunks.push('<p class="pulso-rich-paragraph">' + highlightResultPhrases(trimmed) + '</p>');
            });

            closeLists();

            let html = chunks.join('');
            formulas.forEach(function(formula, idx) {
                const box = '<div class="pulso-formula">' + formula + '</div>';
                html = html.replaceAll('@@PULSO_FORMULA_' + idx + '@@', box);
            });

            if (inline) {
                return html;
            }

            return '<div class="pulso-rich-answer">' + html + '</div>';
        }


        function shouldFormatAsSteps(text) {
            const value = String(text || '').toLowerCase();

            // Nunca formatear como pasos si el texto es corto (menos de 5 líneas con contenido).
            const contentLines = value.split('\n').map(function(l) { return l.trim(); }).filter(Boolean);
            if (contentLines.length < 5) {
                return false;
            }

            // Respuestas con numeración explícita "1. xxx" del modelo → respetar.
            if (/^\s*\d+\.\s+/m.test(value)) {
                // Solo si hay al menos 3 líneas numeradas (no una suelta).
                const numberedCount = contentLines.filter(function(l) { return /^\d+\.\s+/.test(l); }).length;
                if (numberedCount >= 3) {
                    return true;
                }
            }

            // Solo formatear como pasos cuando hay instrucciones procedurales explícitas.
            const proceduralHints = [
                'sigamos estos pasos', 'paso a paso', 'sigue estos pasos',
                'los pasos son', 'pasos a seguir'
            ];

            const hasProceduralHint = proceduralHints.some(function(hint) {
                return value.indexOf(hint) !== -1;
            });

            if (!hasProceduralHint) {
                return false;
            }

            const metaLikeLines = contentLines.filter(function(line) { return /^[^:]{2,40}:\s+.+$/.test(line); }).length;
            if (metaLikeLines >= Math.max(2, contentLines.length - 1)) {
                return false;
            }

            return true;
        }


        function toNumberedSteps(text) {
            const lines = String(text).split('\n');
            const stepLines = [];

            lines.forEach(function(line) {
                const s = line.trim();
                if (!s) {
                    return;
                }

                // Separar cuando aparezcan conectores típicos de procedimiento.
                const parts = s
                    .replace(/\s+(?=(primero|segundo|tercero|cuarto|quinto|luego|despu[eé]s|a continuaci[oó]n|finalmente|por [uú]ltimo)\b)/gi, '\n')
                    .split('\n')
                    .map(function(p) { return p.trim(); })
                    .filter(Boolean);

                parts.forEach(function(p) {
                    stepLines.push(p);
                });
            });

            // Solo convertir a pasos si realmente hay varias partes.
            if (stepLines.length < 2) {
                return text;
            }

            const numbered = stepLines.map(function(step, idx) {
                return (idx + 1) + '. ' + step;
            });

            return numbered.join('\n');
        }


        function prettifyFormula(expr) {
            let s = String(expr || '').replace(/\s+/g, ' ').trim();
            s = s.replace(/\\text\{([^}]*)\}/g, '$1');
            s = s.replace(/\\times/g, '×');
            s = s.replace(/\\cdot/g, '·');
            s = s.replace(/\\frac\{([^}]*)\}\{([^}]*)\}/g, '($1 / $2)');
            s = s.replace(/\\,/g, ' ');
            s = s.replace(/\\/g, '');
            return escapeHtml(s);
        }


        function renderStepBody(stepText, stepNumber, totalSteps) {
            const highlighted = highlightResultPhrases(stepText);
            const finalBlock = extractFinalAnswerBlock(stepText, stepNumber, totalSteps);
            if (!finalBlock) {
                return highlighted;
            }

            return '<div>' + highlighted + '</div>' + finalBlock;
        }


        function extractFinalAnswerBlock(stepText, stepNumber, totalSteps) {
            const raw = String(stepText || '').trim();
            if (!raw) {
                return '';
            }

            // OJO: stepText llega ya escapado (viene de formatRichTextResponse);
            // no volver a aplicar escapeHtml o se pintan '&quot;' literales.
            if (/^(respuesta final|resultado final|en resumen|conclusi[oó]n)/i.test(raw)) {
                return '<div class="pulso-result-box">' + highlightResultPhrases(raw) + '</div>';
            }

            const finalCue = /(por lo tanto|en conclusi[oó]n|la respuesta final es|el resultado final es|la respuesta es|el resultado es)[:\s]+(.+)/i.exec(raw);
            if (finalCue && finalCue[2]) {
                return '<div class="pulso-result-box"><strong>Respuesta final:</strong> ' + highlightResultPhrases(finalCue[2].trim()) + '</div>';
            }

            const likelyFinal = /(?:\b(?:es|son)\b\s*)(\d+(?:[.,]\d+)?)\s*(metros|metro|m|kil[oó]metros|km|hect[oó]metros|hm|cent[ií]metros|cm|euros|€|%|grados)?\b/i.exec(raw);
            const isLastStep = totalSteps >= 2 && stepNumber === totalSteps;
            if (isLastStep && likelyFinal) {
                return '<div class="pulso-result-box"><strong>Respuesta final:</strong> ' + highlightResultPhrases(raw) + '</div>';
            }

            return '';
        }


        // Trocea un valor que sea una ristra de preguntas en una lista.
        // Devuelve null si no parece un listado de preguntas.
        function pulsoSplitQuestions(value) {
            const s = String(value || '');
            // Preguntas en español delimitadas por ¿ … ?
            const spanish = s.match(/¿[^¿?]*\?/g);
            if (spanish && spanish.length >= 2) {
                return spanish.map(function(q) { return q.trim(); });
            }
            // Fallback (inglés o sin ¿): solo si hay 2+ signos de interrogación.
            if ((s.match(/\?/g) || []).length >= 2) {
                const parts = s.split('?').map(function(p) { return p.trim(); }).filter(Boolean);
                if (parts.length >= 2) {
                    return parts.map(function(p) { return p + '?'; });
                }
            }
            return null;
        }


        function renderMetaRow(line) {
            const raw = String(line);

            // Detect lines with multiple key:value pairs like
            // "advantage: Velocidad  description: Spark puede..."
            // Split them into separate parts and render each.
            const multiMatch = raw.match(/^([^:]{2,40}):\s+(.+?)\s{2,}([^:]{2,40}):\s+(.+)$/);
            if (multiMatch) {
                const part1 = renderMetaRow(multiMatch[1].trim() + ': ' + multiMatch[2].trim());
                const part2 = renderMetaRow(multiMatch[3].trim() + ': ' + multiMatch[4].trim());
                if (part1 || part2) {
                    return (part1 || '') + (part2 || '');
                }
            }

            const match = raw.match(/^([^:]{2,40}):\s+(.+)$/);
            if (!match) {
                return '';
            }

            const key = match[1].trim();
            const value = match[2].trim();
            if (!key || !value) {
                return '';
            }

            const titleKeys = ['strategy', 'estrategia', 'recommendation', 'recomendación', 'recomendacion', 'tip', 'consejo', 'action', 'acción', 'accion', 'step', 'paso', 'objective', 'objetivo', 'benefit', 'beneficio', 'advantage', 'ventaja', 'option', 'opción', 'opcion', 'feature', 'característica', 'caracteristica', 'tool', 'herramienta', 'method', 'método', 'metodo', 'approach', 'enfoque', 'solution', 'solución', 'solucion', 'idea', 'suggestion', 'sugerencia', 'challenge', 'reto', 'desafío', 'desafio', 'risk', 'riesgo', 'category', 'categoría', 'categoria', 'topic', 'tema', 'area', 'área', 'example', 'ejemplo', 'technique', 'técnica', 'tecnica', 'principle', 'principio', 'role', 'rol', 'activity', 'actividad', 'resource', 'platform', 'plataforma', 'channel', 'canal'];
            const bodyKeys = ['description', 'descripcion', 'descripción', 'detail', 'detalle', 'explanation', 'explicación', 'explicacion', 'reason', 'razón', 'razon', 'impact', 'impacto', 'result', 'resultado', 'note', 'nota', 'details', 'detalles', 'how', 'cómo', 'como', 'why', 'por qué', 'implementation', 'implementación', 'implementacion'];

            const keyLower = key.toLowerCase();

            const isTitleKey = titleKeys.includes(keyLower);
            const isBodyKey = bodyKeys.includes(keyLower);

            // OJO: el texto llega ya escapado (formatRichTextResponse hace
            // escapeHtml sobre todo el bloque antes de trocearlo en líneas);
            // volver a escapar aquí pintaba '&quot;' literales en pantalla.
            if (isTitleKey) {
                return '<div class="pulso-card-item"><div class="pulso-card-item-title"><span>🎯</span> ' + value + '</div></div>';
            }

            if (isBodyKey) {
                return '<div class="pulso-card-item-body">' + highlightResultPhrases(value) + '</div>';
            }

            const translations = {
                'file_name': { label: 'Nombre del archivo', icon: '📄' },
                'file_type': { label: 'Tipo de archivo', icon: '📋' },
                'Archivo': { label: 'Archivo', icon: '📄' },
                'Tipo': { label: 'Tipo', icon: '📋' },
                'Descripcion': { label: 'Descripción', icon: '💬' },
                'Recurso': { label: 'Recurso', icon: '📦' },
                'Seccion': { label: 'Sección', icon: '📂' },
                'Resumen': { label: 'Resumen', icon: '📝' },
                'Nombre': { label: 'Nombre', icon: '🏷️' },
                'Nota máxima': { label: 'Nota máxima', icon: '⭐' },
                'Nota maxima': { label: 'Nota máxima', icon: '⭐' },
                'Preguntas': { label: 'Preguntas', icon: '❓' },
                'Intentos permitidos': { label: 'Intentos permitidos', icon: '🔄' },
                'Completado por': { label: 'Completado por', icon: '✅' },
                'Entregas': { label: 'Entregas', icon: '📥' },
                'Calificación media': { label: 'Calificación media', icon: '📊' },
                'Discusiones': { label: 'Discusiones', icon: '💬' },
                'Mensajes': { label: 'Mensajes', icon: '✉️' },
                'Capítulos': { label: 'Capítulos', icon: '📖' },
                'Entradas': { label: 'Entradas', icon: '📝' }
            };

            const info = translations[key] || { label: key, icon: 'ℹ️' };

            // Si el valor es una ristra de preguntas (p. ej. las sugerencias del
            // saludo), pintarlas como lista en vez de una fila de 2 columnas que
            // se estruja y se corta en anchos pequeños.
            const questions = pulsoSplitQuestions(value);
            if (questions && questions.length >= 2) {
                const items = questions.map(function(q) {
                    return '<li>' + highlightResultPhrases(q) + '</li>';
                }).join('');
                return '<div class="pulso-suggest"><div class="pulso-suggest-title"><span>' + info.icon + '</span> ' + info.label + '</div><ul>' + items + '</ul></div>';
            }

            return '<div class="pulso-meta-row"><div class="pulso-meta-key"><span>' + info.icon + '</span> ' + info.label + '</div><div class="pulso-meta-value">' + highlightResultPhrases(value) + '</div></div>';
        }


        function renderActivityItem(line) {
            const match = String(line).match(/^\[([^\]]+)\]\s+(.+)$/);
            if (!match) {
                return '';
            }

            const moduleType = String(match[1]).trim().toLowerCase();
            const name = String(match[2]).trim();
            if (!moduleType || !name) {
                return '';
            }

            return '<div class="pulso-activity-item"><span class="pulso-activity-badge ' + escapeHtml(moduleType) + '">' + escapeHtml(moduleType) + '</span><div class="pulso-activity-name">' + escapeHtml(name) + '</div></div>';
        }


        function highlightResultPhrases(line) {
            return String(line)
                .replace(/(respuesta final|resultado final|por lo tanto|en conclusion|en conclusión|distancia total|hect[oó]metros)/gi, '<strong>$1</strong>');
        }

        
        function formatAsTable(data, caption) {
            if (!data || data.length === 0) {
                return '<p class="pulso-empty">No hay datos disponibles para mostrar.</p>';
            }

            const tableId = 'table-' + Math.random().toString(36).substr(2, 9);
            let html = '';

            const firstRow = data[0];
            if (typeof firstRow !== 'object') {
                return formatAsList(data);
            }

            html += '<div class="pulso-table-card">';

            // Buscador
            html += '<div class="pulso-table-toolbar">';
            html += '<input type="text" class="pulso-table-search" id="filter-' + tableId + '" placeholder="Buscar en esta tabla..." aria-label="Buscar en la tabla" data-pulso-keyup="filterTable" data-pulso-arg="' + tableId + '" />';
            html += '</div>';

            // Contenedor con scroll horizontal responsivo
            html += '<div class="pulso-table-scroll">';
            html += '<table id="' + tableId + '" class="pulso-table">';
            // Título de la respuesta como <caption> (oculto a la vista, leído por el lector de pantalla).
            html += '<caption class="pulso-sr-only">' + escapeHtml(caption ? String(caption) : 'Tabla de resultados') + '</caption>';

            html += '<thead><tr>';
            // Cada cabecera ordenable es un <button> dentro del <th>, con aria-sort="none" desde el principio.
            Object.keys(firstRow).forEach((key, idx) => {
                const label = pulsoFieldLabel(key);
                html += '<th scope="col" aria-sort="none">'
                    + '<button type="button" class="pulso-sort-btn" data-pulso-action="sortTable" data-pulso-arg="' + tableId + '" data-pulso-arg2="' + idx + '" title="Ordenar por ' + escapeHtml(label) + '">'
                    + escapeHtml(label) + '<span class="pulso-sort-mark" aria-hidden="true">⇅</span></button></th>';
            });
            html += '</tr></thead>';

            html += '<tbody>';
            data.forEach((row) => {
                html += '<tr>';
                Object.values(row).forEach((value, colIdx) => {
                    const isLastCol = colIdx === Object.keys(firstRow).length - 1;
                    const valueStr = String(value).toLowerCase();

                    let cellContent = escapeHtml(String(value));
                    let cellClass = '';

                    // Estado semántico (success/danger/warning/neutral) → píldora.
                    if (isLastCol && (valueStr === 'success' || valueStr === 'danger' || valueStr === 'warning' || valueStr === 'neutral')) {
                        cellContent = '<span class="pulso-status-pill ' + valueStr + '">' + cellContent + '</span>';
                        cellClass = ' style="text-align:center"';
                    }

                    html += '<td' + cellClass + '>' + cellContent + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody>';

            html += '</table>';
            html += '</div>';

            // Footer con recuento y exportación
            html += '<div class="pulso-table-footer">';
            html += '<span class="pulso-table-count" id="count-' + tableId + '" role="status" data-total="' + data.length + '">' + data.length + ' registros</span>';
            html += '<div class="pulso-table-actions">';
            html += '<button class="pulso-export-btn" data-pulso-action="exportTableAsExcel" data-pulso-arg="' + tableId + '">Exportar Excel</button>';
            html += '<button class="pulso-export-btn" data-pulso-action="exportTableAsCSV" data-pulso-arg="' + tableId + '">Exportar CSV</button>';
            html += '</div>';
            html += '</div>';

            html += '</div>';

            // Inicializar estado de tabla
            setTimeout(() => {
                const filterInput = document.getElementById('filter-' + tableId);
                if (filterInput) {
                    filterInput.addEventListener('keyup', () => filterTable(tableId));
                }
                if (!S.tableState) S.tableState = {};
                S.tableState[tableId] = { sortCol: -1, sortAsc: true };
            }, 0);

            return html;
        }

        
        function sortTable(tableId, colIdx) {
            colIdx = parseInt(colIdx, 10);
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            if (!S.tableState) S.tableState = {};
            const state = S.tableState[tableId] || {};
            
            // Toggle sort direction
            if (state.sortCol === colIdx) {
                state.sortAsc = !state.sortAsc;
            } else {
                state.sortAsc = true;
            }
            state.sortCol = colIdx;
            S.tableState[tableId] = state;
            
            // Actualizar indicador visual en headers
            const headers = table.querySelectorAll('th');
            headers.forEach((th, idx) => {
                if (idx === colIdx) {
                    th.classList.add('is-sorted');
                    th.setAttribute('aria-sort', state.sortAsc ? 'ascending' : 'descending');
                } else {
                    th.classList.remove('is-sorted');
                    th.setAttribute('aria-sort', 'none');
                }
            });
            
            // Ordenar filas
            rows.sort((a, b) => {
                const aVal = a.cells[colIdx].textContent.trim();
                const bVal = b.cells[colIdx].textContent.trim();
                
                // Intentar comparar como números
                const aNum = parseFloat(aVal);
                const bNum = parseFloat(bVal);
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return state.sortAsc ? aNum - bNum : bNum - aNum;
                }
                
                // Si no, comparar como strings (case-insensitive)
                const aCmp = aVal.toLowerCase();
                const bCmp = bVal.toLowerCase();
                return state.sortAsc ? aCmp.localeCompare(bCmp) : bCmp.localeCompare(aCmp);
            });
            
            // Re-insert rows sorted (el zebra striping lo aporta el CSS via nth-child).
            const tbody = table.querySelector('tbody');
            rows.forEach((row) => {
                tbody.appendChild(row);
            });
        }

        
        function filterTable(tableId) {
            const table = document.getElementById(tableId);
            const filterId = 'filter-' + tableId;
            const filter = document.getElementById(filterId);
            
            if (!table || !filter) return;
            
            const filterText = filter.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                if (row.classList.contains('filter-no-results')) return;
                const text = row.textContent.toLowerCase();
                if (text.includes(filterText)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // El recuento es role="status": al filtrar anuncia «N de M registros».
            const countEl = document.getElementById('count-' + tableId);
            if (countEl) {
                const total = parseInt(countEl.getAttribute('data-total'), 10) || rows.length;
                countEl.textContent = filterText ? (visibleCount + ' de ' + total + ' registros') : (total + ' registros');
            }

            // Mostrar mensaje si no hay resultados
            if (visibleCount === 0) {
                let msg = table.querySelector('.filter-no-results');
                if (!msg) {
                    msg = document.createElement('tr');
                    msg.className = 'filter-no-results pulso-no-results';
                    const cols = table.querySelectorAll('thead th').length;
                    msg.innerHTML = '<td colspan="' + cols + '">No se encontraron resultados</td>';
                    table.querySelector('tbody').appendChild(msg);
                }
            } else {
                const msg = table.querySelector('.filter-no-results');
                if (msg) msg.remove();
            }
        }

        
        // Exportar tabla como Excel (.xlsx) - T2.5.1
        function exportTableAsExcel(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            // Extraer headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                let text = th.textContent.replace(/\s*⇅\s*$/, '').trim();
                headers.push(text);
            });
            
            // Extraer filas visibles
            const rows = [];
            table.querySelectorAll('tbody tr').forEach(tr => {
                if (tr.classList.contains('filter-no-results')) return;
                if (tr.style.display === 'none') return;
                const rowData = [];
                tr.querySelectorAll('td').forEach(td => {
                    rowData.push(td.textContent.trim());
                });
                if (rowData.length > 0) rows.push(rowData);
            });
            
            // Construir XML de hoja de cálculo Excel (SpreadsheetML)
            let xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
            xml += '<?mso-application progid="Excel.Sheet"?>\n';
            xml += '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
            xml += ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n';
            
            // Estilos
            xml += '<Styles>\n';
            xml += '  <Style ss:ID="header">\n';
            xml += '    <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>\n';
            xml += '    <Interior ss:Color="#012142" ss:Pattern="Solid"/>\n';
            xml += '    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>\n';
            xml += '    <Borders>\n';
            xml += '      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>\n';
            xml += '    </Borders>\n';
            xml += '  </Style>\n';
            xml += '  <Style ss:ID="cell">\n';
            xml += '    <Font ss:Size="10"/>\n';
            xml += '    <Alignment ss:Vertical="Center" ss:WrapText="1"/>\n';
            xml += '    <Borders>\n';
            xml += '      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '    </Borders>\n';
            xml += '  </Style>\n';
            xml += '  <Style ss:ID="cellAlt">\n';
            xml += '    <Font ss:Size="10"/>\n';
            xml += '    <Interior ss:Color="#F8F9FA" ss:Pattern="Solid"/>\n';
            xml += '    <Alignment ss:Vertical="Center" ss:WrapText="1"/>\n';
            xml += '    <Borders>\n';
            xml += '      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>\n';
            xml += '    </Borders>\n';
            xml += '  </Style>\n';
            xml += '</Styles>\n';
            
            xml += '<Worksheet ss:Name="Datos">\n';
            xml += '<Table>\n';
            
            // Anchos de columna automáticos
            headers.forEach(() => {
                xml += '<Column ss:AutoFitWidth="1" ss:Width="120"/>\n';
            });
            
            // Fila de headers
            xml += '<Row ss:Height="24">\n';
            headers.forEach(h => {
                xml += '  <Cell ss:StyleID="header"><Data ss:Type="String">' + escapeXml(h) + '</Data></Cell>\n';
            });
            xml += '</Row>\n';
            
            // Filas de datos
            rows.forEach((row, idx) => {
                xml += '<Row>\n';
                let style = idx % 2 === 0 ? 'cell' : 'cellAlt';
                row.forEach(val => {
                    // Detectar si es número
                    let numVal = parseFloat(val);
                    if (!isNaN(numVal) && val === String(numVal)) {
                        xml += '  <Cell ss:StyleID="' + style + '"><Data ss:Type="Number">' + numVal + '</Data></Cell>\n';
                    } else if (val.match(/^\d+([.,]\d+)?%?$/) && !isNaN(parseFloat(val))) {
                        xml += '  <Cell ss:StyleID="' + style + '"><Data ss:Type="Number">' + parseFloat(val) + '</Data></Cell>\n';
                    } else {
                        xml += '  <Cell ss:StyleID="' + style + '"><Data ss:Type="String">' + escapeXml(val) + '</Data></Cell>\n';
                    }
                });
                xml += '</Row>\n';
            });
            
            xml += '</Table>\n';
            xml += '</Worksheet>\n';
            xml += '</Workbook>';
            
            // Descargar archivo
            const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'pulso-datos-' + new Date().toISOString().slice(0,10) + '.xls';
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            pulsoLog('✅ Excel export completado: ' + rows.length + ' filas exportadas');
        }

        
        // Escapar caracteres especiales XML
        function escapeXml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&apos;');
        }

        
        // Exportar tabla como CSV (T2.5.1)
        function exportTableAsCSV(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            // Extraer headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                // Remover el símbolo de sorting (⇅)
                let text = th.textContent.replace(/\s*⇅\s*$/, '').trim();
                headers.push(text);
            });
            
            // Extraer filas visibles
            const rows = [];
            table.querySelectorAll('tbody tr').forEach(tr => {
                // Saltar filas de "no resultados"
                if (tr.classList.contains('filter-no-results')) return;
                // Solo incluir filas visibles
                if (tr.style.display === 'none') return;
                
                const rowData = [];
                tr.querySelectorAll('td').forEach(td => {
                    rowData.push(escapeCsvField(td.textContent.trim()));
                });
                if (rowData.length > 0) {
                    rows.push(rowData);
                }
            });
            
            // Generar CSV
            let csv = headers.map(h => escapeCsvField(h)).join(',') + '\n';
            rows.forEach(row => {
                csv += row.join(',') + '\n';
            });
            
            // Descargar archivo
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'tabla-' + new Date().getTime() + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            pulsoLog('✅ CSV export completado: ' + rows.length + ' filas exportadas');
        }

        
        // Escape comillas y comas en campos CSV
        function escapeCsvField(field) {
            if (!field) return '';
            field = String(field);
            // Si el campo contiene comillas, comas o saltos de línea, envolverlo en comillas
            if (field.includes(',') || field.includes('"') || field.includes('\n')) {
                return '"' + field.replace(/"/g, '""') + '"';
            }
            return field;
        }

        
        
        // Mapeo de claves técnicas del modelo → etiquetas amigables en español.
        // Compartido por las tablas (encabezados) y las tarjetas (clave: valor).
        const PULSO_FIELD_LABELS = {
            'active_users': 'Usuarios activos',
            'status': 'Estado',
            'grade': 'Calificación',
            'grade_10': 'Nota sobre 10',
            'completion': 'Completitud',
            'completado': 'Completado',
            'score': 'Puntuación',
            'pass_rate': 'Tasa de aprobación',
            'percentage': 'Porcentaje',
            'value': 'Valor',
            'count': 'Cantidad',
            'trend': 'Tendencia',
            'rank': 'Puesto',
            'reason': 'Motivo',
            'action': 'Acción recomendada',
            'risk': 'Riesgo',
            'engagement': 'Participación',
            'progress': 'Progreso',
            'avg_grade': 'Nota media',
            'color': 'Estado',
            'duration': 'Duración',
            'drop_rate': 'Tasa de abandono',
            'started': 'Iniciado',
            'completed': 'Completado',
            'period': 'Período',
            'metric': 'Métrica',
            'item': 'Elemento',
            'time': 'Hora',
            'actions': 'Acciones',
            'name': 'Nombre',
            'title': 'Título',
            'label': 'Etiqueta',
            'firstname': 'Nombre',
            'lastname': 'Apellidos',
            'student': 'Estudiante',
            'module': 'Módulo',
            'activity': 'Actividad',
            'last_access': 'Último acceso',
            'enrolled': 'Inscripción',
            'fecha_inscripcion': 'Fecha de inscripción',
            'nota_promedio': 'Nota media',
            'attempts': 'Intentos',
            'email': 'Correo'
        };


        // Etiqueta amigable para una clave: usa el mapa y, si no está, la
        // embellece (guiones bajos → espacios, primera letra en mayúscula).
        function pulsoFieldLabel(key) {
            const k = String(key || '').trim();
            const mapped = PULSO_FIELD_LABELS[k.toLowerCase()];
            if (mapped) {
                return mapped;
            }
            const pretty = k.replace(/_/g, ' ').trim();
            return pretty.charAt(0).toUpperCase() + pretty.slice(1);
        }


        function formatAsList(data) {
            if (!data || data.length === 0) {
                return '<p class="pulso-empty">No hay datos disponibles para mostrar.</p>';
            }

            let html = '<div class="pulso-list-stack">';
            
            data.forEach((item, idx) => {
                if (typeof item === 'object') {
                    // If the object has only content-wrapper keys (paragraph, text, etc.),
                    // extract the text and render it as a clean paragraph.
                    const contentOnlyKeys = ['paragraph', 'párrafo', 'parrafo', 'text', 'texto', 'content', 'contenido', 'summary', 'resumen', 'conclusion', 'conclusión', 'introduction', 'introducción', 'analysis', 'análisis', 'observation', 'observación', 'comment', 'comentario', 'response', 'respuesta', 'answer', 'description', 'descripción', 'descripcion'];
                    const keys = Object.keys(item);
                    const allContentKeys = keys.every(k => contentOnlyKeys.includes(k.toLowerCase()));
                    if (allContentKeys && keys.length > 0) {
                        const textVal = Object.values(item).map(v => String(v)).join(' ');
                        html += formatRichTextResponse(textVal, true);
                        return;
                    }

                    // Objeto con propiedades - crear tarjeta bonita
                    let primaryLabel = item.name || item.title || item.label || item.period || item.student || item.firstname || item.activity || null;
                    
                    // Encontrar propiedades clave para mostrar en la tarjeta
                    const keyProps = {};
                    const displayOrder = ['status', 'grade', 'completion', 'score', 'pass_rate', 'percentage', 'value', 'count', 'trend', 'rank', 'reason', 'action', 'risk', 'engagement', 'progress', 'avg_grade', 'color', 'duration', 'active_users', 'drop_rate', 'started', 'completed'];
                    
                    // Title-like keys that should be used as card heading (not shown as key:value).
                    const titleLikeKeys = ['strategy', 'estrategia', 'recommendation', 'recomendación', 'recomendacion', 'tip', 'consejo', 'step', 'paso', 'objective', 'objetivo', 'benefit', 'beneficio', 'advantage', 'ventaja', 'option', 'opción', 'opcion', 'feature', 'característica', 'caracteristica', 'tool', 'herramienta', 'method', 'método', 'metodo', 'approach', 'enfoque', 'solution', 'solución', 'solucion', 'idea', 'suggestion', 'sugerencia', 'challenge', 'reto', 'desafío', 'desafio', 'category', 'categoría', 'categoria', 'topic', 'tema', 'area', 'área', 'example', 'ejemplo', 'technique', 'técnica', 'tecnica', 'principle', 'principio', 'role', 'rol', 'activity', 'actividad', 'resource', 'platform', 'plataforma', 'channel', 'canal', 'technology', 'tecnología', 'tecnologia', 'database', 'component', 'componente', 'module', 'módulo', 'modulo', 'service', 'servicio', 'framework', 'library', 'librería', 'libreria', 'protocol', 'protocolo', 'pattern', 'patrón', 'patron', 'type', 'tipo'];
                    // Long text keys rendered as body description.
                    const longTextKeys = ['description', 'descripcion', 'descripción', 'detail', 'detalle', 'explanation', 'explicación', 'explicacion', 'summary', 'resumen', 'content', 'contenido', 'text', 'texto', 'note', 'nota', 'comment', 'comentario', 'details', 'detalles', 'intro', 'introduction', 'introducción', 'introduccion', 'observation', 'observación', 'observacion', 'definition', 'definición', 'definicion', 'use', 'uso', 'purpose', 'propósito', 'proposito'];

                    // If no primary label, try to extract from title-like keys.
                    if (!primaryLabel) {
                        for (const k of Object.keys(item)) {
                            if (titleLikeKeys.includes(k.toLowerCase())) {
                                primaryLabel = item[k];
                                break;
                            }
                        }
                    }

                    for (const key of displayOrder) {
                        if (item.hasOwnProperty(key) && key !== 'name' && key !== 'title' && key !== 'label' && key !== 'period') {
                            keyProps[key] = item[key];
                        }
                    }
                    
                    // Clase de acento semántico según status/riesgo.
                    let accentClass = '';

                    const statusStr = String(item.status || '');
                    if (statusStr.indexOf('PASSED') !== -1 || statusStr === 'Excellent' || /excelente/i.test(statusStr) || /✓/.test(statusStr)) {
                        accentClass = ' success';
                    } else if (item.risk === 'High' || statusStr.indexOf('FAILED') !== -1 || /❌|✕/.test(statusStr)) {
                        accentClass = ' danger';
                    } else if (statusStr.indexOf('BORDERLINE') !== -1 || item.risk === 'Medium' || /⚠/.test(statusStr)) {
                        accentClass = ' warn';
                    } else if (statusStr === 'Good' || item.completion === '100%') {
                        accentClass = ' info';
                    }

                    html += '<div class="pulso-list-card' + accentClass + '">';

                    // Título principal
                    if (primaryLabel) {
                        html += '<div class="pulso-list-card-title">' + escapeHtml(String(primaryLabel)) + '</div>';
                    }

                    // Propiedades en dos columnas
                    html += '<div class="pulso-kv-grid">';
                    for (const [key, value] of Object.entries(keyProps)) {
                        const friendlyLabel = pulsoFieldLabel(key);
                        html += '<div><span class="pulso-kv-key">' + escapeHtml(friendlyLabel) + ':</span> <span class="pulso-kv-val">' + escapeHtml(String(value)) + '</span></div>';
                    }
                    html += '</div>';
                    
                    // Agregar cualquier propiedad no estándar
                    const otherProps = Object.entries(item).filter(([k, v]) => 
                        !['name', 'title', 'label', 'period', 'student', 'firstname', ...displayOrder].includes(k)
                        && !titleLikeKeys.includes(k.toLowerCase())
                    );
                    
                    if (otherProps.length > 0) {
                        // Separate long text props (description, etc.) from short ones.
                        const shortProps = otherProps.filter(([k]) => !longTextKeys.includes(k.toLowerCase()));
                        const longProps = otherProps.filter(([k]) => longTextKeys.includes(k.toLowerCase()));

                        if (shortProps.length > 0) {
                            html += '<div class="pulso-kv-grid secondary">';
                            for (const [key, value] of shortProps) {
                                const friendlyLabel = pulsoFieldLabel(key);
                                html += '<div><span class="pulso-kv-key">' + escapeHtml(friendlyLabel) + ':</span> <span class="pulso-kv-val">' + escapeHtml(String(value)) + '</span></div>';
                            }
                            html += '</div>';
                        }
                        if (longProps.length > 0) {
                            for (const [key, value] of longProps) {
                                html += '<div class="pulso-list-card-desc">' + escapeHtml(String(value)) + '</div>';
                            }
                        }
                    }

                    html += '</div>';
                } else {
                    // String simple - renderizar como elemento de lista simple
                    html += '<div class="pulso-list-item-simple">' + escapeHtml(String(item)) + '</div>';
                }
            });
            
            html += '</div>';
            return html;
        }
    C.fn.formatAIResponse = formatAIResponse;
    C.fn.sortTable = sortTable;
    C.fn.filterTable = filterTable;
    C.fn.exportTableAsExcel = exportTableAsExcel;
    C.fn.exportTableAsCSV = exportTableAsCSV;

    return {};
});
