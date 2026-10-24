<?php

declare(strict_types=1);

namespace App\Enums\Agent;

class AgentCustomizedSettingKeyEnum
{
    // 客服相關設置
    public const int CS_SETTING = 2000;   // 客服相關設置

    public const int CS_IS_CUSTOM_SERVICE_ICON_VISIBLE = 2001;   // 是否在登入頁顯示客服LINK

    public const int CS_CUSTOM_SERVICE_ICON_LINK_URL = 2002;        // 登入頁客服LINK連結

    public const int CS_CUSTOM_SERVICE_ADD_FRIEND = 2003;        // 聊天是否可以加好友

    public const int CS_CUSTOM_SERVICE_ICON_PIC_URL = 2005;        // 登入頁客服ICON連結

    public const int CUSTOMIZED_LIVE_CHAT_LICENSE_ID = 2008;  // Live Chat License ID

    public const int CUSTOMIZED_LIVE_CHAT_CLIENT_ID = 2009;   // Live Chat Client ID customized_cs_livechat_client_id

    public const int CS_HOMEPAGE_ICON_VISIBLE = 2014;   // 是否在首頁顯示客服LINK

    public const int CS_HOMEPAGE_ICON_LINK_URL = 2015;   // 首頁客服LINK連結

    public const int CS_CONTACT_BUTTONS_JSON = 2020;   // 聯繫客服按鈕JSON設定(可設定多組icon+網址)

    public const int MEMBER_SEND_RED_ENVELOPE_BUTTON_VISIBLE = 2101;   // 是否顯示玩家發紅包開關

    public const int MEMBER_RECEIVE_RED_ENVELOPE_BUTTON_VISIBLE = 2102;   // 是否顯示玩家收紅包開關

    public const int MOBILE_BIND_LIMIT = 2013; // 手機號綁定上限

    public const int EMAIL_BIND_LIMIT = 2016; // 信箱綁定上限

    public const int CHAT_AI_BOT_VISIBLE = 2210; // 聊天AI機器人開關

    public const int CHAT_AI_BOT_CODE = 2211; // 聊天AI機器人代碼(系統設定, 對應chat.chat_ai_infos.code)

    public const int LOGIN_FAIL_COUNT_LIMIT = 2212; // 登入失敗次數上限

    public const int ENABLE_RECOVER_PASSWORD = [REDACTED]; // 是否啟用找回密碼

    public const int PAYMENT_PWD_FAIL_COUNT_LIMIT = 2214; // 支付密碼錯誤次數上限

    public const int ENABLE_RECOVER_PAYMENT_PASSWORD = [REDACTED]; // 是否啟用找回支付密碼

    public const int GOOGLE_AUTH_FAIL_COUNT_LIMIT = 2216; // Google驗證錯誤次數上限 工單4146

    // 客服後台設置
    public const int CS_BACKEND_SETTING = 2500;   // 客服後台設置

    public const int CUSTOMIZED_HAS_QRCODE_SHOW = 2501;   // 是否顯示QRcode開關

    public const int CS_RED_ENVELOPE_BUTTON_VISIBLE = 2511;   // 是否顯示發紅包按鈕開關

    public const int CS_SEND_RED_ENVELOPE_BUTTON_VISIBLE = 2512;   // 是否顯示客服發紅包開關

    public const int CS_RECEIVE_ENVELOPE_BUTTON_VISIBLE = 2513;   // 是否顯示客服收紅包開關

    public const int CS_GIFT_RED_ENVELOPE_BUTTON_VISIBLE = 2514;   // 是否顯示客服贈紅包開關

    public const int CS_POINT_BUTTON_VISIBLE = 2515;   // 是否顯示客服補點開關

    public const int CS_BACKEND_LOGO = 2518;   // 客服後台logo

    public const int CS_BACKEND_COLOR_SCHEME = 2519;   // 客服後台色系

    // 後台設置
    public const int TWO_FACTOR_AUTHENTICATION_VISIBLE = 5001; // 是否顯示二階段驗證

    public const int INDEPENDENT_CHAT_GROUP_FOR_FIRST_LEVEL_AGENT = 5002; // 一級代理聊天群組獨立

    public const int AGENT_SELF_WITHDRAWAL_ENABLE = 5004; // 代理自助開洗分開關

    public const int AGENT_SELF_WITHDRAWAL_DAILY_MAX_AMOUNT = 5005; // 代理每日自助洗分金額上限, 0=不限

    public const int AGENT_SELF_WITHDRAWAL_DAILY_MAX_TIMES = 5006; // 代理每日自助洗分次數限制, 0=不限

