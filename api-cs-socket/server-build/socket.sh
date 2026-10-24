#!/bin/sh

chown -R apache.apache /var/www/html/storage
chmod -R 777 /var/www/html/storage/framework/cache/data
chown -R apache.apache /var/www/html/storage/framework/cache/data

echo "* * * * * php /var/www/html/artisan schedule:run >> /var/log/schedule_$(date +"\%Y-\%m-\%d").log 2>&1; chown -R apache.apache /var/www/html/storage/framework/cache/data;" >> /etc/cron.d/crontabfile
echo "* * * * * sleep 10; php /var/www/html/artisan schedule:run >> /var/log/schedule_$(date +"\%Y-\%m-\%d").log 2>&1; chown -R apache.apache /var/www/html/storage/framework/cache/data;" >> /etc/cron.d/crontabfile
echo "* * * * * sleep 20; php /var/www/html/artisan schedule:run >> /var/log/schedule_$(date +"\%Y-\%m-\%d").log 2>&1; chown -R apache.apache /var/www/html/storage/framework/cache/data;" >> /etc/cron.d/crontabfile
echo "* * * * * sleep 30; php /var/www/html/artisan schedule:run >> /var/log/schedule_$(date +"\%Y-\%m-\%d").log 2>&1; chown -R apache.apache /var/www/html/storage/framework/cache/data;" >> /etc/cron.d/crontabfile
echo "* * * * * sleep 40; php /var/www/html/artisan schedule:run >> /var/log/schedule_$(date +"\%Y-\%m-\%d").log 2>&1; chown -R apache.apache /var/www/html/storage/framework/cache/data;" >> /etc/cron.d/crontabfile
echo "* * * * * sleep 50; php /var/www/html/artisan schedule:run >> /var/log/schedule_$(date +"\%Y-\%m-\%d").log 2>&1; chown -R apache.apache /var/www/html/storage/framework/cache/data;" >> /etc/cron.d/crontabfile
echo '00 */1  * * * \rm /var/www/html/storage/uploads/*.jpeg;' >> /etc/cron.d/crontabfile
crontab /etc/cron.d/crontabfile
crond

chown -R apache.apache /var/www/html/storage/framework/cache/data

php-fpm --nodaemonize