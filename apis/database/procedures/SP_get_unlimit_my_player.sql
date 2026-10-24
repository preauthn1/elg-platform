CREATE PROCEDURE `SP_get_unlimit_my_player`(
    IN p_memberid INT,
    IN p_starttime DATETIME,
    IN p_endtime DATETIME
)
BEGIN

    # 我的推廣
    # 2020-12-17 Mars 			無限代轉換比例從games.valid_bet_rate改為使用vendor_info_games.valid_bet_rate
    # 2021-01-26 Nars 			SLOW LOG Add U.parent_id = L.parent_id AND
    # 2025-04-15 Mars 			優化group by, keyword加上``

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_get_unlimit_my_player(',
                    IFNULL(p_memberid, 'NULL'), ',',
                    IFNULL(p_starttime, 'NULL'), ',',
                    IFNULL(p_endtime, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SELECT MA.member_id
         , M.username AS member
         , B.TeamBet AS teamContribution
         , B.SelfBet AS memberContribution
         , MA.TeamCount AS teamCount
         , MA.DirectCount AS memberCount
         , CASE WHEN MA.TeamCount > 0 THEN 2 ELSE 1 END memberType
    FROM (
             SELECT U.member_id
                  , SUM(CASE WHEN L.member_id != U.member_id THEN 1 ELSE 0 END) AS TeamCount
                  , SUM(CASE WHEN L.topper_member_id = U.member_id THEN 1 ELSE 0 END) AS DirectCount
             FROM member_agent AS U
                      LEFT JOIN member_agent AS L
                                ON U.parent_id = L.parent_id
                                    AND L.agent_relation LIKE CONCAT('%,',U.member_id,',%')
             WHERE U.topper_member_id = p_memberid
             GROUP BY U.member_id
         ) AS MA
             LEFT JOIN (
        SELECT Z.member_id
             , IFNULL(SUM(Z.bet * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS SelfBet
             , IFNULL(SUM(Z.bet_direct * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0))
                          + SUM(Z.bet_team * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS TeamBet
        FROM (
                 SELECT T.member_id, T.vendor_id, T.game_id, T.bet, T.bet_direct, T.bet_team, AV.vendor_info_id
                 FROM (
                          SELECT B.member_id
                               , ANY_VALUE(A.hierarchy_path) AS hierarchy_path
                               , B.vendor_id
                               , B.game_id
                               , SUM(B.Bet) AS bet
                               , SUM(B.Bet_direct) AS bet_direct
                               , SUM(B.Bet_team) AS bet_team
                          FROM member_agent AS MA
                                   JOIN summary_bet_records_team AS B
                                        ON B.member_id = MA.member_id
                                   JOIN agents AS A
                                        ON A.id = MA.parent_id
                          WHERE B.summary_time >= p_starttime
                            AND B.summary_time <= p_endtime
                            AND MA.topper_member_id = p_memberid
                          GROUP BY B.member_id, B.vendor_id, B.game_id
                      ) AS T
                          JOIN (
                     SELECT av.agent_id
                          , av.vendor_id
                          , a.hierarchy_path
                          , av.vendor_info_id
                          , LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path) AS indexs
                     FROM agent_vendors AS av
                              JOIN agents AS a
                                   ON a.id = av.agent_id
                                       AND a.host_id = 0
                     WHERE av.`status` = 1
                 ) AS AV
                               ON AV.vendor_id = T.vendor_id
                                   AND T.hierarchy_path LIKE concat(AV.hierarchy_path, '%')
                 GROUP BY T.member_id, T.game_id HAVING MAX(AV.indexs)
             ) AS Z
                 JOIN vendors AS V ON V.id = Z.vendor_id
                 LEFT JOIN vendor_info_games AS VIG
                           ON VIG.game_id = Z.game_id
                               AND VIG.vendor_info_id = Z.vendor_info_id
        GROUP BY Z.member_id
    ) AS B ON MA.member_id = B.member_id
             JOIN members AS M
                  ON M.id = MA.member_id;
END
