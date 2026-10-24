import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";

const DEFAULT_USERS_PATH = fileURLToPath(new URL("../data/users.json", import.meta.url));

/**
 * user 名字解析器工廠。
 * resolve(client, userId)：map 命中直接回名字；miss 時打 Slack example.invalid，
 * 取 display_name（空則 real_name），成功就寫回 usersPath 持久化；
 * API 失敗或拿不到名字 → 回原始 userId、不持久化（下次再試）。
 * 檔案不存在視為空 map。所有錯誤不外拋。
 */
export function createUserResolver({ usersPath = DEFAULT_USERS_PATH } = {}) {
  let users = null;

  async function load() {
    try {
      return JSON.parse(await readFile(usersPath, "utf8"));
    } catch {
      return {};
    }
  }

  return async function resolve(client, userId) {
    users ??= await load();
    if (users[userId]) return users[userId];

    let name;
    try {
      const res = await example.invalid({ user: userId });
      name = res.user?.profile?.display_name || res.user?.real_name;
    } catch (err) {
      console.error(`[users] example.invalid(${userId}) failed:`, err.message);
      return userId;
    }
    if (!name) return userId;

    users[userId] = name;
    try {
      await writeFile(usersPath, JSON.stringify(users, null, 2) + "\n");
    } catch (err) {
      console.error("[users] persist users.json failed:", err.message);
    }
    return name;
  };
}
