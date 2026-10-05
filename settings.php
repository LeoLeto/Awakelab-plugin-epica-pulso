<?php
defined('MOODLE_INTERNAL') || die();

// Solo los usuarios con permisos de administración total del sitio pueden ver esto.
if ($ADMIN->fulltree) {

    // Anthropic API Key: se usa EXCLUSIVAMENTE para las respuestas de chat (Claude).
    // Usamos admin_setting_configpasswordunmask para que la clave esté oculta (con puntos)
    // pero el administrador pueda desenmascararla para verificarla. Es más seguro.
    $settings->add(new admin_setting_configpasswordunmask(
        'block_pulso/anthropic_key',
        get_string('setanthropickey', 'block_pulso'),
        get_string('setanthropickey_desc', 'block_pulso'),
        ''
    ));

    // "Check key" button para la clave de Anthropic.
    $settings->add(new admin_setting_description(
        'block_pulso/test_anthropic_key_button',
        '',
        '<button type="button" id="pulso-test-anthropic-key-btn" class="btn btn-secondary btn-sm">
            Check API key
        </button>
        <span id="pulso-test-anthropic-key-result" style="margin-left:10px;font-weight:bold"></span>
        <script>
        (function() {
            var s = document.createElement("style");
            s.textContent =
                "#admin-anthropic_key .form-inline{overflow:hidden}" +
                "#admin-anthropic_key .form-control{min-width:0;flex:1 1 auto;max-width:100%}" +
                "#admin-test_anthropic_key_button{margin-bottom:1.5rem}";
            document.head.appendChild(s);

            function init() {
                var btn = document.getElementById("pulso-test-anthropic-key-btn");
                if (!btn) { return; }
                btn.addEventListener("click", function() {
                    var result = document.getElementById("pulso-test-anthropic-key-result");
                    btn.disabled = true;
                    btn.textContent = "Checking…";
                    result.textContent = "";
                    fetch(M.cfg.wwwroot + "/blocks/pulso/check_anthropic_key.php", {credentials: "same-origin"})
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            result.style.color = d.success ? "green" : "red";
                            result.textContent  = (d.success ? "✓ " : "✗ ") + d.message;
                        })
                        .catch(function(e) {
                            result.style.color = "red";
                            result.textContent  = "✗ " + e.message;
                        })
                        .finally(function() {
                            btn.disabled = false;
                            btn.textContent = "Check API key";
                        });
                });
            }
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", init);
            } else {
                init();
            }
        })();
        </script>'
    ));

    // Selector de modelo Claude para las respuestas de chat.
    $options = [
        'claude-sonnet-5' => 'Claude Sonnet 5 (Recomendado)',
        'claude-opus-4-8' => 'Claude Opus 4.8 (Máxima calidad)',
        'claude-haiku-4-5' => 'Claude Haiku 4.5 (Económico)',
    ];
    $settings->add(new admin_setting_configselect(
        'block_pulso/model',                          // Variable de configuración
        get_string('setmodel', 'block_pulso'),        // Título
        get_string('setmodel_desc', 'block_pulso'),   // Descripción
        'claude-sonnet-5',                            // Valor por defecto
        $options                                      // Opciones del selector
    ));

    // OpenAI API Key: se usa EXCLUSIVAMENTE para generar los embeddings del RAG
    // (classes/embedding_manager.php). Ya NO se usa para el chat.
    $settings->add(new admin_setting_configpasswordunmask(
        'block_pulso/openai_key',
        get_string('setapikey', 'block_pulso'),
        get_string('setapikey_desc', 'block_pulso'),
        ''
    ));

    // "Check key" button para la clave de OpenAI (embeddings).
    $settings->add(new admin_setting_description(
        'block_pulso/test_key_button',
        '',
        '<button type="button" id="pulso-test-key-btn" class="btn btn-secondary btn-sm">
            Check API key
        </button>
        <span id="pulso-test-key-result" style="margin-left:10px;font-weight:bold"></span>
        <script>
        (function() {
            // Inject layout fixes via JS so Moodle\'s HTML purifier cannot strip them.
            var s = document.createElement("style");
            s.textContent =
                "#admin-openai_key .form-inline{overflow:hidden}" +
                "#admin-openai_key .form-control{min-width:0;flex:1 1 auto;max-width:100%}" +
                "#admin-test_key_button{margin-bottom:1.5rem}";
            document.head.appendChild(s);

            function init() {
                var btn = document.getElementById("pulso-test-key-btn");
                if (!btn) { return; }
                btn.addEventListener("click", function() {
                    var result = document.getElementById("pulso-test-key-result");
                    btn.disabled = true;
                    btn.textContent = "Checking…";
                    result.textContent = "";
                    fetch(M.cfg.wwwroot + "/blocks/pulso/check_api_key.php", {credentials: "same-origin"})
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            result.style.color = d.success ? "green" : "red";
                            result.textContent  = (d.success ? "✓ " : "✗ ") + d.message;
                        })
                        .catch(function(e) {
                            result.style.color = "red";
                            result.textContent  = "✗ " + e.message;
                        })
                        .finally(function() {
                            btn.disabled = false;
                            btn.textContent = "Check API key";
                        });
                });
            }
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", init);
            } else {
                init();
            }
        })();
        </script>'
    ));

    // ============================================================
    // T2.6.1: Per-course enable/disable toggle (global default)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/coursecontrol_heading',
        get_string('coursecontrol_heading', 'block_pulso'),
        get_string('coursecontrol_heading_desc', 'block_pulso')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_pulso/enabled_by_default',
        get_string('enabled_by_default', 'block_pulso'),
        get_string('enabled_by_default_desc', 'block_pulso'),
        1
    ));

    // ============================================================
    // T2.6.2: Data access permission controls (category toggles)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/dataaccess_heading',
        get_string('dataaccess_heading', 'block_pulso'),
        get_string('dataaccess_heading_desc', 'block_pulso')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_pulso/data_completion',
        get_string('data_completion', 'block_pulso'),
        get_string('data_completion_desc', 'block_pulso'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_pulso/data_grades',
        get_string('data_grades', 'block_pulso'),
        get_string('data_grades_desc', 'block_pulso'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_pulso/data_logs',
        get_string('data_logs', 'block_pulso'),
        get_string('data_logs_desc', 'block_pulso'),
        1
    ));

    // ============================================================
    // RAG: Retrieval-Augmented Generation settings
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/rag_heading',
        get_string('rag_heading', 'block_pulso'),
        get_string('rag_heading_desc', 'block_pulso')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_pulso/rag_enabled',
        get_string('rag_enabled', 'block_pulso'),
        get_string('rag_enabled_desc', 'block_pulso'),
        0   // Disabled by default until the admin runs the first index.
    ));


    // ============================================================
    // Validación de los ajustes numéricos y de URL (v1.31.0).
    // ============================================================
    // Un cupo negativo o con texto dejaba la herramienta bloqueada (o sin tope) sin
    // avisar. admin_setting_configtext::validate() acepta true o un texto de error.
    $pulsonum = function (string $name, $default, string $paramtype = PARAM_INT) use ($settings) {
        $setting = new admin_setting_configtext(
            'block_pulso/' . $name,
            get_string($name, 'block_pulso'),
            get_string($name . '_desc', 'block_pulso'),
            $default,
            $paramtype
        );
        if (method_exists($setting, 'set_validate_function')) {
            $setting->set_validate_function(function ($value) {
                $value = trim((string)$value);
                return (is_numeric($value) && (float)$value >= 0) ? true : get_string('err_setting_nonneg', 'block_pulso');
            });
        }
        $settings->add($setting);
    };

    // ============================================================
    // Límite de uso del chat por persona (v1.31.0)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/chatlimit_heading',
        get_string('chatlimit_heading', 'block_pulso'),
        get_string('chatlimit_heading_desc', 'block_pulso')
    ));
    $pulsonum('chat_max_por_minuto_usuario', 6);
    $pulsonum('chat_max_por_dia_usuario', 150);

    // ============================================================
    // Encargos de creacion (Epica): cupos anti-abuso (v1.18.0)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/creationquota_heading',
        get_string('creationquota_heading', 'block_pulso'),
        get_string('creationquota_heading_desc', 'block_pulso')
    ));
    $pulsonum('quota_user_section_day', 2);
    $pulsonum('quota_user_course_day', 5);
    $pulsonum('quota_course_hour', 15);
    $pulsonum('quota_course_day_floor', 40);
    $pulsonum('quota_course_day_multiplier', '1.5', PARAM_RAW);
    $pulsonum('quota_teacher_day', 10);

    // ============================================================
    // Ampliacion de recursos: YouTube + OpenAlex (v1.24.0)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/ampliacion_heading',
        get_string('ampliacion_heading', 'block_pulso'),
        get_string('ampliacion_heading_desc', 'block_pulso')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'block_pulso/youtube_api_key',
        get_string('youtube_api_key', 'block_pulso'),
        get_string('youtube_api_key_desc', 'block_pulso'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'block_pulso/openalex_api_key',
        get_string('openalex_api_key', 'block_pulso'),
        get_string('openalex_api_key_desc', 'block_pulso'),
        ''
    ));

    $pulsonum('ampliacion_max_dia_sitio', 80);
    $pulsonum('ampliacion_max_dia_usuario', 5);
    $pulsonum('ampliacion_ttl_dias', 30);

    // ============================================================
    // Retos de Epica: topes propios (v1.26.0)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/retos_heading',
        get_string('retos_heading', 'block_pulso'),
        get_string('retos_heading_desc', 'block_pulso')
    ));
    $pulsonum('retos_max_propuestas_usuario_dia', 6);
    $pulsonum('retos_max_elegidos_usuario_dia', 3);
    $pulsonum('retos_max_elegidos_curso_dia', 40);

    // ============================================================
    // Ciclo con Epica: dónde apuntar y modo de ensayo (v1.19.0)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/epica_heading',
        get_string('epica_heading', 'block_pulso'),
        get_string('epica_heading_desc', 'block_pulso')
    ));

    // Sin valor por defecto a propósito (v1.31.0): antes apuntaba al QA y una producción mal
    // configurada habría mandado material al QA. Vacío = Épica no disponible.
    $epicaurl = new admin_setting_configtext(
        'block_pulso/epica_base_url',
        get_string('epica_base_url', 'block_pulso'),
        get_string('epica_base_url_desc', 'block_pulso'),
        '',
        PARAM_URL
    );
    if (method_exists($epicaurl, 'set_validate_function')) {
        $epicaurl->set_validate_function(function ($value) {
            $value = trim((string)$value);
            return ($value === '' || stripos($value, 'https://') === 0) ? true : get_string('err_setting_https', 'block_pulso');
        });
    }
    $settings->add($epicaurl);

    $settings->add(new admin_setting_configcheckbox(
        'block_pulso/epica_dry_run',
        get_string('epica_dry_run', 'block_pulso'),
        get_string('epica_dry_run_desc', 'block_pulso'),
        0
    ));

    // ============================================================
    // Diagnóstico para quien administra el sitio (v1.31.0)
    // ============================================================
    $settings->add(new admin_setting_heading(
        'block_pulso/diagnostico_heading',
        get_string('diagnostico_heading', 'block_pulso'),
        html_writer::link(
            new moodle_url('/blocks/pulso/diagnostico.php'),
            get_string('diagnostico_link', 'block_pulso')
        ) . '<br>' . get_string('diagnostico_heading_desc', 'block_pulso')
    ));
}
