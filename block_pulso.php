<?php
defined('MOODLE_INTERNAL') || die();

class block_pulso extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_pulso');
    }

    public function get_content() {
        global $COURSE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;

        if (empty($COURSE->id) || (defined('SITEID') && (int)$COURSE->id === (int)SITEID)) {
            $this->content->text = '';
            return $this->content;
        }

        // Validar contexto
        $context = context_course::instance($COURSE->id);

        // T2.6.1: Verificar si Pulso está habilitado para este curso
        $course_enabled = get_config('block_pulso', 'enabled_course_' . $COURSE->id);
        $default_enabled = get_config('block_pulso', 'enabled_by_default');
        // Si hay config específica del curso, usarla; si no, usar el default global
        if ($course_enabled !== false) {
            $is_enabled = (bool)$course_enabled;
        } else {
            $is_enabled = ($default_enabled === false) ? true : (bool)$default_enabled;
        }

        if (!$is_enabled) {
            $this->content->text = get_string('plugin_disabled_course', 'block_pulso');
            return $this->content;
        }

        // Permiso mínimo: usar el chat para preguntas de contenido (lo tienen
        // también los alumnos). Sin él, el chat no se renderiza.
        if (!has_capability('block/pulso:usechat', $context)) {
            $this->content->text = get_string('error_no_permission', 'block_pulso');
            return $this->content;
        }

        // T2.6.2: la capability de analítica distingue profesorado de alumnado.
        // Se calcula UNA vez aquí y se pasa a la vista para que la UI se adapte
        // (el bloqueo real de datos está en servidor, no en la UI).
        $isteacher = has_capability('block/pulso:viewanalytics', $context);

        // Cargar chat simple
        require_once(__DIR__ . '/chat_simple_view.php');
        $this->content->text = render_chat_simple($COURSE->id, $context, $isteacher);

        return $this->content;
    }

    public function applicable_formats() {
        return array(
            'site-index' => false,
            'course-view' => true,
            'all' => false
        );
    }

    public function has_config() {
        return true;
    }
}