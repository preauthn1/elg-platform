CREATE PROCEDURE `SP_Job_Member_Type_Change`()
BEGIN

	#將當前會員類型移至前一天會員類型
    
    DECLARE ExecStart, ExecEnd DATETIME;
	DECLARE Yestoday DATE 
		DEFAULT DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 1 DAY);
	DECLARE StartTime DATETIME
		DEFAULT DATE_SUB(Yestoday, INTERVAL 8 HOUR);
    
	### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_Job_Member_Type_Change(',
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    SET ExecStart = NOW();
    
	UPDATE members
    SET previous_day_type = `type`
	WHERE previous_day_type != `type`;
    
    # 紀錄會員類型不為0的
    #INSERT INTO member_type_histories(data_time, member_id, type)
	#SELECT StartTime, id, previous_day_type
	#FROM members 
	#WHERE previous_day_type != 0;
          
    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_Job_Member_Type_Change(',
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd),
        'db'
	);  
END