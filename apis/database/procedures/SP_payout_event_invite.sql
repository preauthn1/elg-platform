CREATE PROCEDURE `SP_payout_event_invite`(
    IN p_member_id INT,
    IN p_event_id INT,
    IN p_event_detail_id INT,
    IN p_prize_id INT
)
BEGIN

    # 派發老虎機爭霸戰獎項
    # 2024/08/30 Neil 		新增

    DECLARE PrizeContentId, PointType, EventProcessId INT(11);
    DECLARE AgentId INT DEFAULT(SELECT parent FROM members WHERE id = p_member_id);
    DECLARE TransactionLogType INT DEFAULT 26; #邀請活動
    DECLARE RollingChipLogType INT DEFAULT 13; #邀請活動
    DECLARE RewardPoint DECIMAL(18, 4);
    DECLARE BeforeRollingChip DECIMAL(18, 4);
    DECLARE BeforeBalance DECIMAL(18, 4) DEFAULT 0;
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE TransactionNo VARCHAR(30) DEFAULT '0';
    DECLARE FlowLimitMultiple INT DEFAULT (SELECT flow_limit_multiple FROM prizes WHERE id = p_prize_id);
    DECLARE ReferenceId, PrizeCount INT DEFAULT 0;
    DECLARE Remark VARCHAR(50) DEFAULT ' ';
    DECLARE RegisterIp BIGINT(20);
    DECLARE MemberFirstName VARCHAR(100);
    DECLARE RegisterMobile VARCHAR(30);
    DECLARE IpBlock, PhoneBlock, AccountBlock TINYINT(1) DEFAULT 0;
    DECLARE EventStartTime, EventEndTime DATETIME;

    SELECT id, point_type, point, name, count(*)
    INTO PrizeContentId, PointType, RewardPoint, Remark, PrizeCount
    FROM prize_contents
    WHERE prize_id = p_prize_id;

    ## 檢查IP/mobile/account是否重覆
    /*IF PrizeCount > 0 THEN
        SELECT ip_block, phone_block, account_block, start_time, end_time
        INTO IpBlock, PhoneBlock, AccountBlock, EventStartTime, EventEndTime
        FROM event_details
        WHERE id = p_event_detail_id;

        IF AccountBlock = 1 THEN
            IF MemberFirstName IS NULL THEN
                SET PrizeCount = 0;
            ELSEIF EXISTS (
                SELECT *
                FROM prize_members AS pm
                JOIN members AS m
                ON m.id = pm.member_id
                AND m.first_name = MemberFirstName
                AND m.created_at >= EventStartTime
                AND m.created_at <= EventEndTime
                WHERE pm.prize_id = p_prize_id
                AND pm.event_detail_id = p_event_detail_id
                AND pm.member_id != p_member_id
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
                WHERE pm.prize_id = p_prize_id
                AND pm.event_detail_id = p_event_detail_id
                AND pm.member_id != p_member_id
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
                WHERE pm.prize_id = p_prize_id
                AND pm.event_detail_id = p_event_detail_id
                AND pm.member_id != p_member_id
            ) THEN
                SET PrizeCount = 0;
            END IF;
        END IF;
    END IF;*/

    IF PrizeCount > 0 THEN
        #寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
        SELECT id INTO EventProcessId FROM event_processes WHERE detail_id = p_event_detail_id AND member_id = p_member_id;
        IF EventProcessId IS NOT NULL AND EventProcessId > 0 THEN
            UPDATE event_processes SET point = (point + 1), received = (received + 1) WHERE id = EventProcessId;
        ELSE
            INSERT INTO event_processes (detail_id, member_id, point, received, status, admin)
                VALUE(p_event_detail_id, p_member_id, 1, 1, 0, 0);
        END IF;

        IF PointType = 1 THEN
            SET TransactionNo = CONCAT(DATE_FORMAT(NOW(), '%Y%m%d%H%i%S'),RIGHT(CONCAT('000', FLOOR(RAND()*100)), 4));
            SELECT balance INTO BeforeBalance FROM members WHERE id = p_member_id;
            # 寫入獎勵
            UPDATE members SET balance = (balance + RewardPoint) WHERE id = p_member_id;
            # 寫入transaction_logs
            INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                        , type, point, vendor_point, rate, before_balance, balance, vendor_balance, status, remark, result, operator, platform, admin)
            VALUES(TransactionNo, AgentId, p_member_id, 0, 0, TransactionLogType, RewardPoint, 0, 1, BeforeBalance, (BeforeBalance + RewardPoint), 0
                  , 1000, Remark, 0, 'system', 0, 51);
            SELECT LAST_INSERT_ID() INTO ReferenceId;
        ELSEIF PointType = 2 THEN
            # 取玩家禮碼餘額
            SELECT mw.balance INTO BeforeRollingChip
            FROM members AS m
                     LEFT JOIN member_wallets AS mw ON mw.member_id = m.id
            WHERE member_id = p_member_id AND wallet_type_id = WalletTypeId;

            IF BeforeRollingChip IS NOT NULL THEN
                # 更新玩家禮碼餘額
                UPDATE member_wallets SET balance = (BeforeRollingChip + RewardPoint)
                WHERE member_id = p_member_id AND wallet_type_id = WalletTypeId;
            ELSE
                SET BeforeRollingChip = 0;
                # 創建玩家禮碼錢包
                INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
                VALUES(p_member_id, WalletTypeId, RewardPoint, 1, 0);
            END IF;
            # 寫入rolling_chip_logs
            INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance, balance, `status`, remark, `admin`)
            VALUES(AgentId, p_member_id, 0, RollingChipLogType, RewardPoint, BeforeRollingChip, (BeforeRollingChip + RewardPoint), 1000, Remark, 51);
            SELECT LAST_INSERT_ID() INTO ReferenceId;
        END IF;

        # 寫進prize_members
        INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, lottery_point, status, admin)
            VALUE(p_member_id, p_prize_id, PrizeContentId, p_event_detail_id, RewardPoint, 1, 51);

        # 有設定稽核流水倍數，就新增稽核流水log
        IF FlowLimitMultiple > 0 THEN
            INSERT INTO flow_limits(member_id, type, event_id, reference_id, flow_limit, reach, admin)
            VALUES(p_member_id, TransactionLogType, p_event_id, TransactionNo, (RewardPoint * FlowLimitMultiple), 0, 0);
        END IF;
    END IF;
END
