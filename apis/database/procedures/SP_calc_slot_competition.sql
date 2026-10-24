CREATE PROCEDURE `SP_calc_slot_competition`(
    IN p_event_id INT,
    IN p_event_detail_id INT
)
BEGIN

    # 2020-09-03 Mars 			老虎機爭霸賽統計SP

    DECLARE Now DATETIME DEFAULT NOW();
    DECLARE EventId, EventDetailId INT DEFAULT 0;
    DECLARE SlotCompetitionId, SlotCompetitionNo INT DEFAULT 0;
    DECLARE p_ExecStart DATETIME;
    DECLARE p_ExecEnd DATETIME;
    DECLARE p_VendorID INT;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_slot_competition(',
                    IFNULL(p_event_id, 'NULL'), ',',
                    IFNULL(p_event_detail_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET p_VendorID = 13001;
    SET p_ExecStart = NOW();

    SELECT id, `no`
    INTO SlotCompetitionId, SlotCompetitionNo
    FROM event_slot_competitions
    WHERE event_id = p_event_id
      AND event_detail_id = p_event_detail_id;

    #名次重新排名
    UPDATE event_slot_competition_records
    SET ranking = 0
    WHERE event_slot_competition_id = SlotCompetitionId
      AND ranking != 0;

    INSERT INTO event_slot_competition_records(
        event_slot_competition_id, member_id, bet_amount,
        last_bet_time, ranking
    )
    SELECT SlotCompetitionId, z.member_id, z.`point`, z.last_bet_time, z.`rank`
    FROM (
             SELECT p.member_id
                  , p.`point`
                  , p.last_bet_time
                  , @rownum := @rownum + 1 AS `rank`
             FROM (
                      SELECT member_id, `point`, MAX(last_bet_time) AS last_bet_time
                      FROM event_processes
                      WHERE detail_id = p_event_detail_id
                      GROUP BY member_id
                      ORDER BY `point` DESC, last_bet_time ASC
                  ) AS p,
                  (SELECT @rownum := 0) r
         ) AS z
    ON DUPLICATE KEY UPDATE
                         bet_amount = z.`point`,
                         last_bet_time = z.last_bet_time,
                         ranking = z.`rank`;

    SET p_ExecEnd = NOW();

    #更新控制表
    UPDATE summary_control_tables
    SET stat_start_id = p_event_id
      , stat_end_id = p_event_detail_id
      , exec_start_time = p_ExecStart
      , exec_end_time = p_ExecEnd
    WHERE vendor_id = p_VendorID;

    #寫紀錄
    INSERT INTO summary_control_logs(vendor_id, params, exec_start_time, exec_end_time)
    VALUES(p_VendorID, CONCAT('EventId:', p_event_id, ' DetailId:', p_event_detail_id), p_ExecStart, p_ExecEnd);
END
