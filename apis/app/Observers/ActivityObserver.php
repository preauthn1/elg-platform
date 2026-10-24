<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * 活動日誌觀察者
 *
 * 負責監聽模型的 created / updated / deleted 事件，
 * 根據 `config/activitylog-member-events.php` 中的規則，自動產生活動日誌。
 * 支援：
 *  - 佔位符（placeholder）替換（例如 :id, :member_id）
 *  - 欄位格式化（例如 ip_long）
 *  - 規則條件（例如 status 從某值變成某值時才記錄）
 */
class ActivityObserver
{
    public function __construct(private readonly ActivityLogService $service) {}

    /**
     * 當模型被建立時觸發
     */
    public function created(Model $model): void
    {
        $this->handleEvent($model, 'created');
    }

    /**
     * 當模型被更新時觸發
     */
    public function updated(Model $model): void
    {
        $this->handleEvent($model, 'updated');
    }

    /**
     * 當模型被刪除時觸發
     */
    public function deleted(Model $model): void
    {
        $this->handleEvent($model, 'deleted');
    }

    /**
     * 統一處理事件並記錄活動日誌的入口
     *
     * 步驟：
     *  1. 從設定檔讀取本模型 & 事件對應的規則
     *  2. 針對每一條規則判斷是否需要記錄（ruleShouldProcess）
     *  3. 若需記錄，建立 placeholder 並替換描述文字，最後呼叫 service 寫入日誌
     *
     * @param  Model  $model  觸發事件的模型實例
     * @param  string  $event  事件名稱（created / updated / deleted）
     */
    protected function handleEvent(Model $model, string $event): void
    {
        $rulesList = $this->getEventRules($model, $event);
        if ($rulesList === null) {
            // 若沒有對應規則，直接返回不做任何事
            return;
        }

        // 設定支援單一規則或多規則格式，統一處理為陣列
        if (isset($rulesList['log_name'])) {
            $rulesList = [$rulesList];
        }

        foreach ($rulesList as $rules) {
            $this->processRule($model, $event, $rules);
        }
    }

    /**
     * 處理單一條規則：先判斷是否應該執行，若應該則組裝描述並記錄日誌
     */
    private function processRule(Model $model, string $event, array $rules): void
    {
        $formatters = $rules['formatters'] ?? [];
        $attributes = $rules['attributes'] ?? [];

        // 判斷是否應該處理這條規則（例如 updated 時屬性是否有變、status 條件是否符合）
        if (!$this->ruleShouldProcess($model, $event, $attributes, $rules)) {
            return;
        }

        // 建立佔位符並生成最終描述文字
        $placeholders = $this->buildPlaceholders($model, $event, $attributes, $formatters);
        $description = $this->interpolateDescription($rules['description'] ?? $event, $placeholders);

        // 呼叫 service 寫入活動日誌（同步或非同步由 ActivityLogService 控制）
        $this->service->record($model, $event, [
            'log_name' => $rules['log_name'] ?? class_basename($model),
            'description' => $description,
            'properties' => [
                // properties.old 會包含非 before/after 的佔位符值
                'old' => $this->stripPlaceholderPrefixes($placeholders),
                // attributes 包含格式化後的變更欄位值
                'attributes' => $this->formatChangedAttributes($model, $formatters),
            ],
        ]);
    }

    /**
     * 判斷是否應該處理目前這條規則（true = 處理, false = 跳過）
     *
     * - 對非 updated 事件，預設回傳 true（直接處理）
     * - 對 updated：
     *   1) 若規則指定了 attributes，且這些屬性在本次更新中沒有變更，則跳過
     *   2) 若規則含有 status 條件（conditions.status），則檢查 from/to 是否符合
     */
    private function ruleShouldProcess(Model $model, string $event, array $attributes, array $rules): bool
    {
        if ($event !== 'updated') {
            return true;
        }

        // 若規則指定屬性但此次更新沒有改動，跳過
        if (!$this->hasChangedAttributes($model, $attributes)) {
            return false;
        }

        // 若規則針對 status 設有條件，評估條件是否成立
        $statusCondition = $rules['conditions']['status'] ?? null;
        if (in_array('status', $attributes, true) && $statusCondition !== null) {
            return $this->matchesStatusCondition($model, $statusCondition);
        }

        // 其他情況都處理
        return true;
    }

    /**
     * 檢查 status 條件是否符合
     * 條件格式： ['from' => <int>|null, 'to' => <int>|null]
     */
    private function matchesStatusCondition(Model $model, array $statusCondition): bool
    {
        $originalStatus = $model->getOriginal('status');
        $currentStatus = $model->getAttribute('status');

        $origInt = is_numeric($originalStatus) ? (int) $originalStatus : null;
        $nowInt = is_numeric($currentStatus) ? (int) $currentStatus : null;

        if (array_key_exists('from', $statusCondition) && $statusCondition['from'] !== null) {
            if (!is_numeric($statusCondition['from']) || (int) $statusCondition['from'] !== $origInt) {
                return false;
            }
        }

        if (array_key_exists('to', $statusCondition) && $statusCondition['to'] !== null) {
            if (!is_numeric($statusCondition['to']) || (int) $statusCondition['to'] !== $nowInt) {
                return false;
            }
        }

        return true;
    }

