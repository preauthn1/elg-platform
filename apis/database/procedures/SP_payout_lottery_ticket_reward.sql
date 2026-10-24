CREATE PROCEDURE `SP_payout_lottery_ticket_reward`(
    IN p_lucky_draws_id INT,
    OUT p_tg_message VARCHAR(500)
)
BEGIN

    # 派發當期中獎抽獎券
    # 2020/11/02 Mars 		新增
    # 2022/09/23 Mars 		增加寫入prize_members event_detail_id, expired_time

    DECLARE Done, RowCount, ReturnValue INT DEFAULT 0;
    DECLARE EventDetailId, RewardItemId, RewardItemType, RewardItemLevel, PrizeId, PrizeContentID INT DEFAULT 0;
    DECLARE LuckyDrawNumber, L_no, R_no VARCHAR(8);
    DECLARE StartTime, EndTime, LuckyDrawTime, ExpireDate DATETIME DEFAULT NOW();
    DECLARE RewardItemBonus DECIMAL(16, 2) DEFAULT 0;
    DECLARE RewardItemName, DrawName, ErrorMessage VARCHAR(50) DEFAULT '';
    DECLARE AutoPayout INT DEFAULT 0;

    # 取出抽獎獎號
    DECLARE cur CURSOR FOR
        SELECT r.id
             , r.level
             , r.type AS reward_type
             , TRUNCATE(r.bonus, 2) AS bonus
             , r.prize_name AS prize_name
             , CASE WHEN r.level = 1 THEN l.draw_number
                    WHEN r.level = 2 THEN CONCAT(SUBSTRING_INDEX(l.draw_number, '-', 1), '%')
                    ELSE CONCAT(LEFT(SUBSTRING_INDEX(l.draw_number, '-', 1), (8 - r.level)), '%') END AS l_no
             , CASE WHEN r.level > 2 THEN CONCAT('%', RIGHT(SUBSTRING_INDEX(l.draw_number, '-', 1), (8 - r.level)), '-%') ELSE '' END AS r_no
             , p.id AS prize_id
             , r.name AS draw_name
             , pc.id AS prize_content_id
             , ed.id AS event_detail_id
             , CASE WHEN ed.expire_hour > 0 THEN DATE_ADD(NOW(), INTERVAL ed.expire_hour HOUR) ELSE p.expire_date END AS expire_date
        FROM lucky_draws AS l
                 JOIN event_details AS ed ON ed.id = l.event_detail_id
                 JOIN prizes as p ON p.event_id = l.event_id
                 JOIN prize_contents AS pc ON pc.prize_id = p.id AND pc.status = 1
                 JOIN reward_items AS r ON r.prize_content_id = pc.id AND r.status = 1
        WHERE l.id = p_lucky_draws_id AND l.status = 0 AND l.draw_number IS NOT NULL
        ORDER BY r.level;

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
            # SELECT RewardItemLevel, RewardItemBonus, L_no, R_no, PrizeId, DrawName, PrizeContentID;
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
                member_id, prize_id, content_id, event_detail_id, expired_time,
                lottery_number, status, admin
            )
            SELECT ldt.member_id, PrizeId, PrizeContentID, EventDetailId, ExpireDate, ldt.id, 0, 0
            FROM lucky_draw_tickets as ldt
            WHERE ldt.lucky_draw_id = p_lucky_draws_id
              AND ldt.reward_item_id = RewardItemId
            # 排除重複派發
            #AND NOT EXISTS (SELECT * FROM prize_members AS pm WHERE pm.prize_id = PrizeId AND pm.lottery_number = ldt.id)
            ;

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

    # 查詢events是不是自動派獎
    SELECT e.auto_payout
    INTO AutoPayout
    FROM lucky_draws AS l
             JOIN events AS e ON e.id = l.event_id
    WHERE l.id = p_lucky_draws_id;

    IF AutoPayout = 1 THEN
        # 是自動派獎的話call SP派獎
        CALL SP_payout_lottery_ticket_reward_auto(p_lucky_draws_id);
    END IF;

    # 將該期修改為已派發
    UPDATE lucky_draws SET status = 1 WHERE id = p_lucky_draws_id;

    SET EndTime = NOW();
END
