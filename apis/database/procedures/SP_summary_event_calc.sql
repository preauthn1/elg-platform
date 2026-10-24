CREATE PROCEDURE `SP_summary_event_calc`(IN p_event_id INT)
BEGIN
    # 活動獎勵派發 - Calc
    # 2022/02/08 Mars 		ADD

    #計算
    DECLARE StartTime DATETIME DEFAULT NOW();
    DECLARE EndTime DATETIME DEFAULT DATE_SUB(NOW(), INTERVAL 1 DAY);

    DECLARE EventId, EventDetailId, EventProcessId, MemberId, PrizeId, PrizeTicketNumber, PrizeIsRepeatedly, ProcessNumber, PrizeTypeId, LuckyDrawId INT DEFAULT 0;
    DECLARE EventDetailTitle VARCHAR(100) DEFAULT '';
    DECLARE EventStartTime, EventEndTime DATETIME;

    DECLARE CurMemberCount, CurTicketNumber, CurSentTicketNumber, CurRemainder INT DEFAULT 0;
    DECLARE Done, Continues INT DEFAULT 0;

    DECLARE Cur CURSOR FOR
        SELECT e.id AS event_id
             , ep.id AS process_id
             , ep.member_id
             , p.id AS prize_id
             , FLOOR(MIN(ep.point / d.point)) AS ticket_number
             , p.is_repeatedly
             , COUNT(p.id) AS process_number
             , d.start_time
             , d.end_time
             , p.type_id
             , CASE WHEN p.type_id = 5 THEN d.id ELSE 0 END AS detail_id #判斷是不是抽獎券活動
             , ld.id AS lucky_draw_id
             , e.title
        FROM events AS e
                 JOIN event_details AS d
                      ON d.event_id = e.id
                          AND d.status = 1
                          AND d.action_id < 6
                          AND d.start_time <= StartTime
                          AND d.end_time >= EndTime
                 JOIN prizes AS p ON p.event_id = e.id
                 LEFT JOIN event_processes AS ep ON ep.detail_id = d.id
                 LEFT JOIN lucky_draws AS ld ON ld.event_detail_id = d.id
        WHERE e.status = 1
          AND (p_event_id = 0 OR (p_event_id > 0 AND e.id = p_event_id))
        GROUP BY e.id, ep.member_id, detail_id
        HAVING ticket_number >= 1;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
    REPEAT
        FETCH Cur
            INTO EventId, EventProcessId, MemberId, PrizeId, PrizeTicketNumber, PrizeIsRepeatedly, ProcessNumber,
                EventStartTime, EventEndTime, PrizeTypeId, EventDetailId, LuckyDrawId, EventDetailTitle;

        SELECT COUNT(*) AS cnt INTO CurMemberCount
        FROM events AS e
                 JOIN event_details AS d
                      ON d.event_id = e.id
                          AND (PrizeTypeId != 5 OR (PrizeTypeId = 5 AND d.id = EventDetailId))
        WHERE e.id = EventId
          AND d.status = 1
          AND d.start_time <= StartTime
          AND d.end_time >= EndTime;

        SET CurMemberCount = IFNULL(CurMemberCount, 0);

        # 先確認是否符合條件，進度達成的數量等於detail的數量
        IF CurMemberCount > 0 AND ProcessNumber = CurMemberCount THEN
            # 更新進度狀態
            UPDATE event_processes
            SET status = 2
            WHERE id = EventProcessId;

            SET CurTicketNumber = 0;
            IF PrizeIsRepeatedly = 0 THEN
                SET CurTicketNumber = 1; #不可重覆領獎, 只給一張
            ELSE
                SET CurTicketNumber = FLOOR(PrizeTicketNumber); #可重覆領獎
            END IF;

            # 給獎
            # V-Jackpot
            IF PrizeTypeId = 5 THEN
                # 驗證給券的數量是否正確
                SELECT COUNT(*) AS CNT INTO CurSentTicketNumber
                FROM lucky_draw_tickets
                WHERE member_id = MemberId
                  AND lucky_draw_id = LuckyDrawId;
                SET CurSentTicketNumber = IFNULL(CurSentTicketNumber, 0);

                # 還未給獎的次數
                IF CurTicketNumber > CurSentTicketNumber THEN
                    SET CurRemainder = (CurTicketNumber - CurSentTicketNumber);
                    IF CurRemainder > 0 THEN
                        # 當派獎數大於1000時先派1000張
                        IF CurRemainder > 5000 THEN
                            SET CurRemainder = 5000;
                        END IF;

                        # 派發抽獎券
                        CALL SP_set_lucky_ticket(LuckyDrawId, MemberId, CurRemainder);
                    END IF;
                END IF;
            ELSE
                # 驗證給獎的數量是否正確
                SELECT COUNT(*) AS cnt INTO CurSentTicketNumber
                FROM prize_members
                WHERE member_id = MemberId
                  AND prize_id = PrizeId
                  AND created_at BETWEEN EventStartTime AND EventEndTime;

                SET CurSentTicketNumber = IFNULL(CurSentTicketNumber, 0);

                # 還未給獎的次數
                IF CurTicketNumber > CurSentTicketNumber THEN
                    SET CurRemainder = CurTicketNumber - CurSentTicketNumber;
                    WHILE CurRemainder > 0 DO
                            INSERT INTO prize_members (member_id, prize_id, status, admin)
                            VALUES (MemberId, PrizeId, 0, 0);

                            SET CurRemainder = CurRemainder - 1;
                        END WHILE;
                END IF;
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;
END
