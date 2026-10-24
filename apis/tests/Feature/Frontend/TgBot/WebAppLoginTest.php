<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\TgBot;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CurrencyEnum;
use App\Enums\MemberFieldPageTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentMemberFieldSetting;
use App\Models\Brand;
use App\Models\BrandAgent;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/TgBot/WebAppLoginTest.php
 */
class WebAppLoginTest extends TestCase
{
    protected Agent $agent;

    protected AgentCashFlow $agentCashFlow;

    protected Brand $brand;

    protected BrandAgent $brandAgent;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->brand = $this->createBrand();
        $this->brandAgent = $this->createBrandAgent();
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::REGISTRATION_ALLOW_CODE_EMPTY, 1);
    }

    /**
     * php artisan test tests/Feature/Frontend/TgBot/WebAppLoginTest.php --filter test_success
     *
     * @return void
     */
    public function test_success()
    {
        $this->mockRegisterService();
        $initData = 'query_id=AAHEECJFAwAAAMQQIkWY33An&user=%7B%22id%22%3A7602311364%2C%22first_name%22%3A%22Tim%20T%22%2C%22last_name%22%3A%22%22%2C%22language_code%22%3A%22zh-hans%22%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%example.invalid%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2FATR6lKDM6yAmu6jumGAEsYwa9XMk4rNUvILZA8YvwchqhKPe_VyMUd5wjE3g0ytA.svg%22%7D&auth_date=1748314899&signature=ifgIYz4_ihBYoPQHE94ASjglaYvrVrdMqLclDrV5XCT4EXjv0kw7ghIaTFnQGRJKHZPMy5W_g1yLA6M9uBPIDA';
        $sign = $this->sign($this->brand->bot_token, $initData);
        $initData = $initData . '&hash=' . $sign;
        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tg-bot/web-app-login',
            data: [
                'init_data' => $initData,
                'brand_code' => 'e41ae3fd-33e9-44c5-9468-9ebd88751491',
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
            ]
        );
        // [
        //     "code" => 200
        //     "message" => "成功"
        //     "data" => array:1 [
        //       "token" => "L8IwRhCk0r4hYZWNNDkgbLOCRK1L4Aem"
        //     ]
        //   ]

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('token', $data);
        $token = [REDACTED]'token'];
        $emberAccessToken = [REDACTED]'token', $token)->orderBy('id', 'desc')->first();
        $this->assertNotNull($emberAccessToken);

        $member = Member::query()->where('id', $emberAccessToken->member_id)->first();
        $this->assertNotNull($member);
        $this->assertEquals($member->nickname, 'Tim T');
    }

    /**
     * bot 代理的組別在關閉模式時，TG 首次建帳的帳號用前綴產生（target/ticket_tasks#1629）
     */
    public function test_closed_mode_creates_prefixed_username(): void
    {
        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => CurrencyEnum::MYR->value,
            'memo' => 'tg closed mode test',
        ]);
        $groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => 6,
            'organization_id' => $orgId,
            'name' => 'tg closed mode test',
            'memo' => '',
            'is_default' => 0,
        ]);
        foreach ([['username', 0, ['username_prefix' => 'togo']], ['mobile', 1, null]] as [$key, $status, $extraConfig]) {
            AgentMemberFieldSetting::create([
                'agent_group_id' => $groupId,
                'currency_id' => CurrencyEnum::MYR->value,
                'page_type' => MemberFieldPageTypeEnum::REGISTER->value,
                'field_key' => $key,
                'field_group' => 1,
                'sort' => 1,
                'is_required' => $status,
                'status' => $status,
                'extra_config' => $extraConfig,
                'admin' => 0,
            ]);
        }
        $this->agent->update(['organization_id' => $orgId, 'member_field_setting_group_id' => $groupId]);

        $this->mockRegisterService();
        $initData = 'query_id=AAHEECJFAwAAAMQQIkWY33An&user=%7B%22id%22%3A7602311364%2C%22first_name%22%3A%22Tim%20T%22%2C%22last_name%22%3A%22%22%2C%22language_code%22%3A%22zh-hans%22%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%example.invalid%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2FATR6lKDM6yAmu6jumGAEsYwa9XMk4rNUvILZA8YvwchqhKPe_VyMUd5wjE3g0ytA.svg%22%7D&auth_date=1748314899&signature=ifgIYz4_ihBYoPQHE94ASjglaYvrVrdMqLclDrV5XCT4EXjv0kw7ghIaTFnQGRJKHZPMy5W_g1yLA6M9uBPIDA';
        $initData = $initData . '&hash=' . $this->sign($this->brand->bot_token, $initData);

        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tg-bot/web-app-login',
            data: [
                'init_data' => $initData,
                'brand_code' => 'e41ae3fd-33e9-44c5-9468-9ebd88751491',
            ],
        );

        $response->assertStatus(200);
        $memberId = MemberAccessToken:[REDACTED]'token', $response->json('data.token'))->value('member_id');
        $this->assertMatchesRegularExpression('/^togo-[0-9a-f]{13}$/', (string) Member::query()->whereKey($memberId)->value('username'));
    }

    public function test_sign_failed()
    {
        $initData = 'query_id=AAHEECJFAwAAAMQQIkWY33An&user=%7B%22id%22%3A7602311364%2C%22first_name%22%3A%22Tim%20T%22%2C%22last_name%22%3A%22%22%2C%22language_code%22%3A%22zh-hans%22%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%example.invalid%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2FATR6lKDM6yAmu6jumGAEsYwa9XMk4rNUvILZA8YvwchqhKPe_VyMUd5wjE3g0ytA.svg%22%7D&auth_date=1748314899&signature=ifgIYz4_ihBYoPQHE94ASjglaYvrVrdMqLclDrV5XCT4EXjv0kw7ghIaTFnQGRJKHZPMy5W_g1yLA6M9uBPIDA';
        $sign = $this->sign($this->brand->bot_token, $initData);
        $initData = $initData . '&hash=failed';
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tg-bot/web-app-login',
            data: [
                'init_data' => $initData,
                'brand_code' => 'e41ae3fd-33e9-44c5-9468-9ebd88751491',
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
            ]
        );
        $response->assertStatus(400);
        $json = $response->json();
        $this->assertEquals($json['code'], 400);
    }

    protected function createBrand(): Brand
    {
        // INSERT INTO `xsgcasino`.`brands` (`id`, `name`, `code`, `bot_id`, `bot_token`, `remark`, `status`, `created_at`, `updated_at`, `admin`) VALUES (14, 'gff_tim_dev', 'e41ae3fd-33e9-44c5-9468-9ebd88751491', 'gff_tim_dev_bot', '[TG_BOT_TOKEN_REDACTED]', '[]', 0, '2025-04-24 03:13:09', '2025-05-05 02:12:14', 0);
        $brand = new Brand;
        $brand->name = 'gff_tim_dev';
        $brand->code = 'e41ae3fd-33e9-44c5-9468-9ebd88751491';
        $brand->bot_id = 'gff_tim_dev_bot';
        $brand->bot_token = [REDACTED]';
        $brand->remark = '[]';
        $brand->status = 0;
        $brand->admin = 0;
        $brand->save();

        return $brand;
    }

    protected function createBrandAgent(): BrandAgent
    {
        // INSERT INTO `xsgcasino`.`brands` (`id`, `name`, `code`, `bot_id`, `bot_token`, `remark`, `status`, `created_at`, `updated_at`, `admin`) VALUES (14, 'gff_tim_dev', 'e41ae3fd-33e9-44c5-9468-9ebd88751491', 'gff_tim_dev_bot', '[TG_BOT_TOKEN_REDACTED]', '[]', 0, '2025-04-24 03:13:09', '2025-05-05 02:12:14', 0);
        $brand = new BrandAgent;
        $brand->brand_id = $this->brand->id;
        $brand->agent_id = $this->agent->id;
        $brand->info = '{"CS_LINK":"https://example.invalid/C36524","OFFICIAL_LINK":"https://example.invalid/co365","WITHDRAWAL_LINK":"%s/tg-login?brand_code=%s&redirect=/withdrawal/bank-bind&platform=tgbot","EVENT_LINK":"%s/tg-login?brand_code=%s&redirect=/reward&platform=tgbot","GAME_LINK":"%s/tg-login?brand_code=%s&redirect=/game/%s&lang=%s&platform=tgbot","LOBBY_LINK":"%s/tg-login?brand_code=%s&redirect=/&lang=%s&platform=tgbot","WALLET_LINK":"%s/tg-login?brand_code=%s&redirect=/wallet&platform=tgbot","VIP_LINK":"%s/tg-login?brand_code=%s&redirect=/vip/introduce&platform=tgbot","HISTORY_LINK":"%s/tg-login?brand_code=%s&redirect=/record/list&platform=tgbot","WEBAPP_LOGIN_LINK":"%s/tg-login?brand_code=%s&platform=tgbot","BOT_MODE":"1","DEFAULT_LANGUAGE":"zh-tw","MAIN_BUTTON_CS_PRE_LOGIN":"1","MAIN_BUTTON_OFFICIAL":"1","MAIN_BUTTON_WALLET":"1","MAIN_BUTTON_VIP":"1","MAIN_BUTTON_EVENT":"1","MAIN_BUTTON_INVITE":"1","MAIN_BUTTON_CHECKIN":"1","MAIN_BUTTON_MISSION":"1","MAIN_BUTTON_CS_POST_LOGIN":"1","MAIN_BUTTON_HISTORY":"1","MAIN_BUTTON_HOMEPAGE":"0","START_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png"},"RESTART_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png"},"VIP_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket/tgbot/broadcast/vip_banner.png"}}';
        $brand->save();

        return $brand;
    }

    protected function mockRegisterService()
    {
        $registerServiceMock = Mockery::mock(RegisterService::class, [
            $this->app->make(EventService::class),
            $this->app->make(MemberService::class),
            $this->app->make(MemberCodeService::class),
            $this->app->make(EventInviteService::class),
        ])->makePartial();
        $registerServiceMock->shouldReceive('triggerCsSyncData')->once()->andReturnNull();
        $registerServiceMock->shouldReceive('runRegisterEventStoredProcedures')->once()->andReturnNull();
        $this->app->instance(RegisterService::class, $registerServiceMock);
    }

    private function sign($bot_token, $data_check_string)
    {
        $data_check_arr = explode('&', rawurldecode($data_check_string));

        $data_check_arr = array_filter($data_check_arr);
        sort($data_check_arr);

        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = [REDACTED]'sha256', $bot_token, 'WebAppData', true);
        $hash = bin2hex(hash_hmac('sha256', $data_check_string, $secret_key, true));

        return $hash;
    }
}
