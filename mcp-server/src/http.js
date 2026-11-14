/**
 * Xclipse Remote MCP Server (Streamable HTTP Transport)
 * Compatible with Gemini Spark, Claude Remote MCP, Cursor, MCP Inspector, etc.
 */
import http from 'node:http';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import {
  VERSION,
  ALL_GROUPS,
  TOOL_CATALOG,
  getEnvConfig,
  requestStore,
  createMcpServer,
} from './core.js';

const MAX_BODY_BYTES = 10 * 1024 * 1024; // 10MB (supports base64 media uploads)

function setCors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
  res.setHeader(
    'Access-Control-Allow-Headers',
    'Content-Type, Accept, Authorization, Mcp-Session-Id, Mcp-Protocol-Version, X-MCP-Auth, X-WP-URL, X-WP-Key, X-WP-Tools, X-Xclipse-URL, X-Xclipse-Key, X-Xclipse-Tools, X-Tool-Mode'
  );
  res.setHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id');
}

function sendJson(res, status, data) {
  setCors(res);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(data));
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(Object.assign(new Error('Payload too large'), { status: 413 }));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8').trim();
      if (!raw) return resolve(undefined);
      try {
        resolve(JSON.parse(raw));
      } catch {
        reject(Object.assign(new Error('Invalid JSON body'), { status: 400 }));
      }
    });
    req.on('error', reject);
  });
}

function checkAuth(req, urlObj) {
  const expected = process.env.MCP_AUTH_TOKEN || '';
  if (!expected) return true;
  const auth = req.headers['authorization'] || '';
  const bearer = auth.toLowerCase().startsWith('bearer ') ? auth.slice(7).trim() : '';
  const headerToken = req.headers['x-mcp-auth'] || '';
  const queryToken = urlObj.searchParams.get('token') || '';
  const provided = bearer || headerToken || queryToken;
  return provided === expected;
}

function resolveRequestContext(req, urlObj) {
  const env = getEnvConfig();
  const h = req.headers;
  const q = urlObj.searchParams;

  const base = String(
    h['x-wp-url'] || h['x-xclipse-url'] || q.get('wp_url') || env.base || ''
  ).replace(/\/$/, '');

  const key = String(
    h['x-wp-key'] || h['x-xclipse-key'] || q.get('wp_key') || env.key || ''
  );

  const toolsRaw =
    h['x-wp-tools'] || h['x-xclipse-tools'] || q.get('tools') || '';
  const tools = toolsRaw
    ? String(toolsRaw).split(',').map((s) => s.trim().toLowerCase()).filter(Boolean)
    : env.tools;

  const mode = String(
    h['x-tool-mode'] || q.get('mode') || env.mode || 'full'
  ).trim().toLowerCase();

  return {
    ...env,
    base,
    key,
    tools,
    mode,
  };
}

export function startHttpServer({ port = parseInt(process.env.PORT, 10) || 3000, host = process.env.HOST || '0.0.0.0' } = {}) {
  const startedAt = Date.now();

  const httpServer = http.createServer(async (req, res) => {
    setCors(res);

    if (req.method === 'OPTIONS') {
      res.writeHead(204);
      res.end();
      return;
    }

    const urlObj = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
    const pathname = urlObj.pathname.replace(/\/+$/, '') || '/';

    if (req.method === 'GET' && (pathname === '/' || pathname === '/health')) {
      const env = getEnvConfig();
      return sendJson(res, 200, {
        ok: true,
        name: 'xclipse-mcp',
        version: VERSION,
        transport: 'streamable-http',
        mode: env.mode,
        catalog_tools: TOOL_CATALOG.length,
        groups: ALL_GROUPS,
        configured_wp: Boolean(env.base && env.key),
        auth_required: Boolean(process.env.MCP_AUTH_TOKEN),
        endpoints: { mcp: '/mcp', health: '/health' },
        uptime_s: Math.floor((Date.now() - startedAt) / 1000),
      });
    }

    if (pathname !== '/mcp') {
      return sendJson(res, 404, { error: 'Not Found', endpoints: ['/mcp', '/health'] });
    }

    if (!checkAuth(req, urlObj)) {
      return sendJson(res, 401, {
        jsonrpc: '2.0',
        error: { code: -32001, message: 'Unauthorized: invalid or missing MCP_AUTH_TOKEN' },
        id: null,
      });
    }

    if (req.method === 'GET' || req.method === 'DELETE') {
      return sendJson(res, 405, {
        jsonrpc: '2.0',
        error: { code: -32000, message: 'Method not allowed in stateless Streamable HTTP mode. Use POST /mcp.' },
        id: null,
      });
    }

    if (req.method !== 'POST') {
      return sendJson(res, 405, { error: 'Method Not Allowed' });
    }

    let body;
    try {
      body = await readJsonBody(req);
    } catch (e) {
      return sendJson(res, e.status || 400, {
        jsonrpc: '2.0',
        error: { code: -32700, message: e.message || 'Invalid JSON' },
        id: null,
      });
    }

    const ctx = resolveRequestContext(req, urlObj);
    const { server } = createMcpServer({ mode: ctx.mode, tools: ctx.tools });
    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: undefined,
    });

    res.on('close', () => {
      try { transport.close(); } catch {}
      try { server.close(); } catch {}
    });

    try {
      await requestStore.run(ctx, async () => {
        await server.connect(transport);
        await transport.handleRequest(req, res, body);
      });
    } catch (e) {
      if (!res.headersSent) {
        sendJson(res, 500, {
          jsonrpc: '2.0',
          error: { code: -32603, message: e?.message || 'Internal server error' },
          id: null,
        });
      }
    }
  });

  return new Promise((resolve) => {
    httpServer.listen(port, host, () => {
      const addr = httpServer.address();
      const actualPort = typeof addr === 'object' && addr ? addr.port : port;
      const env = getEnvConfig();
      console.error(
        `[XCLIPSE v${VERSION}] HTTP MCP listening on http://${host}:${actualPort}/mcp | mode=${env.mode} | catalog=${TOOL_CATALOG.length} tools`
      );
      resolve({ httpServer, port: actualPort });
    });
  });
}
