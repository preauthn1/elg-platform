<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\RGSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\RgswAccount;
use App\Vendors\Models\RgswBetRecord;
use App\Vendors\RGSW\Enums\BetRecordStatusEnum;
use App\Vendors\RGSW\Enums\StatusCodeEnum;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RGSW 測試基底類別
 *
 * 建立所有 RGSW 測試共用的環境：代理商、會員、Vendor / VendorInfo / Game、RgswAccount 與錢包。
 *
 * 驗證方式：RG 走 header `wtoken`，值就是 vendorInfo->info['token']（見 RgswController::verifyWtoken），
 * 沒有簽名可算，所以測試只要帶對 token 就是合法請求。IP 白名單走
 * `Common::verifyIPWhitelistByVendorId()`，測試建立的 vendor 沒有設 whitelist，該函式第一個判斷就放行，
 * 測試不需要處理。
 *
 * 金額單位為「遊戲商幣值」，與平台幣值之間以 vendorInfo->rate 換算；預設 rate 固定 1.0 讓多數測試
 * 專注在重送有沒有被擋掉，要驗換算的子類覆寫 $rate 即可。
 */
class RgswTestCase extends TestCase
{
    protected string $token = [REDACTED]';

    protected string $currency = 'MYR';

    /** 遊戲商幣值與平台幣值的換算率；子類覆寫即可測 rate ≠ 1 的白標 */
    protected float $rate = 1.0;

    /** 禮碼錢包開關；子類覆寫成 1 就能測到 changeBalance() 的禮碼退還分支 */
    protected int $rollingChipStatus = 0;

    /** 於 setUp 依 vendorInfo->account_prefix 組出，不硬編前綴 */
    protected string $playerCode = '';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected RgswAccount $account;

    protected Member|MemberWallet $wallet;

    /** 預設不開禮碼（rolling_chip_status = 0）故為 null；子類若開啟，這裡就會有值 */
    protected ?MemberWallet $rollingChipWallet = null;

    protected Game $game;

    const API_RGSW_REFUND = '/external/api/sw/rg/game/refund';

    const API_RGSW_DEBIT = '/external/api/sw/rg/game/debit';

    const API_RGSW_CREDIT = '/external/api/sw/rg/game/credit';

    const API_RGSW_SUM_BET = '/external/api/sw/rg/game/sumBet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::RGSW->value);
        $this->vendor->name = 'RGSW';
        $this->vendor->code = 'rgsw';
        $this->vendor->tname = 'rgsw';
        $this->vendor->third_party_code = 'RGSW';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'rgsw';
        $this->vendorInfo->account_prefix = VendorIdEnum::RGSW->value . '_RGSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = $this->rate;
        $this->vendorInfo->currency = $this->currency;
        $this->vendorInfo->rolling_chip_status = $this->rollingChipStatus;
        // info 欄位已 cast 成 array，這裡指派陣列而非 json_encode 後的字串，
        // 否則會被再編碼一次、controller 讀到的 info['token'] 會取不到值
        $this->vendorInfo->info = [
            'API_URL' => '',
            'GAME_URL' => '',
            'token' => $this->token,
            'CURRENCY' => $this->currency,
        ];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        // 固定字面而非隨機值：隨機值會讓失敗案例無法重現（對照 PtswTestCase 的 'ubal'）
        $this->game->game_type = 'rg-slot';
        $this->game->status = 1;
        $this->game->save();

        $this->playerCode = $this->vendorInfo->account_prefix . '00000001';

        $this->account = RgswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->playerCode,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);

        $walletDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDTO->wallet;
        $this->rollingChipWallet = $walletDTO->rollingChipWallet;
    }

    /** RG 的回調驗證只靠 header wtoken（見 RgswController::verifyWtoken） */
    protected function postWithToken(string $uri, array $data): TestResponse
    {
        return $this->postJson($uri, $data, ['wtoken' => $this->token]);
    }

    /**
     * 斷言「這是一個成功回應」。
     *
     * ⚠️ 不可以只用 assertOk()：RgswController 的 errorResponse() 沒帶狀態碼，錯誤同樣回 HTTP 200
     * （狀態放在 body 的 status.code），所以 assertOk() 對這支 controller 是恆真斷言，
     * 「守衛擋掉並回成功」與「第二次請求直接出錯」會長得一模一樣。
     */
    protected function assertRgSuccess(TestResponse $response): TestResponse
    {
        return $response->assertOk()->assertJsonPath('status.code', StatusCodeEnum::SUCCESS->value);
    }

    protected function setBalance(string $balance): void
    {
        $this->wallet->update(['balance' => $balance]);
    }

    protected function currentBalance(): string
    {
        return (string) $this->wallet->fresh()->balance;
    }

    /**
     * 建一筆「已結算」注單：betPayout 一次下注加結算，是 TPower 生產上 99.7% 的樣態
     * （2026-09-09 實測 180,527 / 180,970 列為 BETPAYOUT）。
     */
    protected function createSettledBetRecord(string $betId, string $bet, string $win, ?string $validBet = null): RgswBetRecord
    {
        return RgswBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'username' => $this->playerCode,
            'bet_id' => $betId,
            'reference_no' => $betId,
            'game_code' => 'rg-test-game',
            'game_type' => $this->game->game_type,
            'status' => BetRecordStatusEnum::BETPAYOUT->value,
            'bet' => $bet,
            'valid_bet' => $validBet ?? $bet,
            'win' => $win,
            'win_lose' => Common::numSub($win, $bet, 4),
            'bet_time' => now()->toDateTimeString(),
            'payout_time' => now()->toDateTimeString(),
            'info' => json_encode([
                'bet_no' => $betId,
                'order_sn' => $betId,
                'username' => $this->playerCode,
            ]),
        ]);
    }

    /**
     * 建一筆「未結算」注單：下注已扣款、還沒開獎（payout_time 帶 controller 用的 sentinel）。
     *
     * `$validBet` 可單獨指定，因為 bet() 允許 bet_real < bet_total（`valid_bet < bet`），
     * 那是部分退款把 valid_bet 減到負數、需要被夾到 0 的情境。
     */
    protected function createPendingBetRecord(string $betId, string $bet, ?string $validBet = null): RgswBetRecord
    {
        return RgswBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'username' => $this->playerCode,
            'bet_id' => $betId,
            'reference_no' => $betId,
            'game_code' => 'rg-test-game',
            'game_type' => $this->game->game_type,
            'status' => BetRecordStatusEnum::BET->value,
            'bet' => $bet,
            'valid_bet' => $validBet ?? $bet,
            'win' => 0,
            'win_lose' => Common::numMul($bet, -1, 4),
            'bet_time' => now()->toDateTimeString(),
            'payout_time' => '1970-01-01 00:00:00',
            'info' => json_encode([
                'bet_no' => $betId,
                'order_sn' => $betId,
                'username' => $this->playerCode,
            ]),
        ]);
    }
}
