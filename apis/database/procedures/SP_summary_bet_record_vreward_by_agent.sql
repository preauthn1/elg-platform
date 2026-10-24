CREATE PROCEDURE `SP_summary_bet_record_vreward_by_agent`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME
)
BEGIN
    # 將Summary Bet Record的資料依代理及遊戲商做彙整
    #一般呼叫方式: CALL SP_summary_bet_record_vreward_by_agent(NULL, NULL);
    #重算呼叫方式: CALL SP_summary_bet_record_vreward_by_agent('2021-01-01 00:00:00', '2021-01-01 01:00:00');
    #重算呼叫方式: CALL SP_summary_bet_record_vreward_by_agent('2021-01-01 00:00:00', '2021-01-01 01:00:00');

    /*
    新增Event
    CREATE EVENT Event_Summary_Bet_Record_vreward_By_Agent
        ON SCHEDULE EVERY 1 DAY STARTS '2023-02-16 06:15:00' #系統時間UTC+0
        COMMENT '每天-彙整SR19V-Reward'
        DO
            CALL SP_summary_bet_record_vreward_by_agent(NULL, NULL);
    */


    DECLARE AdminUser, VendorId INT DEFAULT -2;
    DECLARE StartTime, EndTime, ExecStart, ExecEnd, SummaryDate DATETIME;
    DECLARE Params Text;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_bet_record_vreward_by_agent(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'), ',',
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
                  , r.id AS agent_id
                  , -2 AS vendor_id
                  , 0 AS game_type_id
                  , 0 AS total_bet
                  , 0 AS total_win_lose
                  , (SUM(r.bonus_direct) + SUM(r.bonus_team)) AS total_jackpot
                  , 0 AS total_share_amount
                  , 0 AS total_commission
                  , 0 AS total_cost
                  , 0 AS total_v_jackpot
                  , (SUM(r.bonus_direct) + SUM(r.bonus_team)) AS total_score
                  , TRUNCATE(((SUM(r.bonus_direct) + SUM(r.bonus_team)) * a.proportion * -1), 4) AS proportion
             FROM (
                      SELECT DATE_FORMAT(ua.created_at, '%Y-%m-%d %H:00:00') AS summary_time,
                             ua.agent_id AS id,
                             TRUNCATE(ua.bonus_direct, 2) AS bonus_direct,
                             TRUNCATE(ua.bonus_team, 2) AS bonus_team
                      FROM unlimit_bonusreport_agents AS ua
                      WHERE ua.created_at >= StartTime
                        AND ua.created_at < EndTime
                        AND (TRUNCATE(ua.bonus_direct, 2) > 0 OR TRUNCATE(ua.bonus_team, 2) > 0)
                      UNION
                      SELECT DATE_FORMAT(um.created_at, '%Y-%m-%d %H:00:00') AS summary_time,
                             a.id AS id,
                             TRUNCATE(um.bonus_direct, 2) AS bonus_direct,
                             TRUNCATE(um.bonus_team, 2) AS bonus_team
                      FROM unlimit_bonusreport AS um
                               JOIN members AS m ON m.id = um.member_id
                               JOIN agents AS a ON a.id = m.parent
                      WHERE um.created_at >= StartTime
                        AND um.created_at < EndTime
                        AND (TRUNCATE(um.bonus_direct, 2) > 0 or TRUNCATE(um.bonus_team, 2) > 0)
                  ) AS r
                      JOIN agents AS a ON a.id = r.id
             GROUP BY r.id, r.summary_time
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
END
