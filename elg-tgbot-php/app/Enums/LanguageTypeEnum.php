<?php

namespace App\Enums;

enum LanguageTypeEnum: string
{
    case EN_US = 'en-us';
    case ZH_TW = 'zh-tw';
    case ZH_CN = 'zh-cn';
    case MS_MY = 'ms-my';
    case MM_MY = 'mm-my';
    case RU = 'ru';

    public static function getLanguageButtonList(): array
    {
        return [
            self::EN_US->value => 'English',
            self::ZH_TW->value => '繁體中文',
            self::ZH_CN->value => '简体中文',
            self::MS_MY->value => 'Bahasa Melayu',
            self::MM_MY->value => 'မြန်မာ',
            self::RU->value => 'Русский',
        ];
    }
}
