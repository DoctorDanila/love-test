<?php

namespace app\controllers;

use yii\base\NotSupportedException;
use yii\web\Controller;

class SiteController extends Controller
{
    public function actionIndex()
    {
        return $this->render('index');
    }
}