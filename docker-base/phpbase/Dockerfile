FROM bitnami/php-fpm:7.3-prod
RUN apt-get update -y
RUN apt-get install -y git php-gd php-soap php-gmp openssh-client unzip

# Install Composer
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer self-update 1.8.5