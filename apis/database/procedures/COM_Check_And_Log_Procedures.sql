CREATE PROCEDURE `COM_Check_And_Log_Procedures`(
	IN p_CurrentRoutineSchema VARCHAR(64)
)
BEGIN
	# SP版控
    # CALL COM_Check_And_Log_Procedures(NULL);
    # CALL COM_Check_And_Log_Procedures('miner');
    
    # 2024/12/18 Mars 		Add
    
    DECLARE InCurrentRoutineSchema VARCHAR(64) 
		DEFAULT IFNULL(p_CurrentRoutineSchema, 'xsgcasino');
    DECLARE CurrentRoutineSchema VARCHAR(64);
    DECLARE CurrentRoutineName VARCHAR(64);
    DECLARE CurrentRoutineDefinition LONGTEXT;
    DECLARE Done INT DEFAULT 0;
    
    DECLARE NewVersion INT;

    DECLARE Cur CURSOR FOR
        SELECT ROUTINE_SCHEMA, ROUTINE_NAME, ROUTINE_DEFINITION
        FROM information_schema.ROUTINES
        WHERE ROUTINE_TYPE = 'PROCEDURE'
		AND ROUTINE_SCHEMA = InCurrentRoutineSchema;
        
    ### SQL異常時發TG通知 ##################
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
			@p2 = MESSAGE_TEXT;
            
        # 發通知
        CALL COM_Send_Error_Message(
			CONCAT(
				'[系統通知] COM_Check_And_Log_Procedures(',
                '\'', p_CurrentRoutineSchema,'\'',
				') 異常 ', @p2), 
			'db'
		);
    END;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    OPEN Cur;
	REPEAT
		FETCH Cur 
        INTO CurrentRoutineSchema, CurrentRoutineName, CurrentRoutineDefinition;
		IF NOT Done THEN
			-- 檢查是否存在於 sp_logs 並比對
			IF NOT EXISTS (
				SELECT 1
				FROM sp_logs
				WHERE routine_schema = CurrentRoutineSchema
				AND routine_name = CurrentRoutineName
				AND routine_definition = CurrentRoutineDefinition
			) THEN
				-- 計算新版本號
				SET NewVersion = COALESCE((
					SELECT MAX(version_number)
					FROM sp_logs
					WHERE routine_schema = CurrentRoutineSchema
					AND routine_name = CurrentRoutineName
				), 0) + 1;
	
				-- 新增變更記錄至 sp_logs
				INSERT INTO sp_logs (routine_schema, routine_name, routine_definition, version_number)
				VALUES (CurrentRoutineSchema, CurrentRoutineName, CurrentRoutineDefinition, NewVersion);
			END IF;
        END IF;
    UNTIL Done END REPEAT;
    CLOSE Cur;
END