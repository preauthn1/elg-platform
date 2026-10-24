CREATE PROCEDURE `SP_Job_Ranking_Tbr_Record`(
	IN `p_DateHour` DATETIME
)
BEGIN
	# 統計達標得注單紀錄
	# 只記錄 winlose大於此值, 暫定大於500
	DECLARE target_amount INT DEFAULT 500;
	DECLARE StartTime, EndTime DATETIME;
    
	### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] SP_Job_Ranking_Tbr_Record(',
                IFNULL(p_DateHour, 'NULL'), 
				') 異常 ', @p2), 
			'db'
		);
    END;
    
	IF p_DateHour IS NULL THEN
	    SET EndTime = DATE_ADD(CURDATE(), INTERVAL HOUR(NOW()) + 1 HOUR);
	    SET StartTime = DATE_ADD(EndTime, INTERVAL -3 HOUR);
	ELSE
		SET StartTime = DATE_ADD(DATE(p_DateHour), INTERVAL HOUR(p_DateHour) HOUR);
		SET EndTime = DATE_ADD(StartTime, INTERVAL 1 HOUR);
	END IF;
	
	INSERT INTO ranking_tbr_records(
				id
				, summary_time
				, member_id
				, vendor_id
				, game_id
				, bet
				, valid_bet
				, win_lose
				, bet_time
				, payout_time
			)
	SELECT id
			, summary_time
			, member_id
            , vendor_id
            , game_id
            , bet
            , valid_bet
            , win_lose
            , bet_time
            , payout_time
	FROM total_bet_records AS tbr
	WHERE summary_time >= StartTime AND summary_time < EndTime 
	AND win_lose >= target_amount
	ON DUPLICATE KEY UPDATE 
		bet = tbr.bet,
		valid_bet = tbr.valid_bet,
	    win_lose = tbr.win_lose,
	    bet_time = tbr.bet_time,
	    payout_time = tbr.payout_time;
END