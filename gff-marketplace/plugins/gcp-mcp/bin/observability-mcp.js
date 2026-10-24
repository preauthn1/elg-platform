#!/usr/bin/env node
// Cross-platform launcher for @google-cloud/observability-mcp.
// Keep the package pinned so team-wide MCP behavior is reproducible.
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const PKG = '@google-cloud/observability-mcp@0.2.3';

// Persistent per-user cache (NOT /tmp). Two failure modes plagued the old
// /tmp/npm-cache-observability location:
//   1. macOS `com.apple.tmp_cleaner` purges /private/tmp files unaccessed for
//      3 days, leaving the npx extraction half-deleted.
//   2. An interrupted/timed-out npx launch leaves a partially-extracted
//      node_modules, so the next launch crashes with MODULE_NOT_FOUND.
// A stable cache survives reboots/cleanup; purgeNpxCache() self-heals case 2.
function defaultNpmCache() {
  const home = os.homedir();
  if (process.platform === 'win32') {
    const base = process.env.LOCALAPPDATA || path.join(home, 'AppData', 'Local');
    return path.join(base, 'gcp-mcp-observability', 'npm-cache');
  }
  if (process.platform === 'darwin') {
    return path.join(home, 'Library', 'Caches', 'gcp-mcp-observability', 'npm-cache');
  }
  const base = process.env.XDG_CACHE_HOME || path.join(home, '.cache');
  return path.join(base, 'gcp-mcp-observability', 'npm-cache');
}

// Locate the package's real entry file inside the npx extraction tree. The
// relative entry comes from the package's own `bin` field (this one ships
// `./dist/bundle.js`), and the version must match the pin exactly — a drifted
// copy is treated as a miss. Any IO failure returns null so callers fall back
// to npx rather than throwing.
//
// Same logic as apis-toolkit/bin/mysql-mcp.js and gitlab-mcp/bin/gitlab-mcp.js,
// inlined per this repo's convention (no shared module).
function resolveCachedBin(pkgSpec, cacheRoot, fsImpl = fs) {
  if (!cacheRoot) return null;
  const at = pkgSpec.lastIndexOf('@');
  if (at <= 0) return null;
  const name = pkgSpec.slice(0, at);
  const wantVersion = pkgSpec.slice(at + 1);
  const npxDir = path.join(cacheRoot, '_npx');

  let hashes;
  try {
    hashes = fsImpl.readdirSync(npxDir);
  } catch {
    return null;
  }
  for (const hash of hashes) {
    const pkgDir = path.join(npxDir, hash, 'node_modules', name);
    let meta;
    try {
      meta = JSON.parse(fsImpl.readFileSync(path.join(pkgDir, 'package.json'), 'utf8'));
    } catch {
      continue;
    }
    if (meta.version !== wantVersion) continue;
    const rel = typeof meta.bin === 'string' ? meta.bin : meta.bin && meta.bin[path.basename(name)];
    if (!rel) continue;
    return path.join(pkgDir, rel);
  }
  return null;
}

// Prefer running the extracted bundle directly with the current node: that drops
// the resident `npm exec` layer and the shell shim, so a termination signal we
// forward reaches the server itself. Falls back to npx when the cache is not usable.
function buildLaunchCommand({ platform, cacheRoot, fsImpl = fs }) {
  const cached = resolveCachedBin(PKG, cacheRoot, fsImpl);
  if (cached) {
    return { bin: process.execPath, args: [cached], shell: false, group: false };
  }
  return buildNpxCommand({ platform });
}

// `group` asks the launcher to start the child detached (its own process group)
// and to signal that whole group; see signalChild for why only npx needs it.
// Never on Windows, where `detached` opens a separate console.
function buildNpxCommand({ platform }) {
  const isWindows = platform === 'win32';
  return {
    bin: isWindows ? 'npx.cmd' : 'npx',
    args: ['-y', PKG],
    shell: isWindows,
    group: !isWindows,
  };
}

function buildChildEnv(baseEnv) {
  const env = { ...baseEnv };
  if (!env.npm_config_cache) {
    env.npm_config_cache = defaultNpmCache();
  }
  return env;
}

// Wipe only the _npx extraction tree; the content-addressable _cacache store
// stays and is re-verified by npm. Recovers from partial/corrupted extractions
// without forcing a full re-download on every launch.
function purgeNpxCache(cacheDir) {
  try {
    fs.rmSync(path.join(cacheDir, '_npx'), { recursive: true, force: true });
  } catch (err) {
    console.error('[gcp-mcp] failed to purge npx cache:', err);
  }
}

