<?php

namespace App\Enums;

enum TgBotCallbackActionEnum: string
{
    // ##### Common ######
    case BACK = 'back';
    case DELETE_MESSAGE = 'delete_message';
    case CANCEL = 'cancel';
    case HOMEPAGE = 'homepage';
    case HOMEPAGE_WITHOUT_REGISTERED = 'homepage_without_registered';

    // ##### Home page not registered ######
    case REGISTER = 'register';
    case CUSTOMER_SERVICE = 'customer_service';
    case OFFICIAL_TELEGRAM = 'official_telegram';
    case LANGUAGE = 'language';

    // ##### Home page ######
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    case WALLET_MENAGEMENT = 'wallet_management';
    case SELECT_GAMES = 'select_games';
    case HISTORIES = 'histories';
    case EVENT_INFORMATION = 'event_information';
    case LAUNCH_SITE = 'launch_site';
    case LAUNCH_SITE2 = 'launch_site2';
    case VIP = 'vip';
    case INVITE = 'invite_reward';
    case DAILY_SIGN_IN = 'daily_sign_in';
    case DAILY_TASK = 'daily_task';
    case TG_SHARE = 'tg_share';

    // ##### Register ######
    case REGISTER_WANT_ENTER_AGENT_CODE = 'register_want_enter_agent_code';
    case REGISTER_DISMISS_AGENT_CODE = 'register_dismiss_agent_code';

    // ##### Deposit ######
    case DEPOSIT_WAIT_SELECT_ORDER_TYPE = 'deposit_wait_select_order_type';

    // ##### Withdrawal ######
    case WITHDRAWAL_SET_PAYMENT_PASSWORD = [REDACTED]';

    // ##### Game ######
    case GAME_RECENTLY = 'game_recently';
    case GAME_HOT = 'game_hot';
    case GAME_LOBBY = 'game_lobby';

    // ##### History ######
    case HISTORY_SHOW_BY_DAYS_FILTER = 'history_show_by_days_filter';
    case HISTORY_SHOW_BY_DAYS_FILTER_PREV = 'history_show_by_days_filter_prev';
    case HISTORY_SHOW_BY_DAYS_FILTER_NEXT = 'history_show_by_days_filter_next';
    case HISTORY_SHOW_BY_DAYS_FILTER_RETURN = 'history_show_by_days_filter_return';

    // ##### Languages ######
    case LANGUAGE_SELECT = 'language_select';

    // ##### Vip ######
    case VIP_RULE = 'vip_rule';

    // ##### 每日任務頁面 ######
    case DAILY_TASK_CANNOT_CLAIM = 'daily_task_cannot_claim';
    case DAILY_TASK_CLAIM_DONE = 'daily_task_claim_done';
    case DAILY_TASK_CAN_CLAIM = 'daily_task_can_claim';
    case DAILY_TASK_PROGRESS = 'daily_task_progress';
    case DAILY_TASK_REMIND = 'daily_task_remind';

    // ##### 每日任務的開關頁面 ######
    case DAILY_TASK_REMIND_ENABLE = 'daily_task_remind_enable';
    case DAILY_TASK_REMIND_DISABLE = 'daily_task_remind_disable';

    // ##### 每日簽到頁面 ######
    case DAILY_SIGN_IN_SIGN = 'daily_sign_in_sign';
    case DAILY_SIGN_IN_DONE = 'daily_sign_in_done';
    case DAILY_SIGN_IN_REMIND = 'daily_sign_in_remind';

    // ##### 每日簽到提醒頁面 ######
    case DAILY_SIGN_IN_REMIND_ENABLE = 'daily_sign_in_remind_enable';
    case DAILY_SIGN_IN_REMIND_DISABLE = 'daily_sign_in_remind_disable';

    // ##### 邀請獎勵頁面 ######
    case INVITE_CANNOT_CLAIM = 'invite_cannot_claim';
    case INVITE_CLAIM_DONE = 'invite_claim_done';
    case INVITE_CLAIM_REWARDS = 'invite_claim_rewards';
    case INVITE_SHARE = 'invite_share';
}
