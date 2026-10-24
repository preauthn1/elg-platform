CREATE PROCEDURE `SP_payout_slot_lose_rebate`(
    IN p_event_id INT
)
BEGIN

    # 派發老虎機回扣獎項
    # 2022/07/22 Neil		新增

    DECLARE Done INT DEFAULT 0;
    DECLARE StartTime, EndTime, ExpireTime DATETIME DEFAULT NOW();
    DECLARE EventDetailId, EventId, ExpireHour, PrizeId, PrizeContentId
        , EventDayLimit, EventTotalLimit INT DEFAULT 0;
    DECLARE PointPercentage FLOAT DEFAULT 0.8;

    # 取出開獎期數
    DECLARE cur CURSOR FOR
        SELECT ed.id, ed.event_id, ed.expire_hour, p.id, pc.id
             , IFNULL((JSON_UNQUOTE(JSON_EXTRACT(info, '$.event_day_limit')) / pc.point_percentage), 0)
             , IFNULL((JSON_UNQUOTE(JSON_EXTRACT(info, '$.event_total_limit')) / pc.point_percentage), 0)
             , pc.point_percentage
        FROM event_details AS ed
                 JOIN prizes AS p ON p.event_id = ed.event_id
                 JOIN prize_contents AS pc ON pc.prize_id = p.id
        WHERE (ed.event_id = p_event_id OR p_event_id = 0)
          AND ed.action_id = 9
          AND draw_time = DATE_ADD(CURDATE(), INTERVAL HOUR(NOW()) HOUR)
          and ed.`status` = 1;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_payout_slot_lose_rebate(',
                    IFNULL(p_event_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    OPEN cur;
    REPEAT
        FETCH cur
            INTO EventDetailId, EventId, ExpireHour, PrizeId, PrizeContentId
                , EventDayLimit, EventTotalLimit, PointPercentage;
        IF NOT Done THEN
            SET ExpireTime = DATE_ADD(NOW(), INTERVAL ExpireHour HOUR);

            IF EventTotalLimit = 0 THEN
                INSERT INTO prize_members(
                    member_id, prize_id, content_id, event_detail_id, expired_time, lottery_point, `status`, `admin`)
                SELECT member_id, PrizeId, PrizeContentId, EventDetailId, ExpireTime,
                       (CASE
                            WHEN EventDayLimit = 0 THEN `point`
                            WHEN `point` >= EventDayLimit THEN EventDayLimit
                            ELSE `point`
                           END) AS `point`, 0, 0
                FROM event_processes
                WHERE detail_id = EventDetailId
                  # 排除重複派發
                  AND NOT EXISTS (
                    SELECT *
                    FROM prize_members AS pm
                    WHERE pm.prize_id = PrizeId
                      AND pm.content_id = PrizeContentId
                      AND event_detail_id = EventDetailId
                )
                  AND (`point` * PointPercentage) >= 1;
            ELSE
                #SELECT 456, EventDetailId, EventId, ExpireHour, PrizeId, PrizeContentId, EventDayLimit, EventTotalLimit, PointPercentage;
                #有個人最大上限
                INSERT INTO prize_members(
                    member_id, prize_id, content_id, event_detail_id, expired_time, lottery_point, `status`, `admin`
                )
                SELECT member_id, PrizeId, PrizeContentId, EventDetailId, ExpireTime,
                       IF (
                           SUM(`point`) > EventTotalLimit,
                           EventTotalLimit - SUM(CASE WHEN detail_id = @EventDetailId THEN 0 ELSE `point` END),
                           SUM(CASE WHEN detail_id = @EventDetailId THEN `point` ELSE 0 END)
                       ) AS `point`
                    , 0, 0
                FROM event_processes
                WHERE detail_id IN (
                    SELECT id FROM event_details
                    WHERE event_id = @EventId
                      AND end_time < NOW()
                )
                  # 排除重複派發
                  AND NOT EXISTS (
                    SELECT *
                    FROM prize_members AS pm
                    WHERE pm.prize_id = PrizeId
                      AND pm.content_id = PrizeContentId
                      AND event_detail_id = EventDetailId
                )
                GROUP BY member_id
                HAVING (`point` * PointPercentage) >= 1;
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;
END