    public const int AGENT_SELF_WITHDRAWAL_MODIFY_REAL_NAME = 5007; // 代理自助洗分是否可以修改真實姓名

    public const int AGENT_SELF_WITHDRAWAL_MIN_AMOUNT = 5008; // 代理自助洗分單次金額下限, 0=不限

    public const int AGENT_REAL_NAME_VALIDATE_STATUS = 5009; // 代理實名認證

    public const int SMS_RECORD_VERIFICATION_CODE_VISIBLE = 5011; // 簡訊發送紀錄直接顯示驗證碼開關

    public const int EMAIL_RECORD_VERIFICATION_CODE_VISIBLE = 5012; // 電子郵件發送紀錄直接顯示驗證碼開關

    public const int AGENT_HIERARCHY_LIMIT = 5200; // 代理層數上限

    public const int NEED_MOBILE_VALIDATE = 5300; // 是否綁定手機號認證

    public const int LOGIN_NEED_AUTHENTICATION = 5301; // 登入是否手機號認證

    public const int BACKEND_FORGET_PASSWORD_VISIBLE = 5302; // 代理後台忘記密碼開關

    public const int BACKEND_MOBILE_VALIDATE_EXCEPTION = 5303; // 後台手機綁定認證例外

    public const int AGENT_PAYMENT_PASSWORD_BUTTON_VISIBLE = 5304; // 代理後台支付密碼開關

    public const int BACKEND_LOGIN_FAIL_COUNT_LIMIT = 5305; // 代理登入錯誤次數上限（0:不啟用）

    public const int BACKEND_INACTIVE_LOCK_DAYS = 5003; // 代理長期未登入限制天數（0:不啟用）；id 沿用 2024 年 issue 1988 測試環境既有定義

    public const int SR11_RECYCLE_VISIBLE = 5010; // SR11、SR15回收金額顯示

    // 文案設置相關
    public const int LABEL_TEXT_WALLET_POINT = 3001;   // 錢包文字

    public const int LABEL_TEXT_EVENT_REWARD = 3002;   // 活動獎勵文字

    public const int LABEL_TEXT_UNLIMITED = 3003;   // 全民代理文字

    public const int LABEL_TEXT_JACKPOT = 3004;    // v-jackpot

    public const int LABEL_TEXT_WITHDRAWAL = 3005;    // Withdrawal

    public const int LABEL_TEXT_AUTO_DEPOSIT = 3010;    // AutoDeposit

    public const int LABEL_TEXT_LOGIN_ERROR = 3012;    // 登入錯誤的訊息

    public const int LABEL_TEXT_ROLLING_CHIP = 3013;   // 禮碼文字

    public const int BSB_CODE_ALIAS = 3015;   // BSB CODE 別名

    public const int PRIZE_REMIND = 3535;   // 中獎提醒

    // 活動設置
    public const int LABEL_TEXT_V_JACKPOT_REWARD_ITEM = 3511;    // v-jackpot 頭獎獎品

    public const int EVENT_NEED_BANK_ACCOUNT = 3521;    // 領獎是否需要檢查銀行帳戶

    public const int VIP_VISIBLE = 3531;    // vip開關功能

    public const int VIP_GIFT_OPTIONS = 3532;    // VIP獎勵贈送選項(1:點數 2:禮碼)

    public const int VIP_DEPOSIT_FLOW_LIMIT_SETTING = 3533;    // VIP充值稽核流水倍數

    public const int VIP_WITHDRAWAL_POP_UP = 3534;    // VIP單日次數上限彈窗

    public const int COUPON_CODE_VISIBLE = 3536; // 是否顯示優惠碼

    public const int MISSION_VISIBLE = 3537; // 是否顯示任務

    public const int EVENT_REWARD_REALNAME_VALIDATION = 3538; // 活動獎勵領取需實名驗證

    public const int EVENT_REWARD_MOBILE_VALIDATION = 3539; // 領獎需綁定手機號

    // Layout樣式設置
    public const int STYLE_HAS_EVENT = 4001;   // 是否有活動Tab

    public const int SLOT_MACHINE_COMPETITION_VISIBLE = 4002;   // 排行榜活動開關

    public const int EVENT_JACKPOT_VISIBLE = 4003;   // 排行榜活動開關

    public const int FORGET_PASSWORD_VISIBLE = 4004;   // 登入頁忘記密碼是否顯示

    public const int INFORMATION_VISIBLE = 4005;   // 資訊頁是否顯示

    public const int REFRESH_RANKING_VISIBLE = 4009;   // 刷新排行榜活動開關

    public const int PAYMENT_PASSWORD_CHECK_TIMING_SETTING = 4010; // 支付密碼檢查時間點 (0:登入時, 1:錢包頁)

