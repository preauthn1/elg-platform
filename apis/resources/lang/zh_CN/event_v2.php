<?php

declare(strict_types=1);

use App\Enums\EventV2\EventV2ValidateCodeEnum;

return [
    'validation' => [
        EventV2ValidateCodeEnum::SUCCESS->value => '活动条件达成',
        EventV2ValidateCodeEnum::EVENT_NOT_EXIST->value => '活动不存在',
        EventV2ValidateCodeEnum::EVENT_MODE->value => '锁分模式中不能参加活动',
        EventV2ValidateCodeEnum::ONLY_ONE_EVENT->value => '一次只能参加一个活动',
        EventV2ValidateCodeEnum::HAS_JOIN->value => '您已经参加过此活动，无法再次参加',
        EventV2ValidateCodeEnum::DAILY->value => '您今天已经参加过此活动，无法再次参加',
        EventV2ValidateCodeEnum::WEEKLY->value => '您本周已经参加过此活动，无法再次参加',
        EventV2ValidateCodeEnum::MONTHLY->value => '您本月已经参加过此活动，无法再次参加',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_REGISTER->value => '此IP已经参加过活动',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V1_V2->value => '此IP已经参加过活动',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V2->value => '此IP已经参加过活动',
        EventV2ValidateCodeEnum::PHONE_NOT_VALIDATE->value => '请先绑定手机验证',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_REGISTER->value => '此手机已经参加过活动',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V1_V2->value => '此手机已经参加过活动',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V2->value => '此手机已经参加过活动',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_REGISTER->value => '此帐号已经参加过活动',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V1_V2->value => '此帐号已经参加过活动',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V2->value => '此帐号已经参加过活动',
        EventV2ValidateCodeEnum::MAX_JOIN_REACHED->value => '活动人数已达上限',
        EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH->value => '充值尚未达到标准',
        EventV2ValidateCodeEnum::BALANCE_NOT_ENOUGH->value => '主钱包余额不足',
        EventV2ValidateCodeEnum::OVER_JOIN_TIME->value => '已超过参加时间',
        EventV2ValidateCodeEnum::WIN_LOSE->value => '直属下级会员结算不为输分',

        // 欢迎奖金活动
        EventV2ValidateCodeEnum::EVENT_04_NOT_COMPLETE_EVENT_03->value => '您尚未完成"欢迎奖金50%"活动，请先完成',

        // 邀请好友活动
        EventV2ValidateCodeEnum::EVENT_13_INVITE_NOT_ENOUGH->value => '邀请人数不足，已邀请人数为 :invite',

        // 注册活动
        EventV2ValidateCodeEnum::EVENT_01_NOT_IN_TIME->value => '注册时间不再活动时间内',
        EventV2ValidateCodeEnum::EVENT_01_ONLY_ONE_TIMES->value => '注册活动只能参加一次',
        EventV2ValidateCodeEnum::EVENT_01_REALNAME->value => '请先完成实名认证',

        // 首储活动
        EventV2ValidateCodeEnum::DEPOSIT_ALREADY_AWARDED->value => '此笔储值已被其他奖项领取，无法用于本活动',
        EventV2ValidateCodeEnum::DEPOSIT_ALREADY_USED->value => '此笔储值已用于参加其他活动',

        'balance' => '主钱包余额不足',
        'no_join' => '您尚未参加任何活动',
        'no_join_2' => '此玩家已结束活动',
        'wallet_balance' => '活动钱包余额不足',
        'flow' => '流水不足',
        'score_expire_invalid' => '积分有效期须为正整数（小时），留空代表永久',
        'score_type_not_supported' => '每日区间储值、每周累积储值、每日储值组合、红包不提供积分领取，请改选其他活动类型或主钱包',
        'score_fields_not_allowed' => '积分型请留空奖励公式／配置、余额限制、稽核流水；积分数量填提款限制公式，有效期与上限填提款限制配置',
        'score_limit_invalid' => '提款限制配置的 limit（积分上限）须为数字',
        'score_formula_required' => '积分型请填积分数量：提款限制公式，或提款限制配置的 tiers 阶梯',
        'score_amount_source_conflict' => '积分数量只能填一处：提款限制公式与 tiers 阶梯不可同时设定',
        'score_tiers_invalid' => 'tiers 须为数组，每组要有 condition（条件字符串）与 score（数字或公式）',
        'main_wallet_config_keys_not_allowed' => '主钱包型的提款限制配置不使用 expire／limit／tiers，请移除或改选领取去向为积分',
        'withdraw_type_locked' => '领取去向建立后不可变动，要改请另建活动',
        'game_type' => '游戏类别不符合',

        // 红包活动（target/tickets#4234）
        EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_SET->value => '此红包尚未设定消耗积分，请联系客服',
        EventV2ValidateCodeEnum::ANGPOW_OUT_OF_TIME_WINDOW->value => '现在不在红包开放时段',
        EventV2ValidateCodeEnum::ANGPOW_INTERVAL_NOT_REACHED->value => '领取间隔未到，请稍后再试',
        EventV2ValidateCodeEnum::ANGPOW_MEMBER_TIMES_REACHED->value => '已达本期个人领取次数上限',
        EventV2ValidateCodeEnum::ANGPOW_MEMBER_AMOUNT_REACHED->value => '已达本期个人领取金额上限',
        EventV2ValidateCodeEnum::ANGPOW_MAX_AMOUNT_REACHED->value => '红包已发完',
        EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_ENOUGH->value => '积分不足',
        EventV2ValidateCodeEnum::TARGET_NOT_MATCHED->value => '您不符合此活动的参加资格',
        'target_type_required' => '已选择目标用户，请先选择目标用户类型',
        'target_ids_required' => '已选择目标用户类型，请至少选一个会员或群组',
        'target_ids_invalid' => '目标用户中有不存在的会员或群组',
        'period_not_supported' => '只有红包活动可以设定周期（可重复参加／包括期间），其他类型请留 0',
        'period_locked' => '活动周期建立后不可变动，要改请另建活动',
        'angpow_score_only' => '消耗积分（score）只有红包活动可以设定',
        'angpow_score_required' => '红包必须在条件配置设定 score（每抽一次扣几分，正数）',
        'angpow_reward_formula_required' => '红包必须填奖励公式（每包抽到多少，例如 rand(10, 500) 或固定 20）',
        'angpow_config_invalid' => '条件配置的 :key 格式不正确',
        'not_angpow' => '此活动不是红包',
    ],

    // 后台「设定条件」十格的参数说明弹窗（target/tickets#4231 需求 7），结构同 zh_TW
    'params' => [
        'variables' => [
            'deposit' => ['name' => 'deposit', 'description' => '触发本次参加的那笔储值面额（该币种金额，不换汇）', 'example' => 'deposit >= 20'],
            'reward' => ['name' => 'reward', 'description' => '本次算出的奖励金额（奖励公式／配置的结果）；勾「参加即领奖」时三个限制公式不会被检查', 'example' => 'reward * 3'],
            'invite' => ['name' => 'invite', 'description' => '合格邀请人数（依 IP／手机／姓名去重后）', 'example' => 'invite * 10'],
            'win_lose' => ['name' => 'win_lose', 'description' => '直属下线上一周净输的金额（正数）', 'example' => 'win_lose * 0.1'],
            'rand' => ['name' => 'rand(a, b)', 'description' => 'a～b 之间随机取值（小数两位）', 'example' => 'rand(10, 500)'],
        ],
        'keys' => [
            'max_join' => ['name' => 'max_join', 'type' => 'integer', 'description' => '全站参加上限（笔数，不含后台操作放弃）', 'example' => '{"max_join": 100}'],
            'realname' => ['name' => 'realname', 'type' => 'integer', 'description' => '1＝需完成实名认证才能参加', 'example' => '{"realname": 1}'],
            'invite' => ['name' => 'invite', 'type' => 'integer', 'description' => '需邀请的合格人数', 'example' => '{"invite": 3}'],
            'invite_deposit' => ['name' => 'deposit', 'type' => 'number', 'description' => '被邀请人至少要有一笔此面额以上的储值才算合格', 'example' => '{"invite": 3, "deposit": 50}'],
            'dayOfWeek' => ['name' => 'dayOfWeek', 'type' => 'integer', 'description' => '1～7 当周可领取的截止星期（1＝周一）；0 或不设＝累计到上次参加或活动开始', 'example' => '{"dayOfWeek": 7}'],
            'main_wallet_deduction' => ['name' => 'main_wallet_deduction', 'type' => 'number', 'description' => '参加时从主钱包扣进活动钱包的金额；不设＝扣整笔储值，0＝不扣（领取去向＝积分时忽略）', 'example' => '{"main_wallet_deduction": 0}'],
            'limit' => ['name' => 'limit', 'type' => 'number', 'description' => '奖励封顶：公式算出超过就压到这个数', 'example' => '{"limit": 300}'],
            'balance_multiple' => ['name' => 'balance_multiple', 'type' => 'number', 'description' => '领取倍数：可领金额＝活动钱包余额 × 此值，再受提款限制公式封顶', 'example' => '{"balance_multiple": 1}'],
            'expire' => ['name' => 'expire', 'type' => 'integer', 'description' => '积分有效期（小时），到期时间进位到下一个 10 分钟；不设＝永久', 'example' => '{"expire": 720}'],
            'score_limit' => ['name' => 'limit', 'type' => 'number', 'description' => '积分上限：算出超过就压到这个数', 'example' => '{"expire": 720, "limit": 300}'],
            'score_tiers' => ['name' => 'tiers', 'type' => 'array', 'description' => '阶梯：由上往下第一个 condition 成立的那组就是积分数，门槛由高往低排；有 tiers 时提款限制公式须留空', 'example' => '{"expire": 720, "tiers": [{"condition": "deposit >= 300", "score": 30}, {"condition": "deposit >= 100", "score": 10}]}'],
            // 红包（target/tickets#4234）：两个周期在「可重复参加」「包括期间」字段不在这里；只有 score 必填
            'angpow_score' => ['name' => 'score', 'type' => 'number', 'description' => '每抽一次扣几分（必填、正数；超过 2 位小数无条件舍去）。积分是门票：参加当下扣、放弃或作废都不退', 'example' => '{"score": 10}'],
            'angpow_max_join' => ['name' => 'max_join', 'type' => 'integer', 'description' => '全站总次数：「包括期间」内的参加笔数（不含后台操作放弃）达到就挡；期一过重新计，不分周期＝整档活动总共', 'example' => '{"score": 10, "max_join": 50}'],
            'angpow_max_amount' => ['name' => 'max_amount', 'type' => 'number', 'description' => '全站总金额：「包括期间」内已发出（参加当下抽到的金额加总，不含后台操作放弃）达到就挡，期一过重新计；没到就能抽、抽到多少给多少，最后一包可能让总额超过', 'example' => '{"score": 10, "max_amount": 500000}'],
            'angpow_member_times' => ['name' => 'member_times', 'type' => 'integer', 'description' => '每人在「可重复参加」周期内最多抽几次；不分周期＝整档活动总共', 'example' => '{"score": 10, "member_times": 5}'],
            'angpow_member_amount' => ['name' => 'member_amount', 'type' => 'number', 'description' => '每人在「可重复参加」周期内最多抽到多少（参加当下抽到的金额加总），到了直接挡', 'example' => '{"score": 10, "member_amount": 388}'],
            'angpow_interval' => ['name' => 'interval', 'type' => 'integer', 'description' => '同一人两次抽之间至少隔几秒', 'example' => '{"score": 10, "interval": 300}'],
            'angpow_time_window' => ['name' => 'time_window', 'type' => 'array', 'description' => '每天可抽的时段 ["起", "迄"]，HH:MM，填后台时间（目前 +8）；系统转成 UTC 存、以系统时间判断（填 08:00～23:00 会存成 00:00～15:00，编辑时转回原值显示）；起晚于迄视为跨夜；不设＝全天', 'example' => '{"score": 10, "time_window": ["08:00", "23:00"]}'],
        ],
        'arrays' => [
            'range_reward' => ['name' => '[{"reward", "condition"}]', 'type' => 'array', 'description' => '由上往下逐组：先把 reward 加进总奖励，再判断 condition，成立就停（存 120 对范例得 6.99＋3＋7）', 'example' => '[{"reward": 6.99, "condition": "deposit < 50"}, {"reward": 3, "condition": "deposit < 100"}, {"reward": 7, "condition": "deposit < 300"}, {"reward": 23, "condition": "deposit >= 300"}]'],
            'combo_condition' => ['name' => '[{"count", "deposit"}]', 'type' => 'array', 'description' => '今天面额刚好等于 deposit 的完成订单达 count 笔即符合，任一组成立即可参加', 'example' => '[{"count": 1, "deposit": "10"}, {"count": 2, "deposit": "50"}]'],
            'combo_reward' => ['name' => '[{"reward", "condition": {"count", "deposit"}}]', 'type' => 'array', 'description' => '每组独立判断，成立的奖励全部相加', 'example' => '[{"reward": 5, "condition": {"count": 1, "deposit": 10}}, {"reward": 30, "condition": {"count": 2, "deposit": 50}}]'],
            'combo_balance' => ['name' => '[{"balance", "condition": {"count", "deposit"}}]', 'type' => 'array', 'description' => '必填、每组一笔：成立那几组的 balance 相加就是余额门槛；不设门槛填 0，空着会出错', 'example' => '[{"balance": 50, "condition": {"count": 1, "deposit": 10}}, {"balance": 100, "condition": {"count": 2, "deposit": 50}}]'],
        ],
    ],
];
