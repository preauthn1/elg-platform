/* 前台一页 = 后台一页，而且卡片顺序＝该页样板的区块顺序。
   一个前台区块一张卡，区块标题就放在它自己那张卡里 —— 不要另开一张总表，
   否则改一个区块要在两张卡之间来回跳。

   该页自己的资料列表（活动、游戏商、影像、文章…）用 slots 插进区块之间，
   靠 PB_PAGES 里的 { kind: "slot", name } 定位；只有排在最后的才走 extra。

   对照关系（code 对 pages 那 11 笔，pb 对 page_blocks 的键）：
     code === pb 的页面就不必分别写；首页没有 page_blocks，整页靠 extra。 */
import * as store from '../store.js';
import { crumb } from '../shell.js';
import { langChips } from '../ui.js';
import { heroCard } from './hero.js';
import { blocksCard } from './pageblocks.js';

/** 产生一个页面 view。extra 是该页专属的卡片（回传 HTML 字串的函式阵列）。 */
function pageView({ key, code, pb = code, needs = [], slots = {}, extra = [], mount }) {
  return {
    // pages 与 page_blocks 每一页都要；extra 用到的类别由呼叫端补
    needs: ['pages', ...(pb ? ['page_blocks'] : []), ...needs],
    render() {
      /* 语系选择器整页只有一个 —— 原本每张卡各有一个、各自独立记状态，
         在这一张选了简体、下一张还停在 English，站方以为资料没存进去。 */
      return (
        crumb(key) +
        `<div class="card"><div class="card-b">${langChips('page')}</div></div>` +
        heroCard(code) +
        (pb ? blocksCard(pb, slots) : '') +
        extra.map((f) => f()).join('')
      );
    },
    ...(mount ? { mount } : {}),
  };
}

/* --- 各页专属卡片：从原本的 view 档案拿现成的 --- */
import { navCardsCard, journeyCard, ambassadorCard, sponsorsCard } from './homeblock.js';
import { statisticCards, statisticMount } from './statistic.js';
import { promotionCard } from './promotion.js';
import { gameCards } from './game.js';
import { mediaCards } from './media.js';
import { contactCard } from './contact.js';
import { articleCards } from './article.js';

/* 首页没有 page_blocks（首屏之外的区块都在 home_blocks 与 statistics）。
   卡片顺序＝templates/pages/home.njk 的区块顺序：
     首屏 → 四格导览卡 → 平台核心数据 → 发展历史 → 代言人 → 赞助 */
export const home = pageView({
  key: 'home',
  code: 'home',
  pb: null,
  needs: ['home_blocks', 'statistics'],
  extra: [navCardsCard, statisticCards, journeyCard, ambassadorCard, sponsorsCard],
  mount: statisticMount,
});

export const advantages = pageView({ key: 'advantages', code: 'advantages' });
export const guide = pageView({ key: 'guide', code: 'guide' });
export const faq = pageView({ key: 'faq', code: 'faq' });
export const partners = pageView({ key: 'partners', code: 'partners' });
export const compliance = pageView({ key: 'compliance', code: 'compliance' });

export const promotions = pageView({
  key: 'promotions',
  code: 'promotions',
  needs: ['promotions'],
  slots: { promotionCard },
});

export const games = pageView({
  key: 'games',
  code: 'games',
  needs: ['game_categories', 'game_providers'],
  slots: { gameCards },
});

export const brand = pageView({
  key: 'brand',
  code: 'brand',
  needs: ['media'],
  slots: { mediaCards },
});

/* 官方联系的 pages.code 是 contact，page_blocks 的键也是 contact */
export const contact = pageView({
  key: 'contact',
  code: 'contact',
  needs: ['contacts'],
  slots: { contactCard },
});

export const blog = pageView({
  key: 'blog',
  code: 'blog',
  needs: ['article_categories', 'articles'],
  slots: { articleCards },
});
