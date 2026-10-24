#!/usr/bin/env node
// 台灣工作日查詢：週末、國定假日、補假、補班都照政府的「中華民國政府行政機關辦公日曆表」
// （政府資料開放平臺資料集 14718）判斷，不自己推算。
//
// 日曆每年一份，放在 plugin 根目錄的 calendar/（<西元年>.csv ＋ 記錄來源網址的 sources.json），
// 經市集更新發到每台電腦；查詢時只讀這份資料，不寫任何檔案。
// gitlab-workitem server 的 check_workdays、next_workday 兩個工具用這支模組，
// 發版閘門（gitlab-release）與開需求單的排期（pm-ticket-create）都經由那兩個工具查。
//
// 維護者更新日曆（下載今年與明年的最新版，寫進 calendar/，再走 MR）：
//   node plugins/gitlab-mcp/bin/tw-workday.js --update
//   node plugins/gitlab-mcp/bin/tw-workday.js --update 2027
'use strict';

const fs = require('fs');
const path = require('path');

const DATASET_API = 'https://example.invalid/api/v2/rest/dataset/14718';
const DATA_DIR = path.join(__dirname, '..', 'calendar');
const UPLOAD_MONTH = /files\/(\d{6})\//; // 下載網址內的上傳年月，越大越新
const WEEKDAYS = '日一二三四五六';
const MAX_SEARCH_DAYS = 60; // 找下一個工作日最多往後看幾天；春節連假加前後週末也遠低於此
const ONLINE_TIMEOUT_MS = 10000;
const UPDATE_HINT = '請維護者在 GFF-Marketplace 跑 node plugins/gitlab-mcp/bin/tw-workday.js --update 後走 MR 更新';

class CalendarError extends Error {}

/** YYYY-MM-DD 且是真實存在的日期；回傳 UTC 午夜的 Date。 */
function parseIsoDate(value, field = 'date') {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    throw new CalendarError(`${field} 格式要是 YYYY-MM-DD，收到：${JSON.stringify(value)}`);
  }
  const d = new Date(`${value}T00:00:00Z`);
  if (Number.isNaN(d.getTime()) || d.toISOString().slice(0, 10) !== value) {
    throw new CalendarError(`${field} 不是有效日期：${value}`);
  }
  return d;
}

function iso(d) {
  return d.toISOString().slice(0, 10);
}

function addDays(d, n) {
  const out = new Date(d.getTime());
  out.setUTCDate(out.getUTCDate() + n);
  return out;
}

/** 回傳 Map：'YYYY-MM-DD' → { holiday, note }。格式不對就丟錯，不猜。 */
function parseCalendar(text) {
  const lines = text.replace(/^﻿/, '').replace(/\r\n/g, '\n').split('\n').filter(l => l.trim() !== '');
  const header = (lines.shift() || '').split(',').map(s => s.trim());
  const col = name => header.indexOf(name);
  const dateCol = col('西元日期');
  const flagCol = col('是否放假');
  const noteCol = col('備註');
  if (dateCol < 0 || flagCol < 0) throw new CalendarError('CSV 欄位不符（找不到「西元日期」或「是否放假」）');
  const table = new Map();
  for (const line of lines) {
    if (line.includes('"')) throw new CalendarError(`CSV 出現引號，格式跟預期不同：${line}`);
    const cells = line.split(',').map(s => s.trim());
    const raw = cells[dateCol] || '';
    if (!/^\d{8}$/.test(raw)) throw new CalendarError(`CSV 的「西元日期」不是 8 位數字：${raw}`);
    const flag = cells[flagCol];
    if (flag !== '0' && flag !== '2') throw new CalendarError(`「是否放假」出現 0、2 以外的值：${raw} → ${flag}`);
    const key = `${raw.slice(0, 4)}-${raw.slice(4, 6)}-${raw.slice(6, 8)}`;
    table.set(key, { holiday: flag === '2', note: noteCol >= 0 ? cells[noteCol] || '' : '' });
  }
  if (table.size < 365) throw new CalendarError(`CSV 只有 ${table.size} 天，資料不完整`);
  return table;
}

