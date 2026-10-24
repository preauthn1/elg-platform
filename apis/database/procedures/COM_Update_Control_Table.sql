CREATE PROCEDURE `COM_Update_Control_Table`(
    IN VendorId INT,
    IN VendorCode VARCHAR(50),
    IN IsRecalculated INT,
    IN StartTime DATETIME,
    IN EndTime DATETIME,
    IN ExecStart DATETIME,
    IN ExecEnd DATETIME,
    IN Params VARCHAR(512)
)
BEGIN

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            # 發通知
            CALL COM_Send_Error_Message(
                CONCAT(
                    '[系統通知] SP_transfer_event_record_pgs(',
                    IsRecalculated, ',',
                    VendorId, ',',
                    VendorCode, ',',
                    StartTime, ',',
                    EndTime, ',',
                    ExecStart, ',',
                    ExecEnd, ',',
                    Params,
                    ') 異常 ', @p2),
                'db'
                 );
        END;

    IF IsRecalculated = 0 THEN
        INSERT INTO summary_control_tables(
            id, vendor_id, vendor_code, stat_start_time, stat_end_time, exec_start_time, exec_end_time
        )
        VALUES(
                  VendorId, VendorId, VendorCode, StartTime, EndTime, ExecStart, ExecEnd
              )
        ON DUPLICATE KEY UPDATE stat_start_time = StartTime, stat_end_time = EndTime, exec_start_time = ExecStart, exec_end_time = ExecEnd;
    END IF;

    #寫紀錄
    INSERT INTO summary_control_logs(
        vendor_id, stat_start_time, stat_end_time, exec_start_time, exec_end_time, params
    )
    VALUES(
              VendorID, StartTime, EndTime, ExecStart, ExecEnd, Params
          );

END
