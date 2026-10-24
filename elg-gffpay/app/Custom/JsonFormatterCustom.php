<?php

namespace App\Custom;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

class JsonFormatterCustom extends JsonFormatter
{
    /**
     * {@inheritdoc}
     */
    public function format(LogRecord $record): string
    {
        $app_name = config('app.name');

        $entity = [
            'time' => $record['datetime']->format('Y-m-d\TH:i:s.u000\Z'),
            'application' => $app_name,
            'host' => request()->server('SERVER_ADDR'),
            'remote-address' => request()->server('REMOTE_ADDR'),
            'severity' => $record['level_name'],
            'message' => '[' . $app_name . '] ' . $record['message'],
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
