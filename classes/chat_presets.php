<?php
/**
 * Prompts preestablecidos del chat (v2.14.0).
 *
 * El chat sugiere estos prompts mientras se escribe y la home los enseña como catálogo. Al elegir uno
 * se abre un panel con sus opciones (unidad, extensión, formato…) y lo que viaja al servidor NO es
 * texto libre: es el identificador del prompt y sus parámetros. Aquí se validan, se convierten en la
 * pregunta canónica y, en los de MATERIAL, se reúne el texto de la unidad o del recurso POR SU ID —sin
 * pasar por el matcher de nombres— junto con el encargo exacto que recibe el modelo.
 *
 * Dos modos:
 *  - `material`: va SIEMPRE al LLM con el texto de la sección/recurso elegido y un encargo cerrado
 *    (bloque dinámico del system). Se salta la ruta directa y la detección de «pregunta de profesor»:
 *    lo que se manda es contenido del curso, elegido de una lista que ya filtró la visibilidad.
 *  - `query`: una pregunta canónica que entra por el pipeline de siempre (ruta directa o LLM). Son las
 *    preguntas que ya ofrecía la home, ahora con sus parámetros.
 *
 * La UI no es un control de acceso: `resolve()` vuelve a comprobar rol y visibilidad de cada id.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

// Solo lo imprescindible: esta clase se carga al pintar el chat en cada página del curso (home_groups).
// rag_retriever y full_text_store se cargan dentro de los métodos que los usan.
require_once(__DIR__ . '/pulso_error.php');

class chat_presets {

    /** Tope del texto libre de un parámetro (el concepto a explicar). */
    const MAX_TOPIC_CHARS = 120;

    /** Tope de elementos por desplegable (un curso enorme no puede inflar la respuesta). */
    const MAX_OPTIONS = 200;

    /** Grupos del catálogo, en el orden en que se pintan. */
    const GROUPS = [
        'resumenes' => 'Resúmenes',
        'estudio' => 'Estudio',
        'estructura' => 'Estructura del curso',
        'analitica' => 'Analítica del curso',
    ];

    /** Desplegables de valores fijos: nombre => [etiqueta, opciones valor => texto, valor por defecto]. */
    const CHOICES = [
        'length' => ['Extensión', ['breve' => 'Breve', 'normal' => 'Normal', 'detallado' => 'Detallado'], 'normal'],
        'format' => ['Formato', ['texto' => 'Texto', 'puntos' => 'Puntos clave', 'esquema' => 'Esquema'], 'texto'],
        'level' => ['Nivel', ['sencillo' => 'Sencillo', 'normal' => 'Normal', 'avanzado' => 'Avanzado'], 'normal'],
        'count' => ['Número de preguntas', ['5' => '5 preguntas', '10' => '10 preguntas'], '5'],
        'qtype' => ['Tipo de pregunta', ['test' => 'Tipo test', 'abiertas' => 'Respuesta abierta', 'vf' => 'Verdadero o falso'], 'test'],
        'period' => ['Sin acceder desde hace', ['semana' => 'Más de una semana', 'dossemanas' => 'Más de dos semanas', 'mes' => 'Más de un mes'], 'semana'],
    ];

    /**
     * El catálogo. `params` son nombres de un vocabulario cerrado: los de CHOICES más `section`,
     * `resource`, `quiz`, `activity` (ids del curso) y `topic` (texto libre corto). `keywords` son
     * sinónimos para la sugerencia mientras se escribe (la etiqueta ya cuenta).
     *
     * @return array<string,array>
     */
    private static function definitions(): array {
        return [
            'resumen_unidad' => [
                'group' => 'resumenes', 'label' => 'Haz un resumen de una unidad', 'mode' => 'material',
                'params' => ['unit', 'length', 'format'],
                'keywords' => 'resume resumir resumeme sintesis tema seccion bloque modulo',
            ],
            'resumen_recurso' => [
                'group' => 'resumenes', 'label' => 'Haz un resumen de un recurso', 'mode' => 'material',
                'params' => ['resource', 'length', 'format'],
                'keywords' => 'resume resumir resumeme sintesis documento pdf material archivo manual',
            ],
            'resumen_curso' => [
                'group' => 'resumenes', 'label' => 'Haz un resumen del curso', 'mode' => 'query',
                'params' => [],
                'keywords' => 'resume resumir de que trata el curso temario',
                'query' => '¿De qué trata este curso?',
            ],
            'explicar_concepto' => [
                'group' => 'estudio', 'label' => 'Explícame un concepto de una unidad', 'mode' => 'material',
                'params' => ['unit', 'topic', 'level'],
                'keywords' => 'explica explicar explicame aclara duda entender que es que significa tema',
            ],
            'preguntas_repaso' => [
                'group' => 'estudio', 'label' => 'Hazme preguntas de repaso de una unidad', 'mode' => 'material',
                'params' => ['unit', 'count', 'qtype'],
                'keywords' => 'test autoevaluacion examen practicar repasar cuestionario preguntame tema',
            ],
            'esquema_unidad' => [
                'group' => 'estudio', 'label' => 'Haz un esquema de una unidad', 'mode' => 'material',
                'params' => ['unit'],
                'keywords' => 'esquematiza indice mapa conceptual estructura puntos tema',
            ],
            'glosario_unidad' => [
                'group' => 'estudio', 'label' => 'Haz un glosario de una unidad', 'mode' => 'material',
                'params' => ['unit'],
                'keywords' => 'terminos vocabulario definiciones conceptos clave palabras tema',
            ],
            'contenido_unidad' => [
                'group' => 'estructura', 'label' => '¿Qué hay en una unidad?', 'mode' => 'direct',
                'params' => ['unit'],
                'keywords' => 'que contiene actividades recursos materiales lista tema seccion',
            ],
            'secciones_curso' => [
                'group' => 'estructura', 'label' => '¿Qué secciones tiene el curso?', 'mode' => 'query',
                'params' => [],
                'keywords' => 'unidades temas bloques estructura indice',
                'query' => '¿Qué secciones tiene el curso?',
            ],
            'cuestionarios_curso' => [
                'group' => 'estructura', 'label' => '¿Qué cuestionarios hay en el curso?', 'mode' => 'query',
                'params' => [],
                'keywords' => 'examenes test evaluaciones lista',
                'query' => '¿Qué cuestionarios hay en el curso?',
            ],
            'tareas_curso' => [
                'group' => 'estructura', 'label' => '¿Qué tareas hay en el curso?', 'mode' => 'query',
                'params' => [],
                'keywords' => 'entregas trabajos deberes lista',
                'query' => '¿Qué tareas hay en el curso?',
            ],
            'info_actividad' => [
                'group' => 'estructura', 'label' => 'Fechas e instrucciones de una actividad', 'mode' => 'query',
                'params' => ['activity'],
                'keywords' => 'cuando se entrega plazo fecha limite instrucciones enunciado tarea cuestionario',
            ],
            'panorama_curso' => [
                'group' => 'analitica', 'label' => 'Panorama del curso', 'mode' => 'query', 'teacher' => true,
                'params' => [],
                'keywords' => 'como va el curso vision general estado analitica',
                'query' => 'Dame un panorama general del curso: tasa de completitud, nota media y cuántos alumnos están en riesgo.',
            ],
            'alumnos_riesgo' => [
                'group' => 'analitica', 'label' => '¿Qué estudiantes están en riesgo?', 'mode' => 'query', 'teacher' => true,
                'params' => [],
                'keywords' => 'alumnos riesgo abandono suspender alerta',
                'query' => '¿Qué estudiantes están en riesgo?',
            ],
            'alumnos_inactivos' => [
                'group' => 'analitica', 'label' => '¿Qué alumnos llevan tiempo sin acceder?', 'mode' => 'query', 'teacher' => true,
                'params' => ['period'],
                'keywords' => 'estudiantes inactivos no entran sin conectarse acceso dias',
            ],
            'resultados_cuestionario' => [
                'group' => 'analitica', 'label' => 'Resultados de un cuestionario', 'mode' => 'query', 'teacher' => true,
                'params' => ['quiz'],
                'keywords' => 'notas intentos aprobados examen test nota media',
            ],
            'completitud_curso' => [
                'group' => 'analitica', 'label' => '¿Cuál es la tasa de completitud?', 'mode' => 'query', 'teacher' => true,
                'params' => [],
                'keywords' => 'finalizacion completado progreso avance porcentaje',
                'query' => '¿Cuál es la tasa de completitud?',
            ],
            'notas_medias' => [
                'group' => 'analitica', 'label' => '¿Cuáles son las notas medias?', 'mode' => 'query', 'teacher' => true,
                'params' => [],
                'keywords' => 'calificaciones promedio nota media',
                'query' => '¿Cuáles son las notas promedio?',
            ],
            'estado_entregas' => [
                'group' => 'analitica', 'label' => 'Estado de las entregas de las tareas', 'mode' => 'query', 'teacher' => true,
                'params' => [],
                'keywords' => 'entregadas pendientes sin calificar tareas trabajos',
                'query' => '¿Cuál es el estado de las entregas de las tareas: cuántas entregadas, cuántas pendientes y cuántas sin calificar?',
            ],
        ];
    }

    /**
     * Catálogo para el cliente y para la home: solo lo que le toca a este rol.
     *
     * @param bool $isteacher
     * @return array Lista de {id, group, label, keywords, params}
     */
    public static function catalog(bool $isteacher): array {
        $out = [];
        foreach (self::definitions() as $id => $def) {
            if (!empty($def['teacher']) && !$isteacher) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'group' => $def['group'],
                'label' => $def['label'],
                'keywords' => $def['keywords'],
                'params' => $def['params'],
            ];
        }
        return $out;
    }

    /**
     * Contexto de la home (Mustache): grupos con sus prompts, ya filtrados por rol.
     *
     * @param bool $isteacher
     * @return array Lista de {title, items: [{id, label, hasparams}]}
     */
    public static function home_groups(bool $isteacher): array {
        $groups = [];
        foreach (self::catalog($isteacher) as $item) {
            $groups[$item['group']][] = [
                'id' => $item['id'],
                'label' => $item['label'],
                'hasparams' => !empty($item['params']),
            ];
        }
        $out = [];
        foreach (self::GROUPS as $key => $title) {
            if (!empty($groups[$key])) {
                $out[] = ['title' => $title, 'items' => $groups[$key]];
            }
        }
        return $out;
    }

    /**
     * Desplegables de valores fijos, para el cliente.
     *
     * @return array nombre => {label, default, options: [{value, label}]}
     */
    public static function choices(): array {
        $out = [];
        foreach (self::CHOICES as $name => [$label, $options, $default]) {
            $list = [];
            foreach ($options as $value => $text) {
                $list[] = ['value' => (string)$value, 'label' => $text];
            }
            $out[$name] = ['label' => $label, 'default' => $default, 'options' => $list];
        }
        return $out;
    }

    /**
     * Lo que este usuario puede elegir en ESTE curso: unidades, recursos con texto legible,
     * cuestionarios y actividades con fecha. Todo pasa por la visibilidad del usuario actual.
     *
     * @param int $courseid
     * @return array{sections: array, resources: array, quizzes: array, activities: array}
     */
    public static function course_options(int $courseid): array {
        require_once(__DIR__ . '/full_text_store.php');

        require_once(__DIR__ . '/unit_map.php');

        $out = ['units' => [], 'unitsource' => 'reglas', 'resources' => [], 'quizzes' => [], 'activities' => []];
        try {
            $modinfo = \get_fast_modinfo($courseid);
        } catch (\Throwable $e) {
            return $out;
        }

        // Unidades de aprendizaje (unit_map, v2.16.0): las que detectó el modelo al indexar o, sin mapa,
        // las de las reglas de estructura. Ya vienen filtradas por lo que ve este usuario.
        $map = unit_map::for_user($courseid);
        $out['unitsource'] = $map['source'];
        foreach ($map['units'] as $unit) {
            if (count($out['units']) < self::MAX_OPTIONS) {
                $out['units'][] = ['id' => $unit['key'], 'num' => $unit['num'], 'label' => $unit['title']];
            }
        }

        $sectionnames = [];
        foreach ($modinfo->get_section_info_all() as $info) {
            $num = (int)$info->section;
            if ($num === 0 || empty($info->uservisible)) {
                continue;
            }
            $name = self::section_name($courseid, $info);
            $sectionnames[$num] = $name;
        }

        $cmids = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!empty($cm->uservisible)) {
                $cmids[] = (int)$cm->id;
            }
        }
        $rows = [];
        if (!empty($cmids)) {
            try {
                $rows = full_text_store::get_rows_for_cmids($courseid, $cmids);
            } catch (\Throwable $e) {
                $rows = [];
            }
        }

        foreach ($modinfo->get_cms() as $cm) {
            if (empty($cm->uservisible)) {
                continue;
            }
            $cmid = (int)$cm->id;
            $name = trim((string)$cm->name);
            if ($name === '') {
                continue;
            }
            $group = $sectionnames[(int)$cm->sectionnum] ?? '';
            $modname = (string)$cm->modname;

            $row = $rows[$cmid] ?? null;
            if ($row !== null && !empty($row->usable) && count($out['resources']) < self::MAX_OPTIONS) {
                $out['resources'][] = ['id' => $cmid, 'label' => $name, 'group' => $group];
            }
            if ($modname === 'quiz' && count($out['quizzes']) < self::MAX_OPTIONS) {
                $out['quizzes'][] = ['id' => $cmid, 'label' => $name, 'group' => $group];
            }
            if (($modname === 'quiz' || $modname === 'assign') && count($out['activities']) < self::MAX_OPTIONS) {
                $out['activities'][] = ['id' => $cmid, 'label' => $name, 'group' => $group];
            }
        }

        return $out;
    }

    /**
     * Lee `preset` y `preset_params` de la petición y los resuelve. Sin `preset`, null (texto libre).
     *
     * @param int $courseid
     * @param bool $isteacher
     * @return array|null Ver resolve().
     */
    public static function from_request(int $courseid, bool $isteacher): ?array {
        $id = trim((string)\optional_param('preset', '', PARAM_ALPHANUMEXT));
        if ($id === '') {
            return null;
        }
        $raw = (string)\optional_param('preset_params', '{}', PARAM_RAW);
        $params = json_decode($raw, true);
        if (!is_array($params)) {
            $params = [];
        }
        return self::resolve($courseid, $id, $params, $isteacher);
    }

    /**
     * Valida un prompt preestablecido y lo convierte en lo que necesita el pipeline.
     *
     * @param int $courseid
     * @param string $id
     * @param array $params Valores del cliente (nunca de confianza).
     * @param bool $isteacher
     * @return array{id: string, mode: string, query: string, material: array, instructions: string}
     *         `query` = pregunta canónica (la que se contesta y se guarda en el historial);
     *         `material` = contexto de rag_retriever para el bloque dinámico (solo modo material);
     *         `instructions` = encargo para el modelo (solo modo material).
     * @throws pulso_error bad_request si el prompt o un parámetro no valen; access si no es de su rol.
     */
    public static function resolve(int $courseid, string $id, array $params, bool $isteacher): array {
        $defs = self::definitions();
        if (!isset($defs[$id])) {
            throw new pulso_error('bad_request');
        }
        $def = $defs[$id];
        if (!empty($def['teacher']) && !$isteacher) {
            throw new pulso_error('access', '', 403);
        }

        // Valores ya validados: de aquí en adelante solo se usa $v.
        $v = [];
        foreach ($def['params'] as $name) {
            if (isset(self::CHOICES[$name])) {
                [, $options, $default] = self::CHOICES[$name];
                $value = (string)($params[$name] ?? $default);
                $v[$name] = isset($options[$value]) ? $value : $default;
            } else if ($name === 'topic') {
                $v['topic'] = self::clean_topic((string)($params['topic'] ?? ''));
                if ($v['topic'] === '') {
                    throw new pulso_error('bad_request');
                }
            } else if ($name === 'unit') {
                $v['unit'] = (string)($params['unit'] ?? '');
                if (!preg_match('/^u\d{1,12}$/', $v['unit'])) {
                    throw new pulso_error('bad_request');
                }
            } else {
                $v[$name] = (int)($params[$name] ?? 0);
                if ($v[$name] <= 0) {
                    throw new pulso_error('bad_request');
                }
            }
        }

        $out = ['id' => $id, 'mode' => $def['mode'], 'query' => '', 'material' => [], 'instructions' => ''];

        // La unidad elegida, del mapa de ESTE usuario: si no existe o no ve nada de ella, 400.
        $unit = null;
        if (isset($v['unit'])) {
            require_once(__DIR__ . '/unit_map.php');
            $unit = unit_map::find($courseid, $v['unit']);
            if ($unit === null) {
                throw new pulso_error('bad_request');
            }
        }

        if ($def['mode'] === 'direct') {
            $out['query'] = '¿Qué hay en la unidad «' . $unit['title'] . '»?';
            $out['direct'] = self::unit_listing($courseid, $unit);
            return $out;
        }

        if ($def['mode'] === 'material') {
            require_once(__DIR__ . '/rag_retriever.php');
            if ($unit !== null) {
                $material = rag_retriever::build_summary_context_for_cms($courseid, $unit['cmids'], $unit['title']);
                $scope = 'la unidad «' . ($material['title'] ?? '') . '»';
            } else {
                $material = rag_retriever::build_summary_context_for_resource($courseid, $v['resource']);
                $scope = 'el recurso «' . ($material['title'] ?? '') . '»';
            }
            if (empty($material['title'])) {
                // La sección o el recurso no existe, no es de este curso o este usuario no lo ve.
                throw new pulso_error('bad_request');
            }
            $material['preset'] = true;
            $out['material'] = $material;
            [$query, $task] = self::material_task($id, $scope, $v);
            // «de» + «el recurso» se contrae.
            $out['query'] = str_replace(' de el recurso ', ' del recurso ', $query);
            $out['instructions'] = str_replace(' de el recurso ', ' del recurso ', $task);
            return $out;
        }

        $out['query'] = isset($def['query']) ? $def['query'] : self::parametrized_query($courseid, $id, $v);
        return $out;
    }

    /**
     * Pregunta canónica y encargo de un prompt de material.
     *
     * El encargo fija el campo `type` y la forma de `data` con claves que el chat ya sabe pintar
     * (format.js: párrafos {paragraph}, tarjetas con título `name`/`idea`/`tema` y cuerpo
     * `descripcion`/`definicion`/`texto`). No inventa campos fuera del esquema.
     *
     * @param string $id
     * @param string $scope «la unidad «X»» / «el recurso «X»»
     * @param array $v Parámetros ya validados
     * @return array{0: string, 1: string} [pregunta, encargo]
     */
    private static function material_task(string $id, string $scope, array $v): array {
        $lengths = [
            'breve' => ['breve', 'Extensión: breve, unas 100 palabras en total.'],
            'normal' => ['', 'Extensión: media, unas 220 palabras en total.'],
            'detallado' => ['detallado', 'Extensión: detallada, hasta 400 palabras en total, sin dejarte ningún apartado del material.'],
        ];
        $formats = [
            'texto' => ['', 'Formato: "type":"text" y en "data" un objeto {"paragraph":"…"} por párrafo (2 a 5 párrafos).'],
            'puntos' => ['en puntos clave', 'Formato: "type":"list" y en "data" un objeto {"idea":"título corto","descripcion":"una o dos frases"} por punto clave (5 a 8 puntos).'],
            'esquema' => ['en forma de esquema', 'Formato: "type":"list" y en "data" un objeto {"tema":"apartado","descripcion":"sus subpuntos, separados por punto y coma"} por apartado, en el orden del material.'],
        ];

        switch ($id) {
            case 'resumen_unidad':
            case 'resumen_recurso':
                [$lword, $lrule] = $lengths[$v['length']];
                [$fword, $frule] = $formats[$v['format']];
                $query = trim('Haz un resumen ' . $lword) . ' de ' . $scope . ($fword !== '' ? ' ' . $fword : '') . '.';
                $task = 'Resume ' . $scope . ". $lrule $frule";
                break;

            case 'explicar_concepto':
                $levels = [
                    'sencillo' => ['de forma sencilla', 'Nivel: sencillo, para quien lo ve por primera vez; frases cortas, sin tecnicismos sin explicar y con un ejemplo cotidiano.'],
                    'normal' => ['', 'Nivel: el del propio material, con un ejemplo.'],
                    'avanzado' => ['con detalle', 'Nivel: avanzado; entra en los matices, las relaciones con otros conceptos del material y los casos límite que el material recoja.'],
                ];
                [$lword, $lrule] = $levels[$v['level']];
                $query = trim('Explícame ' . $lword) . ' «' . $v['topic'] . '» con el material de ' . $scope . '.';
                $task = 'Explica el concepto «' . $v['topic'] . '» usando el material de ' . $scope . ". $lrule "
                    . 'Formato: "type":"text" y en "data" un objeto {"paragraph":"…"} por párrafo. '
                    . 'Si el material no trata ese concepto, dilo con claridad y di qué conceptos cercanos sí trata; no lo expliques de memoria.';
                break;

            case 'preguntas_repaso':
                $types = [
                    'test' => ['tipo test', 'Cada pregunta es de tipo test con cuatro opciones a), b), c) y d) y una sola correcta.'],
                    'abiertas' => ['de respuesta abierta', 'Cada pregunta es de respuesta abierta y corta.'],
                    'vf' => ['de verdadero o falso', 'Cada pregunta es una afirmación de verdadero o falso.'],
                ];
                [$tword, $trule] = $types[$v['qtype']];
                $n = (int)$v['count'];
                $query = 'Hazme ' . $n . ' preguntas de repaso ' . $tword . ' de ' . $scope . '.';
                $task = 'Escribe ' . $n . ' preguntas de repaso sobre ' . $scope . ". $trule "
                    . 'Deben poder contestarse solo con el material y cubrir apartados distintos. '
                    . 'Formato: "type":"list" y en "data" un objeto por pregunta: {"name":"Pregunta N","texto":"enunciado'
                    . ($v['qtype'] === 'test' ? ' y sus cuatro opciones' : '') . '","solucion":"la respuesta correcta y, en una frase, por qué"}. '
                    . 'Exactamente ' . $n . ' preguntas, ni una más.';
                break;

            case 'esquema_unidad':
                $query = 'Haz un esquema de ' . $scope . '.';
                $task = 'Haz un esquema de ' . $scope . ', en el orden del material. '
                    . 'Formato: "type":"list" y en "data" un objeto {"tema":"apartado","descripcion":"sus subpuntos, separados por punto y coma"} por apartado (hasta 10).';
                break;

            case 'glosario_unidad':
            default:
                $query = 'Haz un glosario de ' . $scope . '.';
                $task = 'Haz un glosario con los términos clave de ' . $scope . ', por orden alfabético. '
                    . 'Formato: "type":"list" y en "data" un objeto {"name":"término","definicion":"definición según el material, en una o dos frases"} por término (hasta 10). '
                    . 'Solo términos que el material define o usa; no añadas definiciones de memoria.';
                break;
        }

        return [$query, $task];
    }

    /**
     * Pregunta canónica de un prompt de modo `query` con parámetros. Mete el NOMBRE real de la
     * sección o actividad: ese texto entra por el pipeline y el matcher lo necesita.
     *
     * @param int $courseid
     * @param string $id
     * @param array $v Parámetros ya validados
     * @return string
     * @throws pulso_error bad_request si el id elegido no existe o no es visible.
     */
    private static function parametrized_query(int $courseid, string $id, array $v): string {
        switch ($id) {
            case 'info_actividad':
                return '¿Cuáles son las instrucciones y las fechas de "' . self::visible_cm_name($courseid, $v['activity'], ['quiz', 'assign']) . '"?';

            case 'resultados_cuestionario':
                return '¿Cuáles son los resultados del cuestionario "' . self::visible_cm_name($courseid, $v['quiz'], ['quiz'])
                    . '": nota media, número de intentos y cuántos alumnos aprobaron?';

            case 'alumnos_inactivos':
                $periods = ['semana' => 'una semana', 'dossemanas' => 'dos semanas', 'mes' => 'un mes'];
                return '¿Qué alumnos llevan más de ' . $periods[$v['period']] . ' sin acceder?';
        }
        throw new pulso_error('bad_request');
    }

    /**
     * Listado de una unidad, montado desde Moodle sin pasar por el LLM (modo `direct`). Misma forma
     * que la respuesta de la ruta directa: lo que guarda el historial y lo que pinta el chat.
     *
     * @param int $courseid
     * @param array $unit De unit_map::find()
     * @return array{answer: string, schema_data: array, followup_questions: array}
     */
    private static function unit_listing(int $courseid, array $unit): array {
        $items = unit_map::describe($courseid, $unit['cmids']);
        $lines = ['Unidad: ' . $unit['title'], 'Numero de actividades: ' . count($items), 'Contenidos de esta unidad:'];
        foreach ($items as $item) {
            $lines[] = '- [' . $item['modname'] . '] ' . ($item['name'] !== '' ? $item['name'] : 'actividad sin nombre');
        }
        $payload = [
            'type' => 'text',
            'title' => 'Contenido de la unidad ' . $unit['title'],
            'summary' => 'Esto es lo que hay en la unidad.',
            'content' => implode("\n", $lines),
        ];
        return [
            'answer' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'schema_data' => $payload,
            'followup_questions' => [],
        ];
    }

    /**
     * Nombre de una sección visible del curso, por su id.
     *
     * @param int $courseid
     * @param int $sectionid course_sections.id
     * @return string
     * @throws pulso_error
     */
    private static function visible_section_name(int $courseid, int $sectionid): string {
        try {
            $modinfo = \get_fast_modinfo($courseid);
            foreach ($modinfo->get_section_info_all() as $info) {
                if ((int)$info->id === $sectionid && (int)$info->section !== 0 && !empty($info->uservisible)) {
                    return self::section_name($courseid, $info);
                }
            }
        } catch (\Throwable $e) {
            // Falla cerrado: sin modinfo no se puede comprobar la visibilidad.
        }
        throw new pulso_error('bad_request');
    }

    /**
     * Nombre de un módulo visible del curso, por su cmid y de uno de los tipos admitidos.
     *
     * @param int $courseid
     * @param int $cmid
     * @param string[] $modnames
     * @return string
     * @throws pulso_error
     */
    private static function visible_cm_name(int $courseid, int $cmid, array $modnames): string {
        try {
            $cm = \get_fast_modinfo($courseid)->get_cm($cmid);
            $name = trim((string)$cm->name);
            if (!empty($cm->uservisible) && in_array((string)$cm->modname, $modnames, true) && $name !== '') {
                // Las comillas dobles delimitan el nombre en la pregunta: dentro, se quitan.
                return str_replace('"', '', $name);
            }
        } catch (\Throwable $e) {
            // get_cm() lanza si el cmid no es de este curso.
        }
        throw new pulso_error('bad_request');
    }

    /**
     * Nombre visible de una sección: el suyo o el que le da el formato del curso («Tema 3»).
     *
     * @param int $courseid
     * @param object $info section_info
     * @return string
     */
    private static function section_name(int $courseid, $info): string {
        $name = trim((string)($info->name ?? ''));
        if ($name === '') {
            try {
                $name = trim((string)\get_section_name($courseid, $info));
            } catch (\Throwable $e) {
                $name = '';
            }
        }
        if ($name === '') {
            $name = 'Sección ' . (int)$info->section;
        }
        return \format_string($name);
    }

    /**
     * Limpia el texto libre de un parámetro: una línea, sin delimitadores de material ni comillas
     * angulares (las usa el encargo para citarlo), y con tope en caracteres (nunca en bytes).
     *
     * @param string $text
     * @return string
     */
    private static function clean_topic(string $text): string {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = preg_replace('/[<>«»"]+/u', '', (string)$text);
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        return mb_substr($text, 0, self::MAX_TOPIC_CHARS, 'UTF-8');
    }
}
