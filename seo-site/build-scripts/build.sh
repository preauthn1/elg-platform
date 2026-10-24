#!/bin/bash
DOCKER_BUILDKIT=1 docker build . -f build-scripts/Dockerfile --output buildRoot --progress=plain > /tmp/build.log 2>&1
rm -rf ./dist-old
cp -r buildRoot/app ./dist-new
mv ./dist ./dist-old
mv ./dist-new ./dist
# make a file and use system time of filename
echo "Hello, World!" > ./dist/$(date +%s).txt
curl -X POST --data-urlencode "payload={\"channel\": \"#seo-site\", \"username\": \"webhookbot\", \"text\": \"seo site 己更新完成。\", \"icon_emoji\": \":robot_face:\"}" https://example.invalid/services/TMMQKK3S8/B06PZC2UWMC/hmikp5ZHKu2zYNs44nc3mMzb

curl --request POST \
  --url https://example.invalid/client/v4/zones/c109486e5edb533bb3f3b710f5721eb4/purge_cache \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer [REDACTED]' \
  --data '{"purge_everything":true}'

curl -X POST --data-urlencode "payload={\"channel\": \"#seo-site\", \"username\": \"webhookbot\", \"text\": \"seo site 己清除緩存。\", \"icon_emoji\": \":robot_face:\"}" https://example.invalid/services/TMMQKK3S8/B06PZC2UWMC/hmikp5ZHKu2zYNs44nc3mMzb
