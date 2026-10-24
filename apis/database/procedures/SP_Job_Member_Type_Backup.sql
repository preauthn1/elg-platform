CREATE PROCEDURE `SP_Job_Member_Type_Backup`()
BEGIN

	/*
	# 會員類型非0備份
    
    # 2025-12-20 Mars 			ADD
    
    DECLARE Today DATE 
		DEFAULT DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR));
    DECLARE Yestoday DATE 
		DEFAULT DATE_SUB(Today, INTERVAL 1 DAY);
    DECLARE StartTime DATETIME 
		DEFAULT DATE_SUB(DATE(Yestoday), INTERVAL 8 HOUR);
    DECLARE EndTime DATETIME 
		DEFAULT DATE_ADD(StartTime, INTERVAL 1 DAY);
    
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_Job_Member_Type_Backup(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;
    
	INSERT INTO member_type_histories(data_time, member_id, `type`)
	SELECT StartTime, id, previous_day_type
	FROM members AS m
	WHERE previous_day_type > 0
    AND m.created_at < EndTime
	AND NOT EXISTS (
		SELECT * FROM member_type_histories AS tmp
		WHERE tmp.member_id = m.id
		AND tmp.data_time = StartTime
	);
    */
END