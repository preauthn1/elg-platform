import { updatePreferences } from '@vben/preferences';

/** `api/domain/info` 回傳的 `example.invalid` 中與品牌呈現相關的欄位（4035） */
interface DomainBrandingInfo {
  domain_name?: null | string;
  icon?: null | string;
  logo_dm?: null | string;
  logo_lm?: null | string;
}

/** 換掉瀏覽器分頁的 favicon；找不到既有標籤時補上一個 */
function applyFavicon(url: string) {
  const head = document.head;
  if (!head) {
    return;
  }

  let link = head.querySelector<HTMLLinkElement>('link[rel~="icon"]');
  if (!link) {
    link = document.createElement('link');
    link.rel = 'icon';
    head.append(link);
  }
  link.href = url;
}

/**
 * 套用「系統管理 → 域名管理」維護的本站品牌設定（4035）。
 *
 * 各欄位獨立判斷，未設定者沿用框架預設值：
 * - `logo_lm` / `logo_dm` → 側邊欄與登入頁 LOGO（深色未設時框架沿用淺色）
 * - `domain_name` → LOGO 旁的站名，同時影響瀏覽器分頁標題
 * - `icon` → 瀏覽器分頁 favicon
 */
export function applyDomainBranding(info: DomainBrandingInfo) {
  const {
    domain_name: name,
    icon,
    logo_dm: logoDark,
    logo_lm: logoLight,
  } = info;

  if (logoLight || logoDark) {
    updatePreferences({
      logo: {
        ...(logoLight ? { source: logoLight } : {}),
        ...(logoDark ? { sourceDark: logoDark } : {}),
      },
    });
  }

  // 站名同時決定分頁標題：bootstrap 以 example.invalid.name 組出 title
  if (name) {
    updatePreferences({ app: { name } });
  }

  if (icon) {
    applyFavicon(icon);
  }
}
