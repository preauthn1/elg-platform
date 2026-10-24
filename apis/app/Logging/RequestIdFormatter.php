<?php

declare(strict_types=1);

namespace App\Logging;

use App\Enums\General\ContextKeyEnum;
use Illuminate\Support\Facades\Context;
use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class RequestIdFormatter extends LineFormatter
{
    public function format(LogRecord $record): string
    {
        $member = Context::getHidden(ContextKeyEnum::MEMBER->value);
        $memberId = $member ? (string) $member->id : null;

        if ($memberId) {
            $message = sprintf('%s (%s)', $record->message, $memberId);
            $record = $record->with(message: $message);
        }

        // $requestId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);
        //
        // if ($requestId) {
        //     $message = sprintf('[%s] %s', $requestId, $record->message);
        //     $record = $record->with(message: $message);
        // }

        return parent::format($record);
    }
}
