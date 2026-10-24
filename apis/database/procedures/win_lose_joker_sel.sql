CREATE PROCEDURE `win_lose_joker_sel`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_joker_sel(',
                    IFNULL(p_summary_time, 'NULL'), ',',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select DATE_FORMAT(DATE_ADD(m.bet_time,INTERVAL 8 HOUR),'%Y-%m-%d')
         , sum(m.amount),sum(m.result)
         , sum(m.result-m.amount)
    from joker_bet_records as m
    group by DATE_FORMAT(DATE_ADD(m.bet_time,INTERVAL 8 HOUR),'%Y-%m-%d');

END
