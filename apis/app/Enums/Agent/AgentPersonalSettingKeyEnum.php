<?php

declare(strict_types=1);

namespace App\Enums\Agent;

class AgentPersonalSettingKeyEnum
{
    // 圖片類設置
    public const int AGENT_MAINTAIN_FLAG = 55001;   // 是否切換至維護模式

    public const int AGENT_MAINTAIN_EXCLUDE_AGENTS = 55002;   // 維護模式排除代理

    public const int AGENT_MAINTAIN_START_TIME = 55008;    // 維護模式起始時間

    public const int AGENT_MAINTAIN_END_TIME = 55009;    // 維護模式結束時間

    // 代理其他設置（95000+，parent 多為 95000）
    public const int PERSONAL_OTHER = 95000; // 代理其他設置

    public const int SHAREHOLDER_POINT = 95001; // 分桶股份佔成數

    public const int SHAREHOLDER_VISIBLE = 95002; // 代理分桶開關

    public const int HAS_MEDIA_SHOW = 95003; // 是否顯示影城開關

    public const int SHARE_VISIBLE = 95004; // 是否顯示股份

    public const int SHARE_EDITABLE = 95005; // 是否允許修改股份

    public const int USER_SEND_RED_ENVELOPE_BUTTON_VISIBLE = 95101; // 代理收發紅包開關

    public const int USER_RECEIVE_RED_ENVELOPE_BUTTON_VISIBLE = 95102; // 代理收紅包開關

    public const int USER_GIFT_RED_ENVELOPE_BUTTON_VISIBLE = 95103; // 代理贈紅包

    // 工單4308：已改由 agent_menus 的 member_make_up_point 權限節點控管，agent_setting_defines 保留但 is_visible=0（停用不顯示）
    public const int USER_MAKE_UP_POINT_BUTTON_VISIBLE = 95104; // 代理補點開關（已棄用，見上）

    // 工單4308：已改由 agent_menus 的 member_deduct_point 權限節點控管，agent_setting_defines 保留但 is_visible=0（停用不顯示）
    public const int USER_DEDUCT_POINT_BUTTON_VISIBLE = 95105; // 代理遊戲錢包扣分開關（已棄用，見上）

    public const int PROFIT_SHARE_WALLET_CAP_AMOUNT = 95110; // 代理佔成錢包封頂額度，-1=不限

    public const int PROFIT_SHARE_WALLET_DEPOSIT_VISIBLE = 95111; // 代理佔成錢包加分開關

    public const int PROFIT_SHARE_WALLET_WITHDRAWAL_VISIBLE = 95112; // 代理佔成錢包洗分開關

    public const int AGENT_MODIFY_AUTO_WITHDRAWAL_ACCOUNT_NAME_VISIBLE = 95201; // 代理可否編輯自助洗分名字

    public const int AGENT_EDIT_AGENT_REAL_NAME = 95202; // 允許編輯代理真實姓名

    public const int MAIN_WALLET_TRANSFER_DISABLED = 95301; // 主錢包轉出開關

    public const int PROFIT_SHARE_WALLET_TRANSFER_DISABLED = 95302; // 佔成錢包轉出開關
}
