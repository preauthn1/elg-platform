CREATE PROCEDURE `SP_reset_point_barrel_share_by_agent`(IN p_agent_id INT)
BEGIN
    # 份桶下層代理持股份檢查並更新
    #
    # 2021/01/15 Mars 			Add

    DECLARE MinLevel, MaxLevel INT DEFAULT 0;
    DECLARE SettingId INT DEFAULT 95001;
    DECLARE HierarchyPath VARCHAR(100) DEFAULT '/1';
    DECLARE p_show BOOLEAN DEFAULT false;

    SELECT hierarchy_path INTO HierarchyPath
    FROM agents
    WHERE id = p_agent_id;

    # 計算代理階層
    SELECT MIN(z.level + 1), MAX(z.level) INTO MinLevel, MaxLevel
    FROM (
             SELECT (length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) AS level
             FROM agents as a
             WHERE a.host_id = 0
               AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
         ) AS z;

    IF (p_show) THEN
        SELECT MinLevel, MaxLevel;
    END IF;

    WHILE MinLevel <= MaxLevel
        DO

            IF (p_show) THEN
                SELECT sp.id
                     , CONCAT(p.value, ' ~ ', (p.value - 30)) as g
                     , sp.value
                     , (sp.value > p.value) AS gt
                     , (sp.value < (p.value - 30)) AS lt
                     , (sp.value > p.value OR sp.value < (p.value - 30)) AS w
                     , CASE WHEN CAST(sp.value AS UNSIGNED) > CAST(p.value AS UNSIGNED) THEN p.value ELSE (CAST(p.value AS UNSIGNED) - 30) END AS share_point
                FROM (
                         SELECT a.id AS agent_id
                              , a.parent AS parent_id
                              , (length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) AS level
                         FROM agents AS a
                         WHERE a.host_id = 0
                           AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
                     ) AS l
                         JOIN agent_personal_settings AS sp ON sp.agent_id = l.agent_id AND sp.setting_id = SettingId
                         JOIN agent_personal_settings AS p ON p.agent_id = l.parent_id AND p.setting_id = SettingId
                WHERE l.level = MinLevel
                  AND (CAST(sp.value AS UNSIGNED) > CAST(p.value AS UNSIGNED) OR CAST(sp.value AS UNSIGNED) < (CAST(p.value AS UNSIGNED) - 30))
                ;
            END IF;

            UPDATE agent_personal_settings p
                INNER JOIN (
                    SELECT sp.id
                         , CASE WHEN CAST(sp.value AS UNSIGNED) > CAST(p.value AS UNSIGNED) THEN p.value ELSE (CAST(p.value AS UNSIGNED) - 30) END AS share_point
                    FROM (
                             SELECT a.id AS agent_id
                                  , a.parent AS parent_id
                                  , (length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) AS level
                             FROM agents AS a
                             WHERE a.host_id = 0
                               AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
                         ) AS l
                             JOIN agent_personal_settings AS sp ON sp.agent_id = l.agent_id AND sp.setting_id = SettingId
                             JOIN agent_personal_settings AS p ON p.agent_id = l.parent_id AND p.setting_id = SettingId
                    WHERE l.level = MinLevel
                      AND (CAST(sp.value AS UNSIGNED) > CAST(p.value AS UNSIGNED) OR CAST(sp.value AS UNSIGNED) < (CAST(p.value AS UNSIGNED) - 30))
                ) a ON a.id = p.id
            SET p.value = a.share_point
              , p.created_by = 0;

            SET MinLevel = MinLevel + 1;
        END WHILE;

END
