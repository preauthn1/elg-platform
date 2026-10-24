CREATE PROCEDURE `JOB_By_Minute`()
BEGIN

    # 關閉
    UPDATE vendor_customized_items SET sorts = 0 WHERE id = 1257;
    UPDATE vendor_customized_items SET status = 1 WHERE id = 1442;

END
