<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PegasusSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\PgsswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PgsswTestCase extends TestCase
{
    protected string $identity = 'test-target-identity-token';

    protected string $playerUniqueId = 'PGSSW_00000001';

    protected string $sessionToken = [REDACTED]';

    protected string $baseUrl = '/external/api/sw/target';

    protected PgsswAccount $account;

    protected Member $member;

    protected VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($agent);

        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::PGSSW->value);
        $vendor->save();

        // game_type 對應 placeBet 傳入的 gameID，BetHelper 依此查找遊戲
        $vendor->games()->first()->update(['game_type' => '1234']);

        $this->vendorInfo = $vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'API_APPID' => 'test-merchant-id',
            'API_KEY' => 'test-merchant-secret',
            'IDENTITY' => $this->identity,
        ];
        $this->vendorInfo->save();

        $this->account = PgsswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->playerUniqueId,
            'session_token' => $this->sessionToken,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletService = new WalletService;
        $walletService->getMemberLockedWallets($this->member, $this->vendorInfo);
    }

    /**
     * 回應給 target 的金額預期值
     *
     * target 規格僅接受小數兩位，回應時以 rate 換算後無條件捨去，見 PegasusController::BALANCE_SCALE。
     */
    protected function expectedResponseAmount(string $platformBalance): float
    {
        return (float) Common::numMul($platformBalance, $this->vendorInfo->rate, 2);
    }

    protected function postWithIdentity(string $path, array $data = []): TestResponse
    {
        return $this->withHeaders(['X-PROVIDER-IDENTITY' => $this->identity])
            ->postJson("{$this->baseUrl}{$path}", $data);
    }

    protected function makeBetRecord(array $overrides = []): \App\Vendors\Models\PgsswBetRecord
    {
        return \App\Vendors\Models\PgsswBetRecord::create(array_merge([
            'vendor_info_id' => $this->vendorInfo->id,
            'bet_id' => 'TXN-BET-001',
            'session_token' => $this->sessionToken,
            'username' => $this->playerUniqueId,
            'game_code' => '1234',
            'bet' => '10.0000',
            'win' => '0.0000',
            'win_lose' => '-10.0000',
            'rolling_chip' => '0.0000',
            'info' => '[]',
            'bet_time' => '2024-09-30 14:00:00',
            'payout_time' => '1970-01-01 00:00:00',
            'settlement_time' => '1970-01-01 00:00:00',
            'status' => 0,
        ], $overrides));
    }
}
