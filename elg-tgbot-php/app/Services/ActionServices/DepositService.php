<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\DepositInfoDTO;
use App\DTOs\DepositStatusMapDTO;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\DepositStatusEnum;
use App\Enums\ErrorCodeEnum;
use App\Enums\TgBotButtonRowTypeEnum;
use App\Enums\TgBotCallbackActionEnum;
use App\Enums\TgBotCallbackExtraParamEnum;
use App\Helpers\CacheHelper;
use App\Helpers\CacheKeysHelper;
use App\Helpers\ReplyMessageHelper;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

class DepositService extends BaseService
{
    protected function show()
    {
        $statusMap = DepositStatusMapDTO::start();
        $depositInfos = $this->platformApiCallService->getDepositInfos();

        if (empty($depositInfos)) {
            throw new \Exception(__('tgbot.empty_collection'), ErrorCodeEnum::DEPOSIT_LIST_EMPTY->value);
        }

        $this->sendMessage_DepositInfoList($depositInfos);

        CacheHelper::putCacheAndLog(CacheKeysHelper::getDepositKey(), [
            'status' => $statusMap->after->value,
            'chat_id' => $this->tgWebhookData->chatId,
        ]);
    }

    public function handle_WaitSelectOrderType(): void
    {
        $statusMap = DepositStatusMapDTO::waitOrderType();
        $depositData = $this->getCacheDataAndCheckStatus(CacheKeysHelper::getDepositKey(), $statusMap->before);

        $extraParam = TgBotCallbackExtraParamEnum::DEPOSIT_ORDER_TYPE_ID->value;
        $orderTypeId = $this->tgWebhookData->callbackActionExtraDatas[$extraParam];

        $messageId = $this->sendMessage_WaitDepositAmount($orderTypeId);
        ReplyMessageHelper::register($messageId, $statusMap->replyMessageStatus);

        $depositData['status'] = $statusMap->after->value;
        $depositData['order_type_id'] = $orderTypeId;
        CacheHelper::putCacheAndLog(CacheKeysHelper::getDepositKey(), $depositData);
    }

    public function handle_WaitDepositAmount(): void
    {
        $statusMap = DepositStatusMapDTO::waitAmount();
        $depositData = $this->getCacheDataAndCheckStatus(CacheKeysHelper::getDepositKey(), $statusMap->before);

        $depositData['status'] = $statusMap->after->value;
        $depositData['amount'] = $this->tgWebhookData->message['text'];

        $responseData = $this->platformApiCallService->depositOrder(
            orderTypeId: (int) $depositData['order_type_id'],
            amount: (int) $depositData['amount']
        );
        $this->sendMessage_PleaseRedirectToPaymentLink($responseData['url']);

        CacheHelper::putCacheAndLog(CacheKeysHelper::getDepositKey(), $depositData);
    }

    /**
     * Summary of sendMessage_DepositInfoList
     *
     * @param  DepositInfoDTO[]  $depositInfos
     */
    private function sendMessage_DepositInfoList(array $depositInfos): void
    {
        $walletBalance = $this->platformApiCallService->getWalletBalance();

        $buttonRows = new TgBotButtonRowBuilder;
        foreach ($depositInfos as $depositInfo) {
            $url = $depositInfo->url;

            $parsedUrl = parse_url($depositInfo->url);
            if (empty($parsedUrl['lang'])) {
                $url = $depositInfo->url . '&lang=' . App::getLocale();
            }

            $buttonRows->addButtonByArray([
                'text' => $depositInfo->name,
                'web_app' => ['url' => $url],
            ]);
            $buttonRows->nextRow();
        }

        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $content = $walletBalance->toText() . "\n";
        $content .= __('tgbot.deposit.please_select_payment_type');

        $tgbotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: $content
        );
        $tgbotMessage->buttonRows = $buttonRows->build();
        $this->tgBotApiCallService->send($tgbotMessage);
    }

    private function sendMessage_WaitDepositAmount(string $orderTypeId): int
    {
        $depositInfoDetail = $this->platformApiCallService->getDepositInfoDetail($orderTypeId);

        $min = $depositInfoDetail['min_amount'];
        $max = $depositInfoDetail['max_amount'];
        $content = __('tgbot.deposit.please_enter_amount') . "\n";
        $content .= __('tgbot.min_max_value', ['min' => $min, 'max' => $max]);

        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::KEYBOARD);
        $buttonRows->setAttr('force_reply', true);

        $tgBotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: $content
        );
        $tgBotMessage->buttonRows = $buttonRows->build();

        return $this->tgBotApiCallService->send($tgBotMessage);
    }

    public function sendMessage_PleaseRedirectToPaymentLink(string $paymentRedirectLink): int
    {
        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->setType(TgBotButtonRowTypeEnum::INLINE_KEYBOARD);
        $buttonRows->addButton(__('tgbot.deposit.payment_link'), url: $paymentRedirectLink);

        $tgBotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.deposit.please_redirect_to_payment_link')
        );
        $tgBotMessage->buttonRows = $buttonRows->build();

        return $this->tgBotApiCallService->send($tgBotMessage);
    }

    /**
     * Summary of getCacheDataAndCheckStatus
     *
     * @throws \Exception Cache data not found
     * @throws \Exception Cache status not match
     */
    private function getCacheDataAndCheckStatus(string $key, DepositStatusEnum $status): array
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

    // ! Only For DEBUG
    private function sendMessage_Deposit_Infos(array $depositData): void
    {
        if (config('app.debug') === false) {
            return;
        }

        $tgBotMessage = new TgBotApiMessageParameterDTO(
            type: ChatMessagesTypeEnum::TEXT,
            content: json_encode($depositData)
        );

        $this->tgBotApiCallService->send($tgBotMessage);
    }
}
