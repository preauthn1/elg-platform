CREATE PROCEDURE `SP_event_deposit_wallet_for_cs`(
    IN p_wallet_transaction_log_id INT,
    IN p_role_id INT
)
BEGIN
    # 代理/客服開分-充值活動
    # 2021/10/22 Mars 		ADD
    # 2021/11/19 Mars 		Fix 當查出活動有一個不是當前代理線, SP就會直接結束的問題
    # 2021/11/23 Mars 		支援手動領獎
    # 2021/12/06 Mars 		每日充
    # 2021/12/10 Mars 		支援活動內部稽核流水倍數
    # 2022/03/01 Mars 		增加寫入events.id到flow_limits
    # 2022/06/02 Mars 		增加判斷稽核流水是否需要含本
    # 2024/01/04 一次調整下列功能
    # -------------------------
    # 2022/09/22 Neil		[GFF]活動稽核流水新增限定遊戲商或類別
    # 2023/04/19 Neil		[GFF]新增禮碼功能
    # 2023/12/25 Mars 		手動領取時, 將prize_content_id寫入, 且lottery_point自動/手動均用一樣方式紀錄
    # 2023/12/25 Mars 		排除指定代理線
    # 2023/12/26 Rory		派發優惠錢包
    # 2024/08/23 Neil       更改派獎prize_members邏輯，手動領獎寫prize_members.lottery_point寫儲值金額，領獎那邊在做計算，以及新增遊戲錢包
    # -------------------------

    DECLARE MemberId, PlatformAgentId, AgentId, OrderStatus, EventDetailId, FlowLimitId, PromotionWalletId INT;
    DECLARE TransactionLogType INT DEFAULT 19;
    DECLARE RollingChipType INT DEFAULT 9;
    DECLARE BeforeBalance, DepositPoint, BeforeRollingChip, BeforePromotionWalletBalance DECIMAL(18, 4);
    DECLARE RemarkText VARCHAR(50) DEFAULT '首充活動獎勵';
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT 0; 		# 獎勵金額
    DECLARE FlowLimitRate DECIMAL(10, 2) DEFAULT 0; 		# 稽核流水倍數
    DECLARE PrizeMaxPoint DECIMAL(18, 4) DEFAULT 0; # 最大獎勵金額
    DECLARE Operator VARCHAR(10) DEFAULT 'system';
    DECLARE OperatorId INT DEFAULT 51;
    DECLARE ReferenceId INT DEFAULT 0;
    DECLARE EventId, PrizeId, ActionId, DetailOrderType, OrderTypeId, PointLimit, PrizeCount, ExpireHour INT DEFAULT 0;
    DECLARE PrizeContentId, PrizeType, PrizeNumber, PrizeSent, PrizePercentage, PrizePoint, PrizeIsRepeatedly INT;
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE PointType INT DEFAULT 1;
    DECLARE PrizePointPercentage DECIMAL(4, 2) DEFAULT 0.00;
    DECLARE PrizeName VARCHAR(50);
    DECLARE PrizeFlowLimitIncludePrincipal TINYINT(1);
    DECLARE FlowLimitVendorId, FlowLimitGameType VARCHAR(100);
    DECLARE FlowLimitRestrictionsCnt, l_FlowLimitVendorId, l_FlowLimitGameType, x INT;
    DECLARE Done, Continues INT DEFAULT 0;
    DECLARE HierarchyPath VARCHAR(255);
    DECLARE PrizeMemberId INT DEFAULT 0;
    DECLARE ExpireTime DATETIME;
    DECLARE Today DATE DEFAULT DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR));

    # 取得該筆充值的建立時間
    DECLARE OrderCreatedAt DATETIME DEFAULT (SELECT created_at FROM wallet_transaction_logs WHERE id = p_wallet_transaction_log_id);

    # 獲取當前活動(type_id; 51:首充, 52:每次充, 53:首充, 54:每次充, 55:每日充)
    DECLARE CurDate CURSOR FOR
        SELECT e.id, ed.id, e.agent_id
             , ed.action_id, ed.order_type_id, expire_hour, ed.point
             , p.id, p.is_repeatedly, p.max_point, p.flow_limit_multiple
             , IFNULL(JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.flow_limit_include_principal')), 0) as flow_limit_include_principal
        FROM event_details AS ed
                 JOIN events AS e ON e.id = ed.event_id AND e.status = 1
                 JOIN prizes AS p ON p.event_id = e.id AND p.status = 1
        WHERE ed.action_id IN (51, 52, 53, 54, 55)
          AND ed.status = 1
          AND ed.order_type_id IN (0, -2, -3)  #-2:客服, -3:代理
          AND ed.start_time <= OrderCreatedAt
          AND ed.end_time >= OrderCreatedAt;
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_event_deposit_for_cs(',
                    IFNULL(p_wallet_transaction_log_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN CurDate;
    REPEAT
        FETCH CurDate
            INTO EventId, EventDetailId, PlatformAgentId, ActionId, DetailOrderType, ExpireHour,
                PointLimit, PrizeId, PrizeIsRepeatedly, PrizeMaxPoint, FlowLimitRate, PrizeFlowLimitIncludePrincipal;
        IF NOT Done THEN
            IF ActionId = 51 OR ActionId = 53 THEN
                SET TransactionLogType = 19;
                SET RollingChipType = 9;
            ELSEIF ActionId = 55 THEN
                SET TransactionLogType = 25;
                SET RollingChipType = 12;
            ELSE
                SET TransactionLogType = 24;
                SET RollingChipType = 20;
            END IF;

            SET OrderStatus = 0;

            # 查訂單
            SELECT o.member_id, o.point, o.status, m.parent, m.balance, a.hierarchy_path
            INTO MemberId, DepositPoint, OrderStatus, AgentId, BeforeBalance, HierarchyPath
            FROM wallet_transaction_logs AS o
                     JOIN members AS m ON m.id = o.member_id
                     JOIN agents AS a ON a.id = m.parent
            WHERE o.id = p_wallet_transaction_log_id AND o.wallet_type_id = 2; # 遊戲錢包

            # 檢查此活動是否為此代理線
            IF EXISTS (SELECT * FROM agents WHERE id = AgentId AND hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')) THEN

                # 判斷是否為排除代理
                IF NOT EXISTS (
                    SELECT *
                    FROM event_exclude_agents AS eea
                             JOIN agents AS a ON a.id = eea.agent_id AND HierarchyPath LIKE CONCAT(a.hierarchy_path, '%')
                    WHERE eea.event_id = EventId
                ) THEN
                    SET OrderTypeId = (p_role_id * -1);

                    # 判斷訂單是否成立
                    IF OrderStatus = 1000 THEN
                        # 判斷充值類型
                        IF DetailOrderType = 0 OR DetailOrderType = OrderTypeId THEN
                            # 判斷訂單金額是否超過限制
                            IF PointLimit <= DepositPoint THEN
                                # 計算percentage
                                SELECT floor(rand() * SUM(percentage)), COUNT(*)
                                INTO PrizePercentage, PrizeCount
                                FROM prize_contents
                                WHERE prize_id = PrizeId
                                  AND status = 1
                                  AND (number = -1 OR (number - sent) > 0);

                                IF PrizeCount > 0 THEN
                                    # 找出獎勵內容
                                    SELECT z.id, z.type, z.name, z.number, z.sent, z.percentage, IFNULL(z.point, 0), IFNULL(z.point_percentage, 0), z.point_type
                                    INTO PrizeContentId, PrizeType, PrizeName, PrizeNumber, PrizeSent, PrizePercentage, PrizePoint, PrizePointPercentage, PointType
                                    FROM (
                                             SELECT PrizePercentage, (@i:= @i + p.percentage) AS rownum,
                                                    p.id, p.type, p.name, p.number, p.sent, p.percentage, p.point_type as point_type, IFNULL(p.point, 0) AS point, IFNULL(p.point_percentage, 0) as point_percentage
                                             FROM prize_contents AS p
                                                ,(SELECT @i:=0) AS r
                                             WHERE prize_id = PrizeId
                                               AND status = 1
                                         ) AS z
                                    WHERE z.rownum >= PrizePercentage
                                    LIMIT 1;

                                    SET Continues = 0;
                                    IF PrizeIsRepeatedly = 1 THEN
                                        # 查看是否已領過
                                        IF EXISTS (
                                            SELECT * FROM prize_members
                                            WHERE member_id = MemberId AND prize_id = PrizeId
                                              AND lottery_number = p_wallet_transaction_log_id
                                        ) THEN
                                            SET Continues = 1;
                                        END IF;
                                    ELSEIF PrizeIsRepeatedly = 2 THEN
                                        # 查看是否已領過
                                        IF EXISTS (
                                            SELECT * FROM prize_members
                                            WHERE member_id = MemberId AND prize_id = PrizeId
                                              AND DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))
                                        ) THEN
                                            SET Continues = 1;
                                        END IF;
                                    ELSE
                                        IF EXISTS (
                                            SELECT * FROM prize_members
                                            WHERE member_id = MemberId AND prize_id = PrizeId
                                        ) THEN
                                            SET Continues = 1;
                                        END IF;
                                    END IF;

                                    IF Continues = 0 THEN
                                        # 查看獎項上限
                                        IF PrizeNumber = -1 OR (PrizeNumber - PrizeSent) > 0 THEN
                                            SET RemarkText = PrizeName;

                                            #自動派獎要直接計算派獎金額
                                            IF ActionId = 51 OR ActionId = 52 THEN
                                                # 計算獎勵金額
                                                IF PrizeType = 1 THEN
                                                    # 依照比例
                                                    SET RewardPoint = (DepositPoint * PrizePointPercentage);
                                                    IF PrizeMaxPoint != 0 THEN
                                                        IF RewardPoint > PrizeMaxPoint THEN
                                                            SET RewardPoint = PrizeMaxPoint;
                                                        END IF;
                                                    END IF;
                                                ELSE
                                                    # 固定金額
                                                    SET RewardPoint = PrizePoint;
                                                END IF;
                                            ELSE
                                                #手動領獎先寫入儲值金額，領獎時在做計算
                                                SET RewardPoint = DepositPoint;
                                            END IF;

                                            IF ExpireHour > 0 THEN
                                                SET ExpireTime = DATE_ADD(NOW(), INTERVAL ExpireHour HOUR);
                                            ELSEIF ExpireHour = -1 THEN
                                                SET ExpireTime = DATE_SUB(DATE_SUB(DATE_ADD(Today, INTERVAL 1 DAY), INTERVAL 8 HOUR), INTERVAL 1 SECOND);
                                            ELSE
                                                SET ExpireTime = NULL;
                                            END IF;

                                            # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
                                            IF ActionId = 51 OR ActionId = 52 OR ActionId = 56 THEN
                                                # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
                                                IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
                                                    UPDATE event_processes
                                                    SET point = (point + PointLimit), received = (received + PointLimit)
                                                    WHERE detail_id = EventDetailId AND member_id = MemberId;
                                                ELSE
                                                    INSERT INTO event_processes(detail_id, member_id, point, received, status, admin)
                                                    VALUES(EventDetailId, MemberId, PointLimit, PointLimit, 2, OperatorId);
                                                END IF;

                                                # 寫入領獎紀錄
                                                INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_point, status, admin)
                                                VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, RewardPoint, 1, OperatorId);
                                            ELSE

                                                # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
                                                IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
                                                    UPDATE event_processes
                                                    SET point = (point + PointLimit)
                                                    WHERE detail_id = EventDetailId AND member_id = MemberId;
                                                ELSE
                                                    INSERT INTO event_processes(detail_id, member_id, point, status, admin)
                                                    VALUES(EventDetailId, MemberId, PointLimit, 0, OperatorId);
                                                END IF;

                                                # 寫入領獎紀錄
                                                INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_point, status, admin)
                                                VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, RewardPoint, 0, OperatorId);
                                            END IF;
                                            SET ReferenceId = 0;
                                            IF ActionId = 51 OR ActionId = 52 THEN
                                                IF PointType = 1 THEN
                                                    # 寫入獎勵
                                                    UPDATE members SET balance = (balance + RewardPoint)
                                                    WHERE id = MemberId;

                                                    # 寫入transaction_logs
                                                    INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                                                                , type, point, vendor_point, rate
                                                                                , before_balance, balance, vendor_balance
                                                                                , status, remark, result, operator, platform, admin)
                                                    VALUES(p_wallet_transaction_log_id, AgentId, MemberId, 0, 0
                                                          , TransactionLogType, RewardPoint, 0, 1
                                                          , BeforeBalance, (BeforeBalance + RewardPoint), 0
                                                          , 1000, RemarkText, 0, Operator, 0, 0);

                                                    # 取得transaction_logs.id
                                                    SELECT LAST_INSERT_ID() INTO ReferenceId;
                                                ELSEIF PointType = 2 THEN
                                                    # 取玩家禮碼餘額
                                                    SELECT balance INTO BeforeRollingChip
                                                    FROM member_wallets
                                                    WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;

                                                    IF BeforeRollingChip IS NOT NULL THEN
                                                        # 更新玩家禮碼餘額
                                                        UPDATE member_wallets SET balance = (BeforeBalance + RewardPoint)
                                                        WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;
                                                    ELSE
                                                        SET BeforeRollingChip = 0;
                                                        # 創建玩家禮碼錢包
                                                        INSERT INTO member_wallets(
                                                            member_id, wallet_type_id, balance, status, admin
                                                        )
                                                        VALUES(
                                                                  MemberId, WalletTypeId, RewardPoint, 1, 0
                                                              );
                                                    END IF;

                                                    # 寫入crolling_chip_logs
                                                    INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, type, amount, before_balance
                                                                                 , balance, `status`, remark, `admin`)
                                                    VALUES(AgentId, MemberId, 0, RollingChipType, RewardPoint, BeforeRollingChip, (BeforeRollingChip + RewardPoint),
                                                           1000, RemarkText, 0);

                                                    # 取得rolling_chip_logs.id
                                                    SELECT LAST_INSERT_ID() INTO ReferenceId;

                                                ELSEIF PointType = 4 THEN
                                                    #檢查活動優惠錢包沒有與現有優惠錢包的遊戲商衝突
                                                    IF NOT EXISTS (
                                                        SELECT *
                                                        FROM promotion_wallets AS pw
                                                                 JOIN event_promotions AS ep ON pw.event_id = ep.event_id
                                                        WHERE pw.member_id = MemberId
                                                          AND pw.status = 1
                                                          AND ep.vendor_id IN (
                                                            SELECT vendor_id FROM event_promotions
                                                            WHERE event_id = EventId
                                                        )
                                                    ) THEN
                                                        # 檢查有無優惠錢包, 沒有就加一個
                                                        IF NOT EXISTS (
                                                            SELECT *
                                                            FROM promotion_wallets
                                                            WHERE member_id = MemberId
                                                              AND event_id = EventId
                                                              AND `status` = 1
                                                        ) THEN
                                                            INSERT INTO promotion_wallets (parent, member_id, event_id, balance, status, admin)
                                                            VALUES (AgentId, MemberId, EventId, 0, 1, 0);
                                                        END IF;

                                                        SELECT IFNULL(id, 0), balance
                                                        INTO PromotionWalletId, BeforePromotionWalletBalance
                                                        FROM promotion_wallets
                                                        WHERE member_id = MemberId
                                                          AND event_id = EventId
                                                          AND `status` = 1
                                                        LIMIT 1;

                                                        UPDATE promotion_wallets SET balance = balance + RewardPoint
                                                        WHERE id = PromotionWalletId;

                                                        INSERT INTO promotion_wallet_logs (
                                                            transaction_no, agent_id, member_id, event_id, promotion_wallet_id, `type`, vendor_id,
                                                            point, vendor_point, rate, before_balance, balance, status
                                                        ) VALUES (
                                                                     '', AgentId, MemberId, EventId, PromotionWalletId, 3, 0,
                                                                     RewardPoint, 0, 1, BeforePromotionWalletBalance, BeforePromotionWalletBalance + Reward, 1000
                                                                 );
                                                        # 取得transaction_logs.id
                                                        SELECT LAST_INSERT_ID() INTO ReferenceId;

                                                        #
                                                        SET TransactionLogType = 104;
                                                    ELSE
                                                        #將自動派發的prize_member修改為0
                                                        UPDATE prize_members SET status = 0 WHERE id = PrizeMemberId;

                                                        # 發通知
                                                        CALL COM_Send_Error_Message(CONCAT('[優惠錢包通知] SP_event_deposit_for_cs(', IFNULL(p_wallet_transaction_log_id, 'NULL'), ') 衝突 '), 'db');
                                                    END IF;
                                                END IF;
                                                # 寫入稽核流水
                                                IF FlowLimitRate > 0 AND ReferenceId > 0 THEN
                                                    #稽核流水需要含本
                                                    IF PrizeFlowLimitIncludePrincipal = 1 THEN
                                                        SET RewardPoint = RewardPoint + DepositPoint;
                                                    END IF;

                                                    INSERT INTO flow_limits(member_id, type, event_id, reference_id, flow_limit, admin)
                                                    VALUES(MemberId, TransactionLogType, EventId, ReferenceId, (RewardPoint * FlowLimitRate), OperatorId);

                                                    # 取得flow_limits.id
                                                    SELECT LAST_INSERT_ID() INTO FlowLimitId;

                                                    SELECT REPLACE(REPLACE(REPLACE(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_vendor_id')), -1), "[", ""), "]", ""), '"', ""),
                                                           REPLACE(REPLACE(REPLACE(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_game_type')), -1), "[", ""), "]", ""), '"', ""),
                                                           char_length(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_vendor_id')),- 1)) - char_length(REPLACE(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_vendor_id')),- 1), ",", "")) + 1
                                                    INTO FlowLimitVendorId, FlowLimitGameType, FlowLimitRestrictionsCnt
                                                    FROM prizes
                                                    WHERE id = PrizeId;

                                                    IF FlowLimitVendorId != -1 THEN
                                                        SET x = 1;
                                                        WHILE x <= FlowLimitRestrictionsCnt DO
                                                                SET l_FlowLimitVendorId = SUBSTRING_INDEX(SUBSTRING_INDEX(FlowLimitVendorId, ",", x), ",", -1);
                                                                SET l_FlowLimitGameType = SUBSTRING_INDEX(SUBSTRING_INDEX(FlowLimitGameType, ",", x), ",", -1);
                                                                SELECT l_FlowLimitVendorId, l_FlowLimitGameType;
                                                                INSERT INTO flow_limit_restrictions(flow_limit_id, vendor_id, type_id)
                                                                VALUES(FlowLimitId, l_FlowLimitVendorId, l_FlowLimitGameType);
                                                                SET x = x + 1;
                                                            END WHILE;
                                                    END IF;
                                                END IF;

                                                #更新獎品派發數量
                                                UPDATE prize_contents
                                                SET sent = (sent + 1)
                                                WHERE id = PrizeContentId;
                                            END IF;
                                        END IF;
                                    END IF;
                                END IF;
                            END IF;
                        END IF;
                    END IF;
                END IF;
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE CurDate;
END
