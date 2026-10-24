<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SafelyDispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 測試用的 job，測試 queue job 是否能正常執行，並測試 SafelyDispatchable 是否能正常捕捉異常。
 */
class TestJob implements ShouldQueue
{
    use Queueable;
    use SafelyDispatchable;

    public function __construct(
        public string $message,
    ) {}

    public function handle(): void
    {
        Log::info('TestJob handling', [
            'message' => $this->message,
        ]);
    }
}