// Self-heal only a *fast* non-zero exit. A corrupted extraction crashes at
// require-time — near-instant, before the MCP server reads stdin — so the
// client's `initialize` bytes stay buffered in the inherited pipe and the
// relaunched child can still consume them. A child that ran past HEAL_WINDOW_MS
// most likely already read the handshake; those stdin bytes are gone, so
// relaunching would hang waiting for input that can never arrive. We trade a few
// missed heals (slow extraction failures just surface) for never hanging.
const HEAL_WINDOW_MS = 5000;

function shouldHeal({ code, healed, elapsedMs, stopping = false }) {
  // Never relaunch while shutting down: a server that exits non-zero in response
  // to a forwarded signal would otherwise look like a fast launch failure.
  return Boolean(code) && !healed && !stopping && elapsedMs < HEAL_WINDOW_MS;
}

const FORWARDED_SIGNALS = ['SIGTERM', 'SIGINT', 'SIGHUP'];
const KILL_GRACE_MS = 1500;

// Take the child down with us when we are told to terminate, so the MCP server
// does not survive as an orphan (PPID=1) holding resources.
//
// The signal is forwarded to the child and the launcher does NOT exit right away:
// the child's `exit` event stays the single place that ends the process. A child
// still alive after graceMs gets SIGKILL (a server may ignore SIGTERM). Only the
// first signal is acted on: repeats are neither re-sent nor allowed to fall back
// to the default "die immediately" behaviour that would abandon the child.
//
// getTarget is a function because this launcher can relaunch its child (self-heal),
// so the handler must always target the current one. It returns { child, group }
// (see signalChild) or null.
// Not registered on Windows: no POSIX signal semantics there, and the npx fallback
// runs through cmd.exe, so kill() would only reach the outer shell.
// SIGKILL cannot be intercepted and is out of reach for this function.
//
// Same logic as apis-toolkit/bin/mysql-mcp.js and gitlab-mcp/bin/gitlab-mcp.js,
// inlined per this repo's convention (no shared module).
// Returns { stopping() }: true once shutdown has begun, so callers stop relaunching.
function forwardSignals(getTarget, proc = process, { graceMs = KILL_GRACE_MS, platform = process.platform, setTimer = setTimeout, killFn } = {}) {
  let stopping = false;
  if (platform === 'win32') return { stopping: () => stopping };
  for (const sig of FORWARDED_SIGNALS) {
    proc.on(sig, () => {
      if (stopping) return;
      stopping = true;
      const target = getTarget();
      if (!target || !target.child) {
        proc.exit(1);
        return;
      }
      signalChild(target, sig, killFn);
      const timer = setTimer(() => signalChild(target, 'SIGKILL', killFn), graceMs);
      if (timer && typeof timer.unref === 'function') timer.unref();
    });
  }
  return { stopping: () => stopping };
}

// Signal the child; with `group` set, signal its whole process group (negative pid).
//
// The npx fallback runs launcher → npm exec → server, so a SIGKILL aimed at the
// direct child never reaches the server, and a server that ignores SIGTERM would
// be left orphaned. That path is spawned detached, making the child a group leader,
// and signalling the group takes the whole chain down. The direct run is only
// launcher → server and needs no group. If the group is already gone (ESRCH),
// fall back to signalling the child itself.
function signalChild({ child, group }, sig, killFn = process.kill.bind(process)) {
  if (group && child.pid) {
    try {
      killFn(-child.pid, sig);
      return;
    } catch (err) {
      // ESRCH means the group is already gone, which is fine. Anything else (e.g. EPERM)
      // means the group was not signalled: report it, then fall back to the child itself.
      if (!err || err.code !== 'ESRCH') {
        console.error(`[gcp-mcp] failed to send ${sig} to process group ${child.pid} (${err && err.code ? err.code : err}); signalling the child only`);
      }
    }
  }
  child.kill(sig);
}

// After the direct child exits, take down whatever is still alive in its process
// group (a no-op unless `group` is set).
//
// On the npx fallback the outer `npm exec` may exit on the signal while a server
// that ignores SIGTERM lives on. The launcher exits on the direct child's `exit`
// event, which takes the pending SIGKILL timer down with it, so the group has to
// be killed right here before exiting. An already-empty group yields ESRCH.
function reapGroup({ child, group }, killFn = process.kill.bind(process)) {
  if (!group || !child.pid) return;
  try {
    killFn(-child.pid, 'SIGKILL');
  } catch (err) {
    // ESRCH means the group is already empty, which is fine. Anything else (e.g. EPERM)
    // means members may have been left behind: report it for troubleshooting.
    if (!err || err.code !== 'ESRCH') {
      console.error(`[gcp-mcp] failed to clean up process group ${child.pid} (${err && err.code ? err.code : err})`);
    }
  }
}

