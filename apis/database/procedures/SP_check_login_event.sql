CREATE PROCEDURE `SP_check_login_event`(
    IN p_member_id INT,
    OUT o_total_count INT
)
BEGIN
    # 檢查是否有登入獎勵, 且發紅包與洗分取消領獎資格設定為啟用
    # 2022/01/25 Mars 		ADD

    DECLARE HierarchyPath VARCHAR(100)
        DEFAULT (
            SELECT a.hierarchy_path
            FROM members AS m
                     JOIN agents AS a ON a.id = m.parent
            WHERE m.id = p_member_id
        );

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_check_login_event(',
                    IFNULL(p_member_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    # 獲取當前活動(type_id; 51:首充, 52:每次充, 53:首充, 54:每次充, 55:每日充)
    SELECT COUNT(*) INTO o_total_count
    FROM prizes AS p
             JOIN events AS e
                  ON e.id = p.event_id
                      AND e.status = 1
             JOIN event_details AS ed
                  ON ed.event_id = e.id
                      AND ed.status = 1
                      AND ed.start_time <= NOW()
                      AND ed.end_time >= NOW()
                      AND ed.action_id in (51, 52, 53, 54, 55)
             JOIN agents AS a
                  ON a.id = e.agent_id
                      AND HierarchyPath LIKE CONCAT(a.hierarchy_path, '%')
             JOIN prize_members AS pm
                  ON pm.prize_id = p.id
                      AND pm.member_id = p_member_id
                      AND pm.status = 0
    WHERE p.withdrawal_cancel_reward = 1
      AND p.status = 1;
END
