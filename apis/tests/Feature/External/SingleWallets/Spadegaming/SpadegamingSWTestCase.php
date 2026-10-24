<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\SpadegamingswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SpadegamingSW 測試基底類別
 *
 * 建立所有 SpadegamingSW 測試共用的環境：
 * - 建立代理商、會員、Vendor / VendorInfo / Game
 * - 建立 SpadegamingswAccount（遊戲帳號與會員的對應關係）
 * - 初始化真金錢包與禮碼錢包
 * - 停用 SpadegamingDigestCheck middleware（測試環境無法產生合法簽名）
 *
 * 所有金額單位為「遊戲商幣值」，與平台幣值之間以 vendorInfo->rate 換算。
 */
class SpadegamingSWTestCase extends TestCase
{
    protected $defaultSerialNo = 'dfc86dbc-4dc6-454b-ae05-bb56fcc4f8ae';

    protected $merchantCode = 'ZCH123456';

    protected $acctId = '81_SGSW00000001';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected SpadegamingswAccount $account;

    protected Member|MemberWallet $wallet;

    protected MemberWallet $rollingChipWallet;

    protected Game $game;

    const API_SPADEGAMINGSW_ENDPOINT = '/external/api/sw/spadegaming';

    protected string $securityKey = 'unit-test-security-key';

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->headers['DataType'] = 'JSON';
        $this->headers['API'] = 'transfer';

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::SPADEGAMINGSW->value);
        $this->vendor->name = 'SpadeGamingSW';
        $this->vendor->code = 'spadegamingsw';
        $this->vendor->tname = 'spadegamingsw';
        $this->vendor->third_party_code = 'SGSW';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'spadegamingsw';
        $this->vendorInfo->account_prefix = VendorIdEnum::SPADEGAMINGSW->value . '_SGSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 2.0;
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'GAME_URL' => '',
            'Merchant_Code' => $this->merchantCode,
            'CURRENCY' => 'MYR',
            'Security_key' => $this->securityKey,
        ];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = Str::random(10);
        $this->game->status = 1;
        $this->game->save();

        $this->account = SpadegamingswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->acctId,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDTO->wallet;
        $this->rollingChipWallet = $walletDTO->rollingChipWallet;
    }

    /**
     * 自動替 SpadegamingSW 的回調簽 digest。
     *
     * digest 驗證已從 middleware 併進 `SpadegamingSWController::callAction()`，測試不能再用
     * `withoutMiddleware()` 繞過。與其讓每個呼叫點各自簽一次，在這裡統一補。
     * 算法必須與 controller 的 `verifyDigest()` 一致：md5(正規化後的 body . Security_key)。
     * 自行帶 `digest` header 的測試（驗簽失敗案例）不受影響。
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($uri === self::API_SPADEGAMINGSW_ENDPOINT && !isset($headers['digest'])) {
            $headers['digest'] = md5(json_encode(json_decode(json_encode($data))) . $this->securityKey);
        }

        return parent::postJson($uri, $data, $headers, $options);
    }
}
