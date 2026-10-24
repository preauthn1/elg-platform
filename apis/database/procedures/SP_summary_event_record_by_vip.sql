CREATE PROCEDURE `SP_summary_event_record_by_vip`(
    IN p_StartTime DATETIME,
    IN p_EndTime DATETIME
)
BEGIN

    # ⛔ 已作廢（2026-09-24，target/tickets#4420）：VIP 獎勵彙總改由 PHP 排程產生，請改用
    #    app/Services/Summary/VipRewardSummaryService.php（每小時排程與回補共用同一支命令
    #    summary:event-record-vip，回補加 --from／--to）。本 SP 把會員彙總與代理彙總分兩段裸跑，
    #    中途失敗時 EXIT HANDLER 吞掉例外又不回滾，而視窗每小時只前進 1 小時，失敗那班若已是最後
    #    一班涵蓋該格的，那一格就永久空掉：TPower 近 30 天漏 22 個小時、合計 4,655.0968。
    #    各站對應的 EVENT Event_Summary_event_reocrd_by_vip 已 DISABLE，本 SP 不再被呼叫，
    #    兩個寫入者不可並存。保留定義只為回滾（重建 EVENT），觀察期後移除檔案並 DROP PROCEDURE。

    # 彙整VIP獎勵

    # 一般呼叫方式: CALL SP_summary_event_record_by_vip(NULL, NULL);
    # 重算呼叫方式: CALL SP_summary_event_record_by_vip('2020-05-30 10:00:00', '2020-05-30 12:00:00');
    /*
    新增Event
	CREATE EVENT Event_Summary_event_reocrd_by_vip
		ON SCHEDULE EVERY 1 HOUR STARTS '2026-04-02 00:00:00' #系統時間UTC+0
		COMMENT '每小時-彙整VIP獎勵'
		DO
			CALL SP_summary_event_record_by_vip(NULL, NULL);
    */
    DECLARE EventId INT DEFAULT 9;
    DECLARE VipRewardType INT DEFAULT 32;
    DECLARE ExecStart, ExecEnd, EndTime, StartTime DATETIME;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_event_record_by_sms(',
                    IFNULL(p_StartTime, 'NULL'), ',',
                    IFNULL(p_EndTime, 'NULL'),
                    ') 異常 ', @p2),
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

    INSERT INTO summary_event_records(summary_time, member_id, event_id, `point`)
    SELECT y.summary_time, y.member_id, EventId, y.`point`
    FROM (
		SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS summary_time, member_id, SUM(`point`) AS `point`
		FROM transaction_logs
		WHERE created_at >= StartTime AND created_at < EndTime AND `type` = VipRewardType
		GROUP BY member_id, summary_time
	) AS y;

    # agent
    IF EXISTS(
        SELECT * FROM summary_event_record_agents WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime) THEN
        DELETE FROM summary_event_record_agents WHERE event_id = EventId AND summary_time >= StartTime AND summary_time < EndTime;
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
		WHERE summary_time >= StartTime AND summary_time < EndTime AND r.event_id = EventId
		GROUP BY r.summary_time, a.id, r.event_id
	) AS y;

    SET ExecEnd = NOW();

    UPDATE summary_control_tables
    SET stat_start_time = StartTime,
        stat_end_time = EndTime,
        exec_start_time = ExecStart,
        exec_end_time = ExecEnd
    WHERE vendor_id = 10007;

    #寫紀錄
    INSERT INTO summary_control_logs(
        vendor_id, stat_start_time, stat_end_time, exec_start_time, exec_end_time
    )
    VALUES(10007, StartTime, EndTime, ExecStart, ExecEnd);
END