<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\RegistrationStatusMapDTO;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\ErrorCodeEnum;
use App\Enums\PlatformApiCodeEnum;
use App\Enums\RegistrationStatusEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;
use App\Helpers\BotHelper;
use App\Helpers\CacheHelper;
use App\Helpers\CacheKeysHelper;
use App\Helpers\ReplyMessageHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RegistrationService extends BaseService
{
    protected function show()
    {
        if ($this->platformApiCallService->isAccountExists()) {
            HomepageService::init()->show();

            return;
        }

        $statusMap = RegistrationStatusMapDTO::start();

        $messageId = $this->sendMessage_PleaseProvidePhoneNumber();
        ReplyMessageHelper::register($messageId, $statusMap->replyMessageStatus);

        CacheHelper::putCacheAndLog(CacheKeysHelper::getRegistrationKey(), [
            'status' => $statusMap->after->value,
            'chat_id' => $this->tgWebhookData->chatId,
        ]);
    }

    public function handle_PhoneNumberV2()
    {
        $contact = $this->tgWebhookData->message['contact'] ?? [];
        $phoneNumber = (string) $contact['phone_number'] ?? '';
        $userId = (string) $contact['user_id'] ?? '';

        // Remove + prefix if exists
        $phoneNumber = ltrim($phoneNumber, '+');

        // Validate phone number format - should only contain digits
        if (!preg_match('/^\d+$/', $phoneNumber)) {
            Log::error('Registration, Invalid phone number format', [
                'phone_number' => $phoneNumber,
            ]);

            throw new \Exception(
                __('tgbot.register.error_phone_number_is_empty'),
                ErrorCodeEnum::REGISTER_PHONE_NUMBER_EMPTY->value
            );
        }

        if (empty($contact)) {
            Log::error('Registration, Request data contact empty', [
                'contact' => $contact,
            ]);

            throw new \Exception(
                __('tgbot.register.error_contact_is_empty'),
                ErrorCodeEnum::REGISTER_CONTACT_EMPTY->value
            );
        } elseif (empty($phoneNumber)) {
            Log::error('Registration, Request data phone number empty', [
                'contact' => $contact,
            ]);

            throw new \Exception(
                __('tgbot.register.error_phone_number_is_empty'),
                ErrorCodeEnum::REGISTER_PHONE_NUMBER_EMPTY->value
            );
        } elseif ($this->tgWebhookData->chatId !== $userId) {
            Log::error('Registration, User ID not match', [
                'chat_id' => $this->tgWebhookData->chatId,
                'user_id' => $userId,
            ]);

            throw new \Exception(
                __('tgbot.register.error_user_id_not_match'),
                ErrorCodeEnum::REGISTER_USER_ID_NOT_MATCH->value
            );
        }

        Log::info('Registration, Phone number: ' . $phoneNumber);
        if ($this->platformApiCallService->bindMobile($phoneNumber)) {
            $this->tgBotApiCallService->sendTextOnly(__('tgbot.bind_mobile.bind_mobile_success'));
        } else {
            $this->tgBotApiCallService->sendTextOnly(__('tgbot.bind_mobile.bind_mobile_failed'));
        }
    }

    public function handle_PhoneNumber()
    {
        $statusMap = RegistrationStatusMapDTO::waitPhoneNumer();

        $contact = $this->tgWebhookData->message['contact'] ?? [];
        $phoneNumber = (string) $contact['phone_number'] ?? '';
        $userId = (string) $contact['user_id'] ?? '';

        if (empty($contact)) {
            Log::error('Registration, Request data contact empty', [
                'contact' => $contact,
            ]);

            throw new \Exception(
                __('tgbot.register.error_contact_is_empty'),
                ErrorCodeEnum::REGISTER_CONTACT_EMPTY->value
            );
        } elseif (empty($phoneNumber)) {
            Log::error('Registration, Request data phone number empty', [
                'contact' => $contact,
            ]);

            throw new \Exception(
                __('tgbot.register.error_phone_number_is_empty'),
                ErrorCodeEnum::REGISTER_PHONE_NUMBER_EMPTY->value
            );
        } elseif ($this->tgWebhookData->chatId !== $userId) {
            Log::error('Registration, User ID not match', [
                'chat_id' => $this->tgWebhookData->chatId,
                'user_id' => $userId,
            ]);

            throw new \Exception(
                __('tgbot.register.error_user_id_not_match'),
                ErrorCodeEnum::REGISTER_USER_ID_NOT_MATCH->value
            );
        }

        $registData = $this->getCacheDataAndCheckStatus(CacheKeysHelper::getRegistrationKey(), $statusMap->before);

        $this->sendMessage_WhetherWantEnterAgentCode();

        // update registration info
        $registData['status'] = $statusMap->after->value;
        $registData['mobile'] = $phoneNumber;
        CacheHelper::putCacheAndLog(CacheKeysHelper::getRegistrationKey(), $registData, 600);
    }

    public function handle_WantEnterAgentCode()
    {
        $statusMap = RegistrationStatusMapDTO::waitWantEnterAgentCode();

        $registData = $this->getCacheDataAndCheckStatus(CacheKeysHelper::getRegistrationKey(), $statusMap->before);

        $messageId = $this->sendMessage_PleaseEnterAgentCode();
        ReplyMessageHelper::register($messageId, $statusMap->replyMessageStatus);

        // update registration info
        $registData['status'] = $statusMap->after->value;
        CacheHelper::putCacheAndLog(CacheKeysHelper::getRegistrationKey(), $registData, 600);
    }

    public function handle_DismissEnterAgentCode()
    {
        $statusMap = RegistrationStatusMapDTO::waitDismissEnterAgentCode();

        $registData = $this->getCacheDataAndCheckStatus(CacheKeysHelper::getRegistrationKey(), $statusMap->before);

        $registData['status'] = $statusMap->after->value;
        $this->platformApiCall_Register($registData);

        CacheHelper::putCacheAndLog(CacheKeysHelper::getRegistrationKey(), $registData, 600);
    }

    public function handle_AgentCode()
    {
        $statusMap = RegistrationStatusMapDTO::waitAgentCode();
        $agentInvitationCode = $this->tgWebhookData->message['text'] ?? '';

        $registData = $this->getCacheDataAndCheckStatus(CacheKeysHelper::getRegistrationKey(), $statusMap->before);
        $registData['status'] = $statusMap->after->value;
        $registData['code'] = $agentInvitationCode;

        $this->platformApiCall_Register($registData);

        CacheHelper::putCacheAndLog(CacheKeysHelper::getRegistrationKey(), $registData, 600);
    }

    private function sendMessage_PleaseProvidePhoneNumber(): int
    {
        $message = __('tgbot.register.please_provide_phone_number');
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::KEYBOARD);
        $buttonRows->setAttr('one_time_keyboard', true);
        $buttonRows->setAttr('remove_keyboard', true);
        $buttonRows->addButtonByArray([
            'text' => __('tgbot.register.please_provide_phone_number_btn'),
            'request_contact' => true,
        ]);
        $buttonRows->nextRow();
        $buttonRows->addButtonByArray([
            'text' => __('tgbot.disagree'),
        ]);

        $tgbotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: $message
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        return $this->tgBotApiCallService->send($tgbotMessage);
    }

    private function sendMessage_WhetherWantEnterAgentCode(): int
    {
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::REGISTER_WANT_ENTER_AGENT_CODE);
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::REGISTER_DISMISS_AGENT_CODE);

        $tgBotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.register.verify_want_enter_agent_code_or_not')
        );
        $tgBotMessage->buttonRows = $buttonRows->build();

        return $this->tgBotApiCallService->send($tgBotMessage);
    }

    private function sendMessage_PleaseEnterAgentCode(): int
    {
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::KEYBOARD);
        $buttonRows->setAttr('force_reply', true);

        $tgBotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.register.please_enter_agent_code')
        );
        $tgBotMessage->buttonRows = $buttonRows->build();

        return $this->tgBotApiCallService->send($tgBotMessage);
    }

    private function platformApiCall_Register(array $data)
    {
        if (empty($data['mobile'])) {
            throw new \Exception(
                __('tgbot.error_phone_number_is_empty'),
                ErrorCodeEnum::REGISTER_PHONE_NUMBER_EMPTY->value
            );
        }

        if (empty($data['code'])) {
            $botInfo = BotHelper::getBotInfos($this->tgWebhookData->botId);
            $data['code'] = $botInfo->inviteCode;
        }

        $this->sendMessage_UseAgentCode($data['code']);
        $this->sendMessage_Register_Infos($data);

        $responseData = $this->platformApiCallService->setRegister($data);
        $this->checkAndSendMessage_SpecialPlatformApiCallMessage($responseData);
    }

    private function checkAndSendMessage_SpecialPlatformApiCallMessage(array $responseData): void
    {
        $responseCode = $responseData['code'] ?? '';

        if ($responseCode === PlatformApiCodeEnum::SUCCESS->value) {
            return;
        }

        if ($responseCode === PlatformApiCodeEnum::REGISTER_DUPLICATE_TG_USER_ID->value) {
            $obj = json_decode($responseData['message'], true);
            $this->tgBotApiCallService->sendTextOnly(__('tgbot.register.error_duplicate_tg_user_id_with_other_bot', ['bot_id' => '@' . $obj['source_id']]));

            return;
        }

        if ($responseCode === PlatformApiCodeEnum::REGISTER_DUPLICATE_PHONE_NUMBER_WITH_AGENT_CODE->value) {
            throw new \Exception(
                'Error: ' . $responseData['message'],
                ErrorCodeEnum::PLATFORM_API_CALL_NOT_SUCCESS->value
            );
        } elseif ($responseCode === ErrorCodeEnum::PLATFORM_API_CALL_NOT_SUCCESS->value) {
            throw new \Exception(
                __('tgbot.register.error'),
                ErrorCodeEnum::PLATFORM_API_CALL_NOT_SUCCESS->value
            );
        } elseif (!empty($responseCode)) {
            throw new \Exception(
                'Error: ' . $responseData['message'] ?? 'unknown error',
                ErrorCodeEnum::PLATFORM_API_CALL_NOT_SUCCESS->value
            );
        }
    }

    private function sendMessage_UseAgentCode(string $code): int
    {
        $message = __('tgbot.register.use_agent_code', ['code' => $code]);

        return $this->tgBotApiCallService->sendTextOnly($message);
    }

    // ! Only For DEBUG
    private function sendMessage_Register_Infos(array $registData): void
    {
        if (config('app.debug') === false) {
            return;
        }

        $tgBotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: json_encode($registData)
        );

        $this->tgBotApiCallService->send($tgBotMessage);
    }

    /**
     * Summary of getCacheDataAndCheckStatus
     *
     * @throws \Exception Cache data not found
     * @throws \Exception Cache status not match
     */
    private function getCacheDataAndCheckStatus(string $key, RegistrationStatusEnum $status): array
    {
        $cacheData = Cache::get($key);

        if (empty($cacheData)) {
            throw new \Exception('Cache data not found', ErrorCodeEnum::CACHE_DATA_NOT_FOUND->value);
        } elseif ($cacheData['status'] !== $status->value) {
            throw new \Exception(
                "Cache status not match, expected: {$status->value}, actual: {$cacheData['status']}",
                ErrorCodeEnum::CACHE_STATUS_INVALID->value
            );
        }

        return $cacheData;
    }
}
