CREATE PROCEDURE `SP_reset_refresh_ranking_wallet`(
    IN p_event_id INT
)
BEGIN
    # 刷新排行榜-重制玩家錢包
    /*
    CREATE EVENT Event_Reset_Refresh_ranking_Wallet
    ON SCHEDULE EVERY 1 DAY STARTS '2024-09-30 16:00:00' #系統時間UTC+0
    COMMENT '每天 - 重製刷新排行榜錢包'
    DO
        CALL SP_reset_refresh_ranking_wallet(1950);
    */

    DECLARE MemberId, IsBet INT DEFAULT 0;
    DECLARE EventAgentId INT DEFAULT (SELECT agent_id FROM `events` where id = p_event_id);
    DECLARE HIERARCHY_PATH VARCHAR(50) DEFAULT (SELECT hierarchy_path FROM agents where id = EventAgentId);
    DECLARE Point INT DEFAULT (SELECT `point` FROM event_details where event_id = p_event_id AND start_time <= now() AND end_time > now());
    DECLARE WalletTypeId INT DEFAULT 1003;
    DECLARE BeforeBalance DECIMAL(18, 4);
    DECLARE Done INT DEFAULT 0;

    DECLARE Cur CURSOR FOR
        SELECT mw.member_id
        FROM member_wallets AS mw
                 JOIN members AS m ON m.id = mw.member_id
                 JOIN agents AS a ON a.id = m.parent AND a.hierarchy_path LIKE CONCAT(HIERARCHY_PATH, '%')
        WHERE mw.wallet_type_id = 1003 AND (mw.is_bet = 1 or mw.balance != Point);

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_reset_refresh_ranking_wallet(',
                    IFNULL(p_event_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
    REPEAT
        FETCH Cur
            INTO MemberId;
        IF NOT Done THEN
            SELECT mw.balance, mw.is_bet INTO BeforeBalance, IsBet
            FROM members AS m
                     LEFT JOIN member_wallets AS mw ON m.id = mw.member_id AND mw.wallet_type_id = WalletTypeId
            WHERE m.id =  MemberId;

            IF BeforeBalance IS NOT NULL THEN
                IF IsBet > 0 THEN
                    UPDATE member_wallets SET balance = Point, is_bet = 0 WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;

                    INSERT INTO refresh_ranking_transaction_logs(member_id, type, before_balance, amount, after_balance, status, remark, admin)
                    VALUES(MemberId, 1, BeforeBalance, ABS(Point - BeforeBalance), Point, 1, 'SP更新', 51);

                ELSEIF BeforeBalance != Point THEN
                    UPDATE member_wallets SET balance = Point, is_bet = 0 WHERE member_id = MemberId AND wallet_type_id = WalletTypeId;

                    INSERT INTO refresh_ranking_transaction_logs(member_id, type, before_balance, amount, after_balance, status, remark, admin)
                    VALUES(MemberId, 1, BeforeBalance, ABS(Point - BeforeBalance), Point, 1, 'SP更新', 51);
                END IF;
            ELSE
                SET BeforeBalance = 0;
                # 創建玩家禮碼錢包
                INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
                VALUES(MemberId, WalletTypeId, Point, 1, 0);

                INSERT INTO refresh_ranking_transaction_logs(member_id, type, before_balance, amount, after_balance, status, remark, admin)
                VALUES(MemberId, 1, 0, Point, Point, 1, 'SP創建', 51);
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;

    update event_pgssw_accounts set member_id = (member_id * -1) where member_id > 0;
    update event_uuslotsw_accounts set member_id = (member_id * -1) where member_id > 0;
    update event_vpowersw_accounts set member_id = (member_id * -1) where member_id > 0;
    update event_wudisw_accounts set member_id = (member_id * -1) where member_id > 0;
    update event_fumasw_accounts set member_id = (member_id * -1) where member_id > 0;
    update event_ppsw_accounts set member_id = (member_id * -1) where member_id > 0;
END
