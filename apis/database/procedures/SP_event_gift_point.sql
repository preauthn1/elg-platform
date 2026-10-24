CREATE PROCEDURE `SP_event_gift_point`(
    IN p_type INT,
    IN p_member_id INT,
    IN p_agent_id INT
)
BEGIN

    # 註冊送(20)/綁定手機送(21)
    # 2020-11-10 Mars 		Add
    # 2023-01-19 Mara 		修改: 改為組別設置&加上稽核流水倍數
    # 2023-04-13 Neil 		修改: 可選擇獎勵為點數或禮碼

    DECLARE GiftPoint, BeforeBalance, AfterBalance DECIMAL(10,2);
    DECLARE SettingId, GiftSettingId INT DEFAULT 0;
    DECLARE GiftType INT DEFAULT 1;
    DECLARE TransactionType INT DEFAULT 20;
    DECLARE RollingChipType INT DEFAULT 10;
    DECLARE Remark VARCHAR(50);
    DECLARE FirstBind BOOLEAN DEFAULT FALSE;
    DECLARE FlowLimitSettingId INT DEFAULT 6503;
    DECLARE FlowLimitRate DECIMAL (8, 4);
    DECLARE WalletTypeId INT DEFAULT 1002;
    DECLARE OperatorId INT DEFAULT 51;
    DECLARE EventType, MemberId, AgentId INT DEFAULT 51;

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            select 'SQLEXCEPTION', @p2;
            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_event_gift_point(',
                    IFNULL(p_type, 'NULL'), ',',
                    IFNULL(p_member_id, 'NULL'), ',',
                    IFNULL(p_agent_id, 'NULL'),
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET EventType = p_type;
    SET MemberId = p_member_id;
    SET AgentId = p_agent_id;

    IF EventType IS NOT NULL THEN
        IF EventType = 1 THEN
            # 註冊送
            #SET SettingId = 65001;  #代理個人
            SET SettingId = 6501; #組別
            SET TransactionType = 20;
            SET RollingChipType = 10;
            SET Remark = '注册送';
            SET GiftSettingId = 6504;

        ELSEIF EventType = 2 THEN
            # 綁定手機送
            #SET SettingId = 65002; #代理個人
            SET SettingId = 6502; #組別
            SET TransactionType = 21;
            SET RollingChipType = 11;
            SET Remark = '绑定手机送';
            SET GiftSettingId = 6505;

            #如果是綁定送, 要確認是否已送過
            SELECT CASE WHEN IFNULL(id, 0) > 0 THEN true ELSE false END
            INTO FirstBind
            FROM transaction_logs
            WHERE member_id = MemberId
              AND type = TransactionType;
        END IF;

        # 取得註冊送點數 by 代理個人
        #SELECT IFNULL(value, 0) INTO GiftPoint
        #FROM agent_personal_settings
        #WHERE setting_id = SettingId AND agent_id = AgentId;

        # 取得註冊送點數 by 組別
        SELECT IFNULL(value, 0) INTO GiftPoint
        FROM agent_customized_settings AS s
                 JOIN agents AS a
                      ON a.customized_setting_group_id = s.agent_group_id
                          AND a.id = AgentId
        WHERE setting_key = SettingId;

        # 取得獎勵類別 by 組別
        SELECT IFNULL(value, 1) INTO GiftType
        FROM agent_customized_settings AS s
                 JOIN agents AS a
                      ON a.customized_setting_group_id = s.agent_group_id
                          AND a.id = AgentId
        WHERE setting_key = GiftSettingId;

        #
        IF GiftPoint > 0 AND FirstBind = false THEN
            # 檢查玩家是否存在
            IF EXISTS (SELECT * FROM members WHERE id = MemberId) THEN
                IF GiftType = 1 THEN
                    # 取玩家餘額
                    SELECT balance INTO BeforeBalance
                    FROM members
                    WHERE id = MemberId;

                    SET AfterBalance = (BeforeBalance + GiftPoint);

                    # 更新玩家餘額
                    UPDATE members
                    SET balance = AfterBalance
                    WHERE id = MemberId;

                    # 寫入transaction_logs
                    INSERT INTO transaction_logs(transaction_no, agent_id, member_id, vendor_id, order_id
                                                , type, point, tax, vendor_point, rate
                                                , before_balance, balance, vendor_balance, `status`, remark
                                                , checksum, result, operator, platform, `admin`)
                    VALUES('', AgentId, MemberId, 0, 0
                          , TransactionType, GiftPoint, 0, 0, 0
                          , BeforeBalance, AfterBalance, 0, 1000, Remark
                          , '', '', 0, 0, OperatorId);
                ELSEIF  GiftType = 2 THEN
                    # 取玩家禮碼餘額
                    SELECT balance INTO BeforeBalance
                    FROM member_wallets
                    WHERE id = MemberId AND wallet_type_id = WalletTypeId;

                    IF BeforeBalance IS NOT NULL THEN
                        SET AfterBalance = (BeforeBalance + GiftPoint);

                        # 更新玩家禮碼餘額
                        UPDATE member_wallets
                        SET balance = AfterBalance
                        WHERE id = MemberId AND wallet_type_id = WalletTypeId;
                    ELSE
                        SET BeforeBalance = 0;
                        SET AfterBalance = GiftPoint;
                        # 創建玩家禮碼錢包
                        INSERT INTO member_wallets(member_id, wallet_type_id, balance, `status`, `admin`)
                        VALUES(MemberId, WalletTypeId, GiftPoint, 1, 0);
                    END IF;

                    # 寫入crolling_chip_logs
                    INSERT INTO rolling_chip_logs(agent_id, member_id, vendor_id, `type`, amount, before_balance
                                                 , balance, `status`, remark, `admin`)
                    VALUES(AgentId, MemberId, 0, RollingChipType, GiftPoint, BeforeBalance, AfterBalance, 1000, Remark, OperatorId);
                END IF;

                # 取稽核流水倍數
                SELECT IFNULL(value, 0) INTO FlowLimitRate
                FROM agent_customized_settings AS s
                         JOIN agents AS a
                              ON a.customized_setting_group_id = s.agent_group_id
                                  AND a.id = AgentId
                WHERE setting_key = FlowLimitSettingId;

                # 寫入稽核流水
                IF FlowLimitRate > 0 THEN
                    INSERT INTO flow_limits(member_id, type, reference_id, flow_limit, admin)
                    VALUES(MemberId, TransactionType, 0, (GiftPoint * FlowLimitRate), OperatorId);
                END IF;

            END IF;
        END IF;
    END IF;
END
