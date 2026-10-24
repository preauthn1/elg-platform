CREATE PROCEDURE `win_lose_ag`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_ag(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 0 HOUR),'%Y-%m-%d')
         ,sum(bet_amount),sum(valid_bet_amount)
         ,sum(net_amount)
    from ag_bet_records
    where data_type!='TR'
    group by DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 0 HOUR),'%Y-%m-%d');

END
