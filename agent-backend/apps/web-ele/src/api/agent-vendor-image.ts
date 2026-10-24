import { requestClient } from '#/api/request';

/**
 * 代理群組遊戲商圖設定（agent/vendor_image_setting，從 group_list 帶 group_id 進入）。 */

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

/** 遊戲商圖列 */
export interface VendorImageRow {
  app_icon?: string;
  id: number | string;
  image?: string;
  name?: string;
  show_name?: string;
}

/** 取群組遊戲商圖設定 */
export async function getVendorImageSettingsApi(groupId: number | string) {
  return requestClient.post<
    BodyOf<{
      data?: VendorImageRow[];
      groups?: { id?: number | string; name?: string };
    }>
  >(
    '/api/agent/vendor_image_settings',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/** 儲存群組遊戲商圖 */
export async function updateVendorImageSettingsApi(params: {
  group_id: number | string;
  vendor_image_datas: Array<{
    app_icon?: string;
    image?: string;
    show_name?: string;
    vendor_id: number | string;
  }>;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/agent/update_vendor_image_settings',
    params,
    { responseReturn: 'body' },
  );
}
