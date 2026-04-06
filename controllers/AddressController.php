<?php

namespace app\controllers;

use app\repositories\AddressRepository;
use app\services\AddressAutocompleteService;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Controller;
use yii\web\Response;
use yii\web\NotFoundHttpException;

class AddressController extends Controller
{
    private AddressAutocompleteService $autocompleteService;
    private AddressRepository $addressRepository;


    /**
     * @throws InvalidConfigException
     */
    public function __construct($id, $module, $config = [])
    {
        $this->autocompleteService  = Yii::createObject(AddressAutocompleteService::class);
        $this->addressRepository    = Yii::createObject(AddressRepository::class);
        parent::__construct($id, $module, $config);
    }

    public function actionAutocomplete($q = null, $limit = 10)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if ($q === null || trim($q) === '') {
            return [];
        }

        $limit = (int)$limit;
        if ($limit <= 0 || $limit > 50) {
            $limit = 10;
        }

        $suggestions = $this->autocompleteService->getSuggestions($q, $limit);

        return array_map(fn($dto) => $dto->toArray(), $suggestions);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionView($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $address = $this->addressRepository->findById((int)$id);
        if (!$address) {
            throw new NotFoundHttpException('Адрес не найден');
        }

        return $address->toArray();
    }
}