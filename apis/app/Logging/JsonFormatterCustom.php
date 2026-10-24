<?php

declare(strict_types=1);

namespace App\Logging;

use App\Enums\General\ContextKeyEnum;
use Illuminate\Support\Facades\Context;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

class JsonFormatterCustom extends JsonFormatter
{
    /**
     * {@inheritdoc}
     */
    public function format(LogRecord $record): string
    {
        $siteName = config('custom.site_name');
        $appName = config('app.name');

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

        $entity = [
            'time' => $record['datetime']->format('Y-m-d\TH:i:s.u000\Z'),
            'application' => $appName,
            'host' => request()->server('SERVER_ADDR'),
            'remote-address' => request()->server('REMOTE_ADDR'),
            'severity' => $record['level_name'],
            'message' => '[' . $siteName . '-' . $appName . '] ' . $record['message'],
        ];

        if (!empty($record['extra'])) {
            $entity['payload']['extra'] = $record['extra'];
        }

        if (!empty($record['context'])) {
            $entity['payload']['context'] = $record['context'];
        }

        return $this->toJson($this->normalize($entity), true) . ($this->appendNewline ? "\n" : '');
    }
}
