CREATE PROCEDURE `SP_event_deposit_by_transaction_log`(IN p_transaction_log_id INT)
BEGIN
    # 充值活動（後台開分視同儲值版本）
    # 與 SP_event_deposit 完全相同的派獎邏輯，差別只在來源資料：
    # 這裡的儲值金額／會員資訊取自 transaction_logs（type=4 開分），不是 orders。
    # 沒有訂單類型（order_type_id）概念，event_details.order_type_id 必須設為 0（任意類型）
    # 才會被這支 SP 觸發；設定非 0 的特定類型不會匹配。
    # 2026/09/17 Neil		[GFF]target/tickets#4352 後台開分等同儲值 ADD

    DECLARE MemberId, PlatformAgentId, AgentId, OrderStatus, EventDetailId, FlowLimitId, PromotionWalletId INT;
    DECLARE TransactionLogType INT DEFAULT 19;
    DECLARE RollingChipType INT DEFAULT 9;
    DECLARE BeforeBalance, DepositTax, DepositPoint, BeforeRollingChip, BeforePromotionWalletBalance DECIMAL(18, 4);
    DECLARE RemarkText VARCHAR(50) DEFAULT '首充活動獎勵';
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT 0; 		# 獎勵金額
    DECLARE FlowLimitRate, PrizeContentFlowLimitRate DECIMAL(10, 2) DEFAULT 0; 		# 稽核流水倍數
    DECLARE PrizeMaxPoint DECIMAL(18, 4) DEFAULT 0; # 最大獎勵金額
    DECLARE FlowLimitPoint DECIMAL(18, 4) DEFAULT 0; # 稽核流水
    DECLARE Operator VARCHAR(10) DEFAULT 'system';
    DECLARE OperatorId INT DEFAULT 51;
    DECLARE ReferenceId INT DEFAULT 0;
    DECLARE EventId, PrizeId, PrizeTypeId, PrizeNumber, ActionId, DetailOrderType, PointLimit, PrizeCancelOtherReward, PrizeCount, ExpireHour INT DEFAULT 0;
    DECLARE PrizeContentId, PrizeType, PrizeContentNumber, PrizeSent, PrizePercentage, PrizePoint, PrizeRandomPointMax, PrizeIsRepeatedly INT;
    DECLARE AchieveCnt, AchievePrizeLvel INT DEFAULT 0;
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE PointType INT DEFAULT 1;
    DECLARE PrizePointPercentage DECIMAL(4, 2) DEFAULT 0.00;
    DECLARE PrizeName VARCHAR(50);
    DECLARE PrizeFlowLimitIncludePrincipal, PrizeFlowLimitDeductPrincipal, PrizeRegistrationLimit TINYINT(1);
    DECLARE FlowLimitVendorId, FlowLimitGameType, MemberFirstName VARCHAR(100);
    DECLARE FlowLimitRestrictionsCnt, l_FlowLimitVendorId, l_FlowLimitGameType, x INT;
    DECLARE HierarchyPath VARCHAR(255);
    DECLARE PrizeMemberId INT DEFAULT 0;
    DECLARE RegisterIp BIGINT(20);
    DECLARE EventStartTime, EventEndTime, ExpireTime DATETIME;
    DECLARE MemberCreatedAt DATETIME;
    DECLARE RegisterMobile VARCHAR(30);
    DECLARE IpBlock, PhoneBlock, BrowserBlock, AccountBlock, TgidBlock, VendorLock TINYINT(1) DEFAULT 0;
    DECLARE PrizeTimesADay, PrizeOnlyWeekend, TodayTimes INT(11) DEFAULT 0;
    DECLARE BlockType, BlockMemberId INT DEFAULT 0; # 用來寫入取消派發，取消類別跟重複的member_id
    DECLARE BlockRemark VARCHAR(100) DEFAULT ' ';
    DECLARE TgUserId BIGINT(20) DEFAULT 0;
    DECLARE FetchStage ENUM('none', 'cursor', 'select') DEFAULT 'none';

    DECLARE Done, Continues, SelectNotFound INT DEFAULT 0;
    # 取得該筆開分紀錄的建立時間（限定 type=4 開分，防呼叫端誤傳其他類型的 transaction_logs.id）
    DECLARE OrderCreatedAt DATETIME DEFAULT (SELECT created_at FROM transaction_logs WHERE id = p_transaction_log_id AND `type` = 4);
    DECLARE OrderCreatedAtWeek INT DEFAULT DAYOFWEEK(DATE_ADD(OrderCreatedAt, INTERVAL 8 HOUR));

    # 獲取當前活動(type_id; 51:首充, 52:每次充, 53:首充, 54:每次充, 55:每日充, 56:每日充(自動))
    DECLARE CurDate CURSOR FOR
        SELECT e.id, ed.id, e.agent_id
             , ed.action_id, ed.order_type_id, ed.start_time, ed.end_time, expire_hour, ed.`point`, ed.global_ip_block, ed.global_phone_block, ed.browser_block, ed.global_account_block
             , tgid_block, ed.vendor_lock, p.id, p.type_id, p.`number`, p.is_repeatedly, p.max_point, p.flow_limit_multiple, p.cancel_other_reward
             , IFNULL(JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.times_a_day')), 1) as times_a_day
             , IFNULL(JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.only_weekend')), 0) as only_weekend
             , IFNULL(JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.flow_limit_include_principal')), 0) as flow_limit_include_principal
             , IFNULL(JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.deduct_principal')), 0) as deduct_principal
             , IFNULL(JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.registration_limit')), 0) as registration_limit
        FROM event_details AS ed
                 JOIN `events` AS e ON e.id = ed.event_id AND e.`status` = 1
                 JOIN prizes AS p ON p.event_id = e.id AND p.`status` = 1
        WHERE ed.action_id IN (51,52,53,54,55,56,57,58,59,61)
          AND ed.`status` = 1
          AND ed.order_type_id >= 0
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
                    '[系統通知] SP_event_deposit_by_transaction_log(',
                    IFNULL(p_transaction_log_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND
        BEGIN
            IF FetchStage = 'cursor' THEN
                SET Done = 1;
            ELSEIF FetchStage = 'select' THEN
                SET SelectNotFound = 1;
            END IF;
        END;

    SET FetchStage = 'cursor';

    OPEN CurDate;
    REPEAT
        FETCH CurDate
            INTO EventId, EventDetailId,
                PlatformAgentId, ActionId, DetailOrderType, EventStartTime, EventEndTime, ExpireHour, PointLimit, IpBlock, PhoneBlock, BrowserBlock, AccountBlock, TgidBlock,
                VendorLock, PrizeId, PrizeTypeId, PrizeNumber, PrizeIsRepeatedly, PrizeMaxPoint, FlowLimitRate, PrizeCancelOtherReward, PrizeTimesADay, PrizeOnlyWeekend, PrizeFlowLimitIncludePrincipal,
                PrizeFlowLimitDeductPrincipal, PrizeRegistrationLimit;
        IF NOT Done THEN
            SET FetchStage = 'select';
            SET SelectNotFound = 0;

            IF ActionId = 51 OR ActionId = 53 THEN
                SET TransactionLogType = 19;
                SET RollingChipType = 9;
            ELSEIF ActionId = 55 OR ActionId = 56 THEN
                SET TransactionLogType = 25;
                SET RollingChipType = 12;
            ELSE
                SET TransactionLogType = 24;
                SET RollingChipType = 20;
            END IF;
            SET OrderStatus = 0;

            # 查開分紀錄（type=4 開分；status=1000 對應 TransactionStatusEnum::STATUS_SUCCESS）
            SELECT tl.member_id, tl.tax, tl.`point`, tl.`status`, m.parent, m.register_ip, m.mobile, m.first_name, m.balance, a.hierarchy_path, m.created_at
            INTO MemberId, DepositTax, DepositPoint, OrderStatus, AgentId, RegisterIp, RegisterMobile, MemberFirstName, BeforeBalance, HierarchyPath, MemberCreatedAt
            FROM transaction_logs AS tl
                     JOIN members AS m ON m.id = tl.member_id
                     JOIN agents AS a ON a.id = m.parent
            WHERE tl.id = p_transaction_log_id AND tl.`type` = 4;

            # 檢查此活動是否為此代理線
            IF EXISTS (SELECT * FROM agents WHERE id = AgentId AND hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')) THEN
                # 判斷是否為排除代理
                IF NOT EXISTS (
                    SELECT *
                    FROM event_exclude_agents AS eea
                             JOIN agents AS a ON a.id = eea.agent_id AND HierarchyPath LIKE CONCAT(a.hierarchy_path, '%')
                    WHERE eea.event_id = EventId
                ) THEN
                    # 判斷開分紀錄是否成立
                    IF OrderStatus = 1000 THEN
                        # 沒有訂單類型概念，只匹配設定為「任意類型」(0) 的活動明細
                        IF DetailOrderType = 0 THEN
                            IF ActionId = 59 THEN
                                # 直接CALL另一隻SP處理連續每日儲值活動
                                CALL SP_event_consecutive_deposit(MemberId, EventId, p_transaction_log_id);
                                SET DepositPoint = 0; # 把儲值金額改成0，不跑下面
                            ELSEIF ActionId = 61 THEN
								# 直接CALL另一隻SP處理連續儲值活動
                                CALL SP_event_continuous_deposit(MemberId, EventId, p_transaction_log_id);
                                SET DepositPoint = 0; # 把儲值金額改成0，不跑下面
                            END IF;

                            # 判斷訂單金額是否超過限制
                            IF PointLimit <= (DepositPoint + DepositTax) THEN
                                # 檢查有無獎品
                                SELECT COUNT(*) INTO PrizeCount
                                FROM prize_contents
                                WHERE prize_id = PrizeId AND `status` = 1 AND (`number` = -1 OR (`number` - sent) > 0);

                                # 如果活動限定周末，則檢查今天是星期幾
                                IF PrizeOnlyWeekend = 1 THEN
                                    IF OrderCreatedAtWeek NOT IN (1, 6, 7) THEN
                                        SET PrizeCount = 0;
                                    END IF;
                                END IF;

                                ## 檢查IP/mobile/account是否重覆
                                IF PrizeCount > 0 THEN
                                    SET BlockType = 0;
                                    SET BlockMemberId = 0;
                                    IF AccountBlock = 1 THEN
                                        SELECT m.id INTO BlockMemberId
										FROM members AS m
											JOIN agents AS a ON a.id = m.parent AND a.hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')
										WHERE m.first_name = MemberFirstName AND m.id != MemberId
										LIMIT 1;

                                        IF MemberFirstName IS NULL OR BlockMemberId > 0 THEN
                                            SET BlockType = 7;
                                            SET BlockRemark = '全站真實姓名重複';
                                        END IF;
                                    END IF;

                                    IF IpBlock = 1 AND BlockType = 0 THEN
                                        SELECT m.id INTO BlockMemberId
										FROM members AS m
											JOIN agents AS a ON a.id = m.parent AND a.hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')
										WHERE m.register_ip = RegisterIp AND m.id != MemberId
										LIMIT 1;

                                        IF RegisterIp IS NULL OR BlockMemberId > 0 THEN
                                            SET BlockType = 8;
                                            SET BlockRemark = '全站註冊IP重複';
                                        END IF;
                                    END IF;

                                    IF PhoneBlock = 1 AND BlockType = 0 THEN
                                        SELECT m.id INTO BlockMemberId
										FROM members AS m
											JOIN agents AS a ON a.id = m.parent AND a.hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')
										WHERE m.mobile = RegisterMobile AND m.id != MemberId
										LIMIT 1;

                                        IF RegisterMobile IS NULL OR BlockMemberId > 0 THEN
                                            SET BlockType = 9;
                                            SET BlockRemark = '全站手機號碼重複';
                                        END IF;
                                    END IF;
                                END IF;

                                IF PrizeCount > 0 THEN
                                    # 找出獎勵內容
                                    IF PrizeTypeId = 9 THEN
                                        #級距獎勵
                                        SELECT id, `type`, `name`, `number`, sent, percentage, IFNULL(`point`, 0), `random_point_max`, IFNULL(point_percentage, 0), flow_limit_multiple, point_type
                                        INTO PrizeContentId, PrizeType, PrizeName, PrizeContentNumber, PrizeSent, PrizePercentage, PrizePoint, PrizeRandomPointMax, PrizePointPercentage, PrizeContentFlowLimitRate, PointType
                                        FROM prize_contents AS p
                                        WHERE prize_id = PrizeId AND `status` = 1 AND achieve_point <= DepositPoint
                                        ORDER BY achieve_point DESC
                                        LIMIT 1;
                                    ELSEIF PrizeTypeId = 10 THEN
                                        #級距多次獎勵
                                        IF PrizeIsRepeatedly = 2 THEN
                                            SELECT COUNT(*) INTO AchieveCnt
                                            FROM prize_members
                                            WHERE prize_id = PrizeId AND event_detail_id = EventDetailId AND member_id = MemberId
                                              AND DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR));
                                        ELSE
                                            SELECT COUNT(*) INTO AchieveCnt
                                            FROM prize_members
                                            WHERE prize_id = PrizeId AND event_detail_id = EventDetailId AND member_id = MemberId;
                                        END IF;

                                        IF AchieveCnt > 0 THEN
                                            SET AchievePrizeLvel = 1;
                                        ELSE
                                            SET AchievePrizeLvel = 0;
                                        END IF;

                                        SELECT id, `type`, `name`, `number`, sent, percentage, IFNULL(`point`, 0), `random_point_max`, IFNULL(point_percentage, 0), flow_limit_multiple, point_type
                                        INTO PrizeContentId, PrizeType, PrizeName, PrizeContentNumber, PrizeSent, PrizePercentage, PrizePoint, PrizeRandomPointMax, PrizePointPercentage, PrizeContentFlowLimitRate, PointType
                                        FROM prize_contents AS p
                                        WHERE prize_id = PrizeId
                                          AND `status` = 1
                                          AND achieve_point <= DepositPoint
                                          AND `level` = AchievePrizeLvel
                                        ORDER BY achieve_point DESC
                                        LIMIT 1;
                                    ELSEIF PrizeTypeId = 8 THEN
                                        #選擇獎勵
                                        SET PrizeContentId = -1;
                                        SET PrizeContentNumber = -1;
                                    ELSE
                                        SELECT FLOOR(RAND() * SUM(percentage)) INTO PrizePercentage
                                        FROM prize_contents
                                        WHERE prize_id = PrizeId AND `status` = 1 AND (`number` = -1 OR (`number` - sent) > 0);

                                        SELECT z.id, z.`type`, z.`name`, z.`number`, z.sent, z.percentage, IFNULL(z.`point`, 0), z.`random_point_max`, IFNULL(z.point_percentage, 0), z.flow_limit_multiple, z.point_type
                                        INTO PrizeContentId, PrizeType, PrizeName, PrizeContentNumber, PrizeSent, PrizePercentage, PrizePoint, PrizeRandomPointMax, PrizePointPercentage, PrizeContentFlowLimitRate, PointType
                                        FROM (
                                                 SELECT PrizePercentage, (@i:= @i + p.percentage) AS rownum,
                                                        p.id, p.`type`, p.`name`, p.`number`, p.sent, p.percentage, p.point_type as point_type, IFNULL(p.`point`, 0) AS `point`, p.`random_point_max`,
                                                        IFNULL(p.point_percentage, 0) as point_percentage, p.flow_limit_multiple as flow_limit_multiple
                                                 FROM prize_contents AS p, (SELECT @i:=0) AS r
                                                 WHERE prize_id = PrizeId AND `status` = 1 AND (p.`number` = -1 OR (p.`number` - p.sent) > 0)
                                             ) AS z
                                        WHERE z.rownum >= PrizePercentage
                                        LIMIT 1;
                                    END IF;

                                    #更新獎品派發數量
                                    UPDATE prize_contents SET sent = (sent + 1) WHERE id = PrizeContentId;

                                    SET Continues = 0;
                                    IF PrizeIsRepeatedly = 1 THEN
                                        # 查看是否已領過
                                        IF EXISTS (
                                            SELECT * FROM prize_members
                                            WHERE member_id = MemberId AND prize_id = PrizeId
                                              AND lottery_number = p_transaction_log_id AND event_detail_id = EventDetailId AND `status` != 2
                                        ) THEN
                                            SET Continues = 1;
                                        END IF;

                                        IF PrizeTypeId = 10 AND AchieveCnt > PrizeNumber THEN
                                            SET Continues = 1;
                                        END IF;
                                    ELSEIF PrizeIsRepeatedly = 2 THEN
                                        # 查看是否已領過
                                        IF EXISTS (
                                            SELECT member_id, COUNT(*) FROM prize_members
                                            WHERE member_id = MemberId AND prize_id = PrizeId
                                              AND DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))
                                              AND `status` != 2
                                            GROUP BY member_id
                                            HAVING COUNT(*) >= PrizeTimesADay
                                        ) THEN
                                            SET Continues = 1;
                                        END IF;

                                        IF PrizeTypeId = 10 AND AchieveCnt <= PrizeNumber THEN
                                            SET Continues = 0;
                                        END IF;
                                    ELSE
                                        IF EXISTS (
                                            SELECT * FROM prize_members WHERE member_id = MemberId AND prize_id = PrizeId AND `status` != 2
                                        ) THEN
                                            SET Continues = 1;
                                        END IF;
                                    END IF;

                                    # 首儲 + 註冊
                                    IF (ActionId = 51 OR ActionId = 53) AND PrizeRegistrationLimit = 1 THEN
                                        # 當玩家創建時間小於活動時間, 排除
                                        IF MemberCreatedAt < EventStartTime THEN
                                            SET Continues = 1;
                                        END IF;
                                    END IF;

                                    IF BlockType > 0 THEN
                                        # 把沒派發的原因寫入table
                                        INSERT INTO event_payout_cancels (event_id, prize_content_id, order_id, from_member_id, member_id, `type`, block_member_id)
                                            VALUE (EventId, PrizeContentId, p_transaction_log_id, MemberId, MemberId, BlockType, BlockMemberId);
                                        SET Continues = 1;
                                    END IF;

                                    IF Continues = 0 THEN
                                        # 查看獎項上限
                                        IF PrizeContentNumber = -1 OR (PrizeContentNumber - PrizeSent) > 0 THEN
                                            SET RemarkText = PrizeName;
                                            #自動派獎要直接計算派獎金額
                                            IF ActionId = 51 OR ActionId = 52 OR ActionId = 56 OR ActionId = 57 THEN

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
                                                    IF PrizeRandomPointMax IS NOT NULL AND PrizeRandomPointMax > PrizePoint THEN
                                                        # 隨機金額
                                                        SET RewardPoint = FLOOR(RAND() * (PrizeRandomPointMax - PrizePoint + 1)) + PrizePoint;
                                                    ELSE
                                                        # 固定金額
                                                        SET RewardPoint = PrizePoint;
                                                    END IF;
                                                END IF;
                                            ELSE
                                                #手動領獎先寫入儲值金額，領獎時在做計算
                                                SET RewardPoint = DepositPoint;
                                            END IF;

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

                                            # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
                                            IF ActionId = 51 OR ActionId = 52 OR ActionId = 56 OR ActionId = 57 THEN
                                                # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
                                                IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
                                                    UPDATE event_processes
                                                    SET point = (`point` + PointLimit), received = (received + PointLimit)
                                                    WHERE detail_id = EventDetailId AND member_id = MemberId;
                                                ELSE
                                                    INSERT INTO event_processes(detail_id, member_id, `point`, received, `status`, `admin`)
                                                    VALUES(EventDetailId, MemberId, PointLimit, PointLimit, 2, OperatorId);
                                                END IF;

                                                # 寫入領獎紀錄
                                                INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_number, lottery_point, `status`, `admin`)
                                                VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, p_transaction_log_id, RewardPoint, 1, OperatorId);
                                            ELSE
                                                # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
                                                IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
                                                    UPDATE event_processes
                                                    SET `point` = (`point` + PointLimit)
                                                    WHERE detail_id = EventDetailId AND member_id = MemberId;
                                                ELSE
                                                    INSERT INTO event_processes(detail_id, member_id, `point`, `status`, `admin`)
                                                    VALUES(EventDetailId, MemberId, PointLimit, 0, OperatorId);
                                                END IF;

                                                # 寫入領獎紀錄
                                                INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_number, lottery_point, `status`, `admin`)
                                                VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, p_transaction_log_id, RewardPoint, 0, OperatorId);
                                            END IF;
                                            SELECT LAST_INSERT_ID() INTO PrizeMemberId;

                                            IF PrizeCancelOtherReward = 1 THEN
                                                INSERT INTO prize_member_orders (prize_member_id, order_id)
                                                    VALUE (PrizeMemberId, p_transaction_log_id);
                                            END IF;

                                            SET ReferenceId = 0;
                                            IF ActionId = 51 OR ActionId = 52 OR ActionId = 56 OR ActionId = 57 THEN
                                                IF PointType = 1 THEN
                                                    # 寫入獎勵
                                                    UPDATE members SET balance = (balance + RewardPoint)
                                                    WHERE id = MemberId;

                                                    # 寫入transaction_logs
                                                    INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                                                                , `type`, `point`, vendor_point, rate
                                                                                , before_balance, balance, vendor_balance
                                                                                , `status`, remark, result, operator, platform, `admin`)
                                                    VALUES(p_transaction_log_id, AgentId, MemberId, 0, 0
                                                          , TransactionLogType, RewardPoint, 0, 1
                                                          , BeforeBalance, (BeforeBalance + RewardPoint), 0
                                                          , 1000, RemarkText, 0, Operator, 0, 0);

                                                    # transaction_logs.id
                                                    SELECT LAST_INSERT_ID() INTO ReferenceId;
                                                ELSEIF PointType = 2 THEN
                                                    # 取玩家禮碼餘額
                                                    SELECT balance INTO BeforeRollingChip
                                                    FROM member_wallets
                                                    WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;

                                                    IF BeforeRollingChip IS NOT NULL THEN
                                                        # 更新玩家禮碼餘額
                                                        UPDATE member_wallets SET balance = (BeforeBalance + RewardPoint)
                                                        WHERE id = MemberId AND wallet_type_id = WalletTypeId;
                                                    ELSE
                                                        SET BeforeRollingChip = 0;
                                                        # 創建玩家禮碼錢包
                                                        INSERT INTO member_wallets(
                                                            member_id, wallet_type_id, balance, `status`, `admin`
                                                        )
                                                        VALUES(
                                                                  MemberId, WalletTypeId, RewardPoint, 1, 0
                                                              );
                                                    END IF;

                                                    # 寫入crolling_chip_logs
                                                    INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance
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
                                                          AND pw.`status` = 1
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
                                                            INSERT INTO promotion_wallets (parent, member_id, event_id, balance, `status`, `admin`)
                                                            VALUES (AgentId, MemberId, EventId, 0, 1, 0);
                                                        END IF;

                                                        SELECT IFNULL(id, 0), balance
                                                        INTO PromotionWalletId, BeforePromotionWalletBalance
                                                        FROM promotion_wallets
                                                        WHERE member_id = MemberId
                                                          AND event_id = EventId
                                                          AND `status` = 1
                                                        LIMIT 1;

                                                        UPDATE promotion_wallets
                                                        SET balance = balance + RewardPoint
                                                        WHERE id = PromotionWalletId;

                                                        INSERT INTO promotion_wallet_logs (
                                                            transaction_no, agent_id, member_id, event_id, promotion_wallet_id, `type`, vendor_id,
                                                            `point`, vendor_point, rate, before_balance, balance, `status`
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
                                                        UPDATE prize_members SET `status` = 0 WHERE id = PrizeMemberId;

                                                        # 發通知
                                                        CALL COM_Send_Error_Message(CONCAT('[優惠錢包通知] SP_event_deposit_by_transaction_log(', IFNULL(p_transaction_log_id, 'NULL'), ') 衝突 '), 'db');
                                                    END IF;
                                                END IF;

                                                # 寫入稽核流水
                                                IF (FlowLimitRate > 0 OR PrizeContentFlowLimitRate > 0) AND ReferenceId > 0 THEN
                                                    IF FlowLimitRate = 0 THEN
                                                        SET FlowLimitRate = PrizeContentFlowLimitRate;
                                                    END IF;
                                                    #稽核流水需要含本
                                                    IF PrizeFlowLimitIncludePrincipal = 1 THEN
                                                        SET RewardPoint = RewardPoint + DepositPoint;
                                                        IF PrizeFlowLimitDeDuctPrincipal = 1 THEN
                                                            SET FlowLimitPoint = (RewardPoint * FlowLimitRate) - DepositPoint;
                                                        ELSE
                                                            SET FlowLimitPoint = RewardPoint * FlowLimitRate;
                                                        END IF;
                                                    ELSE
                                                        SET FlowLimitPoint = RewardPoint * FlowLimitRate;
                                                    END IF;

                                                    IF VendorLock = 1 THEN
                                                        #鎖分模式
                                                        SET TransactionLogType = 105;
                                                        UPDATE members SET event_mode = PrizeContentId WHERE id = MemberId;
                                                    END IF;

                                                    INSERT INTO flow_limits(member_id, `type`, event_id, reference_id, flow_limit, `admin`)
                                                    VALUES(MemberId, TransactionLogType, EventId, ReferenceId, FlowLimitPoint, OperatorId);

                                                    # 取得flow_limits.id
                                                    SELECT LAST_INSERT_ID() INTO FlowLimitId;

                                                    # 自動派彩綁定流水，prize_conetnts版
                                                    CALL SP_add_flow_limit_restrictions(FlowLimitId, PrizeContentId);
                                                END IF;
                                            END IF;
                                        END IF;
                                    ELSE
                                        # 前面加上去過了，要扣回來
                                        UPDATE prize_contents SET sent = (sent - 1) WHERE id = PrizeContentId;
                                    END IF;
                                END IF;
                            END IF;
                        END IF;
                    END IF;
                END IF;
            END IF;
            SET FetchStage = 'cursor';
        END IF;
    UNTIL Done END REPEAT;
    CLOSE CurDate;
END