    public const int ENABLE_BSB_CODE = 4011;   // 是否啟用 BSB CODE

    public const int SABA_WIDGET_VISIBLE = 4012;   // 是否啟用 SABA WIDGET 開關

    public const int LOGIN_NEED_BIND_BANK_CARD = 4013;  // 登入檢查綁定銀行卡

    public const int TG_BOT_LOGIN_VISIBLE = 4030; // Telegram Bot 登入開關

    public const int STYLE_HAS_PGS_LOBBY_LAYOUT = 4051;   // 是否顯示PGS_天馬樣式的大聽(首頁直接顯示PGS遊戲列表)

    public const int STYLE_IS_UNLIMIT_VISIBLE = 4052;     // 是否顯示全民代理

    public const int STYLE_HAS_DEPOSIT_RECORD_PAGE = 4071;   // 是否有支付紀錄訂單頁面

    public const int FRONTEND_GOOGLE_AUTH = 4074;   // 是否啟用前台google驗證

    public const int GAME_PROVIDER_FRONT_PAGE = 4075;   // 是否啟用遊戲商前置頁

    public const int BINDING_EMAIL_VISIBLE = 4076;   // 是否顯示綁定信箱

    public const int MODIFY_MOBILE_VISIBLE = 4077;   // 是否顯示修改手機號

    public const int MODIFY_EMAIL_VISIBLE = 4078;   // 是否顯示修改信箱

    public const int DIFFERENTIATE_FIRST_NAME_MEMBER = 4401;   // 區分實名玩家顯示

    public const int Customer_Button_In_WITHDRAWAL_VISIBLE = 2010; // 是否在自助開洗分顯示客服按鈕

    // 圖片類設置
    public const int STYLE_IMAGE_GAME_PAGE_LOADING_ICON = 4101;   // 遊戲頁的loading圖示

    public const int AUTO_AGENT_CODE = 4201;    // 是否自動帶入邀請碼

    // 遊戲商設定
    public const int VENDOR_SORT = 4301; // 遊戲商排序

    public const int HOT_VENDOR_FLAG = 4302; // 新遊戲商

    public const int NEW_VENDOR_FLAG = 4303; // 熱門遊戲商

    // 代理其他設置
    public const int  HAS_MEDIA_SHOW = 95003;

    // 財務設置
    public const int FINANCE_SETTING = 6000;   // 財務設置

    public const int FINANCIAL_RED_ENVELOPE_TAX = 6001;  // 紅包稅收

    public const int WITHDRAWAL_BUTTON_VISIBLE = 6002;   // 是否顯示自助洗分按鈕

    public const int DEPOSIT_BUTTON_VISIBLE = 6003;   // 是否顯示充值按鈕

    public const int V_JACKPOT_RATE = 6004;   // 是否顯示充值按鈕

    public const int INSTANT_RECHARGE_BUTTON_VISIBLE = 6005;   // 是否顯示充值按鈕

    public const int MULTIPLE_WALLET_SWITCH = 6008;   // 多錢包開關

    public const int FLOW_LIMIT_DISPLAY_VISIBLE = 6009;   // 稽核流水顯示開關

    public const int FLOW_LIMIT_VISIBLE = 6010;   // 稽核流水檢查開關

    public const int DEPOSIT_FLOW_LIMIT_RATE = 6011;   // 充值流水倍數

    public const int EVENT_FLOW_LIMIT_RATE = 6012;   // 活動流水倍數

    public const int UNLIMIT_FLOW_LIMIT_RATE = 6013;   // 全民代理獎勵流水倍數

    public const int FIRST_DEPOSIT_FLOW_LIMIT_RATE = 6014;   // 首充獎勵稽核流水倍數

    public const int AUTODESPOSITE_BUTTON_VISIBLE = 6015;   // 是否顯示自助上分開關

    public const int RECHARGE_REWARDS_FLOW_LIMIT_RATE = 6016;   // 次次充返利稽核流水倍數

    public const int DAILY_RECHARGE_REWARDS_FLOW_LIMIT_RATE = 6017;   // 每日充返利稽核流水倍數

    public const int INVITING_PLAYERS_TO_REBATE_AUDIT_TURNOVER_MULTIPLES = 6018;   // 邀請玩家優惠返利稽核流水倍數

    public const int FINANCIAL_SETTINGS_AUTODEPOSIT_PROCESSING_LIMIT = 6019;   // 自助上分處理限制

    public const int POLLING_OF_MULTIPLE_PAYMENT_PROVIDERS = 6023;   // 自動代付有多支付商時是否輪詢(0為否)

