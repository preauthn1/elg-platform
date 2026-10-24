CREATE PROCEDURE `SP_Job_Vendor_Hour_Record_By_Six_Hour`(
    IN p_DateHour DATETIME,
    IN p_Vendor_Id INT
)
BEGIN
    # 游戏记录每个人每个游戏商每六小时结算（排除真人游戏）
    # 取代原RD-Studio-01 SummaryVendorHourRecord
    # 原本只计算10分钟前的当下小时数据, 因部份注单回来超过10分,造成部份数据未被统计, 所以改成计算前二个小时
    # 一般呼叫方式: CALL SP_Job_Vendor_Hour_Record_By_Six_Hour(NULL,NULL);
    # 重算呼叫方式: CALL SP_Job_Vendor_Hour_Record_By_Six_Hour('2020-05-30 10:00:00', 1);

    # 2022/05/19 Mars			增加寫入最後投注時間

    /*
    新增Event
	CREATE EVENT Event_Job_Vendor_Hour_Record_By_Six_Hour
	ON SCHEDULE EVERY 6 HOUR STARTS '2021/06/01 00:00:00' #系統時間UTC+0
    COMMENT '每六小時-彙整游戏记录每个人每个游戏商结算'
    DO
		CALL SP_Job_Vendor_Hour_Record_By_Six_Hour(NULL, NULL);
	*/

    # 2021/06/08 Mars 			Add
    # 2023/04/24 Neil			投注額要扣除禮碼

    DECLARE NowTime, StartTime, EndTime DATETIME;
    DECLARE VendorId INT;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_Job_Vendor_Hour_Record_By_Six_Hour(',
                    IFNULL(p_DateHour, 'NULL'), ',',
                    IFNULL(p_Vendor_Id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    IF p_Vendor_Id IS NOT NULL THEN
        SET VendorId = p_Vendor_Id;
    END IF;

    IF p_DateHour IS NULL THEN
        SET NowTime = DATE_SUB(NOW(), INTERVAL 1 HOUR);
        SET EndTime = DATE_ADD(DATE(NowTime), INTERVAL (HOUR(NowTime)) HOUR);
        SET StartTime = DATE_ADD(EndTime, INTERVAL -6 HOUR);
    ELSE
        SET StartTime = DATE_ADD(DATE(p_DateHour), INTERVAL HOUR(p_DateHour) HOUR);
        SET EndTime = DATE_ADD(StartTime, INTERVAL 6 HOUR);
    END IF;

    INSERT INTO vendor_hour_records(
        date_hour, member_id, vendor_id, game_id,
        bet, bet_received, win, win_received, win_lose, win_lose_received, last_bet_time
    )
    SELECT t.summary_time, t.member_id, t.vendor_id, t.game_id,
           t.bet, 0, t.win, 0, t.win_lose, 0, t.last_bet_time
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
             WHERE (VendorId IS NULL OR vendor_id = VendorId)
               AND summary_time BETWEEN StartTime AND EndTime
             GROUP BY summary_time, vendor_id, member_id, game_id
         ) AS t
    ON DUPLICATE KEY UPDATE
                         bet = t.bet,
                         win = t.win,
                         win_lose = t.win_lose,
                         last_bet_time = t.last_bet_time;
END
