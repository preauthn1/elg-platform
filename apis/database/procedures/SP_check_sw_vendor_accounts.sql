CREATE PROCEDURE `SP_check_sw_vendor_accounts`()
BEGIN
    # 檢查活動遊戲商預創帳號
    # 2024/11/15 Neil		新增

    /*	CREATE EVENT Event_Calc_Unlimit_Bonus
        ON SCHEDULE EVERY 1 DAY STARTS '2024/11/15 16:00:00' #系統時間UTC+0
        COMMENT '檢查活動遊戲商預創帳號'
        DO CALL SP_check_sw_vendor_accounts(); */

    DECLARE Done INT DEFAULT 0;
    DECLARE VendorInfoId, VendorId INT DEFAULT 0;

    # 取出開獎期數
    DECLARE cur CURSOR FOR
        SELECT id, vendor_id FROM event_vendor_infos Where status = 1;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    OPEN cur;
    REPEAT
        FETCH cur
            INTO VendorInfoId, VendorId;
        IF NOT Done THEN
            IF VendorId = 50 THEN
                Call SP_add_event_pgsssw_accounts(VendorInfoId);
            ELSEIF VendorId = 82 THEN
                Call SP_add_event_uuslotsw_accounts(VendorInfoId);
            ELSEIF VendorId = 145 THEN
                Call SP_add_event_bwgsw_accounts(VendorInfoId);
            ELSEIF VendorId = 170 THEN
                Call SP_add_event_wudisw_accounts(VendorInfoId);
            ELSEIF VendorId = 171 THEN
                Call SP_add_event_fumasw_accounts(VendorInfoId);
            END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE cur;
END
