<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    // 掛在 Olympus 路徑下（target/tickets#4081）：與後台同一入口語意，
    // gate 亦要求 Olympus SSO 登入（見 HorizonServiceProvider）
    'path' => env('HORIZON_PATH', 'olympus/horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    // 與 queue 連線同一顆 DB（預設 3），避免和 cache（DB 0）共用而被後台「清除快取」誤清。
    'use' => 'queue',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Horizon Allowed IPs
    |--------------------------------------------------------------------------
    |
    | Horizon 儀表板存取 IP 白名單（逗號分隔），供 HorizonServiceProvider 的
    | viewHorizon gate 判斷來源 IP。空字串（預設）＝拒絕所有來源。實際允許
    | 的 IP 由部署端 .env（HORIZON_ALLOWED_IPS）注入，勿寫死於程式碼。
    |
    */

    'allowed_ips' => env('HORIZON_ALLOWED_IPS', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    // Laravel 11 起 master supervisor 光是啟動自身就約需 67MB，沿用預設 64 會在
    // 開機當下即超標自殺、被 restart policy 拉起後無限重啟（實測 8 分鐘重啟 8 次）。
    // 對齊下方 worker 的 memory 128，留出啟動基線之上的餘裕。
    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        // 長時 job 專用。本線每支 job 都自己宣告 $timeout（SyncVendorGameList / FetchVendorBetRecord /
        // SummarizeBetRecordHour=600、ReplenishVendorAccountPool=240、RunAdminOperationJob=3600），
        // 單一 job 的執行上限一律由該屬性決定，不吃下面的 timeout。
        //
        // 🔴 下面的 timeout 另有一個容易漏看的作用：**balance=auto 縮編 worker 時的強殺 grace**。
        // AutoScaler 依 time-to-clear 重新分配 worker，佇列待處理數歸零（job 被 reserve 後就不算待處理）
        // 即可能縮編：ProcessPool::scaleDown() 先送 SIGTERM，worker 因為正在跑 job 不會馬上退，
        // 接著 stopTerminatingProcessesThatAreHanging() 在 terminatedAt + timeout 到點時 SIGKILL。
        // 故 timeout 必須大於最長的 job $timeout（3600），否則長時 job 會在執行中被靜默砍掉
        // ——無 PHP fatal、無 Horizon FAIL、pod 不重啟，只有 job 卡在 reserved 直到 retry_after 誤判
        // MaxAttemptsExceeded（2026-09-17 WINTOTO 轉回遊戲商餘額連三次踩中，存活 450~749 秒不等）。
        // 官方文件亦明載：「Always ensure the Horizon timeout is greater than any job-level timeout,
        // otherwise jobs may be terminated mid-execution.」（example.invalid/docs/12.x/horizon#job-timeout）
        //
        // 取 3630：> 最長 job timeout 3600，且 < 本線 retry_after 3660（見 config/queue.php，
        // 官方要求 timeout 須比 retry_after 短，否則 job 會被處理兩次）。
        'supervisor-long-running' => [
            'connection' => 'redis-long-running',
            'queue' => ['long-running'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 3630,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                // 投注即時更新 last_bet_at 不節流，尖峰約 14 萬筆/hr，需較高 worker 上限
                'maxProcesses' => 20,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],

            // 遊戲清單同步整點一次派發 ~50 商，2 個 worker 足以在下一輪前消化；
            // 轉回遊戲商餘額改為不同遊戲商可並行（AdminOperationService::dispatch 的範圍鍵）後，
            // 一輪抽分會獨佔一個 worker 近一小時，2 個就會把整條佇列塞住 ⇒ 提到 4。
            // 抽分是 I/O-bound（等三方 HTTP 與每秒配額的 sleep），實測單一 worker 約 0.1 core、
            // 峰值 89MB，對 pod 的 1 core / 3GiB 上限仍有餘裕（2026-09-23 於 WINTOTO 量測）。
            'supervisor-long-running' => [
                'maxProcesses' => 4,
            ],
        ],

        'dev' => [
            'supervisor-1' => [
                // dev 跑 2 replicas，2×2=4 個 worker processes 已足夠 dev 流量
                // （三個 dev 環境共用同一條佇列，由 dev 這組 Horizon 統一消費）
                'maxProcesses' => 2,
            ],

            'supervisor-long-running' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],

            'supervisor-long-running' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
