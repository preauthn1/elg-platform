<?php

namespace App\Enums\General;

enum CacheTtl: int
{
    /**
     * 預設
     */
    case DEFAULT = 300;

    /**
     * 1分鐘
     */
    case ONE_MINUTE = 60;

    /**
     * 瞬間
     */
    case JIFFY = 180;

    /**
     * 片刻
     */
    case MOMENT = 900;

    /**
     * 半小時
     */
    case HALF_HOUR = 1800;

    /**
     * 一小時
     */
    case ONE_HOUR = 3600;

    /**
     * 半天
     */
    case HALF_DAY = 43200;

    /**
     * 一天
     */
    case ONE_DAY = 86400;
}
