CREATE PROCEDURE `SP_payout_refresh_ranking_sub`(
	IN p_event_id INT,
	IN p_refresh_ranking_id INT,
    IN p_event_detail_id INT,
    IN p_prize_id INT
)
BEGIN

	# 派發老虎機爭霸戰獎項
    # 2024/08/30 Neil 		新增
    
	DECLARE Done INT DEFAULT 0;
    DECLARE EventRefreshRankingRecordId, AgentId, MemberId, PrizeContentId, PointType INT(11);
    DECLARE TransactionLogType INT DEFAULT 13; #統一成活動獎勵
    DECLARE RollingChipLogType INT DEFAULT 21; #刷新排行榜
    DECLARE RewardPoint DECIMAL(18, 4);
    DECLARE BeforeRollingChip DECIMAL(18, 4);
    DECLARE BeforeBalance DECIMAL(18, 4) DEFAULT 0;
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE TransactionNo VARCHAR(30) DEFAULT '0';
    DECLARE FlowLimitMultiple INT DEFAULT (SELECT flow_limit_multiple FROM prizes WHERE id = p_prize_id);
    DECLARE ReferenceId INT DEFAULT 0;
    DECLARE EventTitle VARCHAR(191) DEFAULT (SELECT title FROM events WHERE id = p_event_id);

	# 取出未發獎勵
	DECLARE cur CURSOR FOR 
    SELECT e.id, m.parent, e.member_id, pc.id, pc.point_type, pc.point
	FROM event_refresh_ranking_records AS e
    JOIN prize_contents AS pc ON pc.id = e.prize_content_id
    JOIN members AS m ON m.id = e.member_id
	WHERE e.event_refresh_ranking_id = p_refresh_ranking_id AND e.type = 1 AND e.status = 0;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    
	OPEN cur;
    REPEAT
		FETCH cur 
        INTO EventRefreshRankingRecordId, AgentId, MemberId, PrizeContentId, PointType, RewardPoint;
		IF NOT Done THEN
            IF PointType = 1 THEN
				SET TransactionNo = CONCAT(DATE_FORMAT(NOW(), '%Y%m%d%H%i%S'),RIGHT(CONCAT('000', FLOOR(RAND()*100)), 4));
                SELECT balance INTO BeforeBalance FROM members WHERE id = MemberId;
				# 寫入獎勵
				UPDATE members SET balance = (balance + RewardPoint) WHERE id = MemberId;
				# 寫入transaction_logs
				INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
					, type, point, vendor_point, rate, before_balance, balance, vendor_balance, status, remark, result, operator, platform, admin)
				VALUES(TransactionNo, AgentId, MemberId, 0, 0, TransactionLogType, RewardPoint, 0, 1, BeforeBalance, (BeforeBalance + RewardPoint), 0
					, 1000, JSON_OBJECT('event_id', p_event_id, 'title', EventTitle, 'action_id', 13, 'point', 0), 0, 'system', 0, 51);
				SELECT LAST_INSERT_ID() INTO ReferenceId;
			ELSEIF PointType = 2 THEN
				# 取玩家禮碼餘額
				SELECT mw.balance INTO BeforeRollingChip 
				FROM members AS m
                LEFT JOIN member_wallets AS mw ON m.id = mw.member_id AND mw.wallet_type_id = WalletTypeId
				WHERE m.id =  MemberId;
                    
				IF BeforeRollingChip IS NOT NULL THEN
					# 更新玩家禮碼餘額
					UPDATE member_wallets SET balance = (balance + RewardPoint) 
					WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;
				ELSE
					SET BeforeRollingChip = 0;
					# 創建玩家禮碼錢包
					INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
					VALUES(MemberId, WalletTypeId, RewardPoint, 1, 0);
				END IF;	
				# 寫入rolling_chip_logs
				INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance, balance, `status`, remark, `admin`)
				VALUES(AgentId, MemberId, 0, RollingChipLogType, RewardPoint, BeforeRollingChip, (BeforeRollingChip + RewardPoint), 1000, '', 51);
                SELECT LAST_INSERT_ID() INTO ReferenceId;
			END IF;
            
			# 寫進prize_members
			INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, lottery_point, status, admin)
			VALUE(MemberId, p_prize_id, PrizeContentId, p_event_detail_id, RewardPoint, 1, 51);
            
            # 有設定稽核流水倍數，就新增稽核流水log
            IF FlowLimitMultiple > 0 THEN
				INSERT INTO flow_limits(member_id, type, event_id, reference_id, flow_limit, reach, admin)
                VALUES(MemberId, TransactionLogType, p_event_id, ReferenceId, (RewardPoint * FlowLimitMultiple), 0, 0);
			END IF;
            
            # 把紀錄改成已領獎
			UPDATE event_refresh_ranking_records SET status = 1, time = NOW() WHERE id = EventRefreshRankingRecordId;
		END IF;
	UNTIL Done END REPEAT;
    CLOSE cur;
END