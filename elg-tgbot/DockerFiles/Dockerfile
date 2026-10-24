FROM example.invalid/dotnet/core/aspnet:3.1 AS runtime
ARG APP_NAME
ARG SHA1
ARG PIPE_ID

ENV APP_NAME ${APP_NAME}
ENV Git_SHA1 ${SHA1}
ENV Pipeline_ID ${PIPE_ID}

RUN sed -i 's/MinProtocol = TLSv1.2/MinProtocol = TLSv1/' /etc/ssl/openssl.cnf && sed -i 's/CipherString = DEFAULT@SECLEVEL=2/CipherString = DEFAULT@SECLEVEL=1/' /etc/ssl/openssl.cnf

EXPOSE 80/tcp

WORKDIR /app
COPY release/${APP_NAME} .

CMD dotnet ${APP_NAME}.dll
