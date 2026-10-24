<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\DstPlaySW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\DstPlaySwAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class DstPlaySWTestCase extends TestCase
{
    protected string $apiId = 'DemoApiId';

    protected string $apiKey = [REDACTED]';

    protected string $username = 'dstplaysw_00000001';

    protected string $baseurl = '/external/api/sw/dstplaysw/v1';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected DstPlaySwAccount $account;

    protected Member|MemberWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::DSTPLAYSW->value);
        $this->vendor->save();

        // 對齊測試使用的 GameCode 'TEST'（createBasicVendorGames 預設值），避免被 verifyBettability 擋下
        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'API_ID' => $this->apiId,
            'API_KEY' => $this->apiKey,
        ];
        $this->vendorInfo->save();

        $this->account = DstPlaySwAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
            'session_token' => 'test_session_token',
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;
    }

    /**
     * 依 api.md 通用約定產生 api_id/timestamp/sign
     */
    protected function signedPostData(array $params = []): array
    {
        $timestamp = (string) (int) round(microtime(true) * 1000);

        return array_merge([
            'api_id' => $this->apiId,
            'timestamp' => $timestamp,
            'sign' => md5($this->apiId . $timestamp . $this->apiKey),
        ], $params);
    }
}
