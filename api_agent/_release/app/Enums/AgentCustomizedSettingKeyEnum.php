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
    Const LABEL_TEXT_PAY_FEE = 3006;    //pay fee
    Const LABEL_TEXT_SMS_FEE = 3007;    //sms fee
    Const LABEL_TEXT_COST = 3008;    //cost
    Const LABEL_TEXT_REWARD = 3009;    //SR11平台顯示文字(V-World)
    
    //活動設置
    const LABEL_TEXT_V_JACKPOT_REWARD_ITEM = 3511;    //v-jackpot 頭獎獎品

    //Layout樣式設置
    Const STYLE_HAS_EVENT = 4001;   //是否有活動Tab
    Const STYLE_HAS_PGS_LOBBY_LAYOUT = 4051;   //是否顯示PGS_天馬樣式的大聽(首頁直接顯示PGS遊戲列表)
    Const STYLE_IS_UNLIMIT_VISIBLE = 4052;   //是否顯示全民代理
    Const PAY_PROCESSING_FEE_VISIBLE = 4053; //支付手續費
    Const SHORT_MESSAGE_SERVICE_FEE_VISIBLE = 4054; //簡訊服務費
    Const GAME_FEE_VISIBLE = 4055; //遊戲服務費
    Const STYLE_HAS_DEPOSIT_RECORD_PAGE = 4071;   //是否有支付紀錄訂單頁面

    //後台設置
    Const TWO_FACTOR_AUTHENTICATION_VISIBLE = 5001; //是否顯示二階段驗證

    //圖片類設置
    Const STYLE_IMAGE_GAME_PAGE_LOADING_ICON = 4101;   //遊戲頁的loading圖示  
    Const AUTO_AGENT_CODE = 4201;    //是否自動帶入邀請碼

    //遊戲商設定
    Const VENDOR_SORT = 4301; //遊戲商排序
    Const HOT_VENDOR_FLAG = 4302; //新遊戲商
    Const NEW_VENDOR_FLAG = 4303; //熱門遊戲商

    //代理其他設置
    Const HAS_MEDIA_SHOW = 95003;

    //財務設置
    Const WITHDRAWAL_BUTTON_VISIBLE = 6002;   //是否顯示自助洗分按鈕
    Const DEPOSIT_BUTTON_VISIBLE = 6003;   //是否顯示充值按鈕
    Const INSTANT_RECHARGE_BUTTON_VISIBLE = 6005;   //是否顯示充值按鈕

    Const FLOW_LIMIT_DISPLAY_VISIBLE = 6009;   //稽核流水顯示開關
    Const FLOW_LIMIT_VISIBLE = 6010;   //稽核流水檢查開關
    Const DEPOSIT_FLOW_LIMIT_RATE = 6011;   //充值流水倍數
    Const EVENT_FLOW_LIMIT_RATE = 6012;   //活動流水倍數
    Const UNLIMIT_FLOW_LIMIT_RATE = 6013;   //全民代理獎勵流水倍數
    Const FIRST_DEPOSIT_FLOW_LIMIT_RATE = 6014;   //首充獎勵稽核流水倍數
    Const PAYMENT_PASSWORD_BUTTON_VISIBLE = 6051;   //支付密碼開關

    Const POINT_BARREL_SETTING = 6100;   //分桶開關

}