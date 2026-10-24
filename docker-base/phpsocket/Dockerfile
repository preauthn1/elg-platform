FROM node:10.16-alpine

WORKDIR /app

### Install Laravel Echo Server and dependencies
RUN set -eux; \
    apk add --update --no-cache \
        sqlite \
        openssl \
        curl \
    ; \
    apk add --update --no-cache --virtual .build-deps \
        build-base \
    ; \
    yarn global add --prod --no-lockfile laravel-echo-server ; \
    apk del .build-deps ; \
    yarn cache clean ; \
    mkdir -p /database ;

CMD ["laravel-echo-server", "start"]