FROM golang:1.13 as builder

WORKDIR /app
COPY . /app

# it will take the flags from the environment
RUN CGO_ENABLED=0 GOOS=linux go build -o app main.go

FROM alpine:latest
# mailcap adds mime detection and ca-certificates help with TLS (basic stuff)
RUN apk --no-cache add ca-certificates mailcap && addgroup -S app && adduser -S app -G app
USER app
WORKDIR /app
COPY --from=builder /app/app .
COPY --from=builder /app/config/config.yaml ./config/config.yaml
COPY --from=builder /app/templates ./templates

ENTRYPOINT ["/app/app"]
