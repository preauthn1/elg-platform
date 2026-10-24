CREATE PROCEDURE `SP_event_register_tmp`(
    IN p_MemberId INT,
    IN p_AgentId INT,
    IN p_Type VARCHAR(10)
)
BEGIN
    # 註冊獎勵
    # 2023/10/03 Mars 		ADD

    DECLARE MemberId INT DEFAULT IFNULL(p_MemberId, 0);
    DECLARE AgentId INT DEFAULT IFNULL(p_AgentId, 0);
    # regi / account
    DECLARE SpType VARCHAR(10) DEFAULT IFNULL(p_Type, 'regi');
    DECLARE TransactionNo, RegisterMobile VARCHAR(30);
    DECLARE RegisterFirstName VARCHAR(50);
    DECLARE PlatformAgentId, EventDetailId, FlowLimitId,
        OrganizationId INT;
    DECLARE RegisterIp BIGINT(20);
    DECLARE TransactionLogType INT DEFAULT 19;
    DECLARE RollingChipType INT DEFAULT 9;
    DECLARE BeforeBalance, DepositPoint, BeforeRollingChip DECIMAL(18, 4);
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
    DECLARE IpBlock, PhoneBlock, BrowserBlock, AccountBlock TINYINT(1) DEFAULT 0;
    DECLARE EventStartTime, EventEndTime DATETIME;

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
             , ed.ip_block
             , ed.phone_block
             , ed.browser_block
             , ed.account_block
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

            select 'error', @p2;
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
                IpBlock, PhoneBlock, BrowserBlock, AccountBlock;

        IF NOT Done THEN
            IF (SpType != 'account' AND AccountBlock = 0) OR
               (SpType = 'account' AND AccountBlock = 1) THEN

                #20:註冊送
                SET TransactionLogType = 20;
                SET RollingChipType = 10;

                # 查錢包餘額
                SELECT m.balance, m.register_ip, m.mobile, m.first_name
                INTO BeforeBalance, RegisterIp, RegisterMobile, RegisterFirstName
                FROM members AS m
                         JOIN agents AS a ON a.id = m.parent
                WHERE m.id = MemberId;

                # 檢查此活動是否為此代理線
                IF EXISTS (SELECT * FROM agents WHERE id = AgentId AND hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')) THEN
                    # 計算percentage
                    SELECT floor(rand() * SUM(percentage)), COUNT(*)
                    INTO PrizePercentage, PrizeCount
                    FROM prize_contents
                    WHERE prize_id = PrizeId
                      AND status = 1;
                    #AND (number = -1 OR (number - sent) > 0);

                    ## 檢查IP/mobile/account是否重覆
                    IF PrizeCount > 0 THEN
                        IF AccountBlock = 1 THEN
                            IF SpType != 'account' THEN
                                SET PrizeCount = 0;
                            ELSEIF EXISTS (
                                SELECT *
                                FROM prize_members AS pm
                                         JOIN members AS m
                                              ON m.id = pm.member_id
                                                  AND m.first_name = RegisterFirstName
                                                  AND m.created_at >= EventStartTime
                                                  AND m.created_at <= EventEndTime
                                WHERE pm.prize_id = PrizeId
                                  AND pm.event_detail_id = EventDetailId
                                  AND pm.member_id != MemberId
                            ) THEN
                                SET PrizeCount = 0;
                            END IF;
                        END IF;

                        IF IpBlock = 1 THEN
                            IF EXISTS (
                                SELECT pm.*
                                FROM prize_members AS pm
                                         JOIN members AS m
                                              ON m.id = pm.member_id
                                                  AND m.register_ip = RegisterIp
                                                  AND m.created_at >= EventStartTime
                                                  AND m.created_at <= EventEndTime
                                WHERE pm.prize_id = PrizeId
                                  AND pm.event_detail_id = EventDetailId
                                  AND pm.member_id != MemberId
                            ) THEN
                                SET PrizeCount = 0;
                            END IF;
                        END IF;

                        IF PhoneBlock = 1 THEN
                            IF EXISTS (
                                SELECT *
                                FROM prize_members AS pm
                                         JOIN members AS m
                                              ON m.id = pm.member_id
                                                  AND m.mobile = RegisterMobile
                                                  AND m.created_at >= EventStartTime
                                                  AND m.created_at <= EventEndTime
                                WHERE pm.prize_id = PrizeId
                                  AND pm.event_detail_id = EventDetailId
                                  AND pm.member_id != MemberId
                            ) THEN
                                SET PrizeCount = 0;
                            END IF;
                        END IF;
                    END IF;

                    IF PrizeCount > 0 THEN
                        # 找出獎勵內容
                        SELECT z.id, z.type, z.name, z.number, z.sent, z.percentage, IFNULL(z.point, 0), IFNULL(z.point_percentage, 0), z.point_type
                        INTO PrizeContentId, PrizeType, PrizeName, PrizeNumber, PrizeSent, PrizePercentage, PrizePoint, PrizePointPercentage, PointType
                        FROM (
                                 SELECT PrizePercentage, (@i:= @i + p.percentage) AS rownum,
                                        p.id, p.type, p.name, p.number, p.sent, p.percentage, p.point_type as point_type, IFNULL(p.point, 0) AS point,
                                        IFNULL(p.point_percentage, 0) as point_percentage
                                 FROM prize_contents AS p
                                    , (SELECT @i:=0) AS r
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

                        IF Continues = 0 THEN
                            # 查看獎項上限
                            #IF PrizeNumber = -1 OR (PrizeNumber - PrizeSent) > 0 THEN
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
                                INSERT INTO prize_members(member_id, prize_id, event_detail_id, lottery_point, status, admin)
                                VALUES(MemberId, PrizeId, EventDetailId, RewardPoint, 0, OperatorId);
                            END IF;

                            IF PrizeTypeId = 14 THEN
                                # 寫入領獎紀錄
                                INSERT INTO prize_members(member_id, prize_id, event_detail_id, content_id, lottery_point, status, admin)
                                VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, RewardPoint, 1, OperatorId);

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
                                END IF;

                                # 取得transaction_logs.id
                                SELECT LAST_INSERT_ID() INTO ReferenceId;

                                # 寫入稽核流水
                                IF FlowLimitRate > 0 THEN
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

                            END IF;

                            #更新獎品派發數量
                            UPDATE prize_contents
                            SET sent = (sent + 1)
                            WHERE id = PrizeContentId;
                            #END IF;
                        END IF;
                    END IF;
                END IF;
            END IF;
        END IF;

    UNTIL Done END REPEAT;
    CLOSE CurDate;

    #COMMIT;
END
