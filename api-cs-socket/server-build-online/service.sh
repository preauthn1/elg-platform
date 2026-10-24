#!/bin/sh
echo "pre1" >> /tmp/result

#echo '5 */1 * * * pkill -f laravel-echo-server; nohup /usr/bin/laravel-echo-server start --dir=/tmp/ >> /tmp/echo.log 2>&1 &' >> /etc/cron.d/crontabfile
#echo '5 */1 * * * nohup /usr/bin/laravel-echo-server start --force --dir=/tmp/ >> /tmp/echo.log 2>&1 &' >> /etc/cron.d/crontabfile
crontab /etc/cron.d/crontabfile
crond

nohup /usr/bin/laravel-echo-server start --force --dir=/tmp/ >> /tmp/echo.log 2>&1 &

echo "first done" >>/tmp/result
/usr/sbin/nginx
echo "second done" >> /tmp/result