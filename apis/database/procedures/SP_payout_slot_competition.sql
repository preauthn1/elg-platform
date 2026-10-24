CREATE PROCEDURE `SP_payout_slot_competition`(
    IN p_event_id INT
    #, IN p_event_detail_id INT
)
BEGIN

    # 派發老虎機爭霸戰獎項
    # 2022/05/19 Mars 		新增
    # 2024/08/30 Neil		新增自動派獎

    DECLARE Done, Done2 INT DEFAULT 0;
    DECLARE StartTime, EndTime DateTime DEFAULT NOW();
    DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE EventId, EventDetailId, SlotCompetitionId, SlotCompetitionNo, PrizeId INT DEFAULT 0;
    DECLARE AutoPayout INT DEFAULT 0;

    # 取出開獎期數
    DECLARE cur CURSOR FOR
        SELECT id, event_id, event_detail_id, no
        FROM event_slot_competitions
        WHERE event_id = p_event_id
          AND draw_time <= NOW()
            /*WHERE (
                draw_time = DATE_ADD(CURDATE(), INTERVAL HOUR(NOW()) HOUR)
                OR
                (event_id = p_event_id AND event_detail_id = p_event_detail_id)
            )*/
          AND status = 0;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_payout_slot_competition(',
                    IFNULL(p_event_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET ExecStart = NOW();

    OPEN cur;
    REPEAT
        FETCH cur
            INTO SlotCompetitionId, EventId, EventDetailId, SlotCompetitionNo;
        IF NOT Done THEN

            # 將該期修改為派發中
            UPDATE event_slot_competitions SET status = 2
            WHERE id = SlotCompetitionId
              AND status = 0;

            CALL SP_payout_slot_competition_sub(
                SlotCompetitionId,
                EventId,
                EventDetailId,
                SlotCompetitionNo
                 );

            # 查詢events是否為自動派獎
            SELECT auto_payout INTO AutoPayout FROM events WHERE id = EventId;

            IF AutoPayout = 1 THEN
                # 如果是自動派就call SP派獎
                SELECT id INTO PrizeId FROM prizes WHERE event_id = EventId;
                CALL SP_payout_slot_competition_auto(
                    SlotCompetitionId,
                    EventId,
                    EventDetailId,
                    PrizeId
                     );
            END IF;

            # 將該期修改為已派發
            UPDATE event_slot_competitions SET status = 1
            WHERE id = SlotCompetitionId
              AND status = 2;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;

    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_payout_slot_competition(',
            IFNULL(p_event_id, 'NULL'),
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
         );
END
