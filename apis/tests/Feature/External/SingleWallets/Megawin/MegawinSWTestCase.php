<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Megawin;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\MegawinSW\Datas\MegaWinSWVendorInfoDetailData;
use App\Vendors\MegawinSW\Externals\CipherService;
use App\Vendors\Models\MegawinswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class MegawinSWTestCase extends TestCase
{
    protected $baseurl = '/external/api/sw/megawin';

    protected $siteId = '12345';

    protected $mwCode = 'test_mw_code';

    protected $merchantId = '67890';

    protected $firmId = '11111';

    protected $username = 'test_username';

    protected $user_token = [REDACTED]';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected MegawinswAccount $account;

    protected Member|MemberWallet $wallet;

    protected MemberWallet $rollingChipWallet;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        // 跳過所有 middleware，讓 postJson 直接以明文 params 呼叫 endpoint，不需加解密
        $this->withoutMiddleware();

        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($res, $privateKey);

        $publicKeyDetails = openssl_pkey_get_details($res);
        $publicKey = $publicKeyDetails['key'];

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::MEGAWINSW->value);
        $this->vendor->name = 'MegawinSW';
        $this->vendor->code = 'megawinsw';
        $this->vendor->tname = 'megawinsw';
        $this->vendor->third_party_code = 'megawinsw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'megawinsw';
        $this->vendorInfo->account_prefix = VendorIdEnum::MEGAWINSW->value . '_MWSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 2.0;
        $this->vendorInfo->currency = 'HKD';
        $this->vendorInfo->rolling_chip_status = true;
        $this->vendorInfo->info = json_encode([
            'SITE_URL' => 'http://example.invalid/',
            'SITE_ID' => $this->siteId,
            'MW_PUBLIC_KEY' => base64_encode(random_bytes(64)),
            'EC_PUBLIC_KEY' => base64_encode($publicKey),
            'EC_PRIVATE_KEY' => base64_encode($privateKey),
            'MW_CODE' => $this->mwCode,
            'MERCHANT_ID' => $this->merchantId,
            'FIRM_ID' => $this->firmId,
            'CURRENCY' => 'HKD',
            'IS_TEST' => 'Y',
        ]);
        $this->vendorInfo->save();

        // MegaWinRequestDecrypt middleware 被跳過，需手動將 VendorInfo 設定注入 container
        app()->singleton(
            MegaWinSWVendorInfoDetailData::class,
            fn () => MegaWinSWVendorInfoDetailData::from(json_decode($this->vendorInfo->info, true))
        );

        // MW_PUBLIC_KEY 為假 bytes，無法做真實 RSA 加密；mock encrypt 讓 response 直接回傳明文以便斷言
        $this->mock(CipherService::class, function ($mock) {
            $mock->shouldReceive('encrypt')->andReturnUsing(fn (array $data) => $data);
        });

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = random_int(1000, 9999);
        $this->game->status = 1;
        $this->game->save();

        $this->account = MegawinswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'user_token' => $this->user_token,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDatas = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDatas->wallet;
        $this->rollingChipWallet = $walletDatas->rollingChipWallet;
    }
}
