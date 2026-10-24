CREATE PROCEDURE `SP_summary_event_record_by_lottery`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME
)
BEGIN

    # 彙整transaction_log, 獎勵(10, 13, 15, 19, 20, 21, 24)
    # 2021/10/21 Mars 		transaction_logs.type add 19, 24
    # 2022/11/30 Neil		修改 把開分(贈點)算進去
    # 2024/01/16 Neil		修改 新增優惠錢包
    # 2024/06/27 Mars 		修改時間只留小時

    # 一般呼叫方式: CALL SP_summary_event_record_by_lottery(NULL, NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_lottery('2020-05-30 10:00:00', '2020-05-30 12:00:00');
    /*
    新增Event
	CREATE EVENT Event_Summary_event_reocrd_by_lottery
		ON SCHEDULE EVERY 1 HOUR STARTS '2021-05-01 00:00:00' #系統時間UTC+0
		COMMENT '每小時-彙整V-world獎勵'
		DO
			CALL SP_summary_event_record_by_lottery(NULL, NULL);
    */

    DECLARE EventId INT DEFAULT 1;
    DECLARE StartTime, EndTime DATETIME;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_event_record_by_lottery(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    IF p_StartTime IS NULL THEN
        SET StartTime = DATE_SUB(DATE_ADD(DATE(NOW()), INTERVAL HOUR(NOW()) HOUR), INTERVAL 3 HOUR);
    ELSE
        SET StartTime = DATE_ADD(DATE(p_StartTime), INTERVAL HOUR(p_StartTime) HOUR);
    END IF;

    IF p_EndTime IS NULL THEN
        SET EndTime = DATE_ADD(StartTime, INTERVAL 2 HOUR);
    ELSE
        SET EndTime = DATE_ADD(DATE(p_EndTime), INTERVAL HOUR(p_EndTime) HOUR);
    END IF;

    # member
    IF EXISTS(SELECT * FROM summary_event_records WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime) THEN
        DELETE FROM summary_event_records WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime;
    END IF;

    INSERT INTO summary_event_records(summary_time, member_id, event_id, `point`)
    SELECT y.summary_time, y.member_id, EventId, y.`point`
    FROM (
             SELECT z.summary_time, z.member_id, SUM(z.`point`) AS `point`
             FROM (
                      SELECT member_id
                           , `point`
                           , DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS summary_time
                      FROM transaction_logs
                      WHERE created_at >= StartTime
                        AND created_at < EndTime
                        AND `type` IN (10, 13, 15, 19, 20, 21, 24)
                      UNION
                      SELECT member_id
                           , CASE `type` WHEN 5 THEN `point` * -1 WHEN 7 THEN `point` * -1 ELSE `point` END
                           , DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS summary_time
                      FROM promotion_wallet_logs
                      WHERE created_at >= StartTime
                        AND created_at < EndTime
                        AND `type` IN (3, 5, 6, 7)
                  ) AS z
             GROUP BY z.summary_time, z.member_id
         ) AS y;

    #agetn
    IF EXISTS(SELECT * FROM summary_event_record_agents WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime) THEN
        DELETE FROM summary_event_record_agents WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime;
    END IF;

    INSERT INTO summary_event_record_agents(
        summary_time, agent_id, event_id, `point`
    )
    SELECT y.summary_time, y.agent_id, y.event_id, y.`point`
    FROM (
             SELECT r.summary_time
                  , a.id AS agent_id
                  , r.event_id
                  , SUM(r.`point`) AS `point`
             FROM summary_event_records AS r
                      JOIN members AS m ON m.id = r.member_id
                      JOIN agents AS a ON a.id = m.parent
             WHERE summary_time >= StartTime
               AND summary_time < EndTime
               AND r.event_id = EventId
             GROUP BY r.summary_time, a.id, r.event_id
         ) AS y;

END