    /**
     * 從設定檔取得模型對應事件的規則（若無則回傳 null）
     *
     * 規則儲存在 config/activitylog-member-events.php，key 為模型類名的短名稱（class_basename）
     */
    protected function getEventRules(Model $model, string $event): ?array
    {
        $config = config('activitylog-member-events', []);

        return $config[class_basename($model)][$event] ?? null;
    }

    /**
     * 判斷規則中指定的屬性是否在本次更新中有變更
     *
     * 備註：若未指定 attributes（空陣列或未設定），視為有變更（回傳 true）
     */
    protected function hasChangedAttributes(Model $model, array $attributes): bool
    {
        if (empty($attributes)) {
            return true;
        }

        $changes = $model->getChanges();

        foreach ($attributes as $attr) {
            if (array_key_exists($attr, $changes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 根據屬性清單建立佔位符對應表（key 為 :attribute）
     *
     * - 會把每個屬性放入佔位符，例如 ':member_id' => 123
     * - 若 attributes 包含 'status'，也會額外加入 ':before_status' / ':after_status'（方便在描述或 properties 使用）
     * - 若 attributes 僅包含單一欄位，仍保留通用 ':before' / ':after'（相容既有描述模板）
     */
    protected function buildPlaceholders(
        Model $model,
        string $event,
        array $attributes,
        array $formatters
    ): array {
        $placeholders = [];

        // 依照 attributes 建立基本佔位符
        foreach ($attributes as $attr) {
            $raw = data_get($model, $attr);
            $placeholders[':' . $attr] = $this->applyFormatter($attr, $raw, $formatters);
        }

        // 若 attributes 含 status，固定加入 status 的前後值佔位符
        if ($event === 'updated' && in_array('status', $attributes, true)) {
            $beforeRaw = $model->getOriginal('status');
            $afterRaw = $model->getAttribute('status');
            $placeholders[':before_status'] = $this->applyFormatter('status', $beforeRaw, $formatters);
            $placeholders[':after_status'] = $this->applyFormatter('status', $afterRaw, $formatters);
        }

        // 若只有一個屬性，且尚未設定通用 :before/:after，則加上（保留向後相容）
        if ($event === 'updated' && count($attributes) === 1) {
            $attr = $attributes[0];
            if (!array_key_exists(':before', $placeholders) || !array_key_exists(':after', $placeholders)) {
                $beforeRaw = $model->getOriginal($attr);
                $afterRaw = $model->getAttribute($attr);
                $placeholders[':before'] = $this->applyFormatter($attr, $beforeRaw, $formatters);
                $placeholders[':after'] = $this->applyFormatter($attr, $afterRaw, $formatters);
            }
        }

        return $placeholders;
    }

    /**
     * 以佔位符替換描述模板中的變數
     * 例如："金額從 :before 變更為 :after" => "金額從 100 變更為 200"
     */
    // protected function interpolateDescription(string $template, array $placeholders): string
    // {
    //     return strtr($template, $placeholders);
    // }
    protected function interpolateDescription(string $template, array $placeholders): string
    {
        $safe = [];
        foreach ($placeholders as $k => $v) {
            if (is_object($v)) {
                if ($v instanceof \BackedEnum) {
                    $v = $v->value;
                } elseif ($v instanceof \UnitEnum) {
                    $v = $v->name;
                } elseif (method_exists($v, '__toString')) {
                    $v = (string) $v;
                } else {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            } elseif (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif ($v === null) {
                $v = '';
            }
            $safe[$k] = (string) $v;
        }

        return strtr($template, $safe);
    }

    /**
     * 將佔位符陣列轉換成 properties 用的鍵值（移除冒號前綴）
     * 會過濾掉 :before / :after / :before_status / :after_status（這些屬於描述用）
     */
    protected function stripPlaceholderPrefixes(array $placeholders): array
    {
        $filtered = array_filter(
            $placeholders,
            fn ($k) => !in_array($k, [':before', ':after', ':before_status', ':after_status'], true),
            ARRAY_FILTER_USE_KEY
        );

        return array_combine(
            array_map(fn (string $key): string => ltrim($key, ':'), array_keys($filtered)),
            $filtered
        );
    }

    /**
     * 取得變更過後的屬性值，並依 formatters 進行格式化
     */
    protected function formatChangedAttributes(Model $model, array $formatters): array
    {
        $changes = $model->getChanges();

        foreach ($changes as $k => $v) {
            $changes[$k] = $this->applyFormatter($k, $v, $formatters);
        }

        return $changes;
    }

    /**
     * 根據 formatters 對屬性值做轉換（例如 ip_long）
     */
    protected function applyFormatter(string $attribute, mixed $value, array $formatters): mixed
    {
        if (!array_key_exists($attribute, $formatters)) {
            return $value;
        }

        return match ($formatters[$attribute]) {
            'ip_long' => $this->formatIpLong($value),
            default => $value,
        };
    }

    /**
     * 將 ip2long 的整數還原成 IPv4 字串；失敗時回傳原值
     */
    protected function formatIpLong(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_string($value) && str_contains($value, '.')) {
            return $value;
        }

        $int = is_numeric($value) ? (int) $value : null;
        if ($int === null) {
            return $value;
        }

        return long2ip($int);
    }
}
