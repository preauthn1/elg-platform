CREATE PROCEDURE `SP_calc_three_hierarchy_bonus_by_win_lose`(
    IN p_SummaryTime DATETIME
)
BEGIN
    # 2020-04-30 Neil 		issues_1546 [GFF]新增三層代理(含代理版本)
    # 2020-05-21 Neil 		issues_1366 [GFF]新增三層代理(不含代理版本)

    DECLARE MaxLevel INT;
    DECLARE StartTime DATETIME;
    DECLARE EndTime DATETIME;
    DECLARE SummaryTime DATETIME;
    DECLARE SettlementDate DATETIME;
    DECLARE AdminUser INT DEFAULT 0;
    DECLARE AgentModeSettingId INT DEFAULT 7001;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_three_hierarchy_bonus_by_win_lose(',
                    IFNULL(p_SummaryTime, 'NULL'), ',',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET SummaryTime = IFNULL(p_SummaryTime, DATE(DATE_SUB(NOW(), INTERVAL 16 HOUR)));
    SET StartTime = DATE_SUB(SummaryTime, INTERVAL 8 HOUR);
    SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);
    SET SettlementDate = DATE(DATE_ADD(StartTime, INTERVAL 8 HOUR));
    SET MaxLevel = (SELECT MAX(agent_level) FROM member_agent);

    #判斷是否已經統計過，有則先進行刪除
    IF EXISTS(SELECT * FROM three_hierarchy_bonus_report WHERE summary_time = SummaryTime AND rebate_type = 2) THEN
        #先扣除three_hierarchy_summary_bonus已經計算的數據
        REPLACE INTO three_hierarchy_summary_bonus(member_id, rebate_amount, down_rebate_amount, down2_rebate_amount
                                                  , bonus_amount, withdraw_amount, created_at, updated_at, admin)
        SELECT br.member_id
             ,ifnull(sb.rebate_amount,0) - ifnull(br.rebate_amount,0) AS rebate_amount
             ,ifnull(sb.down_rebate_amount,0) - ifnull(br.down_rebate_amount,0) AS down_rebate_amount
             ,ifnull(sb.down2_rebate_amount,0) - ifnull(br.down2_rebate_amount,0) AS down2_rebate_amount
             , IFNULL(sb.bonus_amount, 0) - IFNULL(br.bonus_amount, 0) AS bonus_amount
             , IFNULL(sb.withdraw_amount, 0) AS withdraw_amount
             , IFNULL(sb.created_at, NOW()) AS created_at
             , NOW() AS updated_at
             , AdminUser
        FROM
            (
                SELECT member_id
                     ,SUM(rebate) AS rebate_amount
                     ,SUM(down_rebate) AS down_rebate_amount
                     ,SUM(down2_rebate) AS down2_rebate_amount
                     ,SUM(rebate + down_rebate + down_rebate) AS bonus_amount
                FROM three_hierarchy_bonus_report
                WHERE summary_time = SummaryTime AND agent_id = 0 AND rebate_type = 2
                GROUP BY member_id
            ) AS br
                LEFT JOIN three_hierarchy_summary_bonus AS sb ON br.member_id = sb.member_id;

        #刪除unlimit_bonusreport對應的數據
        DELETE FROM three_hierarchy_bonus_report
        WHERE summary_time = SummaryTime AND rebate_type = 2;
    END IF;

    WHILE MaxLevel > 0
        DO
            IF MaxLevel > 2 THEN
                INSERT INTO three_hierarchy_bonus_report(summary_time, rebate_type, reward_type, agent_id, member_id, from_member_id, vendor_id, game_type_id
                                                        , bet, down_bet, down2_bet, rebate, down_rebate, down2_rebate, admin)
                SELECT SummaryTime,
                       2,
                       Z.reward_type,
                       Z.agent_id,
                       Z.member_id,
                       Z.this_member,
                       Z.vendor_id,
                       G.type_id,
                       IFNULL(SUM(Z.bet), 0)  AS bet,
                       IFNULL(SUM(Z.down_bet), 0) AS down_bet,
                       IFNULL(SUM(Z.down2_bet), 0) AS down2_bet,
                       IFNULL(SUM(Z.bet * IFNULL(THP.proportion_1, 0)), 0)  AS rebate,
                       IFNULL(SUM(Z.down_bet * IFNULL(THP.proportion_2, 0)), 0) AS down_rebate,
                       IFNULL(SUM(Z.down2_bet * IFNULL(THP.proportion_3, 0)), 0) AS down2_rebate,
                       AdminUser AS admin
                FROM (
                         SELECT ACS2.`value` AS reward_type,
                                T.agent_id,
                                T.member_id,
                                T.this_member,
                                T.vendor_id,
                                T.game_id,
                                A.vendor_customize_group_id,
                                (T.bet * -1) AS bet,
                                (T.down_bet * -1) AS down_bet,
                                (T.down2_bet * -1) AS down2_bet
                         FROM (
                                  SELECT 0 AS agent_id,
                                         S.member_id AS member_id,
                                         B.vendor_id AS vendor_id,
                                         B.game_id AS game_id,
                                         SUM(B.win_lose) AS bet,
                                         0 AS down_bet,
                                         0 AS down2_bet,
                                         S.member_id AS this_member
                                  FROM summary_bet_records AS B
                                           JOIN (
                                      SELECT member_id
                                      FROM member_agent
                                      WHERE agent_level = MaxLevel
                                  ) AS S ON S.member_id = B.member_id
                                  WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
                                  GROUP BY this_member, vendor_id, game_id
                                  UNION
                                  SELECT 0 AS agent_id,
                                         S.topper_member_id AS member_id,
                                         B.vendor_id AS vendor_id,
                                         B.game_id AS game_id,
                                         0 AS bet,
                                         SUM(B.win_lose) AS down_bet,
                                         0 AS down2_bet,
                                         S.member_id AS this_member
                                  FROM summary_bet_records AS B
                                           JOIN (
                                      SELECT topper_member_id,
                                             member_id
                                      FROM member_agent
                                      WHERE agent_level = MaxLevel
                                  ) AS S ON S.member_id = B.member_id
                                  WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
                                  GROUP BY this_member, vendor_id, game_id
                                  UNION
                                  SELECT 0 AS agent_id,
                                         S.topper2_member_id AS member_id,
                                         B.vendor_id AS vendor_id,
                                         B.game_id AS game_id,
                                         0 AS down_bet,
                                         0 AS down_bet,
                                         SUM(B.win_lose) AS down2_bet,
                                         S.member_id AS this_member
                                  FROM summary_bet_records AS B
                                           JOIN (
                                      SELECT substring_index(substring_index(agent_relation,',', agent_level - 1), ',', -1) AS topper2_member_id,
                                             member_id
                                      FROM member_agent
                                      WHERE agent_level = MaxLevel
                                  ) AS S ON S.member_id = B.member_id
                                  WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
                                  GROUP BY this_member, vendor_id, game_id
                              ) AS T
                                  JOIN members AS M ON M.id = T.this_member
                                  JOIN agents AS A ON A.id = M.parent
                                  JOIN agent_customized_settings AS ACS ON ACS.agent_group_id = A.customized_setting_group_id AND ACS.setting_key = AgentModeSettingId AND ACS.`value` = 3
                                  JOIN agent_customized_settings AS ACS2 ON ACS2.agent_group_id = A.customized_setting_group_id AND ACS2.setting_key = 7002
                         GROUP BY T.member_id, T.vendor_id, T.game_id, T.this_member
                     )  AS Z
                         JOIN vendors as V on V.id = Z.vendor_id
                         JOIN games AS G ON G.id = Z.game_id
                         JOIN three_hierarchy_proportions AS THP ON (THP.vendor_id = Z.vendor_id AND THP.game_type_id = G.type_id) AND THP.type = 2 AND THP.agent_group_id = Z.vendor_customize_group_id
                GROUP BY Z.member_id, Z.vendor_id, G.type_id, Z.this_member
                HAVING (SUM(Z.bet) > 0 OR SUM(Z.down_bet) > 0 OR SUM(Z.down2_bet) > 0);

            ELSEIF MaxLevel = 2 THEN
                INSERT INTO three_hierarchy_bonus_report(summary_time, rebate_type, reward_type, agent_id, member_id, from_member_id, vendor_id, game_type_id
                                                        , bet, down_bet, down2_bet, rebate, down_rebate, down2_rebate, admin)
                SELECT SummaryTime,
                       2,
                       Z.reward_type,
                       Z.agent_id,
                       Z.member_id,
                       Z.this_member,
                       Z.vendor_id,
                       G.type_id,
                       IFNULL(SUM(Z.bet), 0)  AS bet,
                       IFNULL(SUM(Z.down_bet), 0) AS down_bet,
                       0 AS down2_bet,
                       IFNULL(SUM(Z.bet * IFNULL(THP.proportion_1, 0)), 0)  AS rebate,
                       IFNULL(SUM(Z.down_bet * IFNULL(THP.proportion_2, 0)), 0) AS down_rebate,
                       0 AS down2_rebate,
                       AdminUser AS admin
                FROM (
                         SELECT ACS2.`value` AS reward_type,
                                T.agent_id,
                                T.member_id,
                                T.this_member,
                                T.vendor_id,
                                T.game_id,
                                A.vendor_customize_group_id,
                                (T.bet * -1) AS bet,
                                (T.down_bet * -1) AS down_bet
                         FROM (
                                  SELECT 0 AS agent_id,
                                         S.member_id AS member_id,
                                         B.vendor_id AS vendor_id,
                                         B.game_id AS game_id,
                                         SUM(B.win_lose) AS bet,
                                         0 AS down_bet,
                                         S.member_id AS this_member
                                  FROM summary_bet_records AS B
                                           JOIN (
                                      SELECT member_id
                                      FROM member_agent
                                      WHERE agent_level = MaxLevel
                                  ) AS S ON S.member_id = B.member_id
                                  WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
                                  GROUP BY this_member, vendor_id, game_id
                                  UNION
                                  SELECT 0 AS agent_id,
                                         S.topper_member_id AS member_id,
                                         B.vendor_id AS vendor_id,
                                         B.game_id AS game_id,
                                         0 AS bet,
                                         SUM(B.win_lose) AS down_bet,
                                         S.member_id AS this_member
                                  FROM summary_bet_records AS B
                                           JOIN (
                                      SELECT topper_member_id,
                                             member_id
                                      FROM member_agent
                                      WHERE agent_level = MaxLevel
                                  ) AS S ON S.member_id = B.member_id
                                  WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
                                  GROUP BY this_member, vendor_id, game_id
                              ) AS T
                                  JOIN members AS M ON M.id = T.this_member
                                  JOIN agents AS A ON A.id = M.parent
                                  JOIN agent_customized_settings AS ACS ON ACS.agent_group_id = A.customized_setting_group_id AND ACS.setting_key = AgentModeSettingId AND ACS.`value` = 3
                                  JOIN agent_customized_settings AS ACS2 ON ACS2.agent_group_id = A.customized_setting_group_id AND ACS2.setting_key = 7002
                         GROUP BY T.member_id, T.vendor_id, T.game_id, T.this_member
                     )  AS Z
                         JOIN vendors as V on V.id = Z.vendor_id
                         JOIN games AS G ON G.id = Z.game_id
                         JOIN three_hierarchy_proportions AS THP ON (THP.vendor_id = Z.vendor_id AND THP.game_type_id = G.type_id) AND THP.type = 2 AND THP.agent_group_id = Z.vendor_customize_group_id
                GROUP BY Z.member_id, Z.vendor_id, G.type_id, Z.this_member
                HAVING (SUM(Z.bet) > 0 OR SUM(Z.down_bet) > 0);

            ELSE
                INSERT INTO three_hierarchy_bonus_report(summary_time, rebate_type, reward_type, agent_id, member_id, from_member_id, vendor_id, game_type_id
                                                        , bet, down_bet, down2_bet, rebate, down_rebate, down2_rebate, admin)
                SELECT SummaryTime,
                       2,
                       Z.reward_type,
                       Z.agent_id,
                       Z.member_id,
                       Z.this_member,
                       Z.vendor_id,
                       G.type_id,
                       IFNULL(SUM(Z.bet), 0)  AS bet,
                       0 AS down_bet,
                       0 AS down2_bet,
                       IFNULL(SUM(Z.bet * IFNULL(THP.proportion_1, 0)), 0)  AS rebate,
                       0 AS down_rebate,
                       0 AS down2_rebate,
                       AdminUser AS admin
                FROM (
                         SELECT ACS2.`value` AS reward_type,
                                T.agent_id,
                                T.member_id,
                                T.this_member,
                                T.vendor_id,
                                T.game_id,
                                A.vendor_customize_group_id,
                                (T.bet * -1) AS bet
                         FROM (
                                  SELECT 0 AS agent_id,
                                         S.member_id AS member_id,
                                         B.vendor_id AS vendor_id,
                                         B.game_id AS game_id,
                                         SUM(B.win_lose) AS bet,
                                         0 AS down_bet,
                                         S.member_id AS this_member
                                  FROM summary_bet_records AS B
                                           JOIN (
                                      SELECT member_id
                                      FROM member_agent
                                      WHERE agent_level = MaxLevel
                                  ) AS S ON S.member_id = B.member_id
                                  WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
                                  GROUP BY this_member, vendor_id, game_id
                              ) AS T
                                  JOIN members AS M ON M.id = T.this_member
                                  JOIN agents AS A ON A.id = M.parent
                                  JOIN agent_customized_settings AS ACS ON ACS.agent_group_id = A.customized_setting_group_id AND ACS.setting_key = AgentModeSettingId AND ACS.`value` = 3
                                  JOIN agent_customized_settings AS ACS2 ON ACS2.agent_group_id = A.customized_setting_group_id AND ACS2.setting_key = 7002
                         GROUP BY T.member_id, T.vendor_id, T.game_id, T.this_member
                     )  AS Z
                         JOIN vendors as V on V.id = Z.vendor_id
                         JOIN games AS G ON G.id = Z.game_id
                         JOIN three_hierarchy_proportions AS THP ON (THP.vendor_id = Z.vendor_id AND THP.game_type_id = G.type_id) AND THP.type = 2 AND THP.agent_group_id = Z.vendor_customize_group_id
                GROUP BY Z.member_id, Z.vendor_id, G.type_id, Z.this_member
                HAVING (SUM(Z.bet) > 0);
            END IF;
            SET MaxLevel = MaxLevel - 1;
        END WHILE;
END
