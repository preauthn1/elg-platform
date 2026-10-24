<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Report;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * SR12 遊戲投注記錄。
 *
 * php artisan test tests/Feature/Agent/Report/Sr12Test.php
 */
class Sr12Test extends TestCase
{
    private const string API = 'agent/api/report/sr12';

    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $this->agent = $this->createAgentAndDomain();
        $this->grantMenu($this->agent);
        $this->loginAs($this->agent);

        $this->member = $this->createMember($this->agent, [
            'username' => 'sr' . bin2hex(random_bytes(6)),
        ]);
        $this->vendor = $this->createBasicVendorGames($this->agent);
    }

    private function grantMenu(Agent $agent): void
    {
        $menu = AgentMenu::create([
            'code' => 'report_sr12',
            'name' => 'SR12 遊戲投注記錄',
            'parent' => 0,
            'url' => 'report/sr12',
            'action' => '',
            'sort' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        AgentPermission::create([
            'agent_id' => $agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function seedBet(string $referenceNo, string $bet, string $validBet, string $winLose): void
    {
        TotalBetRecord::create([
            'summary_time' => '2026-10-01 09:00:00',
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor->id,
            'bet_record_id' => (int) $referenceNo,
            'game_id' => 0,
            'reference_no' => $referenceNo,
            'bet' => $bet,
            'valid_bet' => $validBet,
            'win_lose' => $winLose,
            'rolling_chip' => '0.0000',
            'jackpot' => '0.0000',
            'bonus' => '0.0000',
            'valid_bet_rate' => 1,
            'commission' => 0,
            'bet_time' => '2026-10-01 09:38:16',
            'payout_time' => '2026-10-01 09:38:51',
            'admin' => 0,
        ]);
    }

    #[TestDox('總數派彩＝押注總數＋輸贏總數，不受有效押注影響（target/tickets#4445）')]
    #[Group('rules/report-sr12-payout#R-6')]
    #[Group('rules/report-sr12-payout#R-7')]
    public function test_summary_payout_uses_bet_not_valid_bet(): void
    {
        // 押注 10、有效押注 9.5、贏 9.5：派彩應為 19.5，不是 19
        $this->seedBet(referenceNo: '134208', bet: '10.0000', validBet: '9.5000', winLose: '9.5000');
        // 押注等於有效押注：派彩與舊算法相同
        $this->seedBet(referenceNo: '134216', bet: '10.0000', validBet: '10.0000', winLose: '-10.0000');

        $response = $this->requestApi(self::API, [
            'vendor_id' => $this->vendor->id,
            'game_type' => 0,
            'member_username' => $this->member->username,
            'start_time' => '2026-10-01 00:00:00',
            'end_time' => '2026-10-01 23:59:59',
            'page' => 1,
            'per_page' => 10,
        ]);

        $response->assertStatus(200);
        $summary = $response->json('data.summary');

        $this->assertEquals(20, $summary['bet_amount']);
        $this->assertEquals(19.5, $summary['valid_bet_amount']);
        $this->assertEquals(-0.5, $summary['win_lose']);
        $this->assertEquals(19.5, $summary['payout'], '派彩＝押注 20＋輸贏 -0.5');
    }
}
