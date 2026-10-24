CREATE PROCEDURE `SP_summary_event_record_by_rolling_chip`(
	IN p_StartTime DATETIME,
    IN p_EndTime DATETIME
)
BEGIN

	# 彙整所有禮碼紀錄
    
    # 一般呼叫方式: CALL SP_summary_event_record_by_rolling_chip(NULL, NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_rolling_chip('2020-05-30 10:00:00', '2020-05-30 12:00:00');
    /*
    新增Event
	CREATE EVENT SP_summary_event_record_by_rolling_chip
		ON SCHEDULE EVERY 1 HOUR STARTS '2021-05-01 00:00:00' #系統時間UTC+0
		COMMENT '每小時-彙整V-world獎勵'
		DO
			CALL SP_summary_event_record_by_rolling_chip(NULL, NULL);
    */
    
    DECLARE EventId INT DEFAULT 6;
    DECLARE StartTime, EndTime DATETIME;
    
	### SQL異常時發TG通知 ##################
	DECLARE EXIT HANDLER FOR SQLEXCEPTION
	BEGIN
		GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
			
		# 發通知
		CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_summary_event_record_by_rolling_chip(',
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
    SELECT summary_time, member_id, EventId, pay_rolling_chip
	FROM summary_rolling_chip_records  
	WHERE summary_time >= StartTime AND summary_time < EndTime AND pay_rolling_chip > 0;

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