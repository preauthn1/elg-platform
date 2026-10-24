CREATE PROCEDURE `win_lose_ygg_sel`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_ygg_sel(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select *
    from ygg_bet_records
    group by DATE_FORMAT(DATE_ADD(createTime,INTERVAL 8 HOUR),'%Y-%m-%d');

END
