CREATE PROCEDURE `SP_calc_refresh_ranking`(
    IN p_event_id INT,
    IN p_event_detail_id INT
)
BEGIN

    # 2024-10-15 Neil 			刷新排行榜統計SP

    DECLARE Now DATETIME DEFAULT NOW();
    DECLARE EventId, EventDetailId INT DEFAULT 0;
    DECLARE RefreshRankingId, RefreshRankingNo INT DEFAULT 0;
    DECLARE StartTime datetime;
    DECLARE EndTime datetime;
    DECLARE p_ExecStart datetime;
    DECLARE p_ExecEnd datetime;
    DECLARE p_VendorID INT;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_refresh_ranking(',
                    IFNULL(p_event_id, 'NULL'), ',',
                    IFNULL(p_event_detail_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET p_VendorID = 14001;
    SET p_ExecStart = NOW();
    SET SQL_SAFE_UPDATES = 0;

    SELECT err.id, err.no, ed.start_time, ed.end_time
    INTO RefreshRankingId, RefreshRankingNo, StartTime, EndTime
    FROM event_refresh_rankings AS err
             JOIN event_details AS ed ON ed.id = err.event_detail_id AND  ed.id = p_event_detail_id
    WHERE err.event_id = p_event_id;

    #名次重新排名
    UPDATE event_refresh_ranking_records
    SET ranking = 0
    WHERE event_refresh_ranking_id = RefreshRankingId
      AND ranking != 0;

    INSERT INTO event_refresh_ranking_records(
        event_refresh_ranking_id, member_id, bet_amount,
        last_bet_time
    )
    SELECT RefreshRankingId, z.member_id, z.point, z.last_bet_time
    FROM (
             SELECT p.member_id
                  , p.point
                  , p.last_bet_time
             FROM (
                      SELECT member_id, SUM(valid_bet) AS point, MAX(bet_time) AS last_bet_time
                      FROM event_total_bet_records
                      WHERE summary_time >= StartTime AND summary_time < EndTime
                      GROUP BY member_id
                  ) AS p
         ) AS z
    ON DUPLICATE KEY UPDATE
                         bet_amount = z.point,
                         last_bet_time = z.last_bet_time;

    #刷新排名
    CALL SP_update_refresh_ranking_rank(RefreshRankingId);

    SET p_ExecEnd = NOW();

    #更新控制表
    UPDATE summary_control_tables
    SET stat_start_id = p_event_id
      , stat_end_id = p_event_detail_id
      , exec_start_time = p_ExecStart
      , exec_end_time = p_ExecEnd
    WHERE vendor_id = p_VendorID;

    #寫紀錄
    INSERT INTO summary_control_logs(vendor_id,params,exec_start_time,exec_end_time)
    VALUES(p_VendorID, CONCAT('EventId:', p_event_id, ' DetailId:', p_event_detail_id), p_ExecStart, p_ExecEnd);
END
