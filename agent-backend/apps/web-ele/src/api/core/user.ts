import type { UserInfo } from '@vben/types';

import { preferences } from '@vben/preferences';

import { requestClient } from '#/api/request';

/**
 * 取得使用者資訊：從 gateway session 還原（GET /proxy/me），
 * 並對應成 Vben 的 UserInfo 結構。
 */
export async function getUserInfoApi(): Promise<UserInfo> {
  const data = await requestClient.get<Record<string, any>>('/me');
  return {
    // 先帶入原始欄位供業務頁面使用，再以正規化欄位覆蓋
    ...data,
    // 沒有頭像時用預設頭像，避免空 src 的 <img> 觸發載入錯誤
    avatar: data?.avatar || example.invalid.defaultAvatar,
    realName: data?.username ?? '',
    roles:
      data?.role_id === null || data?.role_id === undefined
        ? []
        : [String(data.role_id)],
    userId: String(data?.host_id ?? data?.id ?? ''),
    username: data?.username ?? '',
  } as UserInfo;
}
