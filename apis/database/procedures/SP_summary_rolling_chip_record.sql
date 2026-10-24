CREATE PROCEDURE `SP_summary_rolling_chip_record`(
    IN p_summary_time DATETIME
)
BEGIN
    # 2023-06-08 neil		新建

    DECLARE StartTime DATETIME;
    DECLARE EndTime DATETIME;
    DECLARE SummaryTime DATETIME;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_summary_rolling_chip_record(',
                    IFNULL(p_summary_time, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    #判斷是否有傳入參數，沒有則抓取前一小時時間區間進行統計
    IF p_summary_time is null THEN
        SET EndTime = DATE_ADD(CURDATE(), INTERVAL HOUR(NOW()) HOUR);
        SET StartTime = DATE_ADD(EndTime, INTERVAL -1 HOUR);
    ELSE
        SET StartTime = p_summary_time;
        SET EndTime = DATE_ADD(StartTime, INTERVAL 1 HOUR);
    END IF;

    #判斷是否已經統計過，有則先進行刪除
    IF EXISTS(SELECT * FROM summary_rolling_chip_records WHERE summary_time = StartTime) THEN
        DELETE FROM summary_rolling_chip_records
        WHERE summary_time = StartTime;
    END IF;

    INSERT INTO summary_rolling_chip_records(summary_time, member_id, pay_rolling_chip, use_rolling_chip)
    SELECT StartTime,
           member_id,
           SUM(D.pay_rolling_chip) AS pay_rolling_chip,
           SUM(D.use_rolling_chip) AS use_rolling_chip
    FROM
        (
            # 三層代獎勵
            SELECT member_id AS member_id,
                   SUM(rebate + down_rebate + down2_rebate) AS pay_rolling_chip,
                   0 AS use_rolling_chip
            FROM three_hierarchy_bonus_report
            WHERE created_at >= StartTime AND created_at < EndTime AND reward_type = 2 AND agent_id = 0
            GROUP BY member_id
            UNION # 活動獎勵
            SELECT pm.member_id AS member_id,
                   SUM(`point`) AS pay_rolling_chip,
                   0 AS use_rolling_chip
            FROM prize_members AS pm
                     JOIN prize_contents AS pc ON pc.id = pm.content_id AND point_type = 2
            WHERE pm.updated_at >= StartTime AND pm.updated_at < EndTime AND pm.`status` = 1
            GROUP BY pm.member_id
            UNION  # 投注遊戲消耗禮碼
            SELECT member_id AS member_id,
                   0 AS pay_rolling_chip,
                   SUM(rolling_chip) AS use_rolling_chip
            FROM summary_bet_records
            WHERE summary_time >= StartTime AND summary_time < EndTime
            GROUP BY member_id
            HAVING SUM(rolling_chip) > 0
            UNION  # VIP獎勵
            SELECT member_id  AS member_id,
                   SUM(`point`) AS pay_rolling_chip,
                   0 AS use_rolling_chip
            FROM member_vip_prizes
            WHERE updated_at >= StartTime AND updated_at < EndTime AND `status` = 1 AND point_type = 2
            GROUP BY member_id
            UNION  # 註冊/綁定手機獎勵 & 代理後台贈送禮碼 & 優惠碼
            SELECT member_id  AS member_id,
                   SUM(amount) AS pay_rolling_chip,
                   0 AS use_rolling_chip
            FROM rolling_chip_logs
            WHERE created_at >= StartTime AND created_at < EndTime AND `type` in (5, 11, 23)
            GROUP BY member_id
        ) AS D
    GROUP BY D.member_id;

    CALL SP_summary_event_record_by_rolling_chip(StartTime, EndTime);
END
