# api-internal

原始路径：`api-internal`　|　文件：**7508**　|　主语言：PHP 6049, JSON 325, CSS 101

## 目录构成

```
_release/vendor/  — 7387
_release/app/  — 30
_release/config/  — 14
_release/resources/  — 8
helm/templates/  — 6
server-build/fluentd/  — 5
_release/database/  — 5
server-build-online/fluentd/  — 5
_release/routes/  — 4
_release/tests/  — 4
server-build/nginx/  — 2
_release/public/  — 2
server-build-online/nginx/  — 2
README.md/  — 1
```

## 关键文件

- CI/构建：`.gitlab-ci.yml`、`_release/vendor/phpdocumentor/reflection-docblock/Makefile`、`_release/vendor/guzzlehttp/promises/Makefile`
- 前端入口：`server-build/nginx/index.html`、`_release/resources/js/app.js`、`_release/vendor/phpunit/php-code-coverage/tests/_files/Report/HTML/CoverageForBankAccount/index.html`
- 依赖清单：`_release/package.json`、`_release/composer.json`、`_release/vendor/mockery/mockery/composer.json`
- 服务端主程序：`_release/vendor/laravel/framework/src/Illuminate/Support/Facades/App.php`

## 主要文件

- `_release/vendor/fzaninotto/faker/src/Faker/Provider/nl_BE/Text.php`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/fr_FR/Text.php`
- `_release/vendor/composer/autoload_static.php`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/el_GR/Text.php`
- `_release/vendor/composer/autoload_classmap.php`
- `_release/vendor/scrivo/highlight.php/demo/highlight.pack.js`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/cs_CZ/Text.php`
- `_release/vendor/facade/ignition/resources/compiled/ignition.js`

## 说明

该工程为平台的一个组件。仅收录文本源码与配置，图片、音频等二进制资源未收录；凭据、密钥、内网地址与域名已按实际内容替换。

