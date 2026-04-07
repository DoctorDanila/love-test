<?php

namespace app\controllers;

use app\services\AddressAutocompleteService;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Controller;
use yii\web\Response;
use yii\web\NotFoundHttpException;

class AddressController extends Controller
{
    private AddressAutocompleteService $autocompleteService;


    /**
     * @throws InvalidConfigException
     */
    public function __construct($id, $module, $config = [])
    {
        $this->autocompleteService = Yii::createObject(AddressAutocompleteService::class);
        parent::__construct($id, $module, $config);
    }

    /**
     * Быстрый префиксный поиск
     */
    public function actionAutocomplete($q = null, $limit = 10)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $q = trim((string)$q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $limit = min(max((int)$limit, 1), 50);
        $suggestions = $this->autocompleteService->getSuggestionsFast($q, $limit);
        return array_map(fn($dto) => $dto->toArray(), $suggestions);
    }

    /**
     * Медленный similarity‑поиск (для опечаток, пунктуации)
     * Вызывается асинхронно, не блокирует интерфейс.
     */
    public function actionAutocompleteSlow($q = null, $limit = 10)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $q = trim((string)$q);
        if (mb_strlen($q) < 3) {
            return [];
        }

        $limit = min(max((int)$limit, 1), 50);
        $suggestions = $this->autocompleteService->getSuggestionsSlow($q, $limit);
        return array_map(fn($dto) => $dto->toArray(), $suggestions);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionView($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $data = $this->autocompleteService->getAddress((int)$id);
        if (!$data) {
            throw new NotFoundHttpException('Адрес не найден');
        }
        return $data;
    }

    public function actionStats()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return $this->autocompleteService->getStats();
    }
}