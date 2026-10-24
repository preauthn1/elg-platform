<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Concerns;

use App\Jobs\Concerns\SafelyDispatchable;
use App\Jobs\SendTelegramNotification;
use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * SafelyDispatchable 的兩種派發時機
 *
 *  - dispatchSafely()：交易 commit 後才派發，rollBack 時丟掉
 *  - dispatchSafelyAfterTransaction()：交易結束後派發，commit 或 rollBack 都派發，而且只派發一次；
 *    Job 參數由 closure 產生，closure 收得到「這次是不是 rollBack」
 *
 * 以使用本 trait 的 SendTelegramNotification 驗證，Bus::fake 攔下實際派發。
 *
 * Bus::fake 在 Bus 層就攔下 Job、不經過 queue，派發測試看不出「queue 要求 after commit」會讓
 * rollBack 後的推送被丟掉（trait docblock 的硬規則）。最後兩個測試直接檢查 queue 設定與 Job 宣告擋住它。
 *
 * php artisan test tests/Unit/Jobs/Concerns/SafelyDispatchableTest.php
 */
class SafelyDispatchableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendTelegramNotification::class]);
    }

    public function test_dispatch_safely_drops_job_when_transaction_rolls_back(): void
    {
        DB::beginTransaction();
        SendTelegramNotification::dispatchSafely('message', 'chat');
        DB::rollBack();

        Bus::assertNotDispatched(SendTelegramNotification::class);
    }

    public function test_after_transaction_dispatches_immediately_without_transaction(): void
    {
        SendTelegramNotification::dispatchSafelyAfterTransaction(fn (): array => ['message', 'chat']);

        Bus::assertDispatchedTimes(SendTelegramNotification::class, 1);
        Bus::assertDispatched(
            SendTelegramNotification::class,
            fn (SendTelegramNotification $job): bool => $job->message === 'message' && $job->chatId === 'chat',
        );
    }

    public function test_after_transaction_dispatches_once_after_rollback(): void
    {
        DB::beginTransaction();
        SendTelegramNotification::dispatchSafelyAfterTransaction(fn (): array => ['message', 'chat']);

        // 交易結束前不派發
        Bus::assertNotDispatched(SendTelegramNotification::class);

        DB::rollBack();

        Bus::assertDispatchedTimes(SendTelegramNotification::class, 1);
    }

    public function test_after_transaction_dispatches_once_after_commit(): void
    {
        DB::beginTransaction();
        SendTelegramNotification::dispatchSafelyAfterTransaction(fn (): array => ['message', 'chat']);

        Bus::assertNotDispatched(SendTelegramNotification::class);

        DB::commit();

        Bus::assertDispatchedTimes(SendTelegramNotification::class, 1);
    }

    public function test_after_transaction_tells_the_closure_it_rolled_back(): void
    {
        $seen = [];

        DB::beginTransaction();
        SendTelegramNotification::dispatchSafelyAfterTransaction(function (bool $rolledBack) use (&$seen): array {
            $seen[] = $rolledBack;

            return [$rolledBack ? 'rolled-back' : 'committed', 'chat'];
        });
        DB::rollBack();

        $this->assertSame([true], $seen);
        Bus::assertDispatched(
            SendTelegramNotification::class,
            fn (SendTelegramNotification $job): bool => $job->message === 'rolled-back',
        );
    }

    public function test_after_transaction_dispatches_once_when_inner_rolls_back_and_outer_commits(): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        SendTelegramNotification::dispatchSafelyAfterTransaction(fn (): array => ['message', 'chat']);
        DB::rollBack();
        DB::commit();

        Bus::assertDispatchedTimes(SendTelegramNotification::class, 1);
    }

    public function test_after_transaction_dispatches_once_when_inner_commits_and_outer_rolls_back(): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        SendTelegramNotification::dispatchSafelyAfterTransaction(fn (): array => ['message', 'chat']);
        DB::commit();

        // 內層 commit 不算交易結束，要等最外層定案
        Bus::assertNotDispatched(SendTelegramNotification::class);

        DB::rollBack();

        Bus::assertDispatchedTimes(SendTelegramNotification::class, 1);
    }

    public function test_queue_connections_do_not_dispatch_after_commit(): void
    {
        $violations = collect(config('queue.connections'))
            ->filter(fn (array $connection): bool => (bool) ($connection['after_commit'] ?? false))
            ->keys()
            ->all();

        $this->assertSame([], $violations, 'queue 連線設了 after_commit：' . implode(', ', $violations));
    }

    /**
     * Job 端要求 after commit 的寫法：實作 ShouldQueueAfterCommit，或在程式碼裡呼叫／設定 afterCommit
     * （例如建構子裡的 $this->afterCommit()）。在 class 裡把 $afterCommit 宣告成 true 行不通：
     * Queueable 已宣告這個屬性，PHP 會直接 fatal，不必另外檢查。
     */
    public function test_jobs_using_trait_do_not_request_after_commit(): void
    {
        $classes = $this->classesUsingSafelyDispatchable();

        // 至少要掃到 SendTelegramNotification，避免掃描失效時測試空轉通過
        $this->assertArrayHasKey(SendTelegramNotification::class, $classes);

        $violations = [];

        foreach ($classes as $class => $path) {
            if (is_a($class, ShouldQueueAfterCommit::class, true)) {
                $violations[] = $class . ' 實作了 ShouldQueueAfterCommit';
            }

            if ($this->touchesAfterCommit($path)) {
                $violations[] = $class . ' 在程式碼裡呼叫或設定了 afterCommit';
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * 程式碼（不含註解）裡有沒有 ->afterCommit
     */
    private function touchesAfterCommit(string $path): bool
    {
        $tokens = array_values(array_filter(
            PhpToken:[REDACTED],
            fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));

        foreach ($tokens as $index => $token) {
            if ($token->is(T_STRING) && $token->text === 'afterCommit'
                && ($tokens[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 掃 app/ 找出使用本 trait 的類別，之後新增的 Job 會自動納入檢查
     *
     * @return array<class-string, string> 類別名稱 => 檔案路徑
     */
    private function classesUsingSafelyDispatchable(): array
    {
        $classes = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || !str_contains(file_get_contents($file->getPathname()), 'SafelyDispatchable')) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen(app_path() . DIRECTORY_SEPARATOR), -strlen('.php'));
            $class = 'App\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (class_exists($class) && in_array(SafelyDispatchable::class, class_uses_recursive($class), true)) {
                $classes[$class] = $file->getPathname();
            }
        }

        ksort($classes);

        return $classes;
    }
}
