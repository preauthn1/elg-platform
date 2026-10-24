CREATE PROCEDURE `SP_calc_profit_share_payment_cost`(
    IN p_SummaryDate DATE
)
BEGIN

    # 分桶佔成 - 支付成本
    /*
	CREATE EVENT Event_Calc_Profit_Share_Payment_Cost
		ON SCHEDULE EVERY 1 DAY STARTS '2025/12/13 07:07:00' #系統時間UTC+0
		COMMENT '每天15:07 - 佔成計算支付費用'
		DO CALL SP_calc_profit_share_payment_cost(NULL);
    */
    # call SP_calc_profit_share_payment_cost(null);
    # call SP_calc_profit_share_payment_cost('2021-01-01');

    # 2025/11/15 Mars		Add
    # 2026-10-01 Tim		成本改直接加總訂單上凍結的 payment_cost（代收 orders、代付 withdrawal_orders），
    #                   不再以「點數加總 × 當下的 acf.cost」現算。現算的問題是重算歷史日期時會套用
    #                   改過的新費率，算出來的不是當時真實成本；凍結值是入帳／派款那一刻的渠道費率。
    #                   cost 欄位仍記該渠道當下費率，僅作參考，不再參與 payment_cost 計算。
    #                   上線前需以 order:backfill-payment-cost 回補歷史單，否則舊單成本為 0（target/tickets#4361）。

    DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE SummaryDate DATE
		DEFAULT IFNULL(p_SummaryDate, DATE(DATE_SUB(DATE_ADD(NOW(), INTERVAL 8 HOUR), INTERVAL 1 DAY)));
    DECLARE SummaryTime, StartTime DATETIME
		DEFAULT DATE_SUB(SummaryDate, INTERVAL 8 HOUR);
	DECLARE EndTime DATETIME
		DEFAULT DATE_ADD(StartTime, INTERVAL 1 DAY);
    DECLARE ShareholderKey INT DEFAULT 6100; #份桶開關
    DECLARE ShareholderType INT DEFAULT 6102; #份桶模式
    DECLARE AgentId, AgentGroupId, AgentCashGroupId
		, AutoWithdrawAgentCashFlowId INT DEFAULT 0;
    DECLARE HierarchyPath VARCHAR(200);
    DECLARE Done INT DEFAULT 0;
    
    # 獲取佔成總代理
    DECLARE Cur CURSOR FOR
        SELECT a.id, a.hierarchy_path
			, a.customized_setting_group_id
            , a.cash_flow_group_id
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
                    '[系統通知] SP_calc_profit_share_payment_cost(',
                    IFNULL(p_SummaryDate, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    SET ExecStart = NOW();
    
    OPEN Cur;
    REPEAT
        FETCH Cur INTO AgentId, HierarchyPath, AgentGroupId, AgentCashGroupId;
        IF NOT Done THEN
        
			DELETE FROM summary_profit_share_payment_costs
			WHERE summary_time BETWEEN StartTime AND EndTime
			AND agent_id IN (
				SELECT id FROM agents 
				WHERE hierarchy_path LIKE CONCAT(HierarchyPath, '%')
			);
            
			### DEPOSIT ##########################
			# 計算當日充值成本
			INSERT INTO summary_profit_share_payment_costs(
				`type`, summary_time, agent_id, agent_cash_flow_id
                , cost, `point`, payment_cost
			)
			SELECT 1 AS `type`
				, StartTime AS summary_time
				, z.agent_id
				, z.agent_cash_flow_id
                , z.cost
				, z.`point` 
				, z.payment_cost
			FROM (
				SELECT r.agent_id
					, r.agent_cash_flow_id
					, acf.cost
					, r.`point`
                    -- orders.payment_cost 已是站點主幣別、入帳當下渠道費率算好並凍結的成本，直接加總
                    , r.payment_cost
				FROM (
					SELECT a.id AS agent_id, o.agent_cash_flow_id, SUM(o.`point`) AS `point`
						, SUM(o.payment_cost) AS payment_cost
					FROM orders AS o
					JOIN members AS m 
						ON m.id = o.member_id 
                        AND m.previous_day_type = 2
					JOIN agents AS a 
						ON a.id = m.parent
                        AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
					WHERE o.`status` = 3 
					AND pay_time >= StartTime
					AND pay_time < EndTime 
					GROUP BY a.id, agent_cash_flow_id
				) AS r
				JOIN agent_cash_flows AS acf
					ON acf.id = r.agent_cash_flow_id
			) AS z
			ON DUPLICATE KEY UPDATE
				cost = z.cost
				, `point` = z.`point`
				, payment_cost = z.payment_cost;
                
                
			### WITHDRAWAL ##########################
            SET AutoWithdrawAgentCashFlowId = 0;
            
			# 計算當日提領成本
			SELECT IFNULL(id, 0)
			INTO AutoWithdrawAgentCashFlowId
			FROM agent_cash_flows 
			WHERE cash_flow_id = 6 
			AND agent_group_id = AgentCashGroupId;
            #SELECT AgentCashGroupId, AutoWithdrawAgentCashFlowId;

			INSERT INTO summary_profit_share_payment_costs(
				`type`, summary_time, agent_id, agent_cash_flow_id
                , cost, `point`, payment_cost
			)
			SELECT 2 AS `type`
				, StartTime AS summary_time
				, z.agent_id
				, z.agent_cash_flow_id
                , z.cost
				, z.`point` 
				, z.payment_cost
			FROM (
				SELECT r.agent_id
					, r.agent_cash_flow_id
					, r.`point`
					, acf.cost
                    -- withdrawal_orders.payment_cost 於派款成功時依渠道 cost 凍結，直接加總
					, r.payment_cost
				FROM (
					SELECT agent_id, agent_cash_flow_id, SUM(`point`) AS `point`
						, SUM(payment_cost) AS payment_cost
                    FROM (
						SELECT a.id AS agent_id
							, IFNULL(wpr.agent_cash_flow_id, AutoWithdrawAgentCashFlowId) AS agent_cash_flow_id
							, (wo.amount * wo.rate) AS `point`
							, wo.payment_cost
						FROM withdrawal_orders AS wo
						JOIN members AS m 
							ON m.id = wo.member_id 
							AND m.previous_day_type = 2
						JOIN agents AS a 
							ON a.id = m.parent 
							AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
						LEFT JOIN withdrawal_payout_records AS wpr 
							ON wpr.withdrawal_order_id = wo.id
							AND wpr.is_success_payout = 1
						WHERE (
							(wpr.callback_time >= StartTime AND wpr.callback_time < EndTime AND wo.status = 24)
							OR 
							(wo.payment_time >= StartTime AND wo.payment_time < EndTime AND wo.status = 4)
						)
                    ) AS z
                    GROUP BY z.agent_id, z.agent_cash_flow_id
				) AS r
				LEFT JOIN agent_cash_flows AS acf ON acf.id = r.agent_cash_flow_id
				GROUP BY r.agent_id, r.agent_cash_flow_id
			) AS z
			ON DUPLICATE KEY UPDATE
				cost = z.cost
				, `point` = z.`point`
				, payment_cost = z.payment_cost;
	
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;

    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_calc_profit_share_payment_cost(',
            IFNULL(p_SummaryDate, 'NULL'),
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
	);
END