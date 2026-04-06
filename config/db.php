<?php

return [
    'class'     => 'yii\db\Connection',
    'dsn'       => 'pgsql:host=' . $_ENV['POSTGRES_HOST'] . ';port=' . $_ENV['POSTGRES_PORT'] . ';dbname=' . $_ENV['POSTGRES_DB'],
    'username'  => $_ENV['POSTGRES_USER'],
    'password'  => $_ENV['POSTGRES_PASSWORD'],
    'charset'   => 'utf8',

    // Schema cache options (for production environment)
    //'enableSchemaCache' => true,
    //'schemaCacheDuration' => 3600,
    //'schemaCache' => 'cache',
];
