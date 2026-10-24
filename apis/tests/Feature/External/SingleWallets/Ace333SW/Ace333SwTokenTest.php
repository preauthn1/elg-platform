<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Ace333SW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\Models\Ace333SwAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Ace333SW 回調的商戶 token（target/op_tickets#3085）
 *
 * api.md「認證方式（Token）」：Wallet API 回調在 header 帶 `token`，代理側須驗證；本平台由
 * `getVendorInfo()` 比對 `vendor_infos.info.CLIENT_ID`。`getVendorInfo('')` 的語意是「退回該玩家所屬商戶」，
 * 會整段跳過比對——所以 header 缺漏或為空時必須在入口就拒收，與 token 錯誤走同一條回應。
 *
 * php artisan test tests/Feature/External/SingleWallets/Ace333SW/Ace333SwTokenTest.php --compact
 */
class Ace333SwTokenTest extends TestCase
{
    private const BASE_URL = '/external/api/sw/ace333sw';

    /** 商戶識別（明顯假值） */
    private const CLIENT_ID = 'ace333-test-client';

    private const PLAYER_ID = '81678141';

    private Agent $agent;

    private Member $member;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::ACE333SW->value);
        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $vendor->id)->firstOrFail();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->currency = 'MYR';
        $this->vendorInfo->info = [
            'CLIENT_ID' => self::CLIENT_ID,
            'CLIENT_SECRET' => 'not-a-real-secret',
            'CURRENCY' => 'MYR',
        ];
        $this->vendorInfo->save();

        Ace333SwAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => 'ace333test_00000001',
            'member_id' => $this->member->id,
            'vendor_username' => 'ace333test_00000001',
            'vendor_uid' => self::PLAYER_ID,
        ]);

        $this->member->update(['balance' => '100.0000']);
    }

    private function getBalanceUrl(): string
    {
        return self::BASE_URL . '/getbalance?playerID=' . self::PLAYER_ID;
    }

    #[Group('rules/vendor-acewin#R-18')]
    public function test_未帶token的回調一律拒收(): void
    {
        $this->getJson($this->getBalanceUrl())
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid token']);
    }

    #[Group('rules/vendor-acewin#R-18')]
    public function test_token為空字串的回調一律拒收(): void
    {
        $this->getJson($this->getBalanceUrl(), ['token' => ''])
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid token']);
    }

    #[Group('rules/vendor-acewin#R-18')]
    public function test_token錯誤的回調拒收(): void
    {
        $this->getJson($this->getBalanceUrl(), ['token' => 'wrong-client'])
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid token']);
    }

    #[Group('rules/vendor-acewin#R-24')]
    public function test_token正確的回調照常回傳餘額(): void
    {
        $this->getJson($this->getBalanceUrl(), ['token' => self::CLIENT_ID])
            ->assertStatus(200)
            ->assertJson(['error' => '0', 'balance' => 100.0]);
    }
}
