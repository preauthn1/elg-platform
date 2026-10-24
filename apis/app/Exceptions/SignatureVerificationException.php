<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * 三方回調的簽名、token 或時間戳驗證失敗。
 */
class SignatureVerificationException extends Exception {}
