CREATE PROCEDURE `SP_summary_event_sub_bet`(
    IN p_event_id INT,
    IN p_event_detail_id INT,
    IN p_action_id INT,
    IN p_order_type_id INT,
    IN p_vendor_id INT,
    IN p_game_type_id INT,
    IN p_game_id INT,
    IN p_join_event_used INT,
    IN p_rate DECIMAL(8, 4),
    IN p_point DECIMAL(18, 4),
    IN p_event_start_time DATETIME,
    IN p_event_end_time DATETIME,
    IN p_agent_id INT
)
BEGIN
    # 活動獎勵派發 - bet
    # 2022-02-08 Mars 		ADD
    # 2025-04-15 Mars 		優化group by, keyword加上``

    DECLARE AgentHierarchyPath VARCHAR(50) DEFAULT (SELECT hierarchy_path FROM agents WHERE id = p_agent_id);
    DECLARE VendorId INT DEFAULT p_vendor_id;
    DECLARE GameTypeId INT DEFAULT p_game_type_id;
    DECLARE JoinEventUsed INT DEFAULT p_join_event_used;
    DECLARE EventStartTime DATETIME DEFAULT p_event_start_time;
    DECLARE EventEndTime DATETIME DEFAULT p_event_end_time;
    DECLARE CurMemberId, CurMemberStatus INT;
    DECLARE CurMemberPoint DECIMAL(18, 4);
    DECLARE Done, Continues INT DEFAULT 0;

    DECLARE Cur CURSOR FOR
        SELECT t.member_id, SUM(t.bet) AS `point`
        FROM (
                 SELECT r.member_id
                      , r.game_id
                      , SUM(r.bet) AS bet
                      , av.vendor_info_id
                 FROM (
                          # 只統計有參加活動的會員
                          SELECT m.id AS member_id
                               , ANY_VALUE(a.id) AS agent_id
                               , v.id AS vendor_id
                               , r.game_id
                               , ANY_VALUE(a.hierarchy_path) AS hierarchy_path
                               , SUM(r.bet) AS bet
                          FROM vendor_hour_records AS r
                                   JOIN members AS m ON m.id = r.member_id
                                   JOIN agents AS a
                                        ON a.id = m.parent
                                            AND a.hierarchy_path LIKE CONCAT(AgentHierarchyPath, '%')
                                   JOIN vendors AS v
                                        ON v.id = r.vendor_id
                                            # 排除豪龍
                                            AND (JoinEventUsed = 1 OR (JoinEventUsed = 0 AND v.valid_bet_rate > 0))
                                            # 舊資料沒有GAME ID，但是是有統計過的，所以可以直接加入
                                            AND (VendorId = 0 OR (VendorId > 0 AND v.id = VendorId))
                                   LEFT JOIN games AS g
                                             ON g.id = r.game_id
                                                 AND (GameTypeId = 0 OR (GameTypeId > 0 AND g.type_id = GameTypeId))
                          WHERE r.date_hour BETWEEN EventStartTime AND EventEndTime
                            AND (JoinEventUsed = 0 OR (JoinEventUsed = 1 AND g.id IS NOT NULL))
                          GROUP BY m.id, v.id, r.game_id
                          HAVING bet > 0
                      ) AS r
                          JOIN (
                     SELECT av.vendor_id
                          , MIN(a.hierarchy_path) AS hierarchy_path
                          , av.vendor_info_id
                          , MIN(LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path)) AS indexs
                     FROM agent_vendors AS av
                              JOIN vendor_infos as vi
                                   ON vi.id = av.vendor_info_id
                                       AND vi.`status` = 1
                              JOIN agents AS a
                                   ON a.id = av.agent_id
                                       AND a.host_id = 0
                                       AND a.hierarchy_path LIKE CONCAT(AgentHierarchyPath, '%')
                     WHERE av.status = 1
                     GROUP BY av.vendor_id, av.vendor_info_id
                 ) AS av ON av.vendor_id = r.vendor_id
                          LEFT JOIN vendor_info_games AS vig
                                    ON vig.game_id = r.game_id
                                        AND vig.vendor_info_id = av.vendor_info_id
                 WHERE (
                     (JoinEventUsed = 1 AND vig.join_bet_event = 1) OR
                         # 排除對壓遊戲
                     (JoinEventUsed = 0 AND (vig.valid_bet_rate IS NULL OR vig.valid_bet_rate > 0))
                     )
                   AND r.hierarchy_path LIKE CONCAT(av.hierarchy_path, '%')
                 GROUP BY r.member_id, r.game_id, av.vendor_info_id
             ) AS t
        GROUP BY t.member_id
        HAVING `point` > 0;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
    REPEAT
        FETCH Cur
            INTO CurMemberId, CurMemberPoint;
        IF NOT Done THEN
            IF CurMemberPoint >= p_point THEN
                SET CurMemberStatus = 1;
            ELSE
                SET CurMemberStatus = 0;
            END IF;

            CALL SP_summary_event_add_process(
                CurMemberId,
                p_event_detail_id,
                CurMemberPoint,
                CurMemberStatus
                 );

        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;
END
