CREATE PROCEDURE `SP_summary_event_add_process`(
    IN p_member_id INT,
    IN p_event_detail_id INT,
    IN p_member_point INT,
    IN p_member_status INT
)
BEGIN
    # 活動獎勵派發 - Add Process
    # 2022/02/08 Mars 		ADD

    DECLARE MemberProcessId, MemberProcessStatus INT DEFAULT 0;

    SELECT id, status
    INTO MemberProcessId, MemberProcessStatus
    #FROM event_processes_test
    FROM event_processes
    WHERE member_id = p_member_id
      AND detail_id = p_event_detail_id
    LIMIT 1;

    IF MemberProcessId > 0 THEN
        IF MemberProcessStatus = 2 THEN
            SET p_member_status = 2;
        END IF;

        #UPDATE event_processes_test
        UPDATE event_processes
        SET point = FLOOR(p_member_point), # 無條件捨去
            status = p_member_status
        WHERE id = MemberProcessId;
    ELSE
        #INSERT INTO event_processes_test(detail_id, member_id, point, status, admin)
        INSERT INTO event_processes(detail_id, member_id, point, status, admin)
        VALUES(p_event_detail_id, p_member_id, p_member_point, p_member_status, 0);
    END IF;
END
