<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WESW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\WeswAccount;
use App\Vendors\Models\WeswBetRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WESW 單一錢包外部回調整合測試共用基底。
 *
 * 對應 controller：app/Http/Controllers/External/SingleWallets/WEController.php
 * 路由前綴：routes/external/sw.php → /external/api/sw/we
 * 端點：validate / balance / debit / credit / rollback / resettlement（genToken 為純開發輔助工具，不測）
 *
 * 錯誤回應以 HTTP status code 表示（WeswErrorCodeEnum），而非 200 + body code，
 * 這點與 PTSW / ClotPlay 不同，斷言時要用 assertStatus() 對應的錯誤碼。
 *
 * 預設情境：單一錢包、rate = 1，金額運算全走 Common::numAdd/Sub/Mul/Div/Compare。
 */
class WeswTestCase extends TestCase
{
    /** vendor_infos.info.operatorId，用來對應商戶 */
    protected string $operatorId = 'WESW_TEST_OPERATOR';

    /** wesw_accounts.username，即 callback 的 playerID */
    protected string $playerId = 'wesw00000001';

    /** vendor_infos.info.appSecret，用於簽名比對 */
    protected string $appSecret = [REDACTED]';

    protected string $baseUrl = '/external/api/sw/we';

    /** 對應 wesw_bet_records.game_code 預設值 'TP01'，同時作為 games.game_type 供 BetHelper 判斷可投注性 */
    protected string $gameCode = 'TP01';

    protected string $currency = 'USD';

    protected float $rate = 1.0;

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected WeswAccount $account;

    protected Member|MemberWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::WESW->value);
        $this->vendor->name = 'WESW';
        $this->vendor->code = 'wesw';
        $this->vendor->tname = 'wesw';
        $this->vendor->third_party_code = 'wesw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'wesw';
        $this->vendorInfo->account_prefix = VendorIdEnum::WESW->value . '_WESW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = $this->rate;
        $this->vendorInfo->currency = $this->currency;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = [
            'operatorId' => $this->operatorId,
            'appSecret' => $this->appSecret,
            'currency' => $this->currency,
        ];
        $this->vendorInfo->save();

        // gameID 必須對應 games.game_type，供 BetHelper::isMemberAbleToBet 判斷可投注性
        $game = $this->vendor->games()->first();
        $game->game_type = $this->gameCode;
        $game->status = 1;
        $game->save();

        $this->account = WeswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->playerId,
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 1,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;
    }

    /**
     * 組合 validate / balance / debit / rollback 共用的基本參數（非 data 陣列端點）。
     */
    protected function baseParams(array $overrides = []): array
    {
        return array_merge([
            'operatorID' => $this->operatorId,
            'appSecret' => $this->appSecret,
            'playerID' => $this->playerId,
        ], $overrides);
    }

    /**
     * 組合 credit / resettlement 端點 data 陣列中的單筆紀錄。
     */
    protected function dataRow(array $overrides = []): array
    {
        return array_merge([
            'operatorID' => $this->operatorId,
            'appSecret' => $this->appSecret,
            'playerID' => $this->playerId,
            'betID' => $this->uniqueId(),
            'type' => 'SETTLE',
            'amount' => '0',
            'validBetAmount' => '0',
            'time' => time(),
        ], $overrides);
    }

    protected function callEndpoint(string $endpoint, array $postData)
    {
        return $this->postJson("{$this->baseUrl}/{$endpoint}", $postData);
    }

    /**
     * 呼叫 credit / resettlement 端點，$rows 為多筆 data 內容。
     */
    protected function callSettleEndpoint(string $endpoint, array $rows)
    {
        return $this->callEndpoint($endpoint, [
            'data' => json_encode($rows),
        ]);
    }

    protected function uniqueId(): string
    {
        return (string) random_int(1_000_000_000, 9_999_999_999);
    }

    /**
     * 透過 debit 端點下注一筆（供 credit / resettlement / rollback 等需要前置注單的測試使用）。
     */
    protected function placeBet(string $betId, float $betAmount = 100): void
    {
        $post = $this->baseParams([
            'betID' => $betId,
            'gameID' => $this->gameCode,
            'gameRoundID' => $this->uniqueId(),
            'type' => 'BET',
            'amount' => $betAmount,
            'time' => time(),
        ]);

        $this->callEndpoint('debit', $post)->assertStatus(200);
    }

    protected function totalBetRecordCount(): int
    {
        return DB::table('total_bet_records')->where('member_id', $this->member->id)->count();
    }

    protected function findBetRecord(string $betId): ?WeswBetRecord
    {
        return WeswBetRecord::where('bet_id', $betId)->first();
    }

    protected function refreshBalance(): string
    {
        $this->wallet->refresh();

        return (string) $this->wallet->balance;
    }
}
