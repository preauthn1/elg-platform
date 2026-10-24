CREATE PROCEDURE `SP_add_event_fumasw_accounts`(IN `p_event_vendor_info_id` INT)
BEGIN
    DECLARE VendorId INT DEFAULT 171; # fuma
    DECLARE PreparedAccountNumber INT; # 預創帳號數量
    DECLARE HavingAccountNumber INT; # 已創建帳號數量
    DECLARE AccountPrefix VARCHAR(200); # 預創帳號前綴
    DECLARE MaxNumber INT DEfAULT 0; # 已創建帳號最大編號

    IF EXISTS(SELECT * FROM event_vendor_infos WHERE id = p_event_vendor_info_id AND vendor_id = VendorId AND status = 1) THEN
        SELECT prepared_account_number, account_prefix INTO PreparedAccountNumber, AccountPrefix
        FROM event_vendor_infos
        WHERE id = p_event_vendor_info_id;

        IF PreparedAccountNumber > 0 THEN
            SELECT COUNT(*) INTO HavingAccountNumber
            FROM event_fumasw_accounts
            WHERE member_id = 0 AND event_vendor_info_id = p_event_vendor_info_id;

            IF PreparedAccountNumber > HavingAccountNumber THEN
                SELECT TRIM(LEADING '0' FROM REPLACE(username, AccountPrefix, '')) INTO MaxNumber
                FROM event_fumasw_accounts
                WHERE member_id = 0 AND event_vendor_info_id = p_event_vendor_info_id ORDER BY username DESC LIMIT 1;

                WHILE PreparedAccountNumber > HavingAccountNumber DO
                        SET MaxNumber = MaxNumber + 1;

                        INSERT INTO event_fumasw_accounts(event_vendor_info_id, username, member_id, admin)
                        VALUES(p_event_vendor_info_id, CONCAT(AccountPrefix, LPAD(MaxNumber, 8, 0)), 0, 0);

                        SET HavingAccountNumber = HavingAccountNumber + 1;
                    END WHILE;
            END IF;
        END IF;
    END IF;
END
