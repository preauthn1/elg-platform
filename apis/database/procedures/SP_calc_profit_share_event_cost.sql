CREATE PROCEDURE `SP_calc_profit_share_event_cost`(
    IN p_SummaryDate DATE
)
BEGIN

    # 分桶佔成 - 活動成本
    /*
    CREATE EVENT Event_Calc_Profit_Share_Event_Cost
		ON SCHEDULE EVERY 1 DAY STARTS '2025/12/13 07:25:00' #系統時間UTC+0
		COMMENT '每天15:05 - 佔成計算活動獎勵'
		DO CALL summary_profit_share_event_costs(NULL);
    */
    # call SP_calc_profit_share_event_cost(null);
    # call SP_calc_profit_share_event_cost('2021-01-01');

    # 2025/11/15 Mars		Add
    # 2025/12/16 Mars 		EVENT運行時間需晚於三層代結算時間

    DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE SummaryDate DATE
		DEFAULT IFNULL(p_SummaryDate, DATE(DATE_SUB(DATE_ADD(NOW(), INTERVAL 8 HOUR), INTERVAL 1 DAY)));
    DECLARE SummaryTime, StartTime DATETIME
		DEFAULT DATE_SUB(SummaryDate, INTERVAL 8 HOUR);
	DECLARE EndTime DATETIME
		DEFAULT DATE_ADD(StartTime, INTERVAL 1 DAY);
    DECLARE ShareholderKey INT DEFAULT 6100; #份桶開關
    DECLARE ShareholderType INT DEFAULT 6102; #份桶模式
    DECLARE AgentId, AgentGroupId, EventType, EventId INT DEFAULT 0;
    DECLARE HierarchyPath VARCHAR(200);
    DECLARE Done INT DEFAULT 0;
    
    # 獲取佔成總代理
    DECLARE Cur CURSOR FOR
        SELECT a.id, a.hierarchy_path
			, a.customized_setting_group_id
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
			AND acs2.value = 1 #2
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
                    '[系統通知] SP_calc_profit_share_event_cost(',
                    IFNULL(p_SummaryDate, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    SET ExecStart = NOW();
    
    OPEN Cur;
    REPEAT
        FETCH Cur INTO AgentId, HierarchyPath, AgentGroupId;
        IF NOT Done THEN
        
			DELETE FROM summary_profit_share_event_costs
			WHERE summary_time BETWEEN StartTime AND EndTime
			AND agent_id IN (
				SELECT id FROM agents 
				WHERE hierarchy_path LIKE CONCAT(HierarchyPath, '%')
			);
            
			### EVENT ##########################
			# 計算當日活動成本
			INSERT INTO summary_profit_share_event_costs(
				summary_time, agent_id, transaction_type, `point`
			)
			SELECT StartTime
				, a.id AS agent_id
                , tl.type
                , SUM(tl.`point`) AS `point`
			FROM transaction_logs as tl
			JOIN members AS m 
				ON m.id = tl.member_id 
                AND m.previous_day_type = 2
			JOIN agents AS a 
				ON a.id = m.parent 
                AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
			WHERE tl.created_at >= StartTime 
			AND tl.created_at < EndTime
			AND tl.`status` = 1000
            # 17:三層代獎勵不納入, 因為這邊的是前一天的數據, 改用下面獨立計算
			AND tl.`type` IN (
				10, 13, 15, 19, 20, 21, 24, 25, 26, 30, 31, 101, 102, 103
			)
			GROUP BY a.id, tl.`type`;
            
            # 三層代獎勵
			INSERT INTO summary_profit_share_event_costs(
				summary_time, agent_id, transaction_type, event_id, `point`
			)
            SELECT StartTime
				, a.id AS agent_id
                , 2 AS `type`
                , 0 AS `event_id`	
                , SUM(rebate + down_rebate + down2_rebate) AS `point`
            FROM three_hierarchy_bonus_report AS r
            JOIN members AS m ON m.id = r.member_id AND m.previous_day_type = 2
            JOIN agents AS a ON a.id = m.parent
			WHERE summary_time >= StartTime
            AND summary_time < EndTime
            GROUP BY a.id;
            
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;

    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_calc_profit_share_event_cost(',
            IFNULL(p_SummaryDate, 'NULL'),
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
	);
END