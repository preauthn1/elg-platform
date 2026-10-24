<?php

declare(strict_types=1);

namespace Tests\Unit\Services\EventV2;

use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Services\EventV2\AbstractEventV2;
use App\Services\EventV2\AngpowService;
use App\Services\EventV2\DailyDepositComboService;
use App\Services\EventV2\DailyDepositRangeService;
use App\Services\EventV2\DailyDepositService;
use App\Services\EventV2\DailyFreeService;
use App\Services\EventV2\DepositService;
use App\Services\EventV2\DownlineCommissionService;
use App\Services\EventV2\EventV2FieldParamService;
use App\Services\EventV2\FirstDepositService;
use App\Services\EventV2\InviteFriendService;
use App\Services\EventV2\MonthlyDeposit2Service;
use App\Services\EventV2\MonthlyDepositService;
use App\Services\EventV2\RegisterService;
use App\Services\EventV2\WeeklyDepositService;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * 後台「設定條件」參數說明彈窗的資料來源：各 handler 的 fieldParams() 宣告（target/tickets#4231 需求 7）。
 *
 * 三件事必須成立：
 * 1. 每個 handler 十格都有宣告，形狀正確（NONE 沒有 items、其餘至少一個）。
 * 2. 宣告引用的每個目錄 id 在四個語系的 event_v2.php params 都有 name／description／example——
 *    這條就是「後端加 key 忘了補語系」的防線，漏了在這裡紅，不會等到運營看到空白。
 * 3. 宣告內容對得上程式實際讀取（2026-09-29 逐 handler 盤點的結果）：
 *    - rand() 只在 convertRewardFormula() 展開，邀請好友／下線傭金的獎勵公式沒有它；限制公式一律沒有。
 *    - 非儲值類的 deposit 恆為 0，條件公式與限制公式都不列。
 *    - 每日儲值組合的條件配置是陣列，連 max_join 都讀不到。
 *    - 積分型的 score 子項只出現在可選積分的類型、且只在提款限制那一對。
 *
 * php artisan test tests/Unit/Services/EventV2/FieldParamsCatalogTest.php
 */
class FieldParamsCatalogTest extends TestCase
{
    /** @var list<class-string<AbstractEventV2>> */
    private const array HANDLERS = [
        RegisterService::class,
        DailyFreeService::class,
        FirstDepositService::class,
        DepositService::class,
        DailyDepositService::class,
        MonthlyDepositService::class,
        MonthlyDeposit2Service::class,
        InviteFriendService::class,
        DownlineCommissionService::class,
        DailyDepositRangeService::class,
        WeeklyDepositService::class,
        DailyDepositComboService::class,
        AngpowService::class,
    ];

    private const array LOCALES = ['en', 'zh_TW', 'zh_CN', 'vi'];

    public function test_every_handler_declares_all_ten_fields_with_valid_shape(): void
    {
        $fields = array_map(fn (EventV2FieldEnum $f) => $f->value, EventV2FieldEnum::cases());

        foreach (self::HANDLERS as $class) {
            $params = $class::fieldParams();
            $this->assertEqualsCanonicalizing($fields, array_keys($params), "{$class} 十格要全部宣告");

            foreach ($params as $field => $entry) {
                $this->assertShape($entry, "{$class}.{$field}");
                if (isset($entry['score'])) {
                    $this->assertShape($entry['score'], "{$class}.{$field}.score");
                }
            }
        }
    }

    public function test_every_referenced_catalog_id_exists_in_all_locales(): void
    {
        $referenced = [];
        foreach (self::HANDLERS as $class) {
            foreach ($class::fieldParams() as $entry) {
                foreach ([$entry, $entry['score'] ?? null] as $set) {
                    if ($set === null || $set['kind'] === EventV2FieldParamKindEnum::NONE) {
                        continue;
                    }
                    foreach ($set['items'] as $id) {
                        $referenced["{$set['kind']->value}.{$id}"] = true;
                    }
                }
            }
        }
        $this->assertNotEmpty($referenced);

        $original = App::getLocale();
        try {
            foreach (self::LOCALES as $locale) {
                App::setLocale($locale);
                foreach (array_keys($referenced) as $path) {
                    $entry = __("event_v2.params.{$path}");
                    $this->assertIsArray($entry, "[{$locale}] event_v2.params.{$path} 缺目錄條目");
                    foreach (['name', 'description', 'example'] as $key) {
                        $this->assertNotSame('', trim((string) ($entry[$key] ?? '')), "[{$locale}] event_v2.params.{$path}.{$key} 為空");
                    }
                }
            }
        } finally {
            App::setLocale($original);
        }
    }

