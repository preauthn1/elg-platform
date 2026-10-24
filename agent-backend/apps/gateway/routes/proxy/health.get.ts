import { defineEventHandler } from 'h3';

/**
 * gateway 就緒探測端點，供 k8s readinessProbe 使用。
 *
 * 走 nginx 的 /proxy 反代打進本進程，因此一次驗證兩件事：nginx 已能服務、
 * 且 gateway（容器內 3000）已可回應。原本探測 `/` 只會拿到 nginx 的靜態檔，
 * gateway 還沒起完就會被判定 ready，該 pod 一進 endpoint，所有 /proxy/* 呼叫
 * 都會拿到 502。
 *
 * 刻意不碰 session、不打上游 API：探測是高頻呼叫，必須零副作用且不因上游
 * 抖動而把自己判成不健康（那會在上游故障時連帶把整組 pod 移出 endpoint）。
 */
export default defineEventHandler(() => ({ status: 'ok' }));
