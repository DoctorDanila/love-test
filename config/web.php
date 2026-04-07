<?php

$params = require __DIR__ . '/params.php';
$db     = require __DIR__ . '/db.php';
$redis  = require __DIR__ . '/redis.php';

$config = [
    'id' => 'basic',
    'name' => $_ENV['APP_NAME'],
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => $_ENV['COOKIE_VALIDATION_KEY'],
        ],
        'redis' =>$redis,
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis' => 'redis',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 1 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                    'except' => [
                        'yii\web\HttpException:404',
                    ],
                ],
            ],
        ],
        'db' => $db,
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET address/autocomplete' => 'address/autocomplete',
                'GET address/<id:\d+>'     => 'address/view',
                ''                         => 'site/index',
            ],
        ],
    ],
    'container' => [
        'definitions' => [
            app\services\AddressAutocompleteService::class => function () {
                return new app\services\AddressAutocompleteService(
                    new app\repositories\AddressRepository(),
                    Yii::$app->cache
                );
            },
        ],
    ],
    'params' => $params,
];

return $config;
