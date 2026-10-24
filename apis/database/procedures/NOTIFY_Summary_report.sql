CREATE PROCEDURE `NOTIFY_Summary_report`(
)
BEGIN
    # 計算當日註冊/開分/洗分/投注/輸贏等數據

    DECLARE Today DATE DEFAULT DATE(DATE_ADD(NOW(), INTERVAL 7 HOUR));
    DECLARE StartTime DATETIME DEFAULT DATE_SUB(Today, INTERVAL 8 HOUR);
    DECLARE EndTime DATETIME DEFAULT DATE_ADD(StartTime, INTERVAL 1 DAY);
    DECLARE Title VARCHAR(50);
    DECLARE Currency VARCHAR(5);
    DECLARE HierarchyPath VARCHAR(500);
    DECLARE TotalReg, TotalRegMobile INT DEFAULT 0;
    DECLARE TotalDeposit, TotalWithdraw, TotalBet, TotalWinLose DECIMAL(18, 4) DEFAULT 0;

    DECLARE Done INT DEFAULT 0;

    DECLARE Cur CURSOR FOR
        SELECT JSON_UNQUOTE(JSON_EXTRACT(info, '$.title')) AS title
             , JSON_UNQUOTE(JSON_EXTRACT(info, '$.currency')) AS currency
             , JSON_UNQUOTE(JSON_EXTRACT(info, '$.path')) AS hierarchy_path
        FROM params
        WHERE type = 'tg_summary_report_agents'
          AND status = 1
        ORDER BY sort, id;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] NOTIFY_Summary_report(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    OPEN Cur;
    REPEAT FETCH Cur
        INTO Title, Currency, HierarchyPath;
    IF NOT Done THEN

        SET TotalReg = 0;
        SET TotalRegMobile = 0;
        SET TotalDeposit = 0;
        SET TotalWithdraw = 0;
        SET TotalBet = 0;
        SET TotalWinLose = 0;

        # 計算註冊人數
        SELECT COUNT(0) AS total_reg
             , IFNULL(SUM(IF(m.mobile_validate = 1, 1, 0)), 0) AS total_reg_mobile
        INTO TotalReg, TotalRegMobile
        FROM members AS m
                 JOIN agents AS a
                      ON m.parent = a.id
                          AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
        WHERE m.created_at >= StartTime
          AND m.created_at < EndTime;

        # 計算開分(3, 4), 洗分(5)金額
        SELECT IFNULL(SUM(CASE t.type WHEN 5 THEN 0 ELSE t.point END), 0) AS total_deposit
             , IFNULL(SUM(CASE t.type WHEN 5 THEN t.point ELSE 0 END), 0) AS total_withdraw
        INTO TotalDeposit, TotalWithdraw
        FROM transaction_logs AS t
                 JOIN agents AS a ON t.agent_id = a.id
            AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
        WHERE t.created_at >= StartTime
          AND t.created_at < EndTime
          AND t.status = 1000
          AND t.type IN (3, 4, 5);

        # alter
        SELECT IFNULL(SUM(s.bet), 0) AS total_bet
             , IFNULL(SUM(s.win_lose), 0) AS total_win_lose
        INTO TotalBet, TotalWinLose
        FROM summary_bet_records as s
                 JOIN members as m ON s.member_id = m.id
                 JOIN agents as a ON m.parent = a.id
            AND a.hierarchy_path LIKE CONCAT(HierarchyPath, '%')
        WHERE s.summary_time >= StartTime
          AND s.summary_time < EndTime;

        SELECT Today, Title, TotalReg, TotalRegMobile
             , CONCAT(TotalDeposit, ' ', Currency)
             , CONCAT(TotalWithdraw, ' ', Currency)
             , CASE WHEN TotalDeposit > 0 THEN (TotalWithdraw / TotalDeposit) * 100 ELSE 0 END
             , CONCAT(TotalBet, ' ', Currency)
             , CONCAT(TotalWinLose, ' ', Currency)
             , CASE WHEN TotalBet > 0 THEN (TotalWinLose / TotalBet) * -100 ELSE 0 END;


    END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;
END
