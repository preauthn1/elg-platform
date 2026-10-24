<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\VpowerSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\VpowerswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * Vpower 單一錢包測試基底類別
 * 負責初始化測試所需的代理、會員、遊戲商資訊、錢包與帳號設定
 */
class VpowerSWTestCase extends TestCase
{
    protected string $username = 'TESTVPSW00000001';

    protected string $appSecret = [REDACTED]';

    protected string $baseurl = '/external/api/sw/vpower';

    protected int $gameCode = 10000134;

    protected Vendor $vendor;

    protected ?VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected VpowerswAccount $account;

    protected MemberWallet|Member $wallet;

    protected MemberWallet $rollingChipWallet;

    /**
     * 測試環境初始化
     */
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        // 植入必要的系統設定
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        // 建立測試用的代理與會員
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        // 建立 VpowerSW 遊戲商資訊
        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::VPOWERSW->value);
        $this->vendor->name = 'Vpower SW';
        $this->vendor->code = 'vpowersw';
        $this->vendor->tname = 'vpowersw';
        $this->vendor->third_party_code = 'vpowersw';
        $this->vendor->save();

        // 設定遊戲商詳細參數 (如匯率、密鑰等)
        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'vpowersw';
        $this->vendorInfo->account_prefix = VendorIdEnum::VPOWERSW->value . '_';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 2.0; // 設定匯率為 2.0 (1 平台幣 = 2 遊戲商幣)
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = json_encode([
            'API_URL' => '',
            'GAME_URL' => '',
            'APP_ID' => 'knldfgli5sdfglkhn',
            'APP_SECRET' => $this->appSecret,
        ]);
        $this->vendorInfo->save();

        // 建立測試遊戲
        $game = $this->vendor->games()->first();
        $game->vendor_id = $this->vendor->id;
        $game->game_type = $this->gameCode;
        $game->status = 1;
        $game->save();

        // 建立會員在該遊戲商的帳號關聯
        $this->account = VpowerswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        // 建立禮碼(反水)錢包
        $this->rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP,
            'balance' => 0,
        ]);

        $this->wallet = $this->member;
    }

    /**
     * 依照 Vpower 規範產生簽名 (Sign)
     */
    protected function makeSign(array $data): string
    {
        return md5(json_encode($data) . $this->appSecret);
    }

    /**
     * 開啟禮碼錢包功能
     */
    protected function enableRollingChip(): void
    {
        $this->vendorInfo->update(['rolling_chip_status' => true]);
    }
}
