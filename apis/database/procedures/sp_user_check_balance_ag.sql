CREATE PROCEDURE `sp_user_check_balance_ag`(IN in_date date)
BEGIN
    -- 宣告當日有轉帳記錄用戶的循環
    -- DECLARE in_date date default str_to_date('2020-04-20', '%Y-%m-%d');
    DECLARE $vendor_id INT DEFAULT 1;
    DECLARE $member_id INT;
    DECLARE $player_name VARCHAR(191);
    DECLARE $cursor1_done INT DEFAULT 0;
    DECLARE $member_count INT DEFAULT 0;
    DECLARE $transation_count INT DEFAULT 0;
    DECLARE $cursor1 CURSOR FOR SELECT member_id FROM transaction_logs
                                WHERE vendor_id = $vendor_id AND created_at BETWEEN in_date AND DATE_ADD(in_date, INTERVAL 1 DAY) GROUP BY member_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET $cursor1_done = 1;
    DROP TEMPORARY TABLE IF EXISTS temp_results;
    -- 暫存用回傳表
    CREATE TEMPORARY TABLE temp_results
    (member_id INT, player_name VARCHAR(191), check_sum INT, winlose INT, diff INT, prev_time DATETIME, curr_time DATETIME);
    OPEN $cursor1;
    read_loop:LOOP
        FETCH $cursor1 INTO $member_id;
        -- 獲取用戶遊戲帳號
        SELECT username INTO $player_name FROM ag_accounts WHERE member_id = $member_id LIMIT 1;
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
                                                                                           FROM transaction_logs WHERE vendor_id = $vendor_id AND member_id = $member_id AND `status`=1000 AND created_at BETWEEN @start_date AND @end_date ORDER BY created_at DESC
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
            temp_table_loop:LOOP
                FETCH $cursor2 INTO $curr_before_balance, $curr_balance, $curr_time;
                -- 取得時間段內投注記錄的輸贏加總
                SELECT
                    CAST(SUM(IF(utc_recalcu_time < $curr_time, net_amount, -bet_amount)) + SUM(IF(utc_bet_time < $prev_time, bet_amount, 0)) AS DECIMAL(10,2))
                INTO @check_sum
                FROM ag_bet_records WHERE player_name=$player_name AND (utc_bet_time BETWEEN $prev_time AND $curr_time OR utc_recalcu_time BETWEEN $prev_time AND $curr_time);
                SET @winlose = CAST($curr_before_balance - $prev_balance AS DECIMAL(10,2));
                SET @diff = ABS(ABS(@check_sum) - ABS(@winlose));
                IF @diff > 1 THEN
                    SELECT $member_id, $player_name, @check_sum, @winlose, @diff, $prev_time, $curr_time;
                    INSERT INTO temp_results (member_id , player_name , check_sum , winlose , diff , prev_time , curr_time)
                        VALUE ($member_id, $player_name, @check_sum, @winlose, @diff, $prev_time, $curr_time);
                    -- leave temp_table_loop;
                END IF;
                SET $prev_balance = $curr_balance;
                SET $prev_time = $curr_time;

                IF $cursor2_done THEN
                    LEAVE temp_table_loop;
                END IF;
            END LOOP temp_table_loop;
            CLOSE $cursor2;
        END;

        IF $cursor1_done THEN
            LEAVE read_loop;
        END IF;
    END LOOP;
    CLOSE $cursor1;
    SELECT $member_count, $transation_count;
    SELECT * FROM temp_results;
END
