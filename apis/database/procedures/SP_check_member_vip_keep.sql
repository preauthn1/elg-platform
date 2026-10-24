CREATE PROCEDURE `SP_check_member_vip_keep`(IN p_StartTime DATETIME)
sp_main: BEGIN
    DECLARE StartTime, EndTime DateTime DEFAULT NOW();
    DECLARE Today Date DEFAULT NOW();
    DECLARE TodayDay VARCHAR(10);
    DECLARE TodayMonth INT DEFAULT 0;
    DECLARE PeriodStart, PeriodEnd DATETIME;
    DECLARE AlreadyPaid INT DEFAULT 0;
    DECLARE PayoutLogId INT DEFAULT 0;
    DECLARE p_ChatId INT DEFAULT -452604221;
    DECLARE p_TGMessage VARCHAR(2000);
    DECLARE RewardId INT;
    DECLARE RewardPeriod INT DEFAULT 0;

    SET StartTime = IFNULL(p_StartTime, DATE_ADD(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL -8 HOUR));
    SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);
    SET Today = DATE_ADD(StartTime, INTERVAL 8 HOUR);
    SET TodayMonth = MONTH(Today); # 取得今天的月份
    SET TodayDay = DATE_FORMAT(Today, '%d'); # 取得今天的日期，確保只在每季1號執行
    SET RewardId = 1; #等級獎金

    # 上一季評估區間，明確列出各發獎月份對應的起訖（本地時間），不論本次是否為發獎月份都先算好
    # summary_start_time / summary_end_time 依慣例採 StartTime/EndTime 格式（本地時間再 -8 小時的 UTC 表示）
    IF TodayMonth = 1 THEN
        # 1月 → 前一年 10/1 0點 ~ 1/1 0點
        SET RewardPeriod = 1;
        SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today) - 1, '-10-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today), '-01-01'), INTERVAL -8 HOUR);
    ELSEIF TodayMonth = 4 THEN
        # 4月 → 1/1 0點 ~ 4/1 0點
        SET RewardPeriod = 2;
        SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today), '-01-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today), '-04-01'), INTERVAL -8 HOUR);
    ELSEIF TodayMonth = 7 THEN
        # 7月 → 4/1 0點 ~ 7/1 0點
        SET RewardPeriod = 3;
        SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today), '-04-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today), '-07-01'), INTERVAL -8 HOUR);
    ELSEIF TodayMonth = 10 THEN
        # 10月 → 7/1 0點 ~ 10/1 0點
        SET RewardPeriod = 4;
        SET PeriodStart = DATE_ADD(CONCAT(YEAR(Today), '-07-01'), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(CONCAT(YEAR(Today), '-10-01'), INTERVAL -8 HOUR);
    ELSE
        # 非發獎月份僅作為執行紀錄用途，沿用往前3個月的區間
        SET PeriodStart = DATE_ADD(DATE_ADD(DATE_FORMAT(Today, '%Y-%m-01'), INTERVAL -3 MONTH), INTERVAL -8 HOUR);
        SET PeriodEnd = DATE_ADD(DATE_FORMAT(Today, '%Y-%m-01'), INTERVAL -8 HOUR);
    END IF;

    # 建立本次執行紀錄，只要執行過就寫入（未派發）
    INSERT INTO vip_reward_payout_logs(vip_reward_id, summary_start_time, summary_end_time, status)
        VALUE (RewardId, PeriodStart, PeriodEnd, 0);
    SELECT LAST_INSERT_ID() INTO PayoutLogId;

    IF TodayMonth IN (1, 4, 7, 10) AND TodayDay = '01' THEN
        # 確認是對應月份1日，修改紀錄狀態
        UPDATE vip_reward_payout_logs SET `status` = 2 WHERE id = PayoutLogId;

        # 防止排程重複觸發造成重複派獎：檢查上一季是否已成功執行過
        SELECT COUNT(*) INTO AlreadyPaid
        FROM vip_reward_payout_logs
        WHERE vip_reward_id = RewardId
          AND status IN (1, 3, 4, 5)
          AND summary_start_time = PeriodStart;

        IF AlreadyPaid > 0 THEN
            LEAVE sp_main;
        END IF;

        # 確認沒有執行過，再次修改狀態
        UPDATE vip_reward_payout_logs SET `status` = 3 WHERE id = PayoutLogId;

        # 先查詢會被降級的玩家
        Block1: BEGIN
            DECLARE p_Done INT DEFAULT 0;
            DECLARE p_MemberId, NowLevel, p_NowLevellId, DownLevel, p_DownLevelId INT;
            DECLARE p_DepositAmount, p_MKeepAmount, p_TargetAmount, p_KeepAmount DECIMAL(18,4) DEFAULT 0;
            DECLARE p_ExpChange DECIMAL(18,4) DEFAULT 0;

            DECLARE cur CURSOR FOR
                SELECT mv.member_id, mv.deposit_amount, mv.keep_amount, vc.keep_amount
                FROM member_vips AS mv
                         JOIN vip_contents AS vc ON vc.id = mv.vip_content_id AND `level` != 0 AND is_downgrade = 1;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done = 1;

            ### SQL異常時發TG通知 ##################
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1
                        @p2 = MESSAGE_TEXT;
                    # 發通知
                    CALL COM_Send_Error_Message(
                        CONCAT(
                            '[系統通知] SP_check_member_vip_keep(',
                            IFNULL(p_StartTime, 'NULL'),
                            ') 異常 ', @p2),
                        'db'
                         );
                    ROLLBACK;
                END;

            OPEN cur;
            REPEAT
                FETCH cur INTO p_MemberId, p_DepositAmount, p_MKeepAmount, p_KeepAmount;
                IF NOT p_Done THEN
                    SET p_ExpChange = 0;
                    IF p_MKeepAmount < p_KeepAmount THEN
                        #如果分數小於保級分數就降級
                        SELECT vc.level, vc.id, vc.target_amount, vc2.level, IFNULL(vc2.id, 0)
                        INTO NowLevel, p_NowLevellId, p_TargetAmount, DownLevel, p_DownLevelId
                        FROM member_vips AS mv
                                 JOIN vip_contents AS vc ON vc.id = mv.vip_content_id
                                 LEFT JOIN vip_contents AS vc2 ON vc2.agent_id = vc.agent_id AND vc2.level = (vc.level - 1)
                        WHERE mv.member_id = p_MemberId;

                        IF p_DownLevelId != 0 THEN
                            UPDATE member_vips
                            SET vip_content_id = p_DownLevelId
                            WHERE member_id = p_MemberId;

                            INSERT INTO member_vip_records (member_id, before_vip_content_id, vip_content_id, admin)
                                VALUE (p_MemberId, p_NowLevellId, p_DownLevelId, 0);

                            IF p_DepositAmount >= p_TargetAmount THEN
                                #如果儲值分數可以回到原本等級就扣掉回到原本等級
                                SET p_ExpChange = (0 - p_TargetAmount);

                                UPDATE member_vips
                                SET vip_content_id = p_NowLevellId, deposit_amount = (deposit_amount - p_TargetAmount)
                                WHERE member_id = p_MemberId;

                                INSERT INTO member_vip_records (member_id, before_vip_content_id, vip_content_id, admin)
                                    VALUE (p_MemberId, p_DownLevelId, p_NowLevellId, 0);
                            END IF;
                        END IF;
                    END IF;
                    UPDATE member_vips SET keep_amount = 0 WHERE member_id = p_MemberId;

                    # 寫入VIP經驗值異動紀錄：keep_amount 每季重置為0，deposit_amount 視是否降級後即刻補回原等級而定
                    INSERT INTO member_vip_exp_logs(member_id, vip_flow_limit_id, exp, before_deposit_amount, after_deposit_amount, before_keep_amount, after_keep_amount, `admin`)
                        VALUE (p_MemberId, 0, p_ExpChange, p_DepositAmount, (p_DepositAmount + p_ExpChange), p_MKeepAmount, 0, 0);
                END IF;
            UNTIL p_Done END REPEAT;
            CLOSE cur;
        END Block1;

        #派發每月的等級獎勵
        Call SP_payout_vip_level_rewards(Today, RewardPeriod, PayoutLogId);

        # 派發完畢，更新派發紀錄
        UPDATE vip_reward_payout_logs SET `status` = 1 WHERE id = PayoutLogId AND `status` = 5;
    END IF;
END sp_main