function readSources(dir) {
  const file = path.join(dir, 'sources.json');
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
}

/**
 * 讀 calendar/ 的查詢器。每年的資料第一次用到才讀，之後留在記憶體。
 * 線上比對（政府出了修正版、或已公布 repo 還沒收的年份）每個年份每個程序只做一次，
 * 連不上線就略過，不影響判斷。
 */
function createCalendar({ dir = DATA_DIR, fetchImpl = globalThis.fetch, online = true } = {}) {
  const years = new Map();
  const onlineChecks = new Map();

  function year(y) {
    if (!years.has(y)) {
      const file = path.join(dir, `${y}.csv`);
      if (!fs.existsSync(file)) throw new CalendarError(`還沒收錄 ${y} 年的辦公日曆表，${UPDATE_HINT}`);
      years.set(y, parseCalendar(fs.readFileSync(file, 'utf8')));
    }
    return years.get(y);
  }

  function lookup(d) {
    const key = iso(d);
    const entry = year(d.getUTCFullYear()).get(key);
    if (!entry) throw new CalendarError(`${key} 不在 ${d.getUTCFullYear()} 年的辦公日曆表內`);
    return { date: key, weekday: WEEKDAYS[d.getUTCDay()], is_workday: !entry.holiday, note: entry.note };
  }

  async function warningsFor(y) {
    if (!online || typeof fetchImpl !== 'function') return [];
    if (!onlineChecks.has(y)) onlineChecks.set(y, onlineWarnings(y, readSources(dir), fetchImpl));
    return onlineChecks.get(y);
  }

  async function warningsForYears(ys) {
    const lists = await Promise.all([...new Set(ys)].map(warningsFor));
    return lists.flat();
  }

  /** 逐日回報是不是工作日與備註（例如「中秋節」「補假」）。 */
  async function checkWorkdays(dates) {
    const parsed = dates.map((v, i) => parseIsoDate(v, `dates[${i}]`));
    const days = parsed.map(lookup);
    return { days, warnings: await warningsForYears(parsed.map(d => d.getUTCFullYear())) };
  }

  /** after 之後（不含當天）的第一個工作日，並列出中間跳過的放假日。 */
  async function nextWorkday(after) {
    const start = parseIsoDate(after, 'after');
    const skipped = [];
    for (let i = 1; i <= MAX_SEARCH_DAYS; i += 1) {
      const day = lookup(addDays(start, i));
      if (day.is_workday) {
        const ys = [start.getUTCFullYear(), addDays(start, i).getUTCFullYear()];
        return { after: iso(start), next_workday: day.date, weekday: day.weekday, skipped, warnings: await warningsForYears(ys) };
      }
      skipped.push({ date: day.date, weekday: day.weekday, note: day.note });
    }
    throw new CalendarError(`${iso(start)} 之後 ${MAX_SEARCH_DAYS} 天內找不到工作日，日曆資料可能有誤`);
  }

  return { checkWorkdays, nextWorkday };
}

// ─────────────────────────────────────────────────────────────
// 政府資料集
// ─────────────────────────────────────────────────────────────

async function fetchText(fetchImpl, url, timeoutMs) {
  let res;
  try {
    res = await fetchImpl(url, { signal: AbortSignal.timeout(timeoutMs) });
  } catch (e) {
    throw new CalendarError(`下載失敗：${url}（${(e && e.message) || e}）`);
  }
  if (!res.ok) throw new CalendarError(`下載失敗：${url}（HTTP ${res.status}）`);
  return res;
}

async function fetchDistribution(fetchImpl, timeoutMs) {
  const res = await fetchText(fetchImpl, DATASET_API, timeoutMs);
  try {
    return (await res.json()).result.distribution;
  } catch (e) {
    throw new CalendarError(`資料集清單格式不符：${e.message}`);
  }
}

