<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Db\ErrorCodeEnum;
use Exception;
use Throwable;

class TonException extends Exception
{
    protected ErrorCodeEnum $tonErrorCode;

    public function __construct(ErrorCodeEnum $code, ?Throwable $previous = null)
    {
        $this->tonErrorCode = $code;
        parent::__construct($code->name, $code->value, $previous);
    }

    public function getTonErrorCode(): ErrorCodeEnum
    {
        return $this->tonErrorCode;
    }
}
