<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EventV2;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\EventV2;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 後台活動類型清單 event-v2/event-list 的 field_params（參數說明彈窗資料，target/tickets#4231 需求 7）。
 *
 * 必須成立：
 * 1. 每個類型帶 field_params，內容依 Accept-Language 本地化（zh-tw 繁中、en 英文）。
 * 2. 沒人讀的格 kind=none 且 items 空——前端據此顯示「此欄位目前無可用參數」，不是空彈窗。
 * 3. 可選積分的類型在提款限制兩格多 score 子項；不開放積分的類型沒有。
 * 4. handler_class 對不到類別時 field_params 為 null，其餘欄位照常，不影響下拉。
 *
 * php artisan test tests/Feature/Agent/EventV2/EventListFieldParamsTest.php
 */
class EventListFieldParamsTest extends TestCase
{
    private const string EVENT_LIST = 'agent/api/event-v2/event-list';

    private Agent $agent;

    private EventV2 $dailyFree;

    private EventV2 $range;

    private EventV2 $unknown;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $this->agent = $this->createAgentAndDomain();
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        // 自建三筆類型列，不依賴測試庫既有的 event_v2 內容
        $suffix = bin2hex(random_bytes(4));
        $this->dailyFree = EventV2::create(['name' => "fp-daily-{$suffix}", 'handler_class' => 'DailyFreeService']);
        $this->range = EventV2::create(['name' => "fp-range-{$suffix}", 'handler_class' => 'DailyDepositRangeService']);
        $this->unknown = EventV2::create(['name' => "fp-unknown-{$suffix}", 'handler_class' => 'NoSuchService']);
    }

    public function test_field_params_are_localized_by_accept_language(): void
    {
        $zh = $this->row($this->dailyFree->id, 'zh-tw');
        $this->assertSame('keys', $zh['condition_config']['main']['kind']);
        $this->assertSame('max_join', $zh['condition_config']['main']['items'][0]['name']);
        $this->assertSame('integer', $zh['condition_config']['main']['items'][0]['type']);
        $this->assertStringContainsString('全站參加上限', $zh['condition_config']['main']['items'][0]['description']);
        $this->assertSame('{"max_join": 100}', $zh['condition_config']['main']['items'][0]['example']);

        $en = $this->row($this->dailyFree->id, 'en');
        $this->assertStringContainsString('Site-wide participation cap', $en['condition_config']['main']['items'][0]['description']);
    }

    public function test_unread_fields_report_none_and_score_variant_follows_handler_support(): void
    {
        $dailyFree = $this->row($this->dailyFree->id, 'zh-tw');
        $this->assertSame(['kind' => 'none', 'items' => []], $dailyFree['flow_config']['main']);
        $this->assertSame(['kind' => 'none', 'items' => []], $dailyFree['balance_config']['main']);
        $this->assertSame('rand(a, b)', $dailyFree['reward_formula']['main']['items'][0]['name']);
        // 每日登入可選積分：提款限制那一對多 score，其他格沒有
        $this->assertSame(['expire', 'limit', 'tiers'], array_column($dailyFree['withdraw_config']['score']['items'], 'name'));
        $this->assertSame(['rand(a, b)'], array_column($dailyFree['withdraw_formula']['score']['items'], 'name'));
        $this->assertArrayNotHasKey('score', $dailyFree['reward_formula']);

        // 每日區間儲值不開放積分：沒有 score；獎勵配置是整段陣列
        $range = $this->row($this->range->id, 'zh-tw');
        $this->assertArrayNotHasKey('score', $range['withdraw_config']);
        $this->assertArrayNotHasKey('score', $range['withdraw_formula']);
        $this->assertSame('arrays', $range['reward_config']['main']['kind']);
        $this->assertSame('none', $range['reward_formula']['main']['kind']);
        $this->assertSame(['deposit'], array_column($range['condition_formula']['main']['items'], 'name'));
    }

    public function test_unknown_handler_gets_null_field_params_but_stays_in_list(): void
    {
        $res = $this->postJson(self::EVENT_LIST, [], array_merge($this->headers, ['Accept-Language' => 'zh-tw']));
        $res->assertOk()->assertJsonPath('code', 200);

        $row = collect($this->rows($res->json('data')))->firstWhere('id', $this->unknown->id);
        $this->assertNotNull($row, '對不到 handler 的類型仍在清單裡');
        $this->assertSame('NoSuchService', $row['handler_class']);
        $this->assertNull($row['field_params']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $language): array
    {
        $res = $this->postJson(self::EVENT_LIST, [], array_merge($this->headers, ['Accept-Language' => $language]));
        $res->assertOk()->assertJsonPath('code', 200);

        $row = collect($this->rows($res->json('data')))->firstWhere('id', $id);
        $this->assertNotNull($row, "清單找不到 id {$id}");
        $this->assertIsArray($row['field_params']);

        return $row['field_params'];
    }

    /**
     * 清單可能直接是陣列，或包在 items 裡；兩種都收
     *
     * @param  array<array-key, mixed>|null  $data
     * @return list<array<string, mixed>>
     */
    private function rows(?array $data): array
    {
        return array_values($data['items'] ?? $data ?? []);
    }
}
