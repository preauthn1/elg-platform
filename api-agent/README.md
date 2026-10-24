# api-agent

原始路径：`api-agent`　|　文件：**7298**　|　主语言：PHP 6387, JSON 203, XML 152

## 目录构成

```
_release/vendor/  — 7082
_release/app/  — 108
_release/resources/  — 22
_release/  — 12
_release/config/  — 12
server-build-online/  — 10
server-build/  — 9
helm/templates/  — 6
server-build/fluentd/  — 5
_release/database/  — 5
_release/public/  — 5
server-build-online/fluentd/  — 5
_release/routes/  — 4
_release/tests/  — 4
```

## 关键文件

- CI/构建：`.gitlab-ci.yml`、`_release/vendor/mockery/mockery/Makefile`、`_release/vendor/mockery/mockery/docs/Makefile`
- 前端入口：`server-build/nginx/index.html`、`_release/resources/assets/js/app.js`、`_release/vendor/phpunit/php-code-coverage/tests/_files/Report/HTML/CoverageForBankAccount/index.html`
- 依赖清单：`_release/package.json`、`_release/composer.json`、`_release/vendor/firebase/php-jwt/composer.json`
- 服务端主程序：`_release/vendor/laravel/framework/src/Illuminate/Support/Facades/App.php`

## 主要文件

- `_release/vendor/fzaninotto/faker/src/Faker/Provider/nl_BE/Text.php`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/fr_FR/Text.php`
- `_release/vendor/composer/autoload_static.php`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/el_GR/Text.php`
- `_release/vendor/composer/autoload_classmap.php`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/cs_CZ/Text.php`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/ru_RU/Text.php`
- `_release/vendor/fzaninotto/faker/src/Faker/Provider/ka_GE/Text.php`

## 说明

该工程为平台的一个组件。仅收录文本源码与配置，图片、音频等二进制资源未收录；凭据、密钥、内网地址与域名已按实际内容替换。

