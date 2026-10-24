<?php

declare(strict_types=1);

namespace App\Console\Commands\Maker;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

abstract class MakeCommand extends Command
{
    protected function prepareDirectory(string $dirPath, string $askMessage): bool
    {
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) { // @phpstan-ignore booleanNot.alwaysFalse, booleanAnd.alwaysFalse (mkdir race condition pattern)
                $this->error('無法建立目錄 ' . $dirPath . '。');

                return false;
            }

            return true;
        }

        $force = $this->confirm($askMessage . ' 是否刪除後重建？');
        if (!$force) {
            $this->error('操作已取消。');

            return false;
        }

        $this->line('正在刪除目錄 ' . $dirPath . ' 及其內容...');
        try {
            $this->deleteDirectory($dirPath);
        } catch (Exception $e) {
            $this->error('刪除目錄時發生錯誤: ' . $e->getMessage());

            return false;
        }

        if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) { // @phpstan-ignore booleanNot.alwaysFalse, booleanAnd.alwaysFalse (mkdir race condition pattern)
            $this->error('無法建立目錄 ' . $dirPath . '。');

            return false;
        }

        return true;
    }

    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    protected function createFileFromStub(string $path, string $stubPath, array $replacements): bool
    {
        if (file_exists($path)) {
            if (!$this->confirm("檔案已存在，是否要覆蓋？ {$path}")) {
                $this->error("{$path} already exists!");

                return false;
            }
        }

        if (!file_exists($stubPath)) {
            $this->error("Stub file {$stubPath} not found!");

            return false;
        }

        (new Filesystem)->ensureDirectoryExists(dirname($path));

        $stub = file_get_contents($stubPath);

        foreach ($replacements as $key => $value) {
            $stub = str_replace($key, $value, $stub);
        }

        file_put_contents($path, $stub);

        return true;
    }

    protected function scaffoldFromStubs(string $basePath, string $stubDir, array $files, array $replacements): void
    {
        foreach ($files as $file) {
            $target = $basePath . '/' . $file . '.php';
            $stubPath = $stubDir . '/' . $file . '.stub';
            if ($this->createFileFromStub($target, $stubPath, $replacements)) {
                $this->info(str_replace(base_path(), '...', $target) . ' 已新增.');
            }
        }
    }
}
