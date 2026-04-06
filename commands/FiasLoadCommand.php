<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Exception;
use yii\helpers\Console;
use app\models\Address;
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
     * Пример:
     * ./yii fias/load --region=59
     * @throws Exception
     */
    public function actionLoad($region = null)
    {
        if ($region === null) {
            $this->stderr("Не указан регион. Используй --region=XX\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Начинаем загрузку ФИАС (регион: $region)\n", Console::FG_GREEN);

        $archivePath = $this->downloadArchive();
        if (!$archivePath) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->parseAndInsert($archivePath, $region);

        $this->cleanup();

        $this->stdout("Загрузка завершена\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Скачивание актуального архива GAR XML
     */
    private function downloadArchive()
    {
        $this->stdout("Получаем ссылку на архив...\n");

        $response = file_get_contents(self::FIAS_API_URL);
        if (!$response) {
            $this->stderr("Ошибка запроса к API\n");
            return false;
        }

        $data = json_decode($response, true);
        if (empty($data[0]['GarXMLFullURL'])) {
            $this->stderr("Не удалось получить ссылку на архив\n");
            return false;
        }

        $downloadUrl = $data[0]['GarXMLFullURL'];

        $this->stdout("Скачивание: $downloadUrl\n");

        $archiveFile = $this->tempDir . '/gar.zip';

        $fp = fopen($archiveFile, 'w');

        $ch = curl_init($downloadUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);
        fclose($fp);

        if ($httpCode != 200 || !file_exists($archiveFile) || filesize($archiveFile) == 0) {
            $this->stderr("Ошибка скачивания. HTTP: $httpCode\n");
            return false;
        }

        $sizeMb = round(filesize($archiveFile) / 1024 / 1024, 2);
        $this->stdout("Архив скачан: {$sizeMb} МБ\n");

        return $archiveFile;
    }

    /**
     * Парсинг напрямую из ZIP без распаковки
     * @throws Exception
     * @throws \Exception
     */
    private function parseAndInsert($archivePath, $region)
    {
        $this->stdout("Чтение из архива (stream)...\n");

        $zip = new \ZipArchive();

        if ($zip->open($archivePath) !== true) {
            $this->stderr("Не удалось открыть архив\n");
            return;
        }

        $entryName = 'AS_ADDR_OBJ.XML';
        $stream = $zip->getStream($entryName);

        if (!$stream) {
            $this->stderr("Файл $entryName не найден\n");
            return;
        }

        $reader = new XMLReader();
        $reader->open($stream);

        $batchSize = 1000;
        $batch = [];
        $total = 0;

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name === 'OBJECT') {

                $xml = $reader->readOuterXML();
                $node = new \SimpleXMLElement($xml);

                $regionCode = (string)$node['REGIONCODE'];
                if ($regionCode !== $region) {
                    continue;
                }

                if ((string)$node['ISACTUAL'] !== '1') {
                    continue;
                }

                $fullAddress = $this->buildFullAddress($node);
                if (!$fullAddress) {
                    continue;
                }

                $batch[] = [
                    'full_address' => $fullAddress,
                    'region'       => $regionCode,
                    'city'         => (string)$node['CITYCODE'] ?: null,
                    'street'       => (string)$node['STREETCODE'] ?: null,
                    'house'        => null,
                    'created_at'   => date('Y-m-d H:i:s'),
                ];

                $total++;

                if (count($batch) >= $batchSize) {
                    $this->insertBatch($batch);
                    $batch = [];
                    $this->stdout("Обработано: $total\r");
                }
            }
        }

        if (!empty($batch)) {
            $this->insertBatch($batch);
        }

        $reader->close();
        $zip->close();

        $this->stdout("\nВставлено записей: $total\n", Console::FG_GREEN);
    }

    /**
     * Batch insert
     * @throws Exception
     */
    private function insertBatch($rows)
    {
        if (empty($rows)) return;

        $columns = ['full_address', 'region', 'city', 'street', 'house', 'created_at'];

        Yii::$app->db->createCommand()
            ->batchInsert(Address::tableName(), $columns, $rows)
            ->execute();
    }

    /**
     * Простейшая сборка адреса
     */
    private function buildFullAddress($node)
    {
        $parts = [];

        if (!empty((string)$node['REGIONNAME'])) {
            $parts[] = (string)$node['REGIONNAME'];
        }

        if (!empty((string)$node['CITYNAME'])) {
            $parts[] = (string)$node['CITYNAME'];
        }

        if (!empty((string)$node['STREETNAME'])) {
            $parts[] = (string)$node['STREETNAME'];
        }

        return implode(', ', $parts);
    }

    /**
     * Очистка временных файлов
     */
    private function cleanup()
    {
        $this->stdout("Очистка...\n");

        $this->delTree($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    private function delTree($dir)
    {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->delTree($path) : unlink($path);
        }

        rmdir($dir);
    }
}