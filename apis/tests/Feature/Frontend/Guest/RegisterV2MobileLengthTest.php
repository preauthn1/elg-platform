<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Guest;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * register-v2 手機號碼字數只算號碼本體、不含國碼（target/tickets#4332）。
 *
 * 前端送出的 mobile 是「國碼＋號碼」（registerFields.js 的 phoneCode + phone），
 * 同一份 payload 另有 country_code 帶純國碼。後台字數設定與前端提示講的都是「號碼幾位」，
 * 連國碼一起量會讓代理設 1~20、玩家填 20 位號碼被擋（現場：設 1-20 填 20 位回 2054）。
 *
 * php artisan test tests/Feature/Frontend/Guest/RegisterV2MobileLengthTest.php
 */
class RegisterV2MobileLengthTest extends TestCase
{
    /** 手機字數設定：1 ~ 20（重現現場設定） */
    private const MOBILE_MIN = 1;

    private const MOBILE_MAX = 20;

    private const COUNTRY_CODE = '60';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 AES 解密與日誌中間件
        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => 1,
            'memo' => 'mobile length test',
        ]);
        $groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => 6,
            'organization_id' => $orgId,
            'name' => 'mobile length test',
            'memo' => '',
            'is_default' => 0,
        ]);

        $rows = [
            ['username', 1, ['min_length' => 4, 'max_length' => 30]],
            ['mobile', 6, ['min_length' => self::MOBILE_MIN, 'max_length' => self::MOBILE_MAX]],
        ];
        foreach ($rows as [$key, $sort, $cfg]) {
            DB::table('agent_member_field_settings')->insert([
                'agent_group_id' => $groupId,
                'currency_id' => 1,
                'page_type' => 1,
                'field_key' => $key,
                'field_group' => 1,
                'sort' => $sort,
                // 手機非必填：本測試只驗字數口徑，不涉驗證碼流程
                'is_required' => 0,
                'status' => 1,
                'extra_config' => json_encode($cfg),
                'admin' => 0,
            ]);
        }

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $orgId,
            'member_field_setting_group_id' => $groupId,
        ]);

        // 與 RegisterTest 同款：帶建構子依賴的 partial mock
        $registerServiceMock = Mockery::mock(RegisterService::class, [
            $this->app->make(EventService::class),
            $this->app->make(MemberService::class),
            $this->app->make(MemberCodeService::class),
            $this->app->make(EventInviteService::class),
        ])->makePartial();
        $registerServiceMock->shouldReceive('triggerCsSyncData')->andReturnNull();
        $registerServiceMock->shouldReceive('runRegisterEventStoredProcedures')->andReturnNull();
        $this->app->instance(RegisterService::class, $registerServiceMock);
    }

    /** @param  array<string, mixed>  $params */
    private function register(array $params): TestResponse
    {
        return $this->requestApi('frontend/api/guest/register-v2', $params + [
            'domain' => $this->domain,
            'username' => 'm' . substr(bin2hex(random_bytes(6)), 0, 9),
            'password' => 'Aa112233',
            'confirm_password' => 'Aa112233',
            'last_name' => 'MOBILE LENGTH TEST',
        ]);
    }

    public function test_max_length_counts_number_only_not_country_code(): void
    {
        // 現場案例：設 1~20、號碼恰好 20 位；連國碼算會變 22 位而被擋。
        // 註冊流程另有「填了手機必須帶驗證碼」的保險（RegisterController），
        // 本案例只驗字數口徑，故直接驗 Service 的動態欄位驗證不拋例外。
        $number = str_repeat('1', self::MOBILE_MAX);

        $this->app->make(MemberFieldSettingService::class)->validateRegisterInput(
            $this->agent,
            [
                'username' => 'mobilelen',
                'password' => 'Aa112233',
                'confirm_password' => 'Aa112233',
                'last_name' => 'MOBILE LENGTH TEST',
                'mobile' => self::COUNTRY_CODE . $number,
                'country_code' => self::COUNTRY_CODE,
            ],
        );

        // 未拋 ApiException 即為通過（連國碼算會是 22 位 → 2054）
        $this->assertTrue(true);
    }

    public function test_number_over_max_is_still_rejected(): void
    {
        $response = $this->register([
            'mobile' => self::COUNTRY_CODE . str_repeat('1', self::MOBILE_MAX + 1),
            'country_code' => self::COUNTRY_CODE,
        ]);

        $this->assertSame(2054, $response->json('code'), '號碼本體超過上限仍須擋下');
    }

    public function test_falls_back_to_whole_value_when_country_code_absent(): void
    {
        // 未送 country_code（非前端正常路徑）：退回量整串，行為與改動前一致
        $response = $this->register([
            'mobile' => str_repeat('1', self::MOBILE_MAX + 1),
        ]);

        $this->assertSame(2054, $response->json('code'));
    }

    public function test_falls_back_when_country_code_is_not_the_prefix(): void
    {
        // country_code 與 mobile 前綴對不上（未串國碼）：不得誤扣，仍以整串計算
        $response = $this->register([
            'mobile' => str_repeat('9', self::MOBILE_MAX + 1),
            'country_code' => self::COUNTRY_CODE,
        ]);

        $this->assertSame(2054, $response->json('code'));
    }
}
