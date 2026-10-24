CREATE PROCEDURE `SP_calc_agent_achievement`(
    IN SummaryDate DATETIME
)
BEGIN
    # SR19詳細資料計算
    # 2023-03-08 Neil ADD

    DECLARE MaxLevel, RunLevel INT;
    DECLARE StartTime DATETIME;
    DECLARE EndTime DATETIME;
    DECLARE AdminUser INT;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_agent_achievement(',
                    IFNULL(SummaryDate, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET AdminUser = 0;
    SET SummaryDate = IFNULL(SummaryDate, DATE(DATE_ADD(NOW(), INTERVAL -16 HOUR)));
    SET StartTime = DATE_ADD(SummaryDate, INTERVAL -8 HOUR);
    SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);
    SET MaxLevel = (
        SELECT MAX((CHAR_LENGTH(A.hierarchy_path) - CHAR_LENGTH(REPLACE(A.hierarchy_path, '/', ''))))
        FROM summary_bet_record_agents AS B
                 JOIN agents AS A ON A.id = B.agent_id
        WHERE B.summary_time >= StartTime
          AND B.summary_time < EndTime
    );
    SET RunLevel = MaxLevel;

    WHILE RunLevel > 1
        DO
            IF MaxLevel = RunLevel THEN
                INSERT INTO summary_agent_achievements(
                    agent_id, summary_time, vendor_id, game_type_id, total_bet,
                    total_win_lose, total_jackpot, total_share_amount,
                    total_commission, total_v_jackpot, total_cost, total_score,
                    proportion, admin
                )
                SELECT Z.agent_id
                     , Z.summary_time
                     , Z.vendor_id
                     , Z.game_type_id
                     , Z.total_bet
                     , Z.total_win_lose
                     , Z.total_jackpot
                     , Z.total_share_amount
                     , Z.total_commission
                     , Z.total_v_jackpot
                     , Z.total_cost
                     , Z.total_score
                     , (CASE WHEN Z.vendor_id = 4 THEN Z.total_bet * 0.19 ELSE Z.total_score * A.proportion * -1 END) AS proportion
                     , AdminUser
                FROM (
                         SELECT B.agent_id
                              , B.summary_time
                              , B.vendor_id
                              , B.game_type_id
                              , B.total_bet
                              , B.total_win_lose
                              , B.total_jackpot
                              , B.total_share_amount
                              , B.total_commission
                              , B.total_v_jackpot
                              , B.total_cost
                              , B.total_score
                              , (CHAR_LENGTH(A.hierarchy_path) - CHAR_LENGTH(replace(A.hierarchy_path, '/', ''))) AS hierarchy_index
                         FROM summary_bet_record_agents AS B
                                  JOIN agents AS A
                                       ON A.id = B.agent_id
                         WHERE B.summary_time >= StartTime
                           AND B.summary_time < EndTime
                     ) AS Z
                         JOIN agents AS A ON A.id = Z.agent_id
                WHERE Z.hierarchy_index = RunLevel;

                SET RunLevel = RunLevel - 1;
            ELSE
                INSERT INTO summary_agent_achievements(
                    agent_id, summary_time, vendor_id, game_type_id, total_bet,
                    total_win_lose, total_jackpot, total_share_amount,
                    total_commission, total_v_jackpot, total_cost, total_score,
                    proportion, admin
                )
                SELECT Z.agent_id
                     , Z.summary_time
                     , Z.vendor_id
                     , Z.game_type_id
                     , SUM(Z.total_bet)
                     , SUM(Z.total_win_lose)
                     , SUM(Z.total_jackpot)
                     , SUM(Z.total_share_amount)
                     , SUM(Z.total_commission)
                     , SUM(Z.total_v_jackpot)
                     , SUM(Z.total_cost)
                     , SUM(Z.total_score)
                     , (CASE WHEN Z.vendor_id = 4 THEN SUM(Z.total_bet) * 0.19 ELSE SUM(Z.total_score) * A.proportion * -1 END) AS proportion
                     , AdminUser
                FROM (
                         SELECT A.parent AS agent_id
                              , B.summary_time
                              , B.vendor_id
                              , B.game_type_id
                              , B.total_bet
                              , B.total_win_lose
                              , B.total_jackpot
                              , B.total_share_amount
                              , B.total_commission
                              , B.total_v_jackpot
                              , B.total_cost
                              , B.total_score
                              , (char_length(A.hierarchy_path) - char_length(replace(A.hierarchy_path, '/', ''))) as hierarchy_index
                         FROM summary_agent_achievements AS B
                                  JOIN agents AS A ON A.id = B.agent_id
                         WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
                           AND (char_length(A.hierarchy_path) - char_length(replace(A.hierarchy_path, '/', ''))) = RunLevel + 1
                         UNION ALL
                         SELECT C.agent_id
                              , C.summary_time
                              , C.vendor_id
                              , C.game_type_id
                              , C.total_bet
                              , C.total_win_lose
                              , C.total_jackpot
                              , C.total_share_amount
                              , C.total_commission
                              , C.total_v_jackpot
                              , C.total_cost
                              , C.total_score
                              , (char_length(A.hierarchy_path) - char_length(replace(A.hierarchy_path, '/', ''))) as hierarchy_index
                         FROM summary_bet_record_agents AS C
                                  JOIN agents AS A ON A.id = C.agent_id
                         WHERE C.summary_time >= StartTime AND C.summary_time < EndTime
                           AND (char_length(A.hierarchy_path) - char_length(replace(A.hierarchy_path, '/', ''))) = RunLevel
                     ) AS Z
                         JOIN agents AS A ON A.id = Z.agent_id
                GROUP BY Z.agent_id, Z.summary_time, Z.vendor_id, Z.game_type_id;
                SET RunLevel = RunLevel - 1;
            END IF;
        END WHILE;
END
