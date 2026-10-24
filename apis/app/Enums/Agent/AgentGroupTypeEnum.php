<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * 代理設置分組組別型別(agent_groups.agent_group_type)
 */
enum AgentGroupTypeEnum: int
{
    /** 個性化設置組別 → agents.customized_setting_group_id */
    case CUSTOMIZED_SETTING = 1;
    /** 支付設置組別 → agents.cash_flow_group_id */
    case CASH_FLOW = 2;
    /** SMS短信設置組別 → agents.sms_gateway_group_id */
    case SMS_GATEWAY = 3;
    /** 聊天組別 → agents.chat_group_id */
    case CHAT = 4;
    /** 遊戲商個性化設置 → agents.vendor_customize_group_id */
    case VENDOR_CUSTOMIZE = 5;
    /** 註冊/登入設置 → agents.member_field_setting_group_id */
    case MEMBER_FIELD_SETTING = 6;
    /** 電子郵件設置組別 → agents.email_gateway_group_id */
    case EMAIL_GATEWAY = 7;
}
