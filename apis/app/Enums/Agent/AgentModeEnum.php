<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * 代理模式 工單4121
 *
 * 對應個性化組別設定鍵 7001（AgentCustomizedSettingKeyEnum::AGENT_MODE_SETTING）
 * 的值域。實際生效以「群組設定 7001」為準（SP_summary_bet_record_team.sql:10
 * 註記 2023-05-09 起 agent_mode 改吃群組），agents.agent_mode 欄位為 legacy。
 *
 * ⚠️ 既有代碼仍以 magic number 判斷（僅判 1 無限代）：RegisterService、
 * Frontend\RegisterController、TgBotController、ShareHolderService、
 * MemberAgentService——為避免註冊流程回歸，本 enum 僅供新代碼使用，
 * 既有判斷點不動。
 *
 * ConfigTemplateResource 已於 op_ticket#3518 移出上述清單：`has_agent_layout`
 * 改判「agents.agent_mode 等於 UNLIMIT」且「組別設定 4052（是否顯示全民代理）開啟」，
 * 缺一即關閉。要比對的是 UNLIMIT 這個值，禁止把 agent_mode 當 truthy 用——值域 0-5，
 * 三層代理 (2/3) 與推廣代理 (5) 都會被誤判成開啟。agents.agent_mode 也禁止單獨擺在
 * `??` 左邊，該欄位 NOT NULL DEFAULT 0，會讓組別設定永遠失效。
 *
 * WINBOX(4) 為工單既定編號，四個 repo（apis/API_Agent/WEB_AgentBackend/
 * agent-backend）皆無實作，純佔位。
 */
enum AgentModeEnum: int
{
    /** 沒有任何模式 */
    case NONE = 0;

    /** 無限代（全民代理） */
    case UNLIMIT = 1;

    /** 三層代理（計算含代理） */
    case THREE_HIERARCHY_WITH_AGENT = 2;

    /** 三層代理（計算限定會員） */
    case THREE_HIERARCHY_MEMBER_ONLY = 3;

    /** winbox 模式（佔位，無實作） */
    case WINBOX = 4;

    /** 推廣代理（GGR 分潤，工單4121） */
    case GGR_PROMOTION = 5;
}
