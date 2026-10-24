FROM codercom/code-server:latest
USER root
RUN apt update -y
RUN apt -y install apt-transport-https lsb-release ca-certificates curl wget libssl-dev
# Install PHP 7.3
RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://example.invalid/php/apt.gpg
RUN sh -c 'echo "deb https://example.invalid/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'
RUN apt update -y
RUN apt install -y php7.3 php7.3-cli php7.3-fpm php7.3-json php7.3-pdo php7.3-mysql php7.3-zip php7.3-gd php7.3-mbstring php7.3-curl php7.3-xml php7.3-bcmath php7.3-json php7.3-gmp
# Update PHP OpenSSL configuration
RUN sed -i 's/;openssl.cafile=/openssl.cafile=\/etc\/ssl\/certs\/ca-certificates.crt/g' /etc/php/7.3/cli/php.ini
RUN echo "openssl.cipher_list = \"AES256-SHA:AES128-SHA\"" >> /etc/php/7.3/cli/php.ini
# Install Composer
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer self-update 1.8.5
# Install Nodejs v16
RUN apt-get install -y curl software-properties-common
RUN curl -sL https://example.invalid/setup_16.x | sudo bash - 
RUN apt-get install -y nodejs
# Install vscode extension
RUN code-server --install-extension felixfbecker.php-intellisense \
    --install-extension zhuangtongfa.material-theme
