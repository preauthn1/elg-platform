import { useUserStore } from '@vben/stores';

import { requestClient } from './request';

/**
 * 通用圖片上傳：base64 → /api/image/base64/upload，回傳圖片網址。
 * 給只需要 path 的頁面共用（agent_id 自動帶登入者）；失敗時丟出後端的錯誤訊息。
 * 需要 type:1 寫圖庫或不同端點（如 tgbot media）的頁面請自備 uploadFn。
 */
export async function uploadImageApi(base64Image: string): Promise<string> {
  const userStore = useUserStore();
  const res = await requestClient.post<{
    code: number | string;
    data?: { path?: string };
    message?: string;
  }>(
    '/api/image/base64/upload',
    { agent_id: (userStore.userInfo as any)?.id ?? 0, base64Image },
    { responseReturn: 'body' },
  );
  if (Number(res.code) !== 1000) throw new Error(res.message || '');
  return res.data?.path ?? '';
}
