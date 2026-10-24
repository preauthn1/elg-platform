# 魔鏡 (magic-mirror) Slack bot — Socket Mode，不需對外開 port
FROM node:22-alpine

ENV NODE_ENV=production
WORKDIR /app

# Claude Code CLI：LLM（haiku 關鍵字改寫＋綜合回答）走容器內 claude -p，
# 憑證由 .env 的 CLAUDE_CODE_OAUTH_TOKEN 提供（host 先跑 `claude setup-token` 產生）
RUN npm i -g @anthropic-ai/claude-code

# 先裝依賴（利用 layer cache：package*.json 沒變就不重跑 npm ci）
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY src ./src

# logs/ 由 compose bind mount 提供；這裡先建目錄並交給 node user
RUN mkdir -p /app/logs && chown -R node:node /app

USER node

CMD ["node", "src/index.js"]