/** 挑出該年最新的一般版 CSV（排除 Google 行事曆專用版）；該年未公布回傳 null。 */
function pickResource(distribution, y) {
  const roc = y - 1911;
  const uploaded = r => (UPLOAD_MONTH.exec(r.resourceDownloadUrl) || [])[1] || '';
  const candidates = distribution.filter(r =>
    String(r.resourceDescription || '').startsWith(`${roc}年`)
    && r.resourceDescription.includes('辦公日曆表')
    && !r.resourceDescription.includes('Google')
    && r.resourceFormat === 'CSV');
  if (!candidates.length) return null;
  return candidates.reduce((a, b) => (uploaded(b) > uploaded(a) ? b : a));
}

async function onlineWarnings(y, sources, fetchImpl) {
  let res;
  try {
    res = pickResource(await fetchDistribution(fetchImpl, ONLINE_TIMEOUT_MS), y);
  } catch {
    return [];
  }
  if (!res) return [];
  if (!sources[String(y)]) return [`政府已公布 ${y} 年的辦公日曆表，repo 還沒收錄，${UPDATE_HINT}`];
  if (res.resourceDownloadUrl !== sources[String(y)].url) {
    return [`政府重新上傳了 ${y} 年的辦公日曆表（${res.resourceDescription}），本次仍以 repo 版判斷，${UPDATE_HINT}`];
  }
  return [];
}

/** 政府上傳的檔案編碼不一：多數是 UTF-8（含 BOM），部分修正版是 Big5。 */
function decode(buf) {
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(buf).replace(/^﻿/, '');
  } catch {
    try {
      return new TextDecoder('big5', { fatal: true }).decode(buf);
    } catch {
      throw new CalendarError('CSV 不是 UTF-8 也不是 Big5，無法解讀');
    }
  }
}

/** 下載最新版、驗證格式後以 UTF-8＋LF 寫進 calendar/，並記錄來源網址。 */
async function update(years, { dir = DATA_DIR, fetchImpl = globalThis.fetch, log = console.log } = {}) {
  const distribution = await fetchDistribution(fetchImpl, 30000);
  const sources = readSources(dir);
  fs.mkdirSync(dir, { recursive: true });
  for (const y of years) {
    const res = pickResource(distribution, y);
    if (!res) {
      log(`${y}：資料集內還沒有這年的辦公日曆表，略過`);
      continue;
    }
    const url = res.resourceDownloadUrl;
    if (sources[String(y)] && sources[String(y)].url === url) {
      log(`${y}：已是最新（${res.resourceDescription}）`);
      continue;
    }
    const text = decode(Buffer.from(await (await fetchText(fetchImpl, url, 30000)).arrayBuffer())).replace(/\r\n/g, '\n');
    parseCalendar(text); // 先驗格式，壞檔不寫入
    fs.writeFileSync(path.join(dir, `${y}.csv`), text, 'utf8');
    sources[String(y)] = { title: res.resourceDescription, url };
    log(`${y}：已寫入（${res.resourceDescription}）`);
  }
  const sorted = Object.fromEntries(Object.keys(sources).sort().map(k => [k, sources[k]]));
  fs.writeFileSync(path.join(dir, 'sources.json'), JSON.stringify(sorted, null, 2) + '\n', 'utf8');
}

/** 台灣時間的今天（台灣不實施夏令時間，固定 UTC+8）。 */
function taipeiToday(now = new Date()) {
  return iso(new Date(now.getTime() + 8 * 3600 * 1000));
}

async function main(argv) {
  if (argv[0] !== '--update') {
    console.error('用法：node tw-workday.js --update [西元年 ...]（預設今年與明年）');
    process.exit(2);
  }
  const thisYear = Number(taipeiToday().slice(0, 4));
  const years = argv.slice(1).map(Number);
  try {
    await update(years.length ? years : [thisYear, thisYear + 1]);
  } catch (e) {
    console.error(`更新失敗：${e.message}`);
    process.exit(2);
  }
}

if (require.main === module) main(process.argv.slice(2));

module.exports = {
  CalendarError,
  DATA_DIR,
  parseCalendar,
  parseIsoDate,
  pickResource,
  createCalendar,
  update,
  taipeiToday,
};
