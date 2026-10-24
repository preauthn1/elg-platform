CREATE PROCEDURE `SP_calc_shareholder2`(
	IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_PayoutRecordId INT
)
BEGIN
	# 分桶+佔成計算
    # 2025-08-10 Mars		Add
    # 2025-09-01 Mars 		公司净利润负数的情况，业绩分红应该放0  只计算投注额分红
    
	DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE MaxLevel, SettlementId, HaveSubData INT DEFAULT 0;
    DECLARE SummaryDate DATE 
	DEFAULT DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 1 DAY);
	DECLARE StartTime, EndTime DATETIME;
    DECLARE AgentGroupId INT;
    DECLARE AgentId INT DEFAULT p_AgentId;
    DECLARE PayoutRecordId INT DEFAULT p_PayoutRecordId;
    DECLARE ThisLevel INT DEFAULT 3;
    DECLARE DataType, DataValue VARCHAR(100);
    DECLARE TotalTurnover, TotalGameProfit, TotalCost DECIMAL(18, 4) DEFAULT 0;
    
    DECLARE ShareholderKey INT DEFAULT 6100; #份桶開關
    DECLARE ShareholderGeneralAgentKey INT DEFAULT 6101; #份桶總代理
    DECLARE ShareholderGeneralAgentShareValueKey INT DEFAULT 8001; #總代理持有股份值
    DECLARE ShareholderModifyShareValueKey INT DEFAULT 8002; #是否可以修改股份值
    DECLARE ShareholderMaxDifferenceKey INT DEFAULT 8003; #代理層級的最大差額
    DECLARE ShareholderAgencyGapKey INT DEFAULT 8004; #代理每層上限差額
    DECLARE ShareholderAutoRewardKey INT DEFAULT 8005; #是否自動派獎(下午3點派獎)
    DECLARE PersonalSettingKey INT DEFAULT 95001;  #分桶股份佔成數
    
    DECLARE ShareholderGeneralAgent VARCHAR(50); #份桶總代理
    DECLARE ShareholderGeneralAgentShareValue INT DEFAULT 0; #總代理持有股份值
    DECLARE ShareholderModifyShareValue INT DEFAULT 0; #是否可以修改股份值
    DECLARE ShareholderMaxDifference INT DEFAULT 0; #代理層級的最大差額
    DECLARE ShareholderAgencyGap INT DEFAULT 0; #代理每層上限差額
    DECLARE ShareholderAutoReward INT DEFAULT 0; #是否自動派獎(下午3點派獎)
    DECLARE ShareholderAgentShareValue INT DEFAULT 0;  #分桶股份佔成數
    
    DECLARE HierarchyPath VARCHAR(50);
    DECLARE StopSeconds INT DEFAULT 1;		# 暫停秒數
    
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_calc_shareholder2(',
                IFNULL(p_SummaryDate, 'NULL'), ',',
                IFNULL(p_AgentId, 'NULL'), ',',
                IFNULL(p_PayoutRecordId, 'NULL'),
				') 異常 ', @p2), 
			'db'
		);
    END;
    
	CALL COM_Send_Error_Message(
		CONCAT(
			'[系統通知] SP_calc_shareholder2(',
			IFNULL(p_SummaryDate, 'NULL'), ',',
			IFNULL(p_AgentId, 'NULL'), ',',
			IFNULL(p_PayoutRecordId, 'NULL'),
			') 開始 '), 
		'db'
	);
    
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
				# 代理每層上限差額
				CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderAgencyGapKey, DataType, ShareholderAgencyGap);
				SET ShareholderAgencyGap = IFNULL(ShareholderAgencyGap, 0);
                
                ##### 中繼表 start ##############################
                # 總銷量先寫至暫存表 => 改至SP_calc_shareholder_temp2
                
                # agent中繼表
                INSERT INTO agent_temps(id, general_agent_id, parent, username, hierarchy_path, `level`, customized_setting_group_id)
				SELECT z.id, AgentId, z.parent, z.username, z.hierarchy_path, z.`level`, z.customized_setting_group_id
				FROM (
					SELECT a.id, a.parent, a.username, a.hierarchy_path, 
						(LENGTH(hierarchy_path) - LENGTH(REPLACE(hierarchy_path, '/', ''))) AS `level`, 
						a.customized_setting_group_id
					FROM agents AS a
					WHERE host_id = 0
					AND hierarchy_path LIKE CONCAT(HierarchyPath, '%')
				) AS z
				ON DUPLICATE KEY UPDATE 
					parent = z.parent,
					hierarchy_path = z.hierarchy_path,
					level = z.`level`,
					customized_setting_group_id = z.customized_setting_group_id;
                
				# summary_shareholder_winloses 中繼表
                INSERT INTO summary_shareholder_winlose_temps(
					summary_time, agent_id, shareholder_category_id, 
					turnover, winlose, win, game_spending, game_profit
				)
				SELECT * 
				FROM (
					SELECT StartTime AS summary_time
						, a.id AS agent_id
						, sc.id AS shareholder_category_id
						, SUM(CASE WHEN scs.calc_bet = 1 THEN IFNULL(ssw.turnover, 0) ELSE 0 END) AS turnover
						, SUM(winlose) AS winlose
                        , CASE WHEN SUM(game_profit) > 0 THEN SUM(game_profit) ELSE 0 END AS win
						, SUM(game_spending) AS game_spending
						, SUM(game_profit) AS game_profit
					FROM agent_temps AS a
					JOIN shareholder_categories AS sc
						ON sc.agent_group_id = AgentGroupId
						AND sc.`status` = 1
					JOIN shareholder_category_settings AS scs 
						ON scs.shareholder_category_id = sc.id
						AND scs.`status` = 1
					JOIN summary_shareholder_winloses AS ssw 
						ON ssw.agent_id = a.id
						AND ssw.summary_time BETWEEN StartTime AND EndTime
                        # 20251008 Mars 補帳用, 先註解待後續使用
						/*AND (
							(ssw.vendor_id = 102 AND ssw.summary_time BETWEEN DATE_SUB(StartTime, INTERVAL 7 DAY) AND EndTime)
							OR
							(ssw.vendor_id != 102 AND ssw.summary_time BETWEEN StartTime AND EndTime)
						)*/
                        AND ssw.vendor_id = scs.vendor_id
						AND (
							scs.game_type_id = ssw.game_type_id
							OR
							scs.game_type_id = 0
						)
					WHERE a.general_agent_id = AgentId
					GROUP BY a.id, sc.id
				) AS z
				ON DUPLICATE KEY UPDATE 
					turnover = z.turnover,
					winlose = z.winlose,
					win = z.win,
					game_spending = z.game_spending,
					game_profit = z.game_profit;
                
                ##### 中繼表 end ##############################
                
				# 計算代理階層
				SELECT MAX(`level`) INTO MaxLevel
				FROM agent_temps 
				WHERE general_agent_id = AgentId;
                
				# 找出未設置分桶的代理, 依上層代理數值給定
				WHILE MaxLevel >= ThisLevel 
				DO
					INSERT INTO agent_personal_settings(
						agent_id, 
                        setting_id, 
                        sub_category, 
                        `value`, 
                        created_by
					)
					SELECT a.id, 
						PersonalSettingKey, 
                        '', 
                        CASE WHEN (pps.`value` - ShareholderAgencyGap) > 0
                            THEN (pps.`value` - ShareholderAgencyGap)
                            ELSE 0
                            END AS `value`, 
                        0 AS created_by
					FROM agent_temps AS a 
					JOIN agent_personal_settings AS pps 
						ON pps.agent_id = a.parent 
						AND pps.setting_id = PersonalSettingKey
					LEFT JOIN agent_personal_settings AS aps 
						ON aps.agent_id = a.id 
						AND aps.setting_id = PersonalSettingKey
					WHERE a.general_agent_id = AgentId
					AND a.`level` = ThisLevel
					AND `aps`.`value` IS NULL;
                    
					SET ThisLevel = ThisLevel + 1;
				END WHILE;
                
                # 重置
                UPDATE summary_shareholders AS r 
                JOIN agent_temps AS a 
					ON a.id = r.agent_id
					AND general_agent_id = AgentId
                SET r.hierarchy_path = ''
					, r.share_point = 0
					, r.upper_share_point = 0
					, r.direct_rate = 0
					, r.direct_turnover = 0
					, r.direct_nett_profit = 0
					, r.team_rate = 0
					, r.team_turnover = 0
					, r.team_nett_profit = 0
					, r.nett_profit = 0
                    , r.direct_win = 0
                    , r.direct_win_rate = 0
                    , r.direct_win_nett_profit = 0
                    , r.team_win = 0
                    , r.team_win_rate = 0
                    , r.team_win_nett_profit = 0
					, r.remark = ''
                WHERE r.summary_time BETWEEN StartTime AND EndTime;
                
				#判斷執行筆數
				SELECT '重置', ROW_COUNT();
				# 分桶計算
                SET ThisLevel = MaxLevel;
				WHILE ThisLevel > 1 
				DO
					# 有下級代理業績
					IF HaveSubData = 0 THEN
						IF EXISTS (
							SELECT * 
							FROM summary_shareholders AS r
							JOIN agent_temps AS a 
								ON a.id = r.agent_id 
								AND a.general_agent_id = AgentId
								AND a.`level` = (ThisLevel + 1)
							WHERE r.summary_time BETWEEN StartTime AND EndTime
						) THEN
							SET HaveSubData = 1;
						END IF;
					END IF;
                
					IF HaveSubData = 0 THEN
						# 無下級代理資料
						# Calc Vendor
						INSERT INTO summary_shareholders(
							summary_time, agent_id, parent_id, 
							shareholder_category_id,
							hierarchy_path, share_point, upper_share_point,  
							direct_rate, direct_turnover, direct_nett_profit, 
							team_rate, team_turnover, team_nett_profit, 
							rate, 
							direct_win_rate, direct_win, direct_win_nett_profit, 
							team_win_rate, team_win, team_win_nett_profit, 
                            remark
						)
						SELECT StartTime AS summary_time, z.agent_id, z.parent_id
							, z.shareholder_category_id
							, z.hierarchy_path, z.share_point, z.upper_share_point
                            
                            # total_bet_nett_profit
							, TRUNCATE(z.direct_rate, 4) AS direct_rate
							, z.direct_turnover AS direct_turnover
							, TRUNCATE((z.total_bet_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4) AS direct_nett_profit
							, TRUNCATE(z.team_rate, 4) AS team_rate
							, z.team_turnover AS team_turnover
							, TRUNCATE((z.total_bet_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4) AS team_nett_profit
							, TRUNCATE(z.rate, 4) AS rate
                            
							# 業績分紅, total_win_nett_profit
                            , TRUNCATE(z.direct_win_rate, 4) AS direct_win_rate
							, z.direct_win AS direct_win
							, TRUNCATE((z.total_win_nett_profit * z.direct_win_rate / z.total_share_point * z.share_point), 4) AS direct_win_nett_profit
							, TRUNCATE(z.team_win_rate, 4) AS team_win_rate
							, z.team_win AS team_win
							, TRUNCATE((z.total_win_nett_profit * z.team_win_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4) AS team_win_nett_profit
							
							, CONCAT(
								'direct bet:', z.total_bet_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team bet:', z.total_bet_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')',
								'direct win:', z.total_win_nett_profit, ' * ', z.direct_win_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team win:', z.total_win_nett_profit, ' * ', z.team_win_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
							) as remark
						FROM (
							SELECT x.agent_id
								, x.parent_id
								, x.shareholder_category_id
								, x.hierarchy_path
								, t.share_point AS total_share_point
								, paps.`value` AS upper_share_point
								, aps.`value` AS share_point
								, x.direct_turnover AS direct_turnover
								, CASE WHEN t.turnover = 0 
									THEN x.direct_turnover
									ELSE (x.direct_turnover / t.turnover)
									END AS direct_rate
								, x.direct_turnover AS team_turnover
								, CASE WHEN t.turnover = 0 
									THEN x.direct_turnover
									ELSE (x.direct_turnover / t.turnover)
									END AS team_rate
								, t.nett_profit AS total_nett_profit
								, t.bet_ratio
                                , t.nett_profit * (t.bet_ratio / (t.bet_ratio + t.commission_ratio)) AS total_bet_nett_profit
								, t.commission_ratio
								# 公司净利润负数的情况，业绩分红应该放0  只计算投注额分红
                                , CASE WHEN t.nett_profit > 0
									THEN t.nett_profit * (t.commission_ratio / (t.bet_ratio + t.commission_ratio))
                                    ELSE 0
                                    END total_win_nett_profit
                                , CASE WHEN t.turnover = 0 
									THEN x.direct_turnover
									ELSE (x.direct_turnover / t.turnover)
									END AS rate
                                    
								# 業績分紅, total_win_nett_profit
								, x.direct_win AS direct_win
								, CASE WHEN t.net_win = 0 
									THEN 0
									ELSE (x.direct_win / t.net_win)
									END AS direct_win_rate
								, x.direct_win AS team_win
								, CASE WHEN t.net_win = 0 
									THEN 0
									ELSE (x.direct_win / t.net_win)
									END AS team_win_rate
							FROM (
								SELECT a.id AS agent_id
									, a.username
									, a.customized_setting_group_id AS agent_group_id
									, a.parent AS parent_id
									, ssw.shareholder_category_id
									, a.hierarchy_path
									, ssw.turnover AS direct_turnover
									, ssw.win AS direct_win
								FROM agent_temps AS a
                                JOIN summary_shareholder_winlose_temps AS ssw
									ON ssw.agent_id = a.id
                                    AND summary_time BETWEEN StartTime AND EndTime
								WHERE a.general_agent_id = AgentId
								AND a.`level` = ThisLevel
								AND NOT EXISTS (
									SELECT *
									FROM shareholder_cancels AS sc
									WHERE sc.agent_id = ssw.agent_id
									AND sc.summary_date BETWEEN StartTime AND EndTime
									AND sc.`status` = 1
								)
							) AS x
							# 取總業績
							JOIN summary_shareholder_temps AS t
								ON t.shareholder_category_id = x.shareholder_category_id
								AND t.summary_time BETWEEN StartTime AND EndTime
								AND t.agent_group_id = AgentGroupId
							# 各代理佔成
							JOIN agent_personal_settings AS aps 
								ON aps.agent_id = x.agent_id 
								AND aps.setting_id = PersonalSettingKey
							# 上級代理佔成
							LEFT JOIN agent_personal_settings AS paps 
								ON paps.agent_id = x.parent_id 
								AND paps.setting_id = PersonalSettingKey
						) AS z
						ON DUPLICATE KEY UPDATE 
							shareholder_category_id = z.shareholder_category_id
							, hierarchy_path = z.hierarchy_path
							, share_point = z.share_point
							, upper_share_point = z.upper_share_point
                            
                            # total_bet_nett_profit
							, direct_rate = TRUNCATE(z.direct_rate, 4)
							, direct_turnover = z.direct_turnover
							, direct_nett_profit = TRUNCATE((z.total_bet_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4)
							, team_rate = TRUNCATE(z.team_rate, 4)
							, team_turnover = z.team_turnover 
							, team_nett_profit = TRUNCATE((z.total_bet_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4)
							, rate = TRUNCATE(z.rate, 4) 
							
							# 業績分紅, total_win_nett_profit
                            , direct_win_rate = TRUNCATE(z.direct_win_rate, 4)
							, direct_win = z.direct_win
							, direct_win_nett_profit = TRUNCATE((z.total_win_nett_profit * z.direct_win_rate / z.total_share_point * z.share_point), 4)
							, team_win_rate = TRUNCATE(z.team_win_rate, 4) 
							, team_win = z.team_win
							, team_win_nett_profit = TRUNCATE((z.total_win_nett_profit * z.team_win_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4)
							
                            , remark = CONCAT(
								'direct bet:', z.total_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team bet:', z.total_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')',
								'direct win:', z.total_win_nett_profit, ' * ', z.direct_win_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team win:', z.total_win_nett_profit, ' * ', z.team_win_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
							);
                    ELSE
						# 有下級代理資料
						# Calc Vendor
						INSERT INTO summary_shareholders(
							summary_time, agent_id, parent_id, 
							shareholder_category_id,
							hierarchy_path, share_point, upper_share_point,  
							direct_rate, direct_turnover, direct_nett_profit, 
							team_rate, team_turnover, team_nett_profit, 
                            rate, 
							direct_win_rate, direct_win, direct_win_nett_profit, 
							team_win_rate, team_win, team_win_nett_profit, 
							remark
						)
						SELECT StartTime AS summary_time, z.agent_id, z.parent_id
							, z.shareholder_category_id
							, z.hierarchy_path, z.share_point, z.upper_share_point
                            
                            # total_bet_nett_profit
							, TRUNCATE(z.direct_rate, 4) AS direct_rate
							, z.direct_turnover AS direct_turnover
							, TRUNCATE((z.total_bet_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4) AS direct_nett_profit
							, TRUNCATE(z.team_rate, 4) AS team_rate
							, z.team_turnover AS team_turnover
							, TRUNCATE((z.total_bet_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4) AS team_nett_profit
							, TRUNCATE(z.rate, 4) AS rate
							
							# 業績分紅, total_win_nett_profit
                            , TRUNCATE(z.direct_win_rate, 4) AS direct_win_rate
							, z.direct_win AS direct_win
							, TRUNCATE((z.total_win_nett_profit * z.direct_win_rate / z.total_share_point * z.share_point), 4) AS direct_win_nett_profit
							, TRUNCATE(z.team_win_rate, 4) AS team_win_rate
							, z.team_win AS team_win
							, TRUNCATE((z.total_win_nett_profit * z.team_win_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4) AS team_win_nett_profit
							
                            , CONCAT(
								'direct: bet', z.total_bet_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team bet:', z.total_bet_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')',
								'direct win:', z.total_win_nett_profit, ' * ', z.direct_win_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team win:', z.total_win_nett_profit, ' * ', z.team_win_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
							) as remark
						FROM (
							SELECT x.agent_id
								, x.parent_id
								, x.shareholder_category_id
								, x.hierarchy_path
								, t.share_point AS total_share_point
								, paps.`value` AS upper_share_point
								, aps.`value` AS share_point
								, x.direct_turnover AS direct_turnover
								, CASE WHEN t.turnover = 0 
									THEN x.direct_turnover
									ELSE (x.direct_turnover / t.turnover)
									END AS direct_rate
								, IFNULL(x.team_turnover, 0) + x.direct_turnover AS team_turnover
								, CASE WHEN t.turnover = 0 
									THEN (IFNULL(x.team_turnover, 0) + x.direct_turnover)
									ELSE ((IFNULL(x.team_turnover, 0) + x.direct_turnover) / t.turnover)
									END AS team_rate
                                    
								, t.nett_profit AS total_nett_profit
                                , t.bet_ratio
                                , t.nett_profit * (t.bet_ratio / (t.bet_ratio + t.commission_ratio)) AS total_bet_nett_profit
								, t.commission_ratio
								# 公司净利润负数的情况，业绩分红应该放0  只计算投注额分红
                                , CASE WHEN t.nett_profit > 0
									THEN t.nett_profit * (t.commission_ratio / (t.bet_ratio + t.commission_ratio))
                                    ELSE 0
                                    END total_win_nett_profit
                                , CASE WHEN t.turnover = 0 
									THEN (IFNULL(x.team_turnover, 0) + x.direct_turnover)
									ELSE ((IFNULL(x.team_turnover, 0) + x.direct_turnover) / t.turnover)
									END AS rate
                                    
								# 業績分紅
								, x.direct_win AS direct_win
								, CASE WHEN t.net_win = 0 
									THEN 0
									ELSE (x.direct_win / t.net_win)
									END AS direct_win_rate
								, IFNULL(x.team_win, 0) + x.direct_win AS team_win
								, CASE WHEN t.net_win = 0 
									THEN 0
									ELSE ((IFNULL(x.team_win, 0) + x.direct_win) / t.net_win)
									END AS team_win_rate
							FROM (
								SELECT a.id AS agent_id
									, a.username
									, a.customized_setting_group_id AS agent_group_id
									, a.parent AS parent_id
									, sc.id AS shareholder_category_id
									, a.hierarchy_path
									, IFNULL(ssw.turnover, 0) AS direct_turnover
                                    , IFNULL(ss.team_turnover, 0) AS team_turnover
									, IFNULL(ssw.win, 0) AS direct_win
                                    , IFNULL(ss.team_win, 0) AS team_win
								FROM agent_temps AS a
								JOIN shareholder_categories AS sc
									ON sc.agent_group_id = AgentGroupId
									AND sc.`status` = 1
								LEFT JOIN summary_shareholder_winlose_temps AS ssw 
									ON ssw.agent_id = a.id
                                    AND ssw.summary_time BETWEEN StartTime AND EndTime
                                    AND ssw.shareholder_category_id = sc.id
                                # 取下級代理業績
								LEFT JOIN (
									SELECT parent_id
										, shareholder_category_id
										, SUM(team_turnover) AS team_turnover
										, SUM(team_nett_profit) AS team_nett_profit
										, SUM(team_win) AS team_win
										, SUM(team_win_nett_profit) AS team_win_nett_profit
									FROM summary_shareholders
									WHERE summary_time BETWEEN StartTime AND EndTime
									GROUP BY parent_id, shareholder_category_id
								) AS ss 
									ON ss.parent_id = a.id
                                    AND ss.shareholder_category_id = sc.id
								WHERE a.general_agent_id = AgentId
								AND a.`level` = ThisLevel
								AND NOT EXISTS (
									SELECT *
									FROM shareholder_cancels AS sc
									WHERE sc.agent_id = ssw.agent_id
									AND sc.summary_date BETWEEN StartTime AND EndTime
									AND sc.`status` = 1
								)
							) AS x
							# 取總業績
							JOIN summary_shareholder_temps AS t
								ON t.shareholder_category_id = x.shareholder_category_id
								AND t.summary_time BETWEEN StartTime AND EndTime
								AND t.agent_group_id = AgentGroupId
							# 各代理佔成
							JOIN agent_personal_settings AS aps 
								ON aps.agent_id = x.agent_id 
								AND aps.setting_id = PersonalSettingKey
							# 上級代理佔成
							LEFT JOIN agent_personal_settings AS paps 
								ON paps.agent_id = x.parent_id 
								AND paps.setting_id = PersonalSettingKey
							WHERE x.direct_turnover != 0 OR x.team_turnover != 0
						) AS z
						ON DUPLICATE KEY UPDATE 
							shareholder_category_id = z.shareholder_category_id
							, hierarchy_path = z.hierarchy_path
							, share_point = z.share_point
							, upper_share_point = z.upper_share_point
                            
                            # total_bet_nett_profit
							, direct_rate = TRUNCATE(z.direct_rate, 4)
							, direct_turnover = z.direct_turnover
							, direct_nett_profit = TRUNCATE((z.total_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4)
							, team_rate = TRUNCATE(z.team_rate, 4)
							, team_turnover = z.team_turnover 
							, team_nett_profit = TRUNCATE((z.total_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4)
							, rate = TRUNCATE(z.rate, 4) 
							
							# 業績分紅, total_win_nett_profit
                            , direct_win_rate = TRUNCATE(z.direct_win_rate, 4)
							, direct_win = z.direct_win
							, direct_win_nett_profit = TRUNCATE((z.total_win_nett_profit * z.direct_win_rate / z.total_share_point * z.share_point), 4)
							, team_win_rate = TRUNCATE(z.team_win_rate, 4)
							, team_win = z.team_win
							, team_win_nett_profit = TRUNCATE((z.total_win_nett_profit * z.team_win_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4)
						
                            , remark = CONCAT(
								'direct bet:', z.total_bet_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team bet:', z.total_bet_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')',
								'direct win:', z.total_win_nett_profit, ' * ', z.direct_win_rate, ' / ', z.total_share_point, '*', z.share_point, '; ',
								'team win:', z.total_win_nett_profit, ' * ', z.team_win_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
							);
                    END IF;
                    
					#判斷執行筆數
					#SELECT '分桶計算', PersonalSettingKey, AgentId, HierarchyPath, AgentGroupId, ThisLevel, MaxLevel, StartTime, EndTime, HaveSubData, ROW_COUNT(), SLEEP(StopSeconds);
					SELECT '分桶計算', AgentId, ThisLevel, MaxLevel, StartTime, EndTime, HaveSubData, ROW_COUNT(), SLEEP(StopSeconds);
					
                    SET ThisLevel = ThisLevel - 1;
				END WHILE;
                
				#0:未派發, 1:已派發, 2:待派發, 3:已輸入數據(未結算), 4:已結算, 5:已計算, 6:已發佈數據
                UPDATE shareholder_payout_logs
                SET `status` = 4
                WHERE id = PayoutRecordId
                AND `status` in (0, 6);
                
				SET ExecEnd = NOW();
				CALL COM_Send_Error_Message(
					CONCAT(
						'[系統通知] SP_calc_shareholder2(',
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