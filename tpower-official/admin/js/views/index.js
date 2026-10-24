/* 画面注册表（key 对应 shell.js 的 ROUTES）。
   除了「全站共用」与「操作纪录」，一个画面对一条前台路径 —— 见 views/pages.js。 */
import { site } from './site.js';
import {
  home,
  advantages,
  promotions,
  games,
  brand,
  contact,
  blog,
  guide,
  faq,
  partners,
  compliance,
} from './pages.js';
import { audit } from './audit.js';
import { accounts } from './accounts.js';
import { system } from './system.js';
import { snapshots } from './snapshots.js';

export const VIEWS = {
  site,
  home,
  advantages,
  promotions,
  games,
  brand,
  contact,
  blog,
  guide,
  faq,
  partners,
  compliance,
  audit,
  accounts,
  system,
  snapshots,
};
