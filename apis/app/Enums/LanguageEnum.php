<?php

declare(strict_types=1);

namespace App\Enums;

enum LanguageEnum: int
{
    /** 英文 */
    case EN = 1;

    /** 繁體中文 */
    case ZH_TW = 2;

    /** 簡體中文 */
    case ZH_CN = 3;

    /** 馬來語（馬來西亞） */
    case MS_MY = 4;

    /** 印尼語 */
    case ID_ID = 5;

    /** 越南語 */
    case VI_VN = 6;

    /** 泰語 */
    case TH_TH = 7;

    /** 坦米爾語（Tamil） */
    case TA = 8;

    /** 菲律賓語（Pilipino） */
    case TL_PH = 9;

    /** 緬甸語（Malay） */
    case MY_MM = 10;

    /** 印地語（Hindi） */
    case HI_IN = 11;

    /** 托克皮辛語（Tok Pisin） */
    case TPI = 12;

    /** 希里莫圖語（Hiri Motu） */
    case HO = 13;

    /** 俄語（Russian） */
    case RU = 14;

    /** 孟加拉語（Bengali） */
    case BN = 15;

    /** 韓語（Korean） */
    case KO = 16;

    /** 法語（French） */
    case FR = 17;
}
