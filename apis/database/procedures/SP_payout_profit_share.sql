CREATE PROCEDURE `SP_payout_profit_share`(
    IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_PayoutRecordId INT
)
BEGIN
    # 佔成派彩
    # 2025/11/01 Mars 			增加
	# 2026/03/03 Mars 			transaction_log_agents.from_limit_of_credit/to_limit_of_credit 改用after balance

    DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE SummaryDate DATE
		DEFAULT IFNULL(
            p_SummaryDate,
            DATE(DATE_SUB(DATE_ADD(NOW(), INTERVAL 8 HOUR), INTERVAL 1 DAY))
		);
    DECLARE StartTime DATETIME
		DEFAULT DATE_SUB(SummaryDate, INTERVAL 8 HOUR);
    DECLARE EndTime DATETIME
		DEFAULT DATE_SUB(DATE_ADD(StartTime, INTERVAL 1 DAY), INTERVAL 1 SECOND);

    DECLARE ToAgentId INT DEFAULT 0;
    DECLARE AgentId INT DEFAULT IFNULL(p_AgentId, 0);
    DECLARE PayoutRecordId INT DEFAULT IFNULL(p_PayoutRecordId, 0);
    DECLARE AgentGroupId INT
        DEFAULT (SELECT customized_setting_group_id FROM agents WHERE id = p_AgentId);
    DECLARE GeneralAgentBeforeBalance, GeneralAgentAfterBalance, GeneralAgentWinAfterBalance,
        BeforeBalance, AfterBalance, WinAfterBalance, 
        TotalProfit, DirectProfit, TeamProfit DECIMAL(18, 4) DEFAULT 0;
    DECLARE GeneralAgentIsBalanceUnlimited, IsBalanceUnlimited
        INT DEFAULT 0;
    DECLARE TransactionType INT DEFAULT 9;
    DECLARE AgentWalletId INT DEFAULT 0;
    DECLARE Done INT DEFAULT 0;
    DECLARE HierarchyPath VARCHAR(50) DEFAULT
        (SELECT hierarchy_path FROM agents WHERE id = AgentId);

    # 取出該期派獎清單
    DECLARE cur CURSOR FOR
        SELECT agent_id
			, IFNULL(SUM(direct_profit), 0) AS direct_profit
			, IFNULL(SUM(team_profit), 0) AS team_profit
			, IFNULL(SUM(profit), 0) AS profit
        FROM (
			SELECT agent_id AS agent_id
				, SUM(direct_profit) AS direct_profit
                , SUM(team_profit) AS team_profit
                , SUM(profit) AS profit
			FROM summary_profit_shares
			WHERE summary_time BETWEEN StartTime AND EndTime
			AND status = 0
			AND profit != 0
			GROUP BY agent_id
		) AS z
		JOIN agents AS a
			ON a.id = z.agent_id
			AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '/%')
        # 排除總代理
        WHERE z.agent_id != AgentId
        GROUP BY agent_id;
        
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
	BEGIN
		GET DIAGNOSTICS CONDITION 1
			@p2 = MESSAGE_TEXT;

            ROLLBACK;
            
			UPDATE profit_share_payout_logs
			SET status = 4
			WHERE id = PayoutRecordId
			AND status = 5;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_payout_profit_share(',
                    IFNULL(p_SummaryDate, 'NULL'), ',',
                    IFNULL(p_AgentId, 'NULL'), ',',
                    IFNULL(p_PayoutRecordId, 'NULL'),
                    ') 異常 ', @p2),
                'db'
			);
	END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    SET ExecStart = NOW();
    
    START TRANSACTION;

	# 1. 悲觀鎖：鎖定這行資料，確保沒人能同時動它
	SELECT status INTO @current_status
	FROM profit_share_payout_logs
	WHERE id = PayoutRecordId
	FOR UPDATE;

	# 2. 檢查狀態是否為待處理 (4)
    IF @current_status != 4 THEN
        # 如果沒更新到資料，代表該 ID 不存在、或是已經被其他人搶先處理了
        ROLLBACK;
        # 這裡可以選擇直接退出或拋出錯誤通知
        CALL COM_Send_Error_Message('SP_payout_profit_share 重複觸發攔截, 此筆紀錄已在處理中或已完成', 'db');
        #LEAVE; # 或者結束 SP
        
	ELSE
    
		# 將狀態改為派發中
		UPDATE profit_share_payout_logs
		SET status = 5
		WHERE id = PayoutRecordId;
    

		OPEN cur;
		REPEAT
			FETCH cur INTO ToAgentId, DirectProfit, TeamProfit, TotalProfit;
			IF NOT Done THEN
				IF TotalProfit != 0 THEN
					SELECT balance, is_balance_unlimited
					INTO BeforeBalance, IsBalanceUnlimited
					FROM agents
					WHERE id = ToAgentId
					FOR UPDATE;
	
					# 子代理
					IF IsBalanceUnlimited = 0 THEN
						SELECT balance, is_balance_unlimited
						INTO GeneralAgentBeforeBalance, GeneralAgentIsBalanceUnlimited
						FROM agents
						WHERE id = AgentId
						FOR UPDATE;
	
						SET AfterBalance = (BeforeBalance + TotalProfit);
						IF GeneralAgentIsBalanceUnlimited = 0 THEN
							SET GeneralAgentAfterBalance = (GeneralAgentBeforeBalance - TotalProfit);
						END IF;
						
						IF TotalProfit < 0 THEN
							SELECT id, balance, is_unlimited
							INTO AgentWalletId, BeforeBalance, IsBalanceUnlimited
							FROM agent_wallets
							WHERE agent_id = ToAgentId
							AND wallet_type_id = 3
							FOR UPDATE;
						
							INSERT INTO wallet_transaction_log_agents(
								wallet_type_id, agent_id, type, amount, 
								before_balance, balance, 
								operator, operator_before_balance, 
								operator_balance, remark, status
							)
							VALUES(
								3, ToAgentId, 3, TotalProfit,
								BeforeBalance, (BeforeBalance + TotalProfit),
								51, 0, 0, '佔成結算', 1
							);
							
							#IF GeneralAgentIsBalanceUnlimited = 0 THEN
							#	UPDATE agents
							#	SET balance = (balance - TotalProfit)
							#	WHERE id = AgentId;
							#END IF;
		
							UPDATE agent_wallets
							SET balance = (balance + TotalProfit)
							WHERE id = AgentWalletId;
						ELSE 
							INSERT INTO transaction_log_agents(
								from_agent_id, to_agent_id, type, amount,
								from_limit_of_credit, from_used_credit,
								to_limit_of_credit, to_used_credit,
								operator, platform, remark, status
							)
							VALUES(
								AgentId, ToAgentId, TransactionType, TotalProfit,
								#GeneralAgentBeforeBalance, 0, BeforeBalance, 0,
								GeneralAgentAfterBalance, 0, AfterBalance, 0,
								'system', 1,
								CONCAT(
									' Unlimited:', GeneralAgentIsBalanceUnlimited,
									' from:', GeneralAgentBeforeBalance, ' > ', GeneralAgentAfterBalance,
									' to:', BeforeBalance, ' > ', AfterBalance
								),
								1
							);
		
							IF GeneralAgentIsBalanceUnlimited = 0 THEN
								UPDATE agents
								SET balance = (balance - TotalProfit)
								WHERE id = AgentId;
							END IF;
		
							UPDATE agents
							SET balance = (balance + TotalProfit)
							WHERE id = ToAgentId;
						END IF;
						
					END IF;
				END IF;
			END IF;
		UNTIL Done END REPEAT;
		CLOSE cur;
		
		UPDATE summary_profit_shares
        SET status = 1
		WHERE summary_time BETWEEN StartTime AND EndTime
		AND status = 0;
        
		# 將狀態改為已派發
		UPDATE profit_share_payout_logs
		SET status = 1
		WHERE id = PayoutRecordId
		AND status = 5;
		
		SET ExecEnd = NOW();
		CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_payout_profit_share(',
				IFNULL(p_SummaryDate, 'NULL'), ',',
				IFNULL(p_AgentId, 'NULL'), ',',
				IFNULL(p_PayoutRecordId, 'NULL'),
				') 計算完畢 ',
				ExecStart, ' ~ ', ExecEnd),
			'db'
		);
		
		COMMIT;
	END IF;
END