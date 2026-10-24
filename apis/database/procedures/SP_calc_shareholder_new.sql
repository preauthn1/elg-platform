CREATE PROCEDURE `SP_calc_shareholder_new`(
    IN p_SummaryDate DATE,
    IN p_AgentId INT,
    IN p_PayoutRecordId INT
)
BEGIN
    # 分桶計算
    # 2020/12/30 Mars		Add
    # 2021/01/13 Mars       可依代理做計算, 並檢查下層代理是否有設定分桶比例, 無資料使用上級代理比例
    # 2023/07/03 Mars 		Modify
    # 2023/10/13 Mars 		ADD calc_bet & calc_win_lose
    # 2023/11/09 Mars 		不更新parent_id
    # 2024/01/23 Mars 		優化

    DECLARE ExecStart, ExecEnd DATETIME;
    DECLARE ExecSubStart, ExecSubEnd DATETIME;
    DECLARE MaxLevel, SettlementId INT DEFAULT 0;
    DECLARE SummaryDate DATE
        DEFAULT DATE_SUB(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL 1 DAY);
    DECLARE StartTime, EndTime DATETIME;
    DECLARE AgentGroupId, TotalSharePoint INT;
    DECLARE AgentId INT DEFAULT p_AgentId;
    DECLARE PayoutRecordId INT DEFAULT p_PayoutRecordId;
    DECLARE ThisLevel INT DEFAULT 3;
    DECLARE DataType, DataValue VARCHAR(100);
    DECLARE TotalTurnover, TotalNettProfit, TotalGameProfit, TotalCost DECIMAL(18, 4) DEFAULT 0;

    DECLARE ShareholderKey INT DEFAULT 6100; #份桶開關
    DECLARE ShareholderGeneralAgentKey INT DEFAULT 6101; #份桶總代理
    DECLARE ShareholderGeneralAgentShareValueKey INT DEFAULT 8001; #總代理持有股份值
    DECLARE ShareholderModifyShareValueKey INT DEFAULT 8002; #是否可以修改股份值
    DECLARE ShareholderMaxDifferenceKey INT DEFAULT 8003; #代理層級的最大差額
    DECLARE ShareholderAgencyGapKey INT DEFAULT 8004; #代理每層上限差額
    DECLARE ShareholderAutoRewardKey INT DEFAULT 8005; #是否自動派獎(下午3點派獎)
    DECLARE PersonalSettingKey INT DEFAULT 95001;  #分桶股份佔成數

    DECLARE ShareholderGeneralAgent VARCHAR(50); #份桶總代理
    DECLARE ShareholderGeneralAgentShareValue INT DEFAULT 0; #總代理持有股份值
    DECLARE ShareholderModifyShareValue INT DEFAULT 0; #是否可以修改股份值
    DECLARE ShareholderMaxDifference INT DEFAULT 0; #代理層級的最大差額
    DECLARE ShareholderAgencyGap INT DEFAULT 0; #代理每層上限差額
    DECLARE ShareholderAutoReward INT DEFAULT 0; #是否自動派獎(下午3點派獎)
    DECLARE ShareholderAgentShareValue INT DEFAULT 0;  #分桶股份佔成數

    DECLARE HierarchyPath VARCHAR(1000);
    #DECLARE StopSeconds INT DEFAULT 1;		# 暫停秒數

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_shareholder_new(',
                    IFNULL(p_SummaryDate, 'NULL'), ',',
                    IFNULL(p_AgentId, 'NULL'), ',',
                    IFNULL(p_PayoutRecordId, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET ExecStart = NOW();
    SET SummaryDate = IFNULL(p_SummaryDate, SummaryDate);
    SET StartTime = DATE_SUB(SummaryDate, INTERVAL 8 HOUR);
    SET EndTime = DATE_SUB(DATE_ADD(StartTime, INTERVAL 1 DAY), INTERVAL 1 SECOND);

    # 取得組別
    SELECT hierarchy_path, customized_setting_group_id
    INTO HierarchyPath, AgentGroupId
    FROM agents
    WHERE id = AgentId;

    # 確認份桶開關
    CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderKey, DataType, DataValue);
    IF DataValue <> 1 THEN
        SELECT '未開啟分桶';
    ELSE
        # 取得分桶總代理
        CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderGeneralAgentKey, DataType, ShareholderGeneralAgent);
        IF ShareholderGeneralAgent = '' THEN
            SELECT '未設定總代';
        ELSE

            # 取得總代成本
            CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderGeneralAgentShareValueKey, DataType, ShareholderGeneralAgentShareValue);
            IF ShareholderGeneralAgentShareValue < 0 THEN
                SELECT '總代成本 ', ShareholderGeneralAgentShareValue;
            ELSE
                # 代理每層上限差額
                CALL ADMIN_get_customized_setting(AgentGroupId, ShareholderAgencyGapKey, DataType, ShareholderAgencyGap);
                SET ShareholderAgencyGap = IFNULL(ShareholderAgencyGap, 0);

                # 計算代理階層
                SELECT MAX(z.level) INTO MaxLevel
                FROM (
                         SELECT a.id, a.username, a.hierarchy_path
                              , (length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) AS level
                         FROM agents as a
                         WHERE a.host_id = 0
                           AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
                     ) AS z;

                # 總銷量先寫至暫存表 => 改至SP_calc_shareholder_temp

                # 找出未設置分桶的代理, 依上層代理數值給定
                /*WHILE MaxLevel >= ThisLevel
                DO
                    INSERT INTO agent_personal_settings(
                        agent_id,
                        setting_id,
                        sub_category,
                        `value`,
                        created_by
                    )
                    SELECT a.id,
                        PersonalSettingKey,
                        '',
                        CASE WHEN (pps.value - ShareholderAgencyGap) > 0
                            THEN (pps.value - ShareholderAgencyGap)
                            ELSE 0
                            END AS `value`,
                        0
                    FROM agents AS a
                    JOIN agent_personal_settings AS pps
                        ON pps.agent_id = a.parent
                        AND pps.setting_id = PersonalSettingKey
                    LEFT JOIN agent_personal_settings AS aps
                        ON aps.agent_id = a.id
                        AND aps.setting_id = PersonalSettingKey
                    WHERE a.hierarchy_path LIKE CONCAT(HierarchyPath, '/%')
                    AND (LENGTH(a.hierarchy_path) - LENGTH(REPLACE(a.hierarchy_path, '/', ''))) = ThisLevel
                    AND `aps`.`value` IS NULL;

                    SET ThisLevel = ThisLevel + 1;
                END WHILE;

                #判斷執行筆數
                SELECT '重置', ROW_COUNT();
                */

                # 取總業績
                SELECT share_point
                     , IFNULL(SUM(turnover), 0) AS turnover
                     , SUM(CASE WHEN shareholder_category_id = 1 THEN nett_profit ELSE 0 END) AS nett_profit
                INTO TotalSharePoint, TotalTurnover, TotalNettProfit
                FROM summary_shareholder_temps
                WHERE summary_time BETWEEN StartTime AND EndTime
                  AND agent_group_id = AgentGroupId
                GROUP BY agent_group_id;

                # 分桶計算
                WHILE MaxLevel > 1
                    DO

                        SET ExecSubStart = NOW();
                        #寫入 OR 重置
                        INSERT INTO summary_shareholders_mars(
                                                               summary_time, agent_id, parent_id
                                                             , shareholder_category_id, hierarchy_path
                                                             , upper_share_point, share_point
                        )
                        SELECT StartTime AS summary_time
                             , z.agent_id
                             , z.parent_id
                             , z.shareholder_category_id
                             , z.hierarchy_path
                             , 0 AS upper_share_point
                             , 0 AS share_point
                        FROM (
                                 SELECT a.agent_id
                                      , a.parent_id
                                      , sc.id as shareholder_category_id
                                      , a.hierarchy_path
                                 FROM (
                                          SELECT id AS agent_id
                                               , parent AS parent_id
                                               , username
                                               , hierarchy_path
                                               , (LENGTH(hierarchy_path) - LENGTH(REPLACE(hierarchy_path, '/', ''))) AS level
                                               , customized_setting_group_id
                                          FROM agents
                                          WHERE host_id = 0
                                            AND (
                                              hierarchy_path LIKE CONCAT(HierarchyPath, '/%')
                                                  OR
                                              id = AgentId
                                              )
                                      ) AS a
                                          JOIN shareholder_categories AS sc
                                               ON sc.agent_group_id = AgentGroupId
                                                   AND sc.status = 1
                                 WHERE a.level = MaxLevel
                                   AND NOT EXISTS (
                                     SELECT *
                                     FROM shareholder_cancels AS sc
                                     WHERE sc.agent_id = a.agent_id
                                       AND sc.summary_date BETWEEN StartTime AND EndTime
                                       AND sc.status = 1
                                 )
                             ) AS z
                        ON DUPLICATE KEY UPDATE
                            parent_id = z.parent_id
                                              , hierarchy_path = z.hierarchy_path
                                              , upper_share_point = 0
                                              , share_point = 0
                                              , direct_turnover = 0
                                              , direct_rate = 0
                                              , direct_nett_profit = 0
                                              , team_turnover = 0
                                              , team_rate = 0
                                              , team_nett_profit = 0
                                              , nett_profit = 0
                                              , rate = 0
                                              , remark = '';

                        CALL COM_Send_Error_Message(
                            CONCAT(
                                '[系統通知] SP_calc_shareholder Reset(',
                                ' level:', MaxLevel, ',',
                                ' start:', ExecSubStart, ',',
                                ' end:', NOW(),
                                ' count:', ROW_COUNT()),
                            'db'
                             );

                        SET ExecSubStart = NOW();
                        # Calc Settlement
                        INSERT INTO summary_shareholders_mars(
                            summary_time, agent_id, parent_id,
                            shareholder_category_id,
                            hierarchy_path, share_point, upper_share_point,
                            direct_rate, direct_turnover, direct_nett_profit,
                            team_rate, team_turnover, team_nett_profit,
                            rate, remark
                        )
                        SELECT StartTime AS summary_time, z.agent_id, z.parent_id
                             , z.shareholder_category_id
                             , z.hierarchy_path, z.share_point, z.upper_share_point
                             , TRUNCATE(z.direct_rate, 4) AS direct_rate
                             , z.direct_turnover AS direct_turnover
                             , TRUNCATE((z.total_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4) AS direct_nett_profit
                             , TRUNCATE(z.team_rate, 4) AS team_rate
                             , z.team_turnover AS team_turnover
                             , TRUNCATE((z.total_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4) AS team_nett_profit
                             , TRUNCATE(z.rate, 4) AS rate
                             , CONCAT(
                            'direct:', z.total_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; '
                                'team:', z.total_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
                               ) AS remark
                        FROM (
                                 SELECT x.agent_id
                                      , x.parent_id
                                      , x.shareholder_category_id
                                      , x.hierarchy_path
                                      , TotalSharePoint AS total_share_point
                                      , paps.value AS upper_share_point
                                      , aps.value AS share_point
                                      , x.direct_turnover AS direct_turnover
                                      , CASE WHEN TotalTurnover = 0
                                                 THEN x.direct_turnover
                                             ELSE (x.direct_turnover / TotalTurnover)
                                     END AS direct_rate
                                      , IFNULL(ss.team_turnover, 0) + x.direct_turnover AS team_turnover
                                      , CASE WHEN TotalTurnover = 0
                                                 THEN (IFNULL(ss.team_turnover, 0) + x.direct_turnover)
                                             ELSE ((IFNULL(ss.team_turnover, 0) + x.direct_turnover) / TotalTurnover)
                                     END AS team_rate
                                      , TotalTurnover AS total_nett_profit
                                      , TotalNettProfit AS total_turnover
                                      , CASE WHEN TotalTurnover = 0
                                                 THEN (IFNULL(ss.team_turnover, 0) + x.direct_turnover)
                                             ELSE ((IFNULL(ss.team_turnover, 0) + x.direct_turnover) / TotalTurnover)
                                     END AS rate
                                 FROM (
                                          SELECT a.agent_id
                                               , a.parent_id
                                               , AgentGroupId AS agent_group_id
                                               , 1 AS shareholder_category_id
                                               , a.hierarchy_path
                                               , SUM(CASE WHEN scs.calc_bet = 1 THEN IFNULL(ssw.turnover, 0) ELSE 0 END) AS direct_turnover
                                          FROM (
                                                   SELECT id AS agent_id
                                                        , parent AS parent_id
                                                        , username
                                                        , hierarchy_path
                                                        , (LENGTH(hierarchy_path) - LENGTH(REPLACE(hierarchy_path, '/', ''))) AS level
                                                        , customized_setting_group_id
                                                   FROM agents
                                                   WHERE host_id = 0
                                                     AND (
                                                       hierarchy_path LIKE CONCAT(HierarchyPath, '/%')
                                                           OR
                                                       id = AgentId
                                                       )
                                               ) AS a
                                                   JOIN (
                                              SELECT scs.vendor_id
                                                   , scs.game_type_id
                                                   , scs.calc_bet
                                              FROM shareholder_categories AS sc
                                                       JOIN shareholder_category_settings AS scs
                                                            ON scs.shareholder_category_id = sc.id
                                                                AND scs.status = 1
                                              WHERE sc.agent_group_id = AgentGroupId
                                                AND sc.status = 1
                                          ) AS scs
                                                   JOIN summary_shareholder_winloses AS ssw
                                                        ON ssw.agent_id = a.agent_id
                                                            AND ssw.summary_time BETWEEN StartTime AND EndTime
                                                            AND ssw.vendor_id = scs.vendor_id
                                                            AND (
                                                               scs.game_type_id = ssw.game_type_id
                                                                   OR
                                                               scs.game_type_id = 0
                                                               )
                                          WHERE a.level = MaxLevel
                                            AND NOT EXISTS (
                                              SELECT *
                                              FROM shareholder_cancels AS sc
                                              WHERE sc.agent_id = ssw.agent_id
                                                AND sc.summary_date BETWEEN StartTime AND EndTime
                                                AND sc.status = 1
                                          )
                                          GROUP BY a.agent_id
                                      ) AS x
                                          # 取下級代理業績
                                          LEFT JOIN (
                                     SELECT parent_id
                                          , shareholder_category_id
                                          , SUM(team_turnover) AS team_turnover
                                          , SUM(team_nett_profit) AS team_nett_profit
                                     FROM summary_shareholders_mars
                                     WHERE summary_time BETWEEN StartTime AND EndTime
                                     GROUP BY shareholder_category_id, parent_id
                                 ) AS ss
                                                    ON ss.parent_id = x.agent_id
                                                        AND ss.shareholder_category_id = x.shareholder_category_id
                                     # 各代理佔成
                                          JOIN agent_personal_settings AS aps
                                               ON aps.agent_id = x.agent_id
                                                   AND aps.setting_id = PersonalSettingKey
                                     # 上級代理佔成
                                          JOIN agent_personal_settings AS paps
                                               ON paps.agent_id = x.parent_id
                                                   AND paps.setting_id = PersonalSettingKey
                             ) AS z
                        ON DUPLICATE KEY UPDATE
                            share_point = z.share_point
                                              , upper_share_point = z.upper_share_point

                                              , direct_rate = TRUNCATE(z.direct_rate, 4)
                                              , direct_turnover = z.direct_turnover
                                              , direct_nett_profit = TRUNCATE((z.total_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4)

                                              , team_rate = TRUNCATE(z.team_rate, 4)
                                              , team_turnover = z.team_turnover
                                              , team_nett_profit = TRUNCATE((z.total_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4)

                                              , rate = TRUNCATE(z.rate, 4)
                                              , remark = CONCAT(
                                'direct:', z.total_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; '
                                    'team:', z.total_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
                                                         );


                        CALL COM_Send_Error_Message(
                            CONCAT(
                                '[系統通知] SP_calc_shareholder Settlement(',
                                ' level:', MaxLevel, ',',
                                ' start:', ExecSubStart, ',',
                                ' end:', NOW(),
                                ' count:', ROW_COUNT()),
                            'db'
                             );

                        SET ExecSubStart = NOW();
                        # Calc Vendor
                        INSERT INTO summary_shareholders_mars(
                            summary_time, agent_id, parent_id,
                            shareholder_category_id,
                            hierarchy_path, share_point, upper_share_point,
                            direct_rate, direct_turnover, direct_nett_profit,
                            team_rate, team_turnover, team_nett_profit,
                            rate, remark
                        )
                        SELECT StartTime AS summary_time, z.agent_id, z.parent_id
                             , z.shareholder_category_id
                             , z.hierarchy_path, z.share_point, z.upper_share_point

                             , TRUNCATE(z.direct_rate, 4) AS direct_rate
                             , z.direct_turnover AS direct_turnover
                             , TRUNCATE((z.total_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4) AS direct_nett_profit

                             , TRUNCATE(z.team_rate, 4) AS team_rate
                             , z.team_turnover AS team_turnover
                             , TRUNCATE((z.total_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4) AS team_nett_profit

                             , TRUNCATE(z.rate, 4) AS rate
                             , CONCAT(
                            'direct:', z.total_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; '
                                'team:', z.total_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
                               ) as remark
                        FROM (
                                 SELECT x.agent_id
                                      , x.parent_id
                                      , x.shareholder_category_id
                                      , x.hierarchy_path
                                      , TotalSharePoint AS total_share_point
                                      , paps.value AS upper_share_point
                                      , aps.value AS share_point

                                      , x.direct_turnover AS direct_turnover
                                      , CASE WHEN TotalTurnover = 0
                                                 THEN x.direct_turnover
                                             ELSE (x.direct_turnover / TotalTurnover)
                                     END AS direct_rate

                                      , IFNULL(ss.team_turnover, 0) + x.direct_turnover AS team_turnover
                                      , CASE WHEN TotalTurnover = 0
                                                 THEN (IFNULL(ss.team_turnover, 0) + x.direct_turnover)
                                             ELSE ((IFNULL(ss.team_turnover, 0) + x.direct_turnover) / t.turnover)
                                     END AS team_rate

                                      , TotalNettProfit AS total_nett_profit
                                      #, IFNULL(ss.team_nett_profit, 0) AS downline_nett_profit
                                      , CASE WHEN TotalTurnover = 0
                                                 THEN (IFNULL(ss.team_turnover, 0) + x.direct_turnover)
                                             ELSE ((IFNULL(ss.team_turnover, 0) + x.direct_turnover) / t.turnover)
                                     END AS rate
                                 FROM (
                                          SELECT a.agent_id
                                               , a.username
                                               , a.customized_setting_group_id AS agent_group_id
                                               , a.parent_id
                                               , scs.shareholder_category_id
                                               , a.hierarchy_path
                                               , SUM(CASE WHEN scs.calc_bet = 1 THEN IFNULL(ssw.turnover, 0) ELSE 0 END) AS direct_turnover
                                          FROM (
                                                   SELECT id AS agent_id
                                                        , parent AS parent_id
                                                        , username
                                                        , hierarchy_path
                                                        , (LENGTH(hierarchy_path) - LENGTH(REPLACE(hierarchy_path, '/', ''))) AS level
                                                        , customized_setting_group_id
                                                   FROM agents
                                                   WHERE host_id = 0
                                                     AND (
                                                       hierarchy_path LIKE CONCAT(HierarchyPath, '/%')
                                                           OR
                                                       id = AgentId
                                                       )
                                               ) AS a
                                                   JOIN (
                                              SELECT sc.id as shareholder_category_id
                                                   , scs.vendor_id
                                                   , scs.game_type_id
                                                   , scs.calc_bet
                                              FROM shareholder_categories AS sc
                                                       JOIN shareholder_category_settings AS scs
                                                            ON scs.shareholder_category_id = sc.id
                                                                AND scs.status = 1
                                              WHERE sc.agent_group_id = AgentGroupId
                                                AND sc.status = 1
                                          ) AS scs
                                                   JOIN summary_shareholder_winloses AS ssw
                                                        ON ssw.agent_id = a.agent_id
                                                            AND ssw.summary_time BETWEEN StartTime AND EndTime
                                                            AND ssw.vendor_id = scs.vendor_id
                                                            AND (
                                                               ssw.game_type_id = scs.game_type_id
                                                                   OR
                                                               scs.game_type_id = 0
                                                               )
                                          WHERE a.level = MaxLevel
                                            AND NOT EXISTS (
                                              SELECT *
                                              FROM shareholder_cancels AS sc
                                              WHERE sc.agent_id = ssw.agent_id
                                                AND sc.summary_date BETWEEN StartTime AND EndTime
                                                AND sc.status = 1
                                          )
                                          GROUP BY a.agent_id, scs.shareholder_category_id
                                      ) AS x
                                          # 取總業績
                                          JOIN summary_shareholder_temps AS t
                                               ON t.shareholder_category_id = x.shareholder_category_id
                                                   AND t.summary_time BETWEEN StartTime AND EndTime
                                                   AND t.agent_group_id = AgentGroupId
                                     # 取下級代理業績
                                          LEFT JOIN (
                                     SELECT parent_id
                                          , shareholder_category_id
                                          , SUM(team_turnover) AS team_turnover
                                          , SUM(team_nett_profit) AS team_nett_profit
                                     FROM summary_shareholders_mars
                                     WHERE summary_time BETWEEN StartTime AND EndTime
                                     GROUP BY shareholder_category_id, parent_id
                                 ) AS ss
                                                    ON ss.parent_id = x.agent_id
                                                        AND ss.shareholder_category_id = x.shareholder_category_id
                                     # 各代理佔成
                                          JOIN agent_personal_settings AS aps
                                               ON aps.agent_id = x.agent_id
                                                   AND aps.setting_id = PersonalSettingKey
                                     # 上級代理佔成
                                          LEFT JOIN agent_personal_settings AS paps
                                                    ON paps.agent_id = x.parent_id
                                                        AND paps.setting_id = PersonalSettingKey
                             ) AS z
                        ON DUPLICATE KEY UPDATE
                            shareholder_category_id = z.shareholder_category_id
                                              , hierarchy_path = z.hierarchy_path
                                              , share_point = z.share_point
                                              , upper_share_point = z.upper_share_point

                                              , direct_rate = TRUNCATE(z.direct_rate, 4)
                                              , direct_turnover = z.direct_turnover
                                              , direct_nett_profit = TRUNCATE((z.total_nett_profit * z.direct_rate / z.total_share_point * z.share_point), 4)

                                              , team_rate = TRUNCATE(z.team_rate, 4)
                                              , team_turnover = z.team_turnover
                                              , team_nett_profit = TRUNCATE((z.total_nett_profit * z.team_rate / z.total_share_point * (z.upper_share_point - z.share_point)), 4)

                                              , rate = TRUNCATE(z.rate, 4)
                                              , remark = CONCAT(
                                'direct:', z.total_nett_profit, ' * ', z.direct_rate, ' / ', z.total_share_point, '*', z.share_point, '; '
                                    'team:', z.total_nett_profit, ' * ', z.team_rate, ' / ', z.total_share_point, '*(', z.upper_share_point, '-', z.share_point, ')'
                                                         );

                        CALL COM_Send_Error_Message(
                            CONCAT(
                                '[系統通知] SP_calc_shareholder Vendor(',
                                ' level:', MaxLevel, ',',
                                ' start:', ExecSubStart, ',',
                                ' end:', NOW(),
                                ' count:', ROW_COUNT()),
                            'db'
                             );

                        #判斷執行筆數
                        #SELECT '分桶計算', PersonalSettingKey, AgentId, HierarchyPath, AgentGroupId, MaxLevel, StartTime, EndTime, SLEEP(StopSeconds);
                        SELECT '分桶計算', PersonalSettingKey, AgentId, HierarchyPath, AgentGroupId, MaxLevel, StartTime, EndTime;

                        SET MaxLevel = MaxLevel - 1;
                    END WHILE;

                #0:未派發, 1:已派發, 2:待派發, 3:已輸入數據(未結算), 4:已結算, 5:已計算, 6:已發佈數據
                #UPDATE shareholder_payout_logs
                #SET status = 4
                #WHERE id = PayoutRecordId
                #AND status in (0, 6);

                SET ExecEnd = NOW();
                CALL COM_Send_Error_Message(
                    CONCAT(
                        '[系統通知] SP_calc_shareholder_new(',
                        IFNULL(p_SummaryDate, 'NULL'), ',',
                        IFNULL(p_AgentId, 'NULL'), ',',
                        IFNULL(p_PayoutRecordId, 'NULL'),
                        ') 計算完畢 ',
                        ExecStart, ' ~ ', ExecEnd),
                    'db'
                     );
            END IF;
        END IF;
    END IF;
END
