<?php

namespace App\Helpers;

use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;

/**
 * Class JsonFormatter
 *
 * @package App\Components\Log\Formatter
 * @author Miguel Borges <user@example.invalid>
 */
class JsonFormatter extends BaseJsonFormatter
{
    /**
     * {@inheritdoc}
     */
    public function format(array $record)
    {
        $app_name = config('app.name');
        $entity = [
            'time' => $record['datetime']->format('Y-m-d\TH:i:s.u000\Z'),
            'application' => $app_name,
            'host' => request()->server('SERVER_ADDR'),
            'remote-addrress' => request()->server('REMOTE_ADDR'),
            'severity' => $record['level_name'],
            'message' => '[' . $app_name . '] ' . $record['message']
        ];
        if (!empty($record['extra'])) {
            $entity['payload']['extra'] = $record['extra'];
        }

        if (!empty($record['context'])) {
            $entity['payload']['context'] = $record['context'];
        }

        $json = $this->toJson($this->normalize($entity), true) . ($this->appendNewline ? "\n" : '');

        return $json;
    }

}