CREATE PROCEDURE `SP_add_flow_limit_restrictions`(
    IN p_FlowLimitId INT,
    IN p_PrizeContentId INT
)
BEGIN
    # 儲值活動自動派獎新增稽核流水

    DECLARE p_ChatId INT DEFAULT -448705546;
    DECLARE p_TGMessage VARCHAR(2000);
    DECLARE p_Done INT DEFAULT 0;
    DECLARE VendorId, GameTypeId INT;
    DECLARE p_VipBet, p_Bet, p_DetailReach DECIMAL(18, 4) DEFAULT 0;

    # 抓出當前有效流水
    DECLARE cur CURSOR FOR
        SELECT vendor_id, game_type_id
        FROM prize_content_flow_limits
        WHERE prize_content_id  = p_PrizeContentId;

    # 監控當not found時, 就跳出
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET p_Done = 1;

    # 監控當SQLEXCEPTION時, 就ROLLBACK, 並發TG通知
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            SET p_TGMessage = CONCAT('[系統通知] 稽核流水異常 ', @p2);
            INSERT INTO telegram_messages(chat_id, message, status, admin)
            VALUES(p_ChatId, p_TGMessage, 0, 0);

            ROLLBACK;
        END;

    OPEN cur;
    REPEAT
        FETCH cur INTO VendorId, GameTypeId;
        IF NOT p_Done THEN

            INSERT INTO flow_limit_restrictions(flow_limit_id, vendor_id, type_id)
            VALUES(p_FlowLimitId, VendorId, GameTypeId);

        END IF;
    UNTIL p_Done END REPEAT;
    CLOSE cur;
END
