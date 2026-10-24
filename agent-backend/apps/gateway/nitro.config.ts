import errorHandler from './error';

process.env.COMPATIBILITY_DATE = new Date().toISOString();
export default defineNitroConfig({
  devErrorHandler: errorHandler,
  errorHandler: '~/error',
  // 前端與 gateway 同源(prod 經 nginx /proxy、dev 經 vite proxy)，
  // 不需要開放 CORS。故移除 Access-Control-Allow-Origin: '*'（搭配 cookie credentials
  // 本就是無效又危險的組合）。如未來真有跨來源需求，再在此明確列出允許的網域。
});
