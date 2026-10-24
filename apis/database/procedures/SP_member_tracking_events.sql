CREATE PROCEDURE `SP_member_tracking_events`(
    IN p_StartTime DATETIME, 
    IN p_EndTime DATETIME, 
    IN p_EventType VARCHAR(50)
)
BEGIN
    # 會員追蹤事件（存款、首次存款等）並記錄到 member_tracking_events
    # 一般呼叫方式: CALL SP_member_tracking_events(NULL, NULL, NULL);  -- 處理最近一小時的所有事件
    # 重算呼叫方式: CALL SP_member_tracking_events('2021-01-01 00:00:00', '2021-01-01 23:59:59', 'DEPOSIT');
    # 重算呼叫方式: CALL SP_member_tracking_events('2021-01-01 00:00:00', '2021-01-01 23:59:59', NULL); -- 所有事件類型
    /*
    CREATE EVENT Event_member_tracking_events
    ON SCHEDULE EVERY 1 HOUR STARTS '2025-10-22 00:00:00' #系統時間UTC+0
    COMMENT '會員追蹤事件'
    DO
        CALL SP_member_tracking_events(NULL, NULL, NULL);
    */
    # 2025/10/21 Hongze Yan - 會員充值

    DECLARE StartTime, EndTime, ExecStart, ExecEnd DATETIME;
    DECLARE Params TEXT;
    DECLARE EventType VARCHAR(50);

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_member_tracking_events(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'), ',',
                    IFNULL(p_EventType, 'NULL'),
                    ') 異常 ', @p2),
                'db'
            );
        END;

    SET ExecStart = NOW();

    # 設定時間範圍
    IF p_EndTime IS NULL THEN
        SET EndTime = NOW();
    ELSE
        SET EndTime = p_EndTime;
    END IF;

    IF p_StartTime IS NULL THEN
        SET StartTime = DATE_SUB(EndTime, INTERVAL 1 HOUR);
    ELSE
        SET StartTime = p_StartTime;
    END IF;

    SET EventType = IFNULL(p_EventType, 'ALL');

    SET Params = CONCAT(
        IFNULL(p_StartTime, 'NULL'), '|',
        IFNULL(p_EndTime, 'NULL'), '|',
        IFNULL(p_EventType, 'NULL'), '|',
        StartTime, '|', EndTime, '|', EventType
    );


    IF EventType = 'ALL' OR EventType = 'DEPOSIT' OR EventType = 'FIRST_DEPOSIT' THEN
        INSERT INTO member_tracking_events (
            member_id,
            type,
            data
        )
        SELECT
            tl.member_id,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM transaction_logs tl2
                    WHERE tl2.member_id = tl.member_id
                      AND tl2.type IN (3, 4)
                      AND tl2.created_at < tl.created_at
                      AND tl2.status = 1000  
                )
                THEN 'DEPOSIT'
                WHEN EXISTS (
                    SELECT 1
                    FROM members m
                    WHERE m.id = tl.member_id
                      AND DATE(DATE_ADD(m.created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(tl.created_at, INTERVAL 8 HOUR))
                )
                THEN 'FIRST_DEPOSIT'
                ELSE 'DEPOSIT'
            END AS type,
            JSON_OBJECT(
                'amount', tl.point,
                'transaction_id', tl.id
            ) AS data
        FROM transaction_logs tl
        WHERE tl.type IN (3, 4)
          AND tl.created_at >= StartTime
          AND tl.created_at < EndTime
          AND tl.status = 1000
          AND NOT EXISTS (
              SELECT 1
              FROM member_tracking_events mte
              WHERE mte.member_id = tl.member_id
                AND mte.type IN ('DEPOSIT', 'FIRST_DEPOSIT')
                AND JSON_UNQUOTE(JSON_EXTRACT(mte.data, '$.transaction_id')) = CAST(tl.id AS CHAR)
          )
        ORDER BY tl.created_at;
    END IF;

    SET ExecEnd = NOW();

    # 更新控制表
    CALL COM_Update_Control_Table(10002, 'member_tracking_events', 0, StartTime, EndTime, ExecStart, ExecEnd, Params);

    SET ExecEnd = NOW();
    CALL COM_Send_Error_Message(
        CONCAT(
            '[系統通知] SP_member_tracking_events(',
            IFNULL(p_StartTime, 'NULL'), ',',
            IFNULL(p_EndTime, 'NULL'), ',',
            IFNULL(p_EventType, 'NULL'),
            ') 計算完畢 ',
            ExecStart, ' ~ ', ExecEnd
        ),
        'db'
    );
END