CREATE PROCEDURE `SP_transfer_bet_record_isb`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME,
    IN p_IsRecalculated INT
)
BEGIN
    #如果要重算特定時間(天or小時)，EX: 2020/06/01 10點資料，p_StartTime = '2020/06/01 10:00:00', p_EndTime = '2020/06/01 10:59:59'
    #p_IsRecalculated: 是否是從新計算，0 or null = 否，1 = 是
    #一般呼叫方式: CALL SP_transfer_bet_record_isb(NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_isb('2020-05-30 10:00:00','2020-05-30 10:59:59',1);

    # 2021-06-30 Mars 			修改計算方式
    # 2025-04-15 Mars 		優化group by, keyword加上``

    DECLARE StartTime, EndTime, ExecStart, ExecEnd DATETIME;
    DECLARE VendorID INT DEFAULT 14;
    DECLARE VendorCode VARCHAR(10) DEFAULT 'isb';
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
                    '[系統通知] SP_transfer_bet_record_isb(',
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
        SET EndTime = IFNULL((SELECT MAX(updated_at) FROM isb_bet_records WHERE updated_at > StartTime), StartTime);
    ELSE
        SET EndTime = p_EndTime;
    END IF;

    SELECT valid_bet_rate INTO ValidBetRate FROM vendors WHERE id = VendorID;

    #轉入時間段內數據
    INSERT INTO total_bet_records(
        summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no,
        bet, valid_bet, win_lose, bet_time, payout_time, valid_bet_rate
    )
    SELECT z.summary_time,
           z.member_id,
           z.vendor_id,
           z.bet_record_id,
           z.game_id,
           z.reference_no,
           z.bet,
           z.valid_bet,
           z.win_lose,
           z.bet_time,
           z.payout_time,
           z.valid_bet_rate
    FROM (
             SELECT DATE_ADD(DATE(b.bet_time), INTERVAL HOUR(b.bet_time) HOUR) AS summary_time
                  , v.member_id AS member_id
                  , vi.vendor_id AS vendor_id
                  , b.bet_record_id AS bet_record_id #以投注那單為基準
                  , IFNULL(g.id, 0) AS game_id
                  , IFNULL(b.roundid, '') AS reference_no
                  , b.bet AS bet
                  , b.bet AS valid_bet
                  , b.win_lose AS win_lose
                  , b.bet_time AS bet_time
                  , b.payout_time AS payout_time
                  , COALESCE(vig.valid_bet_rate, g.valid_bet_rate, ValidBetRate) AS valid_bet_rate
             FROM (
                      SELECT MIN(id) AS bet_record_id
                           , MIN(`time`) AS bet_time
                           , MAX(`time`) AS payout_time
                           , ANY_VALUE(playerid) AS playerid
                           , roundid
                           , ANY_VALUE(gameid) AS gameid
                           , SUM(IF(`type` = 'BET', (amount/100), 0)) AS bet
                           , SUM(IF(`type` = 'WIN', (amount/100), 0) - IF(`type`='BET' , (amount/100), 0)) AS win_lose
                      FROM isb_bet_records
                      WHERE `time` >= DATE_SUB(p_StartTime, INTERVAL 7 DAY)
                        AND roundid IN (
                          #先找出此次異動的round id
                          SELECT roundid
                          FROM isb_bet_records
                          WHERE updated_at BETWEEN StartTime AND EndTime
                          GROUP BY playerid, roundid, gameid
                      )
                      GROUP BY roundid
                  ) AS b
                      JOIN isb_accounts AS v
                           ON v.player_id = b.playerid
                               AND v.member_id > 0
                      JOIN vendor_infos AS vi
                           ON vi.id = v.vendor_info_id
                               AND vi.vendor_id = VendorID
                      LEFT JOIN games AS g
                                ON g.vendor_id = vi.vendor_id
                                    AND g.game_type = b.gameid
                      LEFT JOIN vendor_info_games AS vig
                                ON vig.vendor_info_id = v.vendor_info_id
                                    AND vig.game_id = g.id
         ) AS z
    ON DUPLICATE KEY UPDATE
        game_id = z.game_id
                          , bet = z.bet
                          , valid_bet = z.valid_bet
                          , win_lose = z.win_lose
                          , bet_time = z.bet_time
                          , payout_time = z.payout_time
                          , valid_bet_rate = z.valid_bet_rate;

    SET ExecEnd = NOW();

    #更新控制表
    CALL COM_Update_Control_Table(VendorID, VendorCode, IsRecalculated, StartTime, EndTime, ExecStart, ExecEnd, NULL);

END
