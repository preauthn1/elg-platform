CREATE PROCEDURE `SP_calc_shareholder_winlose`(
    IN p_SummaryDate DATE
)
BEGIN

    # 分桶當日業績計算
    /*
    CREATE EVENT Event_Calc_Shareholder_Winlose
		ON SCHEDULE EVERY 1 DAY STARTS '2023/07/07 07:00:00' #系統時間UTC+0
		COMMENT '每日 分桶當日業績計算'
		DO
			call SP_calc_shareholder_winlose(null);
    */
    # call SP_calc_shareholder_winlose(null);
    # call SP_calc_shareholder_winlose('2021-01-01');

    # 2020/01/06 Mars		Add
    # 2023/07/07 Mars 		Modify
    # 2023/07/19 Mars 		成本需支付/簡訊手續費,活動獎勵的項目

    DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE SummaryTime DATETIME;
    DECLARE SummaryDate DATE
        DEFAULT DATE(DATE_SUB(DATE_ADD(NOW(), INTERVAL 8 HOUR), INTERVAL 1 DAY));
    DECLARE StartTime, EndTime DATETIME ;
    DECLARE ShareholderKey INT DEFAULT 6100; #份桶開關

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_shareholder_winlose(',
                    IFNULL(p_SummaryDate, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET ExecStart = NOW();
    SET SummaryDate = IFNULL(p_SummaryDate, SummaryDate);
    SET StartTime = DATE_SUB(SummaryDate, INTERVAl 8 HOUR);
    SET EndTime = DATE_SUB(DATE_ADD(StartTime, INTERVAl 1 DAY), INTERVAL 1 SECOND);

    #寫入一筆shareholder_payout_logs, 待後續支付時做紀錄用
    INSERT INTO shareholder_payout_logs(
        summary_time, agent_group_id, status
    )
    SELECT StartTime, a.customized_setting_group_id, 0
    FROM agents AS a
             JOIN agent_customized_settings AS acs
                  ON acs.agent_group_id = a.customized_setting_group_id
                      AND acs.setting_key = ShareholderKey
                      AND acs.value = 1
    WHERE a.parent = 1 AND a.host_id = 0
      AND NOT EXISTS(
        SELECT * FROM shareholder_payout_logs AS spl
        WHERE spl.summary_time = StartTime
          AND spl.agent_group_id = a.customized_setting_group_id
    );

    UPDATE summary_shareholder_winloses
    SET turnover = 0,
        winlose = 0,
        game_spending = 0,
        game_profit = 0
    WHERE summary_time BETWEEN StartTime AND EndTime;

    # 計算當日總代業績
    INSERT INTO summary_shareholder_winloses(
        summary_time, agent_id, vendor_id, game_type_id,
        turnover, 		# 營業額
        winlose, 		# 輸贏
        game_spending, 	# 遊戲支出
        game_profit,  	# 遊戲盈利
        memo
    )
    SELECT z.summary_time
         , z.agent_id
         , z.vendor_id
         , z.game_type_id
         , z.turnover
         , z.winlose
         , z.game_spending
         , z.game_profit
         , ''
    FROM (
             SELECT a.summary_time AS summary_time
                  , a.agent_id AS agent_id
                  , a.vendor_id AS vendor_id
                  , a.game_type_id AS game_type_id
                  , SUM(a.total_bet) AS turnover
                  , SUM(a.total_win_lose) AS winlose
                  , SUM(a.total_bet) - (SUM(a.total_win_lose) * -1) AS game_spending
                  , CASE WHEN vendor_id < 0
                             THEN (SUM(a.total_cost) * -1)
                         ELSE (SUM(a.total_win_lose) * -1)
                 END game_profit
             FROM (
                      SELECT StartTime AS summary_time
                           , a1.agent_id
                           , 0 AS vendor_id
                           , 0 AS game_type_id
                           , 0 AS total_bet
                           , 0 AS total_win_lose
                           , 0 AS total_cost
                      FROM (
                               #抓出總代
                               SELECT a.id AS agent_id
                                    , a.hierarchy_path
                               FROM agents AS a
                                        JOIN agent_customized_settings AS acs
                                             ON acs.agent_group_id = a.customized_setting_group_id
                                                 AND acs.setting_key = ShareholderKey
                                                 AND acs.value = 1
                               WHERE a.parent = 1 AND a.host_id = 0
                           ) AS a1
                      UNION
                      SELECT sbra.summary_time
                           , a.id AS agent_id
                           , sbra.vendor_id
                           , sbra.game_type_id
                           , sbra.total_bet
                           , sbra.total_win_lose
                           , sbra.total_cost
                      FROM summary_bet_record_agents AS sbra
                               JOIN agents AS a
                                    ON a.id = sbra.agent_id
                               JOIN (
                          #抓出總代
                          SELECT a.id AS agent_id
                               , a.hierarchy_path
                          FROM agents AS a
                                   JOIN agent_customized_settings AS acs
                                        ON acs.agent_group_id = a.customized_setting_group_id
                                            AND acs.setting_key = ShareholderKey
                                            AND acs.value = 1
                          WHERE a.parent = 1 AND a.host_id = 0
                      ) AS a1
                                    ON (
                                        a.hierarchy_path LIKE CONCAT(a1.hierarchy_path, '/%')
                                            OR
                                        a.id = a1.agent_id
                                        )
                      WHERE sbra.summary_time BETWEEN StartTime AND EndTime
                        #廠商ID; 大於0為vendor_id, -1:獎勵, -2:v-reward, -3:支付, -4:簡訊, -5:三層代獎勵
                        AND sbra.vendor_id != 0
                  ) AS a
             GROUP BY a.summary_time, a.agent_id, a.vendor_id, a.game_type_id
         ) AS z
    ON DUPLICATE KEY UPDATE
        turnover = z.turnover
                          , winlose = z.winlose
                          , game_spending = z.game_spending
                          , game_profit = z.game_profit;


    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_calc_shareholder_winlose(',
            IFNULL(p_SummaryDate, 'NULL'),
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
         );
END
