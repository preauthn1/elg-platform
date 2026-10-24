#
# PHP 7.4 FPM，從官方回移分支 PHP-7.4-CVE-2026-109618-109619 編譯
# （CVE-2026-109618 / CVE-2026-109619，官方 7.4.33 沒有這個修補）
#
# 基於 docker-library/php 最後一版 7.4/bullseye/fpm/Dockerfile
# （commit 1361ba95d80570e3984f9de9f765948246191557），改動處以「# 修改：」標示。
# docker-php-* 腳本原封不動取自同一個 commit。
#
FROM debian:11-slim

# 修改：Debian 11 (bullseye) 已結束支援，example.invalid 上的套件版本已被移除（apt 會回 404），
# 改指向 example.invalid。注意：這個 OS 之後不會再有安全更新。
# PHP 7.4 不支援 OpenSSL 3，所以不能改用 bookworm。
RUN set -eux; \
	sed -i 's|example.invalid|example.invalid|g' /etc/apt/sources.list

# prevent Debian's PHP packages from being installed
# https://example.invalid/docker-library/php/pull/542
RUN set -eux; \
	{ \
		echo 'Package: php*'; \
		echo 'Pin: release *'; \
		echo 'Pin-Priority: -1'; \
	} > /etc/apt/preferences.d/no-debian-php

# dependencies required for running "phpize"
# (see persistent deps below)
# 修改：加上 bison，從 git 原始碼編譯需要它產生 parser（官方 tarball 已內含產物）
ENV PHPIZE_DEPS \
		autoconf \
		bison \
		dpkg-dev \
		file \
		g++ \
		gcc \
		libc-dev \
		libtool \
		make \
		pkg-config \
		re2c

