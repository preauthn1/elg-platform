<?php

declare(strict_types=1);

namespace App\Enums\Member;

/**
 * 站內信發送類型 工單4152
 *
 * 依收件人是否展開存放分成兩類：MEMBER / MEMBER_GROUP 於存檔時展開寫入
 * member_mail_recipients；ORGANIZATION / AGENT_HIERARCHY 涵蓋數十萬會員，
 * 不展開，查詢時動態比對並以註冊時間排除發信後才加入的會員。
 */
enum MemberMailSendTypeEnum: int
{
    /** 指定會員（後台輸入帳號多選） */
    case MEMBER = 1;

    /** 會員群組（後台下拉多選） */
    case MEMBER_GROUP = 2;

    /** 全平臺：同一組織下的所有會員 */
    case ORGANIZATION = 3;

    /** 代理及子代理：指定代理的整條 hierarchy */
    case AGENT_HIERARCHY = 4;

    /** 收件人是否於存檔時展開存放 */
    public function expandsRecipients(): bool
    {
        return match ($this) {
            self::MEMBER, self::MEMBER_GROUP => true,
            self::ORGANIZATION, self::AGENT_HIERARCHY => false,
        };
    }
}
