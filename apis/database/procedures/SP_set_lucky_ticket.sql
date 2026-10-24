CREATE PROCEDURE `SP_set_lucky_ticket`(
	IN p_lucky_draw_id INT,
    IN p_member_id INT,
    IN p_ticket_number INT
)
BEGIN
	
	# 2020-09-03 Mars 			抽獎券派發SP 
    # 2022-03-29 Mars 			修改派發方式
    # 2022-10-05 Mars 			優化派發方式
    # 2024-07-28 Mars 			排除重複單號
    # 2024-12-17 Mars 			超出一百萬張時, 可重覆運行
    
    DECLARE Now DATETIME DEFAULT NOW();
    DECLARE EventId, WeeksOfUse, TicketCount, NotUsedTicket, InsertTicketNumber INT DEFAULT 0;
    DECLARE LuckyDrawId, MemberId, TicketNumber INT DEFAULT 0;
    
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_set_lucky_ticket(',
                IFNULL(p_lucky_draw_id, 'NULL'), ',',
                IFNULL(p_member_id, 'NULL'), ',',
                IFNULL(p_ticket_number, 'NULL'),
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    SET LuckyDrawId = p_lucky_draw_id;
    SET MemberId = p_member_id;
    SET TicketNumber = p_ticket_number;
    
    ## 黑名單排除 改成0, 833904只有在VW有, 885733 VW/TP是不同會員
    IF MemberId = 0 THEN 
		SET TicketNumber = 0;
	END IF;
    
    SELECT event_id, (2 - MOD(IFNULL(no, 0), 2)) 
    INTO EventId, WeeksOfUse
    FROM lucky_draws 
    WHERE id = LuckyDrawId;
    
    # 撈出還剩幾張空的抽獎券
    SELECT COUNT(*) AS cnt
		, IFNULL(SUM(CASE WHEN member_id = 0 THEN 1 ELSE 0 END), 0) AS not_used_ticket
    INTO TicketCount, NotUsedTicket
	FROM lucky_draw_tickets
	WHERE lucky_draw_id = LuckyDrawId;
    
    # 如可派發抽獎券少於當前數, 就補5000張
    IF NotUsedTicket < TicketNumber THEN
		SET InsertTicketNumber = TicketNumber + 5000;
        
        # 寫入空的抽獎券
		INSERT INTO lucky_draw_tickets(lucky_draw_id, member_id, ticket_number, `admin`)
		SELECT LuckyDrawId, 0, CONCAT(ticket_number, '-', FLOOR(RAND()*(10))), 0
		FROM temp_lottery_tickets 
		WHERE event_id = EventId
		AND weeks_of_use = WeeksOfUse
        AND ticket_number NOT IN (
			SELECT z.l_number
            FROM (
				SELECT y.l_number, COUNT(*) AS cnt
				FROM (
					SELECT id
						, ticket_number
						, SUBSTR(ticket_number, 1, 6) AS l_number
						, SUBSTR(ticket_number, 8, 1) as r_number
					FROM lucky_draw_tickets 
					WHERE lucky_draw_id = LuckyDrawId 
				) AS y 
				GROUP BY y.l_number
			) AS z
            #WHERE z.cnt > 0 
            #WHERE z.cnt > (FLOOR((TicketCount + 1) / 1000000) + 1)
            WHERE z.cnt > FLOOR(TicketCount / 1000000)
		) 
		ORDER BY status ASC, sort ASC
		LIMIT InsertTicketNumber;
        
		# 更新temp_lottery_tickets狀態, 並重新rand() sort
		UPDATE temp_lottery_tickets 
		SET sort = FLOOR(RAND() * 10000000), status = (status + 1) 
		WHERE event_id = EventId
		AND weeks_of_use = WeeksOfUse
		ORDER BY status ASC, sort ASC
		LIMIT InsertTicketNumber;
    END IF;
    
    UPDATE lucky_draw_tickets
    SET member_id = MemberId
	WHERE lucky_draw_id = LuckyDrawId 
    AND member_id = 0
    LIMIT TicketNumber;
END