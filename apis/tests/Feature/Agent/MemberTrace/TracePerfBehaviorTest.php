<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberTrace;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 會員追蹤效能收斂的行為驗證（4299；功能本體為 3963）：
 *  1. 熱門共用 IP（>5,000 列）不參與反查
 *  3. 顯示 IP＝撞到置頂＋近期補滿（上限 20）＋登入總筆數
 *  4. 實名只比 first_name（last_name 無索引且僅 180 筆殘留）
 * （第 2 刀 COUNT 封頂與第 5 刀 OR 改 UNION 為純 SQL 改寫，需大量資料才顯差異，
 *  不在此驗，由 TPower 實測對照。）
 *
 * php artisan test tests/Feature/Agent/MemberTrace/TracePerfBehaviorTest.php
 */
class TracePerfBehaviorTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function newMember(array $data = []): Member
    {
        return $this->createMember($this->agent, array_merge(['username' => 'mt' . bin2hex(random_bytes(6))], $data));
    }

    /** 塞登入 log；四段欄位 NOT NULL，一併給值 */
    private function insertIpLogs(int $memberId, string $ip, int $count = 1): void
    {
        $parts = array_map('intval', explode('.', $ip));
        $row = [
            'member_id' => $memberId,
            'ip_1' => $parts[0], 'ip_2' => $parts[1], 'ip_3' => $parts[2], 'ip_4' => $parts[3],
            'ip_address' => $ip,
        ];

        foreach (array_chunk(array_fill(0, $count, $row), 1000) as $chunk) {
            DB::table('member_login_ip_logs')->insert($chunk);
        }
    }

    private function trace(array $payload): TestResponse
    {
        return $this->postJson('agent/api/member/trace', array_merge([
            'page' => 1,
            'per_page' => 30,
        ], $payload), $this->headers);
    }

    /** @return array<int, string> 回應中的 username 清單 */
    private function usernames(TestResponse $response): array
    {
        return array_column($response->json('data.items') ?? [], 'username');
    }

    public function test_hot_ip_excluded_from_trace(): void
    {
        $target = $this->newMember();
        $viaHot = $this->newMember();
        $viaNormal = $this->newMember();

        $hotIp = '0.0.0.0';
        $normalIp = '0.0.0.0';

        // 熱門 IP：塞超過門檻（5,000）的 log 列（掛在無關的第四人身上，模擬公司/CGNAT 出口）
        $crowd = $this->newMember();
        $this->insertIpLogs($crowd->id, $hotIp, 5001);

        $this->insertIpLogs($target->id, $hotIp);
        $this->insertIpLogs($target->id, $normalIp);
        $this->insertIpLogs($viaHot->id, $hotIp);
        $this->insertIpLogs($viaNormal->id, $normalIp);

        $response = $this->trace([
            'username' => $target->username,
            'trace_fields' => ['ip'],
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $usernames = $this->usernames($response);

        $this->assertContains($target->username, $usernames, '本人必列');
        $this->assertContains($viaNormal->username, $usernames, '一般 IP 正常反查');
        $this->assertNotContains($viaHot->username, $usernames, '僅透過熱門 IP 關聯者不得入列');
        $this->assertNotContains($crowd->username, $usernames, '熱門 IP 群眾不得入列');
    }

    public function test_display_ips_matched_pinned_capped_with_total(): void
    {
        $target = $this->newMember();
        $matchedIp = '0.0.0.0';

        // 撞到的 IP 是「最舊」的一筆（id 最小），之後灌 25 個不重複近期 IP —— 驗證置頂保證
        $this->insertIpLogs($target->id, $matchedIp);
        for ($i = 1; $i <= 25; $i++) {
            $this->insertIpLogs($target->id, "10.98.1.{$i}");
        }

        $response = $this->trace(['ip' => $matchedIp]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $row = collect($response->json('data.items'))->firstWhere('username', $target->username);

        $this->assertNotNull($row, '直接模式以 IP 命中該會員');
        $this->assertSame($matchedIp, $row['login_ips'][0], '撞到的 IP 必置頂，即使它是最舊的');
        $this->assertSame([$matchedIp], $row['matched_login_ips']);
        $this->assertCount(20, $row['login_ips'], '顯示上限 20（1 撞到 + 19 近期）');
        $this->assertSame(26, $row['login_log_total'], '登入總筆數為原始列數');
        $this->assertContains('ip', $row['risk_marks'], 'IP 命中標記由撞 IP 查詢判定');
    }

    public function test_realname_matches_first_name_only(): void
    {
        $name = 'RN' . bin2hex(random_bytes(4));
        $target = $this->newMember(['first_name' => $name]);
        $byFirst = $this->newMember(['first_name' => $name]);
        $byLastOnly = $this->newMember(['first_name' => '', 'last_name' => $name]);

        $response = $this->trace([
            'username' => $target->username,
            'trace_fields' => ['realname'],
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $usernames = $this->usernames($response);

        $this->assertContains($target->username, $usernames);
        $this->assertContains($byFirst->username, $usernames, 'first_name 相同者命中');
        $this->assertNotContains($byLastOnly->username, $usernames, 'last_name 不再參與比對（無索引全表掃，僅 180 筆殘留）');

        $row = collect($response->json('data.items'))->firstWhere('username', $byFirst->username);
        $this->assertContains('realname', $row['risk_marks']);
    }
}
