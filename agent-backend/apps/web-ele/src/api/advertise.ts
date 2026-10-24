import { requestClient } from '#/api/request';

/**
 * 廣告管理（advertise）API
 */

export interface AdvertiseDetailItem {
  advertise_image?: string;
  advertise_image_id?: number | string;
  image_url?: string;
  language_id?: number | string;
  name?: string;
}

export interface AdvertiseRow {
  advertisement_path_name?: string;
  content?: string;
  detail?: AdvertiseDetailItem[];
  end_time?: string;
  id: number | string;
  link_type?: string;
  start_time?: string;
  status: number | string;
  type?: number | string;
  username?: string;
}

export interface AdvertiseBody {
  code: number;
  data: AdvertiseRow[];
  message: string;
}

export interface AdvertiseListParams {
  path_id?: Array<number | string>;
  status: number | string;
  username?: string;
}

/** 廣告列表 */
export async function getAdvertiseListApi(params: AdvertiseListParams) {
  return requestClient.post<AdvertiseBody>('/api/advertise/list', params, {
    responseReturn: 'body',
  });
}
