<?php

namespace App\Services\ActionServices;

use App\Builders\TgBotButtonRowBuilder;
use App\DTOs\TgBotApiMessageParameterDTO;
use App\Enums\ChatMessages\ChatMessagesTypeEnum;
use App\Enums\TgBotCallbackActionEnum;
use App\Enums\TgBotCallbackExtraParamEnum;

/**
 * @deprecated 改成直接跳轉到 web 頁面
 */
class HistoryService extends BaseService
{
    public function showDaysRangeList()
    {
        $tgbotMessage = $this->makeDaysRangeListMessage();
        $this->tgBotApiCallService->send($tgbotMessage);
    }

    public function showDaysRangeListFromReturn()
    {
        $tgbotMessage = $this->makeDaysRangeListMessage();
        $this->tgBotApiCallService->editMessage($tgbotMessage);
    }

    public function handle_ShowByDaysFilter()
    {
        $extraParamDays = TgBotCallbackExtraParamEnum::HISTORY_DAYS_FILTER->value;
        $days = $this->tgWebhookData->callbackActionExtraDatas[$extraParamDays];

        $extraParamPage = TgBotCallbackExtraParamEnum::HISTORY_PAGINATION->value;
        $nowPage = $this->tgWebhookData->callbackActionExtraDatas[$extraParamPage];

        $historyDatas = $this->platformApiCallService->getHistory((int) $days, (int) $nowPage);
        $historyList = $historyDatas['record'];
        $historyPaginations = $historyDatas['pagination'];

        $content = $this->makeHistoryTableContentRow([
            __('tgbot.date'),
            __('tgbot.amount'),
            __('tgbot.type'),
            __('tgbot.status'),
        ]) . "\n";
        foreach ($historyList ?? [] as $history) {
            $amountStr = str_pad($history['amount'], 10);
            $type = str_pad($history['order_type'], 8);
            $status = str_pad($history['status_name'], 8);
            $content .= $this->makeHistoryTableContentRow([
                $history['created_at'],
                $amountStr,
                $type,
                $status,
            ]) . "\n";
        }

        $buttonRows = new TgBotButtonRowBuilder;
        if (!empty($historyPaginations['previous'])) {
            $action = TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER_PREV->value;
            $page = $historyPaginations['previous'];
            $buttonRows->addButton(
                text: __('tgbot.previous_page'),
                callbackData: "action={$action}&{$extraParamDays}={$days}&{$extraParamPage}={$page}"
            );
        }

        if (!empty($historyPaginations['next'])) {
            $action = TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER_NEXT->value;
            $page = $historyPaginations['next'];
            $buttonRows->addButton(
                text: __('tgbot.next_page'),
                callbackData: "action={$action}&{$extraParamDays}={$days}&{$extraParamPage}={$page}"
            );
        }

        $buttonRows->nextRow();
        $buttonRows->addButton(
            __('tgbot.return'),
            TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER_RETURN->value
        );

        $tgbotMessage = new TgBotApiMessageParameterDTO(
            ChatMessagesTypeEnum::TEXT,
            content: $content,
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        $this->tgBotApiCallService->editMessage($tgbotMessage);
    }

    private function makeDaysRangeListMessage(): TgBotApiMessageParameterDTO
    {
        $action = TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER->value;
        $days = TgBotCallbackExtraParamEnum::HISTORY_DAYS_FILTER->value;
        $page = TgBotCallbackExtraParamEnum::HISTORY_PAGINATION->value;

        $actionDay0 = "action={$action}&{$days}=0&{$page}=1";
        $actionDay3 = "action={$action}&{$days}=3&{$page}=1";
        $actionDay7 = "action={$action}&{$days}=7&{$page}=1";
        $actionDay30 = "action={$action}&{$days}=30&{$page}=1";

        $buttonRows = new TgBotButtonRowBuilder;
        $buttonRows->addButton(__('tgbot.history.today'), $actionDay0);
        $buttonRows->addButton(__('tgbot.history.3_days'), $actionDay3);
        $buttonRows->nextRow();
        $buttonRows->addButton(__('tgbot.history.7_days'), $actionDay7);
        $buttonRows->addButton(__('tgbot.history.30_days'), $actionDay30);
        $buttonRows->nextRow();
        $buttonRows->addButtonByActionEnum(TgBotCallbackActionEnum::BACK);

        $tgbotMessage = new TgBotApiMessageParameterDTO(
            ChatMessagesTypeEnum::TEXT,
            content: __('tgbot.history.please_select_days')
        );
        $tgbotMessage->buttonRows = $buttonRows->build();

        return $tgbotMessage;
    }

    private function makeHistoryTableContentRow(array $columnDatas): string
    {
        return implode('|', $columnDatas);
    }
}
