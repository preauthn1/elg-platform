CREATE PROCEDURE `SP_transfer_bet_record_evo2`(
    IN p_Vendor INT,
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME,
    IN p_IsRecalculated INT
)
BEGIN
    #如果要重算特定時間(天or小時)，EX: 2020/06/01 10點資料，p_StartTime = '2020/06/01 10:00:00', p_EndTime = '2020/06/01 10:59:59'
    #p_IsRecalculated: 是否是從新計算，0 or null = 否，1 = 是
    #一般呼叫方式: CALL SP_transfer_bet_record_evo2(NULL, NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_evo2(90, '2020-05-30 10:00:00','2020-05-30 10:59:59',1);

    # 2022/12/02 Mars 		修改成聚合(增加join vendor_infos)
    # 2023/06/30 Mars 		修正join games錯誤的問題
    # 2023/06/30 Mars 		排除type=0的數據

    DECLARE StartTime, EndTime, ExecStart, ExecEnd DATETIME;
    DECLARE VendorID INT DEFAULT IFNULL(p_Vendor, 57);
    DECLARE VendorCode VARCHAR(10) DEFAULT 'evo';
    DECLARE IsRecalculated INT DEFAULT 0;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_transfer_bet_record_evo2(',
                    IFNULL(p_Vendor, 'NULL'), ',',
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
        SET EndTime = IFNULL((SELECT MAX(updated_at) FROM evo_bet_records WHERE updated_at > StartTime), StartTime);
    ELSE
        SET EndTime = p_EndTime;
    END IF;

    #轉入時間段內數據
    INSERT INTO total_bet_records(
        summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no,
        bet, valid_bet, win_lose, bet_time, payout_time, valid_bet_rate
    )
    SELECT DATE_ADD(DATE(b.bet_time), INTERVAL HOUR(b.bet_time) HOUR) AS summary_time
         , c.member_id AS member_id
         , vi.vendor_id AS vendor_id
         , b.id as bet_record_id
         , IFNULL(g.id, 0) AS game_id
         , IFNULL(b.bet_id, '') AS reference_no #betid
         , IFNULL(b.bet, 0) AS bet
         , IFNULL(b.bet, 0) AS valid_bet
         , IFNULL(b.win_lose, 0) AS win_lose
         , b.bet_time AS bet_time
         , b.bet_time AS payout_time
         , COALESCE(vig.valid_bet_rate, g.valid_bet_rate, v.valid_bet_rate) AS valid_bet_rate
    FROM evo_bet_records AS b
             JOIN evo_accounts AS c
                  ON b.username = c.username AND c.member_id > 0
             JOIN vendor_infos AS vi
                  ON vi.id = c.vendor_info_id
             JOIN vendors AS v
                  ON v.id = vi.vendor_id
                      AND v.third_party_code = 'EVO'
                      AND v.id = VendorId
             LEFT JOIN games AS g
                       ON g.vendor_id = vi.vendor_id AND g.game_type = b.game_code
             LEFT JOIN vendor_info_games AS vig
                       ON vig.vendor_info_id = c.vendor_info_id AND vig.game_id = g.id
    WHERE b.updated_at BETWEEN StartTime AND EndTime
      AND JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.type')) != '0'
    ON DUPLICATE KEY UPDATE
        game_id = IFNULL(g.id, 0)
                          , bet = IFNULL(b.bet, 0)
                          , valid_bet = IFNULL(b.bet, 0)
                          , win_lose = IFNULL(b.win_lose,0)
                          , bet_time = b.bet_time
                          , payout_time = b.bet_time
                          , valid_bet_rate = COALESCE(vig.valid_bet_rate, g.valid_bet_rate, v.valid_bet_rate);

    SET ExecEnd = NOW();

    #更新控制表
    CALL COM_Update_Control_Table(VendorID, VendorCode, IsRecalculated, StartTime, EndTime, ExecStart, ExecEnd, NULL);

END
