#!/bin/bash
#
# Nginx - new server block
# http://example.invalid
read -p "Enter project director (WEB ROOT IS ./data/html/) : " pdir
read -p "Enter domain name : " domain
read -p "Is Laravel project Y/N (N)? " isLaravelProject

WEB_ROOT="/var/www/html"
LOG_DIR="/var/log/nginx"

# Functions
ok() { echo -e '\e[32m'$domain'\e[m'; } # Green
die() { echo -e '\e[1;31m'$domain'\e[m'; exit 1; }

# Variables
#NGINX_AVAILABLE_VHOSTS='/etc/nginx/sites-available'
NGINX_ENABLED_VHOSTS='./data/nginx/sites-enabled'
ALIASES_YAML='./data/docker-compose.aliases.yaml'
#WEB_DIR='/home'
#WEB_USER=$username
NGINX_LOCATION_TRY_FILES='try_files $uri $uri/ /index.html;'
# if isLaravelProject equl "Y" or y
if [ $isLaravelProject = "Y" ] || [ $isLaravelProject = "y" ] ;
then
    NGINX_LOCATION_TRY_FILES='try_files \$uri \$uri/ /index.php?\$query_string;'
fi



# Create nginx config file
cat > $NGINX_ENABLED_VHOSTS/$domain-vhost.conf <<EOF
### www to non-www
#server {
#    listen	 80;
#    server_name  www.$domain;
#    return	 301 http://$domain\$request_uri;
#}
server {
    listen   80;
    server_name $domain www.$domain;
    root  $WEB_ROOT/$pdir;
    charset  utf-8;
    index index.php index.html index.htm;
    #access_log $LOG_DIR/$domain-access.log;
    access_log off;
    error_log $LOG_DIR/$domain-error.log;
    #error_log off;
    ## REWRITES BELOW ##
    location / {
        $NGINX_LOCATION_TRY_FILES
    }
    error_page   500 502 503 504  /50x.html;
    location = /50x.html {
        root   /usr/share/nginx/html;
    }
    location ~ ^/.+\.php(/|$) {
        root  $WEB_ROOT/$pdir;
        fastcgi_pass   phpfpm:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        fastcgi_param  SCRIPT_FILENAME  \$document_root\$fastcgi_script_name;
        include        fastcgi_params;
    }
}
EOF


# Changing permissions
# chown -R $WEB_USER:$WEB_USER $WEB_DIR/$username

# Enable site by creating symbolic link
# ln -s $NGINX_AVAILABLE_VHOSTS/$1 $NGINX_ENABLED_VHOSTS/$1

# Restart
# echo "Do you wish to restart nginx?"
# select yn in "Yes" "No"; do
#     case $yn in
#         Yes ) service nginx restart ; break;;
#         No ) exit;;
#     esac
# done
## if $ALIASES_YAML file exists
if [ -f "$ALIASES_YAML" ]; then
    echo "File $ALIASES_YAML exists."
else
    echo "File $ALIASES_YAML does not exist."
        cat > $ALIASES_YAML <<EOF
services:
  nginx:
    networks:
      default:
        aliases:
EOF
fi
echo "            - $domain" | sudo tee -a $ALIASES_YAML
echo "0.0.0.0 $domain" | sudo tee -a /private/etc/hosts
./restart.sh

ok "Site Created for $domain"
