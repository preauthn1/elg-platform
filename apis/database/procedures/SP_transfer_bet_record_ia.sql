CREATE PROCEDURE `SP_transfer_bet_record_ia`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME,
    IN p_IsRecalculated INT
)
BEGIN
    #如果要重算特定時間(天or小時)，EX: 2020/06/01 10點資料，p_StartTime = '2020/06/01 10:00:00', p_EndTime = '2020/06/01 10:59:59'
    #p_IsRecalculated: 是否是從新計算，0 or null = 否，1 = 是
    #一般呼叫方式: CALL SP_transfer_bet_record_ia(NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_ia('2020-05-30 10:00:00','2020-05-30 10:59:59',1);
    #
    # 2021/02/19 Mars 		增加ia_daily_win_losses是另一個ia_bet_records

    DECLARE StartTime, EndTime, ExecStart, ExecEnd DATETIME;
    DECLARE VendorID INT DEFAULT 21;
    DECLARE VendorCode VARCHAR(10) DEFAULT 'ia';
    DECLARE IsRecalculated INT DEFAULT 0;
    DECLARE ValidBetRate DECIMAL(4, 2) DEFAULT 0;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_transfer_bet_record_ia(',
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
        SET StartTime = IFNULL((SELECT stat_end_time FROM summary_control_tables WHERE vendor_id = VendorID), CURDATE());
    ELSE
        #如果有指定起始時間，往前推一秒(因為後續該秒不會被納入計算範圍)
        SET StartTime = DATE_SUB(p_StartTime, INTERVAL 1 SECOND);
    END IF;

    IF p_EndTime IS NULL THEN
        #取當前最後一筆訂單時間
        SET EndTime = IFNULL((SELECT MAX(updated_at) FROM ia_daily_win_losses WHERE updated_at > StartTime), StartTime);
    ELSE
        SET EndTime = p_EndTime;
    END IF;

    SELECT valid_bet_rate INTO ValidBetRate FROM vendors WHERE id = VendorID;

    # daily_win是另一個bet_record
    INSERT INTO total_bet_records(
        summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no,
        bet, valid_bet, win_lose, bet_time, payout_time, valid_bet_rate
    )
    SELECT DATE_ADD(DATE(DATE_SUB(b.bonus_time, INTERVAL 8 HOUR)), INTERVAL HOUR(DATE_SUB(b.bonus_time, INTERVAL 8 HOUR)) HOUR) AS summary_time
         , v.member_id AS member_id
         , vi.vendor_id AS vendor_id
         , b.id as bet_record_id
         , IFNULL(g.id, 0) AS game_id
         , IFNULL(b.order_id, '') AS reference_no
         , IFNULL(b.amount, 0) AS bet
         , IFNULL(b.amount, 0) AS valid_bet
         , IFNULL((b.win_lose), 0) AS win_lose
         , DATE_SUB(b.create_time, INTERVAL 8 HOUR) AS bet_time
         , b.utc_update_time AS payout_time
         , COALESCE(vig.valid_bet_rate, g.valid_bet_rate, ValidBetRate) AS valid_bet_rate
    FROM ia_daily_win_losses AS b
             JOIN ia_accounts AS v
                  ON b.username = v.username AND v.member_id > 0
             JOIN vendor_infos AS vi
                  ON vi.id = v.vendor_info_id AND vi.vendor_id = VendorID
             LEFT JOIN games as g
                       ON g.vendor_id = vi.vendor_id AND g.game_type = b.game_type_id
             LEFT JOIN vendor_info_games AS vig
                       ON vig.vendor_info_id = v.vendor_info_id AND vig.game_id = g.id
    WHERE b.updated_at BETWEEN StartTime and EndTime
      AND b.is_getprize = 1
    ON DUPLICATE KEY UPDATE
        game_id = IFNULL(g.id, 0)
                          , bet = CASE WHEN is_cancel = 1 THEN 0 ELSE IFNULL(b.amount, 0) END
                          , valid_bet = CASE WHEN is_cancel = 1 THEN 0 ELSE IFNULL(b.amount, 0) END
                          , win_lose = CASE WHEN is_cancel = 1 THEN 0 ELSE IFNULL(b.win_lose, 0) END
                          , bet_time = DATE_SUB(b.create_time, INTERVAL 8 HOUR)
                          , payout_time = b.utc_update_time
                          , valid_bet_rate = COALESCE(vig.valid_bet_rate, g.valid_bet_rate, ValidBetRate);

    SET ExecEnd = NOW();

    #更新控制表
    CALL COM_Update_Control_Table(VendorID, VendorCode, IsRecalculated, StartTime, EndTime, ExecStart, ExecEnd, NULL);

END
