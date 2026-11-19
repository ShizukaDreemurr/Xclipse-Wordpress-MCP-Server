/**
 * Xclipse MCP Core — API client, TTL cache, response compaction, error classification,
 * dynamic tool catalog & compact gateway (xclipse_discover / xclipse_call).
 */
import { AsyncLocalStorage } from 'node:async_hooks';
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';

const __dir = dirname(fileURLToPath(import.meta.url));

export let VERSION = '5.0.0';
try {
  VERSION = JSON.parse(readFileSync(resolve(__dir, '..', 'package.json'), 'utf8')).version || VERSION;
} catch {}

// Load .env if present (never overwrite existing env vars)
const envPath = resolve(__dir, '..', '.env');
if (existsSync(envPath)) {
  for (const raw of readFileSync(envPath, 'utf8').replace(/^\uFEFF/, '').split(/\r?\n/)) {
    let line = raw.replace(/\r/g, '').trim();
    if (!line || line.startsWith('#')) continue;
    line = line.replace(/^export\s+/, '');
    const i = line.indexOf('=');
    if (i < 1) continue;
    const k = line.slice(0, i).trim();
    let v = line.slice(i + 1).trim();
    if (v.length >= 2 && ((v[0] === '"' && v.endsWith('"')) || (v[0] === "'" && v.endsWith("'")))) {
      v = v.slice(1, -1);
    }
    if (!(k in process.env)) process.env[k] = v;
  }
}

export const requestStore = new AsyncLocalStorage();

export function getEnvConfig() {
  return {
    base: (process.env.XCLIPSE_URL || '').replace(/\/$/, ''),
    key: process.env.XCLIPSE_API_KEY || '',
    timeoutMs: Math.max(1000, parseInt(process.env.XCLIPSE_TIMEOUT_MS, 10) || 30000),
    maxOut: Math.max(200, parseInt(process.env.XCLIPSE_MAX_OUT, 10) || 12000),
    compact: process.env.XCLIPSE_COMPACT !== '0',
    tools: String(process.env.XCLIPSE_TOOLS || '').split(',').map((s) => s.trim().toLowerCase()).filter(Boolean),
    mode: String(process.env.XCLIPSE_TOOL_MODE || 'full').trim().toLowerCase(),
  };
}

export function getSitesConfig() {
  const raw = (process.env.XCLIPSE_SITES || '').trim();
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

function getCtx() {
  return requestStore.getStore() || getEnvConfig();
}

const RETRY_DELAYS = [500, 1500];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ── In-memory TTL Cache for safe read-only GET endpoints ─────────────────────
const CACHE_TTL_MS = Math.max(0, parseInt(process.env.XCLIPSE_CACHE_TTL_MS, 10) || 15000);
const CACHEABLE_PATHS = [
  '/categories', '/tags', '/acf/groups',
  '/wpms/mailers', '/plugins', '/themes', '/users',
];
const cache = new Map(); // key: `${base}:${path}` -> { exp, data }

function isCacheable(method, path) {
  if (CACHE_TTL_MS <= 0 || method !== 'GET') return false;
  const cleanPath = path.split('?')[0];
  return CACHEABLE_PATHS.some((p) => cleanPath === p);
}

function invalidateCache(base) {
  const prefix = `${base}:`;
  for (const k of cache.keys()) {
    if (k.startsWith(prefix)) cache.delete(k);
  }
}

// ── Error classification & network translation ───────────────────────────────
function netError(e, base, timeoutMs) {
  const code = e?.cause?.code || e?.code || '';
  const raw = e?.cause?.message || e?.message || String(e);
  const hint = `Kiểm tra XCLIPSE_URL (${base}) đúng http/https chưa, site có đang chạy không, firewall/hosting có chặn không.`;
  let msg;
  if (e?.name === 'TimeoutError' || e?.name === 'AbortError' || code === 'ETIMEDOUT' || code === 'UND_ERR_CONNECT_TIMEOUT') {
    msg = `Hết thời gian chờ (${timeoutMs}ms) khi gọi ${base}. Có thể tăng XCLIPSE_TIMEOUT_MS.`;
  } else if (code === 'ECONNREFUSED') {
    msg = `Kết nối bị từ chối (ECONNREFUSED) tới ${base}.`;
  } else if (code === 'ENOTFOUND' || code === 'EAI_AGAIN') {
    msg = `Không phân giải được tên miền của ${base} (${code}).`;
  } else if (code === 'ECONNRESET' || code === 'UND_ERR_SOCKET') {
    msg = `Kết nối bị ngắt giữa chừng (${code}).`;
  } else if (/CERT|SSL|TLS|SELF_SIGNED|UNABLE_TO_VERIFY|DEPTH_ZERO/i.test(code + ' ' + raw)) {
    msg = `Lỗi chứng chỉ SSL/TLS (${code || raw}).`;
  } else {
    msg = `Lỗi mạng khi gọi ${base}: ${raw}.`;
  }
  const err = new Error(msg);
  err.net = true;
  err.code = code || 'NETWORK_ERROR';
  err.hint = hint;
  return err;
}

function classifyHttpError(status, message) {
  const err = new Error(message || `HTTP ${status}`);
  err.status = status;
  if (status === 401 || status === 403) {
    err.code = 'AUTH_ERROR';
    err.hint = 'Kiểm tra XCLIPSE_API_KEY và đảm bảo plugin xclipse-mcp-api đang active trên WordPress.';
  } else if (status === 404) {
    err.code = 'NOT_FOUND';
    err.hint = 'ID hoặc endpoint không tồn tại. Kiểm tra lại ID hoặc permalink/REST API trên WordPress.';
  } else if (status === 400 || status === 422) {
    err.code = 'VALIDATION_ERROR';
    err.hint = 'Tham số truyền vào không hợp lệ. Kiểm tra lại kiểu dữ liệu và các trường bắt buộc.';
  } else if (status === 429) {
    err.code = 'RATE_LIMIT';
    err.hint = 'Site đang giới hạn tần suất request. Đợi vài giây rồi thử lại.';
  } else if (status >= 500) {
    err.code = 'SERVER_ERROR';
    err.hint = 'Lỗi phía WordPress/hosting (5xx). Kiểm tra debug.log hoặc tài nguyên server.';
  } else {
    err.code = `HTTP_${status}`;
  }
  return err;
}

const cleanSnippet = (t) =>
  String(t)
    .replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 200);

