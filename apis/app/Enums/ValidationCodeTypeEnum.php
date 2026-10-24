<?php

declare(strict_types=1);

namespace App\Enums;

enum ValidationCodeTypeEnum: int
{
    case BINDING_DATA = 1; // 綁定資料 (手機或信箱)
    case FORGET_PASSWORD = 2; // 忘記密碼
    case RECOVER_PASSWORD = 3; // 找回密碼, 輸入密碼錯誤多次後會跳轉到這流程
    case WITHDRAWAL = 4; // 洗分驗證 @todo 疑似棄用
    case PAYMENT_PASSWORD = 5; // 支付密碼
    case FORGET_PAYMENT_PASSWORD = 6; // 忘記支付密碼
    case UNKNOW = 7; // @deprecated 不明
    case REGISTER = 8; // 一頁式註冊
    case REBINDING_DATA = 9; // 重新綁定資料 (修改手機或信箱)
    case LOGIN_PHONE_EMAIL = 10; // 登入手機/email驗證
    case VERIFY_CODE = 11; // 單純驗證信箱或手機的驗證碼對不對，不做其他事情
    case REGISTER_V2 = 12; // 3969 v2 註冊:非會員(尚未建帳)發送手機/email 驗證碼(含 email 分支,不強制 mobile)
}
