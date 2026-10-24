CREATE PROCEDURE `SP_summary_rolling_chip_record_by_agent`(IN p_summary_time datetime)
BEGIN
    # 2023-06-08 neil		新建
    
    DECLARE StartTime datetime;
    DECLARE EndTime datetime;
    DECLARE SummaryTime datetime;
    
    #判斷是否有傳入參數，沒有則抓取上個月時間區間進行統計
    IF p_summary_time is null THEN
		SET EndTime = DATE_ADD(LAST_DAY(DATE_ADD(CURDATE() - DAY(CURDATE()) +1, INTERVAL -1 MONTH)), INTERVAL 16 HOUR);
		SET StartTime = DATE_ADD(DATE_ADD(CURDATE() - DAY(CURDATE()) +1, INTERVAL -1 MONTH), INTERVAL -8 HOUR);
	ELSE
		SET StartTime = DATE_ADD(p_summary_time, INTERVAL -8 HOUR);
		SET EndTime = DATE_ADD(LAST_DAY(p_summary_time), INTERVAL 16 HOUR);
    END IF;
	
	#判斷是否已經統計過，有則先進行刪除
    IF EXISTS(SELECT * FROM summary_rolling_chip_records_by_agent WHERE summary_time = StartTime) THEN
		DELETE FROM summary_rolling_chip_records_by_agent
        WHERE summary_time = StartTime;
    END IF;
    
	INSERT INTO summary_rolling_chip_records_by_agent(summary_time, agent_id, pay_rolling_chip, use_rolling_chip)
		SELECT StartTime,
        agent_id,
		SUM(D.pay_rolling_chip) AS pay_rolling_chip,
		SUM(D.use_rolling_chip) AS use_rolling_chip        
		FROM
		(
			SELECT m.parent AS agent_id,
				B.pay_rolling_chip AS pay_rolling_chip,
				B.use_rolling_chip AS use_rolling_chip       
			FROM summary_rolling_chip_records AS B
            JOIN members AS m ON m.id = B.member_id
			WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
		) AS D
        GROUP BY D.agent_id;
END