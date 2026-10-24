#!/bin/sh

crond
touch /var/log/cron.log
echo "pre1" >> /tmp/result
nohup /usr/bin/laravel-echo-server start --dir=/tmp/ >> /tmp/echo.log 2>&1 &
echo "first done" >>/tmp/result
/usr/sbin/nginx
echo "second done" >> /tmp/result