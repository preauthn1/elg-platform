CREATE PROCEDURE `SP_payout_vip_level_rewards`(IN p_Today DATETIME, IN p_Period INT, IN p_PayoutLogId INT)
sp_main: BEGIN
    # 派發VIP等級獎金
    # 2026/08/03 	Neil 		新增

    DECLARE Done INT DEFAULT 0;
    DECLARE Today DATE DEFAULT NOW();
    DECLARE PeriodStart, PeriodEnd DATETIME;
    DECLARE AdminUser INT DEFAULT 51; # 系統
    DECLARE RewardId INT DEFAULT 0;
    DECLARE MemberId, AgentId, VipContentId, PointType INT DEFAULT 0;
    DECLARE RewardPoint DECIMAL(18, 4) DEFAULT '0.0000';
    DECLARE ExpiredTime DateTime;
    DECLARE OldVipContentId INT DEFAULT 0;
    DECLARE OldRewardPoint DECIMAL(18, 4) DEFAULT '0.0000';
    DECLARE OldRewardStatus INT DEFAULT 0;
    DECLARE WalletTypeId INT DEFAULT 1002; # 禮碼錢包
    DECLARE TransactionType INT DEFAULT 32; # transaction_logs 交易類型：VIP等級獎勵
    DECLARE RollingChipType INT DEFAULT 19; # rolling_chip_logs 交易類型：VIP等級獎勵
    DECLARE TransactionNo VARCHAR(191);
    DECLARE BeforeBalance DECIMAL(18, 4) DEFAULT 0;
    DECLARE BeforeRollingChip DECIMAL(18, 4) DEFAULT 0;

    # 取出開獎期數
    DECLARE cur CURSOR FOR
    SELECT mv.member_id, vcr.id, vcr.point_type, vcr.`point`,
				CASE vcr.expired_hour WHEN 0 THEN NULL ELSE DATE_ADD(NOW(), INTERVAL vcr.expired_hour HOUR) END
		FROM member_vips as mv
		JOIN vip_content_rewards as vcr on vcr.vip_content_id = mv.vip_content_id AND vip_reward_id = RewardId;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    SET AdminUser = 0;
    SET RewardId = 1; # 等級獎金
    SET Today = IFNULL(p_Today, DATE_ADD(NOW(), INTERVAL 8 HOUR));

    IF p_Period = 1 THEN
		# 週期1，1/1檢查，所以要檢查有無派發的時間點是1/1 0點~ 4/1 0點
		SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today), '-01-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today), '-04-01'), INTERVAL -8 HOUR);
	ELSEIF p_Period = 2 THEN
		# 週期2，4/1檢查，所以要檢查有無派發的時間點是4/1 0點~ 7/1 0點
		SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today), '-04-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today), '-07-01'), INTERVAL -8 HOUR);
	ELSEIF p_Period = 3 THEN
		# 週期3，7/1檢查，所以要檢查有無派發的時間點是7/1 0點~ 10/1 0點
		SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today), '-07-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today), '-10-01'), INTERVAL -8 HOUR);
	ELSEIF p_Period = 4 THEN
		# 週期4，10/1檢查，所以要檢查有無派發的時間點是10/1 0點~ 明年 1/1 0點
		SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today), '-10-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today) + 1, '-01-01'), INTERVAL -8 HOUR);
	ELSE
		LEAVE sp_main;
    END IF;

	# 已跑完降級，開始準備派發等級獎金
	UPDATE vip_reward_payout_logs SET `status` = 4 WHERE id = p_PayoutLogId;

    OPEN cur;
    cursor_loop : REPEAT
        FETCH cur INTO MemberId, VipContentId, PointType, RewardPoint, ExpiredTime;

        IF Done = 1 THEN
            LEAVE cursor_loop;
        END IF;

        SET OldVipContentId = 0;
        SET OldRewardPoint = '0.0000';

        #檢查這個季度該會員是否已派發過等級獎金（獨立區塊，避免 NOT FOUND 影響外層 cursor 的 Done）
        CheckBlock: BEGIN
            DECLARE p_NotFound INT DEFAULT 0;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_NotFound = 1;

            SELECT mvp.vip_content_reward_id, mvp.`point`, mvp.`status` INTO OldVipContentId, OldRewardPoint, OldRewardStatus
            FROM member_vip_prizes AS mvp
            JOIN vip_content_rewards AS vcr ON vcr.id = mvp.vip_content_reward_id AND vcr.vip_reward_id = RewardId
            WHERE mvp.member_id = MemberId AND mvp.created_at >= PeriodStart AND mvp.created_at < PeriodEnd
            ORDER BY mvp.created_at DESC
            LIMIT 1;
        END CheckBlock;

        IF OldVipContentId != 0 THEN
			# 有派發過獎金，且當時派發的金額小於這次派發的金額，就補上少的金額
			IF RewardPoint > OldRewardPoint THEN
				SET RewardPoint = RewardPoint - OldRewardPoint;
                IF PointType = 1 THEN
					SET TransactionNo = CONCAT(DATE_FORMAT(NOW(), '%Y%m%d%H%i%S'),RIGHT(CONCAT('000', FLOOR(RAND()*100)), 4));

					START TRANSACTION;
					# 鎖定該會員餘額，避免併發時讀到舊值造成洗分金額被覆蓋
					SELECT parent, balance INTO AgentId, BeforeBalance FROM members WHERE id = MemberId FOR UPDATE;
					# 寫入獎勵
					UPDATE members SET balance = (balance + RewardPoint) WHERE id = MemberId;
					# 寫入transaction_logs
					INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
						, type, point, vendor_point, rate, before_balance, balance, vendor_balance, status, remark, result, operator, platform, admin)
					VALUES(TransactionNo, AgentId, MemberId, 0, 0, TransactionType, RewardPoint, 0, 1, BeforeBalance, (BeforeBalance + RewardPoint), 0
                      , 1000, 'VIP等級獎勵補上缺少金額', 0, 'system', 0, 51);
					COMMIT;
				ELSEIF PointType = 2 THEN
					START TRANSACTION;
					# 取玩家禮碼餘額，鎖定該筆錢包，避免併發時讀到舊值造成禮碼金額被覆蓋
					IF EXISTS (SELECT * FROM member_wallets WHERE member_id = MemberId AND wallet_type_id = WalletTypeId FOR UPDATE) THEN
						SELECT balance INTO BeforeRollingChip
						FROM member_wallets
						WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;

						# 更新玩家禮碼餘額
						UPDATE member_wallets SET balance = (BeforeRollingChip + RewardPoint)
						WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;
					ELSE
						SET BeforeRollingChip = 0;
						# 創建玩家禮碼錢包
						INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
						VALUES(MemberId, WalletTypeId, RewardPoint, 1, 0);
					END IF;

                    # 取得parent
                    SELECT parent INTO AgentId FROM members WHERE id = MemberId;

					# 寫入rolling_chip_logs
					INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance, balance, `status`, remark, `admin`)
					VALUES(AgentId, MemberId, 0, RollingChipType, RewardPoint, BeforeRollingChip, (BeforeRollingChip + RewardPoint), 1000, 'VIP等級獎勵補上缺少金額', 51);
					COMMIT;
				END IF;
			END IF;
		ELSE
			# 如果這期沒有派發的紀錄，就直接派獎
            INSERT INTO member_vip_prizes(member_id, vip_content_reward_id, point_type, `point`, expired_time,`status`, `admin`)
            VALUE (MemberId, VipContentId, PointType, RewardPoint, ExpiredTime, 0, AdminUser);
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;

    # 派發完畢，更新派發紀錄
	UPDATE vip_reward_payout_logs SET `status` = 5 WHERE id = p_PayoutLogId;
END sp_main