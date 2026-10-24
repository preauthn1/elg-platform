CREATE PROCEDURE `SP_shareholder_payout2`(
    IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_ShareholderPayoutLogId INT
)
BEGIN
    # 派彩
    # 2025/08/20 Mars 			add

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
    DECLARE ShareholderPayoutLogId INT DEFAULT IFNULL(p_ShareholderPayoutLogId, 0);
    DECLARE AgentGroupId INT
        DEFAULT (SELECT customized_setting_group_id FROM agents WHERE id = p_AgentId);
    DECLARE GeneralAgentBeforeBalance, GeneralAgentAfterBalance, GeneralAgentWinAfterBalance,
        BeforeBalance, AfterBalance, WinAfterBalance, NettProfit, BetNettProfit, WinNettProfit DECIMAL(18, 4) DEFAULT 0;
    DECLARE GeneralAgentIsBalanceUnlimited, IsBalanceUnlimited
        INT DEFAULT 0;
    DECLARE TransactionType INT DEFAULT 5;
    DECLARE Done INT DEFAULT 0;
    DECLARE HierarchyPath VARCHAR(50) DEFAULT
        (SELECT hierarchy_path FROM agents WHERE id = AgentId);

    # 取出該期派獎清單
    DECLARE cur CURSOR FOR
        SELECT agent_id
			, IFNULL(SUM(bet_nett_profit), 0) AS bet_nett_profit
			, IFNULL(SUM(win_nett_profit), 0) AS win_nett_profit
        FROM (
			SELECT agent_id AS agent_id
				, SUM(direct_nett_profit) AS bet_nett_profit
                , SUM(direct_win_nett_profit) AS win_nett_profit
			FROM summary_shareholders
			WHERE summary_time BETWEEN StartTime AND EndTime
			AND status = 0
			AND direct_nett_profit != 0
			GROUP BY agent_id
			UNION
			SELECT parent_id AS agent_id
				, SUM(team_nett_profit) AS bet_nett_profit
				, SUM(team_win_nett_profit) AS win_nett_profit
			FROM summary_shareholders
			WHERE summary_time BETWEEN StartTime AND EndTime
			AND status = 0
			AND team_nett_profit != 0
			GROUP BY parent_id
		) AS z
		JOIN agents AS a
			ON a.id = z.agent_id
			AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
        # 排除總代理
        WHERE z.agent_id != AgentId
        GROUP BY agent_id;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
	BEGIN
		GET DIAGNOSTICS CONDITION 1
			@p2 = MESSAGE_TEXT;

            ROLLBACK;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_shareholder_payout2(',
                    IFNULL(p_SummaryDate, 'NULL'), ',',
                    IFNULL(p_AgentId, 'NULL'), ',',
                    IFNULL(p_ShareholderPayoutLogId, 'NULL'),
                    ') 異常 ', @p2),
                'db'
			);
	END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    START TRANSACTION;

    # 將狀態改為派發中
    UPDATE shareholder_payout_logs
    SET status = 2
    WHERE id = ShareholderPayoutLogId;

    OPEN cur;
    REPEAT
        FETCH cur
            INTO ToAgentId, BetNettProfit, WinNettProfit;
        IF NOT Done THEN
			
            SET NettProfit = BetNettProfit + WinNettProfit;
            
            IF BetNettProfit > 0 OR BetNettProfit < 0 THEN
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

                    SET AfterBalance = (BeforeBalance + BetNettProfit);
                    IF GeneralAgentIsBalanceUnlimited = 0 THEN
                        SET GeneralAgentAfterBalance = (GeneralAgentBeforeBalance - BetNettProfit);
                    END IF;

                    INSERT INTO transaction_log_agents(
                        from_agent_id, to_agent_id, type, amount,
                        from_limit_of_credit, from_used_credit,
                        to_limit_of_credit, to_used_credit,
                        operator, platform, remark, status
                    )
                    VALUES(
						AgentId, ToAgentId, TransactionType, BetNettProfit,
						GeneralAgentBeforeBalance, 0, BeforeBalance, 0,
						'system', 1,
						CONCAT(
							' Unlimited:', GeneralAgentIsBalanceUnlimited,
							' from:', GeneralAgentBeforeBalance, ' > ', GeneralAgentAfterBalance,
							' to:', BeforeBalance, ' > ', AfterBalance
						),
						1
					);
                    
                    # 佔成
                    IF WinNettProfit > 0 OR WinNettProfit < 0 THEN
						SET WinAfterBalance = (AfterBalance + WinNettProfit);
						IF GeneralAgentIsBalanceUnlimited = 0 THEN
							SET GeneralAgentWinAfterBalance = (GeneralAgentAfterBalance - WinNettProfit);
						END IF;
                    
						INSERT INTO transaction_log_agents(
							from_agent_id, to_agent_id, type, amount,
							from_limit_of_credit, from_used_credit,
							to_limit_of_credit, to_used_credit,
							operator, platform, remark, status
						)
						VALUES(
							AgentId, ToAgentId, TransactionType, WinNettProfit,
							GeneralAgentBeforeBalance, 0, BeforeBalance, 0,
							'system', 1,
							CONCAT(
								' Unlimited:', GeneralAgentIsBalanceUnlimited,
								' from:', GeneralAgentAfterBalance, ' > ', GeneralAgentWinAfterBalance,
								' to:', AfterBalance, ' > ', WinAfterBalance
							),
							1
						);
                    END IF;

                    IF GeneralAgentIsBalanceUnlimited = 0 THEN
                        UPDATE agents
                        SET balance = (balance - NettProfit)
                        WHERE id = AgentId;
                    END IF;

                    UPDATE agents
                    SET balance = (balance + NettProfit)
                    WHERE id = ToAgentId;
                    
                END IF;
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;

    # 將狀態派發中改為已派獎
    UPDATE shareholder_payout_logs
    SET status = 1
    WHERE id = ShareholderPayoutLogId;

    COMMIT;
END