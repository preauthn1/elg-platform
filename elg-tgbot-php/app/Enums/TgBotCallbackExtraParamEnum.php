<?php

namespace App\Enums;

enum TgBotCallbackExtraParamEnum: string
{
    case DEPOSIT_ORDER_TYPE_ID = 'order_type_id';
    case GAME_LIST_CATEGORY_ID = 'category_id';
    case HISTORY_DAYS_FILTER = 'days';
    case HISTORY_PAGINATION = 'page';
    case LANGUAGE_SELECTED = 'language';
}
