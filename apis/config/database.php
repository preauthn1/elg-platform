<?php

declare(strict_types=1);

$mysqlOptions = extension_loaded('pdo_mysql') ? array_filter([
    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    /**
     * 連線建立時即套上 MySQL 端的語句逾時，避免單一慢查詢長時間佔住連線與 DB CPU。
     * 走 INIT_COMMAND 而非在應用層下 SET，是為了讓 read/write 兩條連線與每次重連都自動生效。
     *
     * - 單位是**毫秒**，預設 120000（120 秒），可用 DB_MAX_EXECUTION_TIME 覆蓋；0 代表不限制，
     *   跑長時間報表／對帳的 worker 若會超過 120 秒，該環境設 0 或加大即可。
     * - 只作用於 read-only SELECT；INSERT/UPDATE/DELETE 與 stored program 內的 SELECT 不受此限。
     *
     * @see https://example.invalid/doc/refman/8.4/en/server-system-variables.html#sysvar_max_execution_time
     */
    PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
        'SET SESSION max_execution_time = %d',
        (int) env('DB_MAX_EXECUTION_TIME', 120000),
    ),
]) : [];

/**
 * 連線經 dbc 代理（本機開發用；SRE 的 dbc 以個人 IAM 身分代理 Cloud SQL）時的兩個差異：
 *
 * 1. dbc 拒絕伺服器端 prepared statement（COM_STMT_PREPARE），而 Laravel 預設
 *    PDO::ATTR_EMULATE_PREPARES=false；DB_READ_EMULATE_PREPARES / DB_WRITE_EMULATE_PREPARES
 *    分別讓 read / write 連線改用 PDO 端模擬 prepare（文字協定）。兩個開關獨立：只有 read 走 dbc
 *    （正式庫本機只讀）時只開 read；Dev 讀寫都走 dbc 時兩個都開。
 * 2. dbc 每站一個本機 port（34xx 從庫 / 35xx 主庫），read 與 write 各自不同，故 read / write
 *    各有 *_DB_PORT_READ / *_DB_PORT_WRITE；未設時退回 DB_PORT，行為與過去一致。
 *
 * 正式環境不設這些變數，全部為 no-op（read / write 的 options 與 port 展開後與改前相同）。
 */
$mysqlReadOptions = filter_var(env('DB_READ_EMULATE_PREPARES', false), FILTER_VALIDATE_BOOLEAN)
    ? $mysqlOptions + [PDO::ATTR_EMULATE_PREPARES => true]
    : $mysqlOptions;
$mysqlWriteOptions = filter_var(env('DB_WRITE_EMULATE_PREPARES', false), FILTER_VALIDATE_BOOLEAN)
    ? $mysqlOptions + [PDO::ATTR_EMULATE_PREPARES => true]
    : $mysqlOptions;

$mysqlCommon = [
    'driver' => 'mysql',

    'read' => [
        'host' => env('DB_HOST_READ', '0.0.0.0'),
        'port' => env('DB_PORT_READ', env('DB_PORT', '3306')),
        'username' => env('DB_USERNAME_READ', 'root'),
        'password' => env('DB_PASSWORD_READ', ''),
        'options' => $mysqlReadOptions,
    ],
    'write' => [
        'host' => env('DB_HOST_WRITE', '0.0.0.0'),
        'port' => env('DB_PORT_WRITE', env('DB_PORT', '3306')),
        'username' => env('DB_USERNAME_WRITE', 'root'),
        'password' => env('DB_PASSWORD_WRITE', ''),
        'options' => $mysqlWriteOptions,
    ],
    'sticky' => true,

    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'laravel'),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    /**
     * 嚴格模式下拿掉 ONLY_FULL_GROUP_BY
     *
     * @see https://example.invalid/articles/53725
     * @see vendor/laravel/framework/src/Illuminate/Database/Connectors/MySqlConnector.php at getSqlMode()
     */
    'modes' => ['STRICT_TRANS_TABLES', 'NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'],
    'engine' => null,
    'options' => $mysqlOptions,
];

