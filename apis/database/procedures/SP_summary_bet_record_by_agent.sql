CREATE PROCEDURE `SP_summary_bet_record_by_agent`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME,
    IN p_VendorId INT(11)
)
BEGIN
    # 將Summary Bet Record的資料依代理及遊戲商做彙整
    #一般呼叫方式: CALL SP_summary_bet_record_by_agent(NULL, NULL, NULL);
    #重算呼叫方式: CALL SP_summary_bet_record_by_agent('2021-01-01 00:00:00', '2021-01-01 01:00:00', 0);
    #重算呼叫方式: CALL SP_summary_bet_record_by_agent('2021-01-01 00:00:00', '2021-01-01 01:00:00', 1);

    /*
    新增Event
    CREATE EVENT Event_Summary_Bet_Record_By_Agent
        ON SCHEDULE EVERY 1 DAY STARTS '2021-05-01 06:10:00' #系統時間UTC+0
        COMMENT '每天-彙整summary_bet_record'
        DO
            CALL SP_summary_bet_record_by_agent(NULL, NULL, NULL);
    */

    # 2020/12/14 Mars 			Add
    # 2020/12/14 Mars 			Add total_commission
    # 2021/04/13 Mars 			Add total_jackpot, total_v_jackpot, game_id
    # 2021/05/21 Mars 			改成區間並加上cost
    # 2021/06/15 Mars 			增加時間判斷0000-00-00 00:00:00 & p_VendorId非限定值
    # 2025/04/15 Mars 			移除時間判斷0000-00-00 00:00:00

    DECLARE AdminUser, VendorId INT DEFAULT 0;
    DECLARE StartTime, EndTime, ExecStart, ExecEnd, SummaryDate DATETIME;
    DECLARE Params TEXT;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_bet_record_by_agent(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'), ',',
                    IFNULL(p_VendorId, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET ExecStart = NOW();

    IF p_EndTime IS NULL THEN
        SET EndTime = DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 8 HOUR);
    ELSE
        SET EndTime = DATE_ADD(DATE(p_EndTime), INTERVAL HOUR(p_EndTime) HOUR);
    END IF;

    IF p_StartTime IS NULL THEN
        SET StartTime = DATE_SUB(EndTime, INTERVAL 1 DAY);
    ELSE
        SET StartTime = DATE_ADD(DATE(p_StartTime), INTERVAL HOUR(p_StartTime) HOUR);
    END IF;

    SET SummaryDate = DATE(StartTime);

    IF p_VendorId IS NULL OR p_VendorId = 2021 THEN
        SET VendorId = 0;
    END IF;

    SET Params = CONCAT(
        p_StartTime, '|', p_EndTime, '|', p_VendorId, '|',
        StartTime, '|', EndTime, '|', SummaryDate, '|',
        VendorId
                 );

    IF VendorId = 0 OR EXISTS (SELECT * FROM vendors WHERE id = VendorId) THEN
        INSERT INTO summary_bet_record_agents(
                                               summary_time, agent_id, vendor_id, game_type_id
                                             , total_bet, total_win_lose, total_jackpot, total_share_amount, total_commission
                                             , total_cost, total_v_jackpot, total_score, proportion, `admin`
        )
        SELECT z.summary_time, z.agent_id, z.vendor_id, z.game_type_id
             , z.total_bet, z.total_win_lose, z.total_jackpot, z.total_share_amount, z.total_commission
             , z.total_cost, z.total_v_jackpot, z.total_score, z.proportion, AdminUser
        FROM (
                 SELECT r.summary_time AS summary_time
                      , a.id AS agent_id
                      , r.vendor_id
                      , IFNULL(g.type_id, 0) AS game_type_id
                      , SUM(r.bet) AS total_bet
                      , SUM(r.win_lose) AS total_win_lose
                      , IFNULL((SUM(r.jackpot) + SUM(r.rolling_chip)), 0) AS total_jackpot
                      , IFNULL(SUM(r.share_amount), 0) AS total_share_amount
                      , IFNULL(SUM(r.commission), 0) AS total_commission
                      , SUM(r.cost) AS total_cost
                      , IFNULL(SUM(r.v_jackpot), 0) AS total_v_jackpot
                      , SUM(r.win_lose) + IFNULL(SUM(r.jackpot), 0) + IFNULL(SUM(r.share_amount), 0) + IFNULL(SUM(r.commission), 0) + SUM(r.cost) + IFNULL(SUM(r.v_jackpot), 0) AS total_score
                      , CASE r.vendor_id WHEN 4 THEN SUM(r.bet) ELSE (SUM(r.win_lose) + IFNULL(SUM(r.jackpot), 0) + IFNULL(SUM(r.share_amount), 0) + IFNULL(SUM(r.commission), 0) + SUM(r.cost) + IFNULL(SUM(r.v_jackpot), 0)) * a.proportion * -1 END AS proportion
                 FROM summary_bet_records AS r
                          JOIN members AS m ON m.id = r.member_id
                          JOIN agents AS a ON a.id = m.parent
                          LEFT JOIN games AS g ON g.id = r.game_id
                 WHERE r.summary_time < EndTime
                   AND (
                     (r.vendor_id NOT IN (4, 10, 23, 43, 45) AND r.summary_time >= StartTime)
                         OR
                     (r.vendor_id IN (4, 10, 23, 43, 45) AND r.summary_time >= SummaryDate)
                     )
                   AND (VendorId = 0 OR r.vendor_id = VendorId)
                 GROUP BY r.summary_time, a.id, r.vendor_id, g.type_id
                 ORDER BY r.summary_time, a.id, r.vendor_id
             ) AS z
        ON DUPLICATE KEY UPDATE total_bet = IFNULL(z.total_bet, 0)
                              , total_win_lose = IFNULL(z.total_win_lose, 0)
                              , total_jackpot = IFNULL(z.total_jackpot, 0)
                              , total_share_amount = IFNULL(z.total_share_amount, 0)
                              , total_commission = IFNULL(z.total_commission, 0)
                              , total_cost = IFNULL(z.total_cost, 0)
                              , total_v_jackpot = IFNULL(z.total_v_jackpot, 0)
                              , total_score = IFNULL(z.total_score, 0)
                              , proportion = IFNULL(z.proportion, 0);
    END IF;

    SET ExecEnd = NOW();

    #更新控制表
    CALL COM_Update_Control_Table(10001, 'summary_bet_records_agent', 0, StartTime, EndTime, ExecStart, ExecEnd, Params);

    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_summary_bet_record_by_agent(',
            IFNULL(p_StartTime, 'NULL'), ',',
            IFNULL(p_EndTime, 'NULL'), ',',
            IFNULL(p_VendorId, 'NULL'),
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
         );
END
