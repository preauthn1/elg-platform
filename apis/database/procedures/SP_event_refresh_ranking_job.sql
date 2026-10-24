CREATE PROCEDURE `SP_event_refresh_ranking_job`(
    IN p_event_id INT,
    IN p_detail_id INT
)
BEGIN
    # 刷新排行榜 排程工作
    /*
    CREATE EVENT Event_Refresh_Ranking_Job
    ON SCHEDULE EVERY 15 MINUTE STARTS '2024-10-01 12:00:00' #系統時間UTC+0
    COMMENT '每15分 - 刷新排行榜'
    DO
        CALL SP_event_refresh_ranking_job(1950, 0);
    */

    DECLARE EventId, EventDetailId INT DEFAULT 0;
    DECLARE Done INT DEFAULT 0;

    DECLARE Cur CURSOR FOR
        SELECT e.id as event_id
             , ed.id as event_detail_id
        FROM event_details AS ed
                 JOIN events AS e ON e.id = ed.event_id AND e.status = 1
                 JOIN event_refresh_rankings AS err ON err.event_detail_id = ed.id
        WHERE ed.start_time <= NOW()
          AND ed.end_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
          AND (p_event_id = 0 OR e.id = p_event_id)
          AND (p_detail_id = 0 OR ed.id = p_detail_id)
          AND ed.status = 1;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_event_slot_competition_job(',
                    IFNULL(p_event_id, 'NULL'), ',',
                    IFNULL(p_detail_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
    REPEAT
        FETCH Cur
            INTO EventId, EventDetailId;
        IF NOT Done THEN
            CALL SP_calc_refresh_ranking(EventId, EventDetailId);
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;

END
