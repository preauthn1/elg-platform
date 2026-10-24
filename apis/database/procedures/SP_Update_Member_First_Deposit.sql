CREATE PROCEDURE `SP_Update_Member_First_Deposit`(
    IN p_SummaryTime DATETIME
)
BEGIN
    # 更新首充
    #
    # 2026/09/01 Mars 		ADD

	DECLARE v_SummaryTime DATETIME DEFAULT IFNULL(p_SummaryTime, DATE_SUB(NOW(), INTERVAL 1 HOUR));
    DECLARE v_StartTime DATETIME DEFAULT DATE_ADD(DATE(v_SummaryTime), INTERVAL HOUR(v_SummaryTime) HOUR);
    DECLARE v_EndTime DATETIME DEFAULT DATE_ADD(v_StartTime, INTERVAL 1 DAY);

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_Update_Member_First_Deposit(',
                    IFNULL(p_SummaryTime, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    UPDATE members m
    -- 先從 orders 篩出這段區間內有成功繳費的會員候選名單（小範圍，吃 pay_time 索引）
    JOIN (
        SELECT DISTINCT member_id
        FROM orders
        WHERE status = 3
          AND pay_time >= v_StartTime
          AND pay_time < v_EndTime
    ) candidates ON candidates.member_id = m.id
    -- 只對候選名單裡的會員，找出這段區間內最早那一筆訂單（id 當 tie-breaker）
    JOIN orders o
        ON o.id = (
            SELECT o2.id
            FROM orders o2
            WHERE o2.member_id = m.id
              AND o2.status = 3
              AND o2.pay_time >= v_StartTime
              AND o2.pay_time < v_EndTime
            ORDER BY o2.pay_time ASC, o2.id ASC
            LIMIT 1
        )
    SET m.first_deposit = o.pay_time,
        m.first_deposit_point = o.point
    WHERE m.first_deposit IS NULL;  -- 已經有值就不再更新
END
