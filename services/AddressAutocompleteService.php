<?php

namespace app\services;

use app\repositories\AddressRepository;
use app\dto\AddressSuggestionDto;
use Yii;
use yii\caching\CacheInterface;

class AddressAutocompleteService
{
    private AddressRepository $repository;
    private CacheInterface $cache;
    private int $defaultTtl = 3600; // 1 час

    public function __construct(AddressRepository $repository, CacheInterface $cache)
    {
        $this->repository   = $repository;
        $this->cache        = $cache;
    }

    /**
     * Возвращает список предложений для автокомплита с кэшированием
     * @param string $query
     * @param int $limit
     * @return AddressSuggestionDto[]
     */
    public function getSuggestions(string $query, int $limit): array
    {
        if (trim($query) === '') {
            return [];
        }

        $cacheKey   = $this->buildCacheKey($query, $limit);
        $result     = $this->cache->get($cacheKey);

        if ($result === false) {
            $result = $this->repository->search($query, $limit);
            $this->cache->set($cacheKey, $result, $this->defaultTtl);
        }

        return $result;
    }

    private function buildCacheKey(string $query, int $limit): string
    {
        return 'autocomplete:' . md5(strtolower(trim($query))) . ':' . $limit;
    }
}