// The per-user cache is shared by every launcher for this user. An unguarded
// `rm -rf _npx` from one session can delete the tree another session is mid-
// extraction on — recreating the exact partial-extraction failure we heal here.
// Guard the purge with an atomic mkdir lock (with a stale-TTL reclaim) so only
// one launcher purges at a time.
const PURGE_LOCK_TTL_MS = 60000;

function acquirePurgeLock(cacheDir, now = Date.now()) {
  const lockDir = path.join(cacheDir, '.gcp-mcp-purge.lock');
  try {
    fs.mkdirSync(cacheDir, { recursive: true });
  } catch (err) {
    console.error('[gcp-mcp] failed to ensure cache dir for purge lock:', err);
    return null;
  }
  try {
    fs.mkdirSync(lockDir); // atomic: throws EEXIST if another launcher holds it
    return lockDir;
  } catch (err) {
    if (err.code !== 'EEXIST') return null;
    try {
      const ageMs = now - fs.statSync(lockDir).mtimeMs;
      if (ageMs > PURGE_LOCK_TTL_MS) {
        // Stale lock left by a crashed launcher; reclaim it.
        fs.rmSync(lockDir, { recursive: true, force: true });
        fs.mkdirSync(lockDir);
        return lockDir;
      }
    } catch {
      // Lost a race to another launcher; treat the lock as held.
    }
    return null;
  }
}

// Purge under the interprocess lock. Returns true if this launcher purged, false
// if another launcher already holds the lock (we skip to avoid rugging its
// in-flight extraction; our single bounded retry still re-runs npx).
function purgeNpxCacheLocked(cacheDir) {
  const lock = acquirePurgeLock(cacheDir);
  if (!lock) {
    console.error('[gcp-mcp] another launcher holds the purge lock; skipping purge.');
    return false;
  }
  try {
    purgeNpxCache(cacheDir);
    return true;
  } finally {
    try {
      fs.rmSync(lock, { recursive: true, force: true });
    } catch (err) {
      console.error('[gcp-mcp] failed to release purge lock:', err);
    }
  }
}

function main() {
  const env = buildChildEnv(process.env);
  const cacheDir = env.npm_config_cache;

  let healed = false;
  let current = null;
  const shutdown = forwardSignals(() => current);
  const launch = () => {
    // Resolve on every attempt, not once per process. A heal purges the _npx
    // tree, so a direct-run target picked before the purge no longer exists —
    // re-resolving lets the retry fall back to npx and actually re-extract.
    const command = buildLaunchCommand({ platform: process.platform, cacheRoot: cacheDir });
    const startedAt = Date.now();
    const child = spawn(command.bin, command.args, {
      stdio: 'inherit',
      env,
      shell: command.shell,
      detached: command.group,
    });
    const target = { child, group: command.group };
    current = target;

    child.on('exit', code => {
      // Before exiting or relaunching, SIGKILL whatever this attempt left in its group:
      // nothing in the group may outlive its launch (the kill is sent, not awaited).
      reapGroup(target);
      if (shouldHeal({ code, healed, elapsedMs: Date.now() - startedAt, stopping: shutdown.stopping() })) {
        healed = true;
        console.error(`[gcp-mcp] launch failed fast (code ${code}); purging npx cache and retrying once.`);
        purgeNpxCacheLocked(cacheDir);
        launch();
        return;
      }
      process.exit(code ?? 1);
    });

    child.on('error', err => {
      console.error('[gcp-mcp] failed to spawn the server:', err);
      process.exit(1);
    });
  };

  launch();
}

if (require.main === module) main();

module.exports = {
  buildNpxCommand,
  buildLaunchCommand,
  resolveCachedBin,
  buildChildEnv,
  defaultNpmCache,
  purgeNpxCache,
  purgeNpxCacheLocked,
  acquirePurgeLock,
  shouldHeal,
  forwardSignals,
  signalChild,
  reapGroup,
  FORWARDED_SIGNALS,
  KILL_GRACE_MS,
  HEAL_WINDOW_MS,
  PURGE_LOCK_TTL_MS,
};
