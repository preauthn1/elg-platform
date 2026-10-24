CREATE PROCEDURE `SP_transfer_bet_record_sbo`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME,
    IN p_IsRecalculated INT
)
BEGIN
    #如果要重算特定時間(天or小時)，EX: 2020/06/01 10點資料，p_StartTime = '2020/06/01 10:00:00', p_EndTime = '2020/06/01 10:59:59'
    #p_IsRecalculated: 是否是從新計算，0 or null = 否，1 = 是
    #一般呼叫方式: CALL SP_transfer_bet_record_sbo(NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_sbo('2020-05-30 10:00:00','2020-05-30 10:59:59',1);

    # 2025-04-15 Mars 		優化group by, keyword加上``

    DECLARE StartTime, EndTime, ExecStart, ExecEnd DATETIME;
    DECLARE VendorID INT DEFAULT 18;
    DECLARE VendorCode VARCHAR(10) DEFAULT 'sbo';
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
                    '[系統通知] SP_transfer_bet_record_sbo(',
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
        SET EndTime = IFNULL((SELECT MAX(updated_at) FROM sbo_bet_records WHERE updated_at > StartTime), StartTime);
    ELSE
        SET EndTIme = p_EndTime;
    END IF;

    SELECT valid_bet_rate INTO ValidBetRate FROM vendors WHERE id = VendorID;

    #轉入時間段內數據
    INSERT INTO total_bet_records(
        summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no,
        bet, valid_bet, win_lose, bet_time, payout_time, valid_bet_rate
    )
    SELECT DATE_ADD(DATE(t.create_time), INTERVAL HOUR(t.create_time) HOUR) AS summary_time
         , t.member_id AS member_id
         , t.vendor_id AS vendor_id
         , t.bet_record_id AS bet_record_id
         , t.game_id AS game_id
         , t.reference_no AS reference_no
         , t.bet AS bet
         , t.bet AS valid_bet
         , t.win_lose AS win_lose
         , t.create_time AS bet_time
         , t.create_time AS payout_time
         , t.valid_bet_rate AS valid_bet_rate
    FROM (
             SELECT max(b.id) AS bet_record_id
                  , v.member_id AS member_id
                  , ANY_VALUE(vi.vendor_id) AS vendor_id
                  , IFNULL(b.bet_id, '') AS reference_no
                  , ANY_VALUE(g.id) AS game_id
                  , IFNULL(SUM(bet_amount), 0) AS bet
                  , IFNULL(SUM(win_amount - bet_amount), 0) AS win_lose
                  , MAX(create_time) AS create_time
                  , COALESCE(vig.valid_bet_rate, g.valid_bet_rate, ValidBetRate) AS valid_bet_rate
             FROM (
                      SELECT id
                           , player_name
                           , game_code
                           , bet_id
                           , trans_type
                           , IFNULL((
                                        SELECT SUM(s.bet_amount) AS bet
                                        FROM sbo_bet_records AS s
                                        WHERE r.bet_id = s.bet_id
                                          AND s.create_time < r.create_time
                                        HAVING SUM(CASE WHEN s.trans_type = 'Payoff' THEN 1 ELSE 0 END) = 0
                                    ), 0) AS bet_amount, win_amount, create_time
                      FROM sbo_bet_records AS r
                      WHERE trans_type = 'Payoff'
                        AND updated_at BETWEEN StartTime AND EndTime
                      UNION ALL
                      SELECT id
                           , player_name
                           , game_code
                           , bet_id
                           , trans_type
                           , 0 AS bet_amount, win_amount, create_time
                      FROM sbo_bet_records AS r
                      WHERE trans_type = 'cancelPayoff'
                        AND updated_at BETWEEN StartTime AND EndTime
                  ) AS b
                      JOIN sbo_accounts AS v
                           ON b.player_name = v.username
                               AND v.member_id > 0
                      JOIN vendor_infos AS vi
                           ON vi.id = v.vendor_info_id
                               AND vi.vendor_id = VendorID
                      LEFT JOIN games AS g
                                ON g.vendor_id = vi.vendor_id
                                    AND b.game_code = g.game_type
                      LEFT JOIN vendor_info_games AS vig
                                ON vig.vendor_info_id = v.vendor_info_id
                                    AND vig.game_id = g.id
             GROUP BY v.member_id, b.bet_id
         ) AS t
    ON DUPLICATE KEY UPDATE
        game_id = t.game_id
                          , bet = t.bet
                          , valid_bet = t.bet
                          , win_lose = t.win_lose
                          , bet_time = t.create_time
                          , payout_time = t.create_time
                          , valid_bet_rate = t.valid_bet_rate;

    SET ExecEnd = NOW();

    #更新控制表
    CALL COM_Update_Control_Table(VendorID, VendorCode, IsRecalculated, StartTime, EndTime, ExecStart, ExecEnd, NULL);

END