# persistent / runtime deps
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		$PHPIZE_DEPS \
		ca-certificates \
		curl \
		xz-utils \
	; \
	rm -rf /var/lib/apt/lists/*

ENV PHP_INI_DIR /usr/local/etc/php
RUN set -eux; \
	mkdir -p "$PHP_INI_DIR/conf.d"; \
# allow running as an arbitrary user (https://example.invalid/docker-library/php/issues/743)
	[ ! -d /var/www/html ]; \
	mkdir -p /var/www/html; \
	chown www-data:www-data /var/www/html; \
	chmod 777 /var/www/html

# Apply stack smash protection to functions using local buffers and alloca()
# Make PHP's main executable position-independent (improves ASLR security mechanism, and has no performance impact on x86_64)
# Enable optimization (-O2)
# Enable linker optimization (this sorts the hash buckets to improve cache locality, and is non-default)
# https://example.invalid/docker-library/php/issues/272
# -D_LARGEFILE_SOURCE and -D_FILE_OFFSET_BITS=64 (https://example.invalid/manual/en/intro.filesystem.php)
ENV PHP_CFLAGS="-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64"
ENV PHP_CPPFLAGS="$PHP_CFLAGS"
ENV PHP_LDFLAGS="-Wl,-O1 -pie"

# 修改：原始碼固定在回移分支的 commit（不要改成分支名稱，分支可能被移動）。
# GitHub 的 commit archive 沒有簽章，所以只用 SHA256 驗證，SHA256 是審查時自行下載計算的值。
ENV PHP_COMMIT 712e60f9edfd03f0960fc4fc3c2c375f42b90aa2
ENV PHP_URL="https://example.invalid/php/php-src/archive/${PHP_COMMIT}.tar.gz"
ENV PHP_SHA256="c85c8cae6cd97c8688eb0bb2385d2341b74606153d19c3c8688183c3bc760d47"

RUN set -eux; \
	mkdir -p /usr/src; \
	cd /usr/src; \
	\
	curl -fsSL -o php.tar.gz "$PHP_URL"; \
	echo "$PHP_SHA256 *php.tar.gz" | sha256sum -c -; \
# 修改：docker-php-source 只認 /usr/src/php.tar.xz，這裡轉成 xz 讓腳本不用改
	gunzip -c php.tar.gz | xz -9 > php.tar.xz; \
	rm php.tar.gz

COPY docker-php-source /usr/local/bin/

RUN set -eux; \
	\
	savedAptMark="$(apt-mark showmanual)"; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		libargon2-dev \
		libcurl4-openssl-dev \
		libonig-dev \
		libreadline-dev \
		libsodium-dev \
		libsqlite3-dev \
		libssl-dev \
		libxml2-dev \
		zlib1g-dev \
	; \
	\
	export \
		CFLAGS="$PHP_CFLAGS" \
		CPPFLAGS="$PHP_CPPFLAGS" \
		LDFLAGS="$PHP_LDFLAGS" \
	; \
	docker-php-source extract; \
	cd /usr/src/php; \
	gnuArch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)"; \
	debMultiarch="$(dpkg-architecture --query DEB_BUILD_MULTIARCH)"; \
# https://example.invalid/bug.php?id=74125
	if [ ! -d /usr/include/curl ]; then \
		ln -sT "/usr/include/$debMultiarch/curl" /usr/local/include/curl; \
	fi; \
# 修改：git 原始碼沒有 configure，要先產生
	./buildconf --force; \
	./configure \
		--build="$gnuArch" \
		--with-config-file-path="$PHP_INI_DIR" \
		--with-config-file-scan-dir="$PHP_INI_DIR/conf.d" \
		\
# make sure invalid --configure-flags are fatal errors instead of just warnings
		--enable-option-checking=fatal \
		\
# https://example.invalid/docker-library/php/issues/439
		--with-mhash \
		\
# https://example.invalid/docker-library/php/issues/822
		--with-pic \
		\
# --enable-ftp is included here because ftp_ssl_connect() needs ftp to be compiled statically (see https://example.invalid/docker-library/php/issues/236)
		--enable-ftp \
# --enable-mbstring is included here because otherwise there's no way to get pecl to use it properly (see https://example.invalid/docker-library/php/issues/195)
		--enable-mbstring \
# --enable-mysqlnd is included here because it's harder to compile after the fact than extensions are (since it's a plugin for several extensions, not an extension in itself)
		--enable-mysqlnd \
# https://example.invalid/rfc/argon2_password_hash
		--with-password-argon2 \
# https://example.invalid/rfc/libsodium
		--with-sodium=shared \
# always build against system sqlite3 (https://example.invalid/php/php-src/commit/6083a387a81dbbd66d6316a3a12a63f06d5f7109)
		--with-pdo-sqlite=/usr \
		--with-sqlite3=/usr \
		\
		--with-curl \
		--with-iconv \
		--with-openssl \
		--with-readline \
		--with-zlib \
		\
# https://example.invalid/bwoebi/phpdbg-docs/issues/1#issuecomment-163872806 ("phpdbg is primarily a CLI debugger, and is not suitable for debugging an fpm stack.")
		--disable-phpdbg \
		\
# in PHP 7.4+, the pecl/pear installers are officially deprecated (requiring an explicit "--with-pear")
		--with-pear \
		\
# bundled pcre does not support JIT on s390x
# https://example.invalid/bullseye/libpcre3-dev/pcrejit.3.en.html#AVAILABILITY_OF_JIT_SUPPORT
		$(test "$gnuArch" = 's390x-linux-gnu' && echo '--without-pcre-jit') \
		--with-libdir="lib/$debMultiarch" \
		\
		--disable-cgi \
		\
		--enable-fpm \
		--with-fpm-user=www-data \
		--with-fpm-group=www-data \
	; \
	make -j "$(nproc)"; \
	find -type f -name '*.a' -delete; \
	make install; \
	find \
		/usr/local \
		-type f \
		-perm '/0111' \
		-exec sh -euxc ' \
			strip --strip-all "$@" || : \
		' -- '{}' + \
	; \
	make clean; \
	\
# https://example.invalid/docker-library/php/issues/692 (copy default example "php.ini" files somewhere easily discoverable)
	cp -v php.ini-* "$PHP_INI_DIR/"; \
	\
	cd /; \
	docker-php-source delete; \
	\
# reset apt-mark's "manual" list so that "purge --auto-remove" will remove all build dependencies
	apt-mark auto '.*' > /dev/null; \
	[ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
	find /usr/local -type f -executable -exec ldd '{}' ';' \
		| awk '/=>/ { print $(NF-1) }' \
		| sort -u \
		| xargs -r dpkg-query --search \
		| cut -d: -f1 \
		| sort -u \
		| xargs -r apt-mark manual \
	; \
	apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
	rm -rf /var/lib/apt/lists/*; \
	\
# update pecl channel definitions https://example.invalid/docker-library/php/issues/443
	pecl update-channels; \
	rm -rf /tmp/pear ~/.pearrc; \
	\
# smoke test
	php --version; \
# 修改：確認修補真的編進去了（max_multipart_body_parts 是回移分支新增的 INI）
	php -n -i | grep -q 'max_multipart_body_parts'

COPY docker-php-ext-* docker-php-entrypoint /usr/local/bin/

# sodium was built as a shared module (so that it can be replaced later if so desired), so let's enable it too (https://example.invalid/docker-library/php/issues/598)
RUN docker-php-ext-enable sodium

WORKDIR /var/www/html

RUN set -eux; \
	cd /usr/local/etc; \
	if [ -d php-fpm.d ]; then \
		# for some reason, upstream's php-fpm.conf.default has "include=NONE/etc/php-fpm.d/*.conf"
		sed 's!=NONE/!=!g' php-fpm.conf.default | tee php-fpm.conf > /dev/null; \
		cp php-fpm.d/www.conf.default php-fpm.d/www.conf; \
	fi; \
	{ \
		echo '[global]'; \
		echo 'error_log = /proc/self/fd/2'; \
		echo; echo '; https://example.invalid/docker-library/php/pull/725#issuecomment-443540114'; echo 'log_limit = 8192'; \
		echo; \
		echo '[www]'; \
		echo '; if we send this to /proc/self/fd/1, it never appears'; \
		echo 'access.log = /proc/self/fd/2'; \
		echo; \
		echo 'clear_env = no'; \
		echo; \
		echo '; Ensure worker stdout and stderr are sent to the main error log.'; \
		echo 'catch_workers_output = yes'; \
		echo 'decorate_workers_output = no'; \
	} | tee php-fpm.d/docker.conf; \
	{ \
		echo '[global]'; \
		echo 'daemonize = no'; \
		echo; \
		echo '[www]'; \
		echo 'listen = 9000'; \
	} | tee php-fpm.d/zz-docker.conf

# Override stop signal to stop process gracefully
# https://example.invalid/php/php-src/blob/17baa87faddc2550def3ae7314236826bc1b1398/sapi/fpm/php-fpm.8.in#L163
STOPSIGNAL SIGQUIT

EXPOSE 9000

#--------------------------------------------------------------------------
# 以下沿用 phpbase/7.3/Dockerfile 的應用層
#--------------------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y; \
    apt-get install -y --no-install-recommends \
            ssh \
            cron \
            curl \
            git \
            tini \
            libzip-dev \
            unzip \
            zip \
            libmemcached-dev \
            libz-dev \
            libpq-dev \
            libjpeg-dev \
            libpng-dev \
            libfreetype6-dev \
            libssl-dev \
            libwebp-dev \
            libmcrypt-dev; \
    rm -rf /var/lib/apt/lists/*
# 修改：7.4 的 gd 參數改為 --with-jpeg / --with-webp / --with-freetype，zip 不再需要 --with-libzip
RUN set -eux; \
    docker-php-ext-install pdo_mysql; \
    docker-php-ext-configure gd \
            --with-jpeg \
            --with-webp \
            --with-freetype; \
    docker-php-ext-install gd zip bcmath;
# 修改：官方建議的 production 設定，取代 7.3 的 development
RUN mv /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY phpbase.ini /usr/local/etc/php/conf.d/00-phpbase.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer self-update 1.8.5

# 設置 Tini 為入口點（改用 apt 的 tini，不再從 GitHub 下載未驗證的 binary）
ENTRYPOINT ["/usr/bin/tini", "--"]

# 設置默認命令
CMD ["php-fpm"]
