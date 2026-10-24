# RD-Studio 更新腳本內容（最後同步：2026-05-27）

> 從 [SKILL.md](../SKILL.md) §4 拆出。已文件化的三支腳本（dev / tpower / vw）都從同一顆 gcloud source repo（`tp-wz3-rd`）拉，差別在 checkout / env / 目的地。gff-* 站各有自己的 `update_live.sh`（未文件化）、wintoto 共用 vw。
> 若日後機器上腳本變動，用 [troubleshooting.md](troubleshooting.md) §8「維護方式」重抓覆蓋本檔。

## `~vwsource/update_rd.sh`（dev）

```bash
cd ~vwsource
\rm -R RD-Studio-01
./login.sh
gcloud source repos clone RD-Studio-01 --project=tp-wz3-rd
cd RD-Studio-01/_release
git fetch
git checkout develop
\rm .env.pro
mv .env.rd .env
composer install
\cp -R * /var/www/html/
\cp .env /var/www/html/
```

## `/vwsource/update_live.sh`（prod tpower-new + tpower-hk）

```bash
cd /vwsource
\rm -R RD-Studio-01
./login.sh
gcloud source repos clone RD-Studio-01 --project=tp-wz3-rd
cd RD-Studio-01/_release
#\rm /var/www/html/.env
\rm .env.rd
mv .env.tpower_new .env
composer install
\cp -R * /var/www/html/
\cp .env /var/www/html/
\cp gobin/getBetRecords/.getBetRecords.tpower_new.yaml /var/www/html/gobin/getBetRecords/getBetRecords.yaml
mv .env.tpower-hk .env
composer install
\cp -R * /var/www/html-hk/
\cp .env /var/www/html-hk/
\cp gobin/getBetRecords/.getBetRecords.tpower-hk.yaml /var/www/html-hk/gobin/getBetRecords/getBetRecords.yaml
```

## `~vwsource/update_live.sh`（prod vw）

```bash
cd ~vwsource
\rm -R RD-Studio-01
./login.sh
gcloud source repos clone RD-Studio-01 --project=tp-wz3-rd
cd RD-Studio-01/_release
#\rm /var/www/html/.env
\rm .env.rd
mv .env.pro .env
composer install
\cp -R * /var/www/html/
\cp .env /var/www/html/
\cp gobin/getBetRecords/.getBetRecords.pro.yaml /var/www/html/gobin/getBetRecords/getBetRecords.yaml
```
