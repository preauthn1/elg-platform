<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\JokerBaccaratSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\JkbswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * JokerBaccaratSW callback 共用測試基底。
 * 對應 controller：app/Http/Controllers/External/SingleWallets/JokerBaccaratSwController.php
 */
class JokerBaccaratSwTestCase extends TestCase
{
    protected string $apiId = 'JKB_OP';

    protected string $apiToken = [REDACTED]';

    protected string $playerId = 'JKB_demo';

    /**
     * BR / TBR 共用 game_code（對齊 controller 內 GAME_CODE）。
     */
    protected string $gameCode = 'lobby';

    protected string $baseurl = '/external/api/sw/jokerbaccarat';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected JkbswAccount $account;

    protected Member|MemberWallet $wallet;

    protected ?MemberWallet $rollingChipWallet = null;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::JOKER_BACCARAT_SW->value);
        $this->vendor->save();

        // migration `add_jkbsw_lobby_game` 已預埋一筆 game_type='lobby' 的 row，但 agent_game / vendor_info_game
        // 是由 createBasicVendorGames 掛在另一筆 row 上；先移除 migration 預埋的孤立 row，再把測試錨點 row 改名對齊 GAME_CODE，
        // 避免 BetHelper 透過 game_type 撈到沒 attach agent_game 的那筆。
        $this->vendor->games()->where('game_type', $this->gameCode)->delete();
        $this->vendor->games()->first()->update(['game_type' => $this->gameCode]);

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'JKB_';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'API_ID' => $this->apiId,
            'API_TOKEN' => $this->apiToken,
            'CURRENCY' => 'MYR',
        ];
        $this->vendorInfo->save();

        $this->account = JkbswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->playerId,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);
        $this->rollingChipWallet = $walletDto->rollingChipWallet;
        $this->wallet = $walletDto->wallet;
    }

    /**
     * balance 端點 request body（action=1）。
     */
    protected function balancePayload(array $overrides = []): array
    {
        return array_merge([
            'playerID' => $this->playerId,
            'action' => 1,
            'actionDesc' => 'Get Balance',
        ], $overrides);
    }

    /**
     * transfer 端點 request body（action 2/3/4/5）。
     */
    protected function transferPayload(int $action, string $transactionNo, string $roundID, string $amount, array $overrides = []): array
    {
        $actionDesc = match ($action) {
            2 => 'Bet',
            3 => 'Win',
            4 => 'Refund',
            5 => 'Rollback',
            default => 'Unknown',
        };

        return array_merge([
            'playerID' => $this->playerId,
            'action' => $action,
            'actionDesc' => $actionDesc,
            'transactionNo' => $transactionNo,
            'roundID' => $roundID,
            'amount' => $amount,
        ], $overrides);
    }
}
