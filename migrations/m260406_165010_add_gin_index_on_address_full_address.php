<?php

use yii\db\Migration;

class m260406_165010_add_gin_index_on_address_full_address extends Migration
{
    public $transactional = false;

    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->execute('CREATE INDEX IF NOT EXISTS idx_address_full_address_gin ON address USING gin (full_address gin_trgm_ops)');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->execute('DROP INDEX IF EXISTS idx_address_full_address_gin');
    }
}
