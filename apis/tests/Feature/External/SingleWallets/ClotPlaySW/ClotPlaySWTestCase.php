<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\ClotPlaySW;

use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\ClotswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClotPlay SW（Slot Game）單一錢包測試基底
 *
 * VendorId: CLOTSW | 前綴: {id}_CLOTSW | 下注紀錄表: clotsw_bet_records
 *
 * 涵蓋範圍：ClotPlay P2P SW（p2p）、ClotPlay Legend Slot SW（legend slot）兩個廠商
 * 與本廠商共用同一套 setUp／下注／結算／取消／查詢餘額邏輯，僅 VendorId、URL 前綴、
 * 下注紀錄表與帳號 Model 不同；因邏輯完全一致，兩者未各自維護獨立 TestCase，
 * 一併由本測試套件涵蓋（原獨立測試已移除）。
 */
class ClotPlaySWTestCase extends TestCase
{
    protected string $baseUrl = '/external/api/sw/clot/api/seamless';

    protected string $username = '142_CLOTSW00000001';

    protected string $operatorToken = [REDACTED]';

    protected string $gameCode = 'GAME001';

    protected string $betRecordTable = 'clotsw_bet_records';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected ClotswAccount $account;

    protected Member|MemberWallet $wallet;

    protected MemberWallet $rollingChipWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::CLOTSW->value);
        $this->vendor->name = 'ClotPlay SW';
        $this->vendor->code = 'clotsw';
        $this->vendor->tname = 'clotsw';
        $this->vendor->third_party_code = 'clotsw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'clotsw';
        $this->vendorInfo->account_prefix = VendorIdEnum::CLOTSW->value . '_CLOTSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = [
            'operatorToken' => $this->operatorToken,
            'apiUrl' => 'http://fake.clotsw.api',
            'currency' => 'MYR',
        ];
        $this->vendorInfo->save();

        $game = $this->vendor->games()->first();
        $game->game_type = $this->gameCode;
        $game->status = 1;
        $game->save();

        $this->account = ClotswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);

        $this->rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => 0,
        ]);

        $this->wallet = $this->member;
    }

    protected function enableRollingChip(): void
    {
        $this->vendorInfo->update(['rolling_chip_status' => 1]);
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => $this->operatorToken];
    }

    protected function makeBetParams(array $overrides = []): array
    {
        return array_merge([
            'player_unique_id' => $this->username,
            'game' => $this->gameCode,
            'game_round_id' => 'round_' . Str::random(8),
            'amount' => 100,
            'created_at' => now()->toDateTimeString(),
        ], $overrides);
    }

    protected function makeSettleParams(string $gameRoundId, array $overrides = []): array
    {
        return array_merge([
            'player_unique_id' => $this->username,
            'game' => $this->gameCode,
            'game_round_id' => $gameRoundId,
            'valid_bet' => 100,
            'amount' => 80,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides);
    }

    protected function makeBetAndSettleParams(array $overrides = []): array
    {
        return array_merge([
            'player_unique_id' => $this->username,
            'game' => $this->gameCode,
            'game_round_id' => 'round_' . Str::random(8),
            'bet' => 100,
            'win' => 80,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides);
    }

    protected function makeCancelParams(string $gameRoundId, array $overrides = []): array
    {
        return array_merge([
            'player_unique_id' => $this->username,
            'game_round_id' => $gameRoundId,
        ], $overrides);
    }
}
