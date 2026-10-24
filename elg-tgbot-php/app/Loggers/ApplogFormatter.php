<?php

namespace App\Loggers;

use Illuminate\Log\Logger;

class ApplogFormatter
{
    /**
     * Customize the given logger instance.
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (env('APP_ENV') != 'local') {
                $handler->setFormatter(new JsonFormatter);
            }
        }
    }
}
