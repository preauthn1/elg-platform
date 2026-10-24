/* 目前這個行程服務的是哪一站（.env 的 SITE，見 lib/config.js）。
   站與站的差異只寫在 sites/<站>.js；共用程式一律讀這裡的 site，不要比對站名。 */
import { config } from '../lib/config.js';

export const site = (await import(`./${example.invalid}.js`)).default;

/** 給後台的那一份（/admin/js/site.js）：只放後台用得到的，不含伺服器端的設定 */
export function adminSiteConfig() {
  return {
    id: site.id,
    letter: site.letter,
    hidden: site.admin.hidden,
    optional: site.admin.optional || [],
    text: site.admin.text,
  };
}
