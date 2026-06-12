<?php
defined('MOODLE_INTERNAL') || die();

// Solo los usuarios con permisos de administración total del sitio pueden ver esto.
if ($ADMIN->fulltree) {

    // Requisito T2.1.4.1: Campo para la OpenAI API Key.
    // Usamos admin_setting_configpasswordunmask para que la clave esté oculta (con puntos) 
    // pero el administrador pueda desenmascararla para verificarla. Es más seguro.
    $settings->add(new admin_setting_configpasswordunmask(
        'block_pulso/openai_key',                     // Variable de configuración: componente/nombre
        get_string('setapikey', 'block_pulso'),       // Título (desde archivo de idioma)
        get_string('setapikey_desc', 'block_pulso'),  // Descripción
        ''                                            // Valor por defecto (vacío)
    ));

    // "Check key" button rendered below the API key field.
    $settings->add(new admin_setting_description(
        'block_pulso/test_key_button',
        '',
        '<style>
            /* Prevent the masked API key dots from overflowing the form column */
            #admin-openai_key .form-inline { flex-wrap: nowrap; overflow: hidden; }
            #admin-openai_key .form-control { min-width: 0; flex: 1 1 auto; max-width: 100%; }
            /* Space between the button row and the next setting */
            #admin-test_key_button { margin-bottom: 1.5rem; }
        </style>
        <button type="button" id="pulso-test-key-btn" class="btn btn-secondary btn-sm">
            Check API key
        </button>
        <span id="pulso-test-key-result" style="margin-left:10px;font-weight:bold"></span>
        <script>
        (function() {
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

    // Requisito T2.1.4.2: Selector de Modelo de IA.
    $options = [
        'gpt-4o' => 'GPT-4o (Recomendado)',
        'gpt-4'  => 'GPT-4'
    ];
    $settings->add(new admin_setting_configselect(
        'block_pulso/model',                          // Variable de configuración
        get_string('setmodel', 'block_pulso'),        // Título
        get_string('setmodel_desc', 'block_pulso'),   // Descripción
        'gpt-4o',                                     // Valor por defecto
        $options                                      // Opciones del selector
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
}