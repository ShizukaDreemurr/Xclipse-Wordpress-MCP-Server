// Smoke test: server giả + spawn index.js qua stdio & Streamable HTTP URL. Không cần site thật.
import http from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
let mode = 'json';
const srv = http.createServer((req, res) => {
  if (mode === '502') {
    res.writeHead(502, { 'content-type': 'text/html' });
    return res.end('<html><body><h1>502 Bad Gateway</h1></body></html>');
  }
  res.writeHead(200, { 'content-type': 'application/json' });
  res.end(JSON.stringify({ ok: true, mock: true, path: req.url, items: [], posts: [], total: 0, empty_str: '', nul: null }));
});
await new Promise((r) => srv.listen(0, '127.0.0.1', r));
const port = srv.address().port;

const results = [];
const rec = (name, pass, note = '') => results.push({ name, pass, note });
const text = (r) => (r?.content || []).map((c) => c.text || '').join('');
const step = async (name, fn) => {
  try {
    const n = await fn();
    rec(name, true, n || '');
  } catch (e) {
    rec(name, false, String(e.message || e).slice(0, 140));
  }
};

async function connectStdio(extraEnv = {}) {
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: ['src/index.js'],
    cwd: root,
    stderr: 'pipe',
    env: { ...process.env, XCLIPSE_URL: `http://127.0.0.1:${port}`, XCLIPSE_API_KEY: 'test', ...extraEnv },
  });
  const client = new Client({ name: 'smoke', version: '1.0.0' });
  await client.connect(transport);
  return client;
}

// 1. Stdio tests (full mode)
let client;
try {
  client = await connectStdio();
  const { tools } = await client.listTools();
  await step('listTools >= 380', async () => {
    if (tools.length < 380) throw new Error('chi co ' + tools.length);
    return tools.length + ' tools';
  });
  await step('ten tool khong trung', async () => {
    const d = tools.length - new Set(tools.map((t) => t.name)).size;
    if (d) throw new Error(d + ' trung');
  });
  await step('moi tool co inputSchema', async () => {
    const b = tools.filter((t) => !t.inputSchema || t.inputSchema.type !== 'object');
    if (b.length) throw new Error(b.slice(0, 3).map((t) => t.name).join(','));
  });
  for (const n of ['site_info', 'posts_list', 'wpms_status']) {
    await step('goi ' + n, async () => {
      if (!tools.find((t) => t.name === n)) throw new Error('tool chua co');
      const r = await client.callTool({ name: n, arguments: {} });
      if (r.isError || !text(r)) throw new Error('isError/rong: ' + text(r).slice(0, 80));
    });
  }
  await step('response compaction bo null/empty', async () => {
    const r = await client.callTool({ name: 'site_info', arguments: {} });
    const parsed = JSON.parse(text(r));
    if ('nul' in parsed || 'empty_str' in parsed || 'items' in parsed) {
      throw new Error('chua compact: ' + text(r));
    }
  });
  await step('xclipse_discover + xclipse_call', async () => {
    const d = await client.callTool({ name: 'xclipse_discover', arguments: { query: 'coupons_list', limit: 5 } });
    const dj = JSON.parse(text(d));
    if (!dj.tools?.some((t) => t.name === 'coupons_list')) throw new Error('discover khong thay coupons_list');
    const c = await client.callTool({ name: 'xclipse_call', arguments: { tool: 'coupons_list', args: {} } });
    if (c.isError) throw new Error('xclipse_call loi: ' + text(c));
  });
  await step('HTML 502 -> isError, client song', async () => {
    mode = '502';
    const r = await client.callTool({ name: 'site_info', arguments: {} });
    mode = 'json';
    if (!r.isError) throw new Error('khong tra isError');
    const r2 = await client.callTool({ name: 'site_info', arguments: {} });
    if (r2.isError) throw new Error('client khong hoi phuc');
  });
} catch (e) {
  rec('stdio khoi dong/ket noi', false, String(e.message || e).slice(0, 140));
} finally {
  try { await client?.close(); } catch {}
}

// 2. Streamable HTTP URL tests (compact mode + auth)
let httpProc;
let httpClient;
try {
  // Find a free port
  const tmpSrv = http.createServer();
  await new Promise((r) => tmpSrv.listen(0, '127.0.0.1', r));
  const httpPort = tmpSrv.address().port;
  await new Promise((r) => tmpSrv.close(r));

  httpProc = spawn(process.execPath, ['src/index.js', '--http'], {
    cwd: root,
    env: {
      ...process.env,
      PORT: String(httpPort),
      HOST: '127.0.0.1',
      MCP_AUTH_TOKEN: 'smoke-secret',
      XCLIPSE_URL: `http://127.0.0.1:${port}`,
      XCLIPSE_API_KEY: 'test',
      XCLIPSE_TOOL_MODE: 'compact',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  await new Promise((resolveReady, rejectReady) => {
    const t = setTimeout(() => rejectReady(new Error('HTTP server timeout')), 5000);
    httpProc.stderr.on('data', (chunk) => {
      if (String(chunk).includes('HTTP MCP listening')) {
        clearTimeout(t);
        resolveReady();
      }
    });
    httpProc.on('exit', (code) => {
      clearTimeout(t);
      rejectReady(new Error('HTTP server exited ' + code));
    });
  });

  await step('HTTP GET /health', async () => {
    const r = await fetch(`http://127.0.0.1:${httpPort}/health`);
    const j = await r.json();
    if (!r.ok || !j.ok || j.transport !== 'streamable-http') throw new Error('health sai');
    return `catalog=${j.catalog_tools}`;
  });

  await step('HTTP /mcp chan khi thieu token (401)', async () => {
    const r = await fetch(`http://127.0.0.1:${httpPort}/mcp`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize' }),
    });
    if (r.status !== 401) throw new Error('expected 401 got ' + r.status);
  });

  await step('HTTP Streamable MCP compact mode + xclipse_call', async () => {
    const transport = new StreamableHTTPClientTransport(
      new URL(`http://127.0.0.1:${httpPort}/mcp`),
      { requestInit: { headers: { Authorization: 'Bearer smoke-secret' } } }
    );
    httpClient = new Client({ name: 'smoke-http', version: '1.0.0' });
    await httpClient.connect(transport);
    const { tools } = await httpClient.listTools();
    if (tools.length > 40 || tools.length < 20) throw new Error('compact tools count bat thuong: ' + tools.length);
    const r = await httpClient.callTool({ name: 'xclipse_call', arguments: { tool: 'coupons_list', args: {} } });
    if (r.isError) throw new Error('http xclipse_call loi: ' + text(r));
    return `${tools.length} compact tools`;
  });
} catch (e) {
  rec('HTTP MCP server', false, String(e.message || e).slice(0, 140));
} finally {
  try { await httpClient?.close(); } catch {}
  try { httpProc?.kill('SIGTERM'); } catch {}
  srv.close();
}

console.log('\nKET QUA SMOKE TEST');
for (const r of results) console.log(`${r.pass ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  - ' + r.note : ''}`);
const failed = results.filter((r) => !r.pass).length;
console.log(`\n${results.length - failed}/${results.length} pass`);
process.exit(failed ? 1 : 0);
