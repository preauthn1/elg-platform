CREATE PROCEDURE `SP_event_invite_deposit`(IN p_order_id INT)
BEGIN
    # 下線充值獎金
    # 2025/03/11 Neil 		ADD
    # 2025/05/21 Mars 		Modify: 修正被邀請註冊的用戶，只要IP、姓名、電話其中一項與同總代之下現有會員重複，就不計邀請講理
    # 2025/07/13 Neil       重複IP/手機號碼/真實姓名不派獎優化，新增tgid_block
    # 2025/11/28 Neil       [GFF][alfawin77]活動阻擋增加判斷全站資訊
    # 2026/03/19 Neil       [GFF][alfawin77]活動阻擋增加判斷真實姓名未認證
    # 2026/06/08 Neil       修正檢查訂單金額時未加上手續費

    # 設定
    DECLARE SPName VARCHAR(50) DEFAULT '下線充值獎金';
    DECLARE OperatorId INT DEFAULT 51;
    # CURSOR
    DECLARE EventId, EventDetailId, PrizeId, PlatformAgentId, DetailOrderType, ExpireHour INT DEFAULT 0;
    DECLARE PointLimit DECIMAL(18, 4) DEFAULT '0.0000';
    DECLARE EventStartTime, EventEndTime, ExpireTime DATETIME;
    DECLARE IpBlock, PhoneBlock, AccountBlock, RealnameBlock, TgidBlock, RealnameValidate TINYINT(1) DEFAULT 0;
    DECLARE BlockType, BlockMemberId INT DEFAULT 0; # 用來寫入取消派發，取消類別跟重複的member_id
    DECLARE BlockRemark VARCHAR(100) DEFAULT ' ';
    DECLARE TgUserId BIGINT(20) DEFAULT 0;
    # Order
    DECLARE MemberId, OrderStatus, OrderTypeId, AgentId, PayoutMemberId INT DEFAULT 0;
    DECLARE DepositPoint DECIMAL(18, 4) DEFAULT '0.0000';
    DECLARE RegisterIp BIGINT(20);
    DECLARE RegisterMobile, MemberInviteCode VARCHAR(30);
    DECLARE MemberFirstName VARCHAR(100);
    DECLARE HierarchyPath, GeneralHierarchyPath VARCHAR(255);
    # 隨機獎項
    DECLARE PrizePercentage, PrizeCount INT DEFAULT 0;
    # PrizeContents
    DECLARE PrizeContentId, PrizeContentType, PrizeContentNumber, PrizeContentSent, PrizeContentPercentage, PrizeContentPointType INT DEFAULT 0;
    DECLARE PrizeContentPoint, RewardPoint DECIMAL(18, 4) DEFAULT '0.0000';
    DECLARE PrizeContentPointPercentage DECIMAL(8, 2) DEFAULT '0.00';

    DECLARE Done, Continues INT DEFAULT 0;
    # 取得該筆訂單的建立時間
    DECLARE OrderCreatedAt DATETIME DEFAULT (SELECT created_at FROM orders WHERE id = p_order_id);

    # 獲取當前活動
    DECLARE CurDate CURSOR FOR
        SELECT e.id, ed.id, p.id, e.agent_id, ed.order_type_id, ed.start_time, ed.end_time, expire_hour, ed.point
             , ed.global_ip_block, ed.global_phone_block, ed.global_account_block, realname_block, tgid_block, a.hierarchy_path
        FROM event_details AS ed
                 JOIN `events` AS e ON e.id = ed.event_id AND e.`status` = 1
                 JOIN prizes AS p ON p.event_id = e.id AND p.`status` = 1
                 JOIN agents AS a ON a.id = e.agent_id
        WHERE ed.action_id = 16
          AND ed.status = 1
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
                    '[系統通知] SP_event_invite_deposit(',
                    IFNULL(p_order_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN CurDate;
    cursor_loop : REPEAT
        FETCH CurDate
            INTO EventId, EventDetailId, PrizeId, PlatformAgentId, DetailOrderType, EventStartTime, EventEndTime, ExpireHour, PointLimit
                , IpBlock, PhoneBlock, AccountBlock, RealnameBlock, TgidBlock, GeneralHierarchyPath;

        IF Done = 1 THEN
            LEAVE cursor_loop;
        END IF;

        # 重置
        SET OrderStatus = 0;

        # 查訂單
        SELECT o.member_id, m.invitation_code, (o.`point` + o.tax), o.`status`, o.order_type_id, m.parent, m.register_ip, m.mobile, m.first_name, m.realname_validate, a.hierarchy_path
        INTO MemberId, MemberInviteCode, DepositPoint, OrderStatus, OrderTypeId, AgentId, RegisterIp, RegisterMobile, MemberFirstName, RealnameValidate, HierarchyPath
        FROM orders AS o
                 JOIN members AS m ON m.id = o.member_id
                 JOIN agents AS a ON a.id = m.parent
        WHERE o.id = p_order_id;

        IF MemberInviteCode IS NULL THEN
            # 如果儲值此訂單的玩家沒有輸入邀請碼，直接關閉
            LEAVE cursor_loop;
        ELSE
            # 先查詢是否是無限代或三層代的邀請碼
            SELECT member_id
            INTO PayoutMemberId
            FROM member_codes
            WHERE agent_code = MemberInviteCode;

            IF PayoutMemberId = 0 THEN
                # 如果不是，在查詢是否為活動邀請碼
                SELECT ec.member_id INTO PayoutMemberId
                FROM event_codes AS ec
                         JOIN `events` AS e ON e.id = ec.event_id
                WHERE ec.`code` = MemberInviteCode AND ec.event_id = EventId;
                IF PayoutMemberId = 0 THEN
                    # 新的邀請碼格式，直接查詢會員名稱
                    SELECT id INTO PayoutMemberId FROM members WHERE username = MemberInviteCode LIMIT 1;
                    IF PayoutMemberId = 0 THEN
                        # 查無邀請碼直接關閉
                        LEAVE cursor_loop;
                    END IF;
                END IF;
            END IF;
        END IF;

        # 判斷訂單是否成立
        IF OrderStatus != 3 THEN
            ITERATE cursor_loop;
        END IF;

        # 判斷訂單金額是否超過限制
        IF PointLimit > DepositPoint THEN
            ITERATE cursor_loop;
        END IF;

        # 檢查此活動是否為此代理線
        IF NOT EXISTS (SELECT * FROM agents WHERE id = AgentId AND hierarchy LIKE CONCAT('%,', PlatformAgentId, ',%')) THEN
            ITERATE cursor_loop;
        END IF;

        # 判斷是否為排除代理
        IF EXISTS (
            SELECT *
            FROM event_exclude_agents AS eea
                     JOIN agents AS a
                          ON a.id = eea.agent_id
                              AND HierarchyPath LIKE CONCAT(a.hierarchy_path, '%')
            WHERE eea.event_id = EventId
        ) THEN
            ITERATE cursor_loop;
        END IF;

        # 計算percentage
        SELECT FLOOR(RAND() * SUM(percentage)), COUNT(*)
        INTO PrizePercentage, PrizeCount
        FROM prize_contents
        WHERE prize_id = PrizeId
          AND `status` = 1
          AND (`number` = -1 OR (`number` - sent) > 0);

        # 確認有無獎勵
        IF PrizeCount = 0 THEN
            ITERATE cursor_loop;
        END IF;

        # 重置
        SET BlockType = 0;
        SET BlockMemberId = 0;

        # 檢查真實姓名
        IF AccountBlock = 1 THEN
            # 檢查『被邀請人』
            SELECT m.id INTO BlockMemberId
            FROM members AS m
                JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(GeneralHierarchyPath, '%')
            WHERE m.first_name = MemberFirstName AND m.id != MemberId
            LIMIT 1;

            IF MemberFirstName IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 7;
                SET BlockRemark = '全站真實姓名重複';
            END IF;
        END IF;

        # 檢查IP
        IF IpBlock = 1 AND BlockType = 0 THEN
            # 檢查『被邀請人』
            SELECT m.id INTO BlockMemberId
            FROM members AS m
                JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(GeneralHierarchyPath, '%')
            WHERE m.register_ip = RegisterIp AND m.id != MemberId
            LIMIT 1;

            IF RegisterIp IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 8;
                SET BlockRemark = '全站註冊IP重複';
            END IF;
        END IF;

        # 檢查手機號碼
        IF PhoneBlock = 1 AND BlockType = 0 THEN
            # 檢查『被邀請人』
            SELECT m.id INTO BlockMemberId
            FROM members AS m
                JOIN members AS m2 ON m2.mobile = RegisterMobile AND m2.id != MemberId
            JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(GeneralHierarchyPath, '%')
            WHERE m.mobile = RegisterMobile AND m.id != MemberId
            LIMIT 1;

            IF RegisterMobile IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 9;
                SET BlockRemark = '全站手機號碼重複';
            END IF;
        END IF;

        # 檢查tg_user_id
        IF TgidBlock = 1 AND BlockType = 0 THEN
            # 檢查『被邀請人』
            SELECT tg_user_id INTO TgUserId FROM member_telegram_rels WHERE member_id = MemberId LIMIT 1;

            IF TgUserId > 0 THEN
                SELECT mtr.member_id INTO BlockMemberId
                FROM member_telegram_rels AS mtr
                WHERE mtr.tg_user_id = TgUserId AND mtr.member_id != MemberId
                LIMIT 1;

                IF BlockMemberId > 0 THEN
                    SET BlockType = 6;
                    SET BlockRemark = 'tg_user_id重複';
                END IF;
            END IF;
        END IF;
        
        IF RealnameBlock = 1 AND BlockType = 0 THEN
			# 檢查『被邀請人』
            IF RealnameValidate = 0 THEN
				SET BlockType = 10;
                SET BlockMemberId = MemberId;
				SET BlockRemark = '真實姓名未認證';
			END IF;
		END IF;

        # 獎勵期限
        IF ExpireHour > 0 THEN
            # 獎勵再過ExpireHour小時後過期
            SET ExpireTime = DATE_ADD(NOW(), INTERVAL ExpireHour HOUR);
        ELSEIF ExpireHour = -1 THEN
            # 獎勵在當天過期
            IF UTC_TIME() >= '16:00:00' THEN
                SET ExpireTime = CONCAT(DATE(DATE_ADD(UTC_DATE(), INTERVAL 1 DAY)), ' 15:59:59');
            ELSE
                SET ExpireTime = CONCAT(DATE(UTC_DATE()), ' 15:59:59');
            END IF;
        ELSE
            # 獎勵不過期
            SET ExpireTime = NULL;
        END IF;

        # 找出獎勵內容
        SELECT z.id, z.`type`, z.`number`, z.sent, z.percentage, IFNULL(z.`point`, 0), IFNULL(z.point_percentage, 0), z.point_type
        INTO PrizeContentId, PrizeContentType, PrizeContentNumber, PrizeContentSent, PrizeContentPercentage, PrizeContentPoint, PrizeContentPointPercentage, PrizeContentPointType
        FROM (
                 SELECT PrizePercentage
                      , (@i:= @i + p.percentage) AS rownum
                      , p.id
                      , p.`type`
                      , p.`name`
                      , p.`number`
                      , p.sent
                      , p.percentage
                      , p.point_type AS point_type
                      , IFNULL(p.`point`, 0) AS `point`
                      , IFNULL(p.point_percentage, 0) AS point_percentage
                 FROM prize_contents AS p, (SELECT @i:=0) AS r
                 WHERE prize_id = PrizeId AND `status` = 1
             ) AS z
        WHERE z.rownum >= PrizePercentage
        LIMIT 1;

        IF PrizeContentNumber != -1 AND (PrizeContentNumber - PrizeContentSent) <= 0 THEN
            # 領獎次數已達上限，不派獎
            ITERATE cursor_loop;
        END IF;

        IF PrizeContentType = 1 THEN
            SET RewardPoint = PrizeContentPoint;
        ELSE
            SET RewardPoint = DepositPoint;
        END IF;

        IF BlockType > 0 THEN
            # 把沒派發的原因寫入table，直接離開
            INSERT INTO event_payout_cancels (event_id, prize_content_id, order_id, from_member_id, member_id, `type`, block_member_id)
                VALUE (EventId, PrizeContentId, p_order_id, MemberId, PayoutMemberId, BlockType, BlockMemberId);
            ITERATE cursor_loop;
        END IF;

        # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
        IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = PayoutMemberId) THEN
            UPDATE event_processes
            SET point = (point + PointLimit) WHERE detail_id = EventDetailId AND member_id = PayoutMemberId;
        ELSE
            INSERT INTO event_processes(detail_id, member_id, `point`, received, `status`, `admin`)
            VALUES(EventDetailId, PayoutMemberId, PointLimit, 0, 2, OperatorId);
        END IF;
        # 寫入領獎紀錄
        INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_number, lottery_point, `status`, `admin`)
        VALUES(PayoutMemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, p_order_id, RewardPoint, 0, OperatorId);

    UNTIL Done END REPEAT;
    CLOSE CurDate;
END
