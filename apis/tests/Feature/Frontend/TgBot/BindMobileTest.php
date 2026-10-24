<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\TgBot;

use App\Enums\MemberBindingLogTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Brand;
use App\Models\BrandAgent;
use App\Models\Member;
use App\Models\MemberTelegramRel;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/TgBot/BindMobileTest.php
 */
class BindMobileTest extends TestCase
{
    protected Agent $agent;

    protected AgentCashFlow $agentCashFlow;

    protected Brand $brand;

    protected BrandAgent $brandAgent;

    protected Member $member;

    protected MemberTelegramRel $memberTelegramRel;

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
        $this->member = $this->createMember($this->agent);
        $this->memberTelegramRel = $this->createMemberTelegramRel();
    }

    public function test_bind_mobile_success()
    {
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/tgBot/bindMobile',
            data: [
                'mobile' => '6013800138000',
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'x-bot-tgID' => '7602311364',
                'x-bot-ID' => $this->brand->code,
            ]
        );
        $response->assertStatus(200);

        $this->member->refresh();
        $this->assertEquals('6013800138000', $this->member->mobile);
        $this->assertEquals(1, $this->member->mobile_validate);
        $this->assertDatabaseHas('member_binding_logs', [
            'member_id' => $this->member->id,
            'type' => MemberBindingLogTypeEnum::BINDING_PHONE->value,
            'value' => '6013800138000',
            'validate_type' => 1,
            'status' => 1,
        ]);
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

    protected function createMemberTelegramRel(): MemberTelegramRel
    {
        return MemberTelegramRel::create([
            'member_id' => $this->member->id,
            'brand_id' => $this->brand->id,
            'tg_user_id' => 7602311364, // 使用數字而不是字符串
            'tg_password' => 'test_password',
        ]);
    }
}
