<?php

namespace App\Loggers;

use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;
use Monolog\LogRecord;

/**
 * Class JsonFormatter
 *
 * @author Miguel Borges <user@example.invalid>
 */
class JsonFormatter extends BaseJsonFormatter
{
    public function format(LogRecord $record): string
    {
        $this->setMaxNormalizeDepth(30);
        $app_name = config('app.name');
        $entity = [
            'time' => $record['datetime']->format('Y-m-d\TH:i:s.u000\Z'),
            'application' => $app_name,
            'host' => request()->server('SERVER_ADDR'),
            'remote-addrress' => request()->server('REMOTE_ADDR'),
            'severity' => $record['level_name'],
            'message' => '[' . $app_name . '] ' . $record['message'],
        ];
        if (!empty($record['extra'])) {
            $entity['payload']['extra'] = $record['extra'];
        }

        if (!empty($record['context'])) {
            $entity['payload']['context'] = $record['context'];
        }

        $json = $this->toJson($this->normalize($entity), true) . PHP_EOL;

        return $json;
    }
}