// 共用 Redis 設定
$redisCommon = [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '0.0.0.0'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => $mysqlCommon,
        /**
         * Dev 讀寫分離：DEV_DB_* 未設時退回預設連線（DB_*）座標。
         *
         * array_merge 是淺合併，此處的 read / write 會整塊取代 $mysqlCommon 的同名鍵，
         * 故不能只寫 env('DEV_DB_HOST_READ')——未設時值為 null，該連線便無可用座標。
         */
        'Dev' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('DEV_DB_HOST_READ', env('DB_HOST_READ', '0.0.0.0')),
                'port' => env('DEV_DB_PORT_READ', env('DB_PORT_READ', env('DB_PORT'))),
                'username' => env('DEV_DB_USERNAME_READ', env('DB_USERNAME_READ', 'root')),
                'password' => env('DEV_DB_PASSWORD_READ', env('DB_PASSWORD_READ', '')),
                'options' => $mysqlReadOptions,
            ],
            'write' => [
                'host' => env('DEV_DB_HOST_WRITE', env('DB_HOST_WRITE', '0.0.0.0')),
                'port' => env('DEV_DB_PORT_WRITE', env('DB_PORT_WRITE', env('DB_PORT'))),
                'username' => env('DEV_DB_USERNAME_WRITE', env('DB_USERNAME_WRITE', 'root')),
                'password' => env('DEV_DB_PASSWORD_WRITE', env('DB_PASSWORD_WRITE', '')),
                'options' => $mysqlWriteOptions,
            ],
        ]),

        'log' => array_merge($mysqlCommon, [
            'database' => env('DB_DATABASE', 'laravel') . '_log',
        ]),
        'testing' => array_merge($mysqlCommon, [
            'database' => env('TESTING_DB_DATABASE', 'xsgcasino_test'),
            'read' => [
                'host' => env('TESTING_DB_HOST_READ', '0.0.0.0'),
                'username' => env('TESTING_DB_USERNAME_READ', 'root'),
                'password' => env('TESTING_DB_PASSWORD_READ', ''),
            ],
            'write' => [
                'host' => env('TESTING_DB_HOST_WRITE', '0.0.0.0'),
                'username' => env('TESTING_DB_USERNAME_WRITE', 'root'),
                'password' => env('TESTING_DB_PASSWORD_WRITE', ''),
            ],
        ]),

        'VWorld' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('VW_DB_HOST_READ'),
                'port' => env('VW_DB_PORT_READ', env('DB_PORT')),
                'username' => env('VW_DB_USERNAME_READ'),
                'password' => env('VW_DB_PASSWORD_READ'),
                'options' => $mysqlReadOptions,
            ],
            'write' => [
                'host' => env('VW_DB_HOST_WRITE'),
                'port' => env('VW_DB_PORT_WRITE', env('DB_PORT')),
                'username' => env('VW_DB_USERNAME_WRITE'),
                'password' => env('VW_DB_PASSWORD_WRITE'),
                'options' => $mysqlWriteOptions,
            ],
        ]),

        'TPower' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('TP_DB_HOST_READ'),
                'port' => env('TP_DB_PORT_READ', env('DB_PORT')),
                'username' => env('TP_DB_USERNAME_READ'),
                'password' => env('TP_DB_PASSWORD_READ'),
                'options' => $mysqlReadOptions,
            ],
            'write' => [
                'host' => env('TP_DB_HOST_WRITE'),
                'port' => env('TP_DB_PORT_WRITE', env('DB_PORT')),
                'username' => env('TP_DB_USERNAME_WRITE'),
                'password' => env('TP_DB_PASSWORD_WRITE'),
                'options' => $mysqlWriteOptions,
            ],
        ]),

        'VPlus33' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('VP_DB_HOST_READ'),
                'port' => env('VP_DB_PORT_READ', env('DB_PORT')),
                'username' => env('VP_DB_USERNAME_READ'),
                'password' => env('VP_DB_PASSWORD_READ'),
                'options' => $mysqlReadOptions,
            ],
            'write' => [
                'host' => env('VP_DB_HOST_WRITE'),
                'port' => env('VP_DB_PORT_WRITE', env('DB_PORT')),
                'username' => env('VP_DB_USERNAME_WRITE'),
                'password' => env('VP_DB_PASSWORD_WRITE'),
                'options' => $mysqlWriteOptions,
            ],
        ]),

        'Yes2Win' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('Y2W_DB_HOST_READ'),
                'port' => env('Y2W_DB_PORT_READ', env('DB_PORT')),
                'username' => env('Y2W_DB_USERNAME_READ'),
                'password' => env('Y2W_DB_PASSWORD_READ'),
                'options' => $mysqlReadOptions,
            ],
            'write' => [
                'host' => env('Y2W_DB_HOST_WRITE'),
                'port' => env('Y2W_DB_PORT_WRITE', env('DB_PORT')),
                'username' => env('Y2W_DB_USERNAME_WRITE'),
                'password' => env('Y2W_DB_PASSWORD_WRITE'),
                'options' => $mysqlWriteOptions,
            ],
        ]),

        'Alfawin77' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('AW77_DB_HOST_READ'),
                'port' => env('AW77_DB_PORT_READ', env('DB_PORT')),
                'username' => env('AW77_DB_USERNAME_READ'),
                'password' => env('AW77_DB_PASSWORD_READ'),
                'options' => $mysqlReadOptions,
            ],
            'write' => [
                'host' => env('AW77_DB_HOST_WRITE'),
                'port' => env('AW77_DB_PORT_WRITE', env('DB_PORT')),
                'username' => env('AW77_DB_USERNAME_WRITE'),
                'password' => env('AW77_DB_PASSWORD_WRITE'),
                'options' => $mysqlWriteOptions,
            ],
        ]),

        'Me77' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('ME77_DB_HOST_READ'),
                'port' => env('ME77_DB_PORT_READ', env('DB_PORT')),
                'username' => env('ME77_DB_USERNAME_READ'),
                'password' => env('ME77_DB_PASSWORD_READ'),
                'options' => $mysqlReadOptions,
            ],
            'write' => [
                'host' => env('ME77_DB_HOST_WRITE'),
                'port' => env('ME77_DB_PORT_WRITE', env('DB_PORT')),
                'username' => env('ME77_DB_USERNAME_WRITE'),
                'password' => env('ME77_DB_PASSWORD_WRITE'),
                'options' => $mysqlWriteOptions,
            ],
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', ''),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => array_merge($redisCommon, [
            'database' => env('REDIS_DB', '0'),
            'serializer' => Redis::SERIALIZER_PHP,
            'compression' => Redis::COMPRESSION_NONE,
        ]),

        // Queue 專用連線
        'queue' => array_merge($redisCommon, [
            'database' => env('REDIS_QUEUE_DB', '3'),
            'serializer' => Redis::SERIALIZER_NONE, // 務必維持 serializer=NONE
            'compression' => Redis::COMPRESSION_NONE,
        ]),

        'cache' => array_merge($redisCommon, [
            'database' => env('REDIS_CACHE_DB', '0'),
            'serializer' => Redis::SERIALIZER_PHP,
            'compression' => Redis::COMPRESSION_NONE,
        ]),

        'json' => array_merge($redisCommon, [
            'database' => env('REDIS_DB', '0'),
            'serializer' => Redis::SERIALIZER_JSON,
            'compression' => Redis::COMPRESSION_NONE,
        ]),

        'session' => array_merge($redisCommon, [
            'database' => env('REDIS_SESSION_DB', '2'),
        ]),

        'db_15' => array_merge($redisCommon, [
            'database' => '15',
            'serializer' => Redis::SERIALIZER_PHP,
            'compression' => Redis::COMPRESSION_NONE,
        ]),
    ],

];
