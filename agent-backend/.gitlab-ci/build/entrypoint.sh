#!/usr/bin/env bash
# 單 image 雙進程啟動：node gateway（背景）+ nginx（前景）
# tini（PID 1，由 Dockerfile ENTRYPOINT 指定）負責 signal/zombie 回收。
# dev 階段不需 supervisor 級的個別重啟：任一進程退出即讓容器結束，交由 k8s 重啟整個 Pod。
# 用 bash 而非 sh：node:24-slim 的 /bin/sh 是 dash，不支援 `wait -n`。
set -e

NGINX_PID=
GATEWAY_PID=

# gateway node server（容器內部 3000，由 nginx /proxy 反代）
echo "[entrypoint] starting gateway (node) on PORT=${PORT:-3000}"
PORT="${PORT:-3000}" node /app/.output/server/index.mjs &
GATEWAY_PID=$!

# k8s 終止 Pod（rollout / HPA 縮容 / 節點回收）時的優雅關閉。三個要點：
#
# 1. nginx 必須送 SIGQUIT。SIGQUIT 才是 graceful shutdown（停止收新連線、把既有
#    請求做完再退）；SIGTERM 走的是 fast shutdown，會把進行中的回應攔腰砍斷，
#    且客戶端收不到 FIN 只能等自己 timeout。`kill` 預設就是 SIGTERM，必須明寫。
# 2. 送完訊號要等兩個進程真的收工才 exit。script 先結束會讓容器立刻被回收，
#    排空等於沒做。
#    **不可用 `wait`**：在由訊號觸發的 trap handler 內，`wait` 會立即返回而不阻塞
#    （dev 實測 SIGTERM 到 exit 只隔 17ms，容器 0.69s 就結束、排空完全沒發生；
#    本機以 stub 模擬時 `wait` 卻正常阻塞，屬假陽性，勿以本機結果為準）。
#    改以 kill -0 輪詢，不依賴 `wait` 語意。
# 3. 正常收工以 exit 0 結束，避免 Pod 被記為 Failed / reason=Error。
#
# 時間預算（與 gffv3 chart 為跨 repo 契約，調整其一必須同步另一方）：
#   nginx worker_shutdown_timeout 20s（強制關閉殘留連線）
#     < 本函式輪詢上限 25s（給 nginx 收工後仍有餘裕）
#     < 目前部署中的 chart grace 30s（無 preStop）
#   套用新 chart（preStop 20s + grace 60s）後：20 + 25 = 45s < 60s，同樣成立。
#   上限取 25s 是為了在「舊 chart 尚未汰換」的期間也不會被 SIGKILL。
shutdown() {
  echo "[entrypoint] SIGTERM received, draining"
  kill -QUIT "$NGINX_PID" 2>/dev/null || true
  kill -TERM "$GATEWAY_PID" 2>/dev/null || true

  local started=$SECONDS
  local deadline=$((started + 25))
  while [ "$SECONDS" -lt "$deadline" ] \
    && { kill -0 "$NGINX_PID" 2>/dev/null || kill -0 "$GATEWAY_PID" 2>/dev/null; }; do
    sleep 0.5
  done

  echo "[entrypoint] drained in $((SECONDS - started))s, exiting"
  exit 0
}
trap shutdown TERM INT

# nginx 前景執行
echo "[entrypoint] starting nginx on :8080"
nginx -g 'daemon off;' &
NGINX_PID=$!

# 等任一進程結束；該進程的 exit code 作為容器 exit code
# （此路徑代表「非預期崩潰」，優雅關閉走上面的 trap）
wait -n "$GATEWAY_PID" "$NGINX_PID"
EXIT_CODE=$?
echo "[entrypoint] a process exited (code=$EXIT_CODE), terminating container"
kill "$GATEWAY_PID" 2>/dev/null || true
kill "$NGINX_PID" 2>/dev/null || true
exit "$EXIT_CODE"
