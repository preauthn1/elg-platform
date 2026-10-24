CREATE PROCEDURE `SP_event_consecutive_deposit`(IN p_MemberId INT, IN p_EventId INT, IN p_OrderId INT)
BEGIN
    # 每日連續充值活動
    # 2025/06/09 Neil 		ADD
    # 2025/07/13 Neil       重複IP/手機號碼/真實姓名不派獎優化，新增tgid_block

    DECLARE EventMemberOrderId INT DEFAULT 0;# event_member_orders.id; 用來更新最後一筆訂的
    DECLARE PrizeContentId INT DEFAULT 0; # prize_contents.id
    DECLARE PrizeId INT DEFAULT 0; # prizes.id
    DECLARE EventDetailId INT DEFAULT 0; # event_details.id
    DECLARE ConsecutiveTimes, MaxConsecutiveTimes INT DEFAULT 0;# 累積儲值次數, 最大累積儲值次數
    DECLARE OrderCreatedAt DATETIME; # 訂單創建時間 (UTC+8)
    DECLARE OrderDepositPoint DECIMAL(18, 4) DEFAULT '0.0000'; # 訂單儲值金額
    DECLARE LastDepositTime, NextUpdateStartTime, NextUpdateEndTime DATETIME;# 最後儲值時間，更新進度開始時間，更新進度結束時間 (UTC+8)
    DECLARE PrizeTypeId, PrizeIsRepeatedly, PrizeRegistrationLimit INT; # prizes
    DECLARE PrizeName VARCHAR(30);# prizes
    DECLARE PrizeContentType, PrizeContentLevel, PrizeContentAchievePoint, PrizeContentPercentage, PrizeContentPoint INT; # prize_contents
    DECLARE PrizeContentPointType DECIMAL(4, 2) DEFAULT '0.00';
    DECLARE MemberFirstName VARCHAR(100); # 派獎會員真實姓名
    DECLARE RegisterIp BIGINT(20); # 派獎會員註冊IP
    DECLARE RegisterMobile VARCHAR(30); # 派獎會員手機號碼
    DECLARE TgUserId BIGINT(20); # member_telegram_rels.tg_user_id
    DECLARE ExpireHour, IpBlock, PhoneBlock, AccountBlock, TgidBlock INT; # event_details
    DECLARE BlockType, BlockMemberId INT DEFAULT 0; # 用來寫入取消派發，取消類別跟重複的member_id
    DECLARE BlockRemark VARCHAR(100) DEFAULT ' ';
    DECLARE ExpireTime DATETIME; # 獎勵到期日期
    DECLARE OperatorId INT DEFAULT 51; # 系統派發
    DECLARE NowTime DATETIME DEFAULT DATE_ADD(NOW(), INTERVAL 8 HOUR);
    DECLARE AccumulatedAmount, AfterAccumulatedAmount DECIMAL(18, 4) DEFAULT '0.0000';
    DECLARE IsFirstTimeDeposit INT DEFAULT 0; # 是否為當天第一次儲值
    DECLARE IsFirstDay INT DEFAULT 0; # event_member_orders.is_first_day
    DECLARE UpdateProgress INT DEFAULT 0; # 進度是否更新，用來檢查是否要派獎
    DECLARE PayoutPrizeContentId, PayoutPrizeContentAchievePoint INT DEFAULT 0; # 派發用
    DECLARE PrizeContentCnt, RepeatCnt INT DEFAULT 0; # 獎品數量，用來避免無限迴圈
    DECLARE DoneRepeat INT DEFAULT 0; # 用來結束迴圈

    main_block : BEGIN
        SELECT register_ip, mobile, first_name INTO RegisterIp, RegisterMobile, MemberFirstName FROM members WHERE id = p_MemberId;

        # 取得獎勵過期時間，及重複檢查
        SELECT id, expire_hour, ip_block, phone_block, account_block, tgid_block
        INTO EventDetailId, ExpireHour, IpBlock, PhoneBlock, AccountBlock, TgidBlock
        FROM event_details
        WHERE event_id = p_EventId AND `status` = 1
        Limit 1;

        IF EventDetailId = 0 THEN
            # 如果沒找到對應的event_details，直接離開
            LEAVE main_block;
        END IF;

        # 取得獎勵設定(prizes)
        SELECT id, type_id, `name`, is_repeatedly, IFNULL(JSON_UNQUOTE(JSON_EXTRACT(info, '$.registration_limit')), 0) as registration_limit
        INTO PrizeId, PrizeTypeId, PrizeName, PrizeIsRepeatedly, PrizeRegistrationLimit
        FROM prizes
        WHERE event_id = p_EventId AND `status` = 1;

        IF PrizeTypeId = 0 OR PrizeTypeId != 3 THEN
            # 如果沒有獎勵設定或者不是手動領取，直接離開
            LEAVE main_block;
        END IF;

        # 先查詢玩家累積的進度
        SELECT id, accumulated_amount, is_first_day, consecutive_times, max_consecutive_times, DATE_ADD(last_deposit_time, INTERVAL 8 HOUR)
        INTO EventMemberOrderId, AccumulatedAmount, IsFirstDay, ConsecutiveTimes, MaxConsecutiveTimes, LastDepositTime
        FROM event_member_orders
        WHERE member_id = p_MemberId AND event_id = p_EventId;

        IF EventMemberOrderId = 0 THEN
            # 如果查不到代表沒有，新建一筆
            INSERT INTO event_member_orders (member_id, event_id) VALUE (p_MemberId, p_EventId);
            SELECT LAST_INSERT_ID() INTO EventMemberOrderId;
            SET ConsecutiveTimes = 0;
            SET AccumulatedAmount = '0.0000';
            SET IsFirstDay = 1;
        END IF;

        # 用訂單編號取得除值的金額跟時間
        SELECT `point`, DATE_ADD(created_at, INTERVAL 8 HOUR) INTO OrderDepositPoint, OrderCreatedAt FROM orders WHERE id = p_OrderId;
        SET NowTime = OrderCreatedAt; # 測試用，上正式請刪除
        SET IsFirstTimeDeposit = 1; # 預設是今天第一次儲值
        # 判斷玩家的累積進度
        IF LastDepositTime IS NOT NULL THEN
            SET NextUpdateStartTime = CONCAT(DATE(DATE_ADD(LastDepositTime, INTERVAL 1 DAY)), ' 00:00:00'); # 隔天開始時間
            SET NextUpdateEndTime = DATE_ADD(DATE_ADD(NextUpdateStartTime, INTERVAL 1 DAY), INTERVAL -1 SECOND); # 隔天結束時間

            IF NowTime < NextUpdateStartTime THEN
                SET IsFirstTimeDeposit = 0; # 現在時間小於隔天開始時間，表示今天不是第一次儲值
            END IF;

            IF IsFirstTimeDeposit = 1 AND ConsecutiveTimes = 1 THEN
                SET IsFirstDay = 0; # 今天第一次儲值，且昨天已經有累積進度，表示已經不是第一天，無法更改進度
            END IF;

            IF IsFirstTimeDeposit = 1 AND NowTime > NextUpdateEndTime THEN
                # 這次的儲值跨天了，連續儲值的進度要歸0
                SET ConsecutiveTimes = 0;
                SET IsFirstDay = 1;
                SET MaxConsecutiveTimes = 0;
            END IF;

            IF IsFirstTimeDeposit = 1 AND ConsecutiveTimes > 0 THEN
                # 有進度且是今天第一次儲值，要檢查昨天是否有達到標準
                SELECT achieve_point INTO PrizeContentAchievePoint
                FROM prize_contents
                WHERE prize_id = PrizeId AND `level` = MaxConsecutiveTimes AND `status` = 1;

                IF (ConsecutiveTimes < MaxConsecutiveTimes) AND (AccumulatedAmount < PrizeContentAchievePoint) THEN
                    # 昨天沒達到進度所需要的金額，要檢查是否達到前一層的累積金額
                    SELECT COUNT(*) INTO PrizeContentCnt FROM prize_contents WHERE prize_id = PrizeId AND `status` = 1;
                    SET RepeatCnt = 0;
                    REPEAT
                        # 如果往下一層有達標，就直接出迴圈，找不到更下層的話也出迴圈
                        SELECT `level`, achieve_point INTO MaxConsecutiveTimes, PrizeContentAchievePoint
                        FROM prize_contents
                        WHERE prize_id = PrizeId AND `level` < MaxConsecutiveTimes AND `status` = 1
                        ORDER BY `level` DESC
                        LIMIT 1;

                        IF AccumulatedAmount >= PrizeContentAchievePoint THEN
                            # 降低進度後，昨天也算達標，所以進度要加1
                            SET ConsecutiveTimes = ConsecutiveTimes + 1;
                            SET DoneRepeat = 1;
                        END IF;

                        SET RepeatCnt = RepeatCnt + 1;
                    UNTIL MaxConsecutiveTimes = 0 OR DoneRepeat = 1 OR RepeatCnt = PrizeContentCnt
                        END REPEAT;
                END IF;

                IF ConsecutiveTimes >= MaxConsecutiveTimes THEN
                    # 累積儲值次數等於最大儲值次數，表示這次連續儲值的進度已達成，要重新開始一輪
                    SET ConsecutiveTimes = 0;
                    SET IsFirstDay = 1;
                    SET MaxConsecutiveTimes = 0;
                END IF;
            END IF;
        END IF;

        IF IsFirstTimeDeposit = 1 THEN
            # 是當天第一次儲值，要先把累積金額歸0
            SET AccumulatedAmount = 0;
        END IF;

        # 加上這次儲值的金額
        SET AfterAccumulatedAmount = AccumulatedAmount + OrderDepositPoint;

        IF IsFirstDay = 1 THEN
            # 第一天，可以決定進度的上限，所以要用這次儲值之後的金額
            SELECT id, `type`, `level`, achieve_point, percentage, point_type, `point`
            INTO PrizeContentId, PrizeContentType, MaxConsecutiveTimes, PrizeContentAchievePoint,
                PrizeContentPercentage, PrizeContentPointType, PrizeContentPoint
            FROM prize_contents
            WHERE prize_id = PrizeId AND achieve_point <= AfterAccumulatedAmount AND `status` = 1
            ORDER BY achieve_point DESC
            LIMIT 1;
        ELSE
            SELECT id, `type`, `level`, achieve_point, percentage, point_type, `point`
            INTO PrizeContentId, PrizeContentType, MaxConsecutiveTimes, PrizeContentAchievePoint,
                PrizeContentPercentage, PrizeContentPointType, PrizeContentPoint
            FROM prize_contents
            WHERE prize_id = PrizeId AND `level` = MaxConsecutiveTimes AND `status` = 1;

            IF AccumulatedAmount >= PrizeContentAchievePoint THEN
                # 如果累積金額大於等於達標金額，表示今天的進度達成了，直接離開
                LEAVE main_block;
            END IF;
        END IF;

        # 加上這次的儲值金額後，有達到標準就增加進度
        # 是第一天且進度是1，代表今天已經累積過進度了，所以進度不做更新
        IF AfterAccumulatedAmount >= PrizeContentAchievePoint AND (IsFirstDay != 1 OR ConsecutiveTimes != 1) THEN
            SET ConsecutiveTimes = ConsecutiveTimes + 1; # 進度更新
            SET UpdateProgress = 1;
        END IF;

        #更新玩家累積的進度
        UPDATE event_member_orders
        SET accumulated_amount = AfterAccumulatedAmount, is_first_day = IsFirstDay, consecutive_times = ConsecutiveTimes,
            max_consecutive_times = MaxConsecutiveTimes, last_deposit_time = DATE_ADD(NowTime, INTERVAL -8 HOUR), last_order_id = p_OrderId
        WHERE id = EventMemberOrderId;

        # 這次的儲值是否達成派獎標準
        IF UpdateProgress = 0 THEN
            # 這次儲值沒有更新進度，但是有可能已經達到下一個獎項的派發標準，要做派發
            SET ConsecutiveTimes = ConsecutiveTimes + 1;
        END IF;

        # 查詢進度有無派發的獎項
        SELECT id, achieve_point INTO PayoutPrizeContentId, PayoutPrizeContentAchievePoint
        FROM prize_contents
        WHERE prize_id = PrizeId AND `level` = ConsecutiveTimes AND `status` = 1
        LIMIT 1;

        IF PayoutPrizeContentId != 0 THEN
            # 有查到獎項，就檢查獎項是否派發過
            IF AccumulatedAmount >= PayoutPrizeContentAchievePoint THEN
                # 儲值之前的累積金額就比派發金額高，表示獎項已派發過，直接離開
                LEAVE main_block;
            ELSEIF AfterAccumulatedAmount < PayoutPrizeContentAchievePoint THEN
                # 儲值之後的金額沒有達成獎勵，直接離開
                LEAVE main_block;
            END IF;
        ELSE
            # 這次進度沒有獎項，直接離開
            LEAVE main_block;
        END IF;

        # 檢查能否重複派獎
        IF PrizeIsRepeatedly = 0 THEN
            IF EXISTS (SELECT * FROM prize_members WHERE member_id = p_MemberId AND prize_id = PrizeId) THEN
                # 不能重複派獎，如果已派獎過，直接離開
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
        IF AccountBlock = 1 THEN
            SELECT pm.member_id INTO BlockMemberId
            FROM prize_members AS pm
                     JOIN members AS m ON m.id = pm.member_id AND m.first_name = MemberFirstName
            WHERE pm.prize_id = PrizeId AND pm.event_detail_id = EventDetailId AND pm.member_id != p_MemberId
            LIMIT 1;

            IF MemberFirstName IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 1;
                SET BlockRemark = '真實姓名重複';
            END IF;
        END IF;

        IF IpBlock = 1 AND BlockType = 0 THEN
            SELECT pm.member_id INTO BlockMemberId
            FROM prize_members AS pm
                     JOIN members AS m ON m.id = pm.member_id AND m.register_ip = RegisterIp
            WHERE pm.prize_id = PrizeId AND pm.event_detail_id = EventDetailId AND pm.member_id != p_MemberId
            LIMIT 1;

            IF RegisterIp IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 2;
                SET BlockRemark = '註冊IP重複';
            END IF;
        END IF;

        IF PhoneBlock = 1 AND BlockType = 0 THEN
            SELECT pm.member_id INTO BlockMemberId
            FROM prize_members AS pm
                     JOIN members AS m ON m.id = pm.member_id AND m.mobile = RegisterMobile
            WHERE pm.prize_id = PrizeId AND pm.event_detail_id = EventDetailId AND pm.member_id != p_MemberId
            LIMIT 1;

            IF RegisterMobile IS NULL OR BlockMemberId > 0 THEN
                SET BlockType = 3;
                SET BlockRemark = '手機號碼重複';
            END IF;

            IF TgidBlock = 1 AND BlockType = 0 THEN
                SELECT tg_user_id INTO TgUserId FROM member_telegram_rels WHERE member_id = MemberId LIMIT 1;

                IF TgUserId > 0 THEN
                    SELECT pm.member_id INTO BlockMemberId
                    FROM prize_members AS pm
                             JOIN member_telegram_rels AS mtr ON mtr.member_id = pm.member_id AND mtr.tg_user_id = TgUserId
                    WHERE pm.prize_id = PrizeId AND pm.event_detail_id = EventDetailId AND pm.member_id != MemberId
                    LIMIT 1;

                    IF BlockMemberId > 0 THEN
                        SET BlockType = 6;
                        SET BlockRemark = 'tg_user_id重複';
                    END IF;
                END IF;
            END IF;
        END IF;

        IF BlockType > 0 THEN
            # 把沒派發的原因寫入table，直接離開
            INSERT INTO event_payout_cancels (event_id, prize_content_id, order_id, from_member_id, member_id, `type`, block_member_id)
                VALUE (p_EventID, PayoutPrizeContentId, p_OrderId, p_MemberId, p_MemberId, BlockType, BlockMemberId);
            LEAVE main_block;
        END IF;

        # 設定派獎的到期日期
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

        IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = p_MemberId) THEN
            # event_processes的進度條加1
            UPDATE event_processes SET `point` = (`point` + 1) WHERE detail_id = EventDetailId AND member_id = p_MemberId;
        ELSE
            INSERT INTO event_processes(detail_id, member_id, `point`, `status`, `admin`)
            VALUES(EventDetailId, p_MemberId, 1, 0, OperatorId);
        END IF;

        # 寫入領獎紀錄
        INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_number, lottery_point, `status`, `admin`)
        VALUES(p_MemberId, PrizeId, PayoutPrizeContentId, EventDetailId, ExpireTime, p_OrderId, AccumulatedAmount, 0, OperatorId);

    END main_block;
END
