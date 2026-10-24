CREATE PROCEDURE `SP_event_register`(IN p_MemberId INT, IN p_AgentId INT, IN p_Type VARCHAR(10))
BEGIN
    # 註冊獎勵
    # 2023/10/03 Mars 		ADD
    # 2024/01/05 Rory		派發優惠錢包
    # 2025/04/26 Neil       修正註冊能直接填真實姓名導致不派發的問題
    # 2025/07/13 Neil       重複IP/手機號碼/真實姓名不派獎優化，新增tgid_block
    # 2025/10/22 Neil		[GFF]輪盤限量派發異常，修正機率，超過數量派發
	# 2025/11/28 Neil       [GFF][alfawin77]活動阻擋增加判斷全站資訊

    DECLARE MemberId INT DEFAULT IFNULL(p_MemberId, 0);
    DECLARE AgentId INT DEFAULT IFNULL(p_AgentId, 0);
    # regi / account
    DECLARE SpType VARCHAR(10) DEFAULT IFNULL(p_Type, 'regi');
    DECLARE TransactionNo, RegisterMobile VARCHAR(30);
    DECLARE RegisterFirstName VARCHAR(50);
    DECLARE PlatformAgentId, EventDetailId, FlowLimitId,
        OrganizationId, PromotionWalletId INT;
    DECLARE RegisterIp BIGINT(20);
    DECLARE TransactionLogType INT DEFAULT 19;
    DECLARE RollingChipType INT DEFAULT 9;
    DECLARE BeforeBalance, DepositPoint, BeforeRollingChip, BeforePromotionWalletBalance DECIMAL(18, 4);
    DECLARE RemarkText VARCHAR(50) DEFAULT '註冊活動獎勵';
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT 0; 		# 獎勵金額
    DECLARE FlowLimitRate DECIMAL(10, 2) DEFAULT 0; 		# 稽核流水倍數
    DECLARE Operator VARCHAR(10) DEFAULT 'system';
    DECLARE OperatorId INT DEFAULT 51;
    DECLARE ReferenceId INT DEFAULT 0;
    DECLARE EventId, PrizeId, ActionId, DetailOrderType, PointLimit, PrizeCount INT DEFAULT 0;
    DECLARE PrizeContentId, PrizeTypeId, PrizeType, PrizeNumber, PrizeSent,
        PrizePercentage, PrizePoint, PrizeIsRepeatedly INT;
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE PointType INT DEFAULT 1;
    DECLARE PrizePointPercentage DECIMAL(4, 2) DEFAULT 0.00;
    DECLARE PrizeName VARCHAR(50);
    DECLARE PrizeFlowLimitIncludePrincipal TINYINT(1);
    DECLARE FlowLimitVendorId, FlowLimitGameType VARCHAR(100);
    DECLARE FlowLimitRestrictionsCnt, l_FlowLimitVendorId, l_FlowLimitGameType, x INT;
    DECLARE IpBlock, PhoneBlock, BrowserBlock, AccountBlock, TgidBlock TINYINT(1) DEFAULT 0;
    DECLARE EventStartTime, EventEndTime DATETIME;
    DECLARE HierarchyPath VARCHAR(500);
    DECLARE PrizeMemberId INT DEFAULT 0;
    DECLARE BlockType, BlockMemberId INT DEFAULT 0; # 用來寫入取消派發，取消類別跟重複的member_id
    DECLARE BlockRemark VARCHAR(100) DEFAULT ' ';
    DECLARE TgUserId BIGINT(20) DEFAULT 0;

    DECLARE Done, Continues INT DEFAULT 0;

    # 獲取當前活動(type_id; 11:註冊獎勵)
    DECLARE CurDate CURSOR FOR
        SELECT e.id, ed.id, e.agent_id
             , ed.action_id
             , ed.order_type_id
             , ed.point
             , ed.start_time
             , ed.end_time
             , p.id
             , p.type_id
             , p.is_repeatedly
             , p.flow_limit_multiple
             , IFNULL(JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.flow_limit_include_principal')), 0) as flow_limit_include_principal
             , ed.global_ip_block
             , ed.global_phone_block
             , ed.browser_block
             , ed.global_account_block
             , ed.tgid_block
        FROM event_details AS ed
                 JOIN events AS e ON e.id = ed.event_id AND e.status = 1
                 JOIN prizes AS p ON p.event_id = e.id AND p.status = 1
        WHERE ed.action_id = 11
          AND ed.status = 1
          AND ed.start_time <= NOW()
          AND ed.end_time >= NOW();

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_event_register(',
                    IFNULL(p_MemberId, 'NULL'), ',',
                    IFNULL(p_AgentId, 'NULL'), ',',
                    IFNULL(p_Type, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
            #ROLLBACK;
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    #START TRANSACTION;

    OPEN CurDate;
    REPEAT
        FETCH CurDate
            INTO EventId, EventDetailId, PlatformAgentId, ActionId, DetailOrderType,
                PointLimit, EventStartTime, EventEndTime,
                PrizeId, PrizeTypeId, PrizeIsRepeatedly, FlowLimitRate, PrizeFlowLimitIncludePrincipal,
                IpBlock, PhoneBlock, BrowserBlock, AccountBlock, TgidBlock;

        IF NOT Done THEN
            IF (SpType != 'account' AND AccountBlock = 0) OR
               (SpType = 'regi' AND AccountBlock = 1) OR
               (SpType = 'account' AND AccountBlock = 1) THEN

                #20:註冊送
                SET TransactionLogType = 20;
                SET RollingChipType = 10;

                # 查錢包餘額
                SELECT m.balance, m.register_ip, m.mobile, m.first_name, a.hierarchy_path
                INTO BeforeBalance, RegisterIp, RegisterMobile, RegisterFirstName, HierarchyPath
                FROM members AS m
                         JOIN agents AS a ON a.id = m.parent
                WHERE m.id = MemberId;

                # 檢查此活動是否為此代理線
                IF EXISTS (SELECT * FROM agents WHERE id = AgentId AND hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')) THEN

                    # 判斷是否為排除代理
                    IF NOT EXISTS (
                        SELECT *
                        FROM event_exclude_agents AS eea
                                 JOIN agents AS a ON a.id = eea.agent_id AND HierarchyPath LIKE CONCAT(a.hierarchy_path, '%')
                        WHERE eea.event_id = EventId
                    ) THEN
                        # 計算percentage
                        SELECT COUNT(*) INTO PrizeCount
                        FROM prize_contents
                        WHERE prize_id = PrizeId AND status = 1 AND (number = -1 OR (number - sent) > 0);

                        ## 檢查IP/mobile/account是否重覆
                        IF PrizeCount > 0 THEN
                            SET BlockType = 0;
                            SET BlockMemberId = 0;
                            IF AccountBlock = 1 THEN
                                SELECT m.id INTO BlockMemberId
                                FROM members AS m
									JOIN agents AS a ON a.id = m.parent AND a.hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')
                                WHERE m.first_name = RegisterFirstName AND m.id != MemberId
								LIMIT 1;

                                IF RegisterFirstName IS NULL OR BlockMemberId > 0 THEN
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
                            SELECT FLOOR(RAND() * SUM(percentage)) INTO PrizePercentage
                            FROM prize_contents
                            WHERE prize_id = PrizeId AND `status` = 1 AND (`number` = -1 OR (`number` - sent) > 0);

                            SELECT z.id, z.type, z.name, z.number, z.sent, z.percentage, IFNULL(z.point, 0), IFNULL(z.point_percentage, 0), z.point_type
                            INTO PrizeContentId, PrizeType, PrizeName, PrizeNumber, PrizeSent, PrizePercentage, PrizePoint, PrizePointPercentage, PointType
                            FROM (
                                     SELECT PrizePercentage, (@i:= @i + p.percentage) AS rownum,
                                            p.id, p.type, p.name, p.number, p.sent, p.percentage, p.point_type as point_type, IFNULL(p.point, 0) AS point,
                                            IFNULL(p.point_percentage, 0) as point_percentage
                                     FROM prize_contents AS p, (SELECT @i:=0) AS r
                                     WHERE prize_id = PrizeId AND status = 1 AND (p.`number` = -1 OR (p.`number` - p.sent) > 0)
                                 ) AS z
                            WHERE z.rownum >= PrizePercentage
                            LIMIT 1;

                            #更新獎品派發數量
                            UPDATE prize_contents SET sent = (sent + 1) WHERE id = PrizeContentId;

                            SET Continues = 0;
                            IF PrizeIsRepeatedly = 1 THEN
                                # 查看是否已領過
                                IF EXISTS (
                                    SELECT * FROM prize_members
                                    WHERE member_id = MemberId
                                      AND prize_id = PrizeId
                                      AND event_detail_id = EventDetailId
                                ) THEN
                                    SET Continues = 1;
                                END IF;

                            ELSEIF PrizeIsRepeatedly = 2 THEN
                                # 查看是否已領過
                                IF EXISTS (
                                    SELECT * FROM prize_members
                                    WHERE member_id = MemberId
                                      AND prize_id = PrizeId
                                      AND event_detail_id = EventDetailId
                                      AND DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))
                                ) THEN
                                    SET Continues = 1;
                                END IF;
                            ELSE
                                IF EXISTS (
                                    SELECT * FROM prize_members
                                    WHERE member_id = MemberId
                                      AND prize_id = PrizeId
                                      AND event_detail_id = EventDetailId
                                ) THEN
                                    SET Continues = 1;
                                END IF;
                            END IF;

                            IF BlockType > 0 AND Continues = 0 THEN
                                SET Continues = 1;
                                # 把沒派發的原因寫入table
                                INSERT INTO event_payout_cancels (event_id, prize_content_id, from_member_id, member_id, `type`, block_member_id)
                                    VALUE (EventId, PrizeContentId, MemberId, MemberId, BlockType, BlockMemberId);
                            END IF;

                            IF Continues = 0 THEN
                                # 查看獎項上限
                                IF PrizeNumber = -1 OR (PrizeNumber - PrizeSent) > 0 THEN
                                    SET RemarkText = PrizeName;

                                    # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
                                    IF PrizeTypeId = 14 THEN
                                        # 計算獎勵金額
                                        IF PrizeType = 1 THEN
                                            # 依照比例
                                            SET RewardPoint = (DepositPoint * PrizePointPercentage);
                                        ELSE
                                            # 固定金額
                                            SET RewardPoint = PrizePoint;
                                        END IF;

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
                                        INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, lottery_point, status, admin)
                                        VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, RewardPoint, 1, OperatorId);
                                    ELSE
                                        # 計算獎勵金額
                                        IF PrizeType = 1 THEN
                                            # 依照比例
                                            SET RewardPoint = DepositPoint;
                                        ELSE
                                            # 固定金額
                                            SET RewardPoint = PrizePoint;
                                        END IF;

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
                                        INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, lottery_point, status, admin)
                                        VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, RewardPoint, 0, OperatorId);
                                    END IF;

                                    SELECT LAST_INSERT_ID() INTO PrizeMemberId;

                                    IF PrizeTypeId = 14 THEN
                                        IF PointType = 1 THEN
                                            # 寫入獎勵
                                            UPDATE members SET balance = (balance + RewardPoint)
                                            WHERE id = MemberId;

                                            SET TransactionNo = CONCAT(
                                                DATE_FORMAT(NOW(), '%Y%m%d%H%i%S'),
                                                RIGHT(CONCAT('000', FLOOR(RAND()*10000)), 4)
                                                                );

                                            # 寫入transaction_logs
                                            INSERT INTO transaction_logs(
                                                transaction_no, agent_id, member_id, vendor_id, order_id,
                                                type, point, vendor_point, rate,
                                                before_balance, balance, vendor_balance,
                                                status, remark, result, operator, platform, admin)
                                            VALUES(
                                                      TransactionNo, AgentId, MemberId, 0, 0,
                                                      TransactionLogType, RewardPoint, 0, 1,
                                                      BeforeBalance, (BeforeBalance + RewardPoint), 0,
                                                      1000, RemarkText, 0, Operator, 0, 0);

                                        ELSEIF PointType = 2 THEN
                                            # 取玩家禮碼餘額
                                            IF EXISTS (
                                                SELECT *
                                                FROM member_wallets
                                                WHERE member_id = MemberId AND wallet_type_id = WalletTypeId
                                            ) THEN
                                                SELECT balance INTO BeforeRollingChip
                                                FROM member_wallets
                                                WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;
                                                # 更新玩家禮碼餘額
                                                UPDATE member_wallets
                                                SET balance = (BeforeBalance + RewardPoint)
                                                WHERE id = MemberId AND wallet_type_id = WalletTypeId;
                                            ELSE
                                                SET BeforeRollingChip = 0;
                                                # 創建玩家禮碼錢包
                                                INSERT INTO member_wallets(member_id, wallet_type_id, balance, status, admin)
                                                VALUES(MemberId, WalletTypeId, RewardPoint, 1, 0);
                                            END IF;

                                            # 寫入crolling_chip_logs
                                            INSERT INTO rolling_chip_logs(
                                                                           agent_id, member_id, vendor_id, type, amount, before_balance
                                                                         , balance, status, remark, admin
                                            )
                                            VALUES(
                                                      AgentId, MemberId, 0, RollingChipType, RewardPoint, BeforeRollingChip, (BeforeRollingChip + RewardPoint),
                                                      1000, RemarkText, 0);
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
                                                CALL COM_Send_Error_Message(CONCAT('[優惠錢包通知] SP_event_register(', IFNULL(p_order_id, 'NULL'), ') 衝突 '), 'db');
                                            END IF;
                                        END IF;

                                        # 取得transaction_logs.id
                                        SELECT LAST_INSERT_ID() INTO ReferenceId;

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

                                            SELECT REPLACE(REPLACE(REPLACE(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_vendor_id')), -1), '\[', ''), '\]', ''), '"', ''),
                                                   REPLACE(REPLACE(REPLACE(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_game_type')), -1), '\[', ''), '\]', ''), '"', ''),
                                                   char_length(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_vendor_id')),- 1)) - char_length(REPLACE(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.flow_limit_vendor_id')),- 1), ',', '')) + 1
                                            INTO FlowLimitVendorId, FlowLimitGameType, FlowLimitRestrictionsCnt
                                            FROM prizes
                                            WHERE id = PrizeId;

                                            IF FlowLimitVendorId != -1 AND FlowLimitVendorId != '' THEN
                                                SET x = 1;
                                                WHILE x <= FlowLimitRestrictionsCnt DO
                                                        SET l_FlowLimitVendorId = SUBSTRING_INDEX(SUBSTRING_INDEX(FlowLimitVendorId, ',', x), ',', -1);
                                                        SET l_FlowLimitGameType = SUBSTRING_INDEX(SUBSTRING_INDEX(FlowLimitGameType, ',', x), ',', -1);
                                                        # SELECT l_FlowLimitVendorId, l_FlowLimitGameType;
                                                        INSERT INTO flow_limit_restrictions(flow_limit_id, vendor_id, type_id)
                                                        VALUES(FlowLimitId, l_FlowLimitVendorId, l_FlowLimitGameType);
                                                        SET x = x + 1;
                                                    END WHILE;
                                            END IF;
                                        END IF;
                                    END IF;
                                END IF;
                            ELSE
                                #前面加上去過了，要扣回來
                                UPDATE prize_contents SET sent = (sent - 1) WHERE id = PrizeContentId;
                            END IF;
                        END IF;
                    END IF;
                END IF;
            END IF;
        END IF;

    UNTIL Done END REPEAT;
    CLOSE CurDate;
END
