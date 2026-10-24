<?php

declare(strict_types=1);

/**
 * 請參照 ActivityLogServiceProvider 進行模型與事件的綁定
 */

return [
    'Member' => [
        'created' => [
            'log_name' => 'member-register',
            'description' => '會員 :id 註冊，IP :last_login_ip',
            'attributes' => ['id', 'last_login_ip'],
        ],
        'updated' => [
            // IP 異動事件
            [
                'log_name' => 'member-login',
                'description' => '會員 :id 登入，IP :last_login_ip',
                'attributes' => ['id', 'last_login', 'last_login_ip'],
                'formatters' => [
                    'last_login_ip' => 'ip_long',
                ],
            ],
            // 餘額異動事件
            [
                'log_name' => 'member-balance-changed',
                'description' => '餘額從 :before 變更為 :after',
                'attributes' => ['balance'],
            ],
            // 修改真實姓名
            [
                'log_name' => 'member-realname-changed',
                'description' => '真實姓名從 :before 變更為 :after',
                'attributes' => ['first_name'],
            ],
            // 修改手機號
            [
                'log_name' => 'member-mobile-changed',
                'description' => '手機號從 :before 變更為 :after',
                'attributes' => ['mobile'],
            ],
        ],
    ],
    'Order' => [
        'created' => [
            'log_name' => 'deposit-created',
            'description' => '建立存款單：編號 :id 金額 :amount',
            'attributes' => ['id', 'order_no', 'amount'],
        ],
        // 三方回調
        'updated' => [
            'log_name' => 'deposit-updated',
            'description' => '更新存款單：編號 :id 狀態 :status',
            'attributes' => ['id', 'order_no', 'third_party_order_no', 'amount', 'status'],
        ],
    ],
    'WithdrawalOrder' => [
        'created' => [
            'log_name' => 'withdrawal-created',
            'description' => '建立提款單：編號 :id 金額 :amount',
            'attributes' => ['id', 'order_no', 'amount'],
        ],
        // 三方回調
        'updated' => [
            'log_name' => 'withdrawal-updated',
            'description' => '更新提款單：編號 :id 狀態 :status',
            'attributes' => ['id', 'order_no', 'third_party_order_no', 'amount', 'status'],
        ],
    ],
    'MemberWallet' => [
        'created' => [
            'log_name' => 'wallet-created',
            'description' => '創建 :wallet_type_id 錢包, 餘額 :balance, ID :id',
            'attributes' => ['id', 'member_id', 'wallet_type_id', 'balance', 'first_deposit', 'status'],
        ],
        'updated' => [
            [
                'log_name' => 'wallet-status',
                'description' => '變更 :wallet_type_id 錢包狀態 :status, ID :id',
                'attributes' => ['id', 'member_id', 'status'],
            ],
            [
                'log_name' => 'wallet-balance-changed',
                'description' => '變更 :wallet_type_id 錢包狀態 :status, ID :id',
                'attributes' => ['id', 'member_id', 'wallet_type_id', 'status'],
            ],
        ],
    ],
    'EventV2UserJoin' => [
        'created' => [
            'log_name' => 'event-joined',
            'description' => '會員 :member_id 參加活動 :event_v2_detail_id',
            'attributes' => ['id', 'event_v2_detail_id', 'member_id', 'order_id', 'reward', 'balance_limit', 'flow_limit', 'withdraw_limit', 'withdraw', 'ip', 'status'],
        ],
        'updated' => [
            [
                'log_name' => 'event-updated',
                'description' => '會員 :member_id 更新活動 :event_v2_detail_id 訂單 :order_id',
                'attributes' => ['id', 'member_id', 'order_id', 'event_v2_detail_id'],
            ],
            [
                'log_name' => 'event-modified',
                'description' => '活動ID #:id 條件調整',
                'attributes' => ['id', 'member_id', 'event_v2_detail_id', 'reward', 'balance_limit', 'flow_limit', 'withdraw_limit', 'withdraw'],
            ],
            // 當 status 變更為 0（放棄活動）時特別記錄；attributes 使用 status 與 event_v2_detail_id
            [
                'log_name' => 'event-abandoned',
                'description' => '會員 :member_id 放棄活動 :event_v2_detail_id',
                'attributes' => ['id', 'status', 'event_v2_detail_id'],
                'conditions' => [
                    'status' => [
                        'to' => 0,
                    ],
                ],
            ],
        ],
    ],
];
