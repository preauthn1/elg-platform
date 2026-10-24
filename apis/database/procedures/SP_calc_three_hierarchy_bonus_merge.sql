CREATE PROCEDURE `SP_calc_three_hierarchy_bonus_merge`(
    IN p_SummaryTime DATETIME
)
BEGIN
    # 2020-04-30 Neil 		issues_1546 [GFF]新增三層代理(含代理版本)

    DECLARE AdminUser INT DEFAULT 0;
    DECLARE SummaryTime DATETIME;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_three_hierarchy_bonus_merge(',
                    IFNULL(p_SummaryTime, 'NULL'), ',',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET SummaryTime = IFNULL(p_SummaryTime, DATE(DATE_SUB(NOW(), INTERVAL 16 HOUR)));

    IF EXISTS(SELECT * FROM three_hierarchy_bonus_report WHERE summary_time = SummaryTime) THEN
        # 增加three_hierarchy_summary_bonus已經計算的數據
        ## 玩家
        INSERT INTO three_hierarchy_summary_bonus(
                                                   member_id, rebate_amount, down_rebate_amount, down2_rebate_amount
                                                 , bonus_amount, withdraw_amount
        )
        SELECT z.member_id
             , z.rebate_amount
             , z.down_rebate_amount
             , z.down2_rebate_amount
             , z.bonus_amount
             , z.withdraw_amount
        FROM (
                 SELECT br.member_id AS member_id
                      , IFNULL(sb.rebate_amount,0) + IFNULL(br.rebate_amount,0) AS rebate_amount
                      , IFNULL(sb.down_rebate_amount,0) + IFNULL(br.down_rebate_amount,0) AS down_rebate_amount
                      , IFNULL(sb.down2_rebate_amount,0) + IFNULL(br.down2_rebate_amount,0) AS down2_rebate_amount
                      , IFNULL(sb.bonus_amount,0) + IFNULL(br.bonus_amount,0) AS bonus_amount
                      , IFNULL(sb.withdraw_amount,0) AS withdraw_amount
                 FROM (
                          SELECT member_id
                               , SUM(rebate) AS rebate_amount
                               , SUM(down_rebate) AS down_rebate_amount
                               , SUM(down2_rebate) AS down2_rebate_amount
                               , SUM(rebate + down_rebate + down2_rebate) AS bonus_amount
                          FROM three_hierarchy_bonus_report
                          WHERE summary_time = SummaryTime AND agent_id = 0
                          GROUP BY member_id
                      ) AS br
                          LEFT JOIN three_hierarchy_summary_bonus AS sb
                                    ON br.member_id = sb.member_id
             ) AS z
        ON DUPLICATE KEY UPDATE
                             rebate_amount = z.rebate_amount,
                             down_rebate_amount = z.down_rebate_amount,
                             down2_rebate_amount = z.down2_rebate_amount,
                             bonus_amount = z.bonus_amount,
                             withdraw_amount = z.withdraw_amount;

        ## 代理
        INSERT INTO three_hierarchy_summary_bonus_agents(
                                                          agent_id, rebate_amount, down_rebate_amount, down2_rebate_amount
                                                        , bonus_amount
        )
        SELECT z.agent_id
             , z.rebate_amount
             , z.down_rebate_amount
             , z.down2_rebate_amount
             , z.bonus_amount
        FROM (
                 SELECT br.agent_id AS agent_id
                      , IFNULL(sb.rebate_amount, 0) + IFNULL(br.rebate_amount, 0) AS rebate_amount
                      , IFNULL(sb.down_rebate_amount, 0) + IFNULL(br.down_rebate_amount, 0) AS down_rebate_amount
                      , IFNULL(sb.down2_rebate_amount, 0) + IFNULL(br.down2_rebate_amount, 0) AS down2_rebate_amount
                      , IFNULL(sb.bonus_amount, 0) + IFNULL(br.bonus_amount, 0) AS bonus_amount
                 FROM (
                          SELECT agent_id
                               , SUM(rebate) AS rebate_amount
                               , SUM(down_rebate) AS down_rebate_amount
                               , SUM(down2_rebate) AS down2_rebate_amount
                               , SUM(rebate + down_rebate + down2_rebate) AS bonus_amount
                          FROM three_hierarchy_bonus_report
                          WHERE summary_time = SummaryTime AND member_id = 0
                          GROUP BY agent_id
                      ) AS br
                          LEFT JOIN three_hierarchy_summary_bonus_agents AS sb
                                    ON br.agent_id = sb.agent_id
             ) AS z
        ON DUPLICATE KEY UPDATE
                             rebate_amount = z.rebate_amount,
                             down_rebate_amount = z.down_rebate_amount,
                             down2_rebate_amount = z.down2_rebate_amount,
                             bonus_amount = z.bonus_amount;
    END IF;
END
