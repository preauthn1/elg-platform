<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 三方回調以 session_token 指認帳號，但查無對應的遊戲商帳號（token 過期或從未發出）。
 *
 * 繼承 VendorAccountException：不區分「帳號不存在」與「token 失效」的 controller，既有的
 * catch (VendorAccountException) 分支不必改；要分開回不同錯誤碼的 controller 先 catch 本類。
 */
class SessionTokenNotFoundException extends VendorAccountException {}
