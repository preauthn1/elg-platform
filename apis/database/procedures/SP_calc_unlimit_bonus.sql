CREATE PROCEDURE `SP_calc_unlimit_bonus`(
    IN SummaryDate DATETIME
)
BEGIN
    # 無限代計算
    # 2020-10-05 Mars 無限代計算unlimit_systemrate依agents.cash_flow_group_id分組
    # 2020-12-17 Mars 無限代計算valid_bet_rate改用vendor_info_games
    # 2020-12-25 Mars fix bug LEFT JOIN vendor_info_games
    # 2021-05-10 Mars Add CALL SP_summary_event_record_by_unlimited(SummaryDate);
    # 2023-03-02 Neil 增加代理自身回水
    # 2025-04-15 Mars 		優化group by, keyword加上``

    DECLARE MaxLevel, AdminUser INT;
    DECLARE StartTime, EndTime DATETIME;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_unlimit_bonus(',
                    IFNULL(SummaryDate, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET AdminUser = 0;
    SET SummaryDate = IFNULL(SummaryDate, DATE(date_add(NOW(), INTERVAL -16 HOUR)));
    SET StartTime = DATE_ADD(SummaryDate, INTERVAL -8 HOUR);
    SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);
    SET MaxLevel = (SELECT MAX(agent_level) FROM member_agent);

    IF EXISTS(SELECT * FROM unlimit_bonusreport WHERE summary_date = SummaryDate) THEN
        #先扣除unlimit_summary_bonus已經計算的數據
        REPLACE INTO unlimit_summary_bonus(member_id, performance_amount, bonus_amount, withdraw_amount, created_at, updated_at, `admin`)
        SELECT br.member_id
             , IFNULL(sb.performance_amount, 0) - IFNULL(br.performance_amount, 0) AS performance_amount
             , IFNULL(sb.bonus_amount, 0) - IFNULL(br.bonus_amount, 0) AS bonus_amount
             , IFNULL(sb.withdraw_amount, 0) AS withdraw_amount
             , IFNULL(sb.flow_amount, 0) - IFNULL(br.flow_amount, 0) AS flow_amount
             , IFNULL(sb.itself_bonus_amount, 0) - IFNULL(br.itself_bonus_amount, 0) AS itself_bonus_amount
             , IFNULL(sb.itself_withdraw_amount, 0) AS itself_withdraw_amount
             , IFNULL(sb.created_at, NOW()) AS created_at
             , NOW() AS updated_at
             , AdminUser
        FROM
            (
                SELECT member_id
                     ,IFNULL(performance_direct + performance_team, 0) AS performance_amount
                     ,IFNULL(bonus_direct + bonus_team, 0) AS bonus_amount
                     ,IFNULL(performance, 0) AS flow_amount
                     ,IFNULL(bonus_itself, 0) AS itself_bonus_amount
                FROM unlimit_bonusreport
                WHERE summary_date = SummaryDate
            ) AS br
                LEFT JOIN unlimit_summary_bonus AS sb
                          ON br.member_id = sb.member_id;

        #刪除unlimit_bonusreport對應的數據
        DELETE FROM unlimit_bonusreport
        WHERE summary_date = SummaryDate;
    END IF;

    WHILE MaxLevel > 0
        DO
            INSERT INTO unlimit_bonusreport(
                member_id, summary_date, performance, performance_direct, performance_team,
                bonus_itself, bonus_direct, bonus_team, bonus_amount, created_at,
                updated_at, `admin`
            )
            SELECT B.member_id
                 , SummaryDate AS summary_date
                 , ANY_VALUE(B.performance) AS performance
                 , ANY_VALUE(B.performance_direct) AS performance_direct
                 , ANY_VALUE(B.performance_team) AS performance_team
                 , TRUNCATE(ANY_VALUE(B.bonus_itself), 4) AS bonus_itself
                 , TRUNCATE(ANY_VALUE(B.bonus_direct), 4) AS bonus_direct
                 , IFNULL(TRUNCATE(SUM((B.bonus_amount - L.bonus_amount) / 10000.00 * L.performance_team), 4), 0) AS bonus_team
                 , ANY_VALUE(B.bonus_amount) AS bonus_amount
                 , NOW() AS created_at
                 , NOW() AS updated_at
                 , AdminUser AS `admin`
            FROM (
                     SELECT D.member_id
                          , D.performance
                          , D.performance_direct
                          , D.performance_team
                          , D.performance * ACS.`value` AS bonus_itself
                          , D.performance_direct * S.bonus_amount / 10000.00 AS bonus_direct
                          , S.bonus_amount
                     FROM (
                              SELECT Z.member_id
                                   , IFNULL(SUM(Z.bet * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance
                                   , IFNULL(SUM(Z.bet_direct * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance_direct
                                   , IFNULL(SUM(Z.bet_team * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance_team
                                   , IFNULL(SUM(Z.bet_direct * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0))
                                                + SUM(Z.bet_team * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance_total
                              FROM (
                                       SELECT T.member_id
                                            , T.vendor_id
                                            , T.game_id
                                            , ANY_VALUE(T.bet) AS bet
                                            , ANY_VALUE(T.bet_direct) AS bet_direct
                                            , ANY_VALUE(T.bet_team) AS bet_team
                                            , ANY_VALUE(AV.vendor_info_id) AS vendor_info_id
                                       FROM (
                                                SELECT B.member_id
                                                     , ANY_VALUE(A.hierarchy_path) AS hierarchy_path
                                                     , B.vendor_id
                                                     , B.game_id
                                                     , SUM(B.Bet) AS bet
                                                     , SUM(B.Bet_direct) AS bet_direct
                                                     , SUM(B.Bet_team) AS bet_team
                                                FROM summary_bet_records_team AS B
                                                         JOIN member_agent AS MA ON B.member_id = MA.member_id
                                                         JOIN agents AS A ON A.id = MA.parent_id
                                                WHERE B.summary_time >= StartTime
                                                  AND B.summary_time < EndTime
                                                  AND MA.agent_level = MaxLevel
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
                                                         AND T.hierarchy_path LIKE CONCAT(AV.hierarchy_path, '%')
                                       GROUP BY T.member_id, T.vendor_id, T.game_id
                                       HAVING MAX(AV.indexs)
                                   ) AS Z
                                       JOIN vendors AS V ON V.id = Z.vendor_id
                                       LEFT JOIN vendor_info_games AS VIG
                                                 ON VIG.game_id = Z.game_id
                                                     AND VIG.vendor_info_id = Z.vendor_info_id
                              GROUP BY Z.member_id
                          ) AS D
                              JOIN members AS M ON D.member_id = M.id
                              JOIN agents AS A ON M.parent = A.id
                              LEFT JOIN agent_customized_settings AS ACS
                                        ON ACS.agent_group_id = A.customized_setting_group_id
                                            AND setting_key = '4502'
                              JOIN unlimit_systemrate AS S
                                   ON A.cash_flow_group_id = S.agent_group_id
                                       AND D.performance_total >= S.min_amount
                                       AND (D.performance_total <= S.max_amount || S.max_amount = 0)
                 ) AS B
                     LEFT JOIN (
                SELECT A.topper_member_id
                     , B.member_id
                     , B.bonus_amount
                     , (performance_direct + performance_team) AS performance_team
                FROM unlimit_bonusreport AS B
                         JOIN member_agent AS A ON B.member_id = A.member_id
                WHERE B.summary_date = SummaryDate
                  AND A.agent_level = MaxLevel + 1
            ) AS L ON L.topper_member_id = B.member_id
            GROUP BY B.member_id;

            SET MaxLevel = MaxLevel - 1;
        END WHILE;

    #無限代 代理計算
    SET MaxLevel = 1;
    IF EXISTS(SELECT * FROM unlimit_bonusreport_agents WHERE summary_date = SummaryDate) THEN
        #刪除unlimit_bonusreport_agents對應的數據
        DELETE FROM unlimit_bonusreport_agents
        WHERE summary_date = SummaryDate;
    END IF;

    INSERT INTO unlimit_bonusreport_agents(
        agent_id, summary_date, performance_direct, performance_team,
        bonus_direct, bonus_team, bonus_amount, `admin`
    )
    SELECT B.parent_id AS agent_id
         , SummaryDate AS summary_date
         , ANY_VALUE(B.performance_direct) AS performance_direct		#直屬業績
         , ANY_VALUE(B.performance_team) AS performance_team			#團隊業績(不含直屬)
         , TRUNCATE(ANY_VALUE(B.bonus_direct), 4) AS bonus_direct  	#直屬業績獎勵
         , IFNULL(TRUNCATE(SUM((B.bonus_amount - L.bonus_amount) / 10000.00 * L.performance_team), 4), 0) AS bonus_team #團隊業績獎勵
         , ANY_VALUE(B.bonus_amount) AS bonus_amount
         , AdminUser AS `admin`
    FROM (
             #直屬業績
             SELECT D.parent_id
                  , D.performance_direct
                  , D.performance_team
                  , D.performance_direct * S.bonus_amount / 10000.00 AS bonus_direct
                  , S.bonus_amount
             FROM (
                      SELECT A.parent_id
                           , IFNULL(SUM(B.performance), 0) AS performance_direct #直屬業績
                           , IFNULL(SUM(B.performance_direct) + SUM(B.performance_team), 0) AS performance_team #團隊業績(不含直屬)
                           , IFNULL(SUM(B.performance) + SUM(B.performance_direct) + SUM(B.performance_team), 0) AS performance_total #全部業績
                      FROM unlimit_bonusreport AS B
                               JOIN member_agent AS A ON B.member_id = A.member_id
                      WHERE B.summary_date = SummaryDate
                        AND A.agent_level = MaxLevel
                      GROUP BY A.parent_id
                  ) AS D
                      JOIN agents AS A ON D.parent_id = A.id
                      JOIN unlimit_systemrate AS S
                           ON A.cash_flow_group_id = S.agent_group_id
                               AND D.performance_total >= S.min_amount
                               AND (D.performance_total <= S.max_amount || S.max_amount = 0)
         ) AS B
             LEFT JOIN (
        SELECT A.parent_id,B.bonus_amount,(performance_direct + performance_team) AS performance_team
        FROM unlimit_bonusreport AS B
                 JOIN member_agent AS A
                      ON B.member_id = A.member_id
        WHERE B.summary_date = SummaryDate
          AND A.agent_level = MaxLevel
    ) AS L ON L.parent_id = B.parent_id
    GROUP BY B.parent_id;

    # 彙整全民代理獎勵到summary_event_record
    CALL SP_summary_event_record_by_unlimited(SummaryDate);
END
