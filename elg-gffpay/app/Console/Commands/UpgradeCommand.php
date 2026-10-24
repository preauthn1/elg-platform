<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpgradeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:upgrade {--silent : 不輸出任何資訊}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '升級腳本';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('升級完成');
    }
}
