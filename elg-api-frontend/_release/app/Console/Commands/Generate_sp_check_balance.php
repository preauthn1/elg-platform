<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Generate_sp_check_balance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate_sp_check_balance {vendor_key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自動產生 check balance store procedure';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     * 產出檢查轉帳記錄的SP程序
     * 自動產出的 store procedure 需要人工優化
     * @return mixed
     * @throws $e
     */
    public function handle()
    {
        //
        $vendor_key = $this->argument('vendor_key');
        $data = $this->get_data($vendor_key);
        if (!$data) {
            return;
        }
        $generated = \Blade::compileString($this->get_sql_template());
        ob_start() and extract($data, EXTR_SKIP);

        try
        {
            eval('?>'.$generated);
        }
        catch (\Exception $e)
        {
            ob_get_clean(); throw $e;
        }

        $content = ob_get_clean();

        echo htmlspecialchars_decode($content);
        return $content;
    }

    private function get_data($vendor_key) {
        $datas = array(
            'ag' => array(
                "vendor_id" => 1, //遊戲商代碼
                "sp_name" => "sp_check_balance_ag", //sp名稱
                "account_table_name" => "ag_accounts", //遊戲商帳號資料表
                "account_field_player_name" => "username", //遊戲商帳號欄位
                "account_field_member_id" => "member_id", //遊戲商帳號用戶ID欄位
                "bet_records_table_name" => "ag_bet_records", //遊戲商注單資料表
                "bet_records_field_player_name" => "player_name", //遊戲商注單帳號欄位
                "bet_records_field_bet_time" => "utc_bet_time", //遊戲商注單下注時間欄位
                "bet_records_field_payout_time" => "utc_recalcu_time", //遊戲商注單派彩時間欄位
                "bet_records_field_bet" => "bet_amount", //遊戲商注單下注金額欄位
                "bet_records_field_winlose" => "net_amount", //遊戲商注單輸贏金額欄位
            ),
            'wm' => array(
                "vendor_id" => 16, //遊戲商代碼
                "sp_name" => "sp_check_balance_wm", //sp名稱
                "account_table_name" => "wm_accounts", //遊戲商帳號資料表
                "account_field_player_name" => "user_id", //遊戲商帳號欄位
                "account_field_member_id" => "member_id", //遊戲商帳號用戶ID欄位
                "bet_records_table_name" => "wm_bet_records", //遊戲商注單資料表
                "bet_records_field_player_name" => "external_user_id", //遊戲商注單帳號欄位
                "bet_records_field_bet_time" => "utc_start_time", //遊戲商注單下注時間欄位
                "bet_records_field_payout_time" => "utc_end_time", //遊戲商注單派彩時間欄位
                "bet_records_field_bet" => "(bet_amount+jack_pot_bet)", //遊戲商注單下注金額欄位
                "bet_records_field_winlose" => "((win_amount+jack_pot_win) - (bet_amount+jack_pot_bet))", //遊戲商注單輸贏金額欄位
            ),
            'isb' => array(
                "vendor_id" => 14, //遊戲商代碼
                "sp_name" => "sp_check_balance_isb", //sp名稱
                "account_table_name" => "isb_accounts", //遊戲商帳號資料表
                "account_field_player_name" => "player_id", //遊戲商帳號欄位
                "account_field_member_id" => "member_id", //遊戲商帳號用戶ID欄位
                "bet_records_table_name" => <<<table
(SELECT 
    `xsgcasino`.`isb_bet_records`.`roundid` AS `roundid`,
    `xsgcasino`.`isb_bet_records`.`playerid` AS `playerid`,
    SUM(CASE
        WHEN `xsgcasino`.`isb_bet_records`.`type` = "BET" THEN `xsgcasino`.`isb_bet_records`.`amount`
        ELSE 0
    END) AS `bet`,
    SUM(CASE
        WHEN `xsgcasino`.`isb_bet_records`.`type` = "WIN" THEN `xsgcasino`.`isb_bet_records`.`amount`
        ELSE 0
    END) AS `win`,
    MAX(CASE
        WHEN `xsgcasino`.`isb_bet_records`.`type` = "BET" THEN `xsgcasino`.`isb_bet_records`.`time`
        ELSE NULL
    END) AS `bet_time`,
    MAX(CASE
        WHEN `xsgcasino`.`isb_bet_records`.`type` = "WIN" THEN `xsgcasino`.`isb_bet_records`.`time`
        ELSE NULL
    END) AS `payout_time`
FROM
    `xsgcasino`.`isb_bet_records`
WHERE `time` BETWEEN \$prev_time AND \$curr_time
GROUP BY `xsgcasino`.`isb_bet_records`.`roundid`) as bet_records
table, //遊戲商注單資料表
                "bet_records_field_player_name" => "playerid", //遊戲商注單帳號欄位
                "bet_records_field_bet_time" => "bet_time", //遊戲商注單下注時間欄位
                "bet_records_field_payout_time" => "payout_time", //遊戲商注單派彩時間欄位
                "bet_records_field_bet" => "bet", //遊戲商注單下注金額欄位
                "bet_records_field_winlose" => "(win-bet)", //遊戲商注單輸贏金額欄位
            )
        );
        return $datas[$vendor_key];
    }

    public function get_sql_template() {
        return <<<'SQL'
CREATE PROCEDURE `{{$sp_name}}`(IN in_date date)
BEGIN
    -- 宣告當日有轉帳記錄用戶的循環
    -- DECLARE in_date date default str_to_date('2020-04-20', '%Y-%m-%d');
    DECLARE $vendor_id INT DEFAULT {{$vendor_id}};
    DECLARE $member_id INT;
    DECLARE $player_name VARCHAR(191);
    DECLARE $cursor1_done INT DEFAULT 0;
    DECLARE $member_count INT DEFAULT 0;
    DECLARE $transation_count INT DEFAULT 0;
    DECLARE $cursor1 CURSOR FOR SELECT member_id FROM transaction_logs
        WHERE vendor_id = $vendor_id AND created_at BETWEEN in_date AND DATE_ADD(in_date, INTERVAL 1 DAY) GROUP BY member_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET $cursor1_done = 1;
    -- 暫存用回傳表
    DROP TEMPORARY TABLE IF EXISTS temp_results;
	CREATE TEMPORARY TABLE temp_results
		(member_id INT, player_name VARCHAR(191), check_sum INT, winlose INT, diff INT, prev_time DATETIME, curr_time DATETIME);    
    OPEN $cursor1;
    read_loop:LOOP
        FETCH $cursor1 INTO $member_id;
        -- 獲取用戶遊戲帳號
        SELECT {{$account_field_player_name}} INTO $player_name FROM {{$account_table_name}} WHERE {{$account_field_member_id}} = $member_id LIMIT 1;
        DROP TEMPORARY TABLE IF EXISTS temp_records;
        CREATE TEMPORARY TABLE temp_records
            (rownum INT, before_balance DECIMAL(10,2), vendor_balance DECIMAL(10,2), created_at DATETIME);
        -- 取出用戶前一天至後一天轉帳記錄存到暫存表
        SET @start_date = DATE_ADD(in_date, INTERVAL -1 DAY);
        SET @end_date = DATE_ADD(in_date, INTERVAL 2 DAY);
        INSERT INTO temp_records (rownum, before_balance, vendor_balance, created_at)
        SELECT @rownum := @rownum + 1, before_balance, vendor_balance, created_at FROM (
            SELECT CASE `type`
                WHEN 1 THEN vendor_balance - `point`
                WHEN 2 THEN vendor_balance + `point`
                ELSE 0 END AS before_balance, vendor_balance, created_at
            FROM transaction_logs WHERE vendor_id = $vendor_id AND member_id = $member_id AND `status`=1000 AND `type` in (1, 2) AND created_at BETWEEN @start_date AND @end_date ORDER BY created_at DESC
        ) AS temp ORDER BY created_at ASC;
        SET $member_count = $member_count + 1;
        SELECT COUNT(0) + $transation_count INTO $transation_count FROM temp_records;
        BEGIN
            -- 轉帳記錄循環
            DECLARE $prev_before_balance, $prev_balance, $curr_before_balance, $curr_balance DECIMAL(10,2) DEFAULT 0.00;
            DECLARE $prev_time, $curr_time DATETIME;
            DECLARE $cursor2_done INT DEFAULT 0;
            DECLARE $cursor2 CURSOR FOR SELECT before_balance, vendor_balance, created_at FROM temp_records;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET $cursor2_done = 1;
            OPEN $cursor2;
            FETCH $cursor2 INTO $prev_before_balance, $prev_balance, $prev_time;
            -- 循環完畢就退出
            IF $cursor1_done THEN
				LEAVE read_loop;
			END IF;
            temp_table_loop:LOOP
                FETCH $cursor2 INTO $curr_before_balance, $curr_balance, $curr_time;
                IF $cursor2_done THEN
                    LEAVE temp_table_loop;
                END IF;
                -- 取得時間段內投注記錄的輸贏加總
                SELECT
                    IFNULL(
                        CAST(
                            SUM(IF({{$bet_records_field_payout_time}} < $curr_time, {{$bet_records_field_winlose}}, -{{$bet_records_field_bet}}))
                            + SUM(IF({{$bet_records_field_bet_time}} < $prev_time, {{$bet_records_field_bet}}, 0)) AS DECIMAL(10,2)
                        ), 0)
                    INTO @check_sum
                FROM {{$bet_records_table_name}} WHERE {{$bet_records_field_player_name}}=$player_name AND ({{$bet_records_field_bet_time}} BETWEEN $prev_time AND $curr_time OR {{$bet_records_field_payout_time}} BETWEEN $prev_time AND $curr_time);
                SET @winlose = CAST($curr_before_balance - $prev_balance AS DECIMAL(10,2));
                SET @diff = ABS(ABS(@check_sum) - ABS(@winlose));
                IF @diff > 1 OR isnull(@check_sum) THEN
                    INSERT INTO temp_results (member_id , player_name , check_sum , winlose , diff , prev_time , curr_time) 
                    VALUE ($member_id, $player_name, @check_sum, @winlose, @diff, $prev_time, $curr_time);
                END IF;
                SET $prev_balance = $curr_balance;
                SET $prev_time = $curr_time;
            END LOOP temp_table_loop;
            CLOSE $cursor2;
        END;
    END LOOP;
    CLOSE $cursor1;
    SELECT $member_count, $transation_count;
    SELECT * FROM temp_results;
END
SQL;

    }
}
