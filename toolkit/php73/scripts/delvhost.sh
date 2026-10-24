#!/bin/bash
read -p "Enter domain name : " domain

NGINX_ENABLED_VHOSTS='./data/nginx/sites-enabled'
ALIASES_YAML='./data/docker-compose.aliases.yaml'

rm $NGINX_ENABLED_VHOSTS/$domain-vhost.conf
sudo sed -i "" "/$domain/d" /private/etc/hosts
sudo sed -i "" "/$domain/d" $ALIASES_YAML

./restart.sh