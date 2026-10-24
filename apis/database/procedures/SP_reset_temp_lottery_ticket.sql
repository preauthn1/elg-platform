CREATE PROCEDURE `SP_reset_temp_lottery_ticket`(
    IN p_event_id INT
)
BEGIN
    # 重置V-Jackpot上期獎券
    # 2022-03-29 Mars 		ADD

    /*
    新增Event
    CREATE EVENT Event_SP_reset_temp_lottery_ticket
	ON SCHEDULE EVERY 1 WEEK STARTS '2022/04/10 19:00:00' #系統時間UTC+0
    COMMENT '每週一-重置v-jackpot彩卷'
    DO
		CALL SP_reset_temp_lottery_ticket(0);
	*/

    DECLARE EventId, EventDetailId, LuckyDrawId, LuckyDrawNo, WeeksOfUse, EventStatus, ExecuteNum INT DEFAULT 0;
    DECLARE TypeId INT DEFAULT 5; #V-Jackpot type id
    DECLARE Done INT DEFAULT 0;
    DECLARE CheckTime DATETIME DEFAULT DATE_SUB(NOW(), INTERVAL 7 DAY);
    DECLARE LimitRow, RowCount INT DEFAULT 50000;	# 限制筆數 / 影響筆數
    DECLARE StopSeconds INT DEFAULT 2;		# 暫停秒數

    DECLARE ExecStart DATETIME DEFAULT NOW();
    DECLARE ExecEnd, ExecSubStart, ExecSubEnd  DATETIME;
    DECLARE VendorId INT DEFAULT 12001;
    DECLARE VendorCode VARCHAR(50) DEFAULT 'reset_temp_lottery_ticket';

    #撈出v-jackpot上期開獎ID & 期數
    DECLARE Cur CURSOR FOR
        SELECT e.id, (2 - MOD(ld.no, 2)) AS no, ed.id, ld.id, ld.no
        FROM events AS e
                 JOIN event_details AS ed
                      ON ed.event_id = e.id
                          AND ed.status = 1
                          AND ed.game_join_event_used = 0
                          AND ed.start_time <= CheckTime
                          AND ed.end_time >= CheckTime
                 JOIN prizes AS p
                      ON p.event_id = e.id
                          AND p.type_id = 5
                          AND p.status = 1
                 JOIN lucky_draws AS ld
                      ON ld.event_id = e.id
                          AND ld.event_detail_id = ed.id
        #AND ld.status = 1
        WHERE e.status = 1
          AND (p_event_id = 0 OR e.id = p_event_id)
        ORDER BY ed.id DESC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
    REPEAT
        FETCH Cur
            INTO EventId, WeeksOfUse, EventDetailId, LuckyDrawId, LuckyDrawNo;
        IF NOT Done THEN
            SELECT 'Start', EventId, WeeksOfUse, EventDetailId, LuckyDrawId, LuckyDrawNo;
            SET ExecSubStart = NOW();
            WHILE RowCount != LimitRow DO
                    #計算執行次數
                    SET ExecuteNum = ExecuteNum + 1;

                    UPDATE temp_lottery_tickets
                    SET sort = FLOOR(RAND() * 10000000), status = 0
                    WHERE event_id = EventId
                      AND weeks_of_use = WeeksOfUse
                      AND status = 0;

                    # 取得影響筆數
                    SET RowCount = ROW_COUNT();

                    # 暫停
                    IF RowCount = LimitRow THEN
                        SELECT ExecuteNum, RowCount, SLEEP(StopSeconds);
                    END IF;
                END WHILE;

            # 全部status改為0
            UPDATE temp_lottery_tickets
            SET status = 0
            WHERE event_id = EventId
              AND weeks_of_use = WeeksOfUse;

            SET ExecSubEnd = NOW();
            #寫紀錄
            INSERT INTO summary_control_logs
            (vendor_id, params, exec_start_time, exec_end_time)
            VALUES
                (VendorId,
                 CONCAT(
                     'event_id:', EventId,
                     ', detail_id:', EventDetailId,
                     ', lucky_draw_id:', LuckyDrawId,
                     ', lucky_draw_no:', LuckyDrawNo,
                     ', weeks_of_use:', WeeksOfUse
                 ),
                 ExecSubStart, ExecSubEnd);

            SELECT 'End', ExecSubStart, ExecSubEnd, (ExecSubEnd - ExecSubStart);
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;

    SET ExecEnd = NOW();

    #更新控制表
    UPDATE summary_control_tables
    SET stat_start_id = EventId
      , stat_end_id = WeeksOfUse
      , exec_start_time = ExecStart
      , exec_end_time = ExecEnd
    WHERE vendor_id = VendorId;
END
