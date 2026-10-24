CREATE PROCEDURE `win_lose_918_sel`()
BEGIN

    select `date`,sum(win) from scr_daily_wins group by `date`;

END
