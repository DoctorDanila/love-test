<?php

namespace app\repositories;

use app\models\Address;
use app\dto\AddressSuggestionDto;
use yii\db\ActiveRecord;

class AddressRepository
{
    /**
     * Поиск адресов по началу строки (ILIKE 'query%')
     * @param string $query
     * @param int $limit
     * @return AddressSuggestionDto[]
     */
    public function search(string $query, int $limit): array
    {
        $rows = Address::find()
            ->select(['id', 'full_address'])
            ->where(['ilike', 'full_address', $query . '%', false])
            ->limit($limit)
            ->asArray()
            ->all();

        return array_map(function ($row) {
            return new AddressSuggestionDto($row['id'], $row['full_address']);
        }, $rows);
    }

    /**
     * Получение адреса по ID
     * @param int $id
     * @return Address|null
     */
    public function findById(int $id): ?Address
    {
        return Address::findOne($id);
    }
}