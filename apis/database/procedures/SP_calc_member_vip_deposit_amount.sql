CREATE PROCEDURE `SP_calc_member_vip_deposit_amount`(IN p_MemberId INT, IN p_VReackAmount DECIMAL(18,4), IN p_VipFlowLimitId INT)
BEGIN

    DECLARE p_MVAgentId, p_VCRID, p_VId, p_VFStatus, AgentId, p_VAgentId INT;
    DECLARE p_VFlowLimit, p_VReach DECIMAL(18,4);
    DECLARE p_DepositAmount, p_MVAmount, p_MVTargetAmount, p_MVMaxTargetAmount, p_RewardPoint DECIMAL(18,4);
    DECLARE p_MVKeepAmount DECIMAL(18,4);
    DECLARE p_BeforeDepositAmount, p_AfterDepositAmount, p_BeforeKeepAmount, p_AfterKeepAmount DECIMAL(18,4);
    DECLARE p_VFLStatus, NowLevel, p_NowLevelId, MaxLevel INT DEFAULT 0;
    DECLARE TransactionType INT DEFAULT 32;
    DECLARE RollingChipType INT DEFAULT 19;
    DECLARE Remark VARCHAR(50);
    DECLARE GiftType INT DEFAULT 1;
    DECLARE OperatorId INT DEFAULT 51;
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE BeforeBalance, AfterBalance DECIMAL(18, 4);
    DECLARE ExpireTime DATETIME;
    DECLARE ExpireHour INT DEFAULT 0;

    SET Remark = 'VIP';

    SELECT vip_content_id, deposit_amount, keep_amount
    INTO p_NowLevelId, p_MVAmount, p_MVKeepAmount
    FROM member_vips
    WHERE member_id = p_MemberId;

    IF p_MVAmount IS NULL THEN
        #找到總代
        SELECT a2.id
        INTO p_VAgentId
        FROM members AS m
                 JOIN agents AS a ON a.id = m.parent
                 JOIN agents AS a2 ON a.hierarchy_path LIKE CONCAT(a2.hierarchy_path, '%') AND a2.role_id = 11
        WHERE m.id = p_MemberId
        order by a2.hierarchy_path desc
        limit 1;

        SELECT id INTO p_VId FROM vip_contents WHERE agent_id = p_VAgentId AND level = 0;
        SELECT p_MemberId,p_VId, p_VAgentId;

        SET p_BeforeDepositAmount = 0;
        SET p_BeforeKeepAmount = 0;
        SET p_AfterDepositAmount = p_VReackAmount;
        SET p_AfterKeepAmount = p_VReackAmount;

        # 創建VIP資料
        IF p_VId IS NOT NULL THEN
            INSERT INTO member_vips(member_id, vip_content_id, deposit_amount, keep_amount, `admin`)
            VALUES(p_MemberId, p_VId, p_VReackAmount, p_VReackAmount, 0);
        END IF;
    ELSE
        SET p_BeforeDepositAmount = p_MVAmount;
        SET p_BeforeKeepAmount = p_MVKeepAmount;
        SET p_AfterDepositAmount = (p_MVAmount + p_VReackAmount);
        SET p_AfterKeepAmount = (p_MVKeepAmount + p_VReackAmount);

        #有資料的話就直接加上去
        UPDATE member_vips
        SET deposit_amount = (deposit_amount + p_VReackAmount), keep_amount = (keep_amount + p_VReackAmount)
        WHERE member_id = p_MemberId;
    END IF;

    # 寫入VIP經驗值異動紀錄
    INSERT INTO member_vip_exp_logs(member_id, vip_flow_limit_id, exp, before_deposit_amount, after_deposit_amount, before_keep_amount, after_keep_amount, `admin`)
        VALUE (p_MemberId, p_VipFlowLimitId, p_VReackAmount, p_BeforeDepositAmount, p_AfterDepositAmount, p_BeforeKeepAmount, p_AfterKeepAmount, 0);

    # 查詢儲值分數是否可以升等
    SELECT mv.deposit_amount, vc.agent_id, SUM(vc2.target_amount), vc.level, vc.id, IFNULL(MAX(vc2.level), 0)
    INTO p_MVAmount, p_MVAgentId, p_MVTargetAmount, NowLevel, p_NowLevelId, MaxLevel
    FROM member_vips AS mv
             JOIN vip_contents AS vc ON vc.id = mv.vip_content_id
             LEFT JOIN vip_contents AS vc2 ON vc2.agent_id = vc.agent_id AND vc2.level > vc.level AND vc2.target_amount > 0
    WHERE mv.member_id = p_MemberId;

    WHILE MaxLevel > 0
        Do
            IF p_MVAmount >= p_MVTargetAmount AND p_MVTargetAmount > 0 THEN
                SELECT id INTO p_VId FROM vip_contents WHERE agent_id = p_MVAgentId AND level = MaxLevel;

                UPDATE member_vips
                SET vip_content_id = p_VId, deposit_amount = (deposit_amount - p_MVTargetAmount)
                  , target_date = DATE_FORMAT(NOW(), '%Y-%m-%d')
                WHERE member_id = p_MemberId;

                # 寫入VIP升等扣除經驗值紀錄（keep_amount 這裡沒有被異動，前後值相同）
                INSERT INTO member_vip_exp_logs(member_id, vip_flow_limit_id, exp, before_deposit_amount, after_deposit_amount, before_keep_amount, after_keep_amount, `admin`)
                    VALUE (p_MemberId, p_VipFlowLimitId, (0 - p_MVTargetAmount), p_MVAmount, (p_MVAmount - p_MVTargetAmount), p_AfterKeepAmount, p_AfterKeepAmount, 0);

                #寫入VIP變更紀錄
                INSERT INTO member_vip_records (member_id, before_vip_content_id, vip_content_id, admin)
                    VALUE (p_MemberId, p_NowLevelId, p_VId, 0);

                #派發達標獎勵
                WHILE NowLevel < MaxLevel
                    DO
                        SET NowLevel = NowLevel + 1;
                        SELECT vcr.id, point_type, point, expired_hour
                        INTO p_VCRID, GiftType, p_RewardPoint, ExpireHour
                        FROM vip_content_rewards as vcr
                                 join vip_contents as vc on vc.id = vcr.vip_content_id and vc.agent_id = p_MVAgentId and vc.level = NowLevel
                        where vcr.vip_reward_id = 4 AND vcr.status = 1;

                        IF p_VCRID IS NOT NULL THEN
                            IF NowLevel = MaxLevel THEN
                                #一個帳號只能領一次 發過了就不發
                                IF NOT EXISTS (SELECT * FROM member_vip_prizes WHERE member_id = p_MemberId AND vip_content_reward_id = p_VCRID) THEN
                                    IF ExpireHour > 0 THEN
										SET ExpireTime = DATE_ADD(NOW(), INTERVAL ExpireHour HOUR);
									ELSEIF ExpireHour = -1 THEN
										IF UTC_TIME() >= '16:00:00' THEN
											SET ExpireTime = CONCAT(DATE(DATE_ADD(UTC_DATE(), INTERVAL 1 DAY)), ' 15:59:59');
										ELSE
											SET ExpireTime = CONCAT(DATE(UTC_DATE()), ' 15:59:59');
										END IF;
									ELSE
										SET ExpireTime = NULL;
									END IF;
                                    
                                    INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, point, expired_time, status, admin)
                                        VALUE(p_MemberId, p_VCRID, GiftType, p_RewardPoint, ExpireTime, 0, 0);
                                END IF;
                            ELSE
                                IF NOT EXISTS (SELECT * FROM member_vip_prizes WHERE member_id = p_MemberId AND vip_content_reward_id = p_VCRID) THEN
                                    IF GiftType = 1 THEN
                                        # 取玩家餘額
                                        SELECT balance, parent
                                        INTO BeforeBalance, AgentId
                                        FROM members
                                        WHERE id = p_MemberId;

                                        SET AfterBalance = (BeforeBalance + p_RewardPoint);

                                        # 更新玩家餘額
                                        UPDATE members
                                        SET balance = AfterBalance
                                        WHERE id = p_MemberId;

                                        # 寫入transaction_logs
                                        INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                                                    , type, point, tax, vendor_point, rate
                                                                    , before_balance, balance, vendor_balance, `status`, remark
                                                                    , checksum, result, operator, platform, `admin`)
                                        VALUES('', AgentId, p_MemberId, 0, 0
                                              , TransactionType, p_RewardPoint, 0, 0, 0
                                              , BeforeBalance, AfterBalance, 0, 1000, Remark
                                              , '', '', 0, 0, OperatorId);
                                    ELSEIF  GiftType = 2 THEN
                                        # 取玩家禮碼餘額
                                        SELECT mw.balance, m.parent
                                        INTO BeforeBalance, AgentId
                                        FROM member_wallets AS mw
                                                 JOIN members as m on m.id = mw.member_id
                                        WHERE mw.member_id = p_MemberId AND mw.wallet_type_id = WalletTypeId;

                                        IF BeforeBalance IS NOT NULL THEN
                                            SET AfterBalance = (BeforeBalance + p_RewardPoint);

                                            # 更新玩家禮碼餘額
                                            UPDATE member_wallets
                                            SET balance = AfterBalance
                                            WHERE member_id = p_MemberId AND wallet_type_id = WalletTypeId;
                                        ELSE
                                            SET BeforeBalance = 0;
                                            SET AfterBalance = p_RewardPoint;
                                            # 創建玩家禮碼錢包
                                            INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
                                            VALUES(p_MemberId, WalletTypeId, p_RewardPoint, 1, 0);
                                        END IF;
                                        # 寫入crolling_chip_logs
                                        INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance
                                                                     , balance, `status`, remark, `admin`)
                                        VALUES(AgentId, p_MemberId, 0, RollingChipType, p_RewardPoint, BeforeBalance, AfterBalance, 1000, Remark, OperatorId);
                                    END IF;
                                    INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, point, expired_time, status, admin)
                                        VALUE(p_MemberId, p_VCRID, GiftType, p_RewardPoint, DATE_ADD(NOW(), INTERVAL 3 DAY), 1, 0);
                                END IF;
                            END IF;
                        END IF;
                    END WHILE;
                SET MaxLevel = 0;
            ELSE
                IF (MaxLevel - 1) <= NowLevel THEN
                    SET MaxLevel = 0;
                ELSE
                    SELECT target_amount INTO p_MVMaxTargetAmount FROM vip_contents WHERE agent_id = p_MVAgentId AND level = MaxLevel;

                    SET p_MVTargetAmount = p_MVTargetAmount - p_MVMaxTargetAmount;
                    SET MaxLevel = MaxLevel - 1;
                END IF;
            END IF;
        END WHILE;
END
