CREATE PROCEDURE `win_lose_isb`()
BEGIN

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] win_lose_isb(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    select m.id as member_id,
           g.id as game_id,
           SUM(if(b.type='BET' , (b.amount/100), 0)) as bet,
           SUM(if(b.type='WIN' , (b.amount/100), 0)-if(b.type='BET' , (b.amount/100), 0)) as win_lose,
           a.percentage
    from isb_bet_records as b
             left join isb_accounts as v on v.player_id = b.playerid
             left join members as m on m.id = v.member_id
             left join agents as a on a.id = m.parent
             left join games as g on g.game_type = b.gameid and g.vendor_id = 14
    where b.time between '2020-04-05 16:00:00' AND '2020-04-06 15:59:59'
    group by m.id, g.id;

END
