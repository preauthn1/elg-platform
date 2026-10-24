<?php

declare(strict_types=1);

return [
    'validation' => [
        'not_available' => '新人挑战活动目前未开放',
        'registered_before_start' => '仅活动开始后注册的玩家可参加',
        'kyc_not_verified' => '尚未完成实名认证',
        'participating_in_another_event' => '已参加其他活动，无法同时参加',
        'already_joined' => '已经参加过了',
        'not_joined' => '尚未参加新人挑战活动',
        'bonus_not_pending' => '目前不是待领取彩金阶段',
        'bonus_window_expired' => '彩金领取时间已过期',
        'no_qualifying_deposit' => '查无符合资格的储值记录',
        'challenge_not_in_progress' => '目前不是挑战进行中阶段',
        'challenge_flow_limit_not_reached' => '挑战流水尚未达标',
        'unlocked_bonus_below_minimum' => '可解锁彩金尚未达最低门槛，请继续游玩',
        'bonus_flow_not_in_progress' => '目前不是彩金流水任务阶段',
        'bonus_flow_limit_not_reached' => '彩金流水尚未达标',
        'bonus_claim_not_found' => '查无彩金核销记录',
        'cannot_quit_at_current_stage' => '目前阶段无法放弃活动',
        'ip_already_joined' => '此 IP 已有人参加过',
        'phone_already_joined' => '此手机号码已有人参加过',
        'account_already_joined' => '此姓名已有人参加过',
        'game_not_allowed' => '此游戏不在活动限定范围内',
    ],
];
