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

    public function actionLoad($region = null, $local = null)
    {
        if (!$region) {
            $this->stderr("Укажите регион: php yii kladr/load 59 [--local=/path/to/kladr.7z]\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Регион: $region\n", Console::FG_GREEN);

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
            if (!$meta) {
                $this->stderr("Не удалось получить метаданные от API. Попробуйте --local\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            if (empty($meta['Kladr47ZUrl'])) {
                $this->stderr("В метаданных нет ссылки на КЛАДР\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $archivePath = $this->download($meta['Kladr47ZUrl'], 'kladr.7z');
            if (!$archivePath) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $extractDir = $this->extract($archivePath);
        if (!$extractDir) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $kladrFile = $this->findFile($extractDir, 'KLADR.DBF');
        $streetFile = $this->findFile($extractDir, 'STREET.DBF');

        if (!$kladrFile || !$streetFile) {
            $this->stderr("Не найдены KLADR.DBF или STREET.DBF\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->loadKladrDict($kladrFile);
        $this->parseStreets($streetFile, $region);

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

    /**
     * Нормализация кодировки из CP866 в UTF-8.
     * Приоритет: mb_convert_encoding -> iconv -> оставить как есть.
     */
    private function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') return null;

        // Пробуем mb_convert_encoding с CP866
        $encodings = ['CP866', 'Windows-1251', 'KOI8-R', 'UTF-8'];
        foreach ($encodings as $enc) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $enc);
            if ($converted !== false && $converted !== '' && $converted !== $value) {
                return trim($converted);
            }
        }
        // Если ничего не помогло, возвращаем как есть
        return trim($value);
    }

    private function loadKladrDict(string $kladrFile): void
    {
        $this->stdout("Загрузка словаря KLADR...\n");
        $this->stdout("Файл: $kladrFile\n");
        $this->stdout("Размер: " . filesize($kladrFile) . " байт\n");

        try {
            $table = new TableReader($kladrFile);
            $recordCount = $table->getRecordCount();
            $this->stdout("Записей: $recordCount\n");

            $columns = $table->getColumns();
            $this->stdout("Поля: " . implode(', ', array_keys($columns)) . "\n");

            if ($recordCount == 0) return;

            $count = 0;
            $i = 0;
            while ($record = $table->nextRecord()) {
                if ($i < 5) {
                    $this->stdout(sprintf(
                        "Запись %d: code='%s', name='%s', socr='%s'\n",
                        $i,
                        $record->get('code'),
                        $record->get('name'),
                        $record->get('socr')
                    ));
                    $i++;
                }
                $code = $record->get('code');
                if (!$code) continue;
                $name = $this->normalize($record->get('name'));
                $socr = $this->normalize($record->get('socr'));
                if ($name === null) continue;
                $fullName = ($socr ? $socr . ' ' : '') . $name;
                $this->kladrDict[$code] = $fullName;
                $count++;
            }
            $this->stdout("Загружено объектов: $count\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("Ошибка: " . $e->getMessage() . "\n", Console::FG_RED);
        }
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

    private function parseStreets(string $streetFile, string $region): void
    {
        $this->stdout("Парсинг улиц...\n");
        $this->stdout("Файл: $streetFile\n");
        $this->stdout("Размер: " . filesize($streetFile) . " байт\n");

        try {
            $table = new TableReader($streetFile);
            $recordCount = $table->getRecordCount();
            $this->stdout("Записей: $recordCount\n");

            $columns = $table->getColumns();
            $this->stdout("Поля: " . implode(', ', array_keys($columns)) . "\n");

            if ($recordCount == 0) return;

            $batch = [];
            $total = 0;
            $batchSize = 1000;
            $lastReport = 0;

            while ($record = $table->nextRecord()) {
                $code = $record->get('code');
                if (!$code) continue;

                // Фильтр по региону (закомментирован, можно раскомментировать позже)
                // if (substr($code, 0, 2) != $region) continue;

                // Обрезаем до 13 символов (код населённого пункта)
                $shortCode = substr($code, 0, 13);

                $cityAddress = $this->buildFullAddress($shortCode);
                if (empty($cityAddress)) {
                    continue; // пропускаем, если не удалось собрать адрес города
                }

                $streetName = $this->normalize($record->get('name'));
                $cityName = $this->kladrDict[$shortCode] ?? null;
                $fullAddress = $cityAddress . ($streetName ? ', ' . $streetName : '');

                $batch[] = [
                    'full_address' => $fullAddress,
                    'region'       => $region, // регион из параметра (может не совпадать с реальным)
                    'city'         => $cityName,
                    'street'       => $streetName,
                    'house'        => null,
                    'created_at'   => date('Y-m-d H:i:s'),
                ];
                $total++;

                // Периодический вывод прогресса
                if ($total - $lastReport >= 10000) {
                    $this->stdout("Обработано улиц: $total\r");
                    $lastReport = $total;
                }

                if (count($batch) >= $batchSize) {
                    $this->insertBatch($batch);
                    $batch = [];
                }
            }

            // Вставка оставшихся
            if (!empty($batch)) {
                $this->insertBatch($batch);
            }

            $this->stdout("\nУлиц загружено: $total\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("Ошибка: " . $e->getMessage() . "\n", Console::FG_RED);
        }
    }

    private function insertBatch(array $rows): void
    {
        $logFile = $this->tempDir . '/insert_log.txt';

        // Пишем в лог информацию о батче
        $logMessage = date('Y-m-d H:i:s') . " - Вставка " . count($rows) . " записей\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);

        // Для первых 3 записей пишем подробности
        static $loggedCount = 0;
        if ($loggedCount < 3 && !empty($rows)) {
            $sample = $rows[0];
            $details = "Пример записи:\n";
            $details .= "full_address: " . ($sample['full_address'] ?? 'NULL') . "\n";
            $details .= "region: " . ($sample['region'] ?? 'NULL') . "\n";
            $details .= "city: " . ($sample['city'] ?? 'NULL') . "\n";
            $details .= "street: " . ($sample['street'] ?? 'NULL') . "\n";
            file_put_contents($logFile, $details, FILE_APPEND);
            $loggedCount++;
        }

        // Выводим в консоль (только если есть строки)
        if (!empty($rows)) {
            $this->stdout("Вставка батча из " . count($rows) . " записей... ");
        }

        try {
            $result = Yii::$app->db->createCommand()->batchInsert(
                Address::tableName(),
                ['full_address', 'region', 'city', 'street', 'house', 'created_at'],
                $rows
            )->execute();
            if (!empty($rows)) {
                $this->stdout("OK\n");
            }
        } catch (\Exception $e) {
            $this->stderr("Ошибка вставки: " . $e->getMessage() . "\n", Console::FG_RED);
            // Записываем ошибку в лог
            file_put_contents($logFile, "Ошибка: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    private function cleanup(): void
    {
        $this->stdout("Очистка...\n");
//        $this->delTree($this->tempDir);
//        mkdir($this->tempDir, 0777, true);
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