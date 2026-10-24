<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988;

use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use Exception;
use Throwable;

class Rcb988Exception extends Exception
{
    public readonly ErrorCodeEnum $errorCode;

    public function __construct(ErrorCodeEnum $errorCode, string $message = '', ?Throwable $previous = null)
    {
        if (empty($message)) {
            $message = $errorCode->name;
        }
        $this->errorCode = $errorCode;
        parent::__construct($message, 0, $previous);
    }
}
