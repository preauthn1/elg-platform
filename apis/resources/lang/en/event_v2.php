<?php

declare(strict_types=1);

use App\Enums\EventV2\EventV2ValidateCodeEnum;

return [
    'validation' => [
        EventV2ValidateCodeEnum::SUCCESS->value => 'The event conditions have been met.',
        EventV2ValidateCodeEnum::EVENT_NOT_EXIST->value => 'The event does not exist.',
        EventV2ValidateCodeEnum::EVENT_MODE->value => 'You cannot participate in an event while in lock mode.',
        EventV2ValidateCodeEnum::ONLY_ONE_EVENT->value => 'You can only participate in one event at a time.',
        EventV2ValidateCodeEnum::HAS_JOIN->value => 'You have already joined this event and cannot join again.',
        EventV2ValidateCodeEnum::DAILY->value => 'You have already participated in this event today and cannot join again.',
        EventV2ValidateCodeEnum::WEEKLY->value => 'You have already participated in this event this week and cannot join again.',
        EventV2ValidateCodeEnum::MONTHLY->value => 'You have already participated in this event this month and cannot join again.',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_REGISTER->value => 'This IP has already participated in an event.',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V1_V2->value => 'This IP has already participated in an event.',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V2->value => 'This IP has already participated in an event.',
        EventV2ValidateCodeEnum::PHONE_NOT_VALIDATE->value => 'Please bind a mobile verification first.',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_REGISTER->value => 'This phone number has already participated in an event.',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V1_V2->value => 'This phone number has already participated in an event.',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V2->value => 'This phone number has already participated in an event.',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_REGISTER->value => 'This account has already participated in an event.',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V1_V2->value => 'This account has already participated in an event.',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V2->value => 'This account has already participated in an event.',
        EventV2ValidateCodeEnum::MAX_JOIN_REACHED->value => 'The event has reached its maximum number of participants.',
        EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH->value => 'Your deposit has not reached the required threshold.',
        EventV2ValidateCodeEnum::BALANCE_NOT_ENOUGH->value => 'Insufficient main wallet balance.',
        EventV2ValidateCodeEnum::OVER_JOIN_TIME->value => 'The join time has expired.',
        EventV2ValidateCodeEnum::WIN_LOSE->value => "Your direct downline's settlement amount was not a net loss.",

        // Welcome bonus event
        EventV2ValidateCodeEnum::EVENT_04_NOT_COMPLETE_EVENT_03->value => 'You have not completed the "Welcome Bonus 50%" event yet. Please complete it first.',

        // Invite friend event
        EventV2ValidateCodeEnum::EVENT_13_INVITE_NOT_ENOUGH->value => 'Insufficient number of invitees. Current invite count: :invite',

        // Registration event
        EventV2ValidateCodeEnum::EVENT_01_NOT_IN_TIME->value => 'The registration time is not within the event period.',
        EventV2ValidateCodeEnum::EVENT_01_ONLY_ONE_TIMES->value => 'You can only join the registration event once.',
        EventV2ValidateCodeEnum::EVENT_01_REALNAME->value => 'Please complete real name authentication first.',

        // First deposit event
        EventV2ValidateCodeEnum::DEPOSIT_ALREADY_AWARDED->value => 'This deposit has already been claimed by another reward and cannot be used for this event.',
        EventV2ValidateCodeEnum::DEPOSIT_ALREADY_USED->value => 'This deposit has already been used to join another event.',

        'balance' => 'Insufficient main wallet balance.',
        'no_join' => 'You have not joined any event yet.',
        'no_join_2' => 'This player has ended the event.',
        'wallet_balance' => 'Insufficient event wallet balance.',
        'flow' => 'Insufficient turnover.',
        'score_expire_invalid' => 'Score expiry must be a positive integer (hours); leave blank for no expiry',
        'score_type_not_supported' => 'Daily deposit range, weekly deposit, daily deposit combo and angpow events cannot pay out as score; choose another event type or main wallet',
        'score_fields_not_allowed' => 'Score events must leave reward formula/config, balance limit and turnover limit blank; put the score amount in the withdrawal limit formula and expiry/cap in the withdrawal limit config',
        'score_limit_invalid' => 'withdrawal limit config "limit" (score cap) must be numeric',
        'score_formula_required' => 'Score events need an amount: set the withdrawal limit formula, or tiers in the withdrawal limit config',
        'score_amount_source_conflict' => 'Set the score amount in one place only: withdrawal limit formula and tiers cannot both be set',
        'score_tiers_invalid' => 'tiers must be an array; each entry needs condition (string) and score (number or formula)',
        'main_wallet_config_keys_not_allowed' => 'Main-wallet events do not use expire/limit/tiers in withdrawal limit config; remove them or switch payout to score',
        'withdraw_type_locked' => 'Payout destination cannot be changed after creation; create a new event instead',
        'game_type' => 'Game type does not match.',

        // Angpow (target/tickets#4234)
        EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_SET->value => 'This angpow has no score cost configured; please contact support',
        EventV2ValidateCodeEnum::ANGPOW_OUT_OF_TIME_WINDOW->value => 'The angpow is not open at this time',
        EventV2ValidateCodeEnum::ANGPOW_INTERVAL_NOT_REACHED->value => 'Please wait before claiming again',
        EventV2ValidateCodeEnum::ANGPOW_MEMBER_TIMES_REACHED->value => 'You have reached your claim count limit for this period',
        EventV2ValidateCodeEnum::ANGPOW_MEMBER_AMOUNT_REACHED->value => 'You have reached your claim amount limit for this period',
        EventV2ValidateCodeEnum::ANGPOW_MAX_AMOUNT_REACHED->value => 'All angpows have been claimed',
        EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_ENOUGH->value => 'Insufficient score',
        EventV2ValidateCodeEnum::TARGET_NOT_MATCHED->value => 'You are not eligible for this event',
        'target_type_required' => 'Targets are selected; choose a target type first',
        'target_ids_required' => 'A target type is selected; choose at least one member or group',
        'target_ids_invalid' => 'Some selected members or groups do not exist',
        'period_not_supported' => 'Only angpow events can set a period (recurring / include period); leave 0 for other types',
        'period_locked' => 'Event period cannot be changed after creation; create a new event instead',
        'angpow_score_only' => 'Score cost (score) can only be set on angpow events',
        'angpow_score_required' => 'Angpow requires score in condition config (score deducted per claim, positive number)',
        'angpow_reward_formula_required' => 'Angpow events require a reward formula (amount per draw, e.g. rand(10, 500) or a fixed 20)',
        'angpow_config_invalid' => 'Condition config :key is not valid',
        'not_angpow' => 'This event is not an angpow',
    ],

    // Parameter help dialog for the ten hand-written fields on the "Conditions" tab (target/tickets#4231 req. 7); same structure as zh_TW
    'params' => [
        'variables' => [
            'deposit' => ['name' => 'deposit', 'description' => 'Face value of the deposit that triggered this participation (in that currency, no conversion)', 'example' => 'deposit >= 20'],
            'reward' => ['name' => 'reward', 'description' => 'Reward amount computed for this participation (result of reward formula/config); the three limit formulas are not checked when "claim on join" is ticked', 'example' => 'reward * 3'],
            'invite' => ['name' => 'invite', 'description' => 'Number of qualified invitees (deduplicated by IP/phone/name)', 'example' => 'invite * 10'],
            'win_lose' => ['name' => 'win_lose', 'description' => 'Net loss of direct downlines last week (positive number)', 'example' => 'win_lose * 0.1'],
            'rand' => ['name' => 'rand(a, b)', 'description' => 'Random value between a and b (2 decimals)', 'example' => 'rand(10, 500)'],
        ],
        'keys' => [
            'max_join' => ['name' => 'max_join', 'type' => 'integer', 'description' => 'Site-wide participation cap (records; operator-quit records excluded)', 'example' => '{"max_join": 100}'],
            'realname' => ['name' => 'realname', 'type' => 'integer', 'description' => '1 = real-name verification required to join', 'example' => '{"realname": 1}'],
            'invite' => ['name' => 'invite', 'type' => 'integer', 'description' => 'Number of qualified invitees required', 'example' => '{"invite": 3}'],
            'invite_deposit' => ['name' => 'deposit', 'type' => 'number', 'description' => 'An invitee counts only with at least one deposit of this face value or more', 'example' => '{"invite": 3, "deposit": 50}'],
            'dayOfWeek' => ['name' => 'dayOfWeek', 'type' => 'integer', 'description' => '1-7: last weekday of the week the reward can be claimed (1 = Monday); 0 or unset = accumulate since the last participation or event start', 'example' => '{"dayOfWeek": 7}'],
            'main_wallet_deduction' => ['name' => 'main_wallet_deduction', 'type' => 'number', 'description' => 'Amount moved from the main wallet into the event wallet at join; unset = the whole deposit, 0 = nothing (ignored when payout is score)', 'example' => '{"main_wallet_deduction": 0}'],
            'limit' => ['name' => 'limit', 'type' => 'number', 'description' => 'Reward cap: a formula result above this is lowered to it', 'example' => '{"limit": 300}'],
            'balance_multiple' => ['name' => 'balance_multiple', 'type' => 'number', 'description' => 'Payout multiplier: claimable = event wallet balance x this value, then capped by the withdrawal limit formula', 'example' => '{"balance_multiple": 1}'],
            'expire' => ['name' => 'expire', 'type' => 'integer', 'description' => 'Score validity in hours; expiry rounds up to the next 10-minute mark; unset = never expires', 'example' => '{"expire": 720}'],
            'score_limit' => ['name' => 'limit', 'type' => 'number', 'description' => 'Score cap: a result above this is lowered to it', 'example' => '{"expire": 720, "limit": 300}'],
            'score_tiers' => ['name' => 'tiers', 'type' => 'array', 'description' => 'Tiers: top-down, the first condition that holds gives the score; order thresholds from high to low; leave the withdrawal limit formula blank when tiers is set', 'example' => '{"expire": 720, "tiers": [{"condition": "deposit >= 300", "score": 30}, {"condition": "deposit >= 100", "score": 10}]}'],
            // Angpow (target/tickets#4234): the two periods live in the "recurring" and "include period" fields, not here; only score is required
            'angpow_score' => ['name' => 'score', 'type' => 'number', 'description' => 'Score deducted per claim (required, positive; digits beyond 2 decimals are truncated). Score is the ticket: deducted at join, never refunded on quit or reset', 'example' => '{"score": 10}'],
            'angpow_max_join' => ['name' => 'max_join', 'type' => 'integer', 'description' => 'Site-wide claim count: blocks once the joins within the "include period" (operator-quit excluded) reach it; resets each period, no period = whole event', 'example' => '{"score": 10, "max_join": 50}'],
            'angpow_max_amount' => ['name' => 'max_amount', 'type' => 'number', 'description' => 'Site-wide total amount: blocks once the amount issued within the "include period" (sum of amounts drawn at join, operator-quit excluded) reaches it, resets each period; below it a claim is allowed and paid in full, so the last packet may push the total over', 'example' => '{"score": 10, "max_amount": 500000}'],
            'angpow_member_times' => ['name' => 'member_times', 'type' => 'integer', 'description' => 'Max claims per member within the "recurring" period; no period = whole event', 'example' => '{"score": 10, "member_times": 5}'],
            'angpow_member_amount' => ['name' => 'member_amount', 'type' => 'number', 'description' => 'Max amount per member within the "recurring" period (sum of amounts drawn at join); blocks once reached', 'example' => '{"score": 10, "member_amount": 388}'],
            'angpow_interval' => ['name' => 'interval', 'type' => 'integer', 'description' => 'Minimum seconds between two claims by the same member', 'example' => '{"score": 10, "interval": 300}'],
            'angpow_time_window' => ['name' => 'time_window', 'type' => 'array', 'description' => 'Daily claim window ["from", "to"] in HH:MM, entered in backend time (currently +8); stored as UTC and checked against system time (08:00-23:00 is stored as 00:00-15:00 and shown back as entered when editing); from later than to means overnight; unset = all day', 'example' => '{"score": 10, "time_window": ["08:00", "23:00"]}'],
        ],
        'arrays' => [
            'range_reward' => ['name' => '[{"reward", "condition"}]', 'type' => 'array', 'description' => 'Top-down per entry: add reward to the total first, then test condition and stop when it holds (a 120 deposit against the example yields 6.99 + 3 + 7)', 'example' => '[{"reward": 6.99, "condition": "deposit < 50"}, {"reward": 3, "condition": "deposit < 100"}, {"reward": 7, "condition": "deposit < 300"}, {"reward": 23, "condition": "deposit >= 300"}]'],
            'combo_condition' => ['name' => '[{"count", "deposit"}]', 'type' => 'array', 'description' => 'Qualifies when today has at least count completed orders of exactly the deposit face value; any one entry holding is enough', 'example' => '[{"count": 1, "deposit": "10"}, {"count": 2, "deposit": "50"}]'],
            'combo_reward' => ['name' => '[{"reward", "condition": {"count", "deposit"}}]', 'type' => 'array', 'description' => 'Each entry is tested on its own; rewards of all entries that hold are summed', 'example' => '[{"reward": 5, "condition": {"count": 1, "deposit": 10}}, {"reward": 30, "condition": {"count": 2, "deposit": 50}}]'],
            'combo_balance' => ['name' => '[{"balance", "condition": {"count", "deposit"}}]', 'type' => 'array', 'description' => 'Required, one entry per combo: the balance threshold is the sum of balance over the entries that hold; use 0 for no threshold, leaving it empty errors', 'example' => '[{"balance": 50, "condition": {"count": 1, "deposit": 10}}, {"balance": 100, "condition": {"count": 2, "deposit": 50}}]'],
        ],
    ],
];
