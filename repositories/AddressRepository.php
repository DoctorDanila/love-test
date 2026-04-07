<?php

namespace app\repositories;

use app\models\Address;
use app\dto\AddressSuggestionDto;

class AddressRepository
{
    /**
     * Быстрый префиксный поиск (использует индекс idx_address_lower_prefix)
     */
    public function searchByPrefix(string $query, int $limit): array
    {
        $normalized = $this->normalizeString($query);
        if (mb_strlen($normalized) < 2) {
            return [];
        }

        $rows = Address::find()
            ->select(['id', 'full_address'])
            ->where('lower(full_address) LIKE :prefix', [':prefix' => $normalized . '%'])
            ->orderBy(['full_address' => SORT_ASC])
            ->limit($limit)
            ->asArray()
            ->all();

        return array_map(fn($row) => new AddressSuggestionDto($row['id'], $row['full_address']), $rows);
    }

    /**
     * Медленный поиск по сходству (для опечаток, пунктуации)
     * Использует GIN-индекс pg_trgm
     */
    public function searchBySimilarity(string $query, int $limit): array
    {
        $normalized = $this->normalizeString($query);
        if (mb_strlen($normalized) < 3) {
            return [];
        }

        $rows = Address::find()
            ->select(['id', 'full_address'])
            ->where('full_address % :q', [':q' => $normalized])
            ->orderBy(['similarity(full_address, :q)' => SORT_DESC])
            ->limit($limit)
            ->asArray()
            ->all();

        return array_map(fn($row) => new AddressSuggestionDto($row['id'], $row['full_address']), $rows);
    }

    private function normalizeString(string $str): string
    {
        $str = trim(mb_strtolower($str));
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

    public function getTotalCount(): int
    {
        return Address::find()->count();
    }
}