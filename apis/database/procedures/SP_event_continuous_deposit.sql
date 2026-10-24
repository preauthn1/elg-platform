CREATE PROCEDURE `SP_event_continuous_deposit`(IN p_MemberId INT, IN p_EventId INT, IN p_OrderId INT)
BEGIN
    # 連續充值活動
    # 2025/12/15 Neil 		ADD

    DECLARE EventMemberOrderId INT DEFAULT 0;# event_member_orders.id; 用來更新最後一筆訂的
    DECLARE PrizeContentId INT DEFAULT 0; # prize_contents.id
    DECLARE PrizeId INT DEFAULT 0; # prizes.id
    DECLARE EventDetailId INT DEFAULT 0; # event_details.id
    DECLARE PointLimit DECIMAL(18, 4) DEFAULT '0.0000'; # 活動最低達成金額
    DECLARE OrderCreatedAt, EventStartTime, EventEndTime DATETIME; # 訂單創建時間 (UTC+8)
    DECLARE OrderDepositPoint DECIMAL(18, 4) DEFAULT '0.0000'; # 訂單儲值金額
    DECLARE LastDepositTime, StartTime, EndTime, StartTimeUtc, EndTimeUtc DATETIME;# 最後儲值時間，更新進度開始時間，更新進度結束時間 (UTC+8)
    DECLARE PrizeTypeId, PrizeIsRepeatedly, PrizePeriod, PrizeAchieveTimes INT; # prizes
    DECLARE PrizeName VARCHAR(30);# prizes
    DECLARE PrizeContentType, PrizeContentNumber, PrizeContentSent, PrizeContentPoint INT; # prize_contents
    DECLARE MemberFirstName VARCHAR(100); # 派獎會員真實姓名
    DECLARE RegisterIp BIGINT(20); # 派獎會員註冊IP
    DECLARE RegisterMobile VARCHAR(30); # 派獎會員手機號碼
    DECLARE ExpireHour, IpBlock, PhoneBlock, AccountBlock INT; # event_details
    DECLARE BlockType, BlockMemberId INT DEFAULT 0; # 用來寫入取消派發，取消類別跟重複的member_id
    DECLARE BlockRemark VARCHAR(100) DEFAULT ' ';
    DECLARE ExpireTime DATETIME; # 獎勵到期日期
    DECLARE TodayDate, StartDate, EndDate DATE; # 獎勵到期日期
    DECLARE OperatorId INT DEFAULT 51; # 系統派發
    DECLARE NowTime DATETIME DEFAULT DATE_ADD(NOW(), INTERVAL 8 HOUR);
    DECLARE ConsecutiveTimes INT DEFAULT 0;# 累積儲值次數
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT 0; # 獎勵金額

	main_block : BEGIN
        SELECT register_ip, mobile, first_name INTO RegisterIp, RegisterMobile, MemberFirstName FROM members WHERE id = p_MemberId;

        # 取得獎勵過期時間，及重複檢查
        SELECT id, `point`, start_time, end_time, expire_hour, global_ip_block, global_phone_block, global_account_block
        INTO EventDetailId, PointLimit, EventStartTime, EventEndTime, ExpireHour, IpBlock, PhoneBlock, AccountBlock
        FROM event_details
        WHERE event_id = p_EventId AND `status` = 1
        Limit 1;

        IF EventDetailId = 0 THEN
            # 如果沒找到對應的event_details，直接離開
            LEAVE main_block;
        END IF;

        # 取得獎勵設定(prizes)
        SELECT id, type_id, `name`, is_repeatedly, IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.period')), 0) as period
			, IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.achieve_times')), 0) as achieve_times
        INTO PrizeId, PrizeTypeId, PrizeName, PrizeIsRepeatedly, PrizePeriod, PrizeAchieveTimes
        FROM prizes
        WHERE event_id = p_EventId AND `status` = 1;

        IF PrizeTypeId = 0 OR PrizeTypeId != 3 THEN
            # 如果沒有獎勵設定或者不是手動領取，直接離開
            LEAVE main_block;
        END IF;
        
        # 取得獎品設定
		SELECT id, `type`, `number`, sent, `point`
        INTO PrizeContentId, PrizeContentType, PrizeContentNumber, PrizeContentSent, PrizeContentPoint
		FROM prize_contents
		WHERE prize_id = PrizeId AND `status` = 1
        LIMIT 1;
     
        IF PrizeContentNumber != -1 AND PrizeContentSent >= PrizeContentNumber THEN
			# 獎品發送完了，直接離開
            LEAVE main_block;
        END IF;
        
        IF PrizeContentType = 1 THEN
			# 類別是儲值金額的%數，直接寫入儲值金額
			SET RewardPoint = OrderDepositPoint;
		ELSE
			SET RewardPoint = PrizeContentPoint;
		END IF;

        # 先查詢玩家累積的進度
        SELECT id, consecutive_times, DATE_ADD(last_deposit_time, INTERVAL 8 HOUR)
        INTO EventMemberOrderId, ConsecutiveTimes, LastDepositTime
        FROM event_member_orders
        WHERE member_id = p_MemberId AND event_id = p_EventId;

        IF EventMemberOrderId = 0 THEN
            # 如果查不到代表沒有，新建一筆
            INSERT INTO event_member_orders (member_id, event_id) VALUE (p_MemberId, p_EventId);
            SELECT LAST_INSERT_ID() INTO EventMemberOrderId;
            SET ConsecutiveTimes = 0;
        END IF;

        # 用訂單編號取得除值的金額跟時間
        SELECT `point`, DATE_ADD(created_at, INTERVAL 8 HOUR) INTO OrderDepositPoint, OrderCreatedAt FROM orders WHERE id = p_OrderId;
        
		IF PointLimit > OrderDepositPoint THEN
			# 儲值金額不達標，直接離開
            LEAVE main_block;
        END IF;
        
        SET TodayDate = DATE(OrderCreatedAt);
        
        IF PrizePeriod = 3 THEN
			#每月重置一次
            SET StartDate = DATE_FORMAT(TodayDate, '%Y-%m-01');
            SET EndDate = LAST_DAY(TodayDate);
        ELSEIF PrizePeriod = 2 THEN
			#每周重置一次
            SET StartDate = DATE_SUB(TodayDate, INTERVAL WEEKDAY(TodayDate) DAY);
            SET EndDate = DATE_ADD(StartDate, INTERVAL 6 DAY);
        ELSEIF PrizePeriod = 1 THEN
			#每日重置一次
            SET StartDate = TodayDate;
            SET EndDate = TodayDate;
		ELSE
			#不重置，週期直接設定活動時間
            SET StartDate = DATE(DATE_ADD(EventStartTime, INTERVAL 8 HOUR));
            SET EndDate = DATE(DATE_ADD(EventEndTime, INTERVAL 8 HOUR));
        END IF;
        
        # 確定周期的開始及結束時間
        SET StartTime = TIMESTAMP(StartDate, '00:00:00');
        SET EndTime = TIMESTAMP(EndDate, '23:59:59');
        SET StartTimeUtc = DATE_SUB(StartTime, INTERVAL 8 HOUR);
        SET EndTimeUtc = DATE_SUB(EndTime, INTERVAL 8 HOUR);
        
        IF LastDepositTime IS NULL OR LastDepositTime < StartTime THEN
			# 如果最後儲值時間是NULL，表示是第一次儲值
            # 如果最後儲值時間小於開始時間，表示累計的是上次的周期，要重置次數
			SET ConsecutiveTimes = 1;
		ELSE
			# 累積次數加1次
			SET ConsecutiveTimes = ConsecutiveTimes + 1;
		END IF;
        
        IF ConsecutiveTimes > PrizeAchieveTimes THEN
			# 超過連續次數，代表獎勵已派發了，直接離開
            LEAVE main_block;
        END IF;

        #更新玩家累積的進度
        UPDATE event_member_orders
        SET consecutive_times = ConsecutiveTimes, 
			last_deposit_time = DATE_ADD(OrderCreatedAt, INTERVAL -8 HOUR), last_order_id = p_OrderId
        WHERE id = EventMemberOrderId;
        
        IF ConsecutiveTimes < PrizeAchieveTimes THEN
			# 不到達標次數，直接離開
            LEAVE main_block;
        END IF;

        # 檢查能否重複派獎
        IF PrizeIsRepeatedly = 0 THEN
            IF EXISTS (SELECT * FROM prize_members WHERE member_id = p_MemberId AND prize_id = PrizeId) THEN
                # 不能重複派獎，如果已派獎過，直接離開
                LEAVE main_block;
            END IF;
		ELSEIF PrizeIsRepeatedly = 1 THEN
			IF EXISTS (
				SELECT * 
                FROM prize_members 
                WHERE member_id = p_MemberId AND prize_id = PrizeId AND created_at >= StartTimeUtc AND created_at < EndTimeUtc
			) THEN
                # 這個周期已經派過獎了，直接離開
                LEAVE main_block;
            END IF;
        END IF;

        IF EXISTS (
            SELECT * FROM prize_members WHERE member_id = p_MemberId AND prize_id = PrizeId AND lottery_number = p_OrderId
        ) THEN
            # 怕直接使用這支SP補發獎勵重複執行，同一張訂單只會派一次
            LEAVE main_block;
        END IF;

        SET BlockType = 0;
        SET BlockMemberId = 0;
        
        IF AccountBlock = 1 THEN
            SELECT id INTO BlockMemberId FROM members WHERE first_name = MemberFirstName AND id != p_MemberId LIMIT 1;
            IF MemberFirstName IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 7;
                SET BlockRemark = '全站真實姓名重複';
            END IF;
        END IF;

        IF IpBlock = 1 AND BlockType = 0 THEN
			SELECT id INTO BlockMemberId FROM members WHERE register_ip = RegisterIp AND id != p_MemberId LIMIT 1;
            IF RegisterIp IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 8;
                SET BlockRemark = '全站註冊IP重複';
            END IF;
        END IF;

        IF PhoneBlock = 1 AND BlockType = 0 THEN
			SELECT id INTO BlockMemberId FROM members WHERE mobile = RegisterMobile AND id != p_MemberId LIMIT 1;
            IF RegisterMobile IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 9;
                SET BlockRemark = '全站手機號碼重複';
            END IF;
        END IF;

        IF BlockType > 0 THEN
			IF EXISTS (SELECT * FROM event_payout_cancels WHERE member_id = p_MemberId AND event_id = p_EventID AND `status` = 0) THEN
				# 如果補已經有未派發的了，就直接離開
				LEAVE main_block;
			END IF;
			
            # 把沒派發的原因寫入table，直接離開
            INSERT INTO event_payout_cancels (event_id, prize_content_id, order_id, from_member_id, member_id, `type`, block_member_id)
                VALUE (p_EventID, PrizeContentId, p_OrderId, p_MemberId, p_MemberId, BlockType, BlockMemberId);
            LEAVE main_block;
        END IF;

        # 設定派獎的到期日期
        IF ExpireHour > 0 THEN
            SET ExpireTime = DATE_ADD(NOW(), INTERVAL ExpireHour HOUR);
        ELSEIF ExpireHour = -1 THEN
            SET ExpireTime = EndTimeUtc;
        ELSE
            SET ExpireTime = NULL;
        END IF;

        IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = p_MemberId) THEN
            # event_processes的進度條加1
            UPDATE event_processes SET `point` = (`point` + PointLimit) WHERE detail_id = EventDetailId AND member_id = p_MemberId;
        ELSE
            INSERT INTO event_processes(detail_id, member_id, `point`, `status`, `admin`)
            VALUES(EventDetailId, p_MemberId, PointLimit, 0, OperatorId);
        END IF;

        # 寫入領獎紀錄
        INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_number, lottery_point, `status`, `admin`)
        VALUES(p_MemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, p_OrderId, RewardPoint, 0, OperatorId);
    END main_block;
END