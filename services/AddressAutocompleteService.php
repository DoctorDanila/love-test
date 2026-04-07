<?php

namespace app\services;

use app\repositories\AddressRepository;
use app\dto\AddressSuggestionDto;
use yii\caching\CacheInterface;

class AddressAutocompleteService
{
    private AddressRepository $repository;
    private CacheInterface $cache;
    private int $fastTtl = 3600;
    private int $slowTtl = 21600;

    public function __construct(AddressRepository $repository, CacheInterface $cache)
    {
        $this->repository = $repository;
        $this->cache = $cache;
    }

    /**
     * Быстрый префиксный поиск с кэшированием
     */
    public function getSuggestionsFast(string $query, int $limit): array
    {
        $normalized = $this->normalizeQuery($query);
        if (mb_strlen($normalized) < 2) {
            return [];
        }

        $cacheKey = $this->buildCacheKey('fast', $normalized, $limit);
        $result = $this->cache->get($cacheKey);
        if ($result === false) {
            $result = $this->repository->searchByPrefix($normalized, $limit);
            $this->cache->set($cacheKey, $result, $this->fastTtl);
        }
        return $result;
    }

    /**
     * Медленный поиск по сходству с кэшированием (для опечаток, пунктуации)
     */
    public function getSuggestionsSlow(string $query, int $limit): array
    {
        $normalized = $this->normalizeQuery($query);
        if (mb_strlen($normalized) < 3) {
            return [];
        }

        $cacheKey = $this->buildCacheKey('slow', $normalized, $limit);
        $result = $this->cache->get($cacheKey);
        if ($result === false) {
            $result = $this->repository->searchBySimilarity($normalized, $limit);
            $this->cache->set($cacheKey, $result, $this->slowTtl);
        }
        return $result;
    }

    public function getAddress(int $id): ?array
    {
        $cacheKey = "address:{$id}";
        $data = $this->cache->get($cacheKey);
        if ($data === false) {
            $address = $this->repository->findById($id);
            if (!$address) {
                return null;
            }
            $data = $address->toArray();
            $this->cache->set($cacheKey, $data, $this->fastTtl);
        }
        return $data;
    }

    public function getStats(): array
    {
        $cacheKey = 'address:stats';
        $stats = $this->cache->get($cacheKey);
        if ($stats === false) {
            $total = $this->repository->getTotalCount();
            $stats = [
                'total' => $total,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $this->cache->set($cacheKey, $stats, 86400); // 24 часа
        }
        return $stats;
    }

    private function normalizeQuery(string $query): string
    {
        return trim(mb_strtolower($query));
    }

    private function buildCacheKey(string $type, string $query, int $limit): string
    {
        return "autocomplete:{$type}:" . md5($query) . ":{$limit}";
    }
}