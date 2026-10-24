<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 遊戲商端查無該遊戲帳號（本地已有帳號紀錄但三方沒建立）
 */
class AccountNotExistsException extends ApiException {}
