<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 抽象同步 Seeder 類別
 *
 * 此類別提供同步資料到資料庫的功能，支援多語系關聯、批次插入與測試模式。
 * 子類別需設定 $dataFile、$modelClass、$uniqueKey 等屬性。
 */
abstract class AbstractSyncSeeder extends Seeder
{
    /** @var string 資料檔案路徑（如 vendors.php） */
    protected string $dataFile;

    /** @var string Model 類名（如 Vendor::class） */
    protected string $modelClass;

    /** @var string Model 的多語系關聯方法名稱 */
    protected string $langRelation;

    /** @var string 多語系關聯的外鍵欄位名稱 */
    protected string $langRelationKey;

    /** @var string 唯一鍵欄位名稱（如 id/code） */
    protected string $uniqueKey;

    /** @var array 預設值陣列 */
    protected array $default = [];

    /** @var bool 是否為測試模式，測試模式下不會真正寫入資料庫 */
    protected bool $isTest = false;

    /**
     * 執行同步操作
     *
     * @return int 新增的資料筆數
     */
    public function run(): int
    {
        $output = $this->command?->getOutput();
        $output?->writeln(static::class . ' 開始執行...');

        $source = $this->loadSource();
        $model = $this->getModel();
        $existing = $this->getExisting($model);

        // 建構待插入資料
        $updated = 0;
        $toInsert = $this->buildToInsert($source, $existing, $updated);

        if (empty($toInsert)) {
            $output?->writeln(static::class . ' 無需更新，已結束。');

            return $updated;
        }

        try {
            DB::beginTransaction();

            [$prepared, $hasLanguage] = $this->prepareItems($toInsert);

            if ($hasLanguage) {
                $this->processCreateWithLanguage($prepared, $model);
            } else {
                $this->processBatchInsert($prepared, $model);
            }

            if ($this->isTest) {
                $output?->writeln('測試模式，所有操作不會寫入資料庫，已回滾。');
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $output?->writeln('發生錯誤，已回滾。錯誤訊息: ' . $e->getMessage());

            return $updated;
        }

        $output?->writeln(static::class . ' 已執行完成。');
        $output?->writeln("更新 {$updated} 筆資料");

        return $updated;
    }

    // ---------- 資料載入與模型方法 ----------

    /**
     * 載入資料來源
     *
     * @return array 資料陣列
     */
    protected function loadSource(): array
    {
        return require database_path('data/' . $this->dataFile);
    }

    /**
     * 取得 Model 實例
     */
    protected function getModel(): Model
    {
        return app($this->modelClass);
    }

    /**
     * 取得現有資料的唯一鍵值陣列
     */
    protected function getExisting(Model $model): array
    {
        return $model->pluck($this->uniqueKey)->toArray();
    }

    // ---------- 資料處理方法 ----------

    /**
     * 建構待插入陣列，回傳每筆包含 data 與 unique
     * 同時會更新 $updated（已新增數量）
     *
     * @param  int  $updated  (by reference)
     */
    protected function buildToInsert(array $source, array $existing, int &$updated): array
    {
        $toInsert = [];

        foreach ($this->getDataCollection($source) as $key => $data) {
            $insertData = $this->formatInsertData($data, $key);

            if (!$this->isValid($data, $key)) {
                continue;
            }

            $uniqueValue = $this->getUniqueValue($data, $key);
            if (!in_array($uniqueValue, $existing, true)) {
                $toInsert[] = [
                    'data' => $insertData,
                    'unique' => $uniqueValue,
                ];

                $this->command?->getOutput()?->writeln($this->getLogMessage($data, $key));
                $updated++;
            }
        }

        return $toInsert;
    }

    /**
     * 將每筆項目準備好，並檢查是否含 language_texts
     * 回傳 [preparedArray, hasLanguage:boolean]
     */
    protected function prepareItems(array $toInsert): array
    {
        $prepared = [];
        $hasLanguage = false;

        foreach ($toInsert as $item) {
            $row = $item['data'];
            $unique = $item['unique'];

            $texts = null;
            if (isset($row['language_texts'])) {
                $texts = $row['language_texts'];
                unset($row['language_texts']);
                $hasLanguage = true;
            }

            $prepared[] = [
                'data' => $row,
                'unique' => $unique,
                'language_texts' => $texts,
            ];
        }

        return [$prepared, $hasLanguage];
    }

    // ---------- 資料庫操作方法 ----------

    /**
     * 逐筆建立資料並處理多語系
     */
    protected function processCreateWithLanguage(array $prepared, Model $model): void
    {
        foreach ($prepared as $item) {
            $row = $item['data'];

            /** @var Model $instance */
            $instance = $model->create($row);

            $texts = $item['language_texts'] ?? [];
            if (!empty($texts)) {
                $this->createLanguageTextsFor($instance, $texts);
            }
        }
    }

    /**
     * 批次插入資料
     */
    protected function processBatchInsert(array $prepared, Model $model): void
    {
        $rows = array_map(fn ($i) => $i['data'], $prepared);

        if (!empty($rows)) {
            $model->insert($rows);
        }
    }

    // ---------- 資料驗證與格式化方法 ----------

    /**
     * 取得資料集合，預設直接用 array
     */
    protected function getDataCollection(array $source): iterable
    {
        return $source;
    }

    /**
     * 判斷資料是否有效，預設只判斷非空
     *
     * @param  mixed  $key
     */
    protected function isValid(array $data, $key): bool
    {
        return !empty($data);
    }

    /**
     * 取得唯一鍵值，預設直接取 uniqueKey
     *
     * @param  mixed  $key
     * @return mixed
     */
    protected function getUniqueValue(array $data, $key)
    {
        return $data[$this->uniqueKey] ?? $key;
    }

    /**
     * 格式化插入資料，預設直接回傳
     *
     * @param  mixed  $key
     */
    protected function formatInsertData(array $data, $key): array
    {
        $data = array_merge($this->default, $data);

        return $data;
    }

    /**
     * 取得日誌訊息，預設顯示 uniqueKey
     *
     * @param  mixed  $key
     */
    protected function getLogMessage(array $data, $key): string
    {
        return "新增 {$this->uniqueKey}: " . ($data[$this->uniqueKey] ?? $key);
    }

    // ---------- 多語系處理方法 ----------

    /**
     * 建立多語系關聯資料，接受已建檔的 Model 實例
     */
    protected function createLanguageTextsFor(Model $instance, array $languageTexts = []): void
    {
        if (empty($languageTexts) || empty($this->langRelation)) {
            return;
        }

        // 檢查關聯方法是否存在
        if (!method_exists($instance, $this->langRelation)) {
            $this->command?->getOutput()?->writeln("警告：關聯方法 '{$this->langRelation}' 不存在於 " . get_class($instance) . ' 中，跳過語系建立。');

            return;
        }

        $languageTexts = $this->prepareLanguageTexts($instance, $languageTexts);

        $instance->{$this->langRelation}()->createMany($languageTexts);
    }

    /**
     * 準備多語系資料
     */
    protected function prepareLanguageTexts(Model $instance, array $languageTexts = []): array
    {
        if (!is_array($languageTexts)) {
            $this->command?->getOutput()?->writeln('警告：languageTexts 不是陣列，跳過處理。');

            return [];
        }

        $data = [];
        foreach ($languageTexts as $languageId => $languageText) {
            if (!is_string($languageText) && !is_numeric($languageText)) {
                $this->command?->getOutput()?->writeln("警告：languageText 必須是字串或數字，跳過 languageId: {$languageId}。");

                continue;
            }

            $data[] = [
                $this->langRelationKey => $instance->id,
                'language_id' => $languageId,
                'name' => $languageText,
                'admin' => 0,
            ];
        }

        return $data;
    }
}
