CREATE PROCEDURE `SP_payout_vip_other_reward`(IN p_PayoutTime DATETIME)
BEGIN

	# 派發其他VIP獎項(因為要等三層代結算完畢或者關帳)
    # 2023/05/10 	Neil 		新增
    # 2025/11/17    Neil        每周額外返水改成每日
    # 2025/12/10    Neil		每周救援金輸分改成總咬分

	DECLARE Done INT DEFAULT 0;
    DECLARE StartTime, EndTime DateTime DEFAULT NOW();
    DECLARE Today Date DEFAULT DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR));
    DECLARE RebateRewardId INT DEFAULT 7; # 每日額外返水
    DECLARE RescueRewardId INT DEFAULT 8; # 每周救援金
    DECLARE AdminUser INT DEFAULT 51; #系統
    DECLARE RewardId, PayoutLogId INT DEFAULT 0;
     DECLARE TodayWeek INT DEFAULT 0;
    
	# 取出開獎期數
    DECLARE cur CURSOR FOR 
    SELECT id
	FROM vip_rewards
	WHERE id in (7,8) AND status = 1;
	
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    
    SET AdminUser = 0;
    SET Today = IFNULL(p_PayoutTime, DATE_ADD(NOW(), INTERVAL 8 HOUR)); # 現在時間 UTC+8
    SET TodayWeek = DAYOFWEEK(DATE_ADD(DATE(Today), INTERVAL -8 HOUR)); # 取得今天的星期，用來發每周救援金
    
	OPEN cur;
    cursor_loop : REPEAT
		FETCH cur 
        INTO RewardId;
        
        IF Done = 1 THEN
			LEAVE cursor_loop;
		END IF;
        
		IF RewardId = RebateRewardId THEN
			# 每日額外返水，取前一天的投注紀錄
			SET EndTime = DATE_ADD(DATE(Today), INTERVAL -8 HOUR);
			SET StartTime = DATE_ADD(EndTime, INTERVAL -1 DAY);
			
			# 創建派發紀錄，未派發
			INSERT INTO vip_reward_payout_logs(vip_reward_id, summary_start_time, summary_end_time, status)
			VALUE (RewardId, StartTime, EndTime, 0);
			SELECT LAST_INSERT_ID() INTO PayoutLogId;
        
            INSERT INTO vip_rebate_summary_records(summary_start_time, summary_end_time, member_id, game_type_id, bet, proportion, rebate_amount)
			SELECT StartTime, 
				EndTime,
				z.member_id,
                z.game_type_id,
                SUM(z.bet),
                vrp.proportion,
                SUM(z.bet * vrp.proportion)
			FROM (
				SELECT l.member_id as member_id, sum(bet) as bet, l.game_type_id as game_type_id
				FROM three_hierarchy_bonus_report AS l
				WHERE l.summary_time >= StartTime and summary_time < EndTime and l.member_id = l.from_member_id and rebate_type = 1
				GROUP BY l.member_id, l.game_type_id 
            ) AS z
			JOIN members AS m ON m.id = z.member_id
			JOIN member_vips AS mv ON mv.member_id = m.id
            JOIN vip_content_rewards AS vcr ON mv.vip_content_id = vcr.vip_content_id AND vcr.vip_reward_id = RebateRewardId
			JOIN vip_rebate_proportions AS vrp ON vrp.vip_content_id = mv.vip_content_id AND vrp.game_type_id = z.game_type_id
			GROUP BY z.member_id, z.game_type_id;
            
            INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, base_amount, expired_time, admin)
            SELECT l.member_id,
				vcr.id,
                vcr.point_type,
                SUM(rebate_amount),
                CASE vcr.expired_hour WHEN 0 THEN NULL WHEN -1 THEN DATE_ADD(CONCAT(DATE(Today), ' 23:59:59'), INTERVAL -8 HOUR) ELSE DATE_ADD(NOW(), INTERVAL vcr.expired_hour HOUR) END,
                AdminUser
            FROM vip_rebate_summary_records AS l
            JOIN members AS m ON m.id = l.member_id
            JOIN member_vips AS mv ON mv.member_id = m.id
            JOIN vip_content_rewards AS vcr ON mv.vip_content_id = vcr.vip_content_id AND vcr.vip_reward_id = RebateRewardId
            WHERE l.summary_start_time = StartTime AND l.summary_end_time = EndTime
            GROUP BY l.member_id;
		ELSEIF RewardId = RescueRewardId THEN
			IF TodayWeek != 1 THEN
                # 每周救援金，只有星期一才派獎
                ITERATE cursor_loop;
            END IF;
            
            # 取前一周的投注紀錄
			SET EndTime = DATE_ADD(DATE(Today), INTERVAL -8 HOUR);
			SET StartTime = DATE_ADD(EndTime, INTERVAL -7 DAY);
            
            # 創建派發紀錄，未派發
			INSERT INTO vip_reward_payout_logs(vip_reward_id, summary_start_time, summary_end_time, status)
			VALUE (RewardId, StartTime, EndTime, 0);
			SELECT LAST_INSERT_ID() INTO PayoutLogId;
        
			INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, base_amount, expired_time, admin)
			SELECT z.member_id,
				vcr.id, 
                vcr.point_type, 
                z.bite_point, 
                CASE vcr.expired_hour WHEN 0 THEN NULL WHEN -1 THEN DATE_ADD(CONCAT(DATE(Today), ' 23:59:59'), INTERVAL -8 HOUR) ELSE DATE_ADD(NOW(), INTERVAL vcr.expired_hour HOUR) END, 
                AdminUser
			FROM (
				SELECT T.member_id, SUM(T.bite_point) * -1 AS bite_point
                FROM (
					SELECT l.member_id as member_id, SUM(jackpot + v_jackpot + win_lose) as bite_point
					FROM summary_bet_records AS l
					WHERE l.summary_time >= StartTime and summary_time < EndTime
					GROUP BY l.member_id
					HAVING bite_point < 0
					UNION
					SELECT th.member_id as member_id, sum(rebate + down_rebate + down2_rebate) as bite_point
					FROM three_hierarchy_bonus_report AS th
					WHERE th.created_at >= StartTime AND th.created_at < EndTime
					GROUP BY th.member_id
					UNION
					SELECT tl.member_id as member_id, sum(`point`) as bite_point
					FROM transaction_logs AS tl
					WHERE tl.created_at >= StartTime AND tl.created_at < EndTime 
						AND tl.`type` IN (10, 13, 15, 19, 20, 21, 24, 25, 26, 30, 31, 32, 101, 102, 103)
					GROUP BY tl.member_id
					UNION
					SELECT er.member_id, SUM(`point`)
					FROM summary_event_records as er
					WHERE er.summary_time >= StartTime AND er.summary_time < EndTime AND event_id = 6 # Rolling Chip
					GROUP BY er.member_id
				) AS T
                GROUP BY T.member_id
            ) AS z
			JOIN members AS m ON m.id = z.member_id
			JOIN member_vips AS mv ON mv.member_id = m.id
            JOIN vip_content_rewards AS vcr ON mv.vip_content_id = vcr.vip_content_id AND vcr.vip_reward_id = RescueRewardId
			WHERE z.bite_point >= vcr.`condition`;
		END IF;
        
        # 派發完畢，更新派發紀錄
        UPDATE vip_reward_payout_logs SET `status` = 1 WHERE id = PayoutLogId;
	UNTIL Done END REPEAT;
    CLOSE cur;
END