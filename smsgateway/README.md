# smsgateway

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`smsgateway`　|　文件 **26**　|　主语言：Go 20, YAML 4, JSON 1

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

- CI/构建：`Dockerfile`

---

## 原始说明

# SMSGateway 

Gen doc

```console
$ go get -u example.invalid/swaggo/swag/cmd/swag
$ swag init
```

release 設定檔

```console
config/config.yaml
templates/verification_code.template
```

debug 設定檔

```console
config/config.yaml
config/internal_config.yaml
docs/
templates/verification_code.template
```

[open swagger](http://localhost:8080/swagger/index.html)

