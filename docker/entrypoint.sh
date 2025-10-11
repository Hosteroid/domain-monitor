#!/bin/sh

# Start cron daemon
crond -f -l 2 &

# Start PHP built-in server
cd /var/www/html
php -S 0.0.0.0:8000 -t public