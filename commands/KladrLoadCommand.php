<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use app\models\Address;
use XBase\TableReader;

class KladrLoadCommand extends Controller
{
    private const FIAS_API_URL = 'https://fias.nalog.ru/WebServices/Public/GetAllDownloadFileInfo';
    private string $tempDir = '@runtime/kladr_temp';
    private array $kladrDict = [];

    public function init()
    {
        parent::init();
        $this->tempDir = Yii::getAlias($this->tempDir);
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    /**
     * Загрузка КЛАДР (улицы + дома).
     * Примеры:
     *   php yii kladr/load 00 --local=/path/to/kladr.7z   # все регионы
     *   php yii kladr/load 59 --local=/path/to/kladr.7z   # только Пермский край
     */
    public function actionLoad($region = null, $local = null)
    {
        if (!$region) {
            $this->stderr("Укажите регион для фильтрации (00 — все регионы): php yii kladr/load 59 [--local=/path/to/kladr.7z]\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Фильтр региона: $region (00 — без фильтра)\n", Console::FG_GREEN);

        $archivePath = null;
        if ($local !== null) {
            if ($local === '') {
                $local = $this->tempDir . '/kladr.7z';
            }
            if (!file_exists($local)) {
                $this->stderr("Локальный файл не найден: $local\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $archivePath = $local;
            $this->stdout("Используем локальный архив: $archivePath\n", Console::FG_GREEN);
        } else {
            $meta = $this->getMeta();
            if (!$meta || empty($meta['Kladr47ZUrl'])) {
                $this->stderr("Не удалось получить ссылку на КЛАДР. Используйте --local\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $archivePath = $this->download($meta['Kladr47ZUrl'], 'kladr.7z');
            if (!$archivePath) return ExitCode::UNSPECIFIED_ERROR;
        }

        $extractDir = $this->extract($archivePath);
        if (!$extractDir) return ExitCode::UNSPECIFIED_ERROR;

        $kladrFile = $this->findFile($extractDir, 'KLADR.DBF');
        $streetFile = $this->findFile($extractDir, 'STREET.DBF');
        $houseFile = $this->findFile($extractDir, 'DOMA.DBF');

        if (!$kladrFile || !$streetFile) {
            $this->stderr("Не найдены KLADR.DBF или STREET.DBF\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->loadKladrDict($kladrFile);

        $this->parseStreets($streetFile, $region);

        if ($houseFile && file_exists($houseFile)) {
            $this->parseHouses($houseFile, $region);
        } else {
            $this->stdout("Файл DOMA.DBF не найден, дома не загружены.\n", Console::FG_YELLOW);
        }

        $this->cleanup();
        $this->stdout("Готово!\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function getMeta(): ?array
    {
        $this->stdout("Получаем метаданные...\n");
        $json = @file_get_contents(self::FIAS_API_URL);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return $data[0] ?? null;
    }

    private function download(string $url, string $filename): ?string
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
            $this->stderr("Ошибка скачивания\n", Console::FG_RED);
            return null;
        }
        return $output;
    }

    private function extract(string $archivePath): ?string
    {
        $this->stdout("Распаковка...\n");
        $dir = $this->tempDir . '/extract';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $cmd = sprintf('7z x -y -o%s %s', escapeshellarg($dir), escapeshellarg($archivePath));
        exec($cmd, $output, $code);
        if ($code !== 0) {
            $this->stderr("Ошибка распаковки\n", Console::FG_RED);
            return null;
        }
        return $dir;
    }

    private function findFile(string $dir, string $filename): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (strtoupper($file->getFilename()) === strtoupper($filename)) {
                return $file->getPathname();
            }
        }
        return null;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        $converted = @mb_convert_encoding($value, 'UTF-8', 'CP866');
        if ($converted === false || $converted === '') {
            $converted = @iconv('CP866', 'UTF-8//IGNORE', $value);
        }
        return trim($converted);
    }

    private function loadKladrDict(string $kladrFile): void
    {
        $this->stdout("Загрузка словаря KLADR...\n");
        $table = new TableReader($kladrFile);
        $this->stdout("Записей: " . $table->getRecordCount() . "\n");

        while ($record = $table->nextRecord()) {
            $code = $record->get('code');
            if (!$code) continue;
            $name = $this->normalize($record->get('name'));
            $socr = $this->normalize($record->get('socr'));
            if ($name === null) continue;
            $fullName = ($socr ? $socr . ' ' : '') . $name;
            $this->kladrDict[$code] = $fullName;
        }
        $this->stdout("Загружено объектов: " . count($this->kladrDict) . "\n", Console::FG_GREEN);
    }

    private function buildFullAddress(string $code): string
    {
        $parts = [];
        $currentCode = $code;
        while (strlen($currentCode) > 0) {
            if (isset($this->kladrDict[$currentCode])) {
                array_unshift($parts, $this->kladrDict[$currentCode]);
                $currentCode = substr($currentCode, 0, -3);
            } else {
                $currentCode = substr($currentCode, 0, -1);
            }
            if (strlen($currentCode) === 2) {
                if (isset($this->kladrDict[$currentCode])) {
                    array_unshift($parts, $this->kladrDict[$currentCode]);
                }
                break;
            }
            if (strlen($currentCode) === 0) break;
        }
        return implode(', ', $parts);
    }

    /**
     * Парсинг улиц с пакетной вставкой.
     */
    private function parseStreets(string $streetFile, string $filterRegion): void
    {
        $this->stdout("Парсинг улиц...\n");
        $table = new TableReader($streetFile);
        $recordCount = $table->getRecordCount();
        $this->stdout("Записей в STREET.DBF: $recordCount\n");

        $batch = [];
        $total = 0;
        $batchSize = 1000;
        $lastReport = 0;

        while ($record = $table->nextRecord()) {
            $code = $record->get('code');
            if (!$code) continue;

            $realRegion = substr($code, 0, 2);
            if ($filterRegion !== '00' && $realRegion != $filterRegion) continue;

            $shortCode = substr($code, 0, 13);
            $cityAddress = $this->buildFullAddress($shortCode);
            if (empty($cityAddress)) continue;

            $streetName = $this->normalize($record->get('name'));
            $cityName = $this->kladrDict[$shortCode] ?? null;
            $fullAddress = $cityAddress . ($streetName ? ', ' . $streetName : '');

            $batch[] = [
                'full_address' => $fullAddress,
                'region'       => $realRegion,
                'city'         => $cityName,
                'street'       => $streetName,
                'house'        => null,
                'created_at'   => date('Y-m-d H:i:s'),
            ];
            $total++;

            if ($total - $lastReport >= 10000) {
                $this->stdout("Обработано улиц: $total\r");
                $lastReport = $total;
            }

            if (count($batch) >= $batchSize) {
                $this->insertBatch($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) $this->insertBatch($batch);
        $this->stdout("\nУлиц загружено: $total\n", Console::FG_GREEN);
    }

    /**
     * Парсинг домов с пакетной вставкой.
     */
    private function parseHouses(string $houseFile, string $filterRegion): void
    {
        $this->stdout("Парсинг домов...\n");
        $table = new TableReader($houseFile);
        $recordCount = $table->getRecordCount();
        $this->stdout("Записей в DOMA.DBF: $recordCount\n");

        $batch = [];
        $total = 0;
        $batchSize = 1000;
        $lastReport = 0;

        while ($record = $table->nextRecord()) {
            $code = $record->get('code');
            if (!$code) continue;

            $realRegion = substr($code, 0, 2);
            if ($filterRegion !== '00' && $realRegion != $filterRegion) continue;

            $streetCode = substr($code, 0, 13);
            $streetAddress = $this->buildFullAddress($streetCode);
            if (empty($streetAddress)) continue;

            $houseNumber = $this->normalize($record->get('name'));
            if (empty($houseNumber)) continue;

            $fullAddress = $streetAddress . ', д. ' . $houseNumber;

            $batch[] = [
                'full_address' => $fullAddress,
                'region'       => $realRegion,
                'city'         => null,
                'street'       => null,
                'house'        => $houseNumber,
                'created_at'   => date('Y-m-d H:i:s'),
            ];
            $total++;

            if ($total - $lastReport >= 10000) {
                $this->stdout("Обработано домов: $total\r");
                $lastReport = $total;
            }

            if (count($batch) >= $batchSize) {
                $this->insertBatch($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) $this->insertBatch($batch);
        $this->stdout("\nДомов загружено: $total\n", Console::FG_GREEN);
    }

    private function insertBatch(array $rows): void
    {
        Yii::$app->db->createCommand()->batchInsert(
            Address::tableName(),
            ['full_address', 'region', 'city', 'street', 'house', 'created_at'],
            $rows
        )->execute();
    }

    private function cleanup(): void
    {
        $this->stdout("Очистка...\n");
        $this->delTree($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    private function delTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->delTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}