/**
 * 歷史報告保留器：每次執行結束後，把 HTML 報告複製一份存進 reports/history/，
 * 資料夾名稱帶「日期時間＋結果摘要」（例：2026-08-27_10-42_失敗2_通過3），
 * 讓 QA 之後隨時能回頭開任何一次的報告（搭配 scripts/open-history.mjs）。
 *
 * 只保留最近 20 次，更舊的自動刪除，不會吃硬碟。
 */
import fs from 'node:fs';
import path from 'node:path';
import type { Reporter, TestCase, TestResult } from '@playwright/test/reporter';

const REPORT_DIR = path.resolve('playwright-report');
const HISTORY_DIR = path.resolve('reports/history');
const KEEP = 20;

function two(n: number): string {
  return String(n).padStart(2, '0');
}

function stamp(d: Date): string {
  return (
    `${d.getFullYear()}-${two(d.getMonth() + 1)}-${two(d.getDate())}` +
    `_${two(d.getHours())}-${two(d.getMinutes())}-${two(d.getSeconds())}`
  );
}

class HistoryReporter implements Reporter {
  private startedAt = 0;
  private passed = 0;
  private failed = 0;
  private skipped = 0;

  onBegin(): void {
    this.startedAt = Date.now();
  }

  onTestEnd(test: TestCase, result: TestResult): void {
    if (result.status === 'skipped') this.skipped += 1;
    else if (result.status === test.expectedStatus) this.passed += 1;
    else if (result.status !== 'interrupted') this.failed += 1;
  }

  // onExit 在所有 reporter（含 HTML 報告寫檔）完成後才執行，此時報告已經在硬碟上
  async onExit(): Promise<void> {
    try {
      const index = path.join(REPORT_DIR, 'index.html');
      if (!fs.existsSync(index)) return;
      // 報告比這次執行還舊 → 這次沒有產新報告（例如 UI 模式），不要存到重複的
      if (fs.statSync(index).mtimeMs < this.startedAt) return;
      if (this.passed + this.failed + this.skipped === 0) return;

      const parts = [stamp(new Date())];
      if (this.failed > 0) parts.push(`失敗${this.failed}`);
      if (this.passed > 0) parts.push(`通過${this.passed}`);
      if (this.skipped > 0) parts.push(`跳過${this.skipped}`);

      fs.mkdirSync(HISTORY_DIR, { recursive: true });
      fs.cpSync(REPORT_DIR, path.join(HISTORY_DIR, parts.join('_')), { recursive: true });

      // 只留最近 KEEP 次（資料夾名以時間開頭，字典序＝時間序）
      const dirs = fs
        .readdirSync(HISTORY_DIR)
        .filter((name) => fs.statSync(path.join(HISTORY_DIR, name)).isDirectory())
        .sort()
        .reverse();
      for (const old of dirs.slice(KEEP)) {
        fs.rmSync(path.join(HISTORY_DIR, old), { recursive: true, force: true });
      }
    } catch (err) {
      // 存歷史失敗不影響測試結果，提示就好
      console.warn('⚠️  保留歷史報告失敗：', err);
    }
  }
}

export default HistoryReporter;
