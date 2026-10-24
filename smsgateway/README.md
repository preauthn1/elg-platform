# smsgateway

原始路径：`smsgateway`　|　文件：**27**　|　主语言：Go 20, YAML 4, JSON 1

## 目录构成

```
pkg/httputil/  — 3
db/mysql/  — 2
README.md/  — 1
docker-compose.yml/  — 1
Dockerfile/  — 1
main.go/  — 1
gateway/nexmo.go/  — 1
gateway/gateway_handler.go/  — 1
gateway/tata.go/  — 1
docs/swagger.json/  — 1
docs/swagger.yaml/  — 1
docs/docs.go/  — 1
config/config.yaml/  — 1
config/internal_config.yaml/  — 1
```

## 关键文件

- CI/构建：`docker-compose.yml`、`Dockerfile`

## 主要文件

- `db/mysql/crud.go`
- `docs/docs.go`
- `gateway/nexmo.go`
- `gateway/tata.go`
- `controller/verification_code.go`
- `pkg/uid/uid.go`
- `pkg/httputil/post_to_url.go`
- `controller/controller.go`

## 说明

该工程为平台的一个组件。仅收录文本源码与配置，图片、音频等二进制资源未收录；凭据、密钥、内网地址与域名已按实际内容替换。

