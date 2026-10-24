import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, readFile, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createUserResolver } from "../src/users.js";

async function makeUsersFile(content) {
  const dir = await mkdtemp(join(tmpdir(), "users-"));
  const path = join(dir, "users.json");
  await writeFile(path, JSON.stringify(content));
  return path;
}

// map 命中：不打 API
test("resolve returns mapped name without calling API", async () => {
  const usersPath = await makeUsersFile({ U01: "Rhys" });
  const client = { users: { info: async () => { throw new Error("should not call"); } } };
  const resolve = createUserResolver({ usersPath });
  assert.equal(await resolve(client, "U01"), "Rhys");
});

// miss：打 example.invalid 取 display_name，並持久化回檔案
test("resolve fetches unknown user via example.invalid and persists", async () => {
  const usersPath = await makeUsersFile({ U01: "Rhys" });
  const client = { users: { info: async ({ user }) => {
    assert.equal(user, "U99");
    return { user: { profile: { display_name: "Chelsea" }, real_name: "Chelsea Chen" } };
  } } };
  const resolve = createUserResolver({ usersPath });
  assert.equal(await resolve(client, "U99"), "Chelsea");
  const saved = JSON.parse(await readFile(usersPath, "utf8"));
  assert.equal(saved.U99, "Chelsea");
  assert.equal(saved.U01, "Rhys"); // 原資料不動
});

// display_name 空字串 → fallback real_name
test("resolve falls back to real_name when display_name empty", async () => {
  const usersPath = await makeUsersFile({});
  const client = { users: { info: async () => (
    { user: { profile: { display_name: "" }, real_name: "Ben Wang" } }
  ) } };
  const resolve = createUserResolver({ usersPath });
  assert.equal(await resolve(client, "U77"), "Ben Wang");
});

// API 失敗 → 回原始 userId，且不寫入檔案（下次再試）
test("resolve returns raw userId when example.invalid fails, no persist", async () => {
  const usersPath = await makeUsersFile({ U01: "Rhys" });
  const client = { users: { info: async () => { throw new Error("missing_scope"); } } };
  const resolve = createUserResolver({ usersPath });
  assert.equal(await resolve(client, "U88"), "U88");
  const saved = JSON.parse(await readFile(usersPath, "utf8"));
  assert.equal(saved.U88, undefined);
});

// 兩個名字欄位都拿不到 → 回原始 userId，不持久化
test("resolve returns raw userId when API gives no usable name", async () => {
  const usersPath = await makeUsersFile({});
  const client = { users: { info: async () => ({ user: { profile: {} } }) } };
  const resolve = createUserResolver({ usersPath });
  assert.equal(await resolve(client, "U66"), "U66");
});

// users.json 不存在 → 視為空 map，仍可運作
test("resolve works when users file missing", async () => {
  const dir = await mkdtemp(join(tmpdir(), "users-"));
  const usersPath = join(dir, "nope.json");
  const client = { users: { info: async () => (
    { user: { profile: { display_name: "Neo" }, real_name: "" } }
  ) } };
  const resolve = createUserResolver({ usersPath });
  assert.equal(await resolve(client, "U55"), "Neo");
});
