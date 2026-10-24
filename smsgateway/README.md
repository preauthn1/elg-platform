# smsgateway

原始路径：`smsgateway`　|　文件：**26**　|　主语言：Go 20, YAML 4, JSON 1

## 目录构成

```
controller/  — 5
gateway/  — 3
docs/  — 3
pkg/httputil/  — 3
config/  — 2
model/  — 2
db/mysql/  — 2
pkg/uid/  — 1
pkg/middleware/  — 1
db/model/  — 1
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

