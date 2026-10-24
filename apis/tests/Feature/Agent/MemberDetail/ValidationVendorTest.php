<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberDetail;

use App\Enums\Vendor\VendorIdEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Member;
use App\Services\Agent\MemberDetailVendorService;
use App\Vendors\I1betnow\Driver as I1betnowDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 會員詳情抽屜（4274）第二批：遊戲商資料兩支。
 * 原「代理自訂認證項目」三支（validations／validate-toggle／validation-review）已移除——
 * 正式站零資料、前台無入口；實名認證改由 view 回傳 KYC 申請狀態（見 MemberDetailTest）。
 * vendor-username 的帳號查詢一律用「無 driver 的歷史商戶」形狀測（新建 vendor 並把該 id 的
 * driver 設為空）：走「前綴＋我方 username」顯示，也是 vendor_infos 前綴真正被讀取的路徑；
 * 有 driver 時的真實帳號欄位由 AbstractAccount 契約的 parity 測試另行釘住；本檔只另測一個
 * 有 driver 的案例（i1betnow），釘住顯示規則取自 driver 的具體 account model。
 *
 * php artisan test tests/Feature/Agent/MemberDetail/ValidationVendorTest.php
 */
class ValidationVendorTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain(['host_id' => 0]);
        $this->member = $this->createMember($this->agent, [
            'username' => 'vv' . bin2hex(random_bytes(6)),
            'is_validate' => 0,
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function api(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/member/detail/' . $action, $payload, $this->headers);
    }

    public function test_vendor_list_returns_enabled_vendors(): void
    {
        DB::table('vendors')->insert([
            'name' => 'TestVendor4274',
            'code' => 'tv4274',
            'rate' => 1,
            'percentage' => 0,
            'info' => '{}',
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->api('vendor-list');

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertContains('TestVendor4274', array_column($response->json('data'), 'name'));
    }

    public function test_vendor_username_gate_paths(): void
    {
        // vendor 不存在
        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => 999999,
        ]);
        $this->assertNotSame(200, $response->json('code'), 'vendor 不存在必擋');

        // 跨線會員
        $originalDomain = $this->domain;
        $this->domain = Str::random(12) . '.test.local';
        $otherAgent = $this->createAgentAndDomain(['host_id' => 0]);
        $this->domain = $originalDomain;
        $outsideMember = $this->createMember($otherAgent, ['username' => 'ox' . bin2hex(random_bytes(5))]);

        $response = $this->api('vendor-username', [
            'member_id' => $outsideMember->id,
            'vendor_id' => 1,
        ]);
        $this->assertNotSame(200, $response->json('code'), '跨線會員必擋（C-T7）');
    }

    /**
     * 正式線與活動線帳號都是有效帳號，全部回傳，依 vendor_infos.type 升冪排序。
     * 活動線帳號先建（主鍵較小），釘住排序依據是 type 而不是主鍵或建立順序。
     */
    public function test_vendor_username_returns_all_lines_ordered_by_type(): void
    {
        $vendorId = $this->createDriverlessVendor('scr');
        $this->insertScrAccount($this->createVendorInfo($vendorId, 2, 'E-'), 'eventuser');
        $this->insertScrAccount($this->createVendorInfo($vendorId, 1, 'N-'), 'normaluser');

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'N-normaluser', 'vendor_info_type' => 1],
            ['username' => 'E-eventuser', 'vendor_info_type' => 2],
        ], $response->json('data'));
    }

    /**
     * 同一張 {tname}_accounts 被多個 vendors 共用時，只回傳本遊戲商啟用中線路的帳號：
     * 停用線（vendor_infos.status=0）、帳號本身停用（status=0）、其他遊戲商的帳號都要排除。
     */
    public function test_vendor_username_scopes_to_enabled_lines_of_requested_vendor(): void
    {
        $vendorId = $this->createDriverlessVendor('scr');
        $otherVendorId = $this->createDriverlessVendor('scr');

        $this->insertScrAccount($this->createVendorInfo($vendorId, 0, 'D-', status: 0), 'disabledline');
        $enabledInfoId = $this->createVendorInfo($vendorId, 1, 'N-');
        $this->insertScrAccount($enabledInfoId, 'disabledaccount', status: 0);
        $this->insertScrAccount($enabledInfoId, 'normaluser');
        $this->insertScrAccount($this->createVendorInfo($otherVendorId, 1, 'O-'), 'othervendor');

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'N-normaluser', 'vendor_info_type' => 1],
        ], $response->json('data'));
    }

    /**
     * 查詢數不得隨帳號筆數增加：前綴隨 join vendor_infos 一併選出，不逐列查 vendor_infos。
     * 直接呼叫 Service，先暖機一次排除首次呼叫才有的查詢，再比較 1 筆與 2 筆的查詢數。
     */
    public function test_vendor_username_query_count_does_not_grow_with_accounts(): void
    {
        $vendorId = $this->createDriverlessVendor('scr');
        $this->insertScrAccount($this->createVendorInfo($vendorId, 1, 'N-'), 'normaluser');
        $service = app(MemberDetailVendorService::class);
        $service->vendorUsername($this->agent, $this->member->id, $vendorId);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertCount(1, $service->vendorUsername($this->agent, $this->member->id, $vendorId));
        $oneAccount = $queries;

        $this->insertScrAccount($this->createVendorInfo($vendorId, 2, 'E-'), 'eventuser');
        $queries = 0;
        $this->assertCount(2, $service->vendorUsername($this->agent, $this->member->id, $vendorId));

        $this->assertSame($oneAccount, $queries, '查詢數不得隨帳號筆數增加');
    }

    /**
     * 沒有 vendor_info_id／status 欄位的歷史帳號表（此處借用 event_pgssw_accounts 的表結構）：
     * 不 join vendor_infos，回傳無前綴的我方 username、vendor_info_type 為 null。
     * 刻意建 2 筆：顯示邏輯若改讀沒預載的 vendorInfo 關聯，延遲載入防護只在一次撈回
     * 多筆時才掛上，1 筆時不會紅。
     */
    public function test_vendor_username_without_vendor_info_column_returns_bare_username(): void
    {
        $vendorId = $this->createDriverlessVendor('event_pgssw');
        foreach (['legacyfirst', 'legacysecond'] as $username) {
            DB::table('event_pgssw_accounts')->insert([
                'username' => $username,
                'member_id' => $this->member->id,
                'session_token' => '',
                'admin' => 0,
            ]);
        }

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'legacyfirst', 'vendor_info_type' => null],
            ['username' => 'legacysecond', 'vendor_info_type' => null],
        ], $response->json('data'));
    }

    /**
     * 有 driver 時，顯示字串交給 driver 的具體 account model 決定（target/op_tickets#3264）：
     * i1betnow 的 username_modified 是「ACCESS_ID@我方 username」，LbbAccount 顯示時去掉前綴。
     * 查詢用的 Model 是匿名子類、只有「回原值」的預設版，顯示若改用它會變回帶前綴，這支會紅。
     */
    public function test_vendor_username_uses_driver_model_display_rule(): void
    {
        $vendorId = $this->createDriverlessVendor('lbb');
        config(['vendor.driver.' . $vendorId => I1betnowDriver::class]);

        DB::table('lbb_accounts')->insert([
            'vendor_info_id' => $this->createVendorInfo($vendorId, 1, 'N-'),
            'username' => 'lbbuser',
            'username_modified' => '94271446@lbbuser',
            'member_id' => $this->member->id,
            'admin' => 0,
        ]);

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'lbbuser', 'vendor_info_type' => 1],
        ], $response->json('data'));
    }

    /**
     * op_tickets#3440：舊系統有特殊顯示的遊戲商，會員詳情與 API_Agent「會員遊戲帳號總覽」
     * 同一套規則。Joker＝vendor_infos.info 的 APP_ID ＋「.」＋我方 username（info 為明文 JSON）。
     */
    public function test_vendor_username_matches_legacy_prefix_rule(): void
    {
        $vendorId = $this->ensureVendor(VendorIdEnum::JOKER->value, 'joker');
        DB::table('joker_accounts')->insert([
            'vendor_info_id' => $this->createVendorInfo($vendorId, 1, '', info: json_encode(['APP_ID' => 'F1M3'])),
            'username' => 'pro00120256',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'F1M3.pro00120256', 'vendor_info_type' => 1],
        ], $response->json('data'));
    }

    /**
     * AE Sexy：info 以 vendor_infos.created_at 為金鑰加密存放（解密走 VendorInfo 的 accessor），
     * 前綴取 BACKEND_PREFIX，組完轉小寫（舊系統總覽頁的特例）。
     */
    public function test_vendor_username_legacy_rule_decrypts_info_and_applies_case(): void
    {
        $vendorId = $this->ensureVendor(VendorIdEnum::AES->value, 'aes');
        $createdAt = '2024-03-05 10:20:30';
        DB::table('aes_accounts')->insert([
            'vendor_info_id' => $this->createVendorInfo(
                $vendorId,
                1,
                '',
                info: $this->encryptVendorInfo(['BACKEND_PREFIX' => 'vwm'], $createdAt),
                createdAt: $createdAt,
            ),
            'username' => 'ELG00051837',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'vwmelg00051837', 'vendor_info_type' => 1],
        ], $response->json('data'));
    }

    /**
     * 100Plus：舊系統只顯示我方 username（也是遊戲商那邊的帳號）；原本的備援規則
     * 多加了 account_prefix，會變成 propro00003940。
     */
    public function test_vendor_username_legacy_plain_username_ignores_account_prefix(): void
    {
        $vendorId = $this->ensureVendor(VendorIdEnum::PLUS->value, 'plus100');
        DB::table('plus100_accounts')->insert([
            'vendor_info_id' => $this->createVendorInfo($vendorId, 1, 'pro'),
            'username' => 'pro00003940',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'pro00003940', 'vendor_info_type' => 1],
        ], $response->json('data'));
    }

    /**
     * 前綴設成空字串（dev 的 AE Sexy vendor_info 527 即是）不算讀不到：舊系統照樣以空前綴組帳號再轉小寫，
     * 會員詳情要一致顯示 aesmyrqa00000022，不能退回原規則顯示大寫的 AESMYRQA00000022。
     */
    public function test_vendor_username_legacy_rule_treats_empty_prefix_as_set(): void
    {
        $vendorId = $this->ensureVendor(VendorIdEnum::AES->value, 'aes');
        $createdAt = '2024-03-05 10:20:30';
        DB::table('aes_accounts')->insert([
            'vendor_info_id' => $this->createVendorInfo(
                $vendorId,
                0,
                'AESMYRQA',
                info: $this->encryptVendorInfo(['BACKEND_PREFIX' => ''], $createdAt),
                createdAt: $createdAt,
            ),
            'username' => 'AESMYRQA00000022',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'aesmyrqa00000022', 'vendor_info_type' => 0],
        ], $response->json('data'));
    }

    /**
     * 前綴設定讀不到（info 沒有該 key）時退回原規則，不顯示「.pro00120256」這種半截帳號。
     */
    public function test_vendor_username_legacy_rule_falls_back_when_setting_missing(): void
    {
        $vendorId = $this->ensureVendor(VendorIdEnum::JOKER->value, 'joker');
        DB::table('joker_accounts')->insert([
            'vendor_info_id' => $this->createVendorInfo($vendorId, 1, ''),
            'username' => 'pro00120256',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->api('vendor-username', [
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
        ]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame([
            ['username' => 'pro00120256', 'vendor_info_type' => 1],
        ], $response->json('data'));
    }

    /**
     * 舊系統規則以固定的 vendor_id 判定，所以要用真實 id 建（測試庫不一定有這些 vendors 列）。
     */
    private function ensureVendor(int $vendorId, string $tname): int
    {
        DB::table('vendors')->updateOrInsert(['id' => $vendorId], [
            'name' => 'T3440-' . $vendorId,
            'code' => 't3440' . bin2hex(random_bytes(4)),
            'tname' => $tname,
            'rate' => 1,
            'percentage' => 0,
            'info' => '{}',
            'status' => 1,
            'admin' => 0,
        ]);

        return $vendorId;
    }

    /** 與 VendorInfo info accessor 的解密對稱：金鑰 md5(md5(created_at))、IV 為 date('UYm') */
    private function encryptVendorInfo(array $data, string $createdAt): string
    {
        return base64_encode((string) openssl_encrypt(
            (string) json_encode($data),
            'aes-256-cbc',
            md5(md5($createdAt)),
            OPENSSL_RAW_DATA,
            date('UYm', (int) strtotime($createdAt)),
        ));
    }

    /**
     * 建一個指向既有 {tname}_accounts 的 vendor，並把該 id 的 driver 設為空，
     * 形狀等同 vendors 表有列、config/vendor.php 沒 driver 的歷史商戶
     * （auto-increment 出來的 id 可能剛好撞到有 driver 的 id，故明確清空）。
     */
    private function createDriverlessVendor(string $tname): int
    {
        $vendorId = DB::table('vendors')->insertGetId([
            'name' => 'T3236' . bin2hex(random_bytes(4)),
            'code' => 't3236' . bin2hex(random_bytes(4)),
            'tname' => $tname,
            'rate' => 1,
            'percentage' => 0,
            'info' => '{}',
            'status' => 1,
            'admin' => 0,
        ]);
        config(['vendor.driver.' . $vendorId => '']);

        return $vendorId;
    }

    private function createVendorInfo(
        int $vendorId,
        int $type,
        string $accountPrefix,
        int $status = 1,
        string $info = '{}',
        ?string $createdAt = null,
    ): int {
        return DB::table('vendor_infos')->insertGetId([
            'name' => 'line-' . $type,
            'type' => $type,
            'vendor_id' => $vendorId,
            'currency' => 'MYR',
            'currency_id' => 0,
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => $info,
            'prepared_account_number' => 0,
            'account_prefix' => $accountPrefix,
            'cost' => 0,
            'status' => $status,
            'created_at' => $createdAt ?? now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }

    private function insertScrAccount(int $vendorInfoId, string $username, int $status = 1): void
    {
        DB::table('scr_accounts')->insert([
            'vendor_info_id' => $vendorInfoId,
            'username' => $username,
            'member_id' => $this->member->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }
}
