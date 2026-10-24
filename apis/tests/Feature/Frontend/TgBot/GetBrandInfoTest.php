<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\TgBot;

use App\Enums\Agent\RoleEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Brand;
use App\Models\BrandAgent;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/TgBot/GetBrandInfoTest.php
 */
class GetBrandInfoTest extends TestCase
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
    }

    /**
     * php artisan test tests/Feature/Frontend/TgBot/GetBrandInfoTest.php --filter=test_success
     */
    public function test_success()
    {
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tgBot/homePageWithoutLogin',
            data: [],
            headers: [
                'Accept-Language' => 'zh-tw',
                'x-bot-tgID' => '',
                'x-bot-ID' => $this->brand->code,
            ]
        );
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals($json['code'], 1000);
    }

    /**
     * php artisan test tests/Feature/Frontend/TgBot/GetBrandInfoTest.php --filter=test_success_with_language
     */
    public function test_success_with_language()
    {
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tgBot/homePageWithoutLogin',
            data: [],
            headers: [
                'Accept-Language' => 'en-us',
                'x-bot-tgID' => '',
                'x-bot-ID' => $this->brand->code,
            ]
        );
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals($json['data']['start_message'], 'start message');
        $this->assertEquals($json['code'], 1000);
    }

    /**
     * 用戶語系欄位為空時退回預設語系；media 無 url 與 link_buttons_config 缺漏各自退回預設值
     *
     * php artisan test tests/Feature/Frontend/TgBot/GetBrandInfoTest.php --filter=test_lang_content_falls_back_to_default_language
     */
    public function test_lang_content_falls_back_to_default_language()
    {
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tgBot/homePageWithoutLogin',
            data: [],
            headers: [
                'Accept-Language' => 'zh-tw',
                'x-bot-tgID' => '',
                'x-bot-ID' => $this->brand->code,
            ]
        );
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertEquals(1000, $json['code']);
        // zh-tw 三個欄位皆為空字串 → 退回 DEFAULT_LANGUAGE（en）的內容
        $this->assertEquals('start message', $json['data']['start_message']);
        $this->assertEquals('restart message', $json['data']['restart_message']);
        $this->assertEquals('🔗 share button', $json['data']['share_button_name']);
        // 兩語系的 media 都沒有 url → 退回內建預設封面
        $expectedCover = [
            'type' => 'photo',
            'url' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png',
        ];
        $this->assertEquals($expectedCover, $json['data']['start_cover_media']);
        $this->assertEquals($expectedCover, $json['data']['restart_cover_media']);
        // lang_content 沒有 link_buttons_config → 空陣列
        $this->assertSame([], $json['data']['link_buttons_config']);
    }

    /**
     * php artisan test tests/Feature/Frontend/TgBot/GetBrandInfoTest.php --filter=test_links_contain_agent_domain
     */
    public function test_links_contain_agent_domain()
    {
        $domain = $this->agent->agentDomains()->first()->domain;

        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tgBot/homePageWithoutLogin',
            data: [],
            headers: [
                'Accept-Language' => 'en-us',
                'x-bot-tgID' => '',
                'x-bot-ID' => $this->brand->code,
            ]
        );
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertEquals(1000, $json['code']);
        $this->assertStringStartsWith("https://{$domain}/", $json['data']['webapp_login_link']);
        $this->assertStringStartsWith("https://{$domain}/", $json['data']['event_link']);
        $this->assertStringStartsWith("https://{$domain}/", $json['data']['launch_link']);
    }

    /**
     * 代理本身沒有網域時，要沿 hierarchy 取上層代理的網域
     *
     * php artisan test tests/Feature/Frontend/TgBot/GetBrandInfoTest.php --filter=test_falls_back_to_parent_agent_domain
     */
    public function test_falls_back_to_parent_agent_domain()
    {
        $parentDomain = $this->agent->agentDomains()->first()->domain;

        // 子代理不建網域
        $child = Agent::factory()->create([
            'status' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
        ]);
        $child->hierarchy_path = "/{$this->agent->id}/{$child->id}";
        $child->save();

        $this->brandAgent->agent_id = $child->id;
        $this->brandAgent->save();

        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tgBot/homePageWithoutLogin',
            data: [],
            headers: [
                'Accept-Language' => 'en-us',
                'x-bot-tgID' => '',
                'x-bot-ID' => $this->brand->code,
            ]
        );
        $json = $response->json();

        $response->assertStatus(200);
        $this->assertEquals(1000, $json['code']);
        $this->assertStringStartsWith("https://{$parentDomain}/", $json['data']['webapp_login_link']);
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
        $brand->status = 1;
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
        $brand->info = '{"CS_LINK":"https://example.invalid/C36524","OFFICIAL_LINK":"https://example.invalid/co365","WITHDRAWAL_LINK":"%s/tg-login?brand_code=%s&redirect=/withdrawal/bank-bind","AGENT_LINK":"%s/tg-login?brand_code=%s&redirect=/agent","EVENT_LINK":"%s/tg-login?brand_code=%s&redirect=/reward","GAME_LINK":"%s/tg-login?brand_code=%s&redirect=/game/%s&lang=%s","LOBBY_LINK":"%s/tg-login?brand_code=%s&redirect=/&lang=%s","WALLET_LINK":"%s/tg-login?brand_code=%s&redirect=/wallet","VIP_LINK":"%s/tg-login?brand_code=%s&redirect=/vip/introduce","HISTORY_LINK":"%s/tg-login?brand_code=%s&redirect=/record/list","WEBAPP_LOGIN_LINK":"%s/tg-login?brand_code=%s","TG_SHARE_TEXT":"Welcome to join us !","BOT_MODE":"2","DEFAULT_LANGUAGE":"en","MAIN_BUTTON_CS_PRE_LOGIN":"0","MAIN_BUTTON_OFFICIAL":"1","MAIN_BUTTON_WALLET":"1","MAIN_BUTTON_VIP":"1","MAIN_BUTTON_EVENT":"1","MAIN_BUTTON_INVITE":"1","MAIN_BUTTON_CHECKIN":"1","MAIN_BUTTON_MISSION":"1","MAIN_BUTTON_CS_POST_LOGIN":"1","MAIN_BUTTON_HISTORY":"1","MAIN_BUTTON_HOMEPAGE":"0","MAIN_BUTTON_SHARE":"1","START_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png"},"RESTART_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png"},"VIP_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket/tgbot/broadcast/vip_banner.png"}}';
        $brand->lang_content = '{"en":{"start_message":"start message","start_media":{"type":"photo","url":""},"restart_message":"restart message","restart_media":{"type":"photo","url":""},"share_button_name":"🔗 share button","share_content":"share content"},"zh-tw":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"zh-cn":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"ms-my":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"id-id":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"vi-vn":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"th-th":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"ta":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"tl-ph":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"my-mm":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"tpi":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"ho":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"ru":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""},"bn":{"start_message":"","start_media":{"type":"photo","url":""},"restart_message":"","restart_media":{"type":"photo","url":""},"share_button_name":"","share_content":""}}';
        $brand->save();

        return $brand;
    }
}
