<?php

use yii\db\Migration;

class m260407_023624_add_prefix_index extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function up()
    {
        $pdo = Yii::$app->db->getMasterPdo();
        $pdo->exec('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_lower_prefix ON address (lower(full_address) text_pattern_ops)');
    }

    /**
     * {@inheritdoc}
     */
    public function down()
    {
        $pdo = Yii::$app->db->getMasterPdo();
        $pdo->exec('DROP INDEX IF EXISTS idx_address_lower_prefix');
    }
}
