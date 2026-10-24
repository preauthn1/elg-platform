<?php

declare(strict_types=1);

namespace Tests;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\RoleEnum;
use App\Enums\CashFlowEnum;
use App\Facades\AES;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\AgentCustomizedSetting;
use App\Models\AgentGame;
use App\Models\AgentPersonalSetting;
use App\Models\AgentVendor;
use App\Models\CashFlow;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberCode;
use App\Models\OrderType;
use App\Models\ProcedureHash;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\StreamOutput;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    // protected int $memoryStart;

    protected array $headers = [
        'Accept-Language' => 'zh-TW',
    ];

    protected string $domain;

    /** DB 座標已驗過（同一個測試程序只驗一次） */
    private static bool $databaseHostVerified = false;

    /** .env.testing 宣告的庫名已驗過（同一個測試程序只驗一次） */
    private static bool $declaredTestingDatabaseVerified = false;

    protected function setUp(): void
    {
        // 必須早於 parent::setUp()：app 啟動過程就會連 DB，等啟動完才擋已經來不及
        self::forceTestingEnvironment();
        $this->verifyDatabaseHost();
        $this->verifyDeclaredTestingDatabase();

        parent::setUp();

        ini_set('memory_limit', '1024M'); // 設置記憶體限制

        if (!file_exists(base_path('.env.testing'))) {
            fwrite(STDERR, "Error: .env.testing file is missing. Please create one before running tests.\n");

            exit(1);
        }

        self::assertTestingDatabaseTarget();

        // Some functions in Controller need this.
        $_SERVER['REMOTE_ADDR'] = '0.0.0.0';

        $this->domain = Str::random(12) . '.test.local';

        // $this->memoryStart = memory_get_usage();
    }

    /**
     * 強制 APP_ENV=testing，讓 app 一定載入 `.env.testing`、不會退回 `.env`。
     *
     * `php artisan test --env=testing` 的父程序會把它載入的 env 檔（含 APP_ENV）putenv 給
     * phpunit 子程序；本機 `.env.testing` 若宣告 APP_ENV=local，子程序的 `$_SERVER['APP_ENV']`
     * 就是 local，Laravel 找不到 `.env.local` 便改讀 `.env`——任何只存在於 `.env` 的鍵
     * （例如本機經 dbc 代理的 DB_PORT_READ=3402）都會漏進測試、連到錯的庫。
     * phpunit.xml 的 `<env force="true">` 只改 `$_ENV`／putenv，Laravel 的 Env 先讀 `$_SERVER`，
     * 擋不住；只能在這裡三處一起蓋。CI 本來就沒有 `.env`，此舉讓本機與 CI 讀同一份 `.env.testing`。
     */
    private static function forceTestingEnvironment(): void
    {
        $_SERVER['APP_ENV'] = 'testing';
        $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');
    }

    /**
     * 防呆：`.env.testing` 宣告的庫名本身必須看起來像測試庫。
     *
     * 補的是 {@see assertTestingDatabaseTarget()} 的盲點——那道只比對「實際連線 vs 宣告」，
     * 宣告本身指向真實庫時兩者一致、照樣放行（2026-08-25 Dev banks 事故的最可能管道，
     * 當時無任何防線）。這裡直接否決「不像測試庫」的宣告，且跑在 app 啟動前。
     *
     * 只驗檔案內的宣告值，不看真實環境變數：環境變數覆蓋的情境由 assertTestingDatabaseTarget()
     * 負責，且 CI `--parallel`（paratest）會在執行期把實際庫換成 `{DB_DATABASE}_test_{token}`，
     * 只認檔案宣告才不會誤殺。
     */
    private function verifyDeclaredTestingDatabase(): void
    {
        if (self::$declaredTestingDatabaseVerified) {
            return;
        }

        self::$declaredTestingDatabaseVerified = true;

        $declared = $this->testingEnvFileValues(['DB_DATABASE'])['DB_DATABASE'] ?? '';

        if ($declared === '' || preg_match('/_(test|testing)(_\\d+)?$/i', $declared) === 1) {
            return;
        }

        fwrite(STDERR, sprintf(
            "Error: .env.testing 宣告的資料庫（%s）不像測試庫，拒絕執行以防寫壞真實庫。\n"
            . "測試會建改刪資料，庫名必須以 _test / _testing 結尾。\n",
            $declared,
        ));

        exit(1);
    }

    /**
     * 防呆：測試只能連本機／容器內的資料庫。
     *
     * 測試會建、改、刪資料，DB 座標一旦指到白標正式機就是災難。
     * 放行 hostname（容器服務名如 mysql、localhost）與 loopback IP，
     * 其餘（典型是內網 IP）直接讓整個測試程序爆掉，不留給人「先跑看看」的空間。
     */
    private function verifyDatabaseHost(): void
    {
        if (self::$databaseHostVerified) {
            return;
        }

        self::$databaseHostVerified = true;

        foreach ($this->testingDatabaseHosts() as $key => $host) {
            if ($host === '' || $this->isLocalDatabaseHost($host)) {
                continue;
            }

            // exit(string) 的離開碼是 0，CI 會誤判成功，必須明確用 exit(1)
            fwrite(STDERR, sprintf(
                "Error: 測試被擋下—— %s 是 [%s]，不是本機或容器內的資料庫。\n"
                . "測試會建改刪資料，只允許 hostname（如 mysql）或 127.x / ::1。\n"
                . "請確認有帶 --env=testing，且 .env.testing 指向測試庫。\n",
                $key,
                $host,
            ));

            exit(1);
        }
    }

    /**
     * 取 .env.testing 的 DB 座標；真實環境變數優先（對齊 Dotenv 不覆寫既有變數的行為）。
     *
     * 這道防呆跑在 app 啟動前，所以不能用 config()，只能自己讀檔。
     *
     * @return array<string, string>
     */
    private function testingDatabaseHosts(): array
    {
        $keys = ['DB_HOST_READ', 'DB_HOST_WRITE'];
        $values = $this->testingEnvFileValues($keys);

        // 真實環境變數優先（對齊 Dotenv 不覆寫既有變數的行為）
        foreach ($keys as $key) {
            $fromEnv = getenv($key);

            if (is_string($fromEnv) && $fromEnv !== '') {
                $values[$key] = $fromEnv;
            }
        }

        return array_intersect_key($values, array_flip($keys));
    }

    /**
     * 讀 `.env.testing` 檔案內的宣告值（不看真實環境變數）。
     *
     * 這道防呆跑在 app 啟動前，所以不能用 config()／base_path()，只能自己讀檔。
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function testingEnvFileValues(array $keys): array
    {
        // app 還沒啟動，base_path() 不可用
        $path = dirname(__DIR__) . '/.env.testing';
        $values = [];

        if (!file_exists($path)) {
            return $values;
        }

        $pattern = '/^\s*(' . implode('|', array_map('preg_quote', $keys)) . ')\s*=\s*(.*)$/';

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match($pattern, $line, $matches) === 1) {
                $values[$matches[1]] = trim($matches[2], " \"'");
            }
        }

        // 展開 ${DB_HOST_READ} 這類引用
        foreach ($values as $key => $value) {
            $values[$key] = (string) preg_replace_callback(
                '/\$\{(\w+)\}/',
                function (array $matches) use ($values): string {
                    $referenced = $values[$matches[1]] ?? getenv($matches[1]);

                    return is_string($referenced) ? $referenced : '';
                },
                $value,
            );
        }

        return $values;
    }

    /**
     * host 是否為本機／容器內：以英文字母開頭的 hostname，或 loopback IP
     */
    private function isLocalDatabaseHost(string $host): bool
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $host) === 1) {
            return true;
        }

        return str_starts_with($host, '127.') || $host === '::1';
    }

    /**
     * 防連錯庫第二道（2026-08-25 Dev banks 全表被清空事故的治本層）：實際連線必須與
     * `.env.testing` 的宣告一致，否則整批拒跑。與 verifyDatabaseHost()（host 層）互補——
     * 這裡擋的是 host 合法、但「連線名／資料庫名」被真實環境變數蓋掉的洩漏
     * （如 `docker exec -e DB_CONNECTION=<白標>`；phpunit.xml 固定 APP_ENV=testing，
     * 環境名擋不住連線層洩漏）。CI `--parallel`（paratest）為每個 process 建
     * `{DB_DATABASE}_test_{token}` 分庫，屬合法目標一併放行。
     *
     * ⚠️ 擋不住 .env.testing 宣告本身就指向共用庫的情況（host 層由 verifyDatabaseHost 把關）。
     */
    private static function assertTestingDatabaseTarget(): void
    {
        static $verified = false;

        if ($verified) {
            return;
        }

        $declared = \Dotenv\Dotenv::parse((string) file_get_contents(base_path('.env.testing')));

        $expectedConnection = $declared['DB_CONNECTION'] ?? null;
        $actualConnection = (string) config('database.default');
        if ($expectedConnection !== null && $actualConnection !== $expectedConnection) {
            fwrite(STDERR, sprintf(
                "Error: 測試的預設連線（%s）與 .env.testing 宣告（%s）不符，拒絕執行以防寫壞真實庫。\n",
                $actualConnection,
                $expectedConnection,
            ));

            exit(1);
        }

        $expectedDatabase = $declared['DB_DATABASE'] ?? null;
        $actualDatabase = (string) DB::connection()->getDatabaseName();
        $isParallelClone = $expectedDatabase !== null
            && preg_match('/^' . preg_quote($expectedDatabase, '/') . '_test_\d+$/', $actualDatabase) === 1;
        if ($expectedDatabase !== null && $actualDatabase !== $expectedDatabase && !$isParallelClone) {
            fwrite(STDERR, sprintf(
                "Error: 測試連上的資料庫（%s）與 .env.testing 宣告（%s）不符，拒絕執行以防寫壞真實庫。\n",
                $actualDatabase,
                $expectedDatabase,
            ));

            exit(1);
        }

        $verified = true;
    }

    /**
     * 建立測試用代理與對應前台網域。
     *
     * 預設會帶入每次呼叫皆不同的 code／username／email，避免多測試或並行時
     * 反覆命中同一筆 agents 造成 lock wait；若需固定身分可於 $data 覆寫。
     * agent_domains.domain：若 {@see $domain} 仍為預設的 localhost，會改為每次不同的子網域並寫回
     * {@see $domain}，以免 agent_domains 唯一鍵衝突；測試 setUp 已自訂網域時則沿用。
     */
    protected function createAgentAndDomain(array $data = []): Agent
    {
        $unique = bin2hex(random_bytes(8));

        $baseData = [
            'code' => substr($unique, 0, 10),
            'username' => 'a' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'password' => Hash::make('123456'),
            'status' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
        ];

        $data = array_merge($baseData, $data);

        $agent = Agent::where('username', $data['username'])->first();
        if (is_object($agent)) {
            $agent->update($data);
            $agent->refresh();

            return $agent;
        }

        $agent = Agent::factory()->create($data);
        $agent->hierarchy_path = "/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        $agentDomain = $this->domain;

        $agent->agentDomains()->create([
            'domain' => $agentDomain,
            'admin' => 1,
        ]);

        return $agent;
    }

    protected function createMemberAndToken($agent, array $data = []): Member
    {
        $member = $this->createMember($agent, $data);

        $token = [REDACTED];

        // $member->update([
        //     'token' => $token,
        // ]);

        return $member;
    }

    protected function createMember(Agent $agent, array $data = []): Member
    {
        $baseData = [
            'parent' => $agent->id,
            'status' => 1,
            'admin' => 0,
        ];

        $data = array_merge($baseData, $data);

        return Member::factory()->create($data);
    }

    protected function createMemberCode(Member $member): MemberCode
    {
        return MemberCode::create([
            'member_id' => $member->id,
            'agent_code' => Str::upper(config('custom.unlimit_prefix') . Str::random(7)),
        ]);
    }

    protected function createToken(Member $member): string
    {
        // 新增TOKEN
        $token = [REDACTED];

        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $token,
            'expire_date' => date('Y-m-d H:i:s', strtotime('+ ' . config('custom.frontend.token_expired') . 'minute')),
            'admin' => 1,
        ]);

        // $member->update([
        //     'token' => $token,
        // ]);

        // add bearer token to headers
        $this->addHeader('Authorization', 'Bearer ' . $token);

        return $token;
    }

    protected function encryptParams(array $params): array
    {
        $json_content = json_encode($params);

        $encryptParams = AES::encrypt($json_content);

        return [
            'key' => md5($encryptParams . config('encrypt.aes.md5')),
            'params' => $encryptParams,
        ];
    }

    protected function setUpLanguage(): void
    {
        $locale = 'en';
        $this->setLocale($locale);
    }

    protected function requestApi(string $uri, array $params = []): TestResponse
    {
        return $this->postJson(
            uri: $uri,
            data: $params,
            headers: $this->headers
        );
    }

    protected function addHeader($key, $value): void
    {
        $this->headers[$key] = $value;
    }

    protected function createBasicVendorGames(Agent $agent, ?int $vendorId = null, $vendorAttributes = [], $vendorInfoAttributes = []): Vendor
    {
        if (is_null($vendorId)) {
            $vendor = Vendor::factory()->create();
        } else {
            $vendor = Vendor::find($vendorId);
            if (!$vendor) {
                $vendor = Vendor::factory()->create([
                    'id' => $vendorId,
                ] + $vendorAttributes);
            }
        }

        $vendorInfo = $vendor->vendorInfos()->save(
            VendorInfo::factory()->make(['admin' => 0] + $vendorInfoAttributes)
        );

        $vendor->agentVendors()->save(new AgentVendor([
            'agent_id' => $agent->id,
            'vendor_info_id' => $vendorInfo->id,
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]));

        $vendor->vendorCustomizedItems()->save(new VendorCustomizedItem([
            'agent_group_id' => $agent->vendor_customize_group_id,
            'type_id' => 0,
            'status' => 1,
        ]));

        $game = $vendor->games()->save(new Game([
            'type_id' => 0,
            'name' => 'test',
            'game_type' => 'TEST',
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid',
            'admin' => 0,
        ]));

        $vendorInfo->vendorInfoGames()->save(new VendorInfoGame([
            'game_id' => $game->id,
            'status' => 1,
        ]));

        $game->agentGames()->save(new AgentGame([
            'agent_id' => $agent->id,
            'status' => 1,
        ]));

        return $vendor;
    }

    protected function createAgentSetting(Agent $agent, int $key, int $value): AgentCustomizedSetting
    {
        $groupId = (int) $agent->customized_setting_group_id;

        $setting = AgentCustomizedSetting::query()->updateOrCreate(
            [
                'agent_group_id' => $groupId,
                'setting_key' => $key,
            ],
            [
                'agent_group_id' => $groupId,
                'setting_key' => $key,
                'sub_category' => 1,
                'memo' => '',
                'value' => $value,
            ]
        );

        // Agent::getSettingValue() 以「群組＋設定鍵」為快取鍵、存活 30 分鐘，且測試快取走 redis
        // （跨進程持久）。DB 列會隨 DatabaseTransactions 回滾，快取不會：工廠代理全在同一群組，
        // 一支測試把多錢包開關快取成 1，之後 30 分鐘內任何進程的錢包測試都會讀到它
        // （2026-09-03 實測：單獨跑 WalletServiceTest 也撞到 is_multi_wallet 死路徑守衛）。
        // 寫入前後各清一次：寫入前清掉舊值讓本測試讀到新值，app 銷毀時清掉本測試留下的值。
        $cacheKey = CacheKeys::agentCustomizedSetting($groupId, $key);
        Cache::forget($cacheKey);
        $this->beforeApplicationDestroyed(static fn () => Cache::forget($cacheKey));

        return $setting;
    }

    /**
     * 建立測試用代理個人化設定（agent_personal_settings）。
     */
    protected function createAgentPersonalSetting(Agent $agent, int|string $key, int|string $value): void
    {
        AgentPersonalSetting::query()->create([
            'agent_id' => $agent->id,
            'setting_id' => $key,
            'sub_category' => '',
            'value' => $value,
            'created_by' => 0,
            'admin' => 0,
        ]);
    }

    protected function createDefaultCashFlow(Agent $agent, int $cashFlowId = 1, int $type = 1): array
    {
        $cashFlow = CashFlow::create([
            'id' => $cashFlowId,
            'name' => 'Bank Transfer',
            'type' => $type,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $orderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '--',
            'info' => '{"data":[{"name":"amount","type":"number","require":"true","display":"deposit_amount"}]}',
            'admin' => 0,
        ]);

        $agentCashFlowOrderType = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'status' => 1,
            'settings' => '{"PopupText":{"0":"PopupText0"}}',
        ]);

        return [$cashFlow, $agentCashFlow, $orderType, $agentCashFlowOrderType];
    }

    protected function createAgentCashFlow(Agent $agent, array $params = []): AgentCashFlow
    {
        $default = [
            'agent_group_id' => $agent->cash_flow_group_id,
            'cash_flow_id' => CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL,
            'cash_flow_info_id' => 0,
            'rate' => 1,
            'info' => [
                'min_amount' => 50,
                'max_amount' => 1000,
            ],
            'sorts' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 1,
        ];

        $data = array_merge($default, $params);

        $agentCashFlow = AgentCashFlow::create($data);

        return $agentCashFlow;
    }

    /**
     * 斷言目前連線資料庫已存在指定名稱的 stored procedure，且與 repo 內 SQL 檔 MD5 一致。
     *
     * 不在此執行 DDL：建立 routine 會觸發隱式 commit，破壞 {@see DatabaseTransactions} 內的交易。
     * 雜湊比對與 {@see \Database\Seeders\SyncProcedureSeeder} 相同：對 `database/procedures/{name}.sql`
     * 全文做 md5，並與 `procedure_hashes.file_hash` 比對。
     *
     * `$routineName` 僅允許 `[A-Za-z0-9_]`（對應專案 procedure 檔名／ROUTINE_NAME）。
     *
     * @param  non-empty-string  $routineName  `information_schema.ROUTINES.ROUTINE_NAME`
     */
    protected function ensureStoredProcedureExists(string $routineName): void
    {
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_]+$/',
            $routineName,
            'ensureStoredProcedureExists：routine 名稱僅允許字母、數字與底線。'
        );

        $syncHint = 'php artisan db:seed --class=SyncProcedureSeeder --env=testing';

        $exists = DB::selectOne(
            'SELECT ROUTINE_NAME FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?',
            [$routineName]
        );

        $this->assertNotNull(
            $exists,
            sprintf(
                '資料庫缺少 stored procedure `%s`。請先在測試庫同步 procedure（例如 `%s` 或 `php artisan app:upgrade`）。',
                $routineName,
                $syncHint
            )
        );

        $fileName = $routineName . '.sql';
        $filePath = database_path('procedures/' . $fileName);

        $this->assertFileExists(
            $filePath,
            sprintf(
                '找不到 procedure 原始檔 `%s`（預期路徑：%s）。',
                $fileName,
                $filePath
            )
        );

        $expectedHash = md5(File::get($filePath));

        $storedHash = ProcedureHash::query()
            ->where('file_name', $fileName)
            ->value('file_hash');

        $this->assertNotNull(
            $storedHash,
            sprintf(
                '`procedure_hashes` 缺少 `%s` 紀錄；資料庫內的 `%s` 可能未經 SyncProcedureSeeder 同步。請執行：%s',
                $fileName,
                $routineName,
                $syncHint
            )
        );

        $this->assertSame(
            $expectedHash,
            $storedHash,
            sprintf(
                'stored procedure `%s` 與 repo 不一致（檔案 md5=%s，procedure_hashes=%s）。請執行：%s',
                $routineName,
                $expectedHash,
                $storedHash,
                $syncHint
            )
        );
    }

    /**
     * 將表格輸出至 STDERR（PHPUnit 跑測試時可直接檢視，不污染 assert 輸出）。
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    protected function table(array $headers, array $rows): void
    {
        $output = new StreamOutput(fopen('php://stderr', 'w'));
        (new Table($output))
            ->setHeaders($headers)
            ->setRows($rows)
            ->render();
    }
}
