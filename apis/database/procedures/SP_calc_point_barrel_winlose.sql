CREATE PROCEDURE `SP_calc_point_barrel_winlose`(IN p_summary_date DATE)
BEGIN

    # 分桶當日業績計算
    # call SP_calc_point_barrel_winlose(null);
    # call SP_calc_point_barrel_winlose('2021-01-01');
    #
    # 2021/01/06 Mars		Add
    # 2021/04/14 Mars		不限定是第二層(總代理)
    # 2021/05/21 Mars		篩選summary_bet_record_agents改採區間

    DECLARE SummaryTime, StartTime, EndTime DATETIME;
    DECLARE PB INT DEFAULT 130;
    DECLARE PointBarrel INT DEFAULT 6100;
    DECLARE GeneralAgent INT DEFAULT 6101;
    DECLARE Operator INT DEFAULT 51;

    IF p_summary_date IS NULL THEN
        SET SummaryTime = DATE_ADD(CURDATE(), INTERVAL -2 DAY);
    ELSE
        SET SummaryTime = DATE_ADD(p_summary_date, INTERVAL -1 DAY);
    END IF;

    SET SummaryTime = DATE_ADD(DATE(SummaryTime), INTERVAL 16 hour);
    SET StartTime = SummaryTime;
    SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);

    # 計算當日總代業績
    INSERT INTO summary_point_barrel_winloses(summary_time, agent_id, vendor_id, turnover, game_spending, game_profit, memo, admin)
    SELECT SummaryTime
         , x.agent_id
         , x.vendor_id
         , x.turnover
         , x.game_spending
         , x.game_profit
         , '' AS memo
         , 51 AS admin
    FROM (
             SELECT a1.agent_id
                  , 0 AS vendor_id
                  , SUM(sbra.total_bet) AS turnover
                  , SUM(sbra.total_bet) - (SUM(sbra.total_win_lose) * -1) AS game_spending
                  , 0 AS game_profit
             FROM (
                      SELECT agent_id, vendor_id, SUM(total_bet) AS total_bet, SUM(total_win_lose) AS total_win_lose
                      FROM summary_bet_record_agents
                      WHERE summary_time >= StartTime
                        AND summary_time < EndTime
                      GROUP BY agent_id, vendor_id
                  ) AS sbra
                      JOIN agents AS a ON a.id = sbra.agent_id
                      JOIN (
                 SELECT a.id AS agent_id, a.hierarchy_path
                 FROM agents AS a
                          JOIN agent_groups AS ag on ag.id = a.customized_setting_group_id AND ag.agent_group_type = 1
                          JOIN agent_customized_settings AS acs ON acs.agent_group_id = ag.id AND acs.setting_key = PointBarrel AND acs.value = 1
                 WHERE a.id IN (
                     SELECT a.id
                     FROM (
                              SELECT t.agent_group_id
                                   , MAX(t.agent_point_barrel) AS agent_point_barrel
                                   , MAX(t.point_barrel_general_agent) AS point_barrel_general_agent
                              FROM (
                                       SELECT agent_group_id
                                            , CASE WHEN setting_key = PointBarrel THEN value ELSE 0 END AS agent_point_barrel
                                            , CASE WHEN setting_key = GeneralAgent THEN value ELSE NULL END AS point_barrel_general_agent
                                       FROM agent_customized_settings
                                       WHERE setting_key IN (PointBarrel, GeneralAgent)
                                   ) AS t
                              GROUP BY agent_group_id
                              HAVING agent_point_barrel = 1 AND point_barrel_general_agent != ''
                          ) AS z
                              JOIN agents AS a ON a.username = z.point_barrel_general_agent
                 )
             ) AS a1 ON a.hierarchy_path LIKE CONCAT(a1.hierarchy_path, '%')
             GROUP BY a1.agent_id

             UNION ALL

             SELECT a1.agent_id
                  , sbra.vendor_id
                  , SUM(sbra.total_bet) as turnover
                  , SUM(sbra.total_bet) - (SUM(sbra.total_win_lose) * -1) AS game_spending
                  , (SUM(sbra.total_win_lose) * -1) AS game_profit
             FROM (
                      SELECT agent_id, vendor_id, SUM(total_bet) AS total_bet, SUM(total_win_lose) AS total_win_lose
                      FROM summary_bet_record_agents
                      WHERE summary_time >= StartTime
                        AND summary_time < EndTime
                      GROUP BY agent_id, vendor_id
                  ) AS sbra
                      JOIN agents AS a ON a.id = sbra.agent_id
                      JOIN (
                 SELECT a.id AS agent_id, a.hierarchy_path
                 FROM agents AS a
                          JOIN agent_groups AS ag ON ag.id = a.customized_setting_group_id AND ag.agent_group_type = 1
                          JOIN agent_customized_settings AS acs ON acs.agent_group_id = ag.id AND acs.setting_key = PointBarrel AND acs.value = 1
                 WHERE a.id IN (
                     SELECT a.id
                     FROM (
                              SELECT t.agent_group_id
                                   , MAX(t.agent_point_barrel) AS agent_point_barrel
                                   , MAX(t.point_barrel_general_agent) AS point_barrel_general_agent
                              FROM (
                                       SELECT agent_group_id
                                            , CASE WHEN setting_key = PointBarrel THEN value ELSE 0 END AS agent_point_barrel
                                            , CASE WHEN setting_key = GeneralAgent THEN value ELSE NULL END AS point_barrel_general_agent
                                       FROM agent_customized_settings
                                       WHERE setting_key IN (PointBarrel, GeneralAgent)
                                   ) AS t
                              GROUP BY agent_group_id
                              HAVING agent_point_barrel = 1 AND point_barrel_general_agent != ''
                          ) AS z
                              JOIN agents AS a ON a.username = z.point_barrel_general_agent
                 )
             ) AS a1 ON a.hierarchy_path LIKE CONCAT(a1.hierarchy_path, '%')
             GROUP BY a1.agent_id, sbra.vendor_id
         ) AS x
    ORDER BY x.agent_id, x.vendor_id;

END
