<?php

namespace target\support\Enums;

class AgentCustomizedSettingKeyEnum
{
    //V2前台
    const V2F_DEPOSIT_STATUS = 100001; //充值開關
    const V2F_DEPOSIT_TYPE = 100002; //充值類型; 0:交易所, 1:線上充值
    const V2F_WITHDRAW_STATUS = 100011; //提領開關
    const V2F_SWAP_STATUS = 100021; //SWAP開關
    //V2 System
    const V2_SYSTEM_NFT_CHECK_TOKEN = [REDACTED]; //NFT是否檢查token id

    //客服相關設置
    const CS_IS_CUSTOM_SERVICE_ICON_VISIBLE = 2001;   //是否在登入頁顯示客服LINK
    const CS_CUSTOM_SERVICE_ICON_LINK_URL = 2002;        //登入頁客服LINK連結
    const CS_CUSTOM_SERVICE_ADD_FRIEND = 2003;        //聊天是否可以加好友
    const CS_CUSTOM_SERVICE_ICON_PIC_URL = 2005;        //登入頁客服ICON連結
    const MEMBER_SEND_RED_ENVELOPE_BUTTON_VISIBLE = 2101;   //是否顯示玩家發紅包開關
    const MEMBER_RECEIVE_RED_ENVELOPE_BUTTON_VISIBLE = 2102;   //是否顯示玩家收紅包開關

    //客服後台設置
    const CS_BACKEND_SETTING = 2500;   //客服後台設置
    const CUSTOMIZED_HAS_QRCODE_SHOW = 2501;   //是否顯示QRcode開關
    const CS_RED_ENVELOPE_BUTTON_VISIBLE = 2511;   //是否顯示發紅包按鈕開關
    const CS_SEND_RED_ENVELOPE_BUTTON_VISIBLE = 2512;   //是否顯示客服發紅包開關
    const CS_RECEIVE_ENVELOPE_BUTTON_VISIBLE = 2513;   //是否顯示客服收紅包開關
    const CS_GIFT_RED_ENVELOPE_BUTTON_VISIBLE = 2514;   //是否顯示客服贈紅包開關
    const CS_POINT_BUTTON_VISIBLE = 2515;   //是否顯示客服補點開關

    //文案設置相關
    const LABEL_TEXT_WALLET_POINT = 3001;   //錢包文字
    const LABEL_TEXT_EVENT_REWARD = 3002;   //活動獎勵文字
    const LABEL_TEXT_UNLIMITED = 3003;   //全民代理文字
    const LABEL_TEXT_JACKPOT = 3004;    //v-jackpot
    const LABEL_TEXT_WITHDRAWAL = 3005;    //Withdrawal
    const LABEL_TEXT_AUTO_DEPOSIT = 3010;    //AutoDeposit
    const LABEL_TEXT_LOGIN_ERROR = 3012;    //登入錯誤的訊息
    
    //活動設置
    const LABEL_TEXT_V_JACKPOT_REWARD_ITEM = 3511;    //v-jackpot 頭獎獎品

    //Layout樣式設置
    const STYLE_HAS_EVENT = 4001;   //是否有活動Tab
    const SLOT_MACHINE_COMPETITION_VISIBLE = 4002;   //排行榜活動開關
    const STYLE_HAS_PGS_LOBBY_LAYOUT = 4051;   //是否顯示PGS_天馬樣式的大聽(首頁直接顯示PGS遊戲列表)
    const STYLE_IS_UNLIMIT_VISIBLE = 4052;     //是否顯示全民代理
    const STYLE_HAS_DEPOSIT_RECORD_PAGE = 4071;   //是否有支付紀錄訂單頁面
    const LOGIN_CHECK_EMAIL_VALIDATE_VISIBLE = 4072; //email認證開啟時, 登入是否檢查開關

    //圖片類設置
    const STYLE_IMAGE_GAME_PAGE_LOADING_ICON = 4101;   //遊戲頁的loading圖示
    const AUTO_AGENT_CODE = 4201;    //是否自動帶入邀請碼

    //遊戲商設定
    const VENDOR_SORT = 4301; //遊戲商排序
    const HOT_VENDOR_FLAG = 4302; //新遊戲商
    const NEW_VENDOR_FLAG = 4303; //熱門遊戲商

    //代理其他設置
    const HAS_MEDIA_SHOW = 95003;

    //財務設置

    const FINANCIAL_RED_ENVELOPE_TAX = 6001;  //紅包稅收
    const WITHDRAWAL_BUTTON_VISIBLE = 6002;   //是否顯示自助洗分按鈕
    const DEPOSIT_BUTTON_VISIBLE = 6003;   //是否顯示充值按鈕
    const INSTANT_RECHARGE_BUTTON_VISIBLE = 6005;   //是否顯示充值按鈕

    const FLOW_LIMIT_DISPLAY_VISIBLE = 6009;   //稽核流水顯示開關
    const FLOW_LIMIT_VISIBLE = 6010;   //稽核流水檢查開關
    const DEPOSIT_FLOW_LIMIT_RATE = 6011;   //充值流水倍數
    const EVENT_FLOW_LIMIT_RATE = 6012;   //活動流水倍數
    const UNLIMIT_FLOW_LIMIT_RATE = 6013;   //全民代理獎勵流水倍數
    const FIRST_DEPOSIT_FLOW_LIMIT_RATE = 6014;   //首充獎勵稽核流水倍數
    const AUTODESPOSITE_BUTTON_VISIBLE = 6015;   //是否顯示自助上分開關
    const RECHARGE_REWARDS_FLOW_LIMIT_RATE = 6016;   //次次充返利稽核流水倍數
    const DAILY_RECHARGE_REWARDS_FLOW_LIMIT_RATE = 6017;   //每日充返利稽核流水倍數
    const INVITING_PLAYERS_TO_REBATE_AUDIT_TURNOVER_MULTIPLES =  6018;   //邀請玩家優惠返利稽核流水倍數
    const HAS_BANK_CARD_SETTING =  6060;   //是否使用銀行卡開關
    const BANK_CARD_AMOUNT =  6061;   //能使用銀行卡的組數
    const OVER_LIMIT_NOTICE_AMOUNT =  6300;   //大額洗分通知金額
    const RISK_CONTROL_TG_CHAT_ID = 6301;   //客戶風控Telegram群組ID


    const MODIFY_AUTO_WITHDRAWAL_ACCOUNT_NAME_VISIBLE = 6041; //可否編輯自助洗分名字
    const PAYMENT_PASSWORD_BUTTON_VISIBLE = 6051;   //支付密碼開關
    const REAL_NAME_VERIFIED_VISIBLE = 6056;   //實名認證開關
    const POINT_BARREL_SETTING = 6100;   //分桶開關

    //其他設置
    const CUSTOMIZED_OTHER_UNLIMIT_INFO_BANNER_IMAGE_URL = 4103;   //全民代理Info Banner圖片網址
    const CUSTOMIZED_OTHER_UNLIMIT_ASK_BANNER_SIZE_IMAGE_URL = 4104;   //全民代理諮詢Banner尺寸圖片網址
    const CUSTOMIZED_OTHER_UNLIMIT_POP_UP_IMAGE_URL = 4105;   //全民代理彈框圖片網址
    const CUSTOMIZED_OTHER_HAOLONG_ODDS_IMAGE_URL = 9001;   //豪龍4D賠率圖
    const CUSTOMIZED_OTHER_MEMBER_AVATER_LIST = 9101;   //玩家頭像清單
    const CUSTOMIZED_OTHER_MEMBER_DEFAULT_AVATER = 9102;   //玩家預設頭像
}
