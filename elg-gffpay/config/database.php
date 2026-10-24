<?php

$mysqlCommon = [
    'driver' => 'mysql',

    'read' => [
        'host' => env('DB_HOST_READ', '0.0.0.0'),
        'username' => env('DB_USERNAME_READ', 'root'),
        'password' => env('DB_PASSWORD_READ', ''),
    ],
    'write' => [
        'host' => env('DB_HOST_WRITE', '0.0.0.0'),
        'username' => env('DB_USERNAME_WRITE', 'root'),
        'password' => env('DB_PASSWORD_WRITE', ''),
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
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
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
        'dev' => $mysqlCommon,

        'log' => array_merge($mysqlCommon, [
            'database' => env('DB_DATABASE', 'laravel') . '_log',
        ]),

        'VWorld' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('VW_DB_HOST_READ'),
                'username' => env('VW_DB_USERNAME_READ'),
                'password' => env('VW_DB_PASSWORD_READ'),
            ],
            'write' => [
                'host' => env('VW_DB_HOST_WRITE'),
                'username' => env('VW_DB_USERNAME_WRITE'),
                'password' => env('VW_DB_PASSWORD_WRITE'),
            ],
        ]),

        'TPower' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('TP_DB_HOST_READ'),
                'username' => env('TP_DB_USERNAME_READ'),
                'password' => env('TP_DB_PASSWORD_READ'),
            ],
            'write' => [
                'host' => env('TP_DB_HOST_WRITE'),
                'username' => env('TP_DB_USERNAME_WRITE'),
                'password' => env('TP_DB_PASSWORD_WRITE'),
            ],
        ]),

        'VPlus33' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('VP_DB_HOST_READ'),
                'username' => env('VP_DB_USERNAME_READ'),
                'password' => env('VP_DB_PASSWORD_READ'),
            ],
            'write' => [
                'host' => env('VP_DB_HOST_WRITE'),
                'username' => env('VP_DB_USERNAME_WRITE'),
                'password' => env('VP_DB_PASSWORD_WRITE'),
            ],
        ]),

        'VietWin88' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('VN_DB_HOST_READ'),
                'username' => env('VN_DB_USERNAME_READ'),
                'password' => env('VN_DB_PASSWORD_READ'),
            ],
            'write' => [
                'host' => env('VN_DB_HOST_WRITE'),
                'username' => env('VN_DB_USERNAME_WRITE'),
                'password' => env('VN_DB_PASSWORD_WRITE'),
            ],
        ]),

        'Yes2Win' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('Y2W_DB_HOST_READ'),
                'username' => env('Y2W_DB_USERNAME_READ'),
                'password' => env('Y2W_DB_PASSWORD_READ'),
            ],
            'write' => [
                'host' => env('Y2W_DB_HOST_WRITE'),
                'username' => env('Y2W_DB_USERNAME_WRITE'),
                'password' => env('Y2W_DB_PASSWORD_WRITE'),
            ],
        ]),

        'AlphaWin77' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('AW77_DB_HOST_READ'),
                'username' => env('AW77_DB_USERNAME_READ'),
                'password' => env('AW77_DB_PASSWORD_READ'),
            ],
            'write' => [
                'host' => env('AW77_DB_HOST_WRITE'),
                'username' => env('AW77_DB_USERNAME_WRITE'),
                'password' => env('AW77_DB_PASSWORD_WRITE'),
            ],
        ]),

        'Me77' => array_merge($mysqlCommon, [
            'read' => [
                'host' => env('ME77_DB_HOST_READ'),
                'username' => env('ME77_DB_USERNAME_READ'),
                'password' => env('ME77_DB_PASSWORD_READ'),
            ],
            'write' => [
                'host' => env('ME77_DB_HOST_WRITE'),
                'username' => env('ME77_DB_USERNAME_WRITE'),
                'password' => env('ME77_DB_PASSWORD_WRITE'),
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
    ],

];
