CREATE PROCEDURE `SP_calc_profit_share_winlose`(
    IN p_SummaryDate DATE
)
BEGIN

    # 分桶當日業績計算
    /*
    CREATE EVENT SP_calc_profit_share_winlose
		ON SCHEDULE EVERY 1 DAY STARTS '2023/07/07 07:00:00' #系統時間UTC+0
		COMMENT '每日 佔成當日業績計算'
		DO
			call SP_calc_profit_share_winlose(null);
    */
    # call SP_calc_profit_share_winlose(null);
    # call SP_calc_profit_share_winlose('2021-01-01');

    # 2025/10/20 Mars		Add
    # 2025/12/20 Mars 		調整918, mega888, pussy888, winlose的計算歸屬

    DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE SummaryDate DATE
		DEFAULT IFNULL(p_SummaryDate, DATE(DATE_SUB(DATE_ADD(NOW(), INTERVAL 8 HOUR), INTERVAL 1 DAY)));
    DECLARE SummaryTime, StartTime DATETIME
		DEFAULT DATE_SUB(SummaryDate, INTERVAL 8 HOUR);
	DECLARE EndTime DATETIME
		DEFAULT DATE_ADD(StartTime, INTERVAL 1 DAY);
    DECLARE ShareholderKey INT DEFAULT 6100; #份桶開關
    DECLARE ShareholderType INT DEFAULT 6102; #份桶模式
    DECLARE AgentId, AgentGroupId INT DEFAULT 0;
    DECLARE HierarchyPath VARCHAR(100);
    DECLARE Done INT DEFAULT 0;
    
    # 獲取佔成總代理
    DECLARE Cur CURSOR FOR
        SELECT a.id, a.customized_setting_group_id, a.hierarchy_path
		FROM agents AS a
		JOIN agent_customized_settings AS acs
			ON acs.agent_group_id = a.customized_setting_group_id
			AND acs.setting_key = ShareholderKey
			AND acs.value = 1
		JOIN agent_personal_settings AS aps
			ON aps.agent_id = a.id
			AND aps.setting_id = 95001
		JOIN agent_customized_settings AS acs2
			ON acs2.agent_group_id = a.customized_setting_group_id
			AND acs2.setting_key = ShareholderType
			AND acs2.value = 1 # 2
		WHERE a.parent = 1 AND a.host_id = 0 AND a.status = 1
		AND aps.value > 0
		GROUP BY a.customized_setting_group_id;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_profit_share_winlose(',
                    IFNULL(p_SummaryDate, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    SET ExecStart = NOW();
    
    OPEN Cur;
    REPEAT
        FETCH Cur INTO AgentId, AgentGroupId, HierarchyPath;
        IF NOT Done THEN
        
			# 備份當前會員狀態
			INSERT INTO member_type_histories(data_time, member_id, `type`)
			SELECT StartTime, m.id, m.previous_day_type
			FROM members AS m
            JOIN agents AS a 
				ON a.id = m.parent 
				AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
			WHERE previous_day_type > 0
			AND m.created_at < EndTime
			AND NOT EXISTS (
				SELECT * FROM member_type_histories AS tmp
				WHERE tmp.member_id = m.id
				AND tmp.data_time = StartTime
			);
		
			#寫入一筆profit_share_payout_logs, 待後續支付時做紀錄用
            IF NOT EXISTS (
				SELECT * FROM profit_share_payout_logs 
                WHERE summary_time = StartTime
                AND agent_group_id = AgentGroupId
            ) THEN
				INSERT INTO profit_share_payout_logs(
					summary_time, agent_group_id, status
				) VALUES(
					StartTime, AgentGroupId, 0
				);
            END IF;
			
			UPDATE summary_profit_share_winloses
			SET turnover = 0,
				winlose = 0,
				game_spending = 0,
				game_profit = 0,
				cost = 0
			WHERE summary_time >= StartTime 
            AND summary_time < EndTime
			AND agent_id IN (
				SELECT id FROM agents 
				WHERE hierarchy_path LIKE CONCAT(HierarchyPath, '%')
			);
			
			# 計算當日總代業績
			INSERT INTO summary_profit_share_winloses(
				summary_time, agent_id, vendor_id, game_type_id,
				turnover, 		# 營業額
				winlose, 		# 輸贏
				game_spending, 	# 遊戲支出
				game_profit,  	# 遊戲盈利
				cost, 			# 成本(盤費)
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
				, z.cost
				, '' AS memo
			FROM (
				SELECT a.summary_time
					, a.agent_id
					, a.vendor_id
					, a.game_type_id
					, a.turnover
					, a.winlose 
					, a.turnover - (a.winlose * -1) AS game_spending
					, (a.winlose * -1) AS game_profit
					, (a.cost * -1) AS cost
				FROM (
					SELECT y.summary_time
						, y.agent_id
						, y.vendor_id
						, y.game_type_id
						, SUM(y.turnover) AS turnover
						, SUM(y.winlose) AS winlose
						, SUM(y.cost) AS cost
                    FROM (
						SELECT sbr.summary_time
							, a.id AS agent_id
							, sbr.vendor_id
							#, g.type_id AS game_type_id
							# 因為份桶不會設定918, mega888, pussy888這三個類別的game_type_id為0的, 
							# 但又因為win_lose又在game_type_id為0, 所以先用此做法
                            , CASE WHEN sbr.vendor_id IN (3, 10, 23) AND g.type_id IS NULL THEN 2
								ELSE IFNULL(g.type_id, 0) END AS game_type_id
							, sbr.bet AS turnover
							, sbr.win_lose AS winlose
							, sbr.cost AS cost
						FROM summary_bet_records AS sbr
						JOIN members AS m 
							ON m.id = sbr.member_id
							AND m.previous_day_type = 2 #佔成會員
						JOIN agents AS a
							ON a.id = m.parent
							AND (
								a.hierarchy_path LIKE CONCAT(HierarchyPath, '/%')
								OR
								a.id = AgentId
								)
						LEFT JOIN games AS g ON g.id = sbr.game_id
						WHERE sbr.summary_time >= StartTime 
						AND sbr.summary_time < EndTime
                    ) AS y
					GROUP BY y.summary_time, y.agent_id, y.vendor_id, y.game_type_id
				) AS a
			) AS z
			ON DUPLICATE KEY UPDATE
				turnover = z.turnover
				, winlose = z.winlose
				, game_spending = z.game_spending
				, game_profit = z.game_profit
				, cost = z.cost;

        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;

    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_calc_profit_share_winlose(',
            IFNULL(p_SummaryDate, 'NULL'),
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
	);
END