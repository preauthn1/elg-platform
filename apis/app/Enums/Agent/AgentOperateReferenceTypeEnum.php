<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * agent_operate_logs.reference_type —— reference_id 指向哪一種實體。
 *
 * 此表由 API_Agent 與 apis 共寫；新增 case 必須同步 API_Agent 的
 * app/Enums/AgentSystemConstant.php::REFERENCE_TYPE_*，兩邊字串必須完全一致。
 *
 * 值一律用單數別名而非 FQCN：兩個 repo 的 namespace 不同，
 * 且 API_Agent 並非每種實體都有對應的 Eloquent Model。
 */
enum AgentOperateReferenceTypeEnum: string
{
    /**
     * 未指向任何實體 —— 資料表預設值，永遠不主動寫入。
     *
     * 三種來源會落在這裡，且都不會消失：
     * 1. 本次變更之前寫入的舊資料（不回填）
     * 2. 本來就沒有操作對象、reference_id 寫 0 的紀錄（登入、權限同步、資料匯出）
     * 3. cs_user_login／cs_user_login_fail 等由本 repo 之外的系統寫入的紀錄
     *
     * 讀取端必須永遠保留此值的相容分支（退回舊的無條件 JOIN 行為）。
     */
    case UNKNOWN = '';

    /** agents.id */
    case AGENT = 'agent';

    /** members.id */
    case MEMBER = 'member';

    /** orders.id */
    case ORDER = 'order';

    /** withdrawal_orders.id */
    case WITHDRAWAL_ORDER = 'withdrawal_order';

    /** 簡訊發送紀錄 id */
    case SMS_RECORD = 'sms_record';

    /** vendor_infos.id */
    case VENDOR_INFO = 'vendor_info';

    /** 遊戲標籤 id */
    case GAME_TAG = 'game_tag';

    /** agent_real_name_validation_requests.id */
    case AGENT_REAL_NAME_REQUEST = 'agent_real_name_request';

    /** 會員的遊戲商帳號 id（各遊戲商一張表，由 Driver 的 accountModel 決定） */
    case VENDOR_ACCOUNT = 'vendor_account';

    /**
     * agent_menus.id —— 匯出稽核紀錄指向被匯出的後台選單。
     *
     * agent_menus 是全站共用表（不分代理），url 欄位即頁面路徑（member/index、report/sr02），
     * 由後端以 url 反查 id，不接受前端直接指定 id。
     */
    case AGENT_MENU = 'agent_menu';
}
