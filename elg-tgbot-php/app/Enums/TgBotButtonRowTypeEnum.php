<?php

namespace App\Enums;

enum TgBotButtonRowTypeEnum: string
{
    /** 按按鈕後會幫用戶輸入按鈕的文字 */
    case KEYBOARD = 'keyboard';
    /** 按按鈕後tg會直接回傳事件, 不會幫用戶輸入文字 */
    case INLINE_KEYBOARD = 'inline_keyboard';
}
