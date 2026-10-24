/*
新增Event
CREATE EVENT Event_SP_Calc_Event_Progressive_Jackpot_Bet_Records
ON SCHEDULE EVERY 1 HOUR STARTS '2026-08-19 00:04:00' #系統時間UTC+0，避開整點/5分倍數的既有排程尖峰，且盡量貼近整點降低玩家解鎖延遲；跟 Event_SP_Calc_Event_Invite_Bet_Records(00:02) 錯開2分鐘避免同時掃 summary_bet_records
COMMENT '每小時-成長彩金計劃投注解鎖計算'
DO
    CALL SP_calc_event_progressive_jackpot_bet_records(NULL);
*/
CREATE PROCEDURE `SP_calc_event_progressive_jackpot_bet_records`(IN p_IsRecalculated INT)
sp_main: BEGIN
    # 成長彩金計劃：計算會員累積投注解鎖彩金
    #
    # 2026-09-22 資料來源由 summary_bet_records（每小時彙整表）改為 total_bet_records（逐筆原始注單），
    # 比照 SP_calc_flow_limit 的水位模式，用 summary_control_tables（vendor_id=30001，功能代碼，
    # 見該表 vendor_id 欄位註解「10000以上為功能編號」）記錄「上次已經處理到哪個 total_bet_records.id」，
    # 每次只掃「上次水位 ~ 這次 MAX(id)」這段區間：
    # - 範圍只跟這段時間新增的注單量成正比，不受 total_bet_records 歷史累積總量影響（id 是 PK，範圍查詢
    #   走 index range scan，不是全表掃描）
    # - total_bet_records 一旦寫入不會被刪除重建或回頭改動（跟 summary_bet_records 不同——後者關帳前的
    #   桶會整批刪除重建、關帳後的補帳列會被蓋上「當天關帳時間」而不是原始小時），id 單調遞增可以放心
    #   當作「有沒有處理過」的判斷依據，不會有視窗過期或補帳晚到被永久跳過的問題
    # 根因（target/op_tickets#3282，TPower 會員29testabc1 投注體育類別未被計入解鎖金額）：舊版以
    # summary_bet_records.summary_time 做「前2小時~前1小時」滑動視窗，該筆流水彙整進 summary_bet_records
    # 的時間晚於對應視窗已經被掃過、寫入 event_progressive_jackpot_user_join_logs 冪等紀錄之後，
    # 之後永遠不會再回頭看那個視窗，導致漏算。改用 id 水位後，遲到的注單不管原始下注時間是哪個小時，
    # 只要 id 還沒被掃過，下一次執行就一定會抓到。
    #
    # 移除舊版 event_progressive_jackpot_user_join_logs 的 AlreadyRun（程式碼裡另外查 COUNT 判斷）冪等檢查：
    # id 水位本身已保證同一個 total_bet_records.id 只會落在唯一一次執行的掃描區間內，不會被重複處理，
    # 不需要再疊一層以「小時視窗」為鍵的冪等判斷（比照 SP_calc_flow_limit 本身也沒有這層檢查）。
    #
    # type=1 這筆 log 的 summary_start_time 改填「這次執行時的整點小時」（CurrentHour，DATE_FORMAT(NOW(),
    # '%Y-%m-%d %H:00:00')，整支 SP 執行期間只算一次、所有 log 共用同一個值，避免跨過整點造成同一批
    # 資料寫出兩個不同小時）。這欄位不是只給人看的——它自己的欄位註解就寫明「type=1 SP 寫入時用來避免
    # 同會員同遊戲類型同區間重複累加」，並且 unique_member_type_game_type_summary
    # (member_id, type, game_type, summary_start_time) 是實體唯一鍵：MySQL 唯一鍵對 NULL 不視為重複，
    # 先前改寫 NULL 等於把這道 DB 層防線關掉，改回填真實小時才是恢復原本設計的保護（跟 id 水位是兩層
    # 互補的防線，不是取代關係）。
    #
    # 連鎖轉移（CascadeBlock）額外補的那筆 type=1 log 維持 NULL 不變：它跟本次原始貢獻那筆共用同一個
    # member_id+game_type，如果也填 CurrentHour 會撞上面那個唯一鍵，這點原本的程式碼註解就已經講過
    # （避開 unique_member_type_game_type_summary），這裡沿用同一個理由，只是把「不能用 StartTime」
    # 換成「不能用 CurrentHour」。
    #
    # status 轉換全部在這支 SP 裡處理（PHP 端 EventProgressiveJackpotService::receiveOne() 只處理
    # 領取後 COMPLETED(2)→CLOSED(5) 這個轉換，其餘都不動 status）：
    # - 進行中的檔位如果 expire_time 已經過了（風控規則：活動到期自動結束），先改成已過期(3)，
    #   再檢查有沒有下一檔等待解鎖(1)，有的話補啟用成進行中(0)並依 event_progressive_jackpot_settings
    #   的 expire_day 重新算一個全新的 expire_time（不是沿用建立當下那個舊的——等待解鎖期間可能等了
    #   很久，沿用舊的 expire_time 會讓新啟用的檔位一啟用就已經過期），然後把這次的解鎖量套用在新檔位上；
    #   沒有下一檔就直接捨棄這次的解鎖量。這支 SP 裡另外兩個會做 WAITING_UNLOCK(1)→IN_PROGRESS(0)
    #   轉換的地方（沒有進行中時補啟用最小等待解鎖檔位、連鎖疊加啟用下一檔）比照同一個道理，也都在啟用當下
    #   重新算 expire_time，不是沿用建立時的舊值
    # - 累加後 point >= reward：封頂 point=reward，status 改 2(已完成)
    # - 封頂後如果還有滿溢出來的解鎖量：檢查下一檔，如果存在且是 1(等待解鎖)，轉成 0(進行中) 並把滿溢金額疊加上去，
    #   疊加後又滿了就繼續連鎖疊到再下一檔，直到沒有滿溢、或下一檔不存在/不是等待解鎖(例如已鎖定)為止；
    #   不是等待解鎖就不能跳過去，滿溢部分直接捨棄，不會硬塞給非等待解鎖的檔位。每次連鎖轉移都會寫兩筆
    #   log：type=3(修改狀態) 記狀態轉換本身(bet=0)，另外補一筆 type=1(解鎖彩金) 帶上這次轉移過來的滿溢
    #   流水量(bet=OverflowUnlock)，讓後台依 type=1 查詢投注解鎖量時看得到這筆差額，兩筆 summary_start_time
    #   都固定 NULL（type=1 的原始那筆已經佔用同一個 member_id+game_type+StartTime 組合，不能再用 StartTime）

    DECLARE Done INT DEFAULT 0;
    DECLARE MemberId, GameTypeId INT;
    DECLARE UnlockRate, PeriodBet DECIMAL(18, 4);
    DECLARE p_ExecStart DATETIME DEFAULT NOW();
    DECLARE p_ExecEnd DATETIME;

    # type=1 log 的 summary_start_time 用值：整支 SP 執行期間固定一次，不要在迴圈裡每次重算
    # NOW()，避免執行跨過整點時同一批資料被拆寫成兩個不同小時
    DECLARE CurrentHour DATETIME DEFAULT DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');

    # 功能代碼，非真實遊戲商編號（比照 SP_calc_flow_limit 用 20001 的慣例，summary_control_tables.vendor_id
    # 註解「10000以上為功能編號」；目前已知使用中：10003/10004/10005/10007/10008/12001/13001/14001/20001，
    # 30001 尚未被佔用）
    DECLARE p_VendorID INT DEFAULT 30001;

    # 取出本次 total_bet_records 執行的 ID 區間，比照 SP_calc_flow_limit
    # 注意：IFNULL 要包在整個子查詢外面，不能包在 SELECT 清單裡——vendor_id=p_VendorID
    # 這筆控制列如果還沒被 migration 建立過（見對應的 seed migration），子查詢會是 0 筆結果，
    # scalar subquery 對 0 筆結果的結果是 NULL，「SELECT IFNULL(stat_end_id, 0) FROM ... WHERE ...」
    # 這種寫法的 IFNULL 只在「有這一列、但 stat_end_id 欄位本身是 NULL」時才生效，救不了「整個
    # WHERE 條件找不到列」這種情況（SP_calc_flow_limit 原版也是這個寫法，只是它的控制列一直都存在，
    # 沒有踩到這個邊界——這裡改用 total_bet_records 是全新功能代碼，冷啟動一定會先踩到沒有列的情況）
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

    # 用 EXISTS 篩會員，不能改用 JOIN：這個會員可能沒有進行中(0)只有等待解鎖(1)的檔位（下面 FetchBlock
    # 會補啟用），JOIN 篩兩種狀態會因為同一會員可能同時有多筆等待解鎖紀錄造成一筆流水被 fan-out 重複加總
    # vendors.is_free=1 是免盤費商戶，比照 RankingService 的慣例排除，不計入解鎖流水
    # summary_bet_records.bet 實際上是 total_bet_records.valid_bet 的每小時彙總（見
    # SummaryBetRecordService），改直接讀來源表後對應改用 valid_bet，維持原本的解鎖計算基準不變
    DECLARE cur CURSOR FOR
        SELECT T.member_id, pbs.game_type_id, pbs.rate, SUM(T.valid_bet)
        FROM total_bet_records AS T
                 JOIN games AS g ON g.id = T.game_id
                 JOIN vendors AS v ON v.id = g.vendor_id AND v.is_free = 0
                 JOIN event_progressive_jackpot_bet_settings AS pbs ON pbs.game_type_id = g.type_id
        WHERE T.id > p_MinTotalBetRecordId AND T.id <= p_MaxTotalBetRecordId
          AND EXISTS (
              SELECT 1 FROM event_progressive_jackpot_user_joins AS epuj
              WHERE epuj.member_id = T.member_id AND epuj.status IN (0, 1)
          )
        GROUP BY T.member_id, pbs.game_type_id, pbs.rate
        HAVING SUM(T.valid_bet) > 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    # 這段區間沒有新注單（p_MaxTotalBetRecordId 為 NULL）：維持水位不動，避免下面 UPDATE 把
    # stat_end_id 寫成 NULL
    IF p_MaxTotalBetRecordId IS NULL THEN
        SET p_MaxTotalBetRecordId = p_MinTotalBetRecordId;
    END IF;

    OPEN cur;
    REPEAT
        FETCH cur INTO MemberId, GameTypeId, UnlockRate, PeriodBet;
        IF NOT Done THEN
            # 獨立區塊：避免 SELECT...FOR UPDATE 的 NOT FOUND 影響外層 cursor 的 Done
            FetchBlock: BEGIN
                DECLARE p_NotFound INT DEFAULT 0;
                DECLARE CurLevel INT DEFAULT 0;
                DECLARE CurPoint, CurReceive, CurReward DECIMAL(18, 4) DEFAULT 0;
                DECLARE CurExpireTime DATETIME DEFAULT NULL;
                DECLARE PeriodUnlock, CurAfterPoint, OverflowUnlock DECIMAL(18, 4) DEFAULT 0;
                DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_NotFound = 1;

                # 鎖定該會員進行中的參加紀錄
                # LIMIT 1 防禦：member_id 只在 (member_id, level) 上是唯一鍵，同時間只會有一筆 status=0
                # 是應用層邏輯保證、非 DB 唯一鍵保證，萬一未來出現不只一筆也不要讓 SELECT...INTO 直接報錯中斷整批
                SET p_NotFound = 0;
                SELECT level, point, receive, reward, expire_time INTO CurLevel, CurPoint, CurReceive, CurReward, CurExpireTime
                FROM event_progressive_jackpot_user_joins
                WHERE member_id = MemberId AND status = 0
                LIMIT 1
                FOR UPDATE;

                # 進行中的檔位如果已經過期：先改成已過期(3)，再檢查有沒有下一檔等待解鎖(1)可以補啟用；
                # 有的話啟用成進行中(0)並依設定重新算一個全新的 expire_time，讓這次的解鎖量套用在新檔位上；
                # 沒有下一檔就把 p_NotFound 設回 1，讓這次的解鎖量直接捨棄（不會累加到已過期的檔位上）
                IF NOT p_NotFound AND CurExpireTime IS NOT NULL AND CurExpireTime < NOW() THEN
                    ExpireBlock: BEGIN
                        DECLARE p_ExpireNotFound INT DEFAULT 0;
                        DECLARE ExpireNextLevel INT DEFAULT NULL;
                        DECLARE ExpireNextPoint, ExpireNextReceive, ExpireNextReward DECIMAL(18, 4) DEFAULT 0;
                        DECLARE ExpireNextDay INT DEFAULT 0;
                        DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_ExpireNotFound = 1;

                        UPDATE event_progressive_jackpot_user_joins
                        SET status = 3
                        WHERE member_id = MemberId AND level = CurLevel;

                        INSERT INTO event_progressive_jackpot_user_join_logs
                            (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
                        VALUE
                            (MemberId, NULL, CurLevel, 3, GameTypeId, UnlockRate, 0, CurPoint, CurPoint, CurReceive, CurReceive, 0, 3);

                        SET p_ExpireNotFound = 0;
                        SELECT level, point, receive, reward INTO ExpireNextLevel, ExpireNextPoint, ExpireNextReceive, ExpireNextReward
                        FROM event_progressive_jackpot_user_joins
                        WHERE member_id = MemberId AND level > CurLevel AND status = 1
                        ORDER BY level
                        LIMIT 1
                        FOR UPDATE;

                        IF p_ExpireNotFound THEN
                            SET p_NotFound = 1;
                        ELSE
                            SET p_ExpireNotFound = 0;
                            SELECT expire_day INTO ExpireNextDay
                            FROM event_progressive_jackpot_settings
                            WHERE level = ExpireNextLevel;

                            IF p_ExpireNotFound THEN
                                SET ExpireNextDay = 0;
                            END IF;

                            UPDATE event_progressive_jackpot_user_joins
                            SET status = 0,
                                expire_time = IF(ExpireNextDay > 0, DATE_ADD(NOW(), INTERVAL ExpireNextDay DAY), NULL)
                            WHERE member_id = MemberId AND level = ExpireNextLevel;

                            INSERT INTO event_progressive_jackpot_user_join_logs
                                (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
                            VALUE
                                (MemberId, NULL, ExpireNextLevel, 3, GameTypeId, UnlockRate, 0, ExpireNextPoint, ExpireNextPoint, ExpireNextReceive, ExpireNextReceive, 1, 0);

                            SET CurLevel = ExpireNextLevel;
                            SET CurPoint = ExpireNextPoint;
                            SET CurReceive = ExpireNextReceive;
                            SET CurReward = ExpireNextReward;
                        END IF;
                    END ExpireBlock;
                END IF;

                # 該會員目前沒有進行中的檔位（例如上次解鎖後 point 剛好等於 reward，沒有滿溢觸發連鎖啟用，
                # 導致下一檔一直停在等待解鎖沒被補啟用；或前面的過期檢查沒有下一檔可補）：檢查下一個檔位
                # （level 最小的等待解鎖(1)紀錄）狀態，沒有資料就直接跳過這筆；有資料且是等待解鎖(1)，
                # 就補啟用成進行中(0)並依設定重新算 expire_time，然後沿用下面同一套解鎖金額計算邏輯繼續
                # 往下跑，這次的投注解鎖量套用在剛啟用的這檔上，不會被捨棄
                IF p_NotFound THEN
                    SET p_NotFound = 0;
                    SELECT level, point, receive, reward INTO CurLevel, CurPoint, CurReceive, CurReward
                    FROM event_progressive_jackpot_user_joins
                    WHERE member_id = MemberId AND status = 1
                    ORDER BY level
                    LIMIT 1
                    FOR UPDATE;

                    IF NOT p_NotFound THEN
                        ActivateWaitingBlock: BEGIN
                            DECLARE p_ActivateNotFound INT DEFAULT 0;
                            DECLARE ActivateExpireDay INT DEFAULT 0;
                            DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_ActivateNotFound = 1;

                            SET p_ActivateNotFound = 0;
                            SELECT expire_day INTO ActivateExpireDay
                            FROM event_progressive_jackpot_settings
                            WHERE level = CurLevel;

                            IF p_ActivateNotFound THEN
                                SET ActivateExpireDay = 0;
                            END IF;

                            UPDATE event_progressive_jackpot_user_joins
                            SET status = 0,
                                expire_time = IF(ActivateExpireDay > 0, DATE_ADD(NOW(), INTERVAL ActivateExpireDay DAY), NULL)
                            WHERE member_id = MemberId AND level = CurLevel;

                            INSERT INTO event_progressive_jackpot_user_join_logs
                                (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
                            VALUE
                                (MemberId, NULL, CurLevel, 3, GameTypeId, UnlockRate, 0, CurPoint, CurPoint, CurReceive, CurReceive, 1, 0);
                        END ActivateWaitingBlock;
                    END IF;
                END IF;

                # 走到這裡 p_NotFound=0 代表現在有一筆可以計算解鎖金額的檔位：原本就進行中的、剛過期補啟用的，或剛補啟用的
                IF NOT p_NotFound THEN
                    SET PeriodUnlock = (PeriodBet * UnlockRate);
                    SET CurAfterPoint = LEAST(CurPoint + PeriodUnlock, CurReward);
                    SET OverflowUnlock = (CurPoint + PeriodUnlock) - CurAfterPoint;

                    UPDATE event_progressive_jackpot_user_joins
                    SET point = CurAfterPoint, status = IF(CurAfterPoint >= CurReward, 2, 0)
                    WHERE member_id = MemberId AND status = 0;

                    INSERT INTO event_progressive_jackpot_user_join_logs
                        (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
                    VALUE
                        (MemberId, CurrentHour, CurLevel, 1, GameTypeId, UnlockRate, PeriodBet, CurPoint, CurAfterPoint, CurReceive, CurReceive, 0, IF(CurAfterPoint >= CurReward, 2, 0));

                    # 這個遊戲類型這次貢獻的解鎖量把目前檔位灌滿了，滿溢部分連鎖疊加到下一檔（只有等待解鎖才會啟用，不會跳過）
                    WHILE OverflowUnlock > 0 DO
                        CascadeBlock: BEGIN
                            DECLARE p_NextNotFound INT DEFAULT 0;
                            DECLARE NextLevel INT DEFAULT NULL;
                            DECLARE NextStatus INT DEFAULT 0;
                            DECLARE NextPoint, NextReceive, NextReward, NextAfterPoint DECIMAL(18, 4) DEFAULT 0;
                            DECLARE NextExpireDay INT DEFAULT 0;
                            DECLARE NextAfterStatus INT DEFAULT 0;
                            DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_NextNotFound = 1;

                            # 不查 event_progressive_jackpot_settings 判斷檔位是否存在，檔位建立由
                            # SP_calc_event_progressive_jackpot_deposit 累積達標時處理，這裡只認 user_joins
                            # 已經有的下一筆等待解鎖紀錄；expire_day 則要查 settings，用來重算新啟用檔位的 expire_time
                            SET p_NextNotFound = 0;
                            SELECT level, status, point, receive, reward INTO NextLevel, NextStatus, NextPoint, NextReceive, NextReward
                            FROM event_progressive_jackpot_user_joins
                            WHERE member_id = MemberId AND level > CurLevel AND status = 1
                            ORDER BY level
                            LIMIT 1
                            FOR UPDATE;

                            IF p_NextNotFound THEN
                                # 沒有下一筆等待解鎖的參加紀錄（可能還沒達標，累積儲值另一支 SP 達標時才會建立），滿溢金額直接捨棄，結束連鎖
                                SET OverflowUnlock = 0;
                            ELSE
                                SET NextAfterPoint = LEAST(NextPoint + OverflowUnlock, NextReward);
                                SET NextAfterStatus = IF(NextAfterPoint >= NextReward, 2, 0);

                                # 這一檔如果因為這次連鎖疊加變成進行中(0)（還沒直接被灌滿到已完成(2)），
                                # 比照補啟用的邏輯重新算一個全新的 expire_time，不是沿用建立當下那個舊值
                                IF NextAfterStatus = 0 THEN
                                    SET p_NextNotFound = 0;
                                    SELECT expire_day INTO NextExpireDay
                                    FROM event_progressive_jackpot_settings
                                    WHERE level = NextLevel;

                                    IF p_NextNotFound THEN
                                        SET NextExpireDay = 0;
                                    END IF;

                                    UPDATE event_progressive_jackpot_user_joins
                                    SET point = NextAfterPoint, status = NextAfterStatus,
                                        expire_time = IF(NextExpireDay > 0, DATE_ADD(NOW(), INTERVAL NextExpireDay DAY), NULL)
                                    WHERE member_id = MemberId AND level = NextLevel;
                                ELSE
                                    UPDATE event_progressive_jackpot_user_joins
                                    SET point = NextAfterPoint, status = NextAfterStatus
                                    WHERE member_id = MemberId AND level = NextLevel;
                                END IF;

                                # summary_start_time 固定給 NULL：這是連鎖轉移，不是這個遊戲類型在這個區間的原始解鎖量，
                                # 不需要也不能受 unique_member_type_game_type_summary 限制（同一批次可能連鎖不只一檔）；
                                # type=3(修改狀態) 這筆維持 bet=0，純記狀態轉換本身
                                INSERT INTO event_progressive_jackpot_user_join_logs
                                    (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
                                VALUE
                                    (MemberId, NULL, NextLevel, 3, GameTypeId, UnlockRate, 0, NextPoint, NextAfterPoint, NextReceive, NextReceive, NextStatus, NextAfterStatus);

                                # 額外補一筆 type=1(解鎖彩金)，後台是依 type 分開顯示投注解鎖量，type=3 那筆看不到差額的
                                # 投注量；bet 固定填 PeriodBet（這批流水的原始有效投注），不能填 OverflowUnlock——
                                # 後者是解鎖點數的滿溢量，單位是彩金點數不是投注金額，連鎖轉移到下一檔也是同一筆
                                # 流水造成的，該記的仍是同一個 PeriodBet，不是每轉移一檔就換算成不同的「投注量」；
                                # summary_start_time 固定 NULL 避開 unique_member_type_game_type_summary（type=1
                                # 的原始那筆已經佔用同一個 member_id+game_type+StartTime 組合，這裡不能再用
                                # StartTime，否則撞唯一鍵）
                                INSERT INTO event_progressive_jackpot_user_join_logs
                                    (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
                                VALUE
                                    (MemberId, NULL, NextLevel, 1, GameTypeId, UnlockRate, PeriodBet, NextPoint, NextAfterPoint, NextReceive, NextReceive, NextStatus, NextAfterStatus);

                                SET OverflowUnlock = (NextPoint + OverflowUnlock) - NextAfterPoint;
                                SET CurLevel = NextLevel;
                            END IF;
                        END CascadeBlock;
                    END WHILE;
                END IF;
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
