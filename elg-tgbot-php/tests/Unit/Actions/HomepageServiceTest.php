<?php

namespace Tests\Unit\Actions;

use App\DTOs\TgBotApiMessageParameterDTO;
use App\DTOs\TgWebhookDataDTO;
use App\Services\ActionServices\HomepageService;

class HomepageServiceTest extends ActionsTestCase
{
    private $homepageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            TgWebhookDataDTO::class,
            TgWebhookDataDTO::fromRequest($this->botId, $this->getTgbotApi_Message_Request())
        );

        $this->homepageService = HomepageService::init();
    }

    public function test_send_with_registered_account()
    {
        $this->platformApiCallService->method('isAccountExists')->willReturn(true);
        $this->mockHomepageInfoRequest();
        $this->mockWalletBalanceRequest();

        $this->tgBotApiCallService
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($tgbotMessage) {
                return ($tgbotMessage instanceof TgBotApiMessageParameterDTO) &&
                       (strpos($tgbotMessage->content, 'helo_terpro') !== false);
            }));

        $this->homepageService->send();
    }
}
