CREATE PROCEDURE `COM_Send_Error_Message`(
    IN p_TGMessage TEXT,
    IN p_MessageType VARCHAR(20)
)
BEGIN

    DECLARE ChatId INT DEFAULT -738288886;
    DECLARE TGMessage TEXT;
    DECLARE MessageType VARCHAR(20);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            SET TGMessage = CONCAT(
                '[系統通知] ADMIN_Send_Error_Message(',
                IFNULL(p_TGMessage, 'NULL'), ',',
                IFNULL(p_MessageType, 'NULL'),
                ') 異常 ', @p2);
            INSERT INTO telegram_messages(chat_id, message)
            VALUES(ChatId, p_TGMessage);
        END;

    SET MessageType = LOWER(IFNULL(p_MessageType, 'info'));

    IF p_MessageType = 'debug' THEN
        SET ChatId = -749538066;
    ELSEIF p_MessageType = 'warn' THEN
        SET ChatId = -652216091;
    ELSEIF p_MessageType = 'error' THEN
        SET ChatId = -871435961;
    ELSEIF p_MessageType = 'db' THEN
        SET ChatId = -738288886;
    ELSE
        # into
        SET ChatId = -707482472;
    END IF;

    SELECT ChatId, p_TGMessage;

    INSERT INTO telegram_messages(chat_id, message)
    VALUES(ChatId, p_TGMessage);
END
