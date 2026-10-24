CREATE PROCEDURE `SP_AccessTokenClear`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_AccessTokenClear(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    DELETE FROM member_access_tokens WHERE expire_date < now();

END
