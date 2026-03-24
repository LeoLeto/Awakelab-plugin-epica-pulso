<?php
/**
 * Upgrade script for block_pulso
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_block_pulso_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026031600) {
        // Add block_pulso_content_chunks table for RAG.
        $table = new xmldb_table('block_pulso_content_chunks');

        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('module_type',  XMLDB_TYPE_CHAR,    '50', null, XMLDB_NOTNULL, null, '');
        $table->add_field('module_name',  XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('chunk_index',  XMLDB_TYPE_INTEGER,  '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('chunk_text',   XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
        $table->add_field('content_hash', XMLDB_TYPE_CHAR,    '64', null, XMLDB_NOTNULL, null, '');
        $table->add_field('embedding_json', XMLDB_TYPE_TEXT,  null, null, null);
        $table->add_field('token_count',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('idx_cmid',     XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        $table->add_index('idx_hash',     XMLDB_INDEX_NOTUNIQUE, ['content_hash']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026031600, 'pulso');
    }

    return true;
}
