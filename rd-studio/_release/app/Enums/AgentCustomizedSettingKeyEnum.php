<?php

namespace App\Enums;

class AgentCustomizedSettingKeyEnum 
{
    //客服相關設置
    Const CS_IS_CUSTOM_SERVICE_ICON_VISIBLE = 2001;   //是否在登入頁顯示客服LINK
    Const CS_CUSTOM_SERVICE_ICON_LINK_URL = 2002;        //登入頁客服LINK連結
    Const CS_CUSTOM_SERVICE_ADD_FRIEND = 2003;        //聊天是否可以加好友

    //文案設置相關
    Const LABEL_TEXT_WALLET_POINT = 3001;   //錢包文字
    Const LABEL_TEXT_EVENT_REWARD = 3002;   //活動獎勵文字
    Const LABEL_TEXT_UNLIMITED = 3003;   //全民代理文字
    Const LABEL_TEXT_JACKPOT = 3004;    //v-jackpot
    
    //活動設置
    const LABEL_TEXT_V_JACKPOT_REWARD_ITEM = 3511;    //v-jackpot 頭獎獎品

    //Layout樣式設置
    Const STYLE_HAS_EVENT = 4001;   //是否有活動Tab
    Const STYLE_HAS_PGS_LOBBY_LAYOUT = 4051;   //是否顯示PGS_天馬樣式的大聽(首頁直接顯示PGS遊戲列表)
    Const STYLE_HAS_DEPOSIT_RECORD_PAGE = 4071;   //是否有支付紀錄訂單頁面

    //圖片類設置
    Const STYLE_IMAGE_GAME_PAGE_LOADING_ICON = 4101;   //遊戲頁的loading圖示  
    Const AUTO_AGENT_CODE = 4201;    //是否自動帶入邀請碼

    //代理其他設置
    Const HAS_MEDIA_SHOW = 95003;

    //財務設置
    Const WITHDRAWAL_BUTTON_VISIBLE = 6002;   //是否顯示自助洗分按鈕
    Const DEPOSIT_BUTTON_VISIBLE = 6003;   //是否顯示充值按鈕
    Const V_JACKPOT_RATE = 6004;   //V Jackpot
    Const PAYMENT_AUTO_COMPLETED = 6006;   //自動代付開關
    Const PAYMENT_AUTO_COMPLETED_AMOUNT = 6007;   //自動代付金額
    Const POINT_BARREL_SETTING = 6100;   //分桶開關

}