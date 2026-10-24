<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TTHSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\TTHSWAccount;
use App\Vendors\TTHSW\Helpers\SignatureHelper;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * TTHSW 測試基底類別
 *
 * 建立所有 TTHSW 測試共用的環境：代理商、會員、Vendor / VendorInfo / Game、TTHSWAccount 與錢包。
 *
 * 與其他單一錢包不同的是驗證方式：TTHSW 走 header `X-Sign` + `X-Ts`（見 SignatureHelper），
 * 沒有可以停用的 middleware，所以測試自己用同一支 helper 產生合法簽名（見 signedHeaders）。
 *
 * 金額單位為「遊戲商幣值」，與平台幣值之間以 vendorInfo->rate 換算。
 */
class TTHSWTestCase extends TestCase
{
    protected string $apiKey = [REDACTED]';

    protected string $currency = 'MYR';

    protected string $providerCode = 'TTH';

    /** 同一支 controller 服務二十餘個 TTHSW_* sub-vendor，子類可覆寫成要測的那一個 */
    protected int $vendorId = VendorIdEnum::TTHSW->value;

    /** 於 setUp 依 vendorInfo->account_prefix 組出，不硬編前綴 */
    protected string $playerCode = '';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected TTHSWAccount $account;

    protected Member|MemberWallet $wallet;

    /** 預設不開禮碼（rolling_chip_status = 0）故為 null；子類若開啟，這裡就會有值 */
    protected ?MemberWallet $rollingChipWallet = null;

    protected Game $game;

    const API_TTHSW_REFUND = '/external/api/sw/tthsw/refund';

    const API_TTHSW_DEDUCT = '/external/api/sw/tthsw/deduct';

    const API_TTHSW_COMPLETE = '/external/api/sw/tthsw/complete';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, $this->vendorId);
        $this->vendor->name = 'TTHSW';
        $this->vendor->code = 'tthsw';
        $this->vendor->tname = 'tthsw';
        $this->vendor->third_party_code = 'TTHSW';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'tthsw';
        $this->vendorInfo->account_prefix = $this->vendorId . '_TTHSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->currency = $this->currency;
        $this->vendorInfo->rolling_chip_status = 0;
        // info 欄位已 cast 成 array（VendorInfo::info），這裡指派陣列而非 json_encode 後的字串，
        // 否則會被再編碼一次、controller 讀到的 $vendorConfig 會是字串
        $this->vendorInfo->info = [
            'API_URL' => '',
            'GAME_URL' => '',
            'API_KEY' => $this->apiKey,
            'CURRENCY' => $this->currency,
            'BALANCE_SCALE' => 4,
        ];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        // 固定字面而非隨機值：game_type 會被子類當成 GameCode 送進 BetHelper::isMemberAbleToBet()，
        // 隨機值會讓失敗案例無法重現（對照 PtswTestCase 的 'ubal'）
        $this->game->game_type = 'tth-slot';
        $this->game->status = 1;
        $this->game->save();

        $this->playerCode = $this->vendorInfo->account_prefix . '00000001';

        $this->account = TTHSWAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->playerCode,
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 1,
        ]);

        $walletDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDTO->wallet;
        $this->rollingChipWallet = $walletDTO->rollingChipWallet;
    }

    /**
     * 產生合法的簽名 header：用的是 controller 驗簽時同一支 SignatureHelper，
     * 所以測試不需要複製簽名規則，規則改了測試也跟著走。
     *
     * ⚠️ 界線：這對「簽名以外」的測試是對的取捨，但**驗簽本身的測試不能用這支 helper**——
     * 產生與驗證都走同一份實作時，簽名算錯兩邊會一起錯而測試照樣綠。要測簽名請自備獨立的期望值
     * （既有的 tests/Unit/Vendors/TTHSW/SignatureTest.php 即是此作法）。
     */
    protected function signedHeaders(array $data): array
    {
        // X-Ts 是「毫秒」時間戳（SignatureHelper::verifyTimestamp 以毫秒比對，容差 300 秒）
        $timestamp = (int) (microtime(true) * 1000);

        return [
            'X-Sign' => SignatureHelper::generateSignature($data, $this->apiKey, $timestamp),
            'X-Ts' => (string) $timestamp,
        ];
    }

    protected function postSigned(string $uri, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($uri, $data, $this->signedHeaders($data));
    }
}
