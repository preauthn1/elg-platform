CREATE PROCEDURE `SP_payout_w_jackpot_ticket_reward`(
    IN p_w_jackpot_id INT,
    OUT p_error_code INT,
    OUT p_tg_message VARCHAR(500)
)
BEGIN
    # 派發w jackpot當期中獎抽獎券
    # 2022/02/23 Mars 		新增
    # 2022-03-14 Mars 		將樣本抽獎券分為單/雙週, 每週重新派發

    DECLARE Done INT DEFAULT 0;
    DECLARE TotalCount, RowCount INT DEFAULT 0;
    DECLARE LuckyDrawTime DateTime;
    DECLARE LuckyDrawNumber VARCHAR(8);
    DECLARE StartTime, EndTime DateTime DEFAULT NOW();
    DECLARE ReturnValue INT DEFAULT 0;
    DECLARE ErrorMessage VARCHAR(50) DEFAULT '';

    DECLARE RewardItemId, RewardItemType, RewardItemLevel, PrizeId, PrizeContentID, EventId INT DEFAULT 0;
    DECLARE RewardItemBonus DECIMAL(18, 4) DEFAULT 0;
    DECLARE RewardNoTmp VARCHAR(100);
    DECLARE RewardNo VARCHAR(10);
    DECLARE RewardNoIndex INT DEFAULT 0;
    DECLARE RewardItemName, DrawName VARCHAR(50) DEFAULT '';

    DECLARE WeeksOfUse INT DEFAULT (SELECT (2 - MOD(IFNULL(no, 0), 2)) FROM w_jackpots WHERE id = p_w_jackpot_id);

    # 取出抽獎獎號
    DECLARE cur CURSOR FOR
        SELECT wjri.id
             , wjri.level
             , wjri.type AS reward_type
             , TRUNCATE(wjri.bonus, 2) AS bonus
             , wjri.prize_name AS prize_name
             , CASE wjri.level
                   WHEN 1 THEN wj.draw_number 		# 頭獎
                   WHEN 2 THEN wj.draw_number_2 	# 二獎
                   WHEN 3 THEN wj.draw_number_3 	# 三獎
                   WHEN 4 THEN wj.draw_number_4 	# 特別獎
                   WHEN 5 THEN wj.draw_number_5 	# 安慰獎
                   ELSE '' END AS reward_no
             , p.id AS prize_id
             , wjri.name AS draw_name
             #, wjri.type AS reward_item_type
             , pc.id AS prize_content_id
             , p.event_id AS event_id
        FROM w_jackpots AS wj
                 JOIN prizes as p ON p.event_id = wj.event_id
                 JOIN prize_contents AS pc ON pc.prize_id = p.id AND pc.status = 1
                 JOIN w_jackpot_reward_items AS wjri ON wjri.bonus = pc.point AND wjri.status = 1
        WHERE wj.id = p_w_jackpot_id AND wj.status = 0 AND wj.draw_number IS NOT NULL
        ORDER BY wjri.level DESC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    OPEN cur;

    SELECT DATE_ADD(draw_time, INTERVAL 8 HOUR)
         , draw_number
    INTO LuckyDrawTime, LuckyDrawNumber
    FROM w_jackpots
    WHERE id = p_w_jackpot_id;

    SET p_tg_message = CONCAT('[W Jackpot抽獎券開奬日期 ', DATE(LuckyDrawTime), ']
');
    SET p_tg_message = CONCAT(p_tg_message, '-----------------------
');

    REPEAT
        FETCH cur
            INTO RewardItemId, RewardItemLevel, RewardItemType, RewardItemBonus, RewardItemName,
                RewardNoTmp, PrizeId, DrawName, PrizeContentID, EventId;
        IF NOT Done THEN

            SET p_tg_message = CONCAT(p_tg_message, DrawName, '號碼:', RewardNo, '
');
            # 將本期改為派發中, 已派發完畢, 無法再派發1
            UPDATE w_jackpots SET status = 2 WHERE id = p_w_jackpot_id AND status != 1;

            SET TotalCount = 0;
            # 針對獎項做foreach派發
            WHILE RewardNoTmp != '' DO
                    SET RewardNoIndex = LOCATE(',', RewardNoTmp);
                    IF RewardNoIndex = 0 THEN
                        SET RewardNo = RewardNoTmp;
                        SET RewardNoTmp = '';
                    ELSE
                        SET RewardNo = LEFT(RewardNoTmp, (RewardNoIndex - 1));
                        SET RewardNoTmp = SUBSTRING(RewardNoTmp, (RewardNoIndex + 1));
                    END IF;

                    # 中獎名單
                    UPDATE w_jackpot_tickets
                    SET reward_item_id = RewardItemId,
                        reward_item_ids = CONCAT(IFNULL(reward_item_ids, ''), (IF(ISNULL(reward_item_ids), '', ',')), RewardItemId),
                        point = IFNULL(point, 0) + RewardItemBonus,
                        type = 2,
                        # 下面那段不要問我在寫什麼XDDD by Mars
                        info = IF(
                            ISNULL(info),
                            JSON_OBJECT(CONCAT('r', RewardItemLevel), 1),
                            JSON_SET(
                                info,
                                CONCAT('$.r', RewardItemLevel),
                                IFNULL(JSON_EXTRACT(info, CONCAT('$.r', RewardItemLevel)), 0) + 1
                            )
                               )
                    WHERE w_jackpot_id = p_w_jackpot_id
                      AND ticket_number = RewardNo
                      AND type != 1;
                    #AND INSTR(RewardNo, ticket_number);

                    # update 影響筆數
                    SELECT row_count() INTO RowCount;

                    # 更新獎項內容派發數量
                    UPDATE prize_contents SET sent = sent + RowCount WHERE id = PrizeContentID;

                    INSERT INTO prize_members(member_id, prize_id, content_id, lottery_number, lottery_point, status, admin)
                    SELECT wjt.member_id, PrizeId, PrizeContentID, wjt.id, RewardItemBonus, 0, 0
                    FROM w_jackpot_tickets as wjt
                    WHERE wjt.w_jackpot_id = p_w_jackpot_id
                      AND wjt.ticket_number = RewardNo
                      AND wjt.reward_item_id = RewardItemId
                      AND type != 1
                    # 排除重複派發
                    #AND NOT EXISTS (SELECT * FROM prize_members AS pm WHERE pm.prize_id = PrizeId AND pm.lottery_number = ldt.id)
                    ;

                    # update 影響筆數
                    SELECT row_count() INTO RowCount;
                    SET TotalCount = (TotalCount + RowCount);
                END WHILE;

            UPDATE prize_members
            SET status = 0
            WHERE prize_id = PrizeId
              AND content_id = PrizeContentID
              AND status = 9;

            SET p_tg_message = CONCAT(p_tg_message, DrawName, ':', RewardItemBonus, ' ', RewardNo, '
');
            IF RewardItemType = 2 THEN
                SET p_tg_message = CONCAT(p_tg_message, DrawName, '獎項:', RewardItemName, ' Count:', TotalCount, '
');
            ELSE
                SET p_tg_message = CONCAT(p_tg_message, DrawName, '獎項:', RewardItemBonus, ' Count:', TotalCount, '
');
            END IF;
        END IF;
    UNTIL Done END REPEAT;

    CLOSE cur;

    # 將狀態派發中改為中獎
    UPDATE w_jackpot_tickets
    SET type = 1
    WHERE w_jackpot_id = p_w_jackpot_id
      AND type = 2;

    # 將該期修改為已派發
    UPDATE w_jackpots SET status = 1 WHERE id = p_w_jackpot_id;
    SET p_error_code = 0;
    SET EndTime = NOW();

    # 將該期樣本獎券重置
    UPDATE temp_four_lottery_tickets
    SET status = 0
    WHERE event_id = EventId
      AND weeks_of_use = WeeksOfUse;
END
