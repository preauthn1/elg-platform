CREATE PROCEDURE `SP_payout_vip_reward`(IN p_StartTime DATETIME, IN p_member_id INT)
BEGIN

    # 派發VIP獎項
    # 2023/05/10 	Neil 		新增

    DECLARE Done INT DEFAULT 0;
    DECLARE StartTime, EndTime DateTime DEFAULT NOW();
    DECLARE Today Date DEFAULT NOW();
    DECLARE TodayDay VARCHAR(10);
    DECLARE TodayWeek INT DEFAULT 0;
    DECLARE AdminUser INT DEFAULT 51; # 系統
    DECLARE RewardId, PayoutLogId INT DEFAULT 0;
    DECLARE BirthdayRewardId INT DEFAULT 2; #生日獎金
    DECLARE CommemorateRewardId INT DEFAULT 3; #紀念獎金
    DECLARE WeekRewardId INT DEFAULT 5; #週禮包
    DECLARE MonthRewardId INT DEFAULT 6; #月禮包

    # 取出開獎期數
    DECLARE cur CURSOR FOR
        SELECT id
        FROM vip_rewards
        WHERE id in (2,3,5,6) AND status = 1;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET Done = 1;

    SET AdminUser = 0;
    SET StartTime = IFNULL(p_StartTime, DATE_ADD(DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR)), INTERVAL -8 HOUR));
    SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);
    SET Today = DATE_ADD(StartTime, INTERVAL 8 HOUR);
    SET TodayDay = DATE_FORMAT(Today, '%d'); # 取的今天的日期，用來發月禮包
    SET TodayWeek = DAYOFWEEK(StartTime); # 取得今天的星期，用來發週禮包

    OPEN cur;
    cursor_loop : REPEAT
        FETCH cur
            INTO RewardId;

        IF Done = 1 THEN
            LEAVE cursor_loop;
        END IF;

        # 創建派發紀錄，未派發
        INSERT INTO vip_reward_payout_logs(vip_reward_id, summary_start_time, summary_end_time, status)
            VALUE (RewardId, StartTime, EndTime, 0);
        SELECT LAST_INSERT_ID() INTO PayoutLogId;

        IF RewardId = BirthdayRewardId THEN
            INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, point, expired_time, status, admin)
            SELECT mv.member_id, vcr.id, vcr.point_type, vcr.point,
                   CASE vcr.expired_hour WHEN 0 THEN NULL ELSE DATE_ADD(NOW(), INTERVAL vcr.expired_hour HOUR) END,
                   0, AdminUser
            FROM member_vips AS mv
                     JOIN vip_contents AS vc ON vc.id = mv.vip_content_id
                     JOIN vip_content_rewards AS vcr ON vcr.vip_content_id = vc.id and vcr.vip_reward_id = RewardId AND vcr.status = 1
                     JOIN members AS m ON m.id = mv.member_id
                     JOIN agents AS a ON a.id = m.parent
            WHERE DATE_FORMAT(m.birthday, '%m-%d') = DATE_FORMAT(Today, '%m-%d');
        ELSEIF RewardId = CommemorateRewardId THEN
            INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, point, expired_time, status, admin)
            SELECT mv.member_id, vcr.id, vcr.point_type, vcr.point,
                   CASE vcr.expired_hour WHEN 0 THEN NULL ELSE DATE_ADD(NOW(), INTERVAL vcr.expired_hour HOUR) END,
                   0, AdminUser
            FROM member_vips AS mv
                     JOIN members AS m ON m.id = mv.member_id AND DATE_FORMAT(DATE_ADD(m.created_at, INTERVAL 8 HOUR), '%m-%d') = DATE_FORMAT(Today, '%m-%d')
                     JOIN vip_contents AS vc ON vc.id = mv.vip_content_id
                     JOIN vip_content_rewards AS vcr ON vcr.vip_content_id = vc.id and vcr.vip_reward_id = RewardId AND vcr.status = 1
                     JOIN agents AS a ON a.id = m.parent;
        ELSEIF RewardId = WeekRewardId THEN
            IF TodayWeek != 1 THEN
                # 週禮包，只有星期一才派獎
                ITERATE cursor_loop;
            END IF;

            INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, point, expired_time, status, admin)
            SELECT mv.member_id, vcr.id, vcr.point_type, vcr.point,
                   CASE vcr.expired_hour WHEN 0 THEN NULL ELSE DATE_ADD(NOW(), INTERVAL vcr.expired_hour HOUR) END,
                   0, AdminUser
            FROM member_vips AS mv
                     JOIN members AS m ON m.id = mv.member_id
                     JOIN vip_contents AS vc ON vc.id = mv.vip_content_id
                     JOIN vip_content_rewards AS vcr ON vcr.vip_content_id = vc.id and vcr.vip_reward_id = RewardId AND vcr.status = 1
                     JOIN agents AS a ON a.id = m.parent;
        ELSEIF RewardId = MonthRewardId THEN
            IF TodayDay != '01' THEN
                # 月禮包，只有每個月1號才派獎
                ITERATE cursor_loop;
            END IF;

            INSERT INTO member_vip_prizes (member_id, vip_content_reward_id, point_type, point, expired_time, status, admin)
            SELECT mv.member_id, vcr.id, vcr.point_type, vcr.point,
                   CASE vcr.expired_hour WHEN 0 THEN NULL ELSE DATE_ADD(NOW(), INTERVAL vcr.expired_hour HOUR) END,
                   0, AdminUser
            FROM member_vips AS mv
                     JOIN members AS m ON m.id = mv.member_id
                     JOIN vip_contents AS vc ON vc.id = mv.vip_content_id
                     JOIN vip_content_rewards AS vcr ON vcr.vip_content_id = vc.id and vcr.vip_reward_id = RewardId AND vcr.status = 1
                     JOIN agents AS a ON a.id = m.parent;
        END IF;

        # 派發完畢，更新派發紀錄
        UPDATE vip_reward_payout_logs SET `status` = 1 WHERE id = PayoutLogId;
    UNTIL Done END REPEAT;
    CLOSE cur;
END
