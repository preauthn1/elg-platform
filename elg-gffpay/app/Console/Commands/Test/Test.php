<?php

declare(strict_types=1);

namespace App\Console\Commands\Test;

use Exception;
use Illuminate\Console\Command;

class Test extends Command
{
    /**
     * php artisan test:test
     *
     * @var string
     */
    protected $signature = 'test:test';

    protected $description = '測試';

    public function handle(): void
    {
        $this->throwException();
    }

    private function throwException(): void
    {
        throw new Exception('測試Exception');
    }
}
