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
        $normalizedQuery = $this->normalizeString($query);

        if (mb_strlen($normalizedQuery) < 2) {
            return [];
        }

        $rows = Address::find()
            ->select(['id', 'full_address'])
            ->where('similarity(full_address, :q) > 0.3', [':q' => $normalizedQuery])
            ->orderBy(['similarity(full_address, :q)' => SORT_DESC])
            ->limit($limit)
            ->asArray()
            ->all();

        if (empty($rows)) {
            $rows = Address::find()
                ->select(['id', 'full_address'])
                ->where(['ilike', 'full_address', $normalizedQuery . '%', false])
                ->limit($limit)
                ->asArray()
                ->all();
        }

        return array_map(fn($row) => new AddressSuggestionDto($row['id'], $row['full_address']), $rows);
    }

    private function normalizeString(string $str): string
    {
        $str = mb_strtolower(trim($str));
        $str = preg_replace('/[[:punct:]]/', ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return $str;
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