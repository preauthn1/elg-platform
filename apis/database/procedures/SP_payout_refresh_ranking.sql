CREATE PROCEDURE `SP_payout_refresh_ranking`(
	IN p_refresh_ranking_id INT,
    IN p_event_id INT,
    IN p_event_detail_id INT,
    IN p_prize_id INT
)
BEGIN

	# 派發老虎機爭霸戰獎項
    # 2022/05/23 Mars 		新增
    
	DECLARE Done INT DEFAULT 0;
    DECLARE PrizeId, PrizeContentId, PrizeContentNumber, PointType, PrizeContentLevel INT(11);
    DECLARE PrizeContentPoint DECIMAL(18, 4);
    DECLARE PrizeContentName VARCHAR(50);
    
	# 取出獎項
	DECLARE cur CURSOR FOR 
    SELECT p.id, pc.id, pc.name, pc.level, pc.number, pc.point_type, pc.point
	FROM prizes AS p
	JOIN prize_contents AS pc ON pc.prize_id = p.id AND pc.status = 1
	WHERE event_id = p_event_id
	AND p.status = 1
    ORDER BY pc.level ASC;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    
	OPEN cur;
    REPEAT
		FETCH cur 
        INTO PrizeId, PrizeContentId, PrizeContentName, PrizeContentLevel, PrizeContentNumber, PointType, PrizeContentPoint;
		IF NOT Done THEN
			UPDATE event_refresh_ranking_records
			SET type = 1,
				prize_content_id = PrizeContentId,
				point = PrizeContentPoint
			WHERE event_refresh_ranking_id = p_refresh_ranking_id AND is_calc = 1 AND type = 0
			ORDER BY ranking ASC
			LIMIT PrizeContentNumber;
		END IF;
	UNTIL Done END REPEAT;
    CLOSE cur;
    
    CALL SP_payout_refresh_ranking_sub(p_event_id, p_refresh_ranking_id, p_event_detail_id, p_prize_id);
    
    # 將該期修改為已派發
	UPDATE event_refresh_rankings SET status = 1 WHERE id = p_refresh_ranking_id AND status = 2;
END