    public function test_declarations_match_what_each_handler_actually_reads(): void
    {
        // 公式型基底：註冊多 realname；每日登入純基底
        $this->assertItems(RegisterService::class, 'condition_formula', EventV2FieldParamKindEnum::NONE, []);
        $this->assertItems(RegisterService::class, 'condition_config', EventV2FieldParamKindEnum::KEYS, ['max_join', 'realname']);
        $this->assertItems(RegisterService::class, 'reward_formula', EventV2FieldParamKindEnum::VARIABLES, ['rand']);
        $this->assertItems(RegisterService::class, 'reward_config', EventV2FieldParamKindEnum::KEYS, ['limit']);
        $this->assertItems(RegisterService::class, 'balance_formula', EventV2FieldParamKindEnum::VARIABLES, ['reward']);
        $this->assertItems(DailyFreeService::class, 'condition_config', EventV2FieldParamKindEnum::KEYS, ['max_join']);
        $this->assertItems(DailyFreeService::class, 'reward_formula', EventV2FieldParamKindEnum::VARIABLES, ['rand']);

        // 五種儲值類：條件公式 deposit、條件配置多 main_wallet_deduction、各公式可用 deposit
        foreach ([FirstDepositService::class, DepositService::class, DailyDepositService::class, MonthlyDepositService::class, MonthlyDeposit2Service::class] as $class) {
            $this->assertItems($class, 'condition_formula', EventV2FieldParamKindEnum::VARIABLES, ['deposit']);
            $this->assertItems($class, 'condition_config', EventV2FieldParamKindEnum::KEYS, ['max_join', 'main_wallet_deduction']);
            $this->assertItems($class, 'reward_formula', EventV2FieldParamKindEnum::VARIABLES, ['deposit', 'rand']);
            $this->assertItems($class, 'reward_config', EventV2FieldParamKindEnum::KEYS, ['limit']);
            $this->assertItems($class, 'withdraw_formula', EventV2FieldParamKindEnum::VARIABLES, ['deposit', 'reward']);
        }

        // 邀請好友／下線傭金：獎勵公式自己執行，沒有 rand、不讀獎勵配置；限制公式只有 reward
        $this->assertItems(InviteFriendService::class, 'condition_config', EventV2FieldParamKindEnum::KEYS, ['max_join', 'invite', 'invite_deposit']);
        $this->assertItems(InviteFriendService::class, 'reward_formula', EventV2FieldParamKindEnum::VARIABLES, ['invite']);
        $this->assertItems(InviteFriendService::class, 'reward_config', EventV2FieldParamKindEnum::NONE, []);
        $this->assertItems(InviteFriendService::class, 'flow_formula', EventV2FieldParamKindEnum::VARIABLES, ['reward']);
        $this->assertItems(DownlineCommissionService::class, 'condition_config', EventV2FieldParamKindEnum::KEYS, ['max_join', 'dayOfWeek']);
        $this->assertItems(DownlineCommissionService::class, 'reward_formula', EventV2FieldParamKindEnum::VARIABLES, ['win_lose']);
        $this->assertItems(DownlineCommissionService::class, 'reward_config', EventV2FieldParamKindEnum::NONE, []);

        // 區間／每週累積：獎勵只讀配置陣列
        foreach ([DailyDepositRangeService::class, WeeklyDepositService::class] as $class) {
            $this->assertItems($class, 'condition_formula', EventV2FieldParamKindEnum::VARIABLES, ['deposit']);
            $this->assertItems($class, 'reward_formula', EventV2FieldParamKindEnum::NONE, []);
            $this->assertItems($class, 'reward_config', EventV2FieldParamKindEnum::ARRAY_TEMPLATE, ['range_reward']);
        }

        // 每日儲值組合：三格配置都是陣列、不讀 max_join、限制公式沒有 deposit；餘額門檻只看配置陣列，餘額限制公式不讀
        $this->assertItems(DailyDepositComboService::class, 'condition_formula', EventV2FieldParamKindEnum::NONE, []);
        $this->assertItems(DailyDepositComboService::class, 'balance_formula', EventV2FieldParamKindEnum::NONE, []);
        $this->assertItems(DailyDepositComboService::class, 'condition_config', EventV2FieldParamKindEnum::ARRAY_TEMPLATE, ['combo_condition']);
        $this->assertItems(DailyDepositComboService::class, 'reward_formula', EventV2FieldParamKindEnum::NONE, []);
        $this->assertItems(DailyDepositComboService::class, 'reward_config', EventV2FieldParamKindEnum::ARRAY_TEMPLATE, ['combo_reward']);
        $this->assertItems(DailyDepositComboService::class, 'balance_config', EventV2FieldParamKindEnum::ARRAY_TEMPLATE, ['combo_balance']);
        $this->assertItems(DailyDepositComboService::class, 'withdraw_formula', EventV2FieldParamKindEnum::VARIABLES, ['reward']);

        // 紅包：不看儲值（deposit 恆為 0）、獎勵走公式型基底、條件配置七個 key（兩個週期在欄位不在 JSON）
        $this->assertItems(AngpowService::class, 'condition_formula', EventV2FieldParamKindEnum::NONE, []);
        $this->assertItems(AngpowService::class, 'condition_config', EventV2FieldParamKindEnum::KEYS, ['angpow_score', 'angpow_max_join', 'angpow_max_amount', 'angpow_member_times', 'angpow_member_amount', 'angpow_interval', 'angpow_time_window']);
        $this->assertItems(AngpowService::class, 'reward_formula', EventV2FieldParamKindEnum::VARIABLES, ['rand']);
        $this->assertItems(AngpowService::class, 'reward_config', EventV2FieldParamKindEnum::KEYS, ['limit']);
        $this->assertItems(AngpowService::class, 'flow_formula', EventV2FieldParamKindEnum::VARIABLES, ['reward']);

        // 全類型一致：提款限制配置 balance_multiple；稽核流水配置沒人讀；餘額限制配置只有組合型讀
        foreach (self::HANDLERS as $class) {
            $this->assertItems($class, 'withdraw_config', EventV2FieldParamKindEnum::KEYS, ['balance_multiple']);
            $this->assertItems($class, 'flow_config', EventV2FieldParamKindEnum::NONE, []);
            if ($class !== DailyDepositComboService::class) {
                $this->assertItems($class, 'balance_config', EventV2FieldParamKindEnum::NONE, []);
            }
        }
    }

