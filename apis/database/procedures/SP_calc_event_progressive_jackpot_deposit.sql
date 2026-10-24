CREATE PROCEDURE `SP_calc_event_progressive_jackpot_deposit`(IN p_order_id INT)
sp_main: BEGIN
    # 成長彩金計劃：累積儲值金額 + 依累積金額建立新達標的檔位參加紀錄
    # 由 PaymentService::UpdateMemberBalance() 在儲值完成後 CALL，每筆儲值都要執行
    # （不像 SP_event_invite_deposit 只在首儲時呼叫一次，成長彩金是累積「所有」儲值）
    # 只有會員的代理（或上層代理）有啟用中的 event_progressive_jackpots 且該代理鏈未被排除，才累加 total_deposit_point
    # 首儲進行中(0)不會擋累積儲值：total_deposit_point 照樣持續累加，Lv1+ 門檻該跨照跨，只是新建立的
    # 檔位會因為 HasInProgress=true（首儲還在進行中）而先建成等待解鎖(1)，呼應「完成當前檔位才算下一檔」
    # 的原則——這條原則只管「投注解鎖 point 的計算順序」，不管「儲值累積跟門檻判斷」
    #
    # 首儲(level=0)是獨立檔位，不計入累積儲值：user_details 還不存在時代表這是首儲的當下，依
    # event_progressive_jackpots.first_deposit_type 判斷這筆是否有資格拿首儲——1(創建帳號後第一筆)要求
    # orders 裡這筆之前完全沒有其他成功儲值，否則不算首儲；2(活動開始後第一筆)或未設定則不檢查，
    # 直接視為首儲。能拿首儲的話，這筆金額不累加、只建立首儲的 user_joins 就結束，不往下判斷其他檔位；
    # 不能拿首儲（本來就不是首儲候選、或首儲候選但被 first_deposit_type 判定不合格），這筆金額才正常
    # 累加進 total_deposit_point，再往下判斷 Lv1+ 一般檔位
    #
    # 檔位開啟交給這支 SP 處理（PHP 端 EventProgressiveJackpotService 不再判斷升等，只讀結果）：
    # 用這筆儲值「新增前/新增後」的累積金額算出新跨過了 event_progressive_jackpot_settings 哪幾個門檻
    # （不查現有 user_joins 狀態，unique_member_level 兜底防止萬一撞出重複），由小到大依序建立；
    # 同一筆儲值可能一次跨過不只一檔，event_progressive_jackpot_user_details 的
    # block_ip_member_id/block_mobile_member_id/block_realname_member_id 任一 >0（IP/手機號/真實姓名重複，
    # 判斷本身在別處完成，這裡只讀結果）就直接建成已鎖定(4)；沒有重複的話，會員目前完全沒有進行中(0)紀錄時，
    # 最早跨過門檻的那一檔直接開通成進行中(0)，其餘一律先建成等待解鎖(1)，之後的升級（等待解鎖→進行中、
    # 完成後連鎖啟用下一檔）都交給 SP_calc_event_progressive_jackpot_bet_records 處理，這支 SP 不負責。
    # expire_time 只在建立當下就是進行中(0) 時才會算（代表「開始倒數」），等待解鎖(1)/已鎖定(4) 都先留
    # NULL，等真的變成進行中時才由 bet_records SP 重新算，避免等待解鎖期間太久、一啟用就已經過期
    #
    # 由 PaymentService::UpdateMemberBalance() 在既有交易內呼叫，故不自行 START TRANSACTION/COMMIT
    # （比照 SP_event_deposit/SP_event_invite_deposit），交易邊界交給呼叫端；EXIT HANDLER 吞掉例外只發通知、
    # 不往上拋，避免這支 SP 出錯連帶讓真正的儲值交易被 rollback

    DECLARE MemberId, OrderStatus INT DEFAULT 0;
    DECLARE DepositAmount DECIMAL(18, 4) DEFAULT 0;
    # 這幾個字串變數都會拿去跟資料表欄位做比較(LIKE/=)，欄位都是 utf8mb4_unicode_ci，顯式指定 COLLATE
    # 避免變數預設 collation 跟連線/schema 預設不一致時噴 Illegal mix of collations（曾經在這裡因為
    # collation 不一致整段被 EXIT HANDLER 吞掉，資料完全沒寫入卻不會報錯，非常難查）
    DECLARE MemberHierarchyPath VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE MemberRegisterIp BIGINT DEFAULT NULL;
    DECLARE MemberMobile VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
    DECLARE MemberRealname VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
    DECLARE JackpotId INT DEFAULT 0;
    DECLARE JackpotIpBlock, JackpotMobileBlock, JackpotRealnameBlock TINYINT DEFAULT 0;
    DECLARE JackpotFirstDepositType INT DEFAULT 0;
    DECLARE BeforeDepositPoint, AfterDepositPoint, LastDepositPoint DECIMAL(18, 4) DEFAULT 0;
    DECLARE HasInProgress, HasBlock, IsNewDetail, AnyLevelCreated TINYINT DEFAULT 0;
    DECLARE CurrentLevelForLog INT DEFAULT 0;
    DECLARE IsFirstDeposit, FirstDepositDisqualified, CanCreateFirstDeposit TINYINT DEFAULT 0;
    DECLARE DupIpMemberId, DupMobileMemberId, DupRealnameMemberId INT DEFAULT 0;
    DECLARE p_NotFound INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_calc_event_progressive_jackpot_deposit(',
                    IFNULL(p_order_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_NotFound = 1;

    # 查訂單：儲值金額含手續費，比照 SP_event_invite_deposit 的 DepositPoint 計算方式；
    # 真實姓名直接帶 members.first_name（不再另查 realname_validation_requests，2026-09-04 改）
    SET p_NotFound = 0;
    SELECT o.member_id, (o.point + o.tax), o.status, a.hierarchy_path, m.register_ip, m.mobile, m.first_name
    INTO MemberId, DepositAmount, OrderStatus, MemberHierarchyPath, MemberRegisterIp, MemberMobile, MemberRealname
    FROM orders AS o
             JOIN members AS m ON m.id = o.member_id
             JOIN agents AS a ON a.id = m.parent
    WHERE o.id = p_order_id;

    IF p_NotFound OR OrderStatus != 3 THEN
        # 查無訂單，或訂單未完成上分（3=已上分），不累加
        LEAVE sp_main;
    END IF;

    # 查彩金設定：找出對這個會員生效的成長彩金活動（該會員代理鏈上含自己，有啟用中(status=1)的設定；
    # 理論上同一代理鏈只會有一層設定，多層都設定時取階層路徑最長、最接近該會員的一筆），一次把阻擋設定
    # (ip_block/mobile_block/realname_block) 跟首儲資格判斷方式(first_deposit_type) 也一起撈出來
    SET p_NotFound = 0;
    SELECT epj.id, epj.ip_block, epj.mobile_block, epj.realname_block, epj.first_deposit_type
    INTO JackpotId, JackpotIpBlock, JackpotMobileBlock, JackpotRealnameBlock, JackpotFirstDepositType
    FROM event_progressive_jackpots AS epj
             JOIN agents AS a ON a.id = epj.agent_id
    WHERE epj.status = 1
      AND MemberHierarchyPath LIKE CONCAT(a.hierarchy_path, '%')
    ORDER BY LENGTH(a.hierarchy_path) DESC
    LIMIT 1;

    IF p_NotFound OR JackpotId = 0 THEN
        # 該會員的代理鏈沒有啟用成長彩金計劃，不累加
        LEAVE sp_main;
    END IF;

    # 排除代理：該會員的代理鏈上任一層被排除，不累加
    IF EXISTS (
        SELECT 1
        FROM event_progressive_jackpot_exclude_agents AS epea
                 JOIN agents AS ea ON ea.id = epea.agent_id
        WHERE epea.event_progressive_jackpot_id = JackpotId
          AND MemberHierarchyPath LIKE CONCAT(ea.hierarchy_path, '%')
    ) THEN
        LEAVE sp_main;
    END IF;

    # user_details 還不存在 = 這是首儲候選（會員在這個活動下第一次被追蹤到儲值）
    SET IsFirstDeposit = NOT EXISTS (
        SELECT 1 FROM event_progressive_jackpot_user_details WHERE member_id = MemberId
    );

    # 首儲資格判斷：只有首儲候選才需要判斷。first_deposit_type=1(創建帳號後第一筆)要求這筆必須是
    # 會員有紀錄以來真正的第一筆成功儲值——這筆之前如果 orders 已經有其他成功儲值(status=3)，代表
    # 活動生效前會員早就儲值過，不算首儲；first_deposit_type=2(活動開始後第一筆)或未設定(0)不檢查；
    # 另外 event_progressive_jackpot_settings 要真的有設定 level=0 這一檔，不然也不能建立
    SET FirstDepositDisqualified = (
        IsFirstDeposit
        AND JackpotFirstDepositType = 1
        AND EXISTS (
            SELECT 1 FROM orders
            WHERE member_id = MemberId AND status = 3 AND id < p_order_id
        )
    );
    SET CanCreateFirstDeposit = (
        IsFirstDeposit
        AND NOT FirstDepositDisqualified
        AND EXISTS (SELECT 1 FROM event_progressive_jackpot_settings WHERE level = 0)
    );

    # 能拿首儲的這筆金額不累加（首儲是獨立檔位），INSERT 進去的值固定 0；不能拿首儲的（不是首儲候選，
    # 或首儲候選但不合格），這筆金額正常累加。用 INSERT...ON DUPLICATE KEY UPDATE 原子性寫入，避免
    # 「SELECT...FOR UPDATE 鎖不到不存在的列」導致併發下重複 INSERT 撞唯一鍵的競態；
    # ROW_COUNT() = 1 代表這次是真的新建（MySQL 慣例：INSERT...ON DUPLICATE KEY UPDATE 新建=1、更新=2）
    #
    # first_deposit 只在 VALUE 子句出現、ON DUPLICATE KEY UPDATE 不列它：這欄記的是「member_id 第一次被
    # 這支 SP 追蹤到」那筆的儲值金額（供會員詳細彈窗顯示用），不管這筆最後有沒有真的拿到首儲檔位獎勵
    # （CanCreateFirstDeposit）都要記，且只在新建當下寫一次，之後每次儲值只更新 total_deposit_point，
    # first_deposit 維持不變
    INSERT INTO event_progressive_jackpot_user_details (member_id, total_deposit_point, first_deposit)
    VALUE (MemberId, IF(CanCreateFirstDeposit, 0, DepositAmount), DepositAmount)
    ON DUPLICATE KEY UPDATE total_deposit_point = IF(CanCreateFirstDeposit, total_deposit_point, total_deposit_point + DepositAmount);

    SET IsNewDetail = (ROW_COUNT() = 1);

    # 只在會員第一次建立 user_details 時做重複判斷（不管這次是不是真的拿到首儲）：拿會員目前的
    # IP/手機號/真實姓名，去比對其他會員在 user_joins 留下的快照，命中就把對方的 member_id 寫進
    # block_xxx_member_id；之後每次儲值 total_deposit_point 只會更新（IsNewDetail=0），不會重複檢查
    IF IsNewDetail THEN
        IF JackpotIpBlock AND MemberRegisterIp IS NOT NULL THEN
            SET p_NotFound = 0;
            SELECT member_id INTO DupIpMemberId
            FROM event_progressive_jackpot_user_joins
            WHERE register_ip = MemberRegisterIp AND member_id != MemberId
            LIMIT 1;
            IF p_NotFound THEN
                SET DupIpMemberId = 0;
            END IF;
        END IF;

        IF JackpotMobileBlock AND MemberMobile IS NOT NULL THEN
            SET p_NotFound = 0;
            SELECT member_id INTO DupMobileMemberId
            FROM event_progressive_jackpot_user_joins
            WHERE mobile = MemberMobile AND member_id != MemberId
            LIMIT 1;
            IF p_NotFound THEN
                SET DupMobileMemberId = 0;
            END IF;
        END IF;

        IF JackpotRealnameBlock AND MemberRealname IS NOT NULL AND MemberRealname != '' THEN
            SET p_NotFound = 0;
            SELECT member_id INTO DupRealnameMemberId
            FROM event_progressive_jackpot_user_joins
            WHERE realname = MemberRealname AND member_id != MemberId
            LIMIT 1;
            IF p_NotFound THEN
                SET DupRealnameMemberId = 0;
            END IF;
        END IF;

        IF DupIpMemberId > 0 OR DupMobileMemberId > 0 OR DupRealnameMemberId > 0 THEN
            UPDATE event_progressive_jackpot_user_details
            SET block_ip_member_id = DupIpMemberId,
                block_mobile_member_id = DupMobileMemberId,
                block_realname_member_id = DupRealnameMemberId
            WHERE member_id = MemberId;
        END IF;
    END IF;

    # block_xxx_member_id 是重複的那個會員系統編號，>0 代表命中重複，任一項命中都視為需要鎖定；
    # 放在上面重複判斷之後才讀，確保剛好是第一次建立就命中重複時，HasBlock 能立刻反映
    SELECT total_deposit_point,
           IF(block_ip_member_id > 0 OR block_mobile_member_id > 0 OR block_realname_member_id > 0, 1, 0)
    INTO AfterDepositPoint, HasBlock
    FROM event_progressive_jackpot_user_details
    WHERE member_id = MemberId;

    IF CanCreateFirstDeposit THEN
        # 首儲：獨立建立、不進累積金額，建完直接結束，不往下判斷 Lv1+ 一般檔位
        FirstDepositBlock: BEGIN
            DECLARE FirstRewardType, FirstExpireDay INT DEFAULT 0;
            DECLARE FirstReward, FirstRewardPercentage, FirstMaxReward, FirstRewardAmount DECIMAL(18, 4) DEFAULT 0;
            DECLARE FirstStatus INT DEFAULT 0;

            SELECT reward_type, reward, reward_percentage, max_reward, expire_day
            INTO FirstRewardType, FirstReward, FirstRewardPercentage, FirstMaxReward, FirstExpireDay
            FROM event_progressive_jackpot_settings
            WHERE level = 0;

            # reward_type=1(固定金額) 直接用設定的 reward；reward_type=2(百分比) 用「這筆首儲的儲值金額」
            # 反推，例如首儲 200% 最高 5888：reward_percentage 存的是倍數(200%=2.0)，直接乘不用再除100，
            # 本次儲值金額(DepositAmount) x 倍數，封頂 max_reward
            SET FirstRewardAmount = IF(
                FirstRewardType = 1,
                FirstReward,
                LEAST(DepositAmount * FirstRewardPercentage, FirstMaxReward)
            );
            # 首儲一定是這個會員第一筆參加紀錄，不會有「已經有進行中」需要排隊的情況，只看有沒有被鎖定
            SET FirstStatus = IF(HasBlock, 4, 0);

            # expire_time 只在真正進行中(0)才算，代表「開始倒數」；已鎖定(4) 還沒開始倒數，先留 NULL，
            # 等後台解除鎖定、真的變成進行中時才需要算
            INSERT INTO event_progressive_jackpot_user_joins
                (member_id, level, register_ip, mobile, realname, expire_time, reward, status)
            VALUE
                (MemberId, 0, MemberRegisterIp, MemberMobile, MemberRealname,
                 IF(FirstStatus = 0 AND FirstExpireDay > 0, DATE_ADD(NOW(), INTERVAL FirstExpireDay DAY), NULL),
                 FirstRewardAmount, FirstStatus);

            # 建立當下 before/after 都填同一個值，代表「一開始就是這個狀態」，不是真的狀態轉換；
            # summary_start_time 固定 NULL，比照其他 type=3(修改狀態) 紀錄
            INSERT INTO event_progressive_jackpot_user_join_logs
                (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
            VALUE
                (MemberId, NULL, 0, 3, 0, 0, 0, 0, 0, 0, 0, FirstStatus, FirstStatus);

            # 首儲不計入累積儲值，AfterDepositPoint 在這個分支固定是 0，before/after 都填同一個值，
            # 純粹留一筆「這張訂單建立了首儲」的稽核紀錄，不是真的有累積金額異動
            INSERT INTO event_progressive_jackpot_user_detail_logs
                (member_id, order_id, level, before_deposit_point, after_deposit_point)
            VALUE
                (MemberId, p_order_id, 0, AfterDepositPoint, AfterDepositPoint);
        END FirstDepositBlock;

        LEAVE sp_main;
    END IF;

    # 走到這裡代表這筆金額已經正常累加進 total_deposit_point（不是首儲候選、或首儲候選但不合格）
    SET BeforeDepositPoint = (AfterDepositPoint - DepositAmount);

    # user_detail_logs 的 level 記法：這筆儲值如果沒跨過任何新檔位，記一筆「當下」的 level
    # （該會員目前已經建立過的最高檔位，一筆都還沒有的話是 0，理論上不會發生，因為走到這裡代表
    # 不是首儲候選就是首儲候選但不合格，前者早就有首儲(0)，後者也還是會落在 IFNULL 的 0）；
    # 如果跨過檔位，改成每跨一檔就在 NewLevelLoop 裡各記一筆該檔位的異動（同一筆訂單一次跨兩檔
    # 就會有兩筆紀錄），後台會用 level 查詢「跟這個檔位相關的訂單」，所以這裡先不寫，等跑完
    # NewLevelLoop 確認到底有沒有跨檔、跨了哪些檔再決定要不要補這筆「當下 level」的紀錄
    SET AnyLevelCreated = 0;

    # 依這筆儲值「新增前」跟「新增後」的累積金額，判斷這筆儲值跨過了幾個檔位門檻，不用管
    # event_progressive_jackpot_user_joins 現有的檔位狀態：每個檔位的 deposit_point 門檻固定不變，
    # 累積金額只會單調遞增，所以 (BeforeDepositPoint, AfterDepositPoint] 這個區間就是這筆儲值新跨過、
    # 需要新增的檔位，由小到大依序建立；LastDepositPoint 是這個迴圈內部的游標，每建立一檔就往前推進到
    # 該檔的門檻，下一輪只找還沒處理過的部分，unique_member_level 兜底防止萬一真的撞出重複。
    # 首儲(level=0)已經在上面獨立處理掉了，這裡不用額外排除：BeforeDepositPoint 永遠 >= 0，
    # deposit_point=0 用嚴格 > 比較永遠不會成立，自然不會被這個迴圈選到
    #
    # HasBlock=1（IP/手機號/真實姓名任一重複）時，新建的檔位一律直接是已鎖定(4)，不會是進行中/等待解鎖，
    # 要後台手動處理解除；沒有重複的話，會員目前完全沒有進行中(0)的紀錄時，最早跨過門檻的那一檔直接開通成
    # 進行中(0)，其餘（含同一批新增裡後面的檔位）一律先建成等待解鎖(1)，之後的升級都交給
    # SP_calc_event_progressive_jackpot_bet_records 依序啟用（比照它既有的
    # 「沒有進行中就補啟用最小等待解鎖檔位」邏輯），這支 SP 不負責升級判斷
    SET HasInProgress = EXISTS (
        SELECT 1 FROM event_progressive_jackpot_user_joins
        WHERE member_id = MemberId AND status = 0
    );
    SET LastDepositPoint = BeforeDepositPoint;

    NewLevelLoop: WHILE 1 DO
        NewLevelBlock: BEGIN
            DECLARE p_LevelNotFound INT DEFAULT 0;
            DECLARE NewLevel, NewRewardType, NewExpireDay INT DEFAULT NULL;
            DECLARE NewReward, NewRewardPercentage, NewMaxReward, NewRewardAmount, NewDepositPoint DECIMAL(18, 4) DEFAULT 0;
            DECLARE NewStatus INT DEFAULT 0;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_LevelNotFound = 1;

            SET p_LevelNotFound = 0;
            SELECT s.level, s.reward_type, s.reward, s.reward_percentage, s.max_reward, s.expire_day, s.deposit_point
            INTO NewLevel, NewRewardType, NewReward, NewRewardPercentage, NewMaxReward, NewExpireDay, NewDepositPoint
            FROM event_progressive_jackpot_settings AS s
            WHERE s.deposit_point > LastDepositPoint AND s.deposit_point <= AfterDepositPoint
            ORDER BY s.level
            LIMIT 1;

            IF p_LevelNotFound THEN
                LEAVE NewLevelLoop;
            END IF;

            SET AnyLevelCreated = 1;

            # 這筆訂單跨過這一檔，記一筆 user_detail_logs（level=NewLevel）；同一筆訂單如果一次跨
            # 好幾檔，NewLevelLoop 跑幾輪就會各記一筆，before/after 都是這整筆儲值的累積金額（不會
            # 因為跨多檔而拆分金額），只是用 level 標記「這筆訂單也跟這一檔有關」方便後台用 level 查詢
            INSERT INTO event_progressive_jackpot_user_detail_logs
                (member_id, order_id, level, before_deposit_point, after_deposit_point)
            VALUE
                (MemberId, p_order_id, NewLevel, BeforeDepositPoint, AfterDepositPoint);

            # reward_type=1(固定金額) 直接用設定的 reward；reward_type=2(百分比) 用「這筆觸發達標的儲值金額」
            # 反推，reward_percentage 存的是倍數(200%=2.0)，直接乘不用再除100，封頂 max_reward
            SET NewRewardAmount = IF(
                NewRewardType = 1,
                NewReward,
                LEAST(DepositAmount * NewRewardPercentage, NewMaxReward)
            );
            SET NewStatus = IF(HasBlock, 4, IF(HasInProgress, 1, 0));

            # expire_time 只在真正進行中(0)才算，代表「開始倒數」；等待解鎖(1)/已鎖定(4) 都還沒開始倒數，
            # 先留 NULL，等 SP_calc_event_progressive_jackpot_bet_records 真的補啟用成進行中時才重算——
            # 不能在這裡先算好，等待解鎖可能要等很久，沿用建立當下算的舊值，啟用時就已經過期了
            INSERT INTO event_progressive_jackpot_user_joins
                (member_id, level, register_ip, mobile, realname, expire_time, reward, status)
            VALUE
                (MemberId, NewLevel, MemberRegisterIp, MemberMobile, MemberRealname,
                 IF(NewStatus = 0 AND NewExpireDay > 0, DATE_ADD(NOW(), INTERVAL NewExpireDay DAY), NULL),
                 NewRewardAmount, NewStatus);

            # 建立當下 before/after 都填同一個值，代表「一開始就是這個狀態」，不是真的狀態轉換；
            # summary_start_time 固定 NULL，比照其他 type=3(修改狀態) 紀錄
            INSERT INTO event_progressive_jackpot_user_join_logs
                (member_id, summary_start_time, level, type, game_type, rate, bet, before_point, after_point, before_receive, after_receive, before_status, after_status)
            VALUE
                (MemberId, NULL, NewLevel, 3, 0, 0, 0, 0, 0, 0, 0, NewStatus, NewStatus);

            SET HasInProgress = 1;
            SET LastDepositPoint = NewDepositPoint;
        END NewLevelBlock;
    END WHILE NewLevelLoop;

    # 這筆儲值沒有跨過任何新檔位：記一筆「當下」的 level，取該會員目前已經建立過的最高檔位
    # （一筆都沒有就退回 0，理論上不會發生，因為能走到這裡代表首儲(0)一定已經存在）
    IF NOT AnyLevelCreated THEN
        SET p_NotFound = 0;
        SELECT MAX(level) INTO CurrentLevelForLog
        FROM event_progressive_jackpot_user_joins
        WHERE member_id = MemberId;

        IF p_NotFound OR CurrentLevelForLog IS NULL THEN
            SET CurrentLevelForLog = 0;
        END IF;

        INSERT INTO event_progressive_jackpot_user_detail_logs
            (member_id, order_id, level, before_deposit_point, after_deposit_point)
        VALUE
            (MemberId, p_order_id, CurrentLevelForLog, BeforeDepositPoint, AfterDepositPoint);
    END IF;
END sp_main
