CREATE PROCEDURE `SP_calc_profit_share_temp`(
	IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_PayoutRecordId INT
)
BEGIN
	# 佔成總銷量計算
    # 2025/10/20 Mars		Add
    
	DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE SettlementId INT DEFAULT 0;
    DECLARE SummaryDate DATE 
		DEFAULT DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 1 DAY);
	DECLARE StartTime, EndTime DATETIME;
    DECLARE AgentGroupId INT;
    DECLARE AgentId INT DEFAULT p_AgentId;
    DECLARE PayoutRecordId INT DEFAULT p_PayoutRecordId;
    DECLARE DataType, DataValue VARCHAR(100);
    DECLARE TotalTurnover, TotalGameProfit, TotalCost DECIMAL(18, 4) DEFAULT 0;
    
    DECLARE ShareholderKey INT DEFAULT 6100; #份桶開關
    DECLARE ShareholderGeneralAgentKey INT DEFAULT 6101; #份桶總代理
    DECLARE ProfixShareSettingKey INT DEFAULT 8201; #佔成上限設定
    DECLARE ProfixShareSetting TEXT; #總代理佔成上限設定
    
    DECLARE ShareholderGeneralAgent VARCHAR(50); #份桶總代理
    DECLARE ShareholderGeneralAgentShareValue INT DEFAULT 0; #總代理持有股份值
    
    DECLARE HierarchyPath VARCHAR(50);
    
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_calc_profit_share_temp(',
                IFNULL(p_SummaryDate, 'NULL'), ',',
                IFNULL(p_AgentId, 'NULL'), ',',
                IFNULL(p_PayoutRecordId, 'NULL'),
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    SET ExecStart = NOW();
    SET SummaryDate = IFNULL(p_SummaryDate, SummaryDate);
    SET StartTime = DATE_SUB(SummaryDate, INTERVAL 8 HOUR);
    SET EndTime = DATE_SUB(DATE_ADD(StartTime, INTERVAL 1 DAY), INTERVAL 1 SECOND);

    # 取得組別
    SELECT hierarchy_path, customized_setting_group_id
    INTO HierarchyPath, AgentGroupId
	FROM agents 
    WHERE id = AgentId;
    
    # 確認份桶開關
    CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderKey, DataType, DataValue);
    IF DataValue <> 1 THEN
		SELECT '未開啟分桶';
    ELSE
		# 取得分桶總代理
		CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderGeneralAgentKey, DataType, ShareholderGeneralAgent);
        IF ShareholderGeneralAgent = '' THEN
			SELECT '未設定總代';
        ELSE 
            # 取得總代成本
            CALL ADMIN_get_customized_setting(AgentGroupId, ProfixShareSettingKey, DataType, ProfixShareSetting);
			IF ProfixShareSetting = '{}' THEN
				SELECT '未設定佔成上限設定 ', ProfixShareSettingKey;
            ELSE 
		
                INSERT INTO summary_profit_share_temps(
					summary_time, agent_group_id, shareholder_category_id, 
                    setting, turnover, original_turnover, nett_profit, original_nett_profit
				)
                SELECT StartTime AS summary_time
					, AgentGroupId AS agent_group_id
					, z.shareholder_category_id
                    , ProfixShareSettingKey
                    , z.turnover
                    , z.turnover
                    , z.game_profit
                    , z.game_profit
                FROM (
					SELECT y.shareholder_category_id
                        , SUM(CASE WHEN y.is_cancel > 0 THEN 0 ELSE y.turnover END) AS turnover
						, SUM(y.game_profit) AS game_profit
                    FROM (
						SELECT scs.shareholder_category_id
							, CASE WHEN scs.calc_bet = 1 THEN IFNULL(spsw.turnover, 0) ELSE 0 END AS turnover
							, CASE WHEN scs.calc_win_lose = 1 THEN IFNULL(spsw.game_profit, 0) ELSE 0 END AS game_profit
							, IFNULL(psc.id, 0) AS is_cancel #不計算營業額
						FROM agents AS a
						JOIN summary_profit_share_winloses AS spsw
							ON spsw.agent_id = a.id
                            AND spsw.summary_time BETWEEN StartTime AND EndTime
                            AND spsw.vendor_id > 0
						JOIN (
							SELECT sc.id AS shareholder_category_id
								, scs.vendor_id
								, scs.game_type_id
								, scs.calc_bet
								, scs.calc_win_lose
							FROM shareholder_categories AS sc
							JOIN shareholder_category_settings AS scs 
								ON scs.shareholder_category_id = sc.id
								AND scs.`status` = 1
							WHERE sc.agent_group_id = AgentGroupId
							AND sc.status = 1
                        ) AS scs
							ON scs.vendor_id = spsw.vendor_id
							AND (
								scs.game_type_id = spsw.game_type_id
								OR
								scs.game_type_id = 0
							)
						LEFT JOIN profit_share_cancels AS psc
							ON psc.agent_id = spsw.agent_id
							AND psc.summary_date BETWEEN StartTime AND EndTime
							AND psc.`status` = 1
                        WHERE a.hierarchy_path LIKE CONCAT(HierarchyPath, '/%') 
							OR 
							a.id = AgentId
					) AS y
					GROUP BY y.shareholder_category_id
                ) AS z
				ON DUPLICATE KEY UPDATE
                    original_turnover = z.turnover, 
                    original_nett_profit = z.game_profit;
                
				SET ExecEnd = NOW();
				CALL COM_Send_Error_Message(
					CONCAT(
						'[系統通知] SP_calc_profit_share_temp(',
						IFNULL(p_SummaryDate, 'NULL'), ',',
						IFNULL(p_AgentId, 'NULL'), ',',
						IFNULL(p_PayoutRecordId, 'NULL'),
						') 計算完畢 ', 
                        ExecStart, ' ~ ', ExecEnd), 
					'db'
				);
		
			END IF;
		END IF;
	END IF;
END