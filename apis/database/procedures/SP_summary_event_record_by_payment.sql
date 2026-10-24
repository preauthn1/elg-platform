CREATE PROCEDURE `SP_summary_event_record_by_payment`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME
)
BEGIN

    # ⛔ 已作廢（2026-09-17，target/tickets#4369）：代收支付成本彙總改由 PHP 排程產生，請改用
    #    app/Services/Summary/PaymentCostSummaryService.php（每小時排程 summary:payment-cost、
    #    重算 order:recalc-payment-cost-summary）。各站對應 EVENT 已 DISABLE，本 SP 不再被呼叫；
    #    保留定義只為回滾（重建 EVENT），觀察期後移除檔案並 DROP PROCEDURE。

    # 彙整支付成功成本計算

    # 一般呼叫方式: CALL SP_summary_event_record_by_payment(NULL, NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_payment('2020-05-30 10:00:00', '2020-05-30 12:00:00');
    /*
    新增Event
	CREATE EVENT Event_Summary_event_reocrd_by_payment
		ON SCHEDULE EVERY 1 HOUR STARTS '2021-05-01 00:00:00' #系統時間UTC+0
		COMMENT '每小時-彙整支付成本'
		DO
			CALL SP_summary_event_record_by_payment(NULL, NULL);
    */

    # 2025-04-15 Mars 		優化group by, keyword加上``
    # 2026-09-16 Tim 		成本改直接加總 orders.payment_cost（建單當下依渠道 cost 凍結，target/tickets#4361），
    #                   不再 JOIN agent_cash_flows 現算。順帶修掉原本 GROUP BY 會員卻用未聚合 acf.cost 的 bug
    #                   （同一會員同一小時走兩個渠道時任選一個渠道的費率乘全部金額，TPower 實測 0 費率渠道被算成 1%）。
    #                   上線前需以 order:backfill-payment-cost 回補歷史單，否則舊單成本為 0。

    DECLARE EventId INT DEFAULT 3;
    DECLARE ExecStart, ExecEnd, StartTime, EndTime DATETIME;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p1 = RETURNED_SQLSTATE,
                @p2 = MESSAGE_TEXT,
                @p3 = MYSQL_ERRNO;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_event_record_by_payment(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'),
                    ') 異常 ',
                    ' code:' , @p1,
                    ' msg:' , @p2,
                    ' state:' , @p3),
                'db'
                 );
        END;

    SET ExecStart = NOW();

    IF p_EndTime IS NULL THEN
        SET EndTime = DATE_ADD(CURDATE(), INTERVAL HOUR(CURRENT_TIME()) HOUR);
    ELSE
        SET EndTime = DATE_ADD(DATE(p_EndTime), INTERVAL HOUR(p_EndTime) HOUR);
    END IF;

    IF p_StartTime IS NULL THEN
        SET StartTime = DATE_SUB(EndTime, INTERVAL 3 HOUR);
    ELSE
        SET StartTime = DATE_ADD(DATE(p_StartTime), INTERVAL HOUR(p_StartTime) HOUR);
    END IF;

    # member
    IF EXISTS(SELECT * FROM summary_event_records WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime) THEN
        DELETE FROM summary_event_records WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime;
    END IF;
    INSERT INTO summary_event_records(
        summary_time, member_id, event_id, `point`
    )
    SELECT y.summary_time, y.member_id, EventId, y.`point`
    FROM (
             SELECT DATE_FORMAT(o.pay_time, '%Y-%m-%d %H:00:00') AS summary_time
                  , m.id AS member_id
                  -- orders.payment_cost 已是站點主幣別、建單當下渠道費率算好的成本，直接加總
                  , TRUNCATE(SUM(o.payment_cost), 6) AS `point`
             FROM orders AS o
                      JOIN members AS m
                           ON m.id = o.member_id
                      JOIN agents AS a
                           ON a.id = m.parent
             WHERE o.pay_time >= StartTime
               AND o.pay_time < EndTime
               AND o.`status` = 3
             GROUP BY summary_time, m.id HAVING `point` > 0
         ) AS y;

    # agent
    IF EXISTS(SELECT * FROM summary_event_record_agents WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime) THEN
        DELETE FROM summary_event_record_agents WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime;
    END IF;

    INSERT INTO summary_event_record_agents(
        summary_time, agent_id, event_id, `point`
    )
    SELECT y.summary_time, y.agent_id, y.event_id, y.`point`
    FROM (
             SELECT r.summary_time
                  , a.id AS agent_id
                  , r.event_id
                  , SUM(r.`point`) AS `point`
             FROM summary_event_records AS r
                      JOIN members AS m ON m.id = r.member_id
                      JOIN agents AS a ON a.id = m.parent
             WHERE summary_time >= StartTime
               AND summary_time < EndTime
               AND r.event_id = EventId
             GROUP BY r.summary_time, a.id, r.event_id
         ) AS y;

    SET ExecEnd = NOW();

    UPDATE summary_control_tables
    SET stat_start_time = StartTime,
        stat_end_time = EndTime,
        exec_start_time = ExecStart,
        exec_end_time = ExecEnd
    WHERE vendor_id = 10004;

    #寫紀錄
    INSERT INTO summary_control_logs(
        vendor_id, stat_start_time, stat_end_time, exec_start_time, exec_end_time
    )
    VALUES(10004, StartTime, EndTime, ExecStart, ExecEnd);
END
