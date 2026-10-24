CREATE PROCEDURE `SP_calc_profit_share`(
    IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_PayoutRecordId INT
)
BEGIN
    # 佔成計算
    # 2025-11-01 Mars 			ADD
    # 2025-12-16 Mars 			918, MEGA888, PUSSY888算全部
    # 2026-03-02 Mars 			當錢包為負值(-0.0001)時, 就直接為0
    # 2026-05-13 Tim			權重計算納入 PROFIT_SHARE_WALLET_CAP_AMOUNT(95110) 封頂

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
    DECLARE ProfitShareWalletCapAmountKey INT DEFAULT 95110; # 佔成錢包封頂額度 -1=不限
	DECLARE CompanyProfitShareKey INT DEFAULT 8201;  #公司佔成數
	
    DECLARE ShareholderGeneralAgent VARCHAR(50); #份桶總代理
    DECLARE ShareholderGeneralAgentShareValue INT DEFAULT 0; #總代理持有股份值
    DECLARE ShareholderModifyShareValue INT DEFAULT 0; #是否可以修改股份值
    DECLARE ShareholderMaxDifference INT DEFAULT 0; #代理層級的最大差額
    DECLARE ShareholderAgencyGap INT DEFAULT 0; #代理每層上限差額
    DECLARE ShareholderAutoReward INT DEFAULT 0; #是否自動派獎(下午3點派獎)
    DECLARE ShareholderAgentShareValue INT DEFAULT 0;  #分桶股份佔成數
	DECLARE CompanyProfitShareValue VARCHAR(500) DEFAULT '';  #公司佔成數設定

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
				'[系統通知] SP_calc_profit_share(',
				IFNULL(p_SummaryDate, 'NULL'), ',',
				IFNULL(p_AgentId, 'NULL'), ',',
				IFNULL(p_PayoutRecordId, 'NULL'),
				') 異常 ', @p2),
			'db'
			);
	END;

    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_calc_profit_share(',
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

				#0:未派發, 1:已派發, 2:結算中, 3:已結算, 4:已發佈數據
                UPDATE profit_share_payout_logs
                SET `status` = 2
                WHERE id = PayoutRecordId
				AND `status` = 0;

                ##### 中繼表 start ##############################
                # 公司佔成中繼表
				DELETE FROM profit_share_settings
                WHERE summary_time = StartTime
                AND agent_group_id = AgentGroupId;
                
                SELECT value INTO @Json_Value
				FROM agent_customized_settings
				WHERE setting_key = 8201
				AND agent_group_id = AgentGroupId
				LIMIT 1;
                
                INSERT INTO profit_share_settings(
					summary_time, agent_group_id, 
                    max_share_point, min_share_point,
                    direct_percentage, sub_percentage, sub_sub_percentage
                )
				SELECT StartTime AS summary_time
					, AgentGroupId AS agent_group_id
					, jt.max_sp AS max_share_point
					, jt.min_sp AS min_share_point
					, jt.p1 AS direct_percentage
					, jt.p2 AS sub_percentage
					, jt.p3 AS sub_sub_percentage
				FROM JSON_TABLE(
					@Json_Value,
					'$.setting[*]'
					COLUMNS (
						max_sp INT PATH '$.max_sp',
						min_sp INT PATH '$.min_sp',
						p1 INT PATH '$.p1',
						p2 INT PATH '$.p2',
						p3 INT PATH '$.p3'
					)
				) AS jt;
                
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

                # summary_profit_share_winlose_temps 中繼表
                INSERT INTO summary_profit_share_winlose_temps(
                    summary_time, agent_id, shareholder_category_id,
                    direct_percentage, team_percentage, 
                    turnover, winlose, game_spending, game_profit, 
                    cost, event_cost_original, payment_cost_original
                )
                SELECT StartTime AS summary_time
					, z.agent_id
                    , z.shareholder_category_id
                    , IFNULL(psa.direct_percentage, 0) AS direct_percentage
                    , IFNULL(psa.team_percentage, 0) AS team_percentage
                    , z.turnover
                    , z.winlose
                    , z.game_spending
                    , z.game_profit
                    , z.cost
                    , IFNULL(spsec.event_cost, 0) AS event_cost_original
                    , IFNULL(spspc.payment_cost, 0) AS payment_cost_original
                FROM (
					SELECT a.id AS agent_id
						, sc.id AS shareholder_category_id
						, SUM(CASE WHEN scs.calc_bet = 1 THEN IFNULL(ssw.turnover, 0) ELSE 0 END) AS turnover
						, SUM(winlose) AS winlose
						, SUM(game_spending) AS game_spending
						, SUM(game_profit) AS game_profit
						, SUM(cost) AS cost
					FROM agent_temps AS a
					JOIN shareholder_categories AS sc
						ON sc.agent_group_id = AgentGroupId
						AND sc.`status` = 1
					JOIN shareholder_category_settings AS scs
						ON scs.shareholder_category_id = sc.id
						AND scs.`status` = 1
					JOIN summary_profit_share_winloses AS ssw
						ON ssw.agent_id = a.id
						AND ssw.summary_time BETWEEN StartTime AND EndTime
						AND ssw.vendor_id = scs.vendor_id
						AND (
							# 918, MEGA888, PUSSY888算全部
							#scs.vendor_id IN (3, 10, 23) OR
							scs.game_type_id = ssw.game_type_id
								OR
							scs.game_type_id = 0
						)
					JOIN vendors AS v ON v.id = ssw.vendor_id AND v.calc_on_profit_share = 1
					WHERE a.general_agent_id = AgentId
					GROUP BY a.id, sc.id
				) AS z
				# 代理佔成(未設定就是0)
				LEFT JOIN profit_share_agents AS psa
					ON psa.shareholder_category_id = z.shareholder_category_id
					AND psa.agent_id = z.agent_id
					AND psa.status = 1
				LEFT JOIN (
					SELECT agent_id, (SUM(point) * -1) AS event_cost
                    FROM summary_profit_share_event_costs 
                    WHERE summary_time BETWEEN StartTime AND EndTime
                    GROUP BY agent_id
                ) AS spsec
					ON spsec.agent_id = z.agent_id
				LEFT JOIN (
					SELECT agent_id, (SUM(IFNULL(payment_cost, 0)) * -1) AS payment_cost
                    FROM summary_profit_share_payment_costs 
                    WHERE summary_time BETWEEN StartTime AND EndTime
                    GROUP BY agent_id
                ) AS spspc
					ON spspc.agent_id = z.agent_id
                ON DUPLICATE KEY UPDATE
					direct_percentage = IFNULL(psa.direct_percentage, 0),
					team_percentage = IFNULL(psa.team_percentage, 0),
					turnover = z.turnover,
					winlose = z.winlose,
					game_spending = z.game_spending,
					game_profit = z.game_profit,
					cost = z.cost,
                    event_cost_original = IFNULL(spsec.event_cost, 0),
                    event_cost = 0,
                    payment_cost_original = IFNULL(spspc.payment_cost, 0),
                    payment_cost = 0;
                   
				UPDATE summary_profit_share_winlose_temps AS r
                JOIN (
					SELECT agent_id, SUM(ABS(IFNULL(winlose, 0))) AS winlose
                    FROM summary_profit_share_winlose_temps 
                    WHERE summary_time = StartTime
                    GROUP BY agent_id
                ) AS c
					ON c.agent_id = r.agent_id
				#SET event_cost = (r.event_cost_original * (ABS(r.winlose) / c.winlose))
				#	, payment_cost = (r.payment_cost_original * (ABS(r.winlose) / c.winlose))
                SET event_cost = CASE 
						WHEN c.winlose = 0 THEN r.event_cost_original 
						ELSE r.event_cost_original * (ABS(r.winlose) / c.winlose) END, 
                    payment_cost = CASE 
						WHEN c.winlose = 0 THEN r.payment_cost_original 
						ELSE r.payment_cost_original * (ABS(r.winlose) / c.winlose) END
                WHERE r.summary_time = StartTime;
                
                ##### 中繼表 end ##############################

                # 計算代理階層
                SELECT MAX(`level`) INTO MaxLevel
                FROM agent_temps
                WHERE general_agent_id = AgentId;

                # 重置
                UPDATE summary_profit_shares AS r
				JOIN agent_temps AS a
                    ON a.id = r.agent_id
					AND general_agent_id = AgentId
                SET r.hierarchy_path = ''
					, r.company_rate = 0
					, r.share_point = 0
                    , r.wallet_balance = 0
                    , r.cap_amount = -1
					, r.direct_win_lose_original = 0
                    , r.game_cost = 0
                    , r.event_cost = 0
                    , r.payment_cost = 0
					, r.direct_win_lose = 0
                    , r.direct_real_win_lose = 0
					, r.direct_rate = 0
					, r.direct_profit = 0
					, r.team_win_lose = 0
                    , r.team_win_lose_extra = 0
					, r.team_rate = 0
					, r.team_profit = 0
                    , r.weighting = 0
                    , r.profit = 0
					, r.remark = ''
                WHERE r.summary_time BETWEEN StartTime AND EndTime;

                #判斷執行筆數
                #SELECT '重置', ROW_COUNT();

                # 佔成計算
                SET ThisLevel = MaxLevel;
                WHILE ThisLevel > 1 DO
                
                    # 有下級代理業績
                    IF HaveSubData = 0 THEN
                        IF EXISTS (
                            SELECT *
                            FROM summary_profit_shares AS r
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
						INSERT INTO summary_profit_shares(
                            summary_time, agent_id, parent_id, 
                            shareholder_category_id, hierarchy_path, 
                            company_rate, share_point, wallet_balance,
                            direct_win_lose_original, game_cost, event_cost, payment_cost, 
                            direct_real_win_lose, direct_win_lose, direct_rate, direct_profit,
                            team_win_lose, team_win_lose_extra, team_rate, team_profit, 
                            remark
                        )
						SELECT StartTime AS summary_time, z.agent_id, z.parent_id
                             , z.shareholder_category_id, z.hierarchy_path
                             , z.company_rate, z.share_point, z.wallet_balance
                             
                             , z.direct_win_lose_original
                             , z.game_cost
                             , z.event_cost
                             , z.payment_cost
                             , z.direct_real_win_lose
                             , z.direct_win_lose
                             , TRUNCATE(z.direct_rate, 4) AS direct_rate
                             , (z.direct_win_lose * z.direct_rate) AS direct_profit
                             
                             , z.team_win_lose
                             , z.team_win_lose_extra
                             , TRUNCATE(z.team_rate, 4) AS team_rate
                             , ((z.team_win_lose + z.team_win_lose_extra) * z.team_rate) AS team_profit
                             
                             , CONCAT(
								'direct:', z.direct_win_lose, ' * ', z.direct_rate, '; ',
                                'team:(', z.team_win_lose, '+', z.team_win_lose_extra, ') * ', z.team_rate
							) as remark
                        FROM (
							SELECT x.agent_id
								, x.parent_id
								, x.shareholder_category_id
								, x.hierarchy_path
                                , ((pss.direct_percentage + pss.sub_percentage + pss.sub_sub_percentage) / 100) AS company_rate
								, aps.`value` AS share_point
                                , IFNULL(wb.balance, 0) AS wallet_balance
                                
                                , x.direct_win_lose_original
                                , x.game_cost
                                , x.event_cost
                                , x.payment_cost
                                , (x.direct_win_lose_original + x.game_cost + x.event_cost + x.payment_cost) AS direct_real_win_lose 
                                , ((x.direct_win_lose_original + x.game_cost + x.event_cost + x.payment_cost) * ((pss.direct_percentage + pss.sub_percentage + pss.sub_sub_percentage) / 100)) AS direct_win_lose 
                                , IFNULL(psa.direct_percentage / 100, 0) AS direct_rate
                                
                                , 0 AS team_win_lose 
                                , 0 AS team_win_lose_extra
                                , IFNULL(psa.team_percentage / 100, 0) AS team_rate
							FROM (
								SELECT a.id AS agent_id
									, a.username
									, a.customized_setting_group_id AS agent_group_id
									, a.parent AS parent_id
									, spswt.shareholder_category_id
									, a.hierarchy_path
									, (spswt.winlose * -1) AS direct_win_lose_original
                                    , spswt.cost AS game_cost
									, IFNULL(spswt.event_cost, 0) AS event_cost
                                    , IFNULL(spswt.payment_cost, 0) AS payment_cost
								FROM agent_temps AS a
								JOIN summary_profit_share_winlose_temps AS spswt
									ON spswt.agent_id = a.id
									AND spswt.summary_time BETWEEN StartTime AND EndTime
								WHERE a.general_agent_id = AgentId
								AND a.`level` = ThisLevel
								AND NOT EXISTS (
									SELECT *
									FROM profit_share_cancels AS sc
									WHERE sc.agent_id = spswt.agent_id
									AND sc.summary_date BETWEEN StartTime AND EndTime
									AND sc.`status` = 1
								)
							) AS x
							# 各代理股份
							JOIN agent_personal_settings AS aps
								ON aps.agent_id = x.agent_id
								AND aps.setting_id = PersonalSettingKey
							JOIN profit_share_settings AS pss
								ON (aps.`value` * 1) BETWEEN pss.min_share_point AND pss.max_share_point
                                AND pss.summary_time = StartTime
                                AND pss.agent_group_id = AgentGroupId
                            LEFT JOIN agent_wallets AS wb
								ON wb.agent_id = x.agent_id
                                AND wb.wallet_type_id = 3
                                AND wb.status = 1
                                AND wb.balance >= 0
							# 代理佔成(未設定就是0)
                            LEFT JOIN profit_share_agents AS psa
								ON psa.shareholder_category_id = x.shareholder_category_id
                                AND psa.agent_id = x.agent_id
                                AND psa.status = 1
						) AS z
                        ON DUPLICATE KEY UPDATE
                            shareholder_category_id = z.shareholder_category_id
                            , hierarchy_path = z.hierarchy_path
                            , company_rate = z.company_rate
                            , share_point = z.share_point
                            , wallet_balance = z.wallet_balance
                            , direct_win_lose_original = z.direct_win_lose_original
                            , game_cost = z.game_cost
                            , event_cost = z.event_cost
                            , payment_cost = z.payment_cost
                            , direct_real_win_lose = z.direct_real_win_lose
                            , direct_win_lose = z.direct_win_lose
                            , direct_rate = TRUNCATE(z.direct_rate, 4)
                            , direct_profit = TRUNCATE((z.direct_win_lose * z.direct_rate), 4)
                            , team_win_lose = z.team_win_lose
                            , team_win_lose_extra = z.team_win_lose_extra
                            , team_rate = TRUNCATE(z.team_rate, 4)
                            , team_profit = TRUNCATE(((z.team_win_lose + z.team_win_lose_extra) * z.team_rate), 4)
                            , remark = CONCAT(
                                'direct:', z.direct_win_lose, ' * ', z.direct_rate, '; '
								'team:(', z.team_win_lose, ' + ', z.team_win_lose_extra, ') * ', z.team_rate
						);
                        
                    ELSE
						INSERT INTO summary_profit_shares(
                            summary_time, agent_id, parent_id, shareholder_category_id, hierarchy_path, 
                            company_rate, share_point, wallet_balance,
                            direct_win_lose_original, game_cost, event_cost, payment_cost, 
                            direct_real_win_lose, direct_win_lose, direct_rate, direct_profit,
                            team_win_lose_original, team_win_lose, team_win_lose_extra, team_rate, team_profit, 
                            remark
                        )
						SELECT StartTime AS summary_time, z.agent_id, z.parent_id
                             , z.shareholder_category_id, z.hierarchy_path
                             , z.company_rate, z.share_point, z.wallet_balance
                             
                             , z.direct_win_lose_original
                             , z.game_cost
                             , z.event_cost
                             , z.payment_cost
                             , z.direct_real_win_lose
                             , z.direct_win_lose
                             , TRUNCATE(z.direct_rate, 4) AS direct_rate
                             , (z.direct_win_lose * z.direct_rate) AS direct_profit
                             
                             , z.team_win_lose_original
                             , z.team_win_lose
                             , z.team_win_lose_extra
                             , TRUNCATE(z.team_rate, 4) AS team_rate
                             , ((z.team_win_lose + z.team_win_lose_extra) * z.team_rate) AS team_profit
                             
                             , CONCAT(
								'direct:', z.direct_win_lose, ' * ', z.direct_rate, '; '
								'team:(', z.team_win_lose, ' + ', z.team_win_lose_extra ,') * ', z.team_rate
							) AS remark
                        FROM (
							SELECT x.agent_id
								, x.parent_id
								, x.shareholder_category_id
								, x.hierarchy_path
                                , ((pss.direct_percentage + pss.sub_percentage + pss.sub_sub_percentage) / 100) AS company_rate
								, aps.`value` AS share_point
                                , IFNULL(wb.balance, 0) AS wallet_balance
                                
                                , x.direct_win_lose_original
                                , x.game_cost
                                , x.event_cost
                                , x.payment_cost
                                , x.direct_win_lose_original AS direct_real_win_lose 
                                , ((x.direct_win_lose_original + x.game_cost + x.event_cost + x.payment_cost) * ((pss.direct_percentage + pss.sub_percentage + pss.sub_sub_percentage) / 100)) AS direct_win_lose 
                                , IFNULL(psa.direct_percentage / 100, 0) AS direct_rate
                                
								, x.team_win_lose_original
                                , x.team_win_lose 
                                , ((x.team_win_lose_original * (pss.sub_percentage / 100))) AS team_win_lose_extra
								#, (x.team_win_lose * ((pss.direct_percentage + pss.sub_percentage + pss.sub_sub_percentage) / 100)) AS team_win_lose 
                                , IFNULL(psa.team_percentage / 100, 0) AS team_rate
							FROM (
								SELECT a.id AS agent_id
									, a.username
									, a.customized_setting_group_id AS agent_group_id
									, a.parent AS parent_id
									, sc.id AS shareholder_category_id
									, a.hierarchy_path
									, IFNULL((spswt.winlose * -1), 0) AS direct_win_lose_original
								    , IFNULL(spswt.cost, 0) AS game_cost
                                    , IFNULL(spswt.event_cost, 0) AS event_cost
                                    , IFNULL(spswt.payment_cost, 0) AS payment_cost
									, IFNULL(ss.team_win_lose, 0) AS team_win_lose
									, IFNULL(ss.direct_win_lose_original, 0) + IFNULL(ss.team_win_lose_original, 0) AS team_win_lose_original
									, IFNULL(ss.team_win_lose_extra, 0) AS team_win_lose_extra
                                FROM agent_temps AS a
								JOIN shareholder_categories AS sc
									ON sc.agent_group_id = AgentGroupId
									AND sc.`status` = 1
								LEFT JOIN summary_profit_share_winlose_temps AS spswt
									ON spswt.agent_id = a.id
									AND spswt.summary_time BETWEEN StartTime AND EndTime
									AND spswt.shareholder_category_id = sc.id
								# 取下級代理業績
								LEFT JOIN (
									SELECT parent_id
										, shareholder_category_id
										, SUM(direct_win_lose + team_win_lose + team_win_lose_extra - profit) AS team_win_lose
                                        
										, SUM(direct_win_lose_original) 
											+ SUM(game_cost)
											+ SUM(event_cost)
											+ SUM(payment_cost)
											AS direct_win_lose_original
                                            
										, SUM(team_win_lose_original) AS team_win_lose_original
                                        
										, SUM(team_win_lose_extra) AS team_win_lose_extra
									FROM summary_profit_shares
									WHERE summary_time BETWEEN StartTime AND EndTime
									GROUP BY parent_id, shareholder_category_id
								) AS ss
									ON ss.parent_id = a.id
									AND ss.shareholder_category_id = sc.id
								WHERE a.general_agent_id = AgentId
								AND a.`level` = ThisLevel
								AND NOT EXISTS (
									SELECT *
									FROM profit_share_cancels AS sc
									WHERE sc.agent_id = spswt.agent_id
									AND sc.summary_date BETWEEN StartTime AND EndTime
									AND sc.`status` = 1
								)
							) AS x
							# 各代理股份
							JOIN agent_personal_settings AS aps
								ON aps.agent_id = x.agent_id
								AND aps.setting_id = PersonalSettingKey
							JOIN profit_share_settings AS pss
								ON (aps.`value` * 1) BETWEEN pss.min_share_point AND pss.max_share_point 
                                AND pss.summary_time = StartTime
                                AND pss.agent_group_id = AgentGroupId
                            LEFT JOIN agent_wallets AS wb
								ON wb.agent_id = x.agent_id
                                AND wb.wallet_type_id = 3
                                AND wb.status = 1
                                AND wb.balance >= 0
							# 代理佔成(未設定就是0)
                            LEFT JOIN profit_share_agents AS psa
								ON psa.shareholder_category_id = x.shareholder_category_id
                                AND psa.agent_id = x.agent_id
                                AND psa.status = 1
							WHERE x.direct_win_lose_original != 0 OR x.team_win_lose_original != 0
						) AS z
                        ON DUPLICATE KEY UPDATE
                            shareholder_category_id = z.shareholder_category_id
                            , hierarchy_path = z.hierarchy_path
                            , company_rate = z.company_rate
                            , share_point = z.share_point
                            , wallet_balance = z.wallet_balance
                            , direct_win_lose_original = z.direct_win_lose_original
                            , game_cost = z.game_cost
                            , event_cost = z.event_cost
                            , payment_cost = z.payment_cost
                            , direct_real_win_lose = z.direct_real_win_lose
                            , direct_win_lose = z.direct_win_lose
                            , direct_rate = TRUNCATE(z.direct_rate, 4)
                            , direct_profit = TRUNCATE((z.direct_win_lose * z.direct_rate), 4)
                            , team_win_lose_original = z.team_win_lose_original
                            , team_win_lose = z.team_win_lose
                            , team_win_lose_extra = z.team_win_lose_extra
                            , team_rate = TRUNCATE(z.team_rate, 4)
                            , team_profit = TRUNCATE(((z.team_win_lose + z.team_win_lose_extra) * z.team_rate), 4)
                            , remark = CONCAT(
                                'direct:', z.direct_win_lose, ' * ', z.direct_rate, '; '
								'team:(', z.team_win_lose, ' + ', z.team_win_lose_extra ,') * ', z.team_rate
							);
                    END IF;
                    
                    # 計算權重/實際輸贏（effective_wallet：95110 封頂 cap 小於 balance 時以 cap 計權重）
					UPDATE summary_profit_shares AS r
					JOIN (
						SELECT z.agent_id
                            , z.cap_amount
							, CASE 
								WHEN z.is_unlimited = 1 THEN 1 
                                WHEN z.effective_wallet = 0 THEN 0
								WHEN z.effective_wallet >= ABS(z.profit) THEN 1 
                                ELSE TRUNCATE((z.effective_wallet / ABS(z.profit)), 8) END weighting
						FROM (
							SELECT a.id AS agent_id
								, SUM(IFNULL(sps.direct_profit, 0) + IFNULL(sps.team_profit, 0)) AS profit
								, IFNULL(wb.balance, 0) AS balance
                                , IFNULL(cap_ps.cap_amount, -1) AS cap_amount
                                , wb.is_unlimited
                                , CASE
                                    WHEN IFNULL(cap_ps.cap_amount, -1) >= 0
                                        AND IFNULL(cap_ps.cap_amount, -1) < IFNULL(wb.balance, 0)
                                    THEN IFNULL(cap_ps.cap_amount, 0)
                                    ELSE IFNULL(wb.balance, 0)
                                  END AS effective_wallet
							FROM summary_profit_shares AS sps
							JOIN agent_temps AS a
								ON sps.agent_id = a.id
								AND a.`level` = ThisLevel
							LEFT JOIN agent_wallets AS wb
								ON wb.agent_id = a.id
								AND wb.wallet_type_id = 3
								AND wb.status = 1
                                AND wb.balance >= 0
                            LEFT JOIN (
                                SELECT agent_id, (`value` * 1) AS cap_amount
                                FROM agent_personal_settings
                                WHERE setting_id = ProfitShareWalletCapAmountKey
                            ) AS cap_ps ON cap_ps.agent_id = a.id
							WHERE sps.summary_time = StartTime
							GROUP BY a.id, wb.balance, wb.is_unlimited, cap_ps.cap_amount
						) AS z
					) AS a ON a.agent_id = r.agent_id
					SET r.weighting = a.weighting
						, r.cap_amount = a.cap_amount
						, r.profit = (r.direct_profit + r.team_profit) * a.weighting
					WHERE r.summary_time = StartTime;
					
                    #判斷執行筆數
                    #SELECT '佔成計算', AgentId, ThisLevel, MaxLevel, StartTime, EndTime, HaveSubData, ROW_COUNT(), SLEEP(StopSeconds);

                    SET ThisLevel = ThisLevel - 1;
				END WHILE;

                #0:未派發, 1:已派發, 2:結算中, 3:已結算, 4:已發佈數據, 5:派發中
                UPDATE profit_share_payout_logs
                SET `status` = 3
                WHERE id = PayoutRecordId
				AND `status` = 2;

                SET ExecEnd = NOW();
                CALL COM_Send_Error_Message(
                    CONCAT(
                        '[系統通知] SP_calc_profit_share(',
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