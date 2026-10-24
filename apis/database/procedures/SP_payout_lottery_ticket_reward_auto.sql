CREATE PROCEDURE `SP_payout_lottery_ticket_reward_auto`(
    IN p_lucky_draws_id INT
)
BEGIN

    # 派發V-Jpot、W-Jpot獎項
    # 2022/05/23 Neil 		新增

    DECLARE Done INT DEFAULT 0;
    DECLARE AgentId, MemberId, PrizeMemberId, PrizeContentId, LuckDrawTicketId, EventId, EventDetailId, PointType INT(11);
    DECLARE TransactionLogType INT DEFAULT 18; #V-Jackpot
    DECLARE RewardPoint DECIMAL(18, 4);
    DECLARE BeforeRollingChip DECIMAL(18, 4);
    DECLARE BeforeBalance DECIMAL(18, 4) DEFAULT 0;
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE TransactionNo VARCHAR(30) DEFAULT '0';
    DECLARE FlowLimitMultiple INT DEFAULT 0;
    DECLARE ReferenceId INT DEFAULT 0;

    # 取出未發獎勵
    DECLARE cur CURSOR FOR
        SELECT pm.id, m.parent, pm.member_id, pm.content_id, pm.lottery_number, e.id, ed.id, pc.point_type, pc.point, IFNULL(s.value, 0)
        FROM lucky_draws AS l
                 JOIN events AS e ON e.id = l.event_id
                 JOIN event_details AS ed ON ed.id = l.event_detail_id
                 JOIN prize_members AS pm ON pm.event_detail_id = ed.id AND pm.status = 0
                 JOIN prize_contents AS pc ON pc.id = pm.content_id
                 JOIN members AS m ON m.id = pm.member_id
                 JOIN agents AS a ON a.id = m.parent
                 Left Join agent_customized_settings AS s ON s.agent_group_id = a.customized_setting_group_id AND s.setting_key = 6012 #活動流水倍數
        WHERE l.id = p_lucky_draws_id;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_payout_lottery_ticket_reward_auto(',
                    IFNULL(p_lucky_draws_id, 'NULL'), ',',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    OPEN cur;
    REPEAT
        FETCH cur
            INTO PrizeMemberId, AgentId, MemberId, PrizeContentId, LuckDrawTicketId, EventId, EventDetailId, PointType, RewardPoint, FlowLimitMultiple;
        IF NOT Done THEN
            #先把抽獎眷改成已領取
            UPDATE lucky_draw_tickets SET status = 1, time = NOW() WHERE id = LuckDrawTicketId;

            IF PointType = 1 THEN
                SET TransactionNo = CONCAT(DATE_FORMAT(NOW(), '%Y%m%d%H%i%S'),RIGHT(CONCAT('000', FLOOR(RAND()*100)), 4));
                #查詢玩家金額
                SELECT balance INTO BeforeBalance FROM members WHERE id = MemberId;
                # 寫入獎勵
                UPDATE members SET balance = (balance + RewardPoint) WHERE id = MemberId;
                # 寫入transaction_logs
                INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                            , type, point, vendor_point, rate, before_balance, balance, vendor_balance, status, remark, result, operator, platform, admin)
                VALUES(TransactionNo, AgentId, MemberId, 0, LuckDrawTicketId, TransactionLogType, RewardPoint, 0, 1, BeforeBalance, (BeforeBalance + RewardPoint), 0
                      , 1000, '', 0, 'system', 0, 51);
                SELECT LAST_INSERT_ID() INTO ReferenceId;
            ELSEIF PointType = 2 THEN
                # 取玩家禮碼餘額
                SELECT balance INTO BeforeRollingChip
                FROM member_wallets
                WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;

                IF BeforeRollingChip IS NOT NULL THEN
                    # 更新玩家禮碼餘額
                    UPDATE member_wallets SET balance = (balance + RewardPoint)
                    WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;
                ELSE
                    SET BeforeRollingChip = 0;
                    # 創建玩家禮碼錢包
                    INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
                    VALUES(MemberId, WalletTypeId, RewardPoint, 1, 0);
                END IF;

                # 寫入rolling_chip_logs
                INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance, balance, `status`, remark, `admin`)
                VALUES(AgentId, MemberId, 0, TransactionLogType, RewardPoint, BeforeRollingChip, (BeforeRollingChip + RewardPoint), 1000, '', 51);
                SELECT LAST_INSERT_ID() INTO ReferenceId;
            END IF;

            # 把prize_members改成已領取
            UPDATE prize_members SET status = 1 WHERE id = PrizeMemberId;

            # 有設定稽核流水倍數，就新增稽核流水log
            IF FlowLimitMultiple > 0 THEN
                INSERT INTO flow_limits(member_id, type, event_id, reference_id, flow_limit, reach, admin)
                VALUES(MemberId, TransactionLogType, EventId, ReferenceId, (RewardPoint * FlowLimitMultiple), 0, 0);
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;
END
