CREATE PROCEDURE `SP_set_lucky_ticket_6d`(
    IN p_lucky_draw_id INT,
    IN p_member_id INT,
    IN p_ticket_number INT
)
BEGIN

    # 派發6D抽獎券
    # 2022-09-14 Mars 			ADD
    # 2022-10-05 Mars 			優化派發方式

    DECLARE Now DATETIME DEFAULT NOW();
    DECLARE EventId, WeeksOfUse, NotUsedTicket, InsertTicketNumber INT DEFAULT 0;

    SELECT event_id, (2 - MOD(IFNULL(no, 0), 2))
    INTO EventId, WeeksOfUse
    FROM lucky_draws
    WHERE id = p_lucky_draw_id;

    # 撈出還剩幾張空的抽獎券
    SELECT COUNT(*)
    INTO NotUsedTicket
    FROM lucky_draw_tickets
    WHERE lucky_draw_id = p_lucky_draw_id
      AND member_id = 0;

    # 如可派發抽獎券少於當前數, 就補500張
    IF NotUsedTicket < p_ticket_number THEN
        SET InsertTicketNumber = p_ticket_number + 500;

        # 寫入空的抽獎券
        INSERT INTO lucky_draw_tickets(lucky_draw_id, member_id, ticket_number, `admin`)
        SELECT p_lucky_draw_id, 0, ticket_number, 0
        FROM temp_lottery_tickets
        WHERE event_id = EventId
          AND weeks_of_use = WeeksOfUse
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
    SET member_id = p_member_id
    WHERE lucky_draw_id = p_lucky_draw_id
      AND member_id = 0
    LIMIT p_ticket_number;
END
