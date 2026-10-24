<?php

namespace Tests\Unit\Actions;

use App\DTOs\BotInfoDTO;
use App\DTOs\RegistrationStatusMapDTO;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\DTOs\TgWebhookDataDTO;
use App\Enums\PlatformApiCodeEnum;
use App\Enums\TgBotCallbackActionEnum;
use App\Helpers\CacheKeysHelper;
use App\Services\ActionServices\RegistrationService;
use Illuminate\Http\Request;

class RegistrationServiceTest extends ActionsTestCase
{
    private int $messageId = 1;

    private $tgWebhookData;

    private $registrationService;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_start_when_account_exists()
    {
        $this->setUpWebhookDataAndService();
        $this->mockHomepageInfoRequest();
        $this->mockWalletBalanceRequest();

        $this->platformApiCallService->method('isAccountExists')->willReturn(true);

        $expectedMessage = __('tgbot.homepage.content', ['username' => $this->username]);
        $this->tgBotApiCallService
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($tgbotMessage) use ($expectedMessage) {
                return $tgbotMessage instanceof TgBotApiMessageParameterDTO &&
                    str_contains($tgbotMessage->content, $expectedMessage) &&
                    isset($tgbotMessage->buttonRows['inline_keyboard']);
            }))
            ->willReturn($this->messageId);

        $this->registrationService->start();
    }

    public function test_start_when_account_does_not_exist()
    {
        $this->setUpWebhookDataAndService();
        $this->platformApiCallService->method('isAccountExists')->willReturn(false);

        $statusMap = RegistrationStatusMapDTO::start();

        $expectedMessage = __('tgbot.register.please_provide_phone_number');
        $this->tgBotApiCallService
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($tgbotMessage) use ($expectedMessage) {
                return $tgbotMessage instanceof TgBotApiMessageParameterDTO &&
                       $tgbotMessage->content === $expectedMessage &&
                       isset($tgbotMessage->buttonRows['keyboard']);
            }))
            ->willReturn($this->messageId);

        $this->cacheShouldPut(
            key: CacheKeysHelper::getRepliedMessageKey($this->messageId),
            value: ['status' => $statusMap->replyMessageStatus->value]
        );

        $this->cacheShouldPut(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->after->value,
                'chat_id' => $this->tgWebhookData->chatId,
            ]
        );

        $this->registrationService->start();
    }

    public function test_handle_phone_number()
    {
        $request = $this->getTgbotApi_ReplyMessage_Request(contact: true);
        $this->setUpWebhookDataAndService($request);

        $statusMap = RegistrationStatusMapDTO::waitPhoneNumer();

        $expectedMessage = __('tgbot.register.verify_want_enter_agent_code_or_not');
        $this->tgBotApiCallService
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(
                function ($tgbotMessage) use ($expectedMessage) {
                    return $tgbotMessage instanceof TgBotApiMessageParameterDTO &&
                        $tgbotMessage->content === $expectedMessage &&
                        isset($tgbotMessage->buttonRows['inline_keyboard']);
                }
            ))
            ->willReturn($this->messageId);

        $this->cacheShouldGet(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->before->value,
                'chat_id' => $this->tgWebhookData->chatId,
            ]
        );

        $this->cacheShouldPut(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->after->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
            ]
        );

        $this->registrationService->handle_PhoneNumber();
    }

    public function test_handle_want_enter_agent_code()
    {
        $request = $this->getTgbotApi_Callback_Request(TgBotCallbackActionEnum::REGISTER_WANT_ENTER_AGENT_CODE->value);
        $this->setUpWebhookDataAndService($request);

        $statusMap = RegistrationStatusMapDTO::waitWantEnterAgentCode();

        $expectedMessage = __('tgbot.register.please_enter_agent_code');
        $this->tgBotApiCallService
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(
                function ($tgbotMessage) use ($expectedMessage) {
                    return $tgbotMessage instanceof TgBotApiMessageParameterDTO &&
                        $tgbotMessage->content === $expectedMessage &&
                        isset($tgbotMessage->buttonRows['force_reply']);
                }
            ))
            ->willReturn($this->messageId);

        $this->cacheShouldGet(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->before->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
            ]
        );

        $this->cacheShouldPut(
            key: CacheKeysHelper::getRepliedMessageKey($this->messageId),
            value: ['status' => $statusMap->replyMessageStatus->value]
        );

        $this->cacheShouldPut(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->after->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
            ]
        );

        $this->registrationService->handle_WantEnterAgentCode();
    }

    public function test_handle_dismiss_enter_agent_code()
    {
        $request = $this->getTgbotApi_Callback_Request(TgBotCallbackActionEnum::REGISTER_DISMISS_AGENT_CODE->value);
        $this->setUpWebhookDataAndService($request);

        $statusMap = RegistrationStatusMapDTO::waitDismissEnterAgentCode();

        // Send message, user agent
        $expectedMessage = __('tgbot.register.use_agent_code', ['code' => $this->inviteCode]);
        $this->tgBotApiCallService
            ->expects($this->once())
            ->method('sendTextOnly')
            ->with($this->callback(
                function ($content) use ($expectedMessage) {
                    return is_string($content) && $content === $expectedMessage;
                }
            ))
            ->willReturn($this->messageId);

        // Send message, register info
        if (config('app.debug') !== false) {
            $this->tgBotApiCallService
                ->expects($this->once())
                ->method('send')
                ->with($this->callback(
                    function ($tgbotMessage) use ($statusMap) {
                        return $tgbotMessage instanceof TgBotApiMessageParameterDTO &&
                            str_contains($tgbotMessage->content, $statusMap->after->value);
                    }
                ))
                ->willReturn($this->messageId);
        }

        $this->platformApiCallService
            ->expects($this->once())
            ->method('setRegister')
            ->with([
                'status' => $statusMap->after->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
                'code' => $this->inviteCode,
            ])
            ->willReturn([
                'code' => PlatformApiCodeEnum::SUCCESS->value,
                'message' => 'success',
                'data' => [
                    'id' => 1,
                    'name' => $this->username,
                    'balance' => 0.00,
                ],
            ]);

        $this->cacheShouldGet(
            key: CacheKeysHelper::getBotInfoKey($this->botId),
            value: new BotInfoDTO(
                $this->botId,
                'test_bot_code',
                'test_bot_token',
                'test_invite_code'
            )
        );

        $this->cacheShouldGet(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->before->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
            ]
        );

        $this->cacheShouldPut(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->after->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
            ]
        );

        $this->registrationService->handle_DismissEnterAgentCode();
    }

    public function test_handle_agent_code()
    {
        $request = $this->getTgbotApi_ReplyMessage_Request();
        $this->setUpWebhookDataAndService($request);

        $statusMap = RegistrationStatusMapDTO::waitAgentCode();
        $code = $this->tgWebhookData->message['text'];

        // Send message, user agent
        $expectedMessage = __('tgbot.register.use_agent_code', ['code' => $code]);
        $this->tgBotApiCallService
            ->expects($this->once())
            ->method('sendTextOnly')
            ->with($this->callback(
                function ($content) use ($expectedMessage) {
                    return is_string($content) && $content === $expectedMessage;
                }
            ))
            ->willReturn($this->messageId);

        // Send message, register info
        if (config('app.debug') !== false) {
            $this->tgBotApiCallService
                ->expects($this->once())
                ->method('send')
                ->with($this->callback(
                    function ($tgbotMessage) use ($statusMap) {
                        return $tgbotMessage instanceof TgBotApiMessageParameterDTO &&
                            str_contains($tgbotMessage->content, $statusMap->after->value);
                    }
                ))
                ->willReturn($this->messageId);
        }

        $this->platformApiCallService
            ->expects($this->once())
            ->method('setRegister')
            ->with([
                'status' => $statusMap->after->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
                'code' => $code,
            ])
            ->willReturn([
                'code' => PlatformApiCodeEnum::SUCCESS->value,
                'message' => 'success',
                'data' => [
                    'id' => 1,
                    'name' => $this->username,
                    'balance' => 0.00,
                ],
            ]);

        $this->cacheShouldGet(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->before->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
            ]
        );

        $this->cacheShouldPut(
            key: CacheKeysHelper::getRegistrationKey(),
            value: [
                'status' => $statusMap->after->value,
                'chat_id' => $this->tgWebhookData->chatId,
                'mobile' => $this->mobile,
                'code' => $code,
            ]
        );

        $this->registrationService->handle_AgentCode();
    }

    private function setUpWebhookDataAndService(?Request $request = null)
    {
        if (is_null($request)) {
            $request = $this->getTgbotApi_Callback_Request(TgBotCallbackActionEnum::REGISTER->value);
        }

        $this->tgWebhookData = TgWebhookDataDTO::fromRequest($this->botId, $request);
        $this->app->instance(TgWebhookDataDTO::class, $this->tgWebhookData);

        $this->registrationService = RegistrationService::init();
    }
}
