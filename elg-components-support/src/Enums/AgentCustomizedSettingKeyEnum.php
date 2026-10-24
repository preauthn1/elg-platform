<?php

namespace target\support\Enums;

class AgentCustomizedSettingKeyEnum
{
    //客服相關設置
    const CS_IS_CUSTOM_SERVICE_ICON_VISIBLE = 2001;   //是否在登入頁顯示客服LINK
    const CS_CUSTOM_SERVICE_ICON_LINK_URL = 2002;        //登入頁客服LINK連結
    
    //文案設置相關
    const LABEL_TEXT_WALLET_POINT = 3001;   //錢包文字
    const LABEL_TEXT_EVENT_REWARD = 3002;   //活動獎勵文字
    const LABEL_TEXT_UNLIMITED = 3003;   //全民代理文字
    const LABEL_TEXT_JACKPOT = 3004;    //v-jackpot

    //活動設置
    const VIP_VISIBLE = 3531;    //vip開關功能
    const VIP_GIFT_OPTIONS = 3532;    //VIP獎勵贈送選項(1:點數 2:禮碼)
    const VIP_DEPOSIT_FLOW_LIMIT_SETTING = 3533;    //VIP充值稽核流水倍數

    //Layout樣式設置
    const STYLE_HAS_EVENT = 4001;   //是否有活動Tab
    const STYLE_HAS_PGS_LOBBY_LAYOUT = 4051;   //是否顯示PGS_天馬樣式的大聽(首頁直接顯示PGS遊戲列表)
    const STYLE_HAS_DEPOSIT_RECORD_PAGE = 4071;   //是否有支付紀錄訂單頁面

    //圖片類設置
    const STYLE_IMAGE_GAME_PAGE_LOADING_ICON = 4101;   //遊戲頁的loading圖示
    const AUTO_AGENT_CODE = 4201;    //是否自動帶入邀請碼

    //財務設置
    const MULTIPLE_WALLET_SWITCH = 6008;   //多錢包開關
}
