/* 官方联系设定 —— 上限 10 笔。存档前跳一次确认：这几条连结直接出现在官网上，
   改错就是把访客导去别的地方（仿冒钓鱼会盯这里）。 */
import * as store from '../store.js';
import {
  esc,
  ACTIONS,
  addRow,
  delRow,
  sw,
  selectOf,
  getInlineLang,
  langInput,
  renderView,
} from '../ui.js';
import { nextSort } from './common.js';

/* ⚠ 這幾個中文字串是「存進 content.json 的識別碼」，server 端用 z.enum 驗證，
   所以必須維持原樣（繁體）—— 轉成簡體會讓存檔直接 422。
   畫面上要顯示簡體，靠下面的 *_LABEL 對照表（跟語系的 LANG_NAME 同一個做法）。
   之後若要徹底解決，正解是把識別碼換成 ASCII code（像遊戲分類的 SLOT/SPORTS），
   那需要連 schema 與既有資料一起遷移，不在這次範圍。 */
const TYPES = ['Telegram', 'WhatsApp', 'Email', 'LINE', { v: '自訂', t: '自订' }];
const MAX = 10;

export function contactCard() {
    const list = store.get('contacts');
    const lang = getInlineLang('page');
    return `
<div class="card">
  <div class="card-h">官方渠道 <span class="sub">${list.length} / ${MAX} 笔</span>
    <div class="right"><button class="btn sm" data-act="contact.add">新增</button>
      <button class="btn sm primary" data-act="contact.save">更新储存</button></div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:60px">顺序</th><th style="width:130px">渠道类型</th>
      <th style="width:170px">显示名称</th>
      <th>连结（有填就能点，整张卡）</th><th style="width:250px">没连结时显示的文字</th>
      <th style="width:84px">状态</th><th style="width:70px">操作</th>
    </tr></thead>
    <tbody>${list
      .map(
        (c, i) => `
      <tr>
        <td><input class="inp xs num" data-bind="contacts:${i}.sort" data-type="int" value="${esc(c.sort)}"></td>
        <td>${selectOf(TYPES, c.type, `contacts:${i}.type`, 'min-width:112px')}</td>
        <td>${langInput(`contacts:${i}.name`, c.name, lang)}</td>
        <td><input class="inp mono" style="min-width:200px;width:100%;font-size:13.5px" data-bind="contacts:${i}.link" value="${esc(
          c.link
        )}" placeholder="https://example.invalid/… 留空则不可点"></td>
        <td>${langInput(`contacts:${i}.value`, c.value, lang, { ph: '选填 · 例：官方账号即将公布' })}</td>
        <td>${sw(c.on, `contacts:${i}.on`)}</td>
        <td class="ops"><button class="btn text danger-t" data-act="contact.del" data-arg="${i}">删除</button></td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
</div>

`;
}


Object.assign(ACTIONS, {
  'contact.add': () => {
    const list = store.get('contacts');
    const tpl = {
      id: 'contact-' + Date.now().toString(36),
      type: 'Telegram',
      label: {},
      name: {},
      value: {},
      link: '',
      placeholder: {},
      sort: nextSort(list),
      on: true,
    };
    if (addRow(list, tpl, { max: MAX })) renderView();
  },
  'contact.del': (i) => {
    if (delRow(store.get('contacts'), +i, { min: 1 })) renderView();
  },
  'contact.save': () => store.save('contacts'),
});
