// Smoke test for Lite / Core Edition
import http from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const srv = http.createServer((req, res) => {
  res.writeHead(200, { 'content-type': 'application/json' });
  res.end(JSON.stringify({ ok: true, mock: true, path: req.url, items: [], empty_str: '', nul: null }));
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

let client;
try {
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: ['src/index.js'],
    cwd: root,
    stderr: 'pipe',
    env: { ...process.env, XCLIPSE_URL: `http://127.0.0.1:${port}`, XCLIPSE_API_KEY: 'test' },
  });
  client = new Client({ name: 'smoke-lite', version: '1.0.0' });
  await client.connect(transport);
  const { tools } = await client.listTools();
  await step('Lite listTools >= 18', async () => {
    if (tools.length < 18) throw new Error('only ' + tools.length);
    return tools.length + ' tools';
  });
  await step('call site_info & posts_list', async () => {
    const r1 = await client.callTool({ name: 'site_info', arguments: {} });
    const r2 = await client.callTool({ name: 'posts_list', arguments: {} });
    if (r1.isError || r2.isError) throw new Error('tool error');
  });
} finally {
  try { await client?.close(); } catch {}
}

let httpProc;
let httpClient;
try {
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
  });

  await step('HTTP GET /health', async () => {
    const r = await fetch(`http://127.0.0.1:${httpPort}/health`);
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error('health failed');
    return `catalog=${j.catalog_tools}`;
  });

  await step('HTTP Streamable MCP site_info', async () => {
    const transport = new StreamableHTTPClientTransport(
      new URL(`http://127.0.0.1:${httpPort}/mcp`),
      { requestInit: { headers: { Authorization: 'Bearer smoke-secret' } } }
    );
    httpClient = new Client({ name: 'smoke-lite-http', version: '1.0.0' });
    await httpClient.connect(transport);
    const r = await httpClient.callTool({ name: 'site_info', arguments: {} });
    if (r.isError || !text(r)) throw new Error('http site_info failed');
  });
} finally {
  try { await httpClient?.close(); } catch {}
  try { httpProc?.kill('SIGTERM'); } catch {}
  srv.close();
}

for (const r of results) console.log(`${r.pass ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  - ' + r.note : ''}`);
const failed = results.filter((r) => !r.pass).length;
console.log(`\n${results.length - failed}/${results.length} pass`);
process.exit(failed ? 1 : 0);
