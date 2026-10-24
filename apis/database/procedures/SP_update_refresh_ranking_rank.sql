CREATE PROCEDURE `SP_update_refresh_ranking_rank`(
    IN p_event_refresh_ranking_id INT
)
BEGIN

    # 2020-09-03 Mars 			刷新排行榜統計SP

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_update_refresh_ranking_rank(',
                    IFNULL(p_event_refresh_ranking_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    #名次重新排名
    UPDATE event_refresh_ranking_records
    SET ranking = 0
    WHERE event_refresh_ranking_id = p_event_refresh_ranking_id
      AND ranking != 0;

    INSERT INTO event_refresh_ranking_records (event_refresh_ranking_id, member_id, ranking)
    SELECT p_event_refresh_ranking_id, z.member_id, z.`rank`
    FROM (
             SELECT member_id, @rownum := @rownum + 1 AS `rank`
             FROM event_refresh_ranking_records AS l, (SELECT @rownum := 0) r
             WHERE event_refresh_ranking_id = p_event_refresh_ranking_id
               AND is_calc = 1
             ORDER BY bet_amount DESC, last_bet_time ASC
         ) AS z
    ON DUPLICATE KEY UPDATE
        ranking = `rank`;
END
