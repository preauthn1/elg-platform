CREATE PROCEDURE `SP_set_w_jackpot_ticket`(
    IN p_event_id INT,
    IN p_w_jackpot_id INT,
    IN p_member_id INT,
    IN p_ticket_number INT
)
BEGIN
    # RD-studio-01 summary_event 呼叫, 派發抽獎券
    # 2022-02-14 	Mars 		W Jackpot抽獎券派發SP
    # 2022-03-14 	Mars 		將樣本抽獎券分為單/雙週, 每週重新派發

    DECLARE JackpotNo INT DEFAULT 0;
    DECLARE WeeksOfUse INT DEFAULT 0;

    SELECT no INTO JackpotNo FROM w_jackpots WHERE id = p_w_jackpot_id;
    SET WeeksOfUse = (2 - MOD(JackpotNo, 2));

    # 寫入w_jackpot_tickets
    INSERT INTO w_jackpot_tickets(w_jackpot_id, member_id, ticket_number, admin)
    SELECT p_w_jackpot_id, p_member_id, ticket_number, 0
    FROM temp_four_lottery_tickets
    WHERE event_id = p_event_id
      AND weeks_of_use = WeeksOfUse
    ORDER BY status, sort
    LIMIT p_ticket_number;

    # 更新temp_four_lottery_tickets狀態, 並重新rand() sort
    UPDATE temp_four_lottery_tickets
    SET sort = FLOOR(RAND() * 10000000), status = (status + 1)
    WHERE event_id = p_event_id
      AND weeks_of_use = WeeksOfUse
    ORDER BY status, sort
    LIMIT p_ticket_number;

END