    public function test_score_variant_only_on_supported_handlers_and_only_on_withdraw_fields(): void
    {
        foreach (self::HANDLERS as $class) {
            $params = $class::fieldParams();
            $supported = EventV2WithdrawTypeEnum::SCORE->supportsHandler(class_basename($class));

            foreach ($params as $field => $entry) {
                $expectScore = $supported && in_array($field, ['withdraw_formula', 'withdraw_config'], true);
                $this->assertSame($expectScore, isset($entry['score']), "{$class}.{$field} score 子項有無");
            }

            if (!$supported) {
                continue;
            }
            // 積分數量走 getReward()：變數與獎勵公式相同；配置改讀 expire／limit／tiers
            $this->assertSame($params['reward_formula']['items'], $params['withdraw_formula']['score']['items'], "{$class} 積分數量變數＝獎勵公式變數");
            $this->assertSame(EventV2FieldParamKindEnum::KEYS, $params['withdraw_config']['score']['kind']);
            $this->assertSame(['expire', 'score_limit', 'score_tiers'], $params['withdraw_config']['score']['items']);
        }
    }

    public function test_service_localizes_by_current_locale_and_rejects_unknown_handler(): void
    {
        $service = new EventV2FieldParamService;
        $original = App::getLocale();

        try {
            App::setLocale('zh_TW');
            $zh = $service->forHandler('DailyDepositComboService');
            $this->assertNotNull($zh);
            $this->assertSame('keys', $zh['withdraw_config']['main']['kind']);
            $this->assertSame('balance_multiple', $zh['withdraw_config']['main']['items'][0]['name']);
            $this->assertStringContainsString('領取倍數', $zh['withdraw_config']['main']['items'][0]['description']);
            // 陣列型：整格一筆，name 是形狀、example 是整段
            $this->assertSame('arrays', $zh['reward_config']['main']['kind']);
            $this->assertCount(1, $zh['reward_config']['main']['items']);
            $this->assertSame('array', $zh['reward_config']['main']['items'][0]['type']);
            $this->assertStringStartsWith('[{', $zh['reward_config']['main']['items'][0]['example']);
            // NONE：kind 帶到、items 空；不開放積分的類型沒有 score
            $this->assertSame(['kind' => 'none', 'items' => []], $zh['flow_config']['main']);
            $this->assertArrayNotHasKey('score', $zh['withdraw_config']);
            // 變數沒有 type
            $this->assertNull($zh['withdraw_formula']['main']['items'][0]['type']);

            App::setLocale('en');
            $en = $service->forHandler('DailyFreeService');
            $this->assertStringContainsString('multiplier', $en['withdraw_config']['main']['items'][0]['description']);
            // 積分型 score 子項：目錄 id score_limit／score_tiers 顯示成 limit／tiers
            $this->assertSame(['expire', 'limit', 'tiers'], array_column($en['withdraw_config']['score']['items'], 'name'));
            $this->assertSame('rand(a, b)', $en['withdraw_formula']['score']['items'][0]['name']);
        } finally {
            App::setLocale($original);
        }

        $this->assertNull($service->forHandler(null));
        $this->assertNull($service->forHandler(''));
        $this->assertNull($service->forHandler('NoSuchService'));
        $this->assertNull($service->forHandler('EventV2Service'), '同目錄但不是活動 handler 的類別不回參數');
    }

    /**
     * @param  array<string, mixed>  $set
     */
    private function assertShape(array $set, string $where): void
    {
        $this->assertInstanceOf(EventV2FieldParamKindEnum::class, $set['kind'] ?? null, "{$where} kind");
        $this->assertIsList($set['items'] ?? null, "{$where} items");
        if ($set['kind'] === EventV2FieldParamKindEnum::NONE) {
            $this->assertSame([], $set['items'], "{$where} NONE 不該有 items");
        } else {
            $this->assertNotEmpty($set['items'], "{$where} 非 NONE 至少一個 item");
        }
    }

    /**
     * @param  class-string<AbstractEventV2>  $class
     * @param  list<string>  $items
     */
    private function assertItems(string $class, string $field, EventV2FieldParamKindEnum $kind, array $items): void
    {
        $entry = $class::fieldParams()[$field];
        $this->assertSame($kind, $entry['kind'], "{$class}.{$field} kind");
        $this->assertSame($items, $entry['items'], "{$class}.{$field} items");
    }
}
