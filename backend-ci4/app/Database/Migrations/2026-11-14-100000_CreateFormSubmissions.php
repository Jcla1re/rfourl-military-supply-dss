<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per form submission that must only ever happen once (a POS sale, a stock order, a
 * delivery received, a stock log...). Each form carries a random key (App\Libraries\OnceToken);
 * the UNIQUE index on submission_key makes the database itself refuse a second use of the same
 * key, also when two requests arrive at the same moment or from two devices. result_type /
 * result_id point at what the first submission created, so a repeat can show that instead.
 *
 * Nothing else in the database changes. Rows older than 7 days can be deleted at any time
 * (OnceToken::purgeOlderThan(), run by the weekly DSS run and `php spark forms:purge`).
 */
class CreateFormSubmissions extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('form_submissions')) {
            return;
        }

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'submission_key' => ['type' => 'CHAR', 'constraint' => 32],
            'action'         => ['type' => 'VARCHAR', 'constraint' => 40],
            'user_id'        => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'result_type'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'result_id'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'created_at'     => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('submission_key');
        $this->forge->addKey('created_at');
        $this->forge->createTable('form_submissions', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);
    }

    public function down()
    {
        $this->forge->dropTable('form_submissions', true);
    }
}
