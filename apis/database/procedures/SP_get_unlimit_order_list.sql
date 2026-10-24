CREATE PROCEDURE `SP_get_unlimit_order_list`(IN p_member_id INT, IN p_order_id INT
,IN p_starttime datetime, IN p_endtime datetime, IN p_page_index INT, IN p_page_count INT)
BEGIN
    DECLARE p_total_count INT;
    SET p_member_id = IFNULL(p_member_id,0);
    SET p_order_id = IFNULL(p_order_id,0);
    SET p_page_index = IFNULL(p_page_index,1);
    SET p_page_count = IFNULL(p_page_count,20);
    SET p_total_count = (p_page_index - 1) * p_page_count;

    SELECT WL.id AS order_id
         ,WL.member_id
         ,WL.amount AS withdraw_amount
         ,SUM(BR.bonus_direct + BR.bonus_team) AS bonus_amount
         ,WL.type
         ,WL.created_at
         ,WL.updated_at
         ,WL.admin
    FROM unlimit_withdrawlog AS WL
             LEFT JOIN unlimit_bonusreport AS BR ON BR.member_id = WL.member_id AND WL.created_at >= BR.summary_date
    WHERE (p_member_id = 0 OR WL.member_id = p_member_id)
      AND (p_order_id = 0 OR WL.id = p_order_id)
      AND (p_starttime is null OR WL.created_at >= p_starttime)
      AND (p_endtime is null OR WL.created_at <= p_endtime)
    GROUP BY WL.id
    ORDER BY order_id
    LIMIT p_page_count OFFSET p_total_count;
END
