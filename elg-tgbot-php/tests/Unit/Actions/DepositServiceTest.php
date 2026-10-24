<?php

namespace Tests\Unit\Actions;

use App\DTOs\DepositInfoDTO;
use App\DTOs\DepositStatusMapDTO;
use App\DTOs\TgWebhookDataDTO;
use App\Enums\TgBotCallbackActionEnum;
use App\Helpers\CacheHelper;
use App\Helpers\CacheKeysHelper;
use App\Services\ActionServices\DepositService;

class DepositServiceTest extends ActionsTestCase
{
    private $tgWebhookData;

    private $depositService;

    private $messageId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->getTgbotApi_Callback_Request(TgBotCallbackActionEnum::DEPOSIT->value);
        $this->tgWebhookData = TgWebhookDataDTO::fromRequest($this->botId, $request);
        $this->app->instance(TgWebhookDataDTO::class, $this->tgWebhookData);

        $this->depositService = DepositService::init();
    }

    public function test_cancel()
    {
        $this->tgBotApiCallService->expects($this->once())
            ->method('deleteMessage')
            ->with($this->messageId);

        $this->depositService->cancel();
    }

    public function test_start()
    {
        $statusMap = DepositStatusMapDTO::start();
        $depositInfos = [
            new DepositInfoDTO([
                'order_type_id' => 0,
                'name' => 'Top Up (Bank Transfer)',
                'cf_id' => 5,
                'cf_name' => 'AutoDeposit',
                'image_path' => 'https://example.invalid/image.png',
                'popup_text' => '',
                'url' => 'https://example.invalid',
            ]),
            new DepositInfoDTO([
                'order_type_id' => 150,
                'name' => 'TRUEMONEY',
                'cf_id' => 3,
                'cf_name' => 'AllPay',
                'image_path' => 'https://example.invalid',
                'popup_text' => '',
                'url' => 'https://example.invalid',
            ]),
        ];

        $this->platformApiCallService->expects($this->once())
            ->method('getDepositInfos')
            ->willReturn($depositInfos);

        $this->tgBotApiCallService->expects($this->once())
            ->method('send');

        $this->cacheShouldPut(CacheKeysHelper::getDepositKey(), [
            'status' => $statusMap->after->value,
            'chat_id' => $this->tgWebhookData->chatId,
        ]);

        $this->depositService->start();
    }

    // public function testHandleWaitSelectOrderType()
    // {
    //     $statusMap = DepositStatusMapDTO::waitOrderType();
    //     $depositData = ['status' => $statusMap->before->value];
    //     $orderTypeId = 1;

    //     Cache::shouldReceive('get')
    //         ->once()
    //         ->with(CacheKeysHelper::getDepositKey())
    //         ->andReturn($depositData);

    //     $this->tgWebhookData->callbackActionExtraDatas = [
    //         TgBotCallbackExtraParamEnum::DEPOSIT_ORDER_TYPE_ID->value => $orderTypeId
    //     ];

    //     $this->tgBotApiCallService->expects($this->once())
    //         ->method('send')
    //         ->willReturn(1);

    //     ReplyMessageHelper::shouldReceive('register')
    //         ->once()
    //         ->with(1, $statusMap->replyMessageStatus);

    //     CacheHelper::shouldReceive('putCacheAndLog')
    //         ->once()
    //         ->with(CacheKeysHelper::getDepositKey(), [
    //             'status' => $statusMap->after->value,
    //             'order_type_id' => $orderTypeId
    //         ]);

    //     $this->depositService->handle_WaitSelectOrderType();
    // }

    // public function testHandleWaitDepositAmount()
    // {
    //     $statusMap = DepositStatusMapDTO::waitAmount();
    //     $depositData = [
    //         'status' => $statusMap->before->value,
    //         'order_type_id' => 1
    //     ];

    //     Cache::shouldReceive('get')
    //         ->once()
    //         ->with(CacheKeysHelper::getDepositKey())
    //         ->andReturn($depositData);

    //     $this->tgWebhookData->message = ['text' => '100'];

    //     $this->platformApiCallService->expects($this->once())
    //         ->method('depositOrder')
    //         ->with(1, 100)
    //         ->willReturn(['url' => 'http://example.invalid']);

    //     $this->tgBotApiCallService->expects($this->once())
    //         ->method('send');

    //     CacheHelper::shouldReceive('putCacheAndLog')
    //         ->once()
    //         ->with(CacheKeysHelper::getDepositKey(), [
    //             'status' => $statusMap->after->value,
    //             'amount' => '100',
    //             'order_type_id' => 1
    //         ]);

    //     $this->depositService->handle_WaitDepositAmount();
    // }
}
