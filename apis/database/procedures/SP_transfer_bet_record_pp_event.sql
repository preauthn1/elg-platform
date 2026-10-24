CREATE PROCEDURE `SP_transfer_bet_record_pp_event`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME,
    IN p_IsRecalculated INT
)
BEGIN
    #如果要重算特定時間(天or小時)，EX: 2020/06/01 10點資料，p_StartTime = '2020/06/01 10:00:00', p_EndTime = '2020/06/01 10:59:59'
    #p_IsRecalculated: 是否是從新計算，0 or null = 否，1 = 是
    #一般呼叫方式: CALL SP_transfer_bet_record_pp_event(NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_pp_event('2020-05-30 10:00:00','2020-05-30 10:59:59',1);

    DECLARE StartTime, EndTime, ExecStart, ExecEnd DATETIME;
    DECLARE VendorID INT DEFAULT 54;
    DECLARE VendorCode VARCHAR(10) DEFAULT 'pp_event';
    DECLARE IsRecalculated INT DEFAULT 0;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_transfer_bet_record_pp_event(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'), ',',
                    IFNULL(p_IsRecalculated, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET ExecStart = NOW();
    SET IsRecalculated = IFNULL(p_IsRecalculated, 0);

    IF p_StartTime IS NULL THEN
        #取上次排程完成時間
        SET StartTime = IFNULL((SELECT stat_end_time FROM summary_control_tables WHERE vendor_id = VendorID), DATE_SUB(NOW(), INTERVAL 1 SECOND));
    ELSE
        #如果有指定起始時間，往前推一秒(因為後續該秒不會被納入計算範圍)
        SET StartTime = DATE_SUB(p_StartTime, INTERVAL 1 SECOND);
    END IF;

    IF p_EndTime IS NULL THEN
        #取當前最後一筆訂單時間
        SET EndTime = IFNULL((SELECT MAX(updated_at) FROM pp_event_records WHERE updated_at > StartTime), StartTime);
    ELSE
        SET EndTIme = p_EndTime;
    END IF;

    #轉入時間段內數據
    INSERT INTO total_bet_records(summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no, bet, valid_bet, win_lose, bet_time, payout_time, admin)
    SELECT DATE_ADD(DATE(b.pay_time), INTERVAL HOUR(b.pay_time) HOUR) AS summary_time
         , v.member_id AS member_id
         , VendorID AS vendor_id
         , b.id as bet_record_id
         , 0 AS game_id
         , IFNULL(b.bet_id, '') AS reference_no #betid
         , 0 AS bet
         , 0 AS valid_bet
         , IFNULL(b.prize_amount, 0) AS win_lose
         , b.pay_time AS bet_time
         , b.pay_time AS payout_time
         , 0 AS admin
    FROM pp_event_records AS b
             JOIN pp_accounts AS v ON b.username = v.username AND v.member_id > 0
    WHERE b.updated_at >= StartTime AND b.updated_at <= EndTime
    ON DUPLICATE KEY UPDATE win_lose = IFNULL(b.prize_amount,0)
                          , bet_time = b.pay_time
                          , payout_time = b.pay_time;

    SET ExecEnd = NOW();

    #更新控制表
    CALL COM_Update_Control_Table(5401, VendorCode, IsRecalculated, StartTime, EndTime, ExecStart, ExecEnd, NULL);

END
