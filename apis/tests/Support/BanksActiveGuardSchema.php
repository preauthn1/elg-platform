<?php

declare(strict_types=1);

namespace Tests\Support;

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * 在「任何測試交易之外」卸除／復原 banks 的 active 唯一約束（uk_banks_active）。
 *
 * 為何需要獨立 app：約束操作是 DDL，MySQL DDL 隱式 commit 會終結 DatabaseTransactions
 * 的包裹交易，因此 DDL 只能落在類級 hook（setUpBeforeClass / tearDownAfterClass）；
 * 而類級 hook 是 static、當下沒有測試 app 可用——這裡自行 boot 一個丟棄式 app 取得
 * DB 連線。因為這條連線跑在 Tests\TestCase 四道連錯庫防線（全在 instance setUp()）
 * 之前，本類在執行任何 DDL 前自帶等價防線：
 *
 * 1. paratest 分庫對齊——CI `--parallel` 的分庫切換是執行期 config 覆寫、綁在測試 app
 *    上，新 boot 的 app 拿到的是 base 庫；這裡依 TEST_TOKEN 自行切到 `{db}_test_{token}`，
 *    確保 DDL 與測試打同一個庫。
 * 2. 目標庫斷言——與 TestCase::assertTestingDatabaseTarget() 同判準（連線名與庫名須符
 *    .env.testing 宣告，庫名放行 `_test_\d+` 並行克隆），擋 `docker exec -e
 *    DB_CONNECTION=<白標>` 之類把 DDL 導向真實庫的注入；不符即 throw、DDL 不執行。
 *
 * 復原走 bank:guard-active-banks 命令：建立 DDL 的單一來源在命令端（migration 與命令
 * 同構），避免出現第三份結構定義 drift；卸除的兩句 ALTER 與 GuardActiveBanksCommandTest
 * tearDown 的既有寫法一致。
 */
final class BanksActiveGuardSchema
{
    /**
     * 卸除約束（column／index 各自判存在、各自卸＝半套狀態也能收乾淨）。
     * 回傳是否實際卸除了任一部分，供呼叫端決定結束時要不要復原。
     */
    public static function drop(): bool
    {
        return self::withApp(function (Application $app): bool {
            $connection = $app['db']->connection();
            $dropped = false;

            if (self::indexExists($connection)) {
                $connection->statement('ALTER TABLE banks DROP INDEX uk_banks_active');
                $dropped = true;
            }

            if ($connection->getSchemaBuilder()->hasColumn('banks', 'active_uk')) {
                $connection->statement('ALTER TABLE banks DROP COLUMN active_uk');
                $dropped = true;
            }

            return $dropped;
        });
    }

    /**
     * 復原約束。前提：測試資料已清（交易回滾或精準 tearDown）；若殘留 active 重複，
     * 命令的安全閘會拒建，此時直接 fail loud 讓污染現形。命令 exit 0 後再驗
     * column＋index 都在（exit code 在歷史上曾於半套狀態說謊，故不單獨採信）。
     */
    public static function restore(): void
    {
        self::withApp(function (Application $app): void {
            $exitCode = $app->make(Kernel::class)->call('bank:guard-active-banks');

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'bank:guard-active-banks 復原失敗（exit ' . $exitCode . '）：測試庫可能殘留 active 重複列'
                );
            }

            $connection = $app['db']->connection();

            if (!$connection->getSchemaBuilder()->hasColumn('banks', 'active_uk') || !self::indexExists($connection)) {
                throw new RuntimeException(
                    'bank:guard-active-banks 回報成功但約束不完整（active_uk 欄位與 uk_banks_active 索引缺一不可），請人工檢查測試庫 schema'
                );
            }
        });
    }

    /**
     * boot 丟棄式 app 執行 $fn；進場先做 paratest 分庫對齊與目標庫斷言（見 class docblock），
     * 離場依序斷開 DB 連線、flush 容器、還原 facade root，並彈掉 bootstrap
     * （HandleExceptions）註冊的 error / exception handler——否則 PHPUnit 會把後續每個
     * 測試判為 risky（handler 堆疊與測試開始時不一致）。register_shutdown_function 無法
     * 反註冊，屬已知殘留（handler 在無 fatal 時為 no-op）。
     *
     * @template T
     *
     * @param  callable(Application): T  $fn
     * @return T
     */
    private static function withApp(callable $fn): mixed
    {
        /** @var Application $app */
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        try {
            self::alignParallelTestDatabase($app);
            self::assertTestingDatabaseTarget($app);

            return $fn($app);
        } finally {
            $app['db']->disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            $app->flush();
            restore_error_handler();
            restore_exception_handler();
        }
    }

    /**
     * CI `--parallel`（paratest）為每個 process 建 `{db}_test_{token}` 分庫並於測試 app
     * 內以執行期 config 切換；丟棄式 app 不經過那條路，這裡自行對齊，確保 DDL 打在
     * 該 process 實際使用的分庫上。
     */
    private static function alignParallelTestDatabase(Application $app): void
    {
        $token = [REDACTED]'TEST_TOKEN'] ?? getenv('TEST_TOKEN');

        if ($token === false || $token === null || $token === '') {
            return;
        }

        $default = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$default}.database");
        $suffix = '_test_' . $token;

        if (str_ends_with($database, $suffix)) {
            return;
        }

        $app['config']->set("database.connections.{$default}.database", $database . $suffix);
        $app['db']->purge();
    }

    /**
     * 與 TestCase::assertTestingDatabaseTarget() 同判準的目標庫斷言（本類的 DDL 跑在該
     * 防線之前，必須自帶）：連線名與庫名須符 .env.testing 宣告，庫名放行 `_test_\d+`
     * 並行克隆；不符即 throw——擋下 `docker exec -e DB_CONNECTION=<白標>` 注入時對
     * 真實庫下 DDL 的災難路徑。
     */
    private static function assertTestingDatabaseTarget(Application $app): void
    {
        $declared = Dotenv::parse((string) file_get_contents($app->basePath('.env.testing')));

        $expectedConnection = $declared['DB_CONNECTION'] ?? null;
        $actualConnection = (string) $app['config']->get('database.default');

        if ($expectedConnection !== null && $actualConnection !== $expectedConnection) {
            throw new RuntimeException(sprintf(
                'BanksActiveGuardSchema：預設連線（%s）與 .env.testing 宣告（%s）不符，拒絕對其執行 DDL。',
                $actualConnection,
                $expectedConnection,
            ));
        }

        $expectedDatabase = $declared['DB_DATABASE'] ?? null;
        $actualDatabase = (string) $app['db']->connection()->getDatabaseName();
        $isParallelClone = $expectedDatabase !== null
            && preg_match('/^' . preg_quote($expectedDatabase, '/') . '_test_\d+$/', $actualDatabase) === 1;

        if ($expectedDatabase !== null && $actualDatabase !== $expectedDatabase && !$isParallelClone) {
            throw new RuntimeException(sprintf(
                'BanksActiveGuardSchema：連上的資料庫（%s）與 .env.testing 宣告（%s）不符，拒絕對其執行 DDL。',
                $actualDatabase,
                $expectedDatabase,
            ));
        }
    }

    /** 檢查索引是否存在（表名為常量、無綁定輸入；與 migration 的判準一致） */
    private static function indexExists(Connection $connection): bool
    {
        return count($connection->select(
            'SHOW INDEX FROM `banks` WHERE Key_name = ?',
            ['uk_banks_active'],
        )) > 0;
    }
}
