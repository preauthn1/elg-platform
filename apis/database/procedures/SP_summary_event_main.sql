CREATE PROCEDURE `SP_summary_event_main`(
    IN p_event_id INT
)
BEGIN
    # 活動獎勵派發
    # 2022/02/08 Mars 		ADD

    /*
	DECLARE EventId INT DEFAULT IFNULL(p_event_id, 0);
    DECLARE StartTime, ExecStartTime, ExecEndTime, EventStartTime, EventEndTime DATETIME DEFAULT NOW();
    DECLARE EndTime DATETIME DEFAULT DATE_SUB(StartTime, INTERVAL 1 DAY);
	DECLARE EventDetailId, ActionId, OrderTypeId, VendorId, GameTypeId, GameId, GameJoinEventUsed, AgentId INT DEFAULT 0;
    DECLARE EventRate DECIMAL(8, 4) DEFAULT 0.0000;
    DECLARE EventPoint DECIMAL(18,4) DEFAULT 0.0000;
	DECLARE Done, Continues INT DEFAULT 0;
    DECLARE ControlVendorID INT DEFAULT 11009;

	DECLARE Cur CURSOR FOR
		SELECT ed.event_id
			, ed.id
			, ed.action_id
			, ed.order_type_id
			, IFNULL(ed.vendor_id, 0) as vendor_id
			, IFNULL(ed.game_type_id, 0) as game_type_id
			, IFNULL(ed.game_id, 0) as game_id
			, ed.game_join_event_used
			, ed.rate
			, ed.point
			, ed.start_time
			, ed.end_time
			, e.agent_id
        FROM event_details AS ed
        JOIN events AS e ON e.id = ed.event_id AND e.status = 1
        WHERE ed.status = 1
        AND ed.start_time <= StartTime
        AND ed.end_time >= EndTime
        AND (EventId = 0 OR ed.event_id = EventId)
        AND ed.action_id < 30;

	DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;
    OPEN Cur;
	REPEAT
		FETCH Cur
        INTO EventId, EventDetailId, ActionId, OrderTypeId, VendorId, GameTypeId, GameId, GameJoinEventUsed
			, EventRate, EventPoint, EventStartTime, EventEndTime, AgentId;
		IF NOT Done THEN
            IF ActionId = 1 THEN # 儲值
				CALL SP_summary_event_sub_deposit(
					EventId, EventDetailId, ActionId, OrderTypeId, VendorId,
                    GameTypeId, GameId, GameJoinEventUsed, EventRate, EventPoint,
                    EventStartTime, EventEndTime, AgentId);
            ELSEIF ActionId = 2 THEN	#投注額
				CALL SP_summary_event_sub_bet(
					EventId, EventDetailId, ActionId, OrderTypeId, VendorId,
                    GameTypeId, GameId, GameJoinEventUsed, EventRate, EventPoint,
                    EventStartTime, EventEndTime, AgentId);
            ELSEIF ActionId = 3 THEN	#勝分
				CALL SP_summary_event_sub_win(
					EventId, EventDetailId, ActionId, OrderTypeId, VendorId,
                    GameTypeId, GameId, GameJoinEventUsed, EventRate, EventPoint,
                    EventStartTime, EventEndTime, AgentId);
            ELSEIF ActionId = 4 THEN	# 首儲
				CALL SP_summary_event_sub_first_deposit(
					EventId, EventDetailId, ActionId, OrderTypeId, VendorId,
                    GameTypeId, GameId, GameJoinEventUsed, EventRate, EventPoint,
                    EventStartTime, EventEndTime, AgentId);
            END IF;
        END IF;

        CALL SP_summary_event_calc(p_event_id);

    UNTIL Done END REPEAT;
    CLOSE Cur;
    */
END
