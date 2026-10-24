<?php

namespace App\Services;

use App\DTOs\TgWebhookDataDTO;
use App\Enums\ErrorCodeEnum;
use App\Enums\LogTypeEnum;
use App\Enums\ReplyMessageStatusEnum;
use App\Enums\TgBotCallbackActionEnum;
use App\Helpers\CacheKeysHelper;
use App\Services\ActionServices\BaseService;
use App\Services\ActionServices\DailySignInRemindService;
use App\Services\ActionServices\DailySignInService;
use App\Services\ActionServices\DailyTaskProgessService;
use App\Services\ActionServices\DailyTaskRemindService;
use App\Services\ActionServices\DailyTaskService;
use App\Services\ActionServices\DepositService;
use App\Services\ActionServices\ErrorService;
use App\Services\ActionServices\GameHotService;
use App\Services\ActionServices\GameRecentlyService;
use App\Services\ActionServices\GameService;
use App\Services\ActionServices\HistoryService;
use App\Services\ActionServices\HomepageRestartService;
use App\Services\ActionServices\HomepageService;
use App\Services\ActionServices\InfoService;
use App\Services\ActionServices\InviteService;
use App\Services\ActionServices\InviteShareService;
use App\Services\ActionServices\LanguageService;
use App\Services\ActionServices\RegistrationService;
use App\Services\ActionServices\VipService;
use App\Services\ActionServices\WithdrawalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookHandleService
{
    protected TgWebhookDataDTO $tgWebhookData;

    public function exec()
    {
        $this->tgWebhookData = app(TgWebhookDataDTO::class);

        try {
            if (!empty($this->tgWebhookData->callbackAction)) {
                // 處理按鈕類型的事件
                $this->handleCallbackAction();
            } elseif (!empty($this->tgWebhookData->message['reply_to_message'])) {
                // 處理回覆訊息
                $this->handleReplyMessage();
            } else {
                // 處理指令 ex: /start /restart /info
                $this->handleMessage();
            }
        } catch (Throwable $e) {
            $errorCodeEnum = ErrorCodeEnum::tryFrom((int) $e->getCode());

            if ($errorCodeEnum !== null) {
                // 可預期的業務邏輯報錯
                ErrorService::init()->showError($e->getMessage());
            } else {
                // 不可預期的報錯
                $fullErrorLog = 'Tgbot_callback_error, At ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getMessage();
                Log::error($fullErrorLog);
                $requestId = Context::getHidden('REQUEST_ID');
                ErrorService::init()->showError(__('tgbot.error', ['request_id' => $requestId]));
            }
        }
    }

    private function handleCallbackAction()
    {
        switch ($this->tgWebhookData->callbackAction) {
            case TgBotCallbackActionEnum::BACK->value:
                BaseService::init()->back();
                break;

            case TgBotCallbackActionEnum::DELETE_MESSAGE->value:
                BaseService::init()->deleteMessage();
                break;

            case TgBotCallbackActionEnum::CANCEL->value:
                HomepageService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::HOMEPAGE->value:
                HomepageService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::REGISTER->value:
                RegistrationService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::REGISTER_WANT_ENTER_AGENT_CODE->value:
                RegistrationService::init()->handle_WantEnterAgentCode();
                break;

            case TgBotCallbackActionEnum::REGISTER_DISMISS_AGENT_CODE->value:
                RegistrationService::init()->handle_DismissEnterAgentCode();
                HomepageService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::DEPOSIT->value:
                DepositService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::DEPOSIT_WAIT_SELECT_ORDER_TYPE->value:
                DepositService::init()->handle_WaitSelectOrderType();
                break;

            case TgBotCallbackActionEnum::WITHDRAWAL->value:
                WithdrawalService::init()->showPage();
                break;

                // ##### Game #####
            case TgBotCallbackActionEnum::SELECT_GAMES->value:
                GameService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::GAME_RECENTLY->value:
                GameRecentlyService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::GAME_HOT->value:
                GameHotService::init()->showPage();
                break;

                // case TgBotCallbackActionEnum::HISTORIES->value:
                //     HistoryService::init()->showDaysRangeList();
                //     break;

                // case TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER_RETURN->value:
                //     HistoryService::init()->showDaysRangeListFromReturn();
                //     break;

                // case TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER_NEXT->value:
                // case TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER_PREV->value:
                // case TgBotCallbackActionEnum::HISTORY_SHOW_BY_DAYS_FILTER->value:
                //     HistoryService::init()->handle_ShowByDaysFilter();
                //     break;

            case TgBotCallbackActionEnum::LANGUAGE->value:
                LanguageService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::LANGUAGE_SELECT->value:
                LanguageService::init()->handle_SelectLanguage();
                break;

                // ##### VIP #####
            case TgBotCallbackActionEnum::VIP->value:
                VipService::init()->showPage();
                break;

                // ##### 每日任務 #####
            case TgBotCallbackActionEnum::DAILY_TASK->value:
                DailyTaskService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::DAILY_TASK_REMIND->value:
                DailyTaskRemindService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::DAILY_TASK_PROGRESS->value:
                DailyTaskProgessService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::DAILY_TASK_CAN_CLAIM->value:
                DailyTaskService::init()->claim();
                break;

                // ##### 每日任務提醒頁面 #####
            case TgBotCallbackActionEnum::DAILY_TASK_REMIND_ENABLE->value:
                DailyTaskRemindService::init()->enable();
                break;

            case TgBotCallbackActionEnum::DAILY_TASK_REMIND_DISABLE->value:
                DailyTaskRemindService::init()->disable();
                break;

                // ##### 每日簽到 #####
            case TgBotCallbackActionEnum::DAILY_SIGN_IN->value:
                DailySignInService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::DAILY_SIGN_IN_SIGN->value:
                DailySignInService::init()->signIn();
                break;

                // ##### 每日簽到提醒頁面 #####
            case TgBotCallbackActionEnum::DAILY_SIGN_IN_REMIND->value:
                DailySignInRemindService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::DAILY_SIGN_IN_REMIND_ENABLE->value:
                DailySignInRemindService::init()->enable();
                break;

            case TgBotCallbackActionEnum::DAILY_SIGN_IN_REMIND_DISABLE->value:
                DailySignInRemindService::init()->disable();
                break;

                // ##### 邀請獎勵頁面 #####
            case TgBotCallbackActionEnum::INVITE->value:
                InviteService::init()->showPage();
                break;

            case TgBotCallbackActionEnum::INVITE_CLAIM_REWARDS->value:
                InviteService::init()->claimRewards();
                break;

            case TgBotCallbackActionEnum::INVITE_SHARE->value:
                InviteShareService::init()->showPage();
                break;

            default:
                Log::warning(
                    "Unknown callback action: {$this->tgWebhookData->callbackAction}",
                    ['extra' => ['type' => LogTypeEnum::TG_WEBHOOK->value]]
                );
                throw new \Exception(__('tgbot.action_not_allowed'), ErrorCodeEnum::INVALID_CALLBACK_ACTION->value);
        }
    }

    private function handleReplyMessage()
    {
        $repliedMessageId = $this->tgWebhookData->message['reply_to_message']['message_id'] ?? -1;

        $cacheKey = CacheKeysHelper::getRepliedMessageKey($repliedMessageId);
        $repliedMessageData = Cache::get($cacheKey);

        if (empty($repliedMessageData)) {
            Log::warning(
                "Cache not found: {$cacheKey}",
                ['extra' => ['type' => LogTypeEnum::TG_WEBHOOK->value]]
            );
            throw new \Exception(__('tgbot.operation_not_found'), ErrorCodeEnum::REPLY_MESSAGE_NOT_FOUND->value);
        }

        Cache::forget($cacheKey);

        $nowStatus = $repliedMessageData['status'];
        switch ($nowStatus) {
            case ReplyMessageStatusEnum::REGISTER_WAIT_PHONE_NUMBER->value:
                RegistrationService::init()->handle_PhoneNumber();
                break;

            case ReplyMessageStatusEnum::REGISTER_WAIT_AGENT_INVITATION_CODE->value:
                RegistrationService::init()->handle_AgentCode();
                HomepageService::init()->showPage();
                break;

            case ReplyMessageStatusEnum::DEPOSIT_WAIT_DEPOSIT_AMOUNT->value:
                DepositService::init()->handle_WaitDepositAmount();
                break;

            default:
                Log::warning(
                    "repliedMessageData status not found: {$nowStatus}",
                    ['data' => $repliedMessageData, 'extra' => ['type' => LogTypeEnum::CACHE->value]]
                );
                throw new \Exception(
                    __('tgbot.operation_not_allowed'),
                    ErrorCodeEnum::REPLY_MESSAGE_STATUS_INVALID->value
                );
        }
    }

    private function handleMessage()
    {
        $isPrivate = ($this->tgWebhookData->message['chat']['type'] ?? '') === 'private';
        if (!$isPrivate) {
            return [
                'code' => 1000,
                'message' => '',
            ];
        }

        if (!empty($this->tgWebhookData->message['contact']['phone_number'])) {
            RegistrationService::init()->handle_PhoneNumberV2();

            return;
        }

        $messageText = $this->tgWebhookData->message['text'] ?? '';
        if ($messageText === '/info') {
            InfoService::init()->showPage();
        } elseif ($messageText === '/restart') {
            HomepageRestartService::init()->showPage();
        } else {
            HomepageService::init()->showPage();
        }
    }
}
