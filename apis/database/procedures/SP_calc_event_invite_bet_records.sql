CREATE PROCEDURE `SP_calc_event_invite_bet_records`(IN p_IsRecalculated INT)
sp_main: BEGIN
    # 邀請好友活動：計算會員累積投注流水
    #
    # 2026-09-29 資料來源由 summary_bet_records（每小時彙整表）改為 total_bet_records（逐筆原始注單），
    # 比照 SP_calc_event_progressive_jackpot_bet_records 的修法（target/op_tickets#3282）與
    # SP_calc_flow_limit 的水位模式，用 summary_control_tables（vendor_id=30002，功能代碼，非真實
    # 遊戲商編號；已知使用中：10003/10004/10005/10007/10008/12001/13001/14001/20001/30001）記錄
    # 「上次已經處理到哪個 total_bet_records.id」，每次只掃「上次水位 ~ 這次 MAX(id)」這段區間：
    # - 範圍只跟這段時間新增的注單量成正比，不受 total_bet_records 歷史累積總量影響（id 是 PK，範圍查詢
    #   走 index range scan，不是全表掃描）
    # - total_bet_records 一旦寫入不會被刪除重建或回頭改動（跟 summary_bet_records 不同——後者關帳前的
    #   桶會整批刪除重建、關帳後的補帳列會被蓋上「當天關帳時間」而不是原始小時），id 單調遞增可以放心
    #   當作「有沒有處理過」的判斷依據，不會有視窗過期或補帳晚到被永久跳過的問題
    # 根因（target/op_tickets#3351，TPower 會員7test1121 邀請好友獎勵沒有計算體育類別流水）：舊版以
    # summary_bet_records.summary_time 做「前2小時~前1小時」滑動視窗，該筆流水彙整進 summary_bet_records
    # 的時間晚於對應視窗已經被掃過、寫入 event_invite_point_logs 冪等紀錄之後，之後永遠不會再回頭看
    # 那個視窗，導致漏算——跟 op_tickets#3282（成長彩金計劃）是同一套根因、同一套修法。
    #
    # 只處理 event_invite_user_joins.status = 0（進行中，分數尚未達上限）的會員
    # 分數依 event_invite_settings.bet_point / bet_reward 換算：每累積滿一個 bet_point 門檻，加發一次 bet_reward
    # 開始計算前會先檢查 event_invite_settings.status 是否為 1(開啟)，沒有設定或已停用(0)的話，
    # 這次排程整批不處理，直接離開——停用期間 total_bet_records 水位不會前進，等於欠著一筆帳，
    # 重新啟用後下一次執行會一次把停用期間累積的流水追回來，不會漏算（比照舊版「整批不處理」的既有假設，
    # 只是新版不會像舊版那樣停用期間的流水直接消失，而是留到重新啟用後補算）
    #
    # 移除舊版 event_invite_point_logs 的 AlreadyRun（比對 summary_start_time = StartTime）冪等檢查：
    # id 水位本身已保證同一個 total_bet_records.id 只會落在唯一一次執行的掃描區間內，不會被重複處理。
    # summary_start_time 改填「這次執行時的整點小時」（CurrentHour），整支 SP 執行期間固定一次，避免
    # 跨過整點造成同一批資料寫出兩個不同小時；unique_member_type_summary (member_id, type,
    # summary_start_time) 是實體唯一鍵，且該欄位 migration 沒有 nullable()、不能填 NULL，CurrentHour
    # 同時也是這道 DB 層防線的必要輸入，跟 id 水位是兩層互補的防線。

    DECLARE Done INT DEFAULT 0;
    DECLARE MemberId INT;
    DECLARE PeriodBet, BeforeBet, CurrentPoint DECIMAL(18,4);
    DECLARE SettingsStatus INT DEFAULT 0;
    DECLARE p_ExecStart DATETIME DEFAULT NOW();
    DECLARE p_ExecEnd DATETIME;

    # summary_start_time 用值：整支 SP 執行期間固定一次，不要在迴圈裡每次重算 NOW()，避免執行跨過
    # 整點時同一批資料被拆寫成兩個不同小時
    DECLARE CurrentHour DATETIME DEFAULT DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');

    # 功能代碼，非真實遊戲商編號（比照 SP_calc_flow_limit 用 20001、
    # SP_calc_event_progressive_jackpot_bet_records 用 30001 的慣例；30002 尚未被佔用）
    DECLARE p_VendorID INT DEFAULT 30002;

    # 取出本次 total_bet_records 執行的 ID 區間，比照 SP_calc_event_progressive_jackpot_bet_records
    # 注意：IFNULL 要包在整個子查詢外面，不能包在 SELECT 清單裡——vendor_id=p_VendorID 這筆控制列
    # 如果還沒被 migration 建立過（見對應的 seed migration），子查詢會是 0 筆結果，scalar subquery
    # 對 0 筆結果的結果是 NULL，「SELECT IFNULL(stat_end_id, 0) FROM ... WHERE ...」這種寫法的
    # IFNULL 只在「有這一列、但 stat_end_id 欄位本身是 NULL」時才生效，救不了「整個 WHERE 條件找不到
    # 列」這種情況
    DECLARE p_MinTotalBetRecordId BIGINT
        DEFAULT IFNULL(
            (SELECT stat_end_id FROM summary_control_tables WHERE vendor_id = p_VendorID),
            0
        );

    DECLARE p_MaxTotalBetRecordId BIGINT
        DEFAULT (
            SELECT MAX(id)
            FROM total_bet_records
            WHERE id > p_MinTotalBetRecordId
        );

    # summary_bet_records.bet 實際上是 total_bet_records.valid_bet 的每小時彙總（見
    # SummaryBetRecordService），改直接讀來源表後對應改用 valid_bet，維持原本的流水累積基準不變；
    # vendor_id 允許清單／遊戲類型排除清單沿用原本直接比對 total_bet_records.vendor_id 的做法，
    # 不額外套用 vendors.is_free 排除（原版就沒有這條，維持既有行為，不擴大這次修改範圍）
    #
    # event_invite_bet_settings.condition_type：1 = 參與遊戲商（只算清單內，空白不限制）、
    # 2 = 排除遊戲類型、3 = 排除遊戲商（清單內不算，空白不排除，target/tickets#4504）。
    # 參與與排除遊戲商在設定頁擇一，但這裡兩個條件各自獨立套用，不依賴設定端的擋法：
    # 萬一兩邊同時有值，結果是「在參與清單內、且不在排除清單內」
    DECLARE cur CURSOR FOR
        SELECT T.member_id, SUM(T.valid_bet)
        FROM total_bet_records AS T
                 JOIN games AS g ON g.id = T.game_id
                 JOIN event_invite_user_joins AS eij ON eij.member_id = T.member_id AND eij.status = 0
        WHERE T.id > p_MinTotalBetRecordId AND T.id <= p_MaxTotalBetRecordId
          AND (
                NOT EXISTS (SELECT 1 FROM event_invite_bet_settings WHERE condition_type = 1)
                OR T.vendor_id IN (SELECT condition_value FROM event_invite_bet_settings WHERE condition_type = 1)
              )
          AND T.vendor_id NOT IN (
                SELECT condition_value FROM event_invite_bet_settings WHERE condition_type = 3
              )
          AND g.type_id NOT IN (
                SELECT condition_value FROM event_invite_bet_settings WHERE condition_type = 2
              )
        GROUP BY T.member_id
        HAVING SUM(T.valid_bet) > 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    # event_invite_settings 原則上只有一筆，直接撈狀態；沒有設定或已停用(0)整批不處理，
    # 先查這個直接離開，後面就不用開 cursor 了。用 MAX() 包一層是因為純 SELECT...LIMIT 1
    # 查無資料時會觸發 NOT FOUND，這個 scope 唯一的 handler 是給 cursor 用的 SET Done=1，
    # 會被誤觸發害 cursor 迴圈直接被當成跑完而整批跳過；MAX() 是聚合函式，永遠回傳一列
    # （查無資料是 NULL），不會有這個副作用
    SELECT IFNULL(MAX(status), 0) INTO SettingsStatus FROM event_invite_settings;
    IF SettingsStatus = 0 THEN
        LEAVE sp_main;
    END IF;

    # 這段區間沒有新注單（p_MaxTotalBetRecordId 為 NULL）：維持水位不動，避免下面 UPDATE 把
    # stat_end_id 寫成 NULL
    IF p_MaxTotalBetRecordId IS NULL THEN
        SET p_MaxTotalBetRecordId = p_MinTotalBetRecordId;
    END IF;

    OPEN cur;
    REPEAT
        FETCH cur INTO MemberId, PeriodBet;
        IF NOT Done THEN
            # 獨立區塊：避免 SELECT...FOR UPDATE 的 NOT FOUND 影響外層 cursor 的 Done
            FetchBlock: BEGIN
                DECLARE p_NotFound INT DEFAULT 0;
                DECLARE AgentId INT DEFAULT 0;
                DECLARE BetPoint, BetReward, MaxReward DECIMAL(18,4) DEFAULT 0;
                DECLARE NewPointEarned, AfterBet, AfterPoint DECIMAL(18,4) DEFAULT 0;
                DECLARE CurrentReceive DECIMAL(18,4) DEFAULT 0;
                DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_NotFound = 1;

                START TRANSACTION;
                # 鎖定該會員參加紀錄，避免併發時讀到舊值造成流水/分數被覆蓋
                SELECT bet, point, receive INTO BeforeBet, CurrentPoint, CurrentReceive
                FROM event_invite_user_joins
                WHERE member_id = MemberId
                FOR UPDATE;

                SET AfterBet = (BeforeBet + PeriodBet);

                #找該會員所屬總代，取得對應的活動設定
                SELECT a2.id
                INTO AgentId
                FROM members AS m
                         JOIN agents AS a ON a.id = m.parent
                         JOIN agents AS a2 ON a.hierarchy_path LIKE CONCAT(a2.hierarchy_path, '%') AND a2.role_id = 11
                WHERE m.id = MemberId
                ORDER BY a2.hierarchy_path DESC
                LIMIT 1;

                # agent_id 在 event_invite_settings 上是 unique，原則上只有一筆，直接 first；
                # 活動開關已在進入 cursor 前檢查過，這裡不用再查 status
                SELECT bet_point, bet_reward, max_reward
                INTO BetPoint, BetReward, MaxReward
                FROM event_invite_settings
                WHERE agent_id = AgentId
                LIMIT 1;

                # 沒有設定或 bet_point 未設定(=0)時不換算分數，避免除以0
                IF BetPoint <= 0 THEN
                    SET NewPointEarned = 0;
                ELSE
                    # 累積流水跨過幾個 bet_point 門檻，就加發幾次 bet_reward
                    SET NewPointEarned = (FLOOR(AfterBet / BetPoint) - FLOOR(BeforeBet / BetPoint)) * BetReward;
                END IF;

                SET AfterPoint = (CurrentPoint + NewPointEarned);

                IF MaxReward > 0 AND AfterPoint >= MaxReward THEN
                    # 達到上限：分數封頂，並將狀態改為結束，之後排程不再撈取此會員
                    SET AfterPoint = MaxReward;

                    UPDATE event_invite_user_joins
                    SET bet = AfterBet, point = AfterPoint, `status` = 1
                    WHERE member_id = MemberId;
                ELSE
                    UPDATE event_invite_user_joins
                    SET bet = AfterBet, point = AfterPoint
                    WHERE member_id = MemberId;
                END IF;

                INSERT INTO event_invite_point_logs(summary_start_time, type, member_id, before_bet, after_bet, before_point, after_point, before_receive, after_receive)
                    VALUE (CurrentHour, 3, MemberId, BeforeBet, AfterBet, CurrentPoint, AfterPoint, CurrentReceive, CurrentReceive);
                COMMIT;
            END FetchBlock;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;

    SET p_ExecEnd = NOW();
    SET p_IsRecalculated = IFNULL(p_IsRecalculated, 0);

    # 只有非重算模式才推進水位，重算模式(p_IsRecalculated=1)可以重複拿同一段區間測試/回填，不影響下次正常排程的起點
    IF p_IsRecalculated = 0 THEN
        UPDATE summary_control_tables
        SET stat_end_id = p_MaxTotalBetRecordId
          , exec_start_time = p_ExecStart
          , exec_end_time = p_ExecEnd
        WHERE vendor_id = p_VendorID;
    END IF;

    INSERT INTO summary_control_logs
    (vendor_id, params, exec_start_time, exec_end_time)
    VALUES
        (p_VendorID,
         CONCAT(p_MinTotalBetRecordId, ' - ', p_MaxTotalBetRecordId),
         p_ExecStart, p_ExecEnd);
END sp_main
