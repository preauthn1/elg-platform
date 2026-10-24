# ──────────────────────────────────────────────────────────────────────────
# 單 image：前端靜態檔（nginx）+ gateway（node），tini 管雙進程
#
# build context 必須是 repo root（需 pnpm-workspace.yaml / pnpm-lock.yaml /
# internal / packages 才能正確 install + filter build）。
# 對外只開 8080（nginx）；gateway node server 跑在容器內部 3000，由 nginx /proxy 反代。
#
# gateway 機密（AES_KEY / MD5_KEY / SESSION_PASSWORD / API_* / CS_*）由 runtime
# 容器 env 注入，image 內不含。
# ──────────────────────────────────────────────────────────────────────────

# ---- builder：一次 build 前端與 gateway ----
FROM node:24-slim AS builder

ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
ENV NODE_OPTIONS=--max-old-space-size=8192
ENV CI=true

RUN corepack enable

WORKDIR /app
COPY . /app

RUN --mount=type=cache,id=pnpm,target=/pnpm/store \
    pnpm install --frozen-lockfile

# 前端靜態檔 → apps/web-ele/dist
RUN pnpm build --filter=@vben/web-ele
# gateway node-server → apps/gateway/.output（entry: .output/server/index.mjs）
RUN pnpm --filter @vben/gateway build

RUN echo "Builder Success 🎉"

# ---- production：node base（gateway 需 node runtime）+ nginx + tini ----
FROM node:24-slim AS production

RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx tini \
    && rm -rf /var/lib/apt/lists/*

# 前端靜態檔
COPY --from=builder /app/apps/web-ele/dist /usr/share/nginx/html
# gateway node server
COPY --from=builder /app/apps/gateway/.output /app/.output

# nginx 設定 + 雙進程啟動腳本
COPY .gitlab-ci/build/nginx.conf /etc/nginx/nginx.conf
COPY .gitlab-ci/build/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# gateway 容器內部聽 3000；nginx 對外 8080
ENV PORT=3000
EXPOSE 8080

# tini 作 PID 1，妥善處理 signal / zombie，再交給 entrypoint 起雙進程
ENTRYPOINT ["/usr/bin/tini", "--", "/entrypoint.sh"]