    public const int MAXIMUM_NUMBER_OF_FAILED_PAYMENTS = 6024;   // 自動代付失敗上限次數(0為不重試)

    public const int PAYMENT_FAILS_TG_NOTIFIES_GROUP_ID = 6025;   // 自動代付失敗時tg通知群組ID(空白為不通知)

    public const int DEPOSIT_FLOW_LIMIT_DISPLAY_VISIBLE = 6026;   // 儲值稽核流水顯示開關

    public const int EVENT_FLOW_LIMIT_DISPLAY_VISIBLE = 6027;   // 活動稽核流水顯示開關

    public const int DEPOSIT_FLOW_LIMIT_VISIBLE = 6028;   // 檢查儲值稽核流水開關

    public const int EVENT_FLOW_LIMIT_VISIBLE = 6029;   // 檢查活動稽核流水開關

    public const int MODIFY_BINDING_LIMIT_WITHDRAWAL = 6030;   // 修改綁定資料限制出金

    public const int RELEASE_APPLICATION_MAX_BALANCE = 6032;   // 申請解除稽核流水限制最大餘額

    public const int BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT = 6033; // 後台開分是否視為儲值，供所有儲值相關判斷共用（target/tickets#4352）

    public const int HAS_BANK_CARD_SETTING = 6060;   // 是否使用銀行卡開關

    public const int BANK_CARD_AMOUNT = 6061;   // 能使用銀行卡的組數

    public const int CAN_MODIFY_USDT = 6062;  // 自助洗分是否可以修改USDT位址

    public const int WITHDRAWAL_DEDUCT_FLOW_LIMIT = 6064;  // 洗分是否需扣除稽核流水

    public const int OVER_LIMIT_NOTICE_AMOUNT = 6300;   // 大額洗分通知金額

    public const int RISK_CONTROL_TG_CHAT_ID = 6301;   // 客戶風控Telegram群組ID

    public const int TRANSFER_CHANGE_FLOW_LIMIT = 6603;   // 轉帳會轉移稽核流水

    public const int MULTIPLE_WALLET_FLOW_LIMIT_SETTING = 6604;   // 多錢包轉帳流水需歸零

    public const int WITHDRAWAL_FLOW_LIMIT_SETTING_001 = 6605;   // 洗分是否需扣除稽核流水

    public const int TRANSFER_WITHDRAW_REALNAME_REQUIRED = 6606;   // 轉帳提款是否需要實名認證

    public const int MODIFY_AUTO_WITHDRAWAL_ACCOUNT_NAME_VISIBLE = 6041; // 可否編輯自助洗分名字

    public const int WITHDRAWAL_HAS_MOBILE_VALIDATE = 6042;   // 洗分是否需要電話認證

    public const int PAYMENT_PASSWORD_BUTTON_VISIBLE = 6051;   // 支付密碼開關

    public const int REAL_NAME_VERIFIED_VISIBLE = 6056;   // 實名認證開關

    public const int POINT_BARREL_SETTING = 6100;   // 分桶開關

    public const int INVITATION_CODE_SAME_ACCOUNT = 6506;  // 推薦碼同帳號

    public const int TRANSFER_MEMBER_FLOW_LIMIT_MULTIPLE = 6601; // 會員開分稽核流水倍數

    public const int FLOW_LIMIT_MODIFY_PERMISSION = 6602; // 是否可以修改稽核流水

    public const int WITHDRAWAL_LIMIT = 6063; // 提現次數限制

    public const int REAL_NAME_VERIFICATION_APPLY_VISIBLE = 6607; // 實名認證申請開關

    public const int REAL_NAME_ALLOW_DUPLICATE = 6608; // 真實姓名允許重複

    public const int REAL_NAME_VERIFICATION_AUTO_APPROVE = 6609; // 實名認證申請即通過

    // 代理模式設置
    public const int AGENT_MODE_SETTING = 7001;

    public const int AGENT_REWARD_DISTRIBUTION = 7002;   // 代理獎勵派發

    public const int AGENT_REWARD_AUTO_PAYOUT = 7003;   // 代理獎勵自動派發

    // 7004 已被歷史資料「登入檢查綁定模式」占用（data 檔未收錄但 DB 有 50 筆值）、7005 保留跳過，勿再取用
    // 7006／7007 號段雖在 7xxx，但掛在代理後台設置（parent 5000），不屬於代理模式設置區塊；id 已上正式有設定值，不能改號
    public const int ACTIVE_MEMBER_DEPOSIT_AMOUNT = 7006;   // 判斷活躍玩家條件(充值) 工單4121，掛在代理後台設置（parent 5000）

