<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\EpicWinSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\EpicSwAccount;
use App\Vendors\Models\EpicSwBetRecord;
use App\Vendors\WFSW\OperatorStatusEnum;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EpicWin 單一錢包（EpicSw）外部回調整合測試共用基底。
 *
 * 對應 controller：app/Http/Controllers/External/SingleWallets/EpicWinController.php
 * 路由前綴：routes/external/sw.php → /external/api/sw/epic
 * 端點：GetBalance / Bet / GameResult / Rollback / CashBonus / Jackpot
 *
 * 預設情境：單一錢包、rolling_chip 關閉、rate = 1。各測試自行覆寫餘額與參數。
 */
class EpicWinSWTestCase extends TestCase
{
    /** vendor_infos.info.OPERATOR_ID，用來對應商戶 */
    protected string $operatorId = 'EPICTESTMYR';

    /** epic_sw_accounts.username，即 callback 的 PlayerId */
    protected string $playerId = '85_EPIC00000001';

    /** vendor_infos.info.SECRET_KEY，用於 md5 簽名 */
    protected string $secretKey = 'test_secret_key';

    protected string $baseUrl = '/external/api/sw/epic';

    protected string $gameCode = 'TEST';

    protected string $currency = 'MYR';

    protected float $rate = 1.0;

    protected string $requestDateTime = '2024-08-20 12:24:35';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected EpicSwAccount $account;

    protected MemberWallet $rollingChipWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::EPICSW->value);
        $this->vendor->name = 'Epic Win SW';
        $this->vendor->code = 'epicsw';
        $this->vendor->tname = 'epicsw';
        $this->vendor->third_party_code = 'epicsw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'epicsw';
        $this->vendorInfo->account_prefix = VendorIdEnum::EPICSW->value . '_EPIC';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = $this->rate;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = [
            'OPERATOR_ID' => $this->operatorId,
            'SECRET_KEY' => $this->secretKey,
            'CURRENCY' => $this->currency,
        ];
        $this->vendorInfo->save();

        $game = $this->vendor->games()->first();
        $game->game_type = $this->gameCode;
        $game->status = 1;
        $game->save();

        $this->account = EpicSwAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->playerId,
            'member_id' => $this->member->id,
            'session_token' => '',
            'admin' => 1,
        ]);

        $this->rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => 0,
        ]);
    }

    /**
     * 組合所有端點共用的基本參數；Signature 由各測試呼叫 regenerateSignature 後覆寫。
     */
    protected function baseParams(array $overrides = []): array
    {
        return array_merge([
            'PlayerId' => $this->playerId,
            'OperatorId' => $this->operatorId,
            'Currency' => $this->currency,
            'RequestDateTime' => $this->requestDateTime,
            'AuthToken' => '',
            'Signature' => '',
        ], $overrides);
    }

    /**
     * 依 EpicWinController::checkSign 的規則重算簽名並寫回 $postData。
     * functionName 必須與 URL 末段一致（GetBalance / Bet / GameResult / Rollback / CashBonus / Jackpot）。
     */
    protected function regenerateSignature(string $functionName, array &$postData): void
    {
        $uniqueId = match ($functionName) {
            'Bet', 'Rollback' => (string) ($postData['BetId'] ?? ''),
            'GameResult' => (string) ($postData['ResultId'] ?? ''),
            'CashBonus', 'Jackpot' => (string) ($postData['TranId'] ?? ''),
            default => '',
        };

        $postData['Signature'] = md5(
            $functionName
            . $uniqueId
            . ($postData['RequestDateTime'] ?? '')
            . ($postData['OperatorId'] ?? '')
            . $this->secretKey
            . ($postData['PlayerId'] ?? '')
        );
    }

    protected function callEndpoint(string $functionName, array $postData)
    {
        return $this->postJson("{$this->baseUrl}/{$functionName}", $postData);
    }

    /**
     * 直接建立一筆 epic_sw_bet_records；欄位模擬 Bet 端點寫入後的狀態（win_lose = -bet）。
     */
    protected function createBetRecord(string $betId, float $bet, int $status = 0, string $type = 'N'): EpicSwBetRecord
    {
        return EpicSwBetRecord::create([
            'type' => $type,
            'bet_id' => $betId,
            'username' => $this->playerId,
            'game_code' => $this->gameCode,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => Common::numSub('0', (string) $bet, 4),
            'rolling_chip' => 0,
            'info' => '[]',
            'bet_time' => now()->toDateTimeString(),
            'payout_time' => '1970-01-01 00:00:00',
            'status' => $status,
        ]);
    }

    protected function uniqueId(): string
    {
        return (string) random_int(1_000_000_000, 9_999_999_999);
    }

    /**
     * 透過 Bet 端點下注一筆（供 GameResult / Rollback 等需要前置注單的測試使用）。
     */
    protected function placeBet(string $betId, float $betAmount = 100): void
    {
        $post = $this->baseParams([
            'BetId' => $betId,
            'RoundId' => $this->uniqueId(),
            'GameCode' => $this->gameCode,
            'GameType' => 'Slot',
            'BetAmount' => $betAmount,
            'ExchangeRate' => 1,
            'TranDateTime' => $this->requestDateTime,
        ]);
        $this->regenerateSignature('Bet', $post);
        $this->callEndpoint('Bet', $post)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);
    }

    protected function totalBetRecordCount(): int
    {
        return DB::table('total_bet_records')->where('member_id', $this->member->id)->count();
    }
}
