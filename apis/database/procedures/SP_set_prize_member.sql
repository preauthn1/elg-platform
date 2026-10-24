CREATE PROCEDURE `SP_set_prize_member`(
    IN p_member_id INT,
    IN p_prize_id INT,
    IN p_event_detail_id INT,
    IN p_expired_time DATETIME,
    IN p_remainder INT
)
BEGIN

    # 派發prize_members
    # 2022-09-14 Mars 			Add

    DECLARE ExecuteNum INT DEFAULT 0;

    INSERT INTO prize_members(
        member_id, prize_id, event_detail_id, expired_time, status, admin
    )
    SELECT p_member_id, p_prize_id, p_event_detail_id, p_expired_time, 0, 51
    FROM prize_members
    LIMIT p_remainder;
END
