<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AFBSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\AFBSW\BetRecordStatusEnum;
use App\Vendors\Models\AfbswAccount;
use App\Vendors\Models\AfbswBetRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class AFBSwTestCase extends TestCase
{
    protected string $baseurl = '/external/api/sw/afb';

    protected string $siteId = 'TESTSITE';

    protected string $username = 'afbsw_00000001';

    protected string $gameCode = 'fishingLegend-skg';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected AfbswAccount $account;

    protected Member|MemberWallet $wallet;

    protected ?MemberWallet $rollingChipWallet = null;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::AFBSW->value);

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->currency = 'MYR';
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'SITE_ID' => $this->siteId,
            'CURRENCY' => 'MYR',
            // 與 refundPayload() 帶的 key 一致。API_KEY 未設定時回調一律拒收
            // （見 AFBSwController::isApiKeyValid()），生產五站實查也都有值，
            // fixture 不設等於在測一個現實不存在的狀態。
            'API_KEY' => 'test-api-key',
        ];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = $this->gameCode;
        $this->game->status = 1;
        $this->game->save();

        $this->account = AfbswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;
        $this->rollingChipWallet = $walletDto->rollingChipWallet;
    }

    /**
     * 建立一筆已扣款、尚未結算的 AFB 注單（對應 bet 回調成功後的狀態）
     */
    protected function createPlacedBetRecord(string $betId, string $bet, string $rollingChips = '0'): AfbswBetRecord
    {
        return AfbswBetRecord::create([
            'member_id' => $this->member->id,
            'username' => $this->username,
            'bet_id' => $betId,
            'game_code' => $this->gameCode,
            'bet' => $bet,
            'valid_bet' => $bet,
            'payout' => 0,
            'win_lose' => $bet,
            'rolling_chips' => $rollingChips,
            'bet_time' => now()->toDateTimeString(),
            'payout_time' => null,
            'status' => BetRecordStatusEnum::BET->value,
            'info' => [],
        ]);
    }

    /**
     * AFB refund 回調的請求內容（欄位依 docs/integrations/vendors/AFBSW/api.md §5.5）
     */
    protected function refundPayload(string $transactionId, string $amount): array
    {
        return [
            'method' => 'refund',
            'key' => 'test-api-key',
            'sid' => $this->siteId,
            'username' => $this->username,
            'amount' => $amount,
            'currency' => 'MYR',
            'sessionId' => 'CTX-TEST-SESSION',
            'transactionId' => $transactionId,
            'gameCode' => $this->gameCode,
            'gameName' => 'Fishing Legend',
            'gameType' => 'fishing',
            'ip' => '0.0.0.0',
        ];
    }
}
