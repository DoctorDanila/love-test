<?php

return [
    'class'     => 'yii\redis\Connection',
    'hostname'  => getenv('REDIS_HOST'),
    'port'      => getenv('REDIS_PORT'),
    'database'  => 0,
];