CREATE PROCEDURE `SP_calc_flow_limit`(
    IN p_IsRecalculated INT
)
BEGIN
    # 稽核流水計算
    # call SP_calc_flow_limit(NULL);
    # call SP_calc_flow_limit(1);
    #
    # 2021-01-28 Mars 			Add
    # 2021-02-02 Mars 			Add flow_limit_details
    # 2021-12-01 Mars 			modify
    # 2021-12-17 Mars 			排除無限代設定為0或0以下的遊戲
    # 2022-09-22 Neil			[GFF]活動稽核流水新增限定遊戲商或類別
    # 2023-10-22 Mars 			使用valid_bet & 加上games.valid_bet_rate
    # 2023-10-22 Mars 			IFNULL 改用 COALESCE
    # 2024-01-16 Neil			新增優惠錢包
    # 2024-07-15 Mars 			稽核流水改用calc_flow_limit
    # 2025-04-15 Mars 			優化group by
    # 2026-08-11				新人專享挑戰流水(type=111/112/113)優先於一般流水消耗，僅次於優惠錢包鎖分模式(type=105)；不用event_id判斷，該欄位保留給活動v1
    # 2026-09-08				新人專享挑戰流水鎖填滿後溢出作廢，不再流向下一筆流水鎖，避免活動彩金流水混入儲值本金流水 target/tickets#3926
    # 2026-09-09				新人專享挑戰限定遊戲商(vendors.is_free=1)的流水，會員曾參加過新人挑戰就只能算給
    #							新人挑戰流水鎖(溢出作廢)，不得流向儲值或其他一般流水鎖 target/tickets#3926
    # 2026-09-10				上述隔離規則收斂為只針對第二階段挑戰流水鎖(type=111，活動錢包)；第四階段彩金流水鎖
    #							(type=112，主錢包，玩家自己的錢在玩)不受影響，填滿後溢出正常流向下一筆鎖 target/tickets#3926
    # 2026-09-15				免盤費vendor(vendors.is_free=1)不得計入非挑戰流水鎖的隔離規則，拿掉「限參加過
    #							新人挑戰的會員」這個條件，改為不分會員一律適用，避免誤算進其他流水鎖 target/tickets#3926
    # 2026-09-16				p_VendorIdLimit/p_GameTypeLimit 由 VARCHAR(100) 改為 TEXT，限制筆數多時
    #							(如新人挑戰第四階段44個vendor)GROUP_CONCAT會超長導致截斷例外 target/op_tickets#3205

    /*
    新增Event
    CREATE EVENT Event_SP_Calc_Flow_Limit
	ON SCHEDULE EVERY 10 MINUTE STARTS '2021/12/01 00:00:00' #系統時間UTC+0
    COMMENT '每10分鐘-稽核流水計算'
    DO
		CALL SP_calc_flow_limit(NULL);
	*/

    DECLARE p_VendorID INT DEFAULT 20001;
    DECLARE p_ChatId INT DEFAULT -448705546;
    DECLARE p_TGMessage VARCHAR(2000);
    DECLARE p_ExecStart DATETIME DEFAULT NOW();
    DECLARE p_ExecEnd DATETIME;

    # 取出本次total_bet_records執行的ID區間
    DECLARE p_MinTotalBetRecordId BIGINT
        DEFAULT (
            SELECT IFNULL(stat_end_id, 1)
            FROM summary_control_tables
            WHERE vendor_id = p_VendorID
        );

    DECLARE p_MaxTotalBetRecordId BIGINT
        DEFAULT (
            SELECT MAX(id)
            FROM total_bet_records
            WHERE id >= p_MinTotalBetRecordId
        );

    Block1: BEGIN
        DECLARE p_Done INT DEFAULT 0;
        DECLARE p_MemberId, p_BetVendorId, p_BetGameType INT;
        DECLARE p_VipBet, p_Bet, p_DetailReach DECIMAL(18, 4) DEFAULT 0;

        # 抓出當前有效流水
        DECLARE cur CURSOR FOR
            SELECT Z.member_id
                 , Z.vendor_id AS vendor_id
                 , g.type_id AS game_type
                 , SUM(CASE WHEN COALESCE(VIG.calc_flow_limit, g.calc_flow_limit, V.calc_flow_limit, 0) > 0 THEN Z.bet ELSE 0 END) AS bet
                 , SUM(CASE WHEN COALESCE(VIG.calc_flow_limit, g.calc_flow_limit, V.calc_flow_limit, 0) > 0 THEN Z.bet ELSE 0 END) AS vip_bet
            FROM (
                     SELECT T.member_id
                          , T.vendor_id
                          , T.game_id
                          , ANY_VALUE(T.bet) AS bet
                          , ANY_VALUE(AV.vendor_info_id) AS vendor_info_id
                          , ANY_VALUE(A.hierarchy_path) AS hierarchy_path
                     FROM (
                              SELECT R.vendor_id
                                   , R.game_id
                                   , R.member_id
                                   , SUM(R.valid_bet) AS bet #改用valid_bet
                              FROM total_bet_records AS R
                              WHERE R.id > p_MinTotalBetRecordId
                                AND R.id <= p_MaxTotalBetRecordId
                              GROUP BY R.vendor_id, R.game_id, R.member_id
                          ) AS T
                              JOIN members AS M ON M.id = T.member_id
                              JOIN (
                         SELECT member_id
                         FROM flow_limits
                         WHERE `status` = 0
                         GROUP BY member_id
                     ) AS FL ON FL.member_id = M.id
                              JOIN agents AS A ON A.id = M.parent
                              JOIN (
                         SELECT av.agent_id
                              , av.vendor_id
                              , a.hierarchy_path
                              , av.vendor_info_id
                              , LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path) AS indexs
                         FROM agent_vendors AS av
                                  JOIN agents AS a ON a.id = av.agent_id AND a.host_id = 0
                         WHERE av.`status` = 1
                     ) AS AV
                                   ON AV.vendor_id = T.vendor_id
                                       AND A.hierarchy_path LIKE CONCAT(AV.hierarchy_path, '%')
                     GROUP BY T.member_id, T.vendor_id, T.game_id HAVING MAX(AV.indexs)
                 ) AS Z
                     JOIN vendors AS V ON V.id = Z.vendor_id
                     JOIN games AS g ON g.id = Z.game_id
                     LEFT JOIN vendor_info_games AS VIG
                               ON VIG.game_id = Z.game_id
                                   AND VIG.vendor_info_id = Z.vendor_info_id
            GROUP BY Z.member_id, Z.vendor_id, g.type_id;

        # 監控當not found時, 就跳出
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done = 1;

        # 監控當SQLEXCEPTION時, 就ROLLBACK, 並發TG通知
        DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                GET DIAGNOSTICS CONDITION 1
                    @p2 = MESSAGE_TEXT;

                ROLLBACK;

                # 發通知
                SET p_TGMessage = CONCAT('[系統通知] 稽核流水異常 ', @p2);
                INSERT INTO telegram_messages(chat_id, message, `status`, `admin`)
                VALUES(p_ChatId, p_TGMessage, 0, 0);

            END;

        OPEN cur;
        REPEAT
            FETCH cur INTO p_MemberId, p_BetVendorId, p_BetGameType, p_Bet, p_VipBet;
            IF NOT p_Done THEN

                Block2: BEGIN
                    DECLARE p_Done2, p_FlowLimitType INT DEFAULT 0;
                    DECLARE x INT DEFAULT 1;
                    DECLARE p_FID, p_FMember_id, p_LimitCount INT;
                    DECLARE p_FlowLimit, p_Reach DECIMAL(18,4);
                    DECLARE p_VendorIdLimit, p_GameTypeLimit TEXT;
                    DECLARE l_VendorId, l_GameType INT;
                    DECLARE CanAdd BOOLEAN DEFAULT false;

                    DECLARE cur1 CURSOR FOR
                        SELECT fl.id
                             , ANY_VALUE(fl.member_id) AS member_id
                             , ANY_VALUE(fl.flow_limit) AS flow_limit
                             , ANY_VALUE(fl.reach) AS reach
                             , GROUP_CONCAT(sub.vendor_id) AS VendorIdLimit
                             , GROUP_CONCAT(sub.type_id) AS GameTypeLimit
                             , ANY_VALUE(fl.`type`) AS FlowLimitType #原始type值，直接拿來比對即可，不用再另外拆布林旗標
                             , COUNT(*) as LimitCount
                        FROM flow_limits AS fl
                                 LEFT JOIN flow_limit_restrictions as sub on sub.flow_limit_id = fl.id
                        WHERE member_id = p_MemberId
                          AND `status` = 0
                        GROUP BY fl.id
                        #105=優惠錢包鎖分；111/112=新人專享挑戰(CHALLENGE_FLOW/BONUS_FLOW)，優先於一般流水
                        ORDER BY (FlowLimitType = 105) DESC, (FlowLimitType IN (111, 112, 113)) DESC, fl.id ASC;
                    DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done2 = 1;

                    OPEN cur1;
                    REPEAT
                        FETCH cur1
                            INTO p_FID, p_FMember_id, p_FlowLimit, p_Reach, p_VendorIdLimit, p_GameTypeLimit, p_FlowLimitType, p_LimitCount;
                        IF NOT p_Done2 THEN
                            IF p_Bet <= 0 THEN
                                LEAVE Block2;
                            ELSE
                                IF p_VendorIdLimit IS NULL THEN
                                    SET CanAdd = true;
                                ELSE
                                    SET x = 1;
                                    SET CanAdd = false; # 先改成false,如果成功遊戲商跟遊戲類別符合就改成true
                                    WHILE x <= p_LimitCount DO
                                            SET l_VendorId = SUBSTRING_INDEX(SUBSTRING_INDEX(p_VendorIdLimit, ",", x), ",", -1);
                                            SET l_GameType = SUBSTRING_INDEX(SUBSTRING_INDEX(p_GameTypeLimit, ",", x), ",", -1);
                                            IF (l_VendorId = 0 OR l_VendorId = p_BetVendorId) THEN
                                                IF (l_GameType = 0 OR l_GameType = p_BetGameType) THEN
                                                    SET CanAdd = true;
                                                    SET x = x + p_LimitCount; # 確認這筆流水可以加後直接加上跑的次數跳出迴圈
                                                END IF;
                                            END IF;
                                            SET x = x + 1;
                                        END WHILE;
                                END IF;

                                #新人專享挑戰第二階段挑戰流水（type=111，活動限定遊戲商vendors.is_free=1，目前為
                                #FumaSW免盤費/WudiSW免盤費）用的是活動錢包(事件錢包)，這類免盤費vendor的流水一律
                                #不得消耗非挑戰流水鎖（不得流向promotion/儲值等其他鎖）——不限參加過新人挑戰的會員，
                                #避免一般儲值流水鎖等未設vendor限制(flow_limit_restrictions為空即視為不限vendor)的
                                #鎖被免盤費流水灌入。第四階段彩金流水(type=112)是走主錢包、限定真實vendor(非
                                #is_free)，不受此限制，溢出可正常流向下一筆鎖 target/tickets#3926
                                IF p_FlowLimitType <> 111
                                   AND EXISTS (SELECT 1 FROM vendors WHERE id = p_BetVendorId AND is_free = 1)
                                THEN
                                    SET CanAdd = false;
                                END IF;

                                IF CanAdd = true THEN
                                    IF p_Bet >= (p_FlowLimit - p_Reach) THEN
                                        UPDATE flow_limits
                                        SET reach = flow_limit, `status` = 1
                                        WHERE id = p_FID;

                                        #如果是鎖分模式的流水，要解鎖玩家的鎖分模式
                                        IF p_FlowLimitType = 105 THEN
                                            UPDATE members SET event_mode = 0 WHERE id = p_FMember_id;
                                        END IF;

                                        SET p_DetailReach = (p_FlowLimit - p_Reach);

                                        #第二階段挑戰流水鎖（type=111，活動錢包）填滿後，剩餘流水作廢、不得流向
                                        #下一筆鎖（避免活動彩金流水被算進儲值本金流水或其他鎖）；第四階段彩金流水鎖
                                        #（type=112，主錢包）是玩家自己的錢在玩，填滿後多餘的照舊正常流向下一筆鎖
                                        IF p_FlowLimitType = 111 THEN
                                            SET p_Bet = 0;
                                        ELSE
                                            SET p_Bet = (p_Bet - (p_FlowLimit - p_Reach));
                                        END IF;
                                    ELSE
                                        UPDATE flow_limits
                                        SET reach = (reach + p_Bet)
                                        WHERE id = p_FID;

                                        SET p_DetailReach = p_Bet;
                                        SET p_Bet = 0;
                                    END IF;

                                    # 寫入稽核流水明細
                                    INSERT INTO flow_limit_details
                                    (flow_limit_id, start_total_bet_record_id, end_total_bet_record_id, start_flow_limit, end_flow_limit, reach)
                                    SELECT p_FID
                                         , (p_MinTotalBetRecordId + 1)
                                         , p_MaxTotalBetRecordId
                                         , p_Reach
                                         , (p_Reach + p_DetailReach)
                                         , 	p_DetailReach
                                    FROM flow_limits
                                    WHERE id = p_FID;

                                    SET p_DetailReach = 0;
                                END IF;
                            END IF;
                        END IF;
                    UNTIL p_Done2 END REPEAT;
                    CLOSE cur1;
                END Block2;

                IF p_VipBet > 0 THEN
                    #跑VIP稽核流水計算
                    CALL SP_calc_vip_flow_limit(p_MemberId, p_VipBet);
                    SET p_VipBet = 0;
                END IF;
            END IF;
        UNTIL p_Done END REPEAT;
        CLOSE cur;
    END Block1;

    SET p_ExecEnd = NOW();
    SET p_IsRecalculated = IFNULL(p_IsRecalculated, 0);

    # 目前無新資料
    #更新控制表
    IF p_IsRecalculated = 0 THEN
        UPDATE summary_control_tables
        SET stat_start_id = p_MinTotalBetRecordId
          , stat_end_id = p_MaxTotalBetRecordId
          , exec_start_time = p_ExecStart
          , exec_end_time = p_ExecEnd
        WHERE vendor_id = p_VendorID;
    END IF;
    #寫紀錄
    INSERT INTO summary_control_logs
    (vendor_id, params, exec_start_time, exec_end_time)
    VALUES
        (p_VendorID,
         CONCAT(p_MinTotalBetRecordId, ' - ', p_MaxTotalBetRecordId),
         p_ExecStart, p_ExecEnd);
END