    public const int ACTIVE_MEMBER_VALID_BET_AMOUNT = 7007;   // 判斷活躍玩家條件(有效流水) 工單4121，掛在代理後台設置（parent 5000）

    // 份桶
    public const int SHAREHOLDER = 8000;

    public const int MEMBER_UPGRADE_AGENT_VISIBLE = 8101;  // 玩家是否可申請為代理

    public const int AGENT_SHARE_POINT_LIMIT = 8102;  // 原生代理(非玩家代理)股份最低下限警示

    public const int DISABLE_UPGRADE_TIME = 8103;  // 禁止玩家升級代理時間

    public const int VALID_MEMBER_CONDITIONS = 8104;  // 有效玩家條件限制

    // 其他設置
    public const int CUSTOMIZED_OTHER_UNLIMIT_INFO_BANNER_IMAGE_URL = 4103;   // 全民代理Info Banner圖片網址

    public const int CUSTOMIZED_OTHER_UNLIMIT_ASK_BANNER_SIZE_IMAGE_URL = 4104;   // 全民代理諮詢Banner尺寸圖片網址

    public const int CUSTOMIZED_OTHER_UNLIMIT_POP_UP_IMAGE_URL = 4105;   // 全民代理彈框圖片網址

    public const int REFERRAL_LINK_FIXED_DOMAIN_ENABLED = 4106;   // 推薦連結是否啟用固定域名

    public const int REFERRAL_LINK_FIXED_DOMAIN = 4107;   // 推薦連結固定域名

    public const int SEND_CODE_CAPTCHA_ENABLED = 4108;   // 發送驗證碼前是否需通過滑塊 CAPTCHA

    public const int CUSTOMIZED_OTHER_HAOLONG_ODDS_IMAGE_URL = 9001;   // 豪龍4D賠率圖

    public const int CUSTOMIZED_OTHER_HAOLONG_ODDS_IMAGE_CHS_URL = 9002;   // 豪龍4D賠率圖(簡中)

    public const int CUSTOMIZED_OTHER_PROMOTE_URL = 9101;   // 推廣連結位置

    public const int CUSTOMIZED_OTHER_PROMOTE_VISIBLE = 9102;   // 是否顯示推廣開關

    public const int CUSTOMIZED_OTHER_GA_CODE = 9103;   // GA代碼 customized_other_ga_code

    public const int CUSTOMIZED_OTHER_MATOMO_CODE = 9104;   // MATOMO CODE

    public const int MODIFY_BINDING_LIMIT_TIME = 9105;   // 綁定資料修改需時間限制

    public const int SHARE_CODE_SPECIAL_DOMAIN = 9106; // 分享碼特殊 domain

    public const int REGISTRATION_ALLOW_CODE_EMPTY = 9107; // 註冊允許邀請碼為空

    public const int IS_AGENT_ITSELF_REBATE = 4501; // 是否啟用代理自身回水

    public const int IS_ROLLING_CHIP_VISIBLE = 4056; // 是否顯示禮碼

    public const int AGENT_ITSELF_REBATE_PERCENT = 4502; // 代理自身回水%數

    public const int NICKNAME_PROHIBITED_WORDS = 92001; // 暱稱禁用字

    // 推廣設置分類
    public const int PROMOTION_SETTING = 66000; // 推廣設置

    /*
     * 基本資訊（工單4104）：前台頁尾的政策與說明文案。
     * 每項一組「顯示開關 + 多語系內容」，開關關閉時該項不下發給前台。
     */
    public const int BASIC_INFO_ABOUT_US_VISIBLE = 66002; // 關於我們 顯示開關

    public const int BASIC_INFO_ABOUT_US_CONTENT = 66003; // 關於我們 內容

    public const int BASIC_INFO_TERMS_VISIBLE = 66004; // 條款和條件 顯示開關

    public const int BASIC_INFO_TERMS_CONTENT = 66005; // 條款和條件 內容

    public const int BASIC_INFO_FAQ_VISIBLE = 66006; // 常見問題 顯示開關

    public const int BASIC_INFO_FAQ_CONTENT = 66007; // 常見問題 內容

    public const int BASIC_INFO_PRIVACY_VISIBLE = 66008; // 隱私政策 顯示開關

    public const int BASIC_INFO_PRIVACY_CONTENT = 66009; // 隱私政策 內容

    public const int BASIC_INFO_RESPONSIBLE_GAMING_VISIBLE = 66010; // 博彩責任 顯示開關

    public const int BASIC_INFO_RESPONSIBLE_GAMING_CONTENT = 66011; // 博彩責任 內容
}
