CREATE PROCEDURE `SP_Job_Vendor_Hour_Rerun`(
    IN p_Date DATE,
    IN p_Vendor_Id INT
)
BEGIN

    # SP_Job_Vendor_Hour_Record重跑
    # 一般呼叫方式: CALL SP_Job_Vendor_Hour_Rerun(NULL, NULL);
    # 重算呼叫方式: CALL SP_Job_Vendor_Hour_Rerun('2021-01-01', 22);

    # 2022/05/19 Mars			增加寫入最後投注時間

    DECLARE StartTime, EndTime DateTime;
    DECLARE VendorId INT;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_Job_Vendor_Hour_Rerun(',
                    IFNULL(p_Date, 'NULL'), ',',
                    IFNULL(p_Vendor_Id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    IF p_Vendor_Id IS NOT NULL THEN
        SET VendorId = p_Vendor_Id;
    END IF;

    IF (p_Date IS NULL) THEN
        SET StartTime = DATE_SUB(CURDATE(), INTERVAL 8 HOUR);
    ELSE
        SET StartTime = DATE_SUB(p_Date, INTERVAL 8 HOUR);
    END IF;

    SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);

    INSERT INTO vendor_hour_records(
        date_hour, member_id, vendor_id, game_id,
        bet, bet_received,
        win, win_received,
        win_lose, win_lose_received,
        last_bet_time
    )
    SELECT t.summary_time, t.member_id, t.vendor_id, t.game_id,
           t.bet, 0,
           t.win, 0,
           t.win_lose, 0,
           t.last_bet_time
    FROM (
             SELECT summary_time
                  , member_id
                  , vendor_id
                  , game_id
                  , TRUNCATE(sum(valid_bet), 2) AS bet
                  , TRUNCATE(sum(CASE WHEN win_lose > 0 THEN win_lose ELSE 0 END), 2) AS win
                  , TRUNCATE(sum(win_lose), 2) AS win_lose
                  , MAX(payout_time) AS last_bet_time
             FROM total_bet_records
             WHERE summary_time >= StartTime
               AND summary_time < EndTime
               AND (VendorId IS NULL OR vendor_id = VendorId)
             GROUP BY summary_time, member_id, vendor_id, game_id
         ) AS t
    ON DUPLICATE KEY UPDATE
                         bet = t.bet,
                         win = t.win,
                         win_lose = t.win_lose,
                         last_bet_time = t.last_bet_time;

END
