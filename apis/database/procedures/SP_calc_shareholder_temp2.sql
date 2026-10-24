CREATE PROCEDURE `SP_calc_shareholder_temp2`(
	IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_PayoutRecordId INT
)
BEGIN
	# 總銷量計算
    # 2025/08/10 Mars		Add
    # 2026/05/19 Mars 		如佔成為0, 正收益總業績將為0
    
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
    DECLARE ShareholderGeneralAgentShareValueKey INT DEFAULT 8001; #總代理持有股份值
    
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
				'[系統通知] SP_calc_shareholder_temp2(',
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
            CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderGeneralAgentShareValueKey, DataType, ShareholderGeneralAgentShareValue);
			IF ShareholderGeneralAgentShareValue < 0 THEN
				SELECT '總代成本 ', ShareholderGeneralAgentShareValue;
            ELSE 
                # 總銷量寫至暫存表
                INSERT INTO summary_shareholder_temps(
					summary_time, agent_group_id, shareholder_category_id,
                    share_point, bet_ratio, commission_ratio,
                    turnover, original_turnover, 
                    net_win, original_net_win,
                    nett_profit, original_nett_profit
				)
                SELECT StartTime
					, AgentGroupId
					, z.shareholder_category_id
                    , ShareholderGeneralAgentShareValue AS share_point
					, z.bet_ratio
					, z.commission_ratio
                    , 0 AS turnover
                    , 0 AS original_turnover
					, 0 AS net_win
					, 0 AS original_net_win
                    , z.game_profit AS game_profit
                    , z.game_profit AS original_game_profit
                FROM (
					SELECT scs.shareholder_category_id
						, scs.bet_ratio
                        , scs.commission_ratio
						, 0 AS turnover
						, IFNULL(SUM(ssw.game_profit), 0) AS game_profit
					FROM agents AS a
                    LEFT JOIN summary_shareholder_winloses AS ssw
						ON ssw.agent_id = a.id
						AND ssw.summary_time BETWEEN StartTime AND EndTime
                        AND ssw.vendor_id <= 0
					JOIN (
						SELECT sc.id AS shareholder_category_id
                            , sc.bet_ratio
							, sc.commission_ratio
							, scs.vendor_id
                            , scs.game_type_id
                        FROM shareholder_categories AS sc
                        JOIN shareholder_category_settings AS scs 
							ON scs.shareholder_category_id = sc.id
							AND scs.`status` = 1
                        WHERE sc.agent_group_id = 0
                        AND sc.`status` = 1
                    ) AS scs
						ON scs.vendor_id = ssw.vendor_id
                        AND scs.game_type_id = ssw.game_type_id
					WHERE (
						a.hierarchy_path LIKE CONCAT(HierarchyPath, '/%') 
						OR 
						a.id = AgentId
					)
                    AND a.host_id = 0
                ) AS z
				ON DUPLICATE KEY UPDATE 
					bet_ratio = z.bet_ratio, 
                    commission_ratio = z.commission_ratio,
					nett_profit = z.game_profit,
                    original_nett_profit = z.game_profit;
                
                INSERT INTO summary_shareholder_temps(
					summary_time, agent_group_id, shareholder_category_id, 
                    share_point, bet_ratio, commission_ratio,
                    turnover, original_turnover, 
                    net_win, original_net_win,
                    nett_profit, original_nett_profit
				)
                SELECT StartTime AS summary_time
					, AgentGroupId AS agent_group_id
					, z.shareholder_category_id
                    , ShareholderGeneralAgentShareValue AS share_point
					, z.bet_ratio
					, z.commission_ratio
                    , z.turnover
                    , z.turnover
					, z.win
					, z.win
                    , z.game_profit
                    , z.game_profit
                FROM (
					SELECT y.shareholder_category_id
						, y.bet_ratio
						, y.commission_ratio
                        , SUM(y.turnover) AS turnover
						, CASE WHEN y.commission_ratio = 0 THEN 0 ELSE SUM(y.win) END AS win
						, SUM(y.game_profit) AS game_profit
                    FROM (
						SELECT x.shareholder_category_id
							, x.bet_ratio
							, x.commission_ratio
							, SUM(CASE WHEN x.is_cancel > 0 THEN 0 ELSE x.turnover END) AS turnover
							, SUM(x.game_profit) AS game_profit
							, CASE WHEN SUM(x.game_profit) > 0 THEN SUM(x.game_profit) ELSE 0 END AS win
						FROM (
							SELECT scs.shareholder_category_id
								, scs.bet_ratio
								, scs.commission_ratio
								, CASE WHEN scs.calc_bet = 1 THEN IFNULL(ssw.turnover, 0) ELSE 0 END AS turnover
								, CASE WHEN scs.calc_win_lose = 1 THEN IFNULL(ssw.game_profit, 0) ELSE 0 END AS game_profit
								, IFNULL(sca.id, 0) AS is_cancel #不計算營業額
								, a.id as agent_id
							FROM agents AS a
							JOIN summary_shareholder_winloses AS ssw
								ON ssw.agent_id = a.id
								AND ssw.summary_time BETWEEN StartTime AND EndTime
                                # 20251008 Mars 補帳用, 先註解待後續使用
								#AND (
								#	#(ssw.vendor_id = 181 AND ssw.summary_time BETWEEN DATE_SUB(StartTime, INTERVAL 7 DAY) AND EndTime)
								#	(ssw.vendor_id = 181 AND ssw.summary_time IN ('2026-06-08 00:00:00', '2026-06-12 00:00:00'))
                                #    OR
								#	(ssw.vendor_id != 181 AND ssw.summary_time BETWEEN StartTime AND EndTime)
								#)
								AND ssw.vendor_id > 0
							JOIN (
								SELECT sc.id AS shareholder_category_id
									, sc.bet_ratio
									, sc.commission_ratio
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
								ON scs.vendor_id = ssw.vendor_id
								AND (
									scs.game_type_id = ssw.game_type_id
									OR
									scs.game_type_id = 0
								)
							LEFT JOIN shareholder_cancels AS sca
								ON sca.agent_id = ssw.agent_id
								AND sca.summary_date BETWEEN StartTime AND EndTime
								AND sca.`status` = 1
							WHERE a.hierarchy_path LIKE CONCAT(HierarchyPath, '/%') 
							OR a.id = AgentId
						) AS x
						GROUP BY x.shareholder_category_id, x.agent_id
					) AS y
					GROUP BY y.shareholder_category_id
                ) AS z
				ON DUPLICATE KEY UPDATE
					bet_ratio = z.bet_ratio, 
                    commission_ratio = z.commission_ratio,
                    original_turnover = z.turnover,
                    original_net_win = z.win,
                    original_nett_profit = z.game_profit;
                
                #0:未派發, 1:已派發, 2:待派發, 3:已輸入數據(未結算), 4:已結算, 5:已計算, 6:已發佈數據
                UPDATE shareholder_payout_logs
                SET `status` = 3
                WHERE id = PayoutRecordId
                AND `status` = 0;
                
				SET ExecEnd = NOW();
				CALL COM_Send_Error_Message(
					CONCAT(
						'[系統通知] SP_calc_shareholder_temp2(',
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