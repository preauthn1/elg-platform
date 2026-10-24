CREATE PROCEDURE `SP_summary_bet_record_three_hierarchy_by_agent`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME
)
BEGIN
    # 將Summary Bet Record的資料依代理及遊戲商做彙整
    #一般呼叫方式: CALL SP_summary_bet_record_three_hierarchy_by_agent(NULL, NULL);
    #重算呼叫方式: CALL SP_summary_bet_record_three_hierarchy_by_agent('2021-01-01 00:00:00', '2021-01-01 01:00:00');
    #重算呼叫方式: CALL SP_summary_bet_record_three_hierarchy_by_agent('2021-01-01 00:00:00', '2021-01-01 01:00:00');

    /*
    新增Event
    CREATE EVENT Event_Summary_Bet_Record_Three_Hierarchy_By_Agent
        ON SCHEDULE EVERY 1 DAY STARTS '2023-06-15 06:15:00' #系統時間UTC+0
        COMMENT '每天-彙整SR19V-Reward'
        DO
            CALL SP_summary_bet_record_vreward_by_agent(NULL, NULL);
    */

    DECLARE AdminUser, VendorId INT DEFAULT -5;
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
                    '[系統通知] SP_summary_bet_record_three_hierarchy_by_agent(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'),
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
                  , VendorId AS vendor_id
                  , 0 AS game_type_id
                  , 0 AS total_bet
                  , 0 AS total_win_lose
                  , SUM(r.rebate_amount) AS total_jackpot
                  , 0 AS total_share_amount
                  , 0 AS total_commission
                  , 0 AS total_cost
                  , 0 AS total_v_jackpot
                  , SUM(r.rebate_amount) AS total_score
                  , TRUNCATE(SUM(r.rebate_amount) * a.proportion * -1, 4) AS proportion
             FROM (
                      SELECT DATE_FORMAT(tha.created_at, '%Y-%m-%d %H:00:00') AS summary_time,
                             tha.agent_id AS id,
                             tha.rebate_amount AS rebate_amount
                      FROM three_hierarchy_bonus_report_agents AS tha
                      WHERE tha.created_at >= StartTime
                        AND tha.created_at < EndTime
                        AND tha.rebate_amount > 0
                      UNION
                      SELECT DATE_FORMAT(th.created_at, '%Y-%m-%d %H:00:00') AS summary_time,
                             a.id AS id,
                             (th.rebate + th.down_rebate + th.down2_rebate) AS rebate_amount
                      FROM three_hierarchy_bonus_report AS th
                               JOIN members AS m
                                    ON m.id = th.member_id
                               JOIN agents AS a
                                    ON a.id = m.parent
                               JOIN agent_customized_settings AS acs
                                    ON acs.agent_group_id = a.customized_setting_group_id
                                        AND acs.setting_key = 7002
                                        AND acs.`value` = 1
                      WHERE th.created_at >= StartTime
                        AND th.created_at < EndTime
                        AND ((th.rebate + th.down_rebate + th.down2_rebate) > 0)
                  ) AS r
                      JOIN agents AS a ON a.id = r.id
             GROUP BY r.id, r.summary_time
         ) AS z
    ON DUPLICATE KEY UPDATE
        total_jackpot = IFNULL(z.total_jackpot, 0)
                          , total_score = IFNULL(z.total_score, 0)
                          , proportion = IFNULL(z.proportion, 0);
END
