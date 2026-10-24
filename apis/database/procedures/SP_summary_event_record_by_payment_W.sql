CREATE PROCEDURE `SP_summary_event_record_by_payment_W`(
    IN p_AgentUsername VARCHAR(50),
    IN p_Date DATE
)
BEGIN

    # ⛔ 已作廢（2026-09-17，target/tickets#4369）：代付支付成本彙總改由 PHP 排程產生，請改用
    #    app/Services/Summary/PaymentCostSummaryService.php（每小時排程 summary:payment-cost、
    #    重算 order:recalc-payment-cost-summary）。各站對應 EVENT 已 DISABLE，本 SP 不再被呼叫；
    #    保留定義只為回滾（重建 EVENT），觀察期後移除檔案並 DROP PROCEDURE。

    # 彙整代付成功成本計算

    # 一般呼叫方式: CALL SP_summary_event_record_by_payment_W(NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_payment_W('2020-05-30', '2020-05-30');
    /*
    新增Event
	CREATE EVENT Event_Summary_event_reocrd_by_payment_W
		ON SCHEDULE EVERY 1 DAY STARTS '2020-01-01 00:00:00' #系統時間UTC+0
		COMMENT '每天 - 彙整代付成本'
		DO
			CALL SP_summary_event_record_by_payment_W('yes2win', NULL);
    */

    # 2026-01-27 Mars 		ADD
    # 2026-09-16 Tim 		成本改直接加總 withdrawal_orders.payment_cost（派款成功時依實際出款渠道凍結，target/tickets#4361），
    #                   不再 JOIN agent_cash_flows 現算；人工出款（status 4）的成本由 API_Agent 後台以手動渠道寫入。
    #                   上線前需以 order:backfill-payment-cost 回補歷史單，否則舊單成本為 0。

    DECLARE EventId INT DEFAULT 8;
    DECLARE Yestoday DATE 
		DEFAULT IFNULL(p_Date, DATE_SUB(CURDATE(), INTERVAL 1 DAY));
	DECLARE IncreaseHour INT DEFAULT 0;
    DECLARE AgentUsername VARCHAR(50) 
		DEFAULT IFNULL(p_AgentUsername, 'admin');
	DECLARE HierarchyPath VARCHAR(500);
	DECLARE AgentId INT DEFAULT 0;
    DECLARE ExecStart, ExecEnd, SummaryTime, StartTime, EndTime DATETIME;
	DECLARE ExecuteNum INT DEFAULT 0;
    DECLARE LimitRow, RowCount, RowCount2 INT DEFAULT 50000;	# 限制筆數 / 影響筆數
    DECLARE StopSeconds INT DEFAULT 2;		# 暫停秒數

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
                    '[系統通知] SP_summary_event_record_by_payment_W(',
                    IFNULL(p_AgentUsername, 'NULL'), ',',
                    IFNULL(p_Date, 'NULL'),
                    ') 異常 ',
                    ' code:' , @p1,
                    ' msg:' , @p2,
                    ' state:' , @p3),
                'db'
                 );
        END;

    SET ExecStart = NOW();

	SET SummaryTime = DATE_SUB(Yestoday, INTERVAL 8 HOUR);
    
    SELECT id, hierarchy_path
	INTO AgentId, HierarchyPath
	FROM agents 
    WHERE username = AgentUsername;
    
	# 當刪除筆數等於影響筆數, 就繼續刪
	WHILE IncreaseHour < 24 DO
		
        SET StartTime = SummaryTime;
        SET EndTime = DATE_ADD(StartTime, INTERVAL 1 HOUR);
        
		# member
		IF EXISTS(
			SELECT * FROM summary_event_records 
            WHERE event_id = EventId 
            AND summary_time = SummaryTime 
		) THEN
			SELECT * FROM summary_event_records 
            WHERE event_id = EventId 
            AND summary_time = SummaryTime;
		END IF;
        
        INSERT INTO summary_event_records(summary_time, member_id, event_id, `point`)    
		SELECT SummaryTime
			, z.member_id
			, EventId as event_id
			-- withdrawal_orders.payment_cost 已依實際出款渠道算好，直接加總
			, CAST(SUM(z.payment_cost) AS DECIMAL(18, 4)) AS payment_cost
		FROM (
			SELECT m.id as member_id
				, wo.payment_cost
			FROM withdrawal_orders AS wo
			JOIN members AS m ON m.id = wo.member_id 
			JOIN agents AS a 
				ON a.id = m.parent 
				AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
			LEFT JOIN withdrawal_payout_records AS wpr 
				ON wpr.withdrawal_order_id = wo.id
				AND wpr.is_success_payout = 1
			WHERE (
				(wpr.callback_time >= StartTime AND wpr.callback_time < EndTime AND wo.status = 24)
				OR 
				(wo.payment_time >= StartTime AND wo.payment_time < EndTime AND wo.status = 4)
			)
		) AS z
		GROUP BY z.member_id
		HAVING payment_cost > 0
		ORDER BY z.member_id;
    
        # 取得影響筆數
		SET RowCount = ROW_COUNT();
    
		# agent
		IF EXISTS(
			SELECT * FROM summary_event_record_agents 
            WHERE event_id = EventId 
            AND summary_time = SummaryTime 
		) THEN
			SELECT * FROM summary_event_record_agents 
            WHERE event_id = EventId 
            AND summary_time = SummaryTime;
		END IF;
        
        INSERT INTO summary_event_record_agents(summary_time, agent_id, event_id, `point`)
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
    
        # 取得影響筆數
		SET RowCount2 = ROW_COUNT();
        
		# 暫停
		SELECT Yestoday, SummaryTime
			, AgentId, AgentUsername, HierarchyPath
			, ExecuteNum, RowCount, RowCount2, SLEEP(StopSeconds);
        
        SET IncreaseHour = IncreaseHour + 1;
		SET SummaryTime = DATE_ADD(SummaryTime, INTERVAL 1 HOUR);
	END WHILE;
    
    
    SET ExecEnd = NOW();
    
    UPDATE summary_control_tables
    SET stat_start_time = SummaryTime,
        stat_end_time = EndTime,
        exec_start_time = ExecStart,
        exec_end_time = ExecEnd
    WHERE vendor_id = 10008;

    #寫紀錄
    INSERT INTO summary_control_logs(
        vendor_id, stat_start_time, stat_end_time, exec_start_time, exec_end_time
    )
    VALUES(10008, SummaryTime, EndTime, ExecStart, ExecEnd);
    
	CALL COM_Send_Error_Message(
       CONCAT(
           '[系統通知] SP_summary_event_record_by_payment_W(',
           IFNULL(p_AgentUsername, 'NULL'), ',',
           IFNULL(p_Date, 'NULL'),
           ') 計算完畢 ',
           ExecStart, ' ~ ', ExecEnd),
       'db'
	);
END