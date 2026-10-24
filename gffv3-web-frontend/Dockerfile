FROM node:24-alpine AS build
WORKDIR /app
ENV CI=true
RUN corepack enable
# pnpm-workspace.yaml 帶 allowBuilds（esbuild 等 build script 白名單），少了它 pnpm 會擋掉安裝腳本
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
# postinstall 的 nuxt prepare 需要完整原始碼，這裡先略過，COPY 之後再跑
RUN --mount=type=cache,id=pnpm,target=/root/.local/share/pnpm/store \
    pnpm install --frozen-lockfile --ignore-scripts
COPY . .
RUN pnpm rebuild && pnpm build

FROM node:24-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production
ENV NITRO_PORT=3000
ENV NITRO_HOST=0.0.0.0
COPY --from=build /app/.output ./.output
USER node
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
