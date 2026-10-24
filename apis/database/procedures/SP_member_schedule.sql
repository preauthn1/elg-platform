CREATE PROCEDURE `SP_member_schedule`(
	IN p_ScheduleTypeId INT
)
BEGIN
	# 會員排程異動
    DECLARE Yestoday DATE 
		DEFAULT DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 1 DAY);
	DECLARE StartTime DATETIME
		DEFAULT DATE_SUB(Yestoday, INTERVAL 8 HOUR);
    DECLARE EndTime DATETIME
		DEFAULT DATE_ADD(StartTime, INTERVAL 1 DAY);
    DECLARE ScheduleTypeId INT
		DEFAULT IFNULL(p_ScheduleTypeId, 0);
    DECLARE ScheduleLogId, MemberId INT DEFAULT 0;
    DECLARE DataValue VARCHAR(50) DEFAULT '';
    DECLARE Done, RowCount INT DEFAULT 0;
	
    # 取出排程清單
    DECLARE cur CURSOR FOR
		SELECT msl.id, msl.member_id, msl.data_value
		FROM member_schedule_logs AS msl
		WHERE msl.created_at >= StartTime
        AND msl.created_at < EndTime
		AND msl.schedule_type_id = ScheduleTypeId
		AND msl.`status` = 0;
    
	### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
		ROLLBACK;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_member_schedule(',
                IFNULL(p_ScheduleTypeId, 'NULL'), 
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    
    START TRANSACTION;
    
    OPEN cur;
    REPEAT
        FETCH cur
            INTO ScheduleLogId, MemberId, DataValue;
        IF NOT Done THEN
			SET RowCount = 0;
			SELECT Yestoday, StartTime, EndTime, ScheduleTypeId
				, ScheduleLogId, MemberId, DataValue;
        
			IF ScheduleTypeId = 51 THEN
				IF DataValue = '2' THEN
					UPDATE members 
					SET `type` = (DataValue * 1)
					WHERE id = MemberId
					AND `type` != (DataValue * 1);
				ELSE
					UPDATE members 
					SET `type` = 0
					WHERE id = MemberId
					AND `type` != 0;
                END IF;
				
				# 取得影響筆數
				SET RowCount = ROW_COUNT();
				
				IF RowCount = 1 THEN
					UPDATE member_schedule_logs
					SET `status` = 1
					WHERE id = ScheduleLogId
					AND `status` = 0;
				ELSE
					UPDATE member_schedule_logs
					SET `status` = 9 #處理失敗
						, memo = '當前資料同變更資料'
					WHERE id = ScheduleLogId
					AND `status` = 0;
				END IF;
            END IF;
			
		COMMIT;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;
	
END