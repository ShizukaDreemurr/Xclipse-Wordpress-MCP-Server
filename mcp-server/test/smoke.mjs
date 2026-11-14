// Smoke test: server giả + spawn index.js qua stdio. Không cần site thật.
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
let mode = 'json';
const srv = http.createServer((req, res) => {
  if (mode === '502') {
    res.writeHead(502, { 'content-type': 'text/html' });
    return res.end('<html><body><h1>502 Bad Gateway</h1></body></html>');
  }
  res.writeHead(200, { 'content-type': 'application/json' });
  res.end(JSON.stringify({ ok: true, mock: true, path: req.url, items: [], posts: [], total: 0 }));
});
await new Promise(r => srv.listen(0, '127.0.0.1', r));
const port = srv.address().port;

const results = [];
const rec = (name, pass, note = '') => results.push({ name, pass, note });
const text = r => (r?.content || []).map(c => c.text || '').join('');

async function connect(extraEnv = {}) {
  const transport = new StdioClientTransport({
    command: process.execPath, args: ['src/index.js'], cwd: root, stderr: 'pipe',
    env: { ...process.env, XCLIPSE_URL: `http://127.0.0.1:${port}`, XCLIPSE_API_KEY: 'test', ...extraEnv },
  });
  const client = new Client({ name: 'smoke', version: '1.0.0' });
  await client.connect(transport);
  return client;
}
const step = async (name, fn) => { try { const n = await fn(); rec(name, true, n || ''); } catch (e) { rec(name, false, String(e.message || e).slice(0, 120)); } };

let client;
try {
  client = await connect();
  const { tools } = await client.listTools();
  await step('listTools >= 380', async () => { if (tools.length < 380) throw new Error('chi co ' + tools.length); return tools.length + ' tools'; });
  await step('ten tool khong trung', async () => { const d = tools.length - new Set(tools.map(t => t.name)).size; if (d) throw new Error(d + ' trung'); });
  await step('moi tool co inputSchema', async () => { const b = tools.filter(t => !t.inputSchema || t.inputSchema.type !== 'object'); if (b.length) throw new Error(b.slice(0, 3).map(t => t.name).join(',')); });
  for (const n of ['site_info', 'posts_list', 'wpms_status'])
    await step('goi ' + n, async () => {
      if (!tools.find(t => t.name === n)) throw new Error('tool chua co (cho index.js moi?)');
      const r = await client.callTool({ name: n, arguments: {} });
      if (r.isError || !text(r)) throw new Error('isError/rong: ' + text(r).slice(0, 80));
    });
  await step('HTML 502 -> isError, client song', async () => {
    mode = '502';
    const r = await client.callTool({ name: 'site_info', arguments: {} });
    mode = 'json';
    if (!r.isError) throw new Error('khong tra isError (cho index.js moi: boc loi?)');
    const r2 = await client.callTool({ name: 'site_info', arguments: {} });
    if (r2.isError) throw new Error('client khong hoi phuc');
  });
} catch (e) {
  rec('khoi dong/ket noi', false, String(e.message || e).slice(0, 120));
} finally {
  try { await client?.close(); } catch {}
  srv.close();
}

console.log('\nKET QUA SMOKE TEST');
for (const r of results) console.log(`${r.pass ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  - ' + r.note : ''}`);
const failed = results.filter(r => !r.pass).length;
console.log(`\n${results.length - failed}/${results.length} pass`);
process.exit(failed ? 1 : 0);
