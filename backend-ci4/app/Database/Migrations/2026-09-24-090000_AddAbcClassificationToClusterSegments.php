<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets the K-Means cluster assignment actually drive the ABC policy tier
 * and service-level Z-score used in Safety Stock / Reorder Point
 * (previously the cluster output was computed and stored but never
 * consumed downstream — see DssRun::run()).
 *
 * abc_class/service_level/z_score are cached here (not just returned by
 * the ml-service response) so DssRun can fall back to the last known
 * classification when the Flask microservice is unreachable, instead of
 * aborting the whole computation run.
 */
class AddAbcClassificationToClusterSegments extends Migration
{
    public function up()
    {
        $this->forge->addColumn('cluster_segments', [
            'abc_class' => [
                'type'       => 'CHAR',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'cluster_name',
            ],
            'service_level' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
                'after'      => 'abc_class',
            ],
            'z_score' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,3',
                'null'       => true,
                'after'      => 'service_level',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('cluster_segments', ['abc_class', 'service_level', 'z_score']);
    }
}
