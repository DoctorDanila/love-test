<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Модель для таблицы address
 *
 * @property int $id
 * @property string $full_address
 * @property string|null $region
 * @property string|null $city
 * @property string|null $street
 * @property string|null $house
 * @property string $created_at
 */
class Address extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'address';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['full_address'], 'required'],
            [['full_address'], 'string'],
            [['created_at'], 'safe'],
            [['region', 'city', 'street'], 'string', 'max' => 255],
            [['house'], 'string', 'max' => 50],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id'            => 'ID',
            'full_address'  => 'Полный адрес',
            'region'        => 'Регион',
            'city'          => 'Город',
            'street'        => 'Улица',
            'house'         => 'Дом',
            'created_at'    => 'Дата создания',
        ];
    }

    /**
     * Поиск адресов по вхождению строки
     * @param string $query
     * @return array
     */
    public static function autocomplete($query)
    {
        return self::find()
            ->select(['id', 'full_address'])
            ->where(['ilike', 'full_address', $query . '%', false])
            ->limit(10)
            ->asArray()
            ->all();
    }
}