async function apiOnce(ctx, path, method, body) {
  const { base, key, timeoutMs } = ctx;
  if (!base || !key) {
    const err = new Error('Thiếu cấu hình XCLIPSE_URL hoặc XCLIPSE_API_KEY');
    err.code = 'MISSING_CONFIG';
    err.hint = 'Cung cấp XCLIPSE_URL & XCLIPSE_API_KEY qua .env hoặc HTTP headers (X-WP-URL, X-WP-Key).';
    throw err;
  }
  let res, text;
  try {
    res = await fetch(`${base}/wp-json/xclipse-mcp/v1${path}`, {
      method,
      headers: { 'X-MCP-Key': key, 'Content-Type': 'application/json' },
      signal: AbortSignal.timeout(timeoutMs),
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    text = await res.text();
  } catch (e) {
    throw netError(e, base, timeoutMs);
  }

  let json;
  let parsed = true;
  if (text === '') json = {};
  else {
    try { json = JSON.parse(text); } catch { parsed = false; }
  }

  if (!parsed) {
    throw classifyHttpError(res.status, `HTTP ${res.status}: ${cleanSnippet(text)}`);
  }
  if (!res.ok) {
    const msg = (json && typeof json === 'object' && json.message) || `HTTP ${res.status}`;
    const err = classifyHttpError(res.status, msg);
    if (res.status === 429) {
      const ra = parseInt(res.headers.get('retry-after'), 10);
      if (ra > 0) err.retryAfterMs = Math.min(ra * 1000, 5000);
    }
    throw err;
  }
  return json;
}

export async function api(path, method = 'GET', body = null) {
  const ctx = getCtx();
  method = String(method).toUpperCase();

  if (method !== 'GET') {
    invalidateCache(ctx.base);
  } else if (isCacheable(method, path)) {
    const hit = cache.get(`${ctx.base}:${path}`);
    if (hit && hit.exp > Date.now()) return hit.data;
  }

  const canRetry = method === 'GET';
  for (let attempt = 0; ; attempt++) {
    try {
      const data = await apiOnce(ctx, path, method, body);
      if (isCacheable(method, path)) {
        cache.set(`${ctx.base}:${path}`, { exp: Date.now() + CACHE_TTL_MS, data });
      }
      return data;
    } catch (e) {
      const retryable =
        canRetry &&
        attempt < RETRY_DELAYS.length &&
        (e.net || e.status === 429 || e.status === 502 || e.status === 503 || e.status === 504);
      if (!retryable) throw e;
      if (process.env.XCLIPSE_DEBUG) {
        console.error(`[XCLIPSE] retry ${attempt + 1} ${method} ${path}: ${e.message.slice(0, 80)}`);
      }
      await sleep(e.retryAfterMs || RETRY_DELAYS[attempt]);
    }
  }
}

// ── Token-saving Response Compaction ─────────────────────────────────────────
export function compactValue(val, depth = 0) {
  if (depth > 10 || val === null || val === undefined || val === '') return undefined;
  if (Array.isArray(val)) {
    if (val.length === 0) return undefined;
    const arr = val.map((v) => compactValue(v, depth + 1)).filter((v) => v !== undefined);
    return arr.length ? arr : undefined;
  }
  if (typeof val === 'object') {
    const out = {};
    let hasKeys = false;
    for (const [k, v] of Object.entries(val)) {
      const cv = compactValue(v, depth + 1);
      if (cv !== undefined) {
        out[k] = cv;
        hasKeys = true;
      }
    }
    return hasKeys ? out : undefined;
  }
  return val;
}

/** Compact JSON without indent — saves ~30-45% tokens */
export const j = (d) => {
  const ctx = getCtx();
  const payload = ctx.compact !== false ? (compactValue(d) ?? {}) : d;
  return { content: [{ type: 'text', text: JSON.stringify(payload) }] };
};

/** Short success response */
export const ok = (msg, extra = {}) => {
  const ctx = getCtx();
  const payload = ctx.compact !== false ? (compactValue({ ok: true, msg, ...extra }) ?? { ok: true, msg }) : { ok: true, msg, ...extra };
  return { content: [{ type: 'text', text: JSON.stringify(payload) }] };
};

export const Pager = { per_page: z.number().default(20), page: z.number().default(1) };
export const Status = (vals, def = 'publish') => ({ status: z.enum(vals).default(def) });

export const qs = (p) =>
  new URLSearchParams(
    Object.fromEntries(
      Object.entries(p)
        .filter(([, v]) => v != null && v !== '')
        .map(([k, v]) => [k, String(v)])
    )
  );

// ── Tool Groups & Output Limiter ─────────────────────────────────────────────
export const TOOL_GROUPS = [
  [/^(products?|orders?|coupons?|customers?|shipping|tax|payment|wc|bulk_products)_/, 'woo'],
  [/^(rankmath|audit|fix_seo|serp|rank|sitemap|schema|rich|url)_/, 'seo'],
  [/^elementor_/, 'elementor'],
  [/^(ux|uxb|flatsome)_/, 'flatsome'],
  [/^(lscache|cache)_/, 'cache'],
  [/^pixta_/, 'pixta'],
  [/^cf7_/, 'forms'],
  [/^wpms_/, 'mail'],
];

export const ALL_GROUPS = ['core', 'woo', 'seo', 'elementor', 'flatsome', 'cache', 'pixta', 'forms', 'mail'];

export const groupOf = (name) => {
  for (const [re, g] of TOOL_GROUPS) if (re.test(name)) return g;
  return 'core';
};

// Essential tools exposed directly in `compact` mode (saves ~90% tools/list tokens)
export const COMPACT_CORE_TOOLS = new Set([
  'site_info', 'site_snapshot', 'batch',
  'posts_list', 'post_get', 'post_create', 'post_update', 'post_delete',
  'pages_list', 'page_get', 'page_create', 'page_update',
  'media_list', 'media_upload_base64',
  'user_change_password',
  'products_list', 'product_get', 'product_create', 'product_update',
  'orders_list', 'order_get',
  'rankmath_get', 'rankmath_update', 'audit_summary',
  'lscache_purge_all', 'lscache_purge_url',
  'wpms_status',
]);

const safeCut = (s, n) => {
  if (s.length <= n) return s;
  let e = n;
  const c = s.charCodeAt(e - 1);
  if (c >= 0xd800 && c <= 0xdbff) e--;
  return s.slice(0, e);
};

export function limitOutput(res, maxOut) {
  if (!res || !Array.isArray(res.content)) return res;
  const limit = maxOut || getCtx().maxOut || 12000;
  let total = 0;
  for (const c of res.content) {
    if (c && c.type === 'text' && typeof c.text === 'string') total += c.text.length;
  }
  if (total <= limit) return res;
  let left = limit;
  const out = [];
  for (const c of res.content) {
    if (c && c.type === 'text' && typeof c.text === 'string') {
      if (left <= 0) continue;
      const t = safeCut(c.text, left);
      left -= t.length;
      out.push({ ...c, text: t });
    } else {
      out.push(c);
    }
  }
  out.push({
    type: 'text',
    text: JSON.stringify({
      truncated: true,
      total_chars: total,
      hint: 'dùng per_page/page/fields/offset hoặc tool *_get để lấy phần nhỏ hơn',
    }),
  });
  return { ...res, content: out };
}

function wrapHandler(handler) {
  return async (...a) => {
    try {
      return limitOutput(await handler(...a));
    } catch (e) {
      const payload = { error: e?.message || String(e) };
      if (e?.code) payload.code = e.code;
      if (e?.status) payload.status = e.status;
      if (e?.hint) payload.hint = e.hint;
      return { isError: true, content: [{ type: 'text', text: JSON.stringify(payload) }] };
    }
  };
}

// ── Dynamic Tool Catalog ─────────────────────────────────────────────────────
export const TOOL_CATALOG = [];
export const TOOL_MAP = new Map();

export function registerCatalogTool(...args) {
  const name = args[0];
  let description = '';
  let schema = {};
  let handler = args[args.length - 1];

  if (args.length === 4) {
    description = args[1];
    schema = args[2] || {};
  } else if (args.length === 3) {
    if (typeof args[1] === 'string') description = args[1];
    else schema = args[1] || {};
  }

  const entry = {
    name,
    group: groupOf(name),
    description,
    schema,
    handler,
    wrapped: wrapHandler(handler),
  };
  TOOL_CATALOG.push(entry);
  TOOL_MAP.set(name, entry);
  return entry;
}

function compactSchemaForTool(schema) {
  if (!schema || typeof schema !== 'object' || Object.keys(schema).length === 0) return {};
  try {
    const js = z.toJSONSchema(z.object(schema));
    delete js.$schema;
    delete js.additionalProperties;
    return js;
  } catch {
    return Object.fromEntries(Object.keys(schema).map((k) => [k, 'any']));
  }
}

/**
 * Create a configured McpServer instance from TOOL_CATALOG.
 * Supports `mode: 'full' | 'compact'` and `tools: string[]` group filter.
 */
export function createMcpServer(opts = {}) {
  const env = getEnvConfig();
  const mode = (opts.mode || env.mode || 'full').toLowerCase();
  const enabled = new Set(opts.tools?.length ? opts.tools : env.tools);
  const srv = new McpServer({ name: 'xclipse', version: VERSION });
  const stats = { registered: 0, total: TOOL_CATALOG.length, mode };

  for (const t of TOOL_CATALOG) {
    if (mode === 'compact') {
      if (!COMPACT_CORE_TOOLS.has(t.name)) continue;
      if (enabled.size && t.group !== 'core' && !enabled.has(t.group)) continue;
    } else {
      if (enabled.size && t.group !== 'core' && !enabled.has(t.group)) continue;
    }
    srv.tool(t.name, t.description, t.schema, t.wrapped);
    stats.registered++;
  }

  // Always register smart meta-tools for discovery & dynamic execution
  srv.tool(
    'xclipse_discover',
    `Tìm kiếm trong ${TOOL_CATALOG.length} tools Xclipse WordPress theo từ khóa hoặc nhóm (${ALL_GROUPS.join(', ')}). Dùng cùng xclipse_call để chạy bất kỳ tool nào mà không tốn token tải toàn bộ schema.`,
    {
      query: z.string().default('').describe('Từ khóa tên hoặc mô tả tool (vd: order, coupon, cf7, elementor, rankmath)'),
      group: z.string().default('').describe(`Lọc theo nhóm: ${ALL_GROUPS.join('|')}`),
      with_schema: z.boolean().default(true).describe('Kèm JSON schema tham số'),
      limit: z.number().default(15).describe('Số lượng kết quả tối đa (mặc định 15, tối đa 50)'),
    },
    wrapHandler(async ({ query, group, with_schema, limit }) => {
      const q = String(query || '').trim().toLowerCase();
      const g = String(group || '').trim().toLowerCase();
      const max = Math.min(Math.max(1, limit || 15), 50);
      const matches = [];
      for (const t of TOOL_CATALOG) {
        if (g && t.group !== g) continue;
        if (q && !t.name.toLowerCase().includes(q) && !t.description.toLowerCase().includes(q)) continue;
        const item = { name: t.name, group: t.group, desc: t.description };
        if (with_schema) item.params = compactSchemaForTool(t.schema);
        matches.push(item);
        if (matches.length >= max) break;
      }
      return j({ total_catalog: TOOL_CATALOG.length, count: matches.length, tools: matches });
    })
  );
  stats.registered++;

  srv.tool(
    'xclipse_call',
    'Gọi bất kỳ tool nào trong hệ thống Xclipse (kể cả khi đang ở compact mode). Dùng xclipse_discover trước để xem tên và tham số.',
    {
      tool: z.string().describe('Tên tool cần gọi, vd: coupons_list'),
      args: z.record(z.any()).default({}).describe('Object tham số truyền cho tool'),
    },
    wrapHandler(async ({ tool, args }) => {
      const entry = TOOL_MAP.get(tool);
      if (!entry) {
        const err = new Error(`Không tìm thấy tool "${tool}". Dùng xclipse_discover để tìm tên chính xác.`);
        err.code = 'TOOL_NOT_FOUND';
        throw err;
      }
      let parsedArgs = args || {};
      if (entry.schema && Object.keys(entry.schema).length > 0) {
        parsedArgs = z.object(entry.schema).parse(parsedArgs);
      }
      return await entry.wrapped(parsedArgs);
    })
  );
  stats.registered++;

  return { server: srv, stats };
}
