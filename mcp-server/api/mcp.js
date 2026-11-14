process.env.MCP_NO_AUTOSTART = '1';
if (!process.env.XCLIPSE_TOOL_MODE) {
  process.env.XCLIPSE_TOOL_MODE = 'compact';
}

await import('../src/index.js');
const { handleHttpRequest } = await import('../src/http.js');

export default async function handler(req, res) {
  return handleHttpRequest(req, res, req.body);
}
