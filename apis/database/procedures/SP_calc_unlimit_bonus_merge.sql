CREATE PROCEDURE `SP_calc_unlimit_bonus_merge`(
    IN p_SummaryDate DATETIME
)
BEGIN
    # 2020-07-07 mars 	unlimit_bonusreport增加group by
    DECLARE SummaryDate DATETIME;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_unlimit_bonus_merge(',
                    IFNULL(p_SummaryDate, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET SummaryDate = IFNULL(p_SummaryDate, DATE(DATE_ADD(NOW(), INTERVAL -16 HOUR)));

    IF EXISTS(SELECT * FROM unlimit_bonusreport WHERE summary_date = SummaryDate) THEN
        #增加unlimit_summary_bonus已經計算的數據
        INSERT INTO unlimit_summary_bonus(
            member_id, performance_amount, bonus_amount, withdraw_amount,
            flow_amount, itself_bonus_amount, itself_withdraw_amount
        )
        SELECT z.member_id
             , z.performance_amount
             , z.bonus_amount
             , z.withdraw_amount
             , z.flow_amount
             , z.itself_bonus_amount
             , z.itself_withdraw_amount
        FROM (
                 SELECT br.member_id AS member_id
                      , IFNULL(sb.performance_amount, 0) + IFNULL(br.performance_amount, 0) AS performance_amount
                      , IFNULL(sb.bonus_amount, 0) + IFNULL(br.bonus_amount, 0) AS bonus_amount
                      , IFNULL(sb.withdraw_amount, 0) AS withdraw_amount
                      , IFNULL(sb.flow_amount, 0) + IFNULL(br.flow_amount, 0) AS flow_amount
                      , IFNULL(sb.itself_bonus_amount, 0) + IFNULL(br.itself_bonus_amount, 0) AS itself_bonus_amount
                      , IFNULL(sb.itself_withdraw_amount, 0) AS itself_withdraw_amount
                 FROM (
                          SELECT member_id
                               , SUM(performance_direct + performance_team) AS performance_amount
                               , SUM(bonus_direct + bonus_team) AS bonus_amount
                               , SUM(performance) AS flow_amount
                               , SUM(bonus_itself) AS itself_bonus_amount
                          FROM unlimit_bonusreport
                          WHERE summary_date = SummaryDate
                          GROUP BY member_id
                      ) AS br
                          LEFT JOIN unlimit_summary_bonus AS sb ON br.member_id = sb.member_id
             ) AS z
        ON DUPLICATE KEY UPDATE
                             performance_amount = z.performance_amount,
                             bonus_amount = z.bonus_amount,
                             withdraw_amount = z.withdraw_amount,
                             flow_amount = z.flow_amount,
                             itself_bonus_amount = z.itself_bonus_amount,
                             itself_withdraw_amount = z.itself_withdraw_amount;
    END IF;
END
