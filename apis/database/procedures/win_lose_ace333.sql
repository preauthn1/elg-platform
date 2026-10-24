CREATE PROCEDURE `win_lose_ace333`()
BEGIN

    -- select DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d'),sum(betAmount),sum(winAmount),sum(winAmount-betAmount) from ace333_bet_records group by DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d');

    select DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d'),
           sum(betAmount),
           sum(winAmount),
           sum(winAmount+jackpotContributionAmt-betAmount),
           sum(jackpotContributionAmt)
    from ace333_bet_records where (transactionType='BT' or transactionType='JP') AND status='C' and roundDetails!='free spin' group by DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d');

END
