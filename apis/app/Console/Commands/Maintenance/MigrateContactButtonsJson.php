<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Models\AgentCustomizedSetting;
use App\Models\AgentSettingDefine;
use Illuminate\Console\Command;

/**
 * 將舊版聯繫客服設定(setting_key 2002/2004/2005/2006)合併為新版JSON設定(setting_key 2020)。
 *
 * 【分類】Maintenance — 可複用遷移工具，新站建置後仍需執行。
 *
 * ## 發生原因
 * 舊版把「登入頁客服按鈕」拆成 4 個獨立 setting_key（2002 網址1／2004 網址2／
 * 2005 icon1／2006 icon2），**組數被寫死成 2 組**，要加第 3 顆按鈕就得再開兩個 key。
 * 新版改為單一 JSON 設定（2020），組數不受限。
 *
 * ⚠️ **舊 key 的來源尚未斷**：`database/data/agent_setting_defines.php` **仍定義 2004／2006**，
 * 因此新白標 seed 之後仍會產生舊格式設定，需要再跑一次本命令合併，並非跑完就結束的一次性 patch。
 *
 * ## 修正目的
 * 讀取 4 個舊 key 的值，合併寫成 2020 的 JSON 結構；**舊 4 個 key 的資料保留不動**
 * （保留回滾餘地與舊前端相容），僅把舊 4 個 `AgentSettingDefine` 的 `is_visible` 設為 0，
 * 讓它們從後台編輯畫面消失、避免營運繼續改到不再生效的欄位。
 *
 * ## 觸發時機
 * 1. **新白標建置完成、seed 之後**（seed 仍會建出舊 key）
 * 2. 從舊站複製代理設定之後
 * 3. 後台改了客服按鈕卻發現前端沒變時（多半是改到舊 key）
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 寫入 2020 走 `updateOrCreate`；舊 key 不刪不改值，
 * `is_visible=0` 重設也是相同結果。支援 `--dry-run` 先看將變更的代理清單。
 *
 * ## 退役條件
 * 同時滿足即可刪除：
 * 1. `database/data/agent_setting_defines.php` **移除 2002／2004／2005／2006 定義**（來源斷開）；且
 * 2. 所有前端／後台讀取端改讀 2020；且
 * 3. 六站確認無殘留舊 key 資料。
 *
 * ## 關聯
 * - 工單：target/tickets#3647
 * - seed 來源：`database/data/agent_setting_defines.php`
 * - key 定義：`app/Enums/Agent/AgentCustomizedSettingKeyEnum.php`
 *
 * php artisan patch:migrate-contact-buttons-json
 * php artisan patch:migrate-contact-buttons-json --dry-run
 */
class MigrateContactButtonsJson extends Command
{
    protected $signature = 'patch:migrate-contact-buttons-json {--dry-run : 只預覽變更，不寫入資料庫}';

    protected $description = '將舊版聯繫客服設定(2002/2004/2005/2006)合併為新版JSON設定(2020)';

    /** 舊setting_key：2004(網址2)、2006(icon2) 目前未在enum命名，維持原始int */
    private const URL_1 = AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ICON_LINK_URL;

    private const ICON_1 = AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ICON_PIC_URL;

    private const URL_2 = 2004;

    private const ICON_2 = 2006;

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('⚠️  DRY RUN 模式：不會修改資料庫');
        }

        $rows = AgentCustomizedSetting::query()
            ->whereIn('setting_key', [self::URL_1, self::URL_2, self::ICON_1, self::ICON_2])
            ->get(['agent_group_id', 'setting_key', 'value']);

        if ($rows->isEmpty()) {
            $this->info('沒有找到舊版聯繫客服設定資料，略過。');

            return self::SUCCESS;
        }

        $grouped = $rows->groupBy('agent_group_id');
        $migrated = 0;
        $skipped = 0;

        foreach ($grouped as $agentGroupId => $settings) {
            $bySettingKey = $settings->keyBy('setting_key');

            $icon1 = trim((string) ($bySettingKey->get(self::ICON_1)->value ?? ''));
            $urls1 = $this->splitUrls($bySettingKey->get(self::URL_1)->value ?? null);
            $icon2 = trim((string) ($bySettingKey->get(self::ICON_2)->value ?? ''));
            $urls2 = $this->splitUrls($bySettingKey->get(self::URL_2)->value ?? null);

            $buttons = [];

            if ($icon1 !== '' || $urls1 !== []) {
                $buttons[] = ['icon' => $icon1, 'link' => $urls1];
            }

            if ($icon2 !== '' || $urls2 !== []) {
                $buttons[] = ['icon' => $icon2, 'link' => $urls2];
            }

            if ($buttons === []) {
                $skipped++;

                continue;
            }

            $json = json_encode(['customerService' => $buttons], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->line(sprintf('agent_group_id=%d => %s', $agentGroupId, $json));

            if (!$isDryRun) {
                AgentCustomizedSetting::updateOrCreate(
                    [
                        'agent_group_id' => $agentGroupId,
                        'setting_key' => AgentCustomizedSettingKeyEnum::CS_CONTACT_BUTTONS_JSON,
                        'sub_category' => '',
                    ],
                    [
                        'value' => $json,
                        'memo' => '聯繫客服按鈕JSON設定',
                    ]
                );
            }

            $migrated++;
        }

        $this->info(sprintf(
            '%s：共 %d 個 agent_group_id 有舊資料，%s %d 筆，略過(無有效資料) %d 筆。',
            $isDryRun ? 'DRY RUN' : '完成',
            $grouped->count(),
            $isDryRun ? '預計寫入' : '已寫入',
            $migrated,
            $skipped
        ));

        $oldKeys = [self::URL_1, self::URL_2, self::ICON_1, self::ICON_2];

        if ($isDryRun) {
            $this->warn(sprintf('DRY RUN：將隱藏舊 AgentSettingDefine setting_key = %s', implode(', ', $oldKeys)));
        } else {
            AgentSettingDefine::whereIn('setting_id', $oldKeys)->update(['is_visible' => 0]);
            $this->info(sprintf('已將舊 AgentSettingDefine setting_key = %s 設為 is_visible=0', implode(', ', $oldKeys)));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function splitUrls(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
