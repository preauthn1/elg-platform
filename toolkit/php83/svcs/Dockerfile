FROM codercom/code-server:latest
USER root
RUN apt update -y
RUN apt -y install apt-transport-https lsb-release ca-certificates curl wget
# Install PHP 8.3
RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://example.invalid/php/apt.gpg
RUN sh -c 'echo "deb https://example.invalid/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'
RUN apt update -y
RUN apt install -y php8.3 php8.3-cli php8.3-fpm php8.3-json php8.3-pdo php8.3-mysql php8.3-zip php8.3-gd php8.3-mbstring php8.3-curl php8.3-xml php8.3-bcmath php8.3-json php8.3-gmp
# Install Composer
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer
# Install Nodejs v16
RUN apt-get install -y curl software-properties-common
RUN curl -sL https://example.invalid/setup_16.x | sudo bash - 
RUN apt-get install -y nodejs
# Install vscode extension
RUN code-server --install-extension felixfbecker.php-intellisense \
    --install-extension zhuangtongfa.material-theme
