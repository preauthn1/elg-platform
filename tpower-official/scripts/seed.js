/* 初始內容 —— 把 seed/<站>/content.json 灌進 content/（站由 .env 的 SITE 決定）。
 *
 * ⚠ seed/<站>/content.json 就是站台內容的版控來源。content/ 不進版控（那是站方在改的
 *   活資料），所以「換機器重建」「改壞了重來」都靠這一份。要改預設文案就改它。
 *
 * ⚠ 這支腳本一度有一千三百行：從工單附的後台原型（一份扁平的簡中 DATA）即時推導出
 *   三語系結構，中間還壓著兩份對照表（中文原句 → 英文、資料路徑 → 各語系）。
 *   內容定案之後那整層推導就只剩維護成本，而且每加一種語系就要再長一層。
 *   現在直接存結果：原型檔、對照表、推導邏輯全部不需要了。
 *
 * 用法：
 *   npm run seed                  灌入（已存在的類別不覆蓋）
 *   node scripts/seed.js --force  覆蓋（writeEntity 會先留快照）
 */
import fs from 'node:fs';
import path from 'node:path';
import { config } from '../server/lib/config.js';
import { site } from '../server/sites/index.js';
import { writeEntity, hasEntity, readSystem, writeSystem } from '../server/lib/store.js';
import { validateEntity, zodIssues, ENTITY_KEYS } from '../server/schema/content.js';
import { upsertUser, listUsers } from '../server/lib/users.js';

const FORCE = process.argv.includes('--force');
const WITH_DEV_ADMIN = process.argv.includes('--with-dev-admin');

const SEED_FILE = path.join(config.dirs.seed, 'content.json');
if (!fs.existsSync(SEED_FILE)) {
  console.error('找不到 ' + SEED_FILE);
  process.exit(1);
}
const seed = JSON.parse(fs.readFileSync(SEED_FILE, 'utf8'));

/* 分類的參照檢查要拿到「同一批」資料才算得準：文章的 cat 必須存在於
   article_categories，遊戲商的 cat 必須存在於 game_categories，反過來也不能有
   沒人用的孤兒分類。四個都從 seed 傳進去，順序就不影響結果。 */
const refs = {
  article_categories: seed.article_categories,
  game_categories: seed.game_categories,
  articles: seed.articles,
  game_providers: seed.game_providers,
};

let wrote = 0;
let skipped = 0;
let failed = 0;

for (const key of ENTITY_KEYS) {
  if (!(key in seed)) {
    console.error('✗ ' + SEED_FILE + ' 少了 ' + key);
    failed++;
    continue;
  }
  if (hasEntity(key) && !FORCE) {
    skipped++;
    continue;
  }
  try {
    await writeEntity(key, validateEntity(key, seed[key], { refs }), { by: 'seed' });
    wrote++;
  } catch (err) {
    failed++;
    console.error('✗ ' + key + ' 驗證未通過：');
    for (const issue of err.issues ? zodIssues(err) : [err.message]) console.error('   - ' + issue);
  }
}

// 圖片：已存在的不覆蓋（站方換過的圖不該被灌回原稿）
let copied = 0;
const seedImages = path.join(config.dirs.seed, 'images');
if (fs.existsSync(seedImages)) {
  fs.mkdirSync(config.dirs.uploads, { recursive: true });
  for (const img of fs.readdirSync(seedImages)) {
    const target = path.join(config.dirs.uploads, img);
    if (fs.existsSync(target)) continue;
    fs.copyFileSync(path.join(seedImages, img), target);
    copied++;
  }
}

if (WITH_DEV_ADMIN && !listUsers().length) {
  const dev = site.devAdmin;
  await upsertUser(dev);
  console.log(`已建立開發用帳號 ${dev.username} / ${dev.password}（僅本機；正式環境請用 npm run create-admin 另建並刪除）`);
}

/* 取數設定的空骨架。先寫進去，部署的人才知道要填什麼、填在哪 ——
   否則第一次上線只會看到「api.baseUrl 未設定」而不知道那個鍵長什麼樣。 */
if (!readSystem('api', null)) {
  await writeSystem('api', { baseUrl: '', aesKey: '', aesIv: '', aesMd5: '', brandDomain: '' });
  console.log('已在 content/system.json 建立空的 api 設定（取數用，在後台「系統設置」那一頁填）');
}

console.log(
  '\n內容：寫入 ' +
    wrote +
    ' 個' +
    (skipped ? '，略過 ' + skipped + ' 個既有類別（--force 可覆蓋）' : '') +
    (failed ? '，失敗 ' + failed + ' 個' : '')
);
console.log('圖片：複製 ' + copied + ' 個到 uploads/');
if (failed) process.exit(1);
