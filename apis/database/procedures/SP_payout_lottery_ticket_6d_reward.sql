CREATE PROCEDURE `SP_payout_lottery_ticket_6d_reward`(
    IN p_lucky_draws_id INT,
    OUT p_error_code INT,
    OUT p_tg_message VARCHAR(500)
)
BEGIN

    # 派發當期6D中獎抽獎券
    # 2022/09/14 Mars 		新增

    DECLARE Done, RowCount, ReturnValue INT DEFAULT 0;
    DECLARE EventDetailId, RewardItemId, RewardItemType, RewardItemLevel, PrizeId, PrizeContentID, EventId, AutoPayout INT DEFAULT 0;
    DECLARE LuckyDrawNumber, L_no, R_no VARCHAR(8);
    DECLARE StartTime, EndTime, LuckyDrawTime, ExpireDate DATETIME DEFAULT NOW();
    DECLARE RewardItemBonus DECIMAL(16, 2) DEFAULT 0;
    DECLARE RewardItemName, DrawName, ErrorMessage VARCHAR(50) DEFAULT '';

    # 取出抽獎獎號
    DECLARE cur CURSOR FOR
        SELECT ri.id
             , pc.level
             , ri.type AS reward_type
             , TRUNCATE(ri.bonus, 2) AS bonus
             , ri.prize_name AS prize_name
             , CASE WHEN pc.level = 1 THEN ld.draw_number
                    ELSE CONCAT(LEFT(ld.draw_number, (7 - pc.level)), '%') END AS l_no
             , CASE WHEN pc.level > 1 THEN CONCAT('%', RIGHT(ld.draw_number, (7 - pc.level))) ELSE '' END AS r_no
             , p.id AS prize_id
             , ri.name AS draw_name
             , pc.id AS prize_content_id
             , ed.id AS event_detail_id
             , CASE WHEN ed.expire_hour > 0 THEN DATE_ADD(NOW(), INTERVAL ed.expire_hour HOUR) ELSE p.expire_date END AS expire_date
        FROM lucky_draws AS ld
                 JOIN event_details AS ed ON ed.id = ld.event_detail_id
                 JOIN prizes as p ON p.event_id = ld.event_id and p.type_id = 7
                 JOIN prize_contents AS pc ON pc.prize_id = p.id AND pc.status = 1
                 JOIN reward_items AS ri ON ri.prize_content_id = pc.id AND ri.status = 1
        WHERE ld.id = p_lucky_draws_id AND ld.status = 0
          AND ld.draw_number IS NOT NULL
        ORDER BY pc.level;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    OPEN cur;

    SELECT DATE_ADD(draw_time, INTERVAL 8 HOUR)
         , draw_number
    INTO LuckyDrawTime, LuckyDrawNumber
    FROM lucky_draws
    WHERE id = p_lucky_draws_id;

    SET p_tg_message = CONCAT('[抽獎券開奬日期 ', DATE(LuckyDrawTime), ']');
    SET p_tg_message = CONCAT(p_tg_message, '-----------------------');
    SET p_tg_message = CONCAT(p_tg_message, '開奬號碼:', LuckyDrawNumber, '');

    REPEAT
        FETCH cur
            INTO RewardItemId, RewardItemLevel, RewardItemType, RewardItemBonus, RewardItemName, L_no, R_no
                , PrizeId, DrawName, PrizeContentID, EventDetailId, ExpireDate;
        IF NOT Done THEN
            UPDATE lucky_draws SET status = 2 WHERE id = p_lucky_draws_id;

            # 中獎名單
            UPDATE lucky_draw_tickets
            SET reward_item_id = RewardItemId, point = RewardItemBonus, type = 1
            WHERE lucky_draw_id = p_lucky_draws_id
              AND reward_item_id = 0
              AND (ticket_number LIKE L_no OR ticket_number LIKE R_no);

            # update 影響筆數
            SELECT row_count() INTO RowCount;

            # 更新獎項內容派發數量
            UPDATE prize_contents SET sent = sent + RowCount WHERE id = PrizeContentID;

            INSERT INTO prize_members(
                member_id, prize_id, content_id, event_detail_id, expired_time, lottery_number, status, admin
            )
            SELECT ldt.member_id, PrizeId, PrizeContentID, EventDetailId, ExpireDate, ldt.id, 0, 0
            FROM lucky_draw_tickets as ldt
            WHERE ldt.lucky_draw_id = p_lucky_draws_id
              AND ldt.reward_item_id = RewardItemId;

            # update 影響筆數
            SELECT row_count() INTO RowCount;
            IF RewardItemType = 2 THEN
                SET p_tg_message = CONCAT(p_tg_message, DrawName, '|', RewardItemName, '|', RowCount, '');
            ELSE
                SET p_tg_message = CONCAT(p_tg_message, DrawName, '|', RewardItemBonus, '|', RowCount, '');
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;

    SELECT e.auto_payout
    INTO AutoPayout
    FROM lucky_draws AS l
             JOIN events AS e ON e.id = l.event_id
    WHERE l.id = p_lucky_draws_id;

    IF AutoPayout = 1 THEN
        CALL SP_payout_lottery_ticket_reward_auto(p_lucky_draws_id);
    END IF;

    # 將該期修改為已派發
    UPDATE lucky_draws SET status = 1 WHERE id = p_lucky_draws_id;
    SET p_error_code = 0;
    SET EndTime = NOW();
END
