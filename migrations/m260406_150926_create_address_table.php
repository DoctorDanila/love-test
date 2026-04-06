<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%address}}`.
 */
class m260406_150926_create_address_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%address}}', [
            'id'            => $this->primaryKey(),
            'full_address'  => $this->text()->notNull()->comment('Полный адрес'),
            'region'        => $this->string(255)->null()->comment('Регион'),
            'city'          => $this->string(255)->null()->comment('Город/Населенный пункт'),
            'street'        => $this->string(255)->null()->comment('Улица'),
            'house'         => $this->string(50)->null()->comment('Номер дома'),
            'created_at'    => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP')->comment('Дата создания'),
        ]);

        $this->createIndex('idx_address_full_address', '{{%address}}', 'full_address');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%address}}');
    }
}
