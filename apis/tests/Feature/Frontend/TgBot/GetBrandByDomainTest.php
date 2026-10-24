<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\TgBot;

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
 * php artisan test tests/Feature/Frontend/TgBot/GetBrandByDomainTest.php
 */
class GetBrandByDomainTest extends TestCase
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

    public function test_success()
    {
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tg-bot/get-brand-by-domain',
            data: [
                'domain' => $this->domain,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
            ]
        );
        $json = $response->json();
        // {
        //     "code": 1000,
        //     "message": "成功",
        //     "data": [
        //       {
        //         "bot_id": "123",
        //         "bot_username": "gff_tim_dev_bot"
        //       }
        //     ]
        //   }

        $response->assertStatus(200);
        $this->assertEquals($json['code'], 1000);
        $this->assertEquals($json['message'], '成功');
        $this->assertCount(1, $json['data']);
        $botTokenArr = explode(':', $this->brand->bot_token);
        $this->assertEquals($json['data'][0]['bot_id'], $botTokenArr[0]);
        $this->assertEquals($json['data'][0]['bot_username'], $this->brand->bot_id);
    }

    public function test_domain_not_found()
    {
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tg-bot/get-brand-by-domain',
            data: [
                'domain' => 'failed',
            ],
        );
        $json = $response->json();
        $response->assertStatus(400);
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
        $brand->info = '{"CS_LINK":"https://example.invalid/C36524","OFFICIAL_LINK":"https://example.invalid/co365","WITHDRAWAL_LINK":"%s/tg-login?brand_code=%s&redirect=/withdrawal/bank-bind&platform=tgbot","EVENT_LINK":"%s/tg-login?brand_code=%s&redirect=/reward&platform=tgbot","GAME_LINK":"%s/tg-login?brand_code=%s&redirect=/game/%s&lang=%s&platform=tgbot","LOBBY_LINK":"%s/tg-login?brand_code=%s&redirect=/&lang=%s&platform=tgbot","WALLET_LINK":"%s/tg-login?brand_code=%s&redirect=/wallet&platform=tgbot","VIP_LINK":"%s/tg-login?brand_code=%s&redirect=/vip/introduce&platform=tgbot","HISTORY_LINK":"%s/tg-login?brand_code=%s&redirect=/record/list&platform=tgbot","WEBAPP_LOGIN_LINK":"%s/tg-login?brand_code=%s&platform=tgbot","BOT_MODE":"1","DEFAULT_LANGUAGE":"zh-tw","MAIN_BUTTON_CS_PRE_LOGIN":"1","MAIN_BUTTON_OFFICIAL":"1","MAIN_BUTTON_WALLET":"1","MAIN_BUTTON_VIP":"1","MAIN_BUTTON_EVENT":"1","MAIN_BUTTON_INVITE":"1","MAIN_BUTTON_CHECKIN":"1","MAIN_BUTTON_MISSION":"1","MAIN_BUTTON_CS_POST_LOGIN":"1","MAIN_BUTTON_HISTORY":"1","MAIN_BUTTON_HOMEPAGE":"0","START_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png"},"RESTART_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/welcome.png"},"VIP_COVER_MEDIA":{"type":"photo","url":"https://example.invalid/cdn-betmonsters-com-cdn-bucket/tgbot/broadcast/vip_banner.png"}}';
        $brand->save();

        return $brand;
    }
}
