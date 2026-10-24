<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AiGamingSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\AiGamingSwAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class AiGamingSWTestCase extends TestCase
{
    protected string $provider = 'TESTMERCHANT';

    protected string $apiKey = [REDACTED]';

    protected string $currency = 'MYR';

    protected string $username = 'aigamingsw_00000001';

    protected string $baseurl = '/external/api/sw/aigaming/seamless';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected AiGamingSwAccount $account;

    protected Member|MemberWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::AIGAMINGSW->value);
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->currency = $this->currency;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'PROVIDER' => $this->provider,
            'API_KEY' => $this->apiKey,
        ];
        $this->vendorInfo->save();

        $this->account = AiGamingSwAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;
    }

    /**
     * 依 api.md 各端點簽名串接規則產生 sign（md5 轉小寫）
     */
    protected function signedPostData(string $method, array $params): array
    {
        $params = array_merge([
            'username' => $this->username,
            'walletType' => $this->currency,
            'timestamp' => (string) (int) round(microtime(true) * 1000),
        ], $params);

        $fields = match ($method) {
            'getBalance' => $params['timestamp'] . $params['username'],
            'deduct' => $params['timestamp'] . $params['username'] . $params['amount']
                . $params['transactionId'] . $params['betTime'] . $params['gameTypeName'],
            'settle' => $params['timestamp'] . $params['username'] . $params['transactionId']
                . $params['payoutAmount'] . $params['resultType'] . $params['gameTypeName'] . $params['resultTime'],
            'bonus', 'rollback', 'cashBonus' => $params['timestamp'] . $params['username'] . $params['amount']
                . $params['transactionId'] . $params['walletType'],
        };

        $params['sign'] = strtolower(md5($this->apiKey . $fields));

        return $params;
    }

    /**
     * 先完成一筆 deduct，回傳 transactionId（供 settle / rollback 測試起手）。
     * payload 取自 2026-08-31 dev 實測 callback（tx 459092；gameCode 改為測試遊戲）
     */
    protected function placeDeduct(string $transactionId = '459092', string $amount = '0.4'): string
    {
        $response = $this->postJson("{$this->baseurl}/deduct", $this->signedPostData('deduct', [
            'amount' => $amount,
            'transactionId' => $transactionId,
            'betTime' => '2026-08-31T17:39:21.687229933',
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'gameInfo' => '{}',
            'hasBonus' => true,
        ]));

        $response->assertStatus(200)->assertJson(['errorCode' => 0]);

        return $transactionId;
    }
}
