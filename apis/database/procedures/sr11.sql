CREATE PROCEDURE `sr11`()
BEGIN
    DECLARE	nowaid INTEGER;
    DECLARE done INTEGER DEFAULT 0;
    DECLARE finished INTEGER DEFAULT 0;
    DECLARE	aid INTEGER;
    DECLARE gid INTEGER;
    DECLARE totalbet DECIMAL(20);
    DECLARE platform NVARCHAR(50);
    DECLARE category NVARCHAR(50);
    DECLARE totalwinlose DECIMAL(20);

    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] sr11(',
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    SET nowaid=33893;
    CREATE TEMPORARY TABLE TEMP(
                                   `agent_id` INT NOT NULL
    );

    BEGIN
        runAgent: LOOP

            BEGIN
                DECLARE agentCursor
                    CURSOR FOR
                    SELECT id FROM agents WHERE parent IN (nowaid);
                DECLARE CONTINUE HANDLER
                    FOR NOT FOUND SET done = 1;
                OPEN agentCursor;

                getAgent:LOOP
                    FETCH agentCursor INTO aid;
                    IF done = 1 THEN
                        LEAVE getAgent;
                    END IF;

                    IF EXISTS(SELECT * FROM agents WHERE parent=aid LIMIT 1) THEN
                        -- INSERT INTO TEMP(`agent_id`) VALUES(aid);
                        SET nowaid = aid;
                        -- SELECT nowaid;
                        -- SET nowaid = (SELECT id FROM agents WHERE parent IN (aid));
                        INSERT INTO TEMP(`agent_id`) VALUES(aid);
                        ITERATE runAgent;
                    END IF;

                    INSERT INTO TEMP(`agent_id`) VALUES(aid);

                END LOOP getAgent;
                CLOSE agentCursor;
            END;

            IF done=1 THEN
                LEAVE runAgent;
            END IF;

        END LOOP runAgent;
    END;

    SELECT * FROM TEMP;

    BEGIN
        DECLARE curCursor
            CURSOR FOR
            -- SELECT id FROM agents WHERE parent IN(33893);
            SELECT game_id FROM summary_bet_records WHERE summary_time BETWEEN '2019-10-01 00:00:00' AND '2019-10-31 23:59:59' and game_id>0 group by game_id;

        DECLARE CONTINUE HANDLER
            FOR NOT FOUND SET finished = 1;
        OPEN curCursor;

        CREATE TEMPORARY TABLE SRTABLE(
                                          `id` INTEGER,
                                          `agentaccount` VARCHAR(50),
                                          `agentname` NVARCHAR(50),
                                          `platform` NVARCHAR(50),
                                          `category` NVARCHAR(20),
                                          `totalbet` DECIMAL(20),
                                          `totalwinlose` DECIMAL(20),
                                          `totaljackpot` DECIMAL(20),
                                          `totalwin` DECIMAL(20)
        );

        getData:LOOP
            FETCH curCursor INTO gid;
            IF finished = 1 THEN
                LEAVE getData;
            END IF;

            INSERT INTO SRTABLE (`id`, `platform`,`category`,`totalbet`,`totalwinlose`) SELECT
                                                                                            m.game_id AS id,
                                                                                            (SELECT n.name FROM games as n WHERE id=m.game_id) AS platform,
                                                                                            (SELECT name FROM game_types WHERE id=(SELECT type_id FROM games WHERE id=m.game_id)) AS category,
                                                                                            SUM(bet) as totalbet,
                                                                                            SUM(win_lose) AS totalwinlose
            FROM summary_bet_records as m
            WHERE m.summary_time BETWEEN '2019-10-01 00:00:00' AND '2019-10-31 23:59:59'
              AND m.game_id=gid;

            -- INSERT INTO SRTABLE (`agentaccount`,`agentname`,`platform`,`category`,`totalbet`,`totalwinlose`,`totaljackpot`,`totalwin`)
            -- VALUES(``,``, platform, category, totalbet, totalwinlose, 0, 0);

        END LOOP getData;
        CLOSE curCursor;
    END;

    SELECT * FROM SRTABLE;

    DROP TEMPORARY TABLE IF EXISTS TEMP;
    DROP TEMPORARY TABLE IF EXISTS SRTABLE;

END
