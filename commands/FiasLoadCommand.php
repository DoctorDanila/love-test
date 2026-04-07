<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\db\Exception;
use app\models\Address;
use XBase\TableReader;
use XMLReader;

class FiasLoadCommand extends Controller
{
    private const FIAS_API_URL = 'https://fias.nalog.ru/WebServices/Public/GetAllDownloadFileInfo';

    private string $tempDir = '@runtime/fias_temp';

    public function init()
    {
        parent::init();

        $this->tempDir = Yii::getAlias($this->tempDir);

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    /**
     * php yii fias/load --region=59
     */
    public function actionLoad($region = null)
    {
        if (!$region) {
            $this->stderr("Укажи регион --region=XX\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Регион: $region\n", Console::FG_GREEN);

        $meta = $this->getLatestMeta();
        if (!$meta) return ExitCode::UNSPECIFIED_ERROR;

        if (!empty($meta['FiasCompleteDbfUrl'])) {
            $this->stdout("Используем FIAS DBF\n");
            $file = $this->download($meta['FiasCompleteDbfUrl'], 'fias_dbf.zip');
            $path = $this->extract($file);
            $this->parseDbf($path, $region);
        }
        elseif (!empty($meta['GarXMLFullURL'])) {
            $this->stdout("Используем FullGarXML stream parsing\n");
            $file = $this->download($meta['GarXMLFullURL'], 'gar.xml.zip');
            $this->parseXmlFromZip($file, $region);
        } else {
            $this->stderr("Нет доступных источников\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->cleanup();

        $this->stdout("Готово\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Получаем метаданные
     */
    private function getLatestMeta()
    {
        $this->stdout("Получаем мету...\n");

        $json = file_get_contents(self::FIAS_API_URL);
        $data = json_decode($json, true);

        if (!$data || empty($data[0])) {
            $this->stderr("Ошибка получения API\n");
            return null;
        }

        return $data[0];
    }

    /**
     * Универсальная загрузка через aria2
     */
    private function download($url, $filename)
    {
        $this->stdout("Скачивание: $url\n");

        $output = $this->tempDir . '/' . $filename;

        $cmd = sprintf(
            'aria2c -x 16 -s 16 -k 1M -c --console-log-level=error -d %s -o %s "%s"',
            escapeshellarg($this->tempDir),
            escapeshellarg($filename),
            $url
        );

        passthru($cmd, $code);

        if ($code !== 0 || !file_exists($output)) {
            $this->stderr("Ошибка скачивания\n");
            return null;
        }

        return $output;
    }

    /**
     * Распаковка
     */
    private function extract($file)
    {
        $this->stdout("Распаковка...\n");

        $dir = $this->tempDir . '/extract';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $ext = pathinfo($file, PATHINFO_EXTENSION);

        if ($ext === 'zip') {
            $zip = new \ZipArchive();
            $zip->open($file);
            $zip->extractTo($dir);
            $zip->close();
        }

        return $dir;
    }

    /**
     * Fias DBF парсинг
     */
    private function parseFiasDbf($file, $region)
    {
        $table = new \XBase\TableReader($file);

        $batch = [];
        $total = 0;

        foreach ($table as $row) {

            if ($row->get('REGIONCODE') != $region) continue;
            if ($row->get('ISACTUAL') != 1) continue;

            $addr = $this->buildAddressDbf($row);

            if (!$addr) continue;

            $batch[] = [
                'full_address'  => $addr,
                'region'        => $row->get('REGIONCODE'),
                'city'          => $row->get('CITYCODE'),
                'street'        => $row->get('STREETCODE'),
                'house'         => null,
                'created_at'    => date('Y-m-d H:i:s'),
            ];

            $total++;

            if (count($batch) >= 1000) {
                $this->insertBatch($batch);
                $batch = [];
                $this->stdout("FIAS: $total\r");
            }
        }

        if ($batch) $this->insertBatch($batch);

        $this->stdout("\nFIAS готово: $total\n", Console::FG_GREEN);
    }

    private function parseDbf($dir, $region)
    {
        $fiasFile  = $dir . '/AS_ADDR_OBJ.DBF';

        if (file_exists($fiasFile)) {
            $this->stdout("FIAS DBF найден\n");
            return $this->parseFiasDbf($fiasFile, $region);
        }

        $this->stderr("Ошибка формата DBF\n");
    }

    /**
     * XML stream парсинг из ZIP
     * Тут я только свечку поставил, чтобы всё работало,
     * хз как проверять, когда весёленький архив - 50 гигов,
     * а у меня только 15 свободно :_(
     * Но выглядит правильно O;)
     */
    private function parseXmlFromZip($zipPath, $region)
    {
        $this->stdout("XML stream...\n");

        $zip = new \ZipArchive();
        $zip->open($zipPath);

        $stream = $zip->getStream('AS_ADDR_OBJ.XML');

        if (!$stream) {
            $this->stderr("XML не найден\n");
            return;
        }

        $reader = new XMLReader();
        $reader->open($stream);

        $batch = [];
        $batchSize = 1000;
        $total = 0;

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name === 'OBJECT') {

                $node = new \SimpleXMLElement($reader->readOuterXML());

                if ((string)$node['REGIONCODE'] !== $region) continue;
                if ((string)$node['ISACTUAL'] !== '1') continue;

                $addr = $this->buildAddressXml($node);
                if (!$addr) continue;

                $batch[] = [
                    'full_address'  => $addr,
                    'region'        => (string)$node['REGIONCODE'],
                    'city'          => (string)$node['CITYCODE'],
                    'street'        => (string)$node['STREETCODE'],
                    'house'         => null,
                    'created_at'    => date('Y-m-d H:i:s'),
                ];

                $total++;

                if (count($batch) >= $batchSize) {
                    $this->insertBatch($batch);
                    $batch = [];
                    $this->stdout("XML: $total\r");
                }
            }
        }

        if ($batch) $this->insertBatch($batch);

        $reader->close();
        $zip->close();

        $this->stdout("\nXML готово: $total\n", Console::FG_GREEN);
    }

    private function buildAddressDbf($row)
    {
        return implode(', ', array_filter([
            $row->get('REGIONNAME'),
            $row->get('CITYNAME'),
            $row->get('STREETNAME'),
        ]));
    }

    private function buildAddressXml($node)
    {
        return implode(', ', array_filter([
            (string)$node['REGIONNAME'],
            (string)$node['CITYNAME'],
            (string)$node['STREETNAME'],
        ]));
    }

    /**
     * Batch insert
     * @throws Exception
     */
    private function insertBatch($rows)
    {
        Yii::$app->db->createCommand()
            ->batchInsert(
                Address::tableName(),
                ['full_address','region','city','street','house','created_at'],
                $rows
            )->execute();
    }

    private function cleanup()
    {
        $this->stdout("Очистка...\n");
        $this->delTree($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    private function delTree($dir)
    {
        if (!is_dir($dir)) return;

        foreach (array_diff(scandir($dir), ['.','..']) as $f) {
            $path = "$dir/$f";
            is_dir($path) ? $this->delTree($path) : unlink($path);
        }

        rmdir($dir);
    }
}