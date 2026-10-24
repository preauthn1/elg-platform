CREATE PROCEDURE `SP_payout_three_hierarchy_bonus`(IN p_username VARCHAR(10))
BEGIN
    # 2024-07-17 Neil 		issues_2048 [GFF][TPOWER]2.0返水直接派發到會員錢包
    # 2025-11-25 Neil       issues_2898 [GFF]三層返水自動派發真金需可綁流水

    DECLARE ExecStart, ExecEnd DATETIME DEFAULT NOW();
    DECLARE TransactionLogType INT DEFAULT 17; #代理模式獎勵(真金)
    DECLARE RollingChipType INT DEFAULT 4; #代理模式獎勵(禮碼)
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE BeforeRollingChip DECIMAL(18, 4);
    DECLARE FlowLimitMultipleSettingId INT DEFAULT 6013; #返水稽核流水倍數
    DECLARE RewardSettingId INT DEFAULT 7002; #代理獎勵派發 1:平台點數,2:禮碼
    DECLARE AutoPayoutSettingId INT DEFAULT 7003; #代理獎勵自動派發
    DECLARE RemarkText VARCHAR(50) DEFAULT '三层代理奖励';
    DECLARE p_Done INT DEFAULT 0;
    DECLARE p_AgentId, p_MemberId, p_RewardType INT;
    DECLARE BeforeBalance DECIMAL(18, 4) DEFAULT 0;
    DECLARE p_Reward DECIMAL(18, 4) DEFAULT 0; # 代理獎勵未領取金額
    DECLARE p_FlowLimitMultiple INT DEFAULT 0; # 稽核流水倍數
    DECLARE TransactionNo VARCHAR(30) DEFAULT '';
    DECLARE OrderNo VARCHAR(30);
    DECLARE ReferenceId INT DEFAULT 0; #用來存transaction_logs.id，寫入稽核流水用
    
    # 抓出代理組別有開啟自動派發且未領取金額大於0的玩家
    DECLARE cur CURSOR FOR
        SELECT m.parent, l.member_id, acs2.value AS reward_type, (l.bonus_amount - withdraw_amount) AS reward, acs3.value AS flow_limit_multiple
        FROM three_hierarchy_summary_bonus AS l
                 JOIN members AS m ON m.id = l.member_id
                 JOIN agents AS a ON a.id = m.parent
                 JOIN agent_customized_settings AS acs ON acs.agent_group_id = a.customized_setting_group_id AND acs.setting_key = AutoPayoutSettingId AND acs.value = 1
                 JOIN agent_customized_settings AS acs2 ON acs2.agent_group_id = a.customized_setting_group_id AND acs2.setting_key = RewardSettingId
                 JOIN agent_customized_settings AS acs3 ON acs3.agent_group_id = a.customized_setting_group_id AND acs3.setting_key = FlowLimitMultipleSettingId
        WHERE (l.bonus_amount - withdraw_amount) > 0;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_payout_three_hierarchy_bonus(',
                    IFNULL(p_username, 'NULL'), ',',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done = 1;

    OPEN cur;
    REPEAT
        FETCH cur INTO p_AgentId, p_MemberId, p_RewardType, p_Reward, p_FlowLimitMultiple;
        IF NOT p_Done THEN
            # 寫入此次的領獎金額
            UPDATE three_hierarchy_summary_bonus SET withdraw_amount = (withdraw_amount + p_Reward) WHERE member_id = p_MemberId;
            IF p_RewardType = 1 THEN
                SET TransactionNo = CONCAT(DATE_FORMAT(NOW(), '%Y%m%d%H%i%S'),RIGHT(CONCAT('000', FLOOR(RAND()*100)), 4));
                SELECT balance INTO BeforeBalance FROM members WHERE id = p_MemberId;
                # 寫入獎勵
                UPDATE members SET balance = (balance + p_Reward) WHERE id = p_MemberId;
                # 寫入transaction_logs
                INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                            , type, point, vendor_point, rate, before_balance, balance, vendor_balance, status, remark, result, operator, platform, admin)
                VALUES(TransactionNo, p_AgentId, p_MemberId, 0, 0, TransactionLogType, p_Reward, 0, 1, BeforeBalance, (BeforeBalance + p_Reward), 0
                      , 1000, RemarkText, 0, 'system', 0, 51);
            ELSEIF p_RewardType = 2 THEN
                # 取玩家禮碼餘額
                IF EXISTS (SELECT * FROM member_wallets WHERE member_id = p_MemberId AND wallet_type_id = WalletTypeId) THEN
                    SELECT balance INTO BeforeRollingChip
                    FROM member_wallets
                    WHERE member_id = p_MemberId AND wallet_type_id = WalletTypeId;

                    # 更新玩家禮碼餘額
                    UPDATE member_wallets SET balance = (BeforeRollingChip + p_Reward)
                    WHERE member_id = p_MemberId AND wallet_type_id = WalletTypeId;
                ELSE
                    SET BeforeRollingChip = 0;
                    # 創建玩家禮碼錢包
                    INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
                    VALUES(p_MemberId, WalletTypeId, p_Reward, 1, 0);
                END IF;

                # 寫入rolling_chip_logs
                INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance, balance, `status`, remark, `admin`)
                VALUES(p_AgentId, p_MemberId, 0, RollingChipType, p_Reward, BeforeRollingChip, (BeforeRollingChip + p_Reward), 1000, RemarkText, 51);
            END IF;
            
            # transaction_logs.id
			SELECT LAST_INSERT_ID() INTO ReferenceId;
            
            IF p_FlowLimitMultiple > 0 THEN				
				INSERT INTO flow_limits(member_id, `type`, event_id, reference_id, flow_limit, `admin`)
				VALUES(p_MemberId, TransactionLogType, 0, ReferenceId, (p_Reward * p_FlowLimitMultiple), 51);
            END IF;

            SET OrderNo = upper(substring(md5(rand()), 1, 10));
            # 寫入three_hierarchy_withdraw_logs
            INSERT INTO three_hierarchy_withdraw_logs(order_no, `type`, member_id, withdraw_point, remaining_point, `status`, `admin`)
            VALUES(OrderNo, p_RewardType, p_MemberId, p_Reward, 0, 1, 51);
        END IF;
    UNTIL p_Done END REPEAT;
    CLOSE cur;

    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_payout_three_hierarchy_bonus(',
            IFNULL(p_username, 'NULL'),') 派發完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
         );
END
