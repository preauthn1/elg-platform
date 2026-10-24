CREATE PROCEDURE `SP_payout_weekly_rescue`(IN p_PayoutTime DATETIME)
BEGIN
	# 每周救援金
    # 2025/06/27 Neil 		ADD
    
    # 設定
    DECLARE PayoutTime DATETIME DEFAULT IFNULL(p_PayoutTime, NOW());    
    DECLARE OperatorId INT DEFAULT 51;
    # CURSOR
    DECLARE EventId, EventDetailId, PrizeId, PrizeContentId, PlatformAgentId, EventDetailVendorId, EventDetailPoint, ExpireHour INT DEFAULT 0;
    DECLARE TodayTime, SummaryStartTime, SummaryEndTime DATETIME DEFAULT NOW();
    DECLARE ExpireTime DATETIME;
    DECLARE DoneEvent INT DEFAULT 0;
    DECLARE MemberId INT DEFAULT 0;
    DECLARE LosePoint DECIMAL(18, 4) DEFAULT '0.0000';
    DECLARE GeneralHierarchyPath VARCHAR(255);

    # 獲取當前活動
    DECLARE CurEvent CURSOR FOR
        SELECT e.id, ed.id, p.id, e.agent_id, ed.vendor_id, ed.`point`, ed.expire_hour
        FROM event_details AS ed
		JOIN `events` AS e ON e.id = ed.event_id AND e.`status` = 1
		JOIN prizes AS p ON p.event_id = e.id AND p.`status` = 1
        WHERE ed.action_id = 17 AND ed.status = 1 AND ed.start_time <= PayoutTime AND ed.end_time >= PayoutTime;
	
    # 計算派發獎金(無限制遊戲商及遊戲類別)
    DECLARE CurSummaryUnlimit CURSOR FOR
		SELECT l.member_id as member_id, sum(win_lose * -1) as lose
		FROM summary_bet_records AS l
        JOIN members AS m ON m.id = l.member_id
        JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path like CONCAT(GeneralHierarchyPath, '%')
		WHERE l.summary_time >= SummaryStartTime and summary_time < SummaryEndTime
		GROUP BY l.member_id
		HAVING lose >= EventDetailPoint;
	
    # 計算派發獎金(限制遊戲商及遊戲類別)
	DECLARE CurSummaryLimit CURSOR FOR
		SELECT l.member_id as member_id, sum(win_lose * -1) as lose
		FROM summary_bet_records AS l
        JOIN members AS m ON m.id = l.member_id
        JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path like CONCAT(GeneralHierarchyPath, '%')
        JOIN games AS g ON g.id = l.game_id
        JOIN event_weekly_rescue_vendors as ewrv ON ewrv.event_id = EventId AND ewrv.vendor_id = l.vendor_id AND ewrv.game_type_id = g.type_id
		WHERE l.summary_time >= SummaryStartTime and summary_time < SummaryEndTime
		GROUP BY l.member_id
		HAVING lose >= EventDetailPoint;
          
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_payout_weekly_rescue(',
                    IFNULL(p_PayoutTime, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET DoneEvent = 1;
    
    OPEN CurEvent;
    event_loop : REPEAT
        FETCH CurEvent
            INTO EventId, EventDetailId, PrizeId, PlatformAgentId, EventDetailVendorId, EventDetailPoint, ExpireHour;
        IF DoneEvent = 1 THEN
            LEAVE event_loop;
        END IF;
        
        SET TodayTime = Date(DATE_ADD(PayoutTime, INTERVAL 8 HOUR)); # 現在時間 UTC+8
		SET SummaryEndTime = DATE_SUB(
			DATE_SUB(CONCAT(Date(TodayTime), ' 23:59:59'), INTERVAL (WEEKDAY(Date(DATE_ADD(NOW(), INTERVAL 8 HOUR))) + 1) DAY), 
			INTERVAL 8 HOUR
		); # 取得上週日的23:59:59 UTC+0
		SET SummaryStartTime = DATE_ADD(DATE_ADD(SummaryEndTime, INTERVAL 1 SECOND), INTERVAL -7 DAY);
        
        SELECT id INTO PrizeContentId FROM prize_contents where prize_id = PrizeId AND status = 1 LIMIT 1;
        SELECT hierarchy_path INTO GeneralHierarchyPath FROM agents WHERE id = PlatformAgentId;
        
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
        
        IF EventDetailVendorId = 0 THEN
			BEGIN
            DECLARE DoneSummary INT DEFAULT 0;
            
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET DoneSummary = 1;
            
			OPEN CurSummaryUnlimit;
            summary_unlimit_loop : REPEAT
				FETCH CurSummaryUnlimit INTO MemberId, LosePoint;
				IF DoneSummary = 1 THEN
					LEAVE summary_unlimit_loop;
				END IF;
                
                # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
				IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
					UPDATE event_processes
					SET `point` = (`point` + EventDetailPoint) WHERE detail_id = EventDetailId AND member_id = MemberId;
				ELSE
					INSERT INTO event_processes(detail_id, member_id, `point`, received, `status`, `admin`)
					VALUES(EventDetailId, MemberId, EventDetailPoint, 0, 2, OperatorId);
				END IF;
				# 寫入領獎紀錄
				INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_point, `status`, `admin`)
				VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, LosePoint, 0, OperatorId);
				
			UNTIL DoneSummary END REPEAT;
            CLOSE CurSummaryUnlimit;
			END;
		ELSE 
			BEGIN
            DECLARE DoneSummary INT DEFAULT 0;
            
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET DoneSummary = 1;
            
			OPEN CurSummaryLimit;
            summary_limit_loop : REPEAT
				FETCH CurSummaryLimit INTO MemberId, LosePoint;
				IF DoneSummary = 1 THEN
					LEAVE summary_limit_loop;
				END IF;
                
                # 寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
				IF EXISTS (SELECT * FROM event_processes WHERE detail_id = EventDetailId AND member_id = MemberId) THEN
					UPDATE event_processes
					SET `point` = (`point` + EventDetailPoint) WHERE detail_id = EventDetailId AND member_id = MemberId;
				ELSE
					INSERT INTO event_processes(detail_id, member_id, `point`, received, `status`, `admin`)
					VALUES(EventDetailId, MemberId, EventDetailPoint, 0, 2, OperatorId);
				END IF;
				# 寫入領獎紀錄
				INSERT INTO prize_members(member_id, prize_id, content_id, event_detail_id, expired_time, lottery_point, `status`, `admin`)
				VALUES(MemberId, PrizeId, PrizeContentId, EventDetailId, ExpireTime, LosePoint, 0, OperatorId);
				
			UNTIL DoneSummary END REPEAT;
            CLOSE CurSummaryLimit;
			END;
        END IF;
    UNTIL DoneEvent END REPEAT;
    CLOSE CurEvent;
END