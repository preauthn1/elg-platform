CREATE PROCEDURE `SP_agent_schedule`(
	IN p_ScheduleTypeId INT
)
BEGIN
	# 會員排程異動
    DECLARE Yestoday DATE 
		DEFAULT DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 1 DAY);
	DECLARE StartTime DATETIME
		DEFAULT DATE_SUB(Yestoday, INTERVAL 8 HOUR);
    DECLARE EndTime DATETIME
		DEFAULT DATE_ADD(StartTime, INTERVAL 1 DAY);
    DECLARE ScheduleTypeId INT
		DEFAULT IFNULL(p_ScheduleTypeId, 0);
    DECLARE ScheduleLogId, AgentId, AgentGroupId, AgentWalletId INT DEFAULT 0;
    DECLARE DataValue VARCHAR(200) DEFAULT '';
    DECLARE ProfitSharingWalletId INT DEFAULT 3;
    DECLARE BeforeBalance, AfterBalance, BeforeWalletBalance, AfterWalletBalance 
		DECIMAL(18, 4) DEFAULT 0;
	DECLARE OperatorUsername VARCHAR(50);
    DECLARE LogMemo TEXT;
    DECLARE Done, RowCount, RowStatus INT DEFAULT 0;
    DECLARE CategoryId, DirectShare, SubShare INT;
    DECLARE BeforeCapValue, AfterCapValue VARCHAR(200) DEFAULT '';
	
    # 取出排程清單
    DECLARE cur CURSOR FOR
		SELECT asl.id, asl.agent_id, asl.data_value
			, a.customized_setting_group_id
		FROM agent_schedule_logs AS asl
        JOIN agents AS a ON a.id = asl.agent_id
		WHERE asl.created_at < EndTime
		AND asl.schedule_type_id = ScheduleTypeId
		AND asl.`status` = 0
        ORDER BY asl.id ASC;
    
	### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
		ROLLBACK;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_agent_schedule(',
                IFNULL(p_ScheduleTypeId, 'NULL'), 
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    
    OPEN cur;
    REPEAT FETCH cur INTO ScheduleLogId, AgentId, DataValue, AgentGroupId;
        IF NOT Done THEN
			#SELECT ScheduleLogId, AgentId, DataValue, AgentGroupId;
            
			START TRANSACTION;
            
			SET RowCount = 0;
            
			SET AgentWalletId = 0;
			SET BeforeBalance = 0;
			SET AfterBalance = 0;
			SET BeforeWalletBalance = 0;
			SET AfterWalletBalance = 0;
			SET BeforeCapValue = '';
			SET AfterCapValue = '';
			SET LogMemo = '';
            SET RowStatus = 1;
                
			IF ScheduleTypeId = 1 THEN
				# 佔成錢包充值
				SELECT IFNULL(id, 0), IFNULL(balance , 0)
				INTO AgentWalletId, BeforeWalletBalance
				FROM agent_wallets
				WHERE agent_id = AgentId
				AND wallet_type_id = ProfitSharingWalletId;
                    
				IF AgentWalletId = 0 THEN
					INSERT INTO agent_wallets(agent_id, wallet_type_id, balance, status)
					VALUES(AgentId, ProfitSharingWalletId, 0, 1);
				END IF;
                    
				INSERT INTO wallet_transaction_log_agents(
					wallet_type_id, agent_id, type, amount, 
					before_balance, balance, 
					operator, operator_before_balance, operator_balance, 
					remark, status
				)
				VALUES (
					3, AgentId, 1, DataValue, 
					BeforeWalletBalance, (BeforeWalletBalance + (DataValue * 1)), 
					51, 0, 0, '', 1 
				);
                    
				UPDATE agent_wallets 
				SET balance = balance + (DataValue * 1)
				WHERE agent_id = AgentId
				AND wallet_type_id = ProfitSharingWalletId
				ORDER BY id ASC LIMIT 1;
                    
				SELECT balance 
				INTO AfterWalletBalance
				FROM agent_wallets
				WHERE agent_id = AgentId
				AND wallet_type_id = ProfitSharingWalletId;
				
				SET LogMemo = CONCAT(
					'Wallet balance: ', BeforeWalletBalance, ' > ', AfterWalletBalance, 
					' Point:', (DataValue * 1)
				);
                
			ELSEIF ScheduleTypeId = 2 THEN
				# 佔成錢包提領, 主錢包充值
				SELECT IFNULL(id, 0), IFNULL(balance , 0)
				INTO AgentWalletId, BeforeWalletBalance
				FROM agent_wallets
				WHERE agent_id = AgentId
				AND wallet_type_id = ProfitSharingWalletId;
						
				IF BeforeWalletBalance >= (DataValue * 1) THEN
					IF AgentWalletId = 0 THEN
						INSERT INTO agent_wallets (agent_id, wallet_type_id, balance, `status`, `admin`)
						VALUES (AgentId, ProfitSharingWalletId, 0, 1, 51);
					END IF;
						
					INSERT INTO wallet_transaction_log_agents(
						wallet_type_id, agent_id, type, amount, 
						before_balance, balance, 
						operator, operator_before_balance, operator_balance, 
						remark, status
					)
					VALUES (
						3, AgentId, 2, (DataValue * 1), 
						BeforeWalletBalance, (BeforeWalletBalance - (DataValue * 1)), 
						51, 0, 0, '', 1 
					);
						
					## 佔成錢包扣款
					UPDATE agent_wallets 
					SET balance = balance - (DataValue * 1)
					WHERE agent_id = AgentId
					AND wallet_type_id = ProfitSharingWalletId
					ORDER BY id ASC LIMIT 1;
			
					SELECT balance 
					INTO AfterWalletBalance
					FROM agent_wallets
					WHERE agent_id = AgentId
					AND wallet_type_id = ProfitSharingWalletId;
						
					## 主錢包充值
					SELECT username, IFNULL(balance , 0) 
					INTO OperatorUsername, BeforeBalance
					FROM agents WHERE id = AgentId;
						
					INSERT INTO transaction_log_agents(
						from_agent_id, to_agent_id, `type`, amount, 
						from_limit_of_credit, from_used_credit, 
						to_limit_of_credit, to_used_credit, 
						status, platform, operator
					)
					VALUES (
						AgentId, AgentId, 8, (DataValue * 1), 
						BeforeBalance, 0, 
						(BeforeBalance + (DataValue * 1)), 0, 
						1, 2, OperatorUsername
					);
						
					UPDATE agents 
					SET balance = balance + (DataValue * 1)
					WHERE id = AgentId;
				
					SELECT balance INTO AfterBalance
					FROM agents WHERE id = AgentId;
					
					SET LogMemo = CONCAT(
						'balance: ', BeforeBalance, ' > ', AfterBalance, 
						' Wallet balance: ', BeforeWalletBalance, ' > ', AfterWalletBalance, 
						' Point:', (DataValue  * -1)
					);
				ELSE 
					# 失敗一樣塞進一筆紀錄
					INSERT INTO wallet_transaction_log_agents(
						wallet_type_id, agent_id, type, amount, 
						before_balance, balance, 
						operator, operator_before_balance, operator_balance, 
						remark, status
					)
					VALUES (
						3, AgentId, 2, (DataValue * 1), 
						BeforeWalletBalance, (BeforeWalletBalance - (DataValue * 1)), 
						51, 0, 0, '', 0 
					);
                
					SET LogMemo = CONCAT(
						'Wallet balance: ', BeforeWalletBalance,
						' Point:', (DataValue * -1), 
						' Message: Insufficient balance'
					);
					SET RowStatus = 0;
				END IF;
				
			ELSEIF ScheduleTypeId = 3 THEN
				# 遊戲類別佔成設定
				SELECT IFNULL(JSON_UNQUOTE(JSON_EXTRACT(data_value, '$.category_id')), 0)
					, IFNULL(JSON_UNQUOTE(JSON_EXTRACT(data_value, '$.direct_share')), 0)
					, IFNULL(JSON_UNQUOTE(JSON_EXTRACT(data_value, '$.sub_share')), 0)
				INTO CategoryId, DirectShare, SubShare
				FROM agent_schedule_logs
				WHERE id = ScheduleLogId;
				
				# 將舊的狀態改為0
				UPDATE profit_share_agents
				SET status = 2
				WHERE shareholder_category_id = CategoryId
				AND agent_id = AgentId;
				
				INSERT INTO profit_share_agents(
					shareholder_category_id, agent_id, direct_percentage, team_percentage, status, admin
				)
				VALUES(
					CategoryId, AgentId, DirectShare, SubShare, 1, 51
				);
				
				SET LogMemo = CONCAT(
					'CategoryId: ', CategoryId, 
					' DirectShare: ', DirectShare, 
					' SubShare:', SubShare
				);
				
			ELSEIF ScheduleTypeId = 4 THEN
				# 佔成錢包封頂額度 agent_personal_settings.setting_id = 95110（-1=不限）
				SET BeforeCapValue = IFNULL(
					(
						SELECT aps.value
						FROM agent_personal_settings AS aps
						WHERE aps.agent_id = AgentId
							AND aps.setting_id = 95110
							AND IFNULL(aps.sub_category, '') = ''
						LIMIT 1
					),
					''
				);
				
				# 用 upsert 寫入：ROW_COUNT() 回傳的是「實際變更列數」，
				# 排程值與現值相同時為 0，若據此改走 INSERT 會撞 unique key，
				# 觸發 EXIT HANDLER 讓整批排程中斷（op_ticket/2860）
				INSERT INTO agent_personal_settings(
					agent_id, setting_id, sub_category, value, created_by, admin
				)
				VALUES (AgentId, 95110, '', DataValue, 51, 51)
				ON DUPLICATE KEY UPDATE
					value = VALUES(value),
					admin = 51;
				
				SELECT IFNULL(aps.value, '')
					, CONCAT(
						'PROFIT_SHARE_WALLET_CAP_AMOUNT(95110): ', BeforeCapValue, ' > ', IFNULL(aps.value, '')
					)
				INTO AfterCapValue, LogMemo
				FROM agent_personal_settings AS aps
				WHERE aps.agent_id = AgentId
					AND aps.setting_id = 95110
					AND IFNULL(aps.sub_category, '') = ''
				LIMIT 1;
			END IF;
			
			# 取得影響筆數
			SET RowCount = ROW_COUNT();
			
			###############
			IF RowCount = 1 AND RowStatus = 1 THEN
				UPDATE agent_schedule_logs
				SET `status` = 1, 
					memo = LogMemo
				WHERE id = ScheduleLogId
				AND `status` = 0;
			ELSE
				UPDATE agent_schedule_logs
				SET `status` = 9 
					, memo = CONCAT('處理失敗 ', LogMemo)
				WHERE id = ScheduleLogId
				AND `status` = 0;
			END IF;
			
			COMMIT;
        
        
        END IF;
        
    UNTIL Done END REPEAT;
    CLOSE cur;
END