/**
 * Xclipse WordPress MCP Server v5.0.0 (Lite / Core Open-Source Edition)
 * Full 390+ Tool Pro Suite (WooCommerce, Elementor, Flatsome, Rank Math SEO,
 * LiteSpeed Cache, CF7, WP Mail SMTP, Batch & Audit): https://agensi.io/creators/xclipse-x
 */
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import {
  VERSION,
  getEnvConfig,
  api,
  j,
  ok,
  Pager,
  qs,
  registerCatalogTool,
  createMcpServer,
} from './core.js';
import { startHttpServer } from './http.js';

const server = { tool: registerCatalogTool };

// INFO
server.tool('site_info', 'WordPress site info: version, active theme, plugins.', {}, async () => j(await api('/info')));

// POSTS
server.tool('posts_list', 'List WordPress posts with pagination and search.', {
  ...Pager,
  search: z.string().default(''),
  status: z.enum(['publish', 'draft', 'pending', 'private', 'any']).default('publish'),
  cat: z.number().default(0),
  orderby: z.enum(['date', 'title', 'modified']).default('date'),
  order: z.enum(['ASC', 'DESC']).default('DESC'),
  fields: z.string().default(''),
}, async (p) => j(await api(`/posts?${qs(p)}`)));

server.tool('post_get', 'Get full post details by ID.', { id: z.number() }, async ({ id }) => j(await api(`/posts/${id}`)));

server.tool('post_create', 'Create a new WordPress post.', {
  title: z.string(),
  content: z.string().describe('HTML content'),
  status: z.enum(['draft', 'publish', 'pending']).default('draft'),
  excerpt: z.string().default(''),
  slug: z.string().default(''),
  categories: z.array(z.number()).default([]),
  tags: z.array(z.string()).default([]),
}, async (b) => j(await api('/posts', 'POST', b)));

server.tool('post_update', 'Update an existing WordPress post.', {
  id: z.number(),
  title: z.string().optional(),
  content: z.string().optional(),
  excerpt: z.string().optional(),
  status: z.enum(['draft', 'publish', 'pending', 'private']).optional(),
  slug: z.string().optional(),
  categories: z.array(z.number()).optional(),
  tags: z.array(z.string()).optional(),
}, async ({ id, ...b }) => ok(`Updated post ${id}`, await api(`/posts/${id}`, 'PUT', b)));

server.tool('post_delete', 'Move a WordPress post to trash.', { id: z.number() },
  async ({ id }) => ok(`Deleted post ${id}`, await api(`/posts/${id}`, 'DELETE')));

// PAGES
server.tool('pages_list', 'List WordPress pages.', {
  per_page: z.number().default(30),
  search: z.string().default(''),
  slug: z.string().default(''),
  status: z.enum(['publish', 'draft', 'private', 'any']).default('publish'),
  fields: z.string().default(''),
}, async (p) => j(await api(`/pages?${qs(p)}`)));

server.tool('page_get', 'Get page details by ID.', { id: z.number() }, async ({ id }) => j(await api(`/pages/${id}`)));

server.tool('page_create', 'Create a new WordPress page.', {
  title: z.string(),
  content: z.string().optional(),
  excerpt: z.string().optional(),
  status: z.enum(['publish', 'draft', 'private', 'pending']).default('draft'),
  slug: z.string().optional(),
  parent: z.number().optional(),
}, async (b) => j(await api('/pages', 'POST', b)));

server.tool('page_update', 'Update an existing WordPress page.', {
  id: z.number(),
  title: z.string().optional(),
  content: z.string().optional(),
  excerpt: z.string().optional(),
  status: z.enum(['publish', 'draft', 'private', 'pending']).optional(),
  slug: z.string().optional(),
  parent: z.number().optional(),
}, async ({ id, ...b }) => j(await api(`/pages/${id}`, 'PUT', b)));

server.tool('page_delete', 'Delete a WordPress page.', {
  id: z.number(),
  force: z.boolean().optional(),
}, async ({ id, force }) => j(await api(`/pages/${id}?force=${force ?? false}`, 'DELETE')));

// MEDIA
server.tool('media_list', 'List media library items.', {
  ...Pager,
  search: z.string().default(''),
  mime: z.string().default('image'),
}, async (p) => j(await api(`/media?${new URLSearchParams(p)}`)));

server.tool('media_get', 'Get media item details.', { id: z.number() }, async ({ id }) => j(await api(`/media/${id}`)));

server.tool('media_upload', 'Upload an image from URL to the media library.', {
  url: z.string(),
  title: z.string().optional(),
  alt: z.string().optional(),
  post_id: z.number().default(0),
}, async (b) => j(await api('/media', 'POST', b)));

// TAXONOMY
server.tool('get_categories', 'List post categories.', {}, async () => j(await api('/categories')));
server.tool('get_tags', 'List top 100 post tags.', {}, async () => j(await api('/tags')));

// COMMENTS
server.tool('comments_list', 'List comments.', {
  ...Pager,
  status: z.enum(['hold', 'approve', 'spam', 'trash', 'all']).default('hold'),
  post_id: z.number().default(0),
}, async (p) => j(await api(`/comments?${new URLSearchParams(p)}`)));

server.tool('comment_update', 'Approve, mark spam/trash, or edit a comment.', {
  id: z.number(),
  status: z.enum(['approved', 'pending', 'spam', 'trash']).optional(),
  content: z.string().optional(),
}, async ({ id, ...b }) => ok(`Comment ${id}`, await api(`/comments/${id}`, 'PUT', b)));

const noAutoStart = process.env.MCP_NO_AUTOSTART === '1' || process.env.VERCEL === '1';

const isHttp =
  process.argv.includes('--http') ||
  /^(http|streamable-http)$/i.test(process.env.MCP_TRANSPORT || '') ||
  Boolean(process.env.PORT);

if (noAutoStart) {
  // Imported as library (e.g., Vercel serverless handler)
} else if (isHttp) {
  await startHttpServer();
} else {
  const env = getEnvConfig();
  if (!env.base || !env.key) {
    console.error('[XCLIPSE] Missing XCLIPSE_URL or XCLIPSE_API_KEY');
    process.exit(1);
  }
  const { server: mcpServer, stats } = createMcpServer();
  const transport = new StdioServerTransport();
  await mcpServer.connect(transport);
  console.error(`[XCLIPSE LITE v${VERSION}] OK ${env.base} | mode=${stats.mode} | tools ${stats.registered}/${stats.total}`);
}
