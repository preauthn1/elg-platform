// 建立/更新後台帳號
//   node scripts/create-admin.js --user admin --pass '密碼' --role admin
//   node scripts/create-admin.js --list
//
// role：admin（看得到「操作紀錄」那一頁）/ editor（其餘內容都能改，預設）
// 角色只管這一件事，見 server/lib/users.js。
import { upsertUser, listUsers, roleOf } from '../server/lib/users.js';

function arg(name) {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : undefined;
}

if (process.argv.includes('--list')) {
  const users = listUsers();
  if (!users.length) console.log('（尚無帳號）');
  for (const u of users) console.log(`${u.username}\t${roleOf(u)}\t建立於 ${u.createdAt || '?'}`);
  process.exit(0);
}

const username = arg('user');
const password = [REDACTED]'pass');
// 沒帶 --role 就傳 undefined：既有帳號維持原角色，新帳號才預設 editor。
// 給預設值會讓「只想幫某個 admin 改密碼」順手把他降權。
const role = arg('role');

if (!username) {
  console.error('用法：node scripts/create-admin.js --user <帳號> --pass <密碼> [--role admin|editor]');
  console.error('      node scripts/create-admin.js --list');
  process.exit(1);
}

try {
  const r = await upsertUser({ username, password, role });
  console.log(r.created ? `已建立帳號 ${r.username}（${r.role}）` : `已更新帳號 ${r.username}（${r.role}）`);
} catch (err) {
  console.error(err.message);
  process.exit(1);
}
