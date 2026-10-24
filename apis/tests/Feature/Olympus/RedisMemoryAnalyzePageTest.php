<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Admin\Pages\Operations\RedisMemoryAnalyze;
use App\Models\AdminOperationLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 後台「Redis 記憶體分析」操作頁測試。
 *
 * php artisan test tests/Feature/Olympus/RedisMemoryAnalyzePageTest.php --compact
 */
class RedisMemoryAnalyzePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->actingAs($this->user(UserRoleEnum::ADMIN), 'admin');
    }

    #[TestDox('送出後以 --connection／--top／--depth 派發指令')]
    public function test_execute_dispatches_with_options(): void
    {
        Livewire::test(RedisMemoryAnalyze::class)
            ->fillForm(['connection' => 'default', 'top' => 30, 'depth' => 2])
            ->call('execute')
            ->assertHasNoFormErrors();

        $log = AdminOperationLog::latest('id')->first();

        $this->assertSame('redis:analyze-memory', $log->operation);
        // 用 assertEquals：params 經 JSON 存回後鍵的順序不保證，順序不是這裡要驗的東西
        $this->assertEquals([
            '--connection' => 'default',
            '--top' => '30',
            '--depth' => '2',
        ], $log->params);
    }

    #[TestDox('下拉以 database 為單位去重：同一個 DB 的多個連線只出現一個選項')]
    public function test_connection_options_are_deduplicated_by_database(): void
    {
        $options = RedisMemoryAnalyze::connectionOptions();

        // client／options 是設定而非連線，不該出現在下拉裡
        $this->assertArrayNotHasKey('client', $options);
        $this->assertArrayNotHasKey('options', $options);

        // default／cache／json 都是 DB 0：只留一個選項，label 標出共用的連線名
        $this->assertArrayHasKey('default', $options);
        $this->assertArrayNotHasKey('cache', $options);
        $this->assertArrayNotHasKey('json', $options);
        $this->assertStringContainsString('cache', (string) $options['default']);
        $this->assertStringContainsString('json', (string) $options['default']);

        // 選項數 ＝ 實際的 DB 數，不是連線數
        $databases = collect(config('database.redis'))
            ->except(['client', 'options'])
            ->filter(fn (mixed $config): bool => is_array($config) && isset($config['database']))
            ->pluck('database')
            ->unique();

        $this->assertCount($databases->count(), $options);
    }

    #[TestDox('運維角色進得來（排查 Redis 正是運維的工作）')]
    public function test_ops_role_can_access(): void
    {
        $this->assertTrue(UserRoleEnum::OPS->canAccessPage(RedisMemoryAnalyze::class));
    }

    private function user(UserRoleEnum $role): User
    {
        return User::create([
            'parent' => 0,
            'username' => 'ops_redis_' . uniqid(),
            'email' => 'user@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => $role->value,
            'admin' => 0,
        ]);
    }
}
