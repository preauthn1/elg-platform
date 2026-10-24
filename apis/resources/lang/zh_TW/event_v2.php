<?php

declare(strict_types=1);

use App\Enums\EventV2\EventV2ValidateCodeEnum;

return [
    'validation' => [
        EventV2ValidateCodeEnum::SUCCESS->value => '活動條件達成',
        EventV2ValidateCodeEnum::EVENT_NOT_EXIST->value => '活動不存在',
        EventV2ValidateCodeEnum::EVENT_MODE->value => '鎖分模式中不能參加活動',
        EventV2ValidateCodeEnum::ONLY_ONE_EVENT->value => '一次只能參加一個活動',
        EventV2ValidateCodeEnum::HAS_JOIN->value => '您已經參加過這個活動，無法再次參加',
        EventV2ValidateCodeEnum::DAILY->value => '您今天已經參加過這個活動，無法再次參加',
        EventV2ValidateCodeEnum::WEEKLY->value => '您這周已經參加過這個活動，無法再次參加',
        EventV2ValidateCodeEnum::MONTHLY->value => '您這個月已經參加過這個活動，無法再次參加',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_REGISTER->value => '此IP已經參加過活動',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V1_V2->value => '此IP已經參加過活動',
        EventV2ValidateCodeEnum::IP_HAS_JOIN_WITH_V2->value => '此IP已經參加過活動',
        EventV2ValidateCodeEnum::PHONE_NOT_VALIDATE->value => '請先綁定手機驗證',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_REGISTER->value => '此手機已經參加過活動',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V1_V2->value => '此手機已經參加過活動',
        EventV2ValidateCodeEnum::PHONE_HAS_JOIN_WITH_V2->value => '此手機已經參加過活動',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_REGISTER->value => '此帳號已經參加過活動',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V1_V2->value => '此帳號已經參加過活動',
        EventV2ValidateCodeEnum::ACCOUNT_HAS_JOIN_WITH_V2->value => '此帳號已經參加過活動',
        EventV2ValidateCodeEnum::MAX_JOIN_REACHED->value => '活動人數已達上限',
        EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH->value => '儲值尚未達到標準',
        EventV2ValidateCodeEnum::BALANCE_NOT_ENOUGH->value => '主錢包餘額不足',
        EventV2ValidateCodeEnum::OVER_JOIN_TIME->value => '已超過參加時間',
        EventV2ValidateCodeEnum::WIN_LOSE->value => '直屬下級會員結算不為輸分',

        // 歡迎獎金活動
        EventV2ValidateCodeEnum::EVENT_04_NOT_COMPLETE_EVENT_03->value => '您尚未完成"歡迎獎金50%"這個活動，請先完成',

        // 邀請好友活動
        EventV2ValidateCodeEnum::EVENT_13_INVITE_NOT_ENOUGH->value => '邀請人數不足，已邀請人數為 :invite',

        // 註冊活動
        EventV2ValidateCodeEnum::EVENT_01_NOT_IN_TIME->value => '註冊時間不再活動時間內',
        EventV2ValidateCodeEnum::EVENT_01_ONLY_ONE_TIMES->value => '註冊活動只能參加一次',
        EventV2ValidateCodeEnum::EVENT_01_REALNAME->value => '請先完成實名認證',

        // 首儲活動
        EventV2ValidateCodeEnum::DEPOSIT_ALREADY_AWARDED->value => '此筆儲值已被其他獎項領取，無法用於本活動',
        EventV2ValidateCodeEnum::DEPOSIT_ALREADY_USED->value => '此筆儲值已用於參加其他活動',

        'balance' => '主錢包餘額不足',
        'no_join' => '您尚未參加任何活動',
        'no_join_2' => '此玩家已結束活動',
        'wallet_balance' => '活動錢包餘額不足',
        'flow' => '流水不足',
        'score_expire_invalid' => '積分有效期須為正整數（小時），留空代表永久',
        'score_type_not_supported' => '每日區間儲值、每週累積儲值、每日儲值組合、紅包不提供積分領取，請改選其他活動類型或主錢包',
        'score_fields_not_allowed' => '積分型請留空獎勵公式／配置、餘額限制、稽核流水；積分數量填提款限制公式，有效期與上限填提款限制配置',
        'score_limit_invalid' => '提款限制配置的 limit（積分上限）須為數字',
        'score_formula_required' => '積分型請填積分數量：提款限制公式，或提款限制配置的 tiers 階梯',
        'score_amount_source_conflict' => '積分數量只能填一處：提款限制公式與 tiers 階梯不可同時設定',
        'score_tiers_invalid' => 'tiers 須為陣列，每組要有 condition（條件字串）與 score（數字或公式）',
        'main_wallet_config_keys_not_allowed' => '主錢包型的提款限制配置不使用 expire／limit／tiers，請移除或改選領取去向為積分',
        'withdraw_type_locked' => '領取去向建立後不可異動，要改請另建活動',
        'game_type' => '遊戲類別不符',

        // 紅包活動（target/tickets#4234）
        EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_SET->value => '此紅包尚未設定消耗積分，請聯繫客服',
        EventV2ValidateCodeEnum::ANGPOW_OUT_OF_TIME_WINDOW->value => '現在不在紅包開放時段',
        EventV2ValidateCodeEnum::ANGPOW_INTERVAL_NOT_REACHED->value => '領取間隔未到，請稍後再試',
        EventV2ValidateCodeEnum::ANGPOW_MEMBER_TIMES_REACHED->value => '已達本期個人領取次數上限',
        EventV2ValidateCodeEnum::ANGPOW_MEMBER_AMOUNT_REACHED->value => '已達本期個人領取金額上限',
        EventV2ValidateCodeEnum::ANGPOW_MAX_AMOUNT_REACHED->value => '紅包已發完',
        EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_ENOUGH->value => '積分不足',
        EventV2ValidateCodeEnum::TARGET_NOT_MATCHED->value => '您不符合此活動的參加資格',
        'target_type_required' => '已選擇目標用戶，請先選擇目標用戶類型',
        'target_ids_required' => '已選擇目標用戶類型，請至少選一個會員或群組',
        'target_ids_invalid' => '目標用戶中有不存在的會員或群組',
        'period_not_supported' => '只有紅包活動可以設定週期（可重複參加／包括期間），其他類型請留 0',
        'period_locked' => '活動週期建立後不可異動，要改請另建活動',
        'angpow_score_only' => '消耗積分（score）只有紅包活動可以設定',
        'angpow_score_required' => '紅包必須在條件配置設定 score（每抽一次扣幾分，正數）',
        'angpow_reward_formula_required' => '紅包必須填獎勵公式（每包抽到多少，例如 rand(10, 500) 或固定 20）',
        'angpow_config_invalid' => '條件配置的 :key 格式不正確',
        'not_angpow' => '此活動不是紅包',
    ],

    // 後台「設定條件」十格的參數說明彈窗（target/tickets#4231 需求 7）。
    // id 由各 handler 的 fieldParams() 引用；子目錄名對應 EventV2FieldParamKindEnum 的 value。
    // name 是畫面顯示的變數／key 名，type 是值的型別（變數不填）。
    'params' => [
        'variables' => [
            'deposit' => ['name' => 'deposit', 'description' => '觸發本次參加的那筆儲值面額（該幣種金額，不換匯）', 'example' => 'deposit >= 20'],
            'reward' => ['name' => 'reward', 'description' => '本次算出的獎勵金額（獎勵公式／配置的結果）；勾「參加即領獎」時三個限制公式不會被檢查', 'example' => 'reward * 3'],
            'invite' => ['name' => 'invite', 'description' => '合格邀請人數（依 IP／手機／姓名去重後）', 'example' => 'invite * 10'],
            'win_lose' => ['name' => 'win_lose', 'description' => '直屬下線上一週淨輸的金額（正數）', 'example' => 'win_lose * 0.1'],
            'rand' => ['name' => 'rand(a, b)', 'description' => 'a～b 之間隨機取值（小數兩位）', 'example' => 'rand(10, 500)'],
        ],
        'keys' => [
            'max_join' => ['name' => 'max_join', 'type' => 'integer', 'description' => '全站參加上限（筆數，不含後台操作放棄）', 'example' => '{"max_join": 100}'],
            'realname' => ['name' => 'realname', 'type' => 'integer', 'description' => '1＝需完成實名認證才能參加', 'example' => '{"realname": 1}'],
            'invite' => ['name' => 'invite', 'type' => 'integer', 'description' => '需邀請的合格人數', 'example' => '{"invite": 3}'],
            'invite_deposit' => ['name' => 'deposit', 'type' => 'number', 'description' => '被邀請人至少要有一筆此面額以上的儲值才算合格', 'example' => '{"invite": 3, "deposit": 50}'],
            'dayOfWeek' => ['name' => 'dayOfWeek', 'type' => 'integer', 'description' => '1～7 當週可領取的截止星期（1＝週一）；0 或不設＝累計到上次參加或活動開始', 'example' => '{"dayOfWeek": 7}'],
            'main_wallet_deduction' => ['name' => 'main_wallet_deduction', 'type' => 'number', 'description' => '參加時從主錢包扣進活動錢包的金額；不設＝扣整筆儲值，0＝不扣（領取去向＝積分時忽略）', 'example' => '{"main_wallet_deduction": 0}'],
            'limit' => ['name' => 'limit', 'type' => 'number', 'description' => '獎勵封頂：公式算出超過就壓到這個數', 'example' => '{"limit": 300}'],
            'balance_multiple' => ['name' => 'balance_multiple', 'type' => 'number', 'description' => '領取倍數：可領金額＝活動錢包餘額 × 此值，再受提款限制公式封頂', 'example' => '{"balance_multiple": 1}'],
            'expire' => ['name' => 'expire', 'type' => 'integer', 'description' => '積分有效期（小時），到期時間進位到下一個 10 分鐘；不設＝永久', 'example' => '{"expire": 720}'],
            'score_limit' => ['name' => 'limit', 'type' => 'number', 'description' => '積分上限：算出超過就壓到這個數', 'example' => '{"expire": 720, "limit": 300}'],
            'score_tiers' => ['name' => 'tiers', 'type' => 'array', 'description' => '階梯：由上往下第一個 condition 成立的那組就是積分數，門檻由高往低排；有 tiers 時提款限制公式須留空', 'example' => '{"expire": 720, "tiers": [{"condition": "deposit >= 300", "score": 30}, {"condition": "deposit >= 100", "score": 10}]}'],
            // 紅包（target/tickets#4234）：兩個週期在「可重複參加」「包括期間」欄位不在這裡；只有 score 必填，其他不填＝不限制
            'angpow_score' => ['name' => 'score', 'type' => 'number', 'description' => '每抽一次扣幾分（必填、正數；超過 2 位小數無條件捨去）。積分是門票：參加當下扣、放棄或作廢都不退', 'example' => '{"score": 10}'],
            'angpow_max_join' => ['name' => 'max_join', 'type' => 'integer', 'description' => '全站總次數：「包括期間」內的參加筆數（不含後台操作放棄）達到就擋；期一過重新計，不分週期＝整檔活動總共', 'example' => '{"score": 10, "max_join": 50}'],
            'angpow_max_amount' => ['name' => 'max_amount', 'type' => 'number', 'description' => '全站總金額：「包括期間」內已發出（參加當下抽到的金額加總，不含後台操作放棄）達到就擋，期一過重新計；沒到就能抽、抽到多少給多少，最後一包可能讓總額超過', 'example' => '{"score": 10, "max_amount": 500000}'],
            'angpow_member_times' => ['name' => 'member_times', 'type' => 'integer', 'description' => '每人在「可重複參加」週期內最多抽幾次；不分週期＝整檔活動總共', 'example' => '{"score": 10, "member_times": 5}'],
            'angpow_member_amount' => ['name' => 'member_amount', 'type' => 'number', 'description' => '每人在「可重複參加」週期內最多抽到多少（參加當下抽到的金額加總），到了直接擋', 'example' => '{"score": 10, "member_amount": 388}'],
            'angpow_interval' => ['name' => 'interval', 'type' => 'integer', 'description' => '同一人兩次抽之間至少隔幾秒', 'example' => '{"score": 10, "interval": 300}'],
            'angpow_time_window' => ['name' => 'time_window', 'type' => 'array', 'description' => '每天可抽的時段 ["起", "迄"]，HH:MM，填後台時間（目前 +8）；系統轉成 UTC 存、以系統時間判斷（填 08:00～23:00 會存成 00:00～15:00，編輯時轉回原值顯示）；起晚於迄視為跨夜；不設＝全天', 'example' => '{"score": 10, "time_window": ["08:00", "23:00"]}'],
        ],
        'arrays' => [
            'range_reward' => ['name' => '[{"reward", "condition"}]', 'type' => 'array', 'description' => '由上往下逐組：先把 reward 加進總獎勵，再判斷 condition，成立就停（存 120 對範例得 6.99＋3＋7）', 'example' => '[{"reward": 6.99, "condition": "deposit < 50"}, {"reward": 3, "condition": "deposit < 100"}, {"reward": 7, "condition": "deposit < 300"}, {"reward": 23, "condition": "deposit >= 300"}]'],
            'combo_condition' => ['name' => '[{"count", "deposit"}]', 'type' => 'array', 'description' => '今天面額剛好等於 deposit 的完成訂單達 count 筆即符合，任一組成立即可參加', 'example' => '[{"count": 1, "deposit": "10"}, {"count": 2, "deposit": "50"}]'],
            'combo_reward' => ['name' => '[{"reward", "condition": {"count", "deposit"}}]', 'type' => 'array', 'description' => '每組獨立判斷，成立的獎勵全部相加', 'example' => '[{"reward": 5, "condition": {"count": 1, "deposit": 10}}, {"reward": 30, "condition": {"count": 2, "deposit": 50}}]'],
            'combo_balance' => ['name' => '[{"balance", "condition": {"count", "deposit"}}]', 'type' => 'array', 'description' => '必填、每組一筆：成立那幾組的 balance 相加就是餘額門檻；不設門檻填 0，空著會出錯', 'example' => '[{"balance": 50, "condition": {"count": 1, "deposit": 10}}, {"balance": 100, "condition": {"count": 2, "deposit": 50}}]'],
        ],
    ],
];
