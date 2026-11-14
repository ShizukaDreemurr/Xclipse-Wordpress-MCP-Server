/**
 * Xclipse WordPress MCP Server v5.0.0
 * - Dual transport: Stdio (local) & Streamable HTTP URL (Gemini Spark, Claude, Cursor, Cloud)
 * - Token optimization: compact JSON, response compaction, compact gateway mode (xclipse_discover + xclipse_call)
 * - Reliability: TTL GET cache, retry with backoff/429, structured error classification
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
  Status,
  qs,
  registerCatalogTool,
  createMcpServer,
} from './core.js';
import { startHttpServer } from './http.js';

const server = { tool: registerCatalogTool };

// INFO
server.tool('site_info', 'Thông tin website: WP version, WooCommerce, RankMath, Burst, theme.', {}, async () => j(await api('/info')));

// POSTS
server.tool('posts_list', 'Danh sách bài viết. Trả về id, title, slug, created_at, modified_at, url, cats, author (id/name/login/email/role). Lọc theo author_id để xem ai viết bài nào.', {
  ...Pager,
  search:    z.string().default(''),
  status:    z.enum(['publish','draft','pending','private','any']).default('publish'),
  cat:       z.number().default(0).describe('ID danh mục'),
  orderby:   z.enum(['date','title','modified']).default('date'),
  order:     z.enum(['ASC','DESC']).default('DESC'),
  author_id: z.number().optional().describe('Lọc bài theo ID tác giả'),
  fields:    z.string().default('').describe('Chỉ trả các field này, vd: id,title,url'),
}, async (p) => j(await api(`/posts?${qs(p)}`)));

server.tool('post_get', 'Chi tiết bài viết: content, SEO, cats, tags.', {
  id: z.number(),
}, async ({ id }) => j(await api(`/posts/${id}`)));

server.tool('post_create', 'Tạo bài viết. Trả về {id,url,status}.', {
  title:     z.string(),
  content:   z.string().describe('HTML'),
  status:    z.enum(['draft','publish','pending']).default('draft'),
  excerpt:   z.string().default(''),
  slug:      z.string().default(''),
  categories:z.array(z.number()).default([]),
  tags:      z.array(z.string()).default([]),
  seo_title: z.string().default(''),
  seo_desc:  z.string().default(''),
  focus_kw:  z.string().default(''),
}, async (b) => j(await api('/posts', 'POST', b)));

server.tool('post_update', 'Cập nhật bài viết. Chỉ truyền fields cần thay đổi.', {
  id:        z.number(),
  title:     z.string().optional(),
  content:   z.string().optional(),
  excerpt:   z.string().optional(),
  status:    z.enum(['draft','publish','pending','private']).optional(),
  slug:      z.string().optional(),
  categories:z.array(z.number()).optional(),
  tags:      z.array(z.string()).optional(),
  seo_title: z.string().optional(),
  seo_desc:  z.string().optional(),
  focus_kw:  z.string().optional(),
}, async ({ id, ...b }) => ok(`Đã cập nhật bài ${id}`, await api(`/posts/${id}`, 'PUT', b)));

server.tool('post_delete', 'Chuyển bài viết vào trash.', { id: z.number() },
  async ({ id }) => ok(`Đã xoá bài ${id}`, await api(`/posts/${id}`, 'DELETE')));

// PAGES
server.tool('pages_list', 'Danh sách trang tĩnh. Trả về id, title, slug, created_at, modified_at, url, parent, author (id/name/login/email/role). Lọc theo author_id để xem ai tạo trang.', {
  per_page:  z.number().default(30),
  search:    z.string().default('').describe('Tìm theo tên trang'),
  slug:      z.string().default('').describe('Tìm chính xác theo slug'),
  status:    z.enum(['publish','draft','private','any']).default('publish'),
  orderby:   z.enum(['post_date','post_title','post_modified','menu_order']).default('post_date'),
  order:     z.enum(['ASC','DESC']).default('DESC'),
  author_id: z.number().optional().describe('Lọc trang theo ID tác giả'),
  fields:    z.string().default('').describe('Chỉ trả các field này, vd: id,title,url'),
}, async (p) => j(await api(`/pages?${qs(p)}`)));

server.tool('page_get',
  'Chi tiết trang tĩnh: nội dung, slug, status, parent, template, SEO, featured image, tác giả, ngày tạo/sửa.',
  { id: z.number() },
  async ({ id }) => j(await api(`/pages/${id}`)));

server.tool('page_create',
  'Tạo trang tĩnh mới. Trả về {id, url, status}.',
  {
    title:        z.string().describe('Tiêu đề trang'),
    content:      z.string().optional().describe('Nội dung HTML'),
    excerpt:      z.string().optional().describe('Mô tả ngắn'),
    status:       z.enum(['publish','draft','private','pending']).default('draft'),
    slug:         z.string().optional().describe('Slug URL (bỏ trống = tự sinh từ title)'),
    parent:       z.number().optional().describe('ID trang cha (0 = trang gốc)'),
    order:        z.number().optional().describe('Thứ tự hiển thị (menu_order)'),
    template:     z.string().optional().describe('Tên file template, vd: full-width.php, hoặc "default"'),
    thumbnail_id: z.number().optional().describe('Attachment ID ảnh đại diện'),
    author_id:    z.number().optional().describe('ID tác giả'),
    seo_title:    z.string().optional(),
    seo_desc:     z.string().optional(),
    focus_kw:     z.string().optional(),
  },
  async (b) => j(await api('/pages', 'POST', b)));

server.tool('page_update',
  'Cập nhật trang tĩnh. Chỉ truyền fields cần thay đổi.',
  {
    id:           z.number(),
    title:        z.string().optional(),
    content:      z.string().optional().describe('Nội dung HTML'),
    excerpt:      z.string().optional(),
    status:       z.enum(['publish','draft','private','pending']).optional(),
    slug:         z.string().optional(),
    parent:       z.number().optional().describe('ID trang cha, 0 = không có cha'),
    order:        z.number().optional().describe('menu_order — thứ tự hiển thị'),
    template:     z.string().optional().describe('File template, vd: full-width.php hoặc "default"'),
    thumbnail_id: z.number().optional().describe('Attachment ID ảnh, 0 = xóa ảnh'),
    author_id:    z.number().optional(),
    seo_title:    z.string().optional(),
    seo_desc:     z.string().optional(),
    focus_kw:     z.string().optional(),
    canonical:    z.string().optional(),
  },
  async ({ id, ...b }) => j(await api(`/pages/${id}`, 'PUT', b)));

server.tool('page_delete',
  'Xóa trang tĩnh. force=false (mặc định): chuyển vào trash. force=true: xóa vĩnh viễn.',
  {
    id:    z.number(),
    force: z.boolean().optional().describe('true = xóa vĩnh viễn, false (mặc định) = trash'),
  },
  async ({ id, force }) => j(await api(`/pages/${id}?force=${force ?? false}`, 'DELETE')));

server.tool('pages_bulk_update',
  'Cập nhật hàng loạt 1 field cho nhiều trang cùng lúc. field: status|author_id|parent|template.',
  {
    ids:   z.array(z.number()).describe('Danh sách page IDs'),
    field: z.enum(['status','author_id','parent','template']).describe('Trường cần cập nhật'),
    value: z.union([z.string(), z.number()]).describe('Giá trị mới áp cho tất cả'),
  },
  async (b) => j(await api('/pages/bulk', 'POST', b)));

// PRODUCTS
server.tool('products_list', 'Danh sách sản phẩm WooCommerce. Trả về id, name, sku, price, stock, created_at, modified_at, author (id/name/login/email/role). Lọc theo author_id để xem ai đăng sản phẩm nào.', {
  ...Pager,
  search:    z.string().default(''),
  category:  z.string().default('').describe('Slug danh mục'),
  status:    z.enum(['publish','draft','any']).default('publish'),
  orderby:   z.enum(['date','title','modified','price','id']).default('date'),
  order:     z.enum(['ASC','DESC']).default('DESC'),
  author_id: z.number().optional().describe('Lọc sản phẩm theo ID người tạo'),
  fields:    z.string().default('').describe('Chỉ trả các field này, vd: id,name,price'),
}, async (p) => j(await api(`/products?${qs(p)}`)));

server.tool('product_get', 'Chi tiết sản phẩm: giá, tồn kho, mô tả, SEO.', { id: z.number() },
  async ({ id }) => j(await api(`/products/${id}`)));

server.tool('product_create', 'Tạo sản phẩm WooCommerce mới.', {
  name:              z.string(),
  regular_price:     z.string().describe('VD: "8500000"'),
  sale_price:        z.string().default(''),
  stock_quantity:    z.number().default(0),
  description:       z.string().default(''),
  short_description: z.string().default(''),
  sku:               z.string().default(''),
  status:            z.enum(['publish','draft']).default('draft'),
}, async (b) => j(await api('/products', 'POST', b)));

server.tool('product_update', 'Cập nhật sản phẩm: giá/tồn kho/mô tả. Chỉ truyền fields thay đổi.', {
  id:                z.number(),
  name:              z.string().optional(),
  regular_price:     z.string().optional(),
  sale_price:        z.string().optional(),
  stock_quantity:    z.number().optional(),
  stock_status:      z.enum(['instock','outofstock','onbackorder']).optional(),
  description:       z.string().optional(),
  short_description: z.string().optional(),
  status:            z.enum(['publish','draft','private']).optional(),
}, async ({ id, ...b }) => j(await api(`/products/${id}`, 'PUT', b)));

// ORDERS
server.tool('orders_list', 'Danh sách đơn hàng. Lọc status/ngày.', {
  ...Pager,
  status: z.enum(['any','pending','processing','on-hold','completed','cancelled','refunded']).default('any'),
  after:  z.string().default('').describe('YYYY-MM-DD'),
  before: z.string().default('').describe('YYYY-MM-DD'),
}, async (p) => j(await api(`/orders?${new URLSearchParams(p)}`)));

server.tool('order_create',
  'Tạo đơn hàng WooCommerce mới (manual order). Truyền billing info, danh sách sản phẩm, payment method. Shipping tự copy từ billing nếu bỏ trống.',
  {
    customer_id:          z.number().default(0).describe('User ID (0 = guest)'),
    status:               z.enum(['pending','processing','on-hold','completed']).default('pending'),
    billing: z.object({
      first_name: z.string().optional(),
      last_name:  z.string().optional(),
      email:      z.string().optional(),
      phone:      z.string().optional(),
      address_1:  z.string().optional(),
      address_2:  z.string().optional(),
      city:       z.string().optional(),
      state:      z.string().optional(),
      postcode:   z.string().optional(),
      country:    z.string().optional().describe('Mã quốc gia — VD: VN, US'),
      company:    z.string().optional(),
    }).optional().describe('Địa chỉ thanh toán'),
    shipping: z.object({
      first_name: z.string().optional(),
      last_name:  z.string().optional(),
      address_1:  z.string().optional(),
      city:       z.string().optional(),
      country:    z.string().optional(),
    }).optional().describe('Địa chỉ giao hàng (bỏ trống = copy từ billing)'),
    items: z.array(z.object({
      product_id:   z.number().describe('ID sản phẩm'),
      qty:          z.number().default(1),
      variation_id: z.number().optional().describe('ID biến thể (nếu là variable product)'),
      price:        z.number().optional().describe('Override giá (mặc định = giá sản phẩm)'),
    })).optional().describe('Danh sách sản phẩm trong đơn'),
    payment_method:       z.string().optional().describe('VD: cod, bacs, paypal, momo'),
    payment_method_title: z.string().optional().describe('Tên hiển thị — VD: "Thanh toán khi nhận hàng"'),
    coupon_codes:         z.array(z.string()).optional().describe('Mã giảm giá áp dụng'),
    note:                 z.string().optional().describe('Ghi chú nội bộ (admin thấy)'),
    customer_note:        z.string().optional().describe('Ghi chú của khách hàng'),
    created_via:          z.string().optional().describe('Nguồn tạo đơn — mặc định: mcp'),
  },
  async (b) => j(await api('/orders', 'POST', b)));

server.tool('order_get', 'Chi tiết đơn hàng: items, địa chỉ, ghi chú.', { id: z.number() },
  async ({ id }) => j(await api(`/orders/${id}`)));

server.tool('order_update', 'Cập nhật đơn hàng: trạng thái, ngày, coupon, thanh toán, billing, ghi chú.', {
  id:                   z.number().describe('Order ID'),
  status:               z.enum(['pending','processing','on-hold','completed','cancelled','refunded']).optional().describe('Trạng thái đơn'),
  note:                 z.string().optional().describe('Ghi chú nội bộ (admin)'),
  customer_note:        z.string().optional().describe('Ghi chú hiển thị cho khách'),
  date_created:         z.string().optional().describe('Ngày tạo đơn — ISO 8601 hoặc "Y-m-d H:i:s" VD: "2025-01-15 09:30:00"'),
  date_paid:            z.string().optional().describe('Ngày thanh toán — ISO 8601 hoặc "Y-m-d H:i:s"'),
  date_completed:       z.string().optional().describe('Ngày hoàn thành — ISO 8601 hoặc "Y-m-d H:i:s"'),
  payment_method:       z.string().optional().describe('Mã phương thức: cod, bacs, paypal, momo...'),
  payment_method_title: z.string().optional().describe('Tên hiển thị — VD: "Thanh toán khi nhận hàng"'),
  transaction_id:       z.string().optional().describe('Mã giao dịch từ cổng thanh toán'),
  coupon_add:    z.array(z.string()).optional().describe('Danh sách mã coupon cần thêm vào đơn'),
  coupon_remove: z.array(z.string()).optional().describe('Danh sách mã coupon cần xóa khỏi đơn'),
  created_via:   z.string().optional().describe('Nguồn gốc đơn hàng — VD: "checkout", "admin", "phone", "facebook", "zalo"'),
  billing: z.object({
    first_name: z.string().optional(),
    last_name:  z.string().optional(),
    email:      z.string().optional(),
    phone:      z.string().optional(),
    address_1:  z.string().optional(),
    city:       z.string().optional(),
    country:    z.string().optional(),
  }).optional().describe('Cập nhật thông tin billing'),
}, async ({ id, ...b }) => j(await api(`/orders/${id}`, 'PUT', b)));

server.tool('order_delete',
  'Xoá đơn hàng. force=false (mặc định) = trash. force=true = xóa vĩnh viễn.',
  {
    id:    z.number().describe('Order ID'),
    force: z.boolean().default(false).describe('true = xóa vĩnh viễn'),
  },
  async ({ id, force }) => j(await api(`/orders/${id}?force=${force}`, 'DELETE')));

server.tool('order_refund', 'Hoàn tiền đơn hàng.', {
  id:      z.number().describe('ID đơn hàng'),
  amount:  z.number().optional().describe('Số tiền hoàn (mặc định = toàn bộ)'),
  reason:  z.string().default(''),
  restock: z.boolean().default(true).describe('Hoàn tồn kho'),
}, async ({ id, ...b }) => j(await api(`/orders/${id}/refund`, 'POST', b)));

// CUSTOMERS
server.tool('customers_list', 'Danh sách khách hàng WooCommerce.', {
  ...Pager, search: z.string().default(''),
}, async (p) => j(await api(`/customers?${new URLSearchParams(p)}`)));

server.tool('customer_get', 'Chi tiết khách hàng: địa chỉ, số đơn, tổng chi tiêu.', { id: z.number() },
  async ({ id }) => j(await api(`/customers/${id}`)));

// COUPONS
server.tool('coupons_list', 'Danh sách mã giảm giá.', {
  ...Pager, search: z.string().default(''),
}, async (p) => j(await api(`/coupons?${new URLSearchParams(p)}`)));

server.tool('coupon_create', 'Tạo mã giảm giá mới.', {
  code:        z.string().describe('Mã giảm giá'),
  type:        z.enum(['percent','fixed_cart','fixed_product']).default('percent'),
  amount:      z.number().describe('Giá trị giảm'),
  usage_limit: z.number().optional().describe('Giới hạn lượt dùng'),
  expiry_date: z.string().optional().describe('YYYY-MM-DD'),
  min_amount:  z.string().optional().describe('Giá trị đơn hàng tối thiểu'),
  product_ids: z.array(z.number()).optional(),
}, async (b) => j(await api('/coupons', 'POST', b)));

server.tool('coupon_update', 'Cập nhật mã giảm giá.', {
  id:          z.number(),
  amount:      z.number().optional(),
  type:        z.enum(['percent','fixed_cart','fixed_product']).optional(),
  usage_limit: z.number().optional(),
  expiry_date: z.string().optional(),
}, async ({ id, ...b }) => ok(`Coupon ${id}`, await api(`/coupons/${id}`, 'PUT', b)));

server.tool('coupon_delete', 'Xoá mã giảm giá.', { id: z.number() },
  async ({ id }) => ok(`Xoá coupon ${id}`, await api(`/coupons/${id}`, 'DELETE')));

// PRODUCT CATEGORIES
server.tool('product_categories', 'Danh mục sản phẩm WooCommerce.', {},
  async () => j(await api('/product-categories')));

server.tool('product_category_create', 'Tạo danh mục sản phẩm mới.', {
  name:        z.string(),
  slug:        z.string().default(''),
  parent:      z.number().default(0),
  description: z.string().default(''),
}, async (b) => j(await api('/product-categories', 'POST', b)));

// STATS
server.tool('get_stats', 'Thống kê: bài viết, sản phẩm, đơn hàng, doanh thu, top sản phẩm.', {
  period: z.enum(['7d','30d','90d']).default('30d'),
}, async ({ period }) => j(await api(`/stats?period=${period}`)));

// MEDIA
server.tool('media_list', 'Thư viện hình ảnh (compact: id,title,url,date).', {
  ...Pager,
  search: z.string().default(''),
  mime:   z.string().default('image').describe('image | video | application/pdf'),
}, async (p) => j(await api(`/media?${new URLSearchParams(p)}`)));

server.tool('media_get', 'Chi tiết file media: kích thước, alt, caption.', { id: z.number() },
  async ({ id }) => j(await api(`/media/${id}`)));

server.tool('media_upload', 'Upload ảnh từ URL vào thư viện.', {
  url:     z.string().describe('URL ảnh'),
  title:   z.string().optional(),
  alt:     z.string().optional(),
  post_id: z.number().default(0),
}, async (b) => j(await api('/media', 'POST', b)));

server.tool('media_update', 'Cập nhật alt text, title, caption của ảnh.', {
  id: z.number(), title: z.string().optional(), alt: z.string().optional(),
  caption: z.string().optional(), description: z.string().optional(),
}, async ({ id, ...b }) => ok(`Media ${id}`, await api(`/media/${id}`, 'PUT', b)));

server.tool('media_delete', 'Xoá file media.', {
  id: z.number(), force: z.boolean().default(false),
}, async ({ id, force }) => ok(`Xoá media ${id}`, await api(`/media/${id}?force=${force}`, 'DELETE')));

// RANKMATH
server.tool('rankmath_overview',
  'Tổng quan RankMath: số bài chưa có focus keyword. Lọc theo post_type, min_score, max_score để tìm bài yếu.',
  {
    post_type: z.string().default('').describe('post|page|product — để trống = tất cả'),
    min_score: z.number().optional().describe('Lọc bài có score >= min'),
    max_score: z.number().optional().describe('Lọc bài có score <= max'),
  },
  async (p) => j(await api(`/rankmath?${qs(p)}`)));

server.tool('rankmath_dump',
  'Dump TOÀN BỘ Rank Math options từ DB + postmeta keys. Bypass cache, luôn trả dữ liệu mới nhất. group=all|general|titles|sitemap. Dùng để xác minh tên key chính xác.',
  {
    group:   z.enum(['all','general','titles','sitemap']).default('all'),
    post_id: z.number().optional().describe('ID bài để xem postmeta — mặc định lấy bài mới nhất'),
  },
  async ({ group, post_id }) => {
    const qs = new URLSearchParams({ group });
    if (post_id) qs.set('post_id', post_id);
    return j(await api(`/rankmath/dump?${qs}`));
  });

server.tool('rankmath_get', 'SEO data của một post/page/product: title, desc, focus_kw, score, canonical + custom_schema postmeta.', { id: z.number() },
  async ({ id }) => j(await api(`/rankmath/${id}`)));

server.tool('rankmath_update',
  'Cập nhật SEO title/desc/focus_kw/canonical cho một post. Field canonical lưu vào rank_math_canonical_url. ' +
  '⚠️ POST MỚI TẠO: phải gọi post_update trước để RankMath khởi tạo record, sau đó mới gọi rankmath_update. Nếu không, data sẽ không được lưu.',
  {
  id:        z.number(),
  seo_title: z.string().optional().describe('SEO title, dùng %title% %sep% %sitename%'),
  seo_desc:  z.string().optional().describe('Meta description 120-160 ký tự'),
  focus_kw:  z.string().optional(),
  canonical: z.string().optional(),
}, async ({ id, ...b }) => j(await api(`/rankmath/${id}`, 'PUT', b)));

server.tool('rankmath_settings_get',
  'Đọc cài đặt global Rank Math: Local SEO, Knowledge Graph, URL website, tên thương hiệu, mạng xã hội. group=titles (mặc định) | general | sitemap.',
  { group: z.enum(['titles','general','sitemap']).default('titles') },
  async ({ group }) => j(await api(`/rankmath/settings?group=${group}`)));

server.tool('rankmath_settings_update',
  'Sửa Rank Math global settings — Local SEO, Knowledge Graph, social URLs. Hỗ trợ cả array values (opening_hours, phone_numbers). Ví dụ: đổi url từ xclipse.store → xclipse.com.',
  {
    group:    z.enum(['titles','general','sitemap']).default('titles'),
    settings: z.record(z.any())
      .describe('VD: {"url":"https://xclipse.com/","opening_hours":[{"day":"Mon-Fri","time":"8:00-18:00"}]}'),
  },
  async ({ group, settings }) => j(await api('/rankmath/settings', 'PUT', { group, settings })));

// RANKMATH BULK + REDIRECTS
server.tool('rankmath_bulk_update',
  'Bulk update SEO title/desc/focus_kw cho NHIỀU posts cùng lúc. Hiệu quả hơn gọi rankmath_update nhiều lần. ' +
  '⚠️ POST MỚI TẠO: phải gọi post_update trước để RankMath khởi tạo record, sau đó mới gọi rankmath_bulk_update. Nếu không, data sẽ không được lưu.',
  {
    items: z.array(z.object({
      id:        z.number().describe('Post/page/product ID'),
      seo_title: z.string().optional(),
      seo_desc:  z.string().optional(),
      focus_kw:  z.string().optional(),
      canonical: z.string().optional(),
    })).describe('[{id:123,seo_title:"...",seo_desc:"...",focus_kw:"..."},…]'),
  },
  async ({ items }) => j(await api('/rankmath/bulk', 'POST', { items })));

server.tool('rankmath_redirects_list',
  'Danh sách Rank Math redirections đang active.',
  { per_page: z.number().default(50) },
  async ({ per_page }) => j(await api(`/rankmath/redirects?per_page=${per_page}`)));

server.tool('rankmath_redirect_create',
  'Tạo redirect mới. Code: 301 (vĩnh viễn), 302 (tạm thời), 307, 410 (xoá), 451.',
  {
    from: z.string().describe('URL nguồn (path), ví dụ: /san-pham-cu/'),
    to:   z.string().describe('URL đích đầy đủ hoặc path'),
    code: z.number().default(301).describe('301|302|307|410|451'),
  },
  async (b) => j(await api('/rankmath/redirects', 'POST', b)));

server.tool('rankmath_redirect_delete',
  'Xoá một redirect theo ID.',
  { id: z.number() },
  async ({ id }) => ok(`Xoá redirect ${id}`, await api(`/rankmath/redirects/${id}`, 'DELETE')));

// BURST STATISTICS
server.tool('burst_stats', 'Burst Statistics: pageviews, visitors, devices, referrers.', {
  days: z.number().default(30),
}, async ({ days }) => j(await api(`/burst?days=${days}`)));

server.tool('burst_top_pages', 'Top trang có nhiều lượt xem nhất.', {
  days: z.number().default(30), limit: z.number().default(20),
}, async ({ days, limit }) => j(await api(`/burst/top-pages?days=${days}&limit=${limit}`)));

// SITE KIT
server.tool('sitekit_info', 'Google Site Kit: modules đã kết nối (GA4, Search Console, AdSense).', {},
  async () => j(await api('/sitekit')));

// THEMES & PLUGINS
server.tool('get_themes', 'Theme đang active và tất cả theme đã cài.', {},
  async () => j(await api('/themes')));

server.tool('get_plugins', 'Danh sách plugin: tên, version, active/inactive.', {},
  async () => j(await api('/plugins')));

// SETTINGS
server.tool('settings_get', 'Đọc cài đặt WP/WooCommerce.', {
  keys: z.string().default('').describe('Danh sách key cách nhau bằng dấu phẩy. Để trống = lấy tất cả.'),
}, async ({ keys }) => j(await api(`/settings?keys=${keys}`)));

server.tool('settings_update', 'Cập nhật cài đặt WP/WooCommerce.', {
  settings: z.record(z.string()).describe('{"blogname":"Tên mới","woocommerce_currency":"VND"}'),
}, async ({ settings }) => j(await api('/settings', 'POST', { settings })));

// TAXONOMY
server.tool('get_categories', 'Danh mục bài viết (id,name,slug,count).', {},
  async () => j(await api('/categories')));

server.tool('get_tags', 'Tags bài viết top 100.', {},
  async () => j(await api('/tags')));

// COMMENTS
server.tool('comments_list', 'Danh sách bình luận. Lọc theo status, post.', {
  ...Pager,
  status:  z.enum(['hold','approve','spam','trash','all']).default('hold').describe('hold=chờ duyệt'),
  post_id: z.number().default(0).describe('Lọc theo bài viết (0=tất cả)'),
}, async (p) => j(await api(`/comments?${new URLSearchParams(p)}`)));

server.tool('comment_get', 'Chi tiết một bình luận.', { id: z.number() },
  async ({ id }) => j(await api(`/comments/${id}`)));

server.tool('comment_update', 'Duyệt/spam/xoá bình luận hoặc sửa nội dung.', {
  id:      z.number(),
  status:  z.enum(['approved','pending','spam','trash']).optional(),
  content: z.string().optional(),
}, async ({ id, ...b }) => ok(`Comment ${id}`, await api(`/comments/${id}`, 'PUT', b)));

server.tool('comment_delete', 'Xoá bình luận.', {
  id: z.number(), force: z.boolean().default(false),
}, async ({ id, force }) => ok(`Xoá comment ${id}`, await api(`/comments/${id}?force=${force}`, 'DELETE')));

// USERS
server.tool('users_list', 'Danh sách người dùng WordPress.', {
  ...Pager,
  role:   z.string().default('').describe('administrator|editor|author|subscriber|customer'),
  search: z.string().default(''),
}, async (p) => j(await api(`/users?${new URLSearchParams(p)}`)));

server.tool('user_get', 'Chi tiết người dùng: roles, bio.', { id: z.number() },
  async ({ id }) => j(await api(`/users/${id}`)));

server.tool('user_create', 'Tạo người dùng mới.', {
  username:     z.string(),
  email:        z.string(),
  password:     z.string().default('').describe('Để trống = tự tạo'),
  role:         z.string().default('subscriber'),
  display_name: z.string().default(''),
}, async (b) => j(await api('/users', 'POST', b)));

server.tool('user_update', 'Cập nhật thông tin người dùng + Rank Math social meta.', {
  id:           z.number(),
  email:        z.string().optional(),
  display_name: z.string().optional(),
  role:         z.string().optional(),
  bio:          z.string().optional(),
  // Rank Math author social
  website:      z.string().optional().describe('rank_math_author_website'),
  facebook:     z.string().optional(),
  twitter:      z.string().optional(),
  linkedin:     z.string().optional(),
  instagram:    z.string().optional(),
  youtube:      z.string().optional(),
}, async ({ id, ...b }) => ok(`User ${id}`, await api(`/users/${id}`, 'PUT', b)));

// BULK POSTS & PRODUCTS
server.tool('bulk_posts_update',
  'Bulk update NHIỀU posts cùng lúc: title, content, status, categories, meta, SEO. ' +
  'dry_run=true để xem sẽ update những gì mà không thực sự thay đổi.',
  {
    items: z.array(z.object({
      id:         z.number(),
      title:      z.string().optional(),
      content:    z.string().optional(),
      excerpt:    z.string().optional(),
      status:     z.string().optional().describe('publish|draft|private|trash'),
      slug:       z.string().optional(),
      categories: z.array(z.number()).optional(),
      tags:       z.array(z.string()).optional(),
      seo_title:  z.string().optional(),
      seo_desc:   z.string().optional(),
      focus_kw:   z.string().optional(),
      meta:       z.record(z.any()).optional().describe('Custom post meta'),
    })).describe('[{id:123,title:"Tiêu đề mới",status:"publish",seo_title:"..."},…]'),
    dry_run: z.boolean().default(false).describe('true = chỉ preview, không thay đổi gì'),
  },
  async ({ items, dry_run }) => j(await api('/bulk/posts', 'POST', { items, dry_run })));

server.tool('bulk_products_update',
  'Bulk update NHIỀU WooCommerce products cùng lúc: giá, tồn kho, tên, SEO. Hiệu quả hơn gọi product_update nhiều lần.',
  {
    items: z.array(z.object({
      id:           z.number(),
      title:        z.string().optional(),
      description:  z.string().optional(),
      short_desc:   z.string().optional(),
      status:       z.string().optional().describe('publish|draft|private|trash'),
      sku:          z.string().optional(),
      price:        z.string().optional().describe('Giá thường (VD: "500000")'),
      sale_price:   z.string().optional().describe('Giá sale'),
      stock:        z.number().optional(),
      stock_status: z.string().optional().describe('instock|outofstock|onbackorder'),
      weight:       z.string().optional(),
      categories:   z.array(z.number()).optional(),
      seo_title:    z.string().optional(),
      seo_desc:     z.string().optional(),
      focus_kw:     z.string().optional(),
      meta:         z.record(z.any()).optional(),
    })).describe('[{id:456,price:"550000",stock:10,seo_title:"..."},…]'),
  },
  async ({ items }) => j(await api('/bulk/products', 'POST', { items })));

// MENUS
server.tool('get_menus', 'Danh sách tất cả navigation menus: id, name, slug, số items, location đang assign.', {},
  async () => j(await api('/menus')));

server.tool('get_menu', 'Xem toàn bộ nội dung 1 menu: danh sách items với title, url, parent, order, type, classes.', { id: z.number() },
  async ({ id }) => j(await api(`/menus/${id}`)));

server.tool('menu_create',
  'Tạo menu navigation mới. Tùy chọn assign ngay vào 1 location (lấy tên location từ menu_locations).',
  {
    name:     z.string().describe('Tên menu'),
    location: z.string().optional().describe('Location để assign (vd: primary, footer)'),
  },
  async (b) => j(await api('/menus', 'POST', b)));

server.tool('menu_update',
  'Đổi tên menu hoặc assign/gỡ menu khỏi location. Truyền location="" để gỡ menu khỏi tất cả locations.',
  {
    id:       z.number().describe('Menu ID'),
    name:     z.string().optional().describe('Tên mới'),
    location: z.string().optional().describe('Location để assign, hoặc "" để gỡ'),
  },
  async ({ id, ...b }) => j(await api(`/menus/${id}`, 'PUT', b)));

server.tool('menu_delete',
  'Xóa 1 menu và toàn bộ items của nó.',
  { id: z.number().describe('Menu ID') },
  async ({ id }) => j(await api(`/menus/${id}`, 'DELETE')));

server.tool('menu_item_add',
  'Thêm item vào menu. type: custom (URL tùy chỉnh) | post_type (link đến post/page) | taxonomy (link đến category/tag). parent=0: item cấp 1; parent=item_id: submenu.',
  {
    id:        z.number().describe('Menu ID'),
    title:     z.string().describe('Nhãn hiển thị'),
    url:       z.string().optional().describe('URL (dùng cho type=custom)'),
    type:      z.enum(['custom','post_type','taxonomy']).default('custom'),
    object:    z.string().optional().describe('post|page|category|product|product_cat|...'),
    object_id: z.number().optional().describe('ID của post/page/term cần link'),
    parent:    z.number().optional().describe('ID của item cha (0 = cấp 1)'),
    order:     z.number().optional().describe('Vị trí trong menu (1,2,3,...)'),
    target:    z.string().optional().describe('_blank để mở tab mới'),
    classes:   z.string().optional().describe('CSS classes tùy chỉnh'),
    attr_title:z.string().optional().describe('Title attribute (tooltip)'),
  },
  async ({ id, ...b }) => j(await api(`/menus/${id}/items`, 'POST', b)));

server.tool('menu_item_update',
  'Sửa 1 item trong menu. Chỉ truyền fields cần thay đổi, các field khác giữ nguyên.',
  {
    id:        z.number().describe('Menu ID'),
    item_id:   z.number().describe('Item ID'),
    title:     z.string().optional(),
    url:       z.string().optional(),
    type:      z.enum(['custom','post_type','taxonomy']).optional(),
    object:    z.string().optional(),
    object_id: z.number().optional(),
    parent:    z.number().optional().describe('0 = lên cấp 1, hoặc ID item cha mới'),
    order:     z.number().optional(),
    target:    z.string().optional().describe('_blank hoặc "" để bỏ'),
    classes:   z.string().optional(),
    attr_title:z.string().optional(),
  },
  async ({ id, item_id, ...b }) => j(await api(`/menus/${id}/items/${item_id}`, 'PUT', b)));

server.tool('menu_item_delete',
  'Xóa 1 item khỏi menu.',
  {
    id:      z.number().describe('Menu ID'),
    item_id: z.number().describe('Item ID'),
  },
  async ({ id, item_id }) => j(await api(`/menus/${id}/items/${item_id}`, 'DELETE')));

server.tool('menu_reorder',
  'Sắp xếp lại thứ tự và cấu trúc cha-con của items trong menu. Truyền toàn bộ items với order mới.',
  {
    id:    z.number().describe('Menu ID'),
    items: z.array(z.object({
      id:     z.number().describe('Item ID'),
      order:  z.number().describe('Vị trí mới (bắt đầu từ 1)'),
      parent: z.number().optional().describe('0 = cấp 1, hoặc ID item cha'),
    })).describe('Danh sách items với order và parent mới'),
  },
  async ({ id, items }) => j(await api(`/menus/${id}/reorder`, 'POST', { items })));

server.tool('menu_locations',
  'Xem tất cả vị trí (locations) menu của theme: tên location, label, menu đang được assign.',
  {},
  async () => j(await api('/menus/locations')));

server.tool('menu_assign_location',
  'Assign 1 menu vào 1 location của theme. menu_id=0 để gỡ menu khỏi location đó.',
  {
    location: z.string().describe('Tên location (lấy từ menu_locations)'),
    menu_id:  z.number().describe('Menu ID cần assign, hoặc 0 để gỡ'),
  },
  async (b) => j(await api('/menus/locations', 'POST', b)));

// CODE SNIPPETS PLUGIN (v4.6.14)
server.tool('snippets_list',
  'Danh sách code snippets (Code Snippets plugin). Trả tên, type, scope, status — không trả code để tiết kiệm token.',
  {
    per_page: z.number().default(50),
    status:   z.enum(['active','inactive','any']).default('any'),
    search:   z.string().default(''),
  },
  async (p) => j(await api(`/snippets?${qs(p)}`)));

server.tool('snippet_get',
  'Xem đầy đủ một snippet theo ID: name, code, type, scope, active, priority, tags.',
  { id: z.number() },
  async ({ id }) => j(await api(`/snippets/${id}`)));

server.tool('snippet_create',
  'Tạo snippet mới (Code Snippets plugin). ' +
  'type: php|html|css|js. ' +
  'scope: global=chạy everywhere | admin=chỉ WP admin | front-end=chỉ frontend | single-use=1 lần rồi tắt | content=dùng qua shortcode. ' +
  '⚠️ LUÔN để active=false khi tạo. Quy trình bắt buộc: (1) snippet_create → (2) snippet_toggle để bật → (3) lscache_purge_all.',
  {
    name:     z.string().describe('Tên snippet'),
    code:     z.string().describe('Nội dung code'),
    type:     z.string().default('php').describe('php|html|css|js'),
    scope:    z.string().default('global').describe('global|admin|front-end|single-use|content'),
    active:   z.boolean().default(false).describe('⚠️ LUÔN false khi tạo — bật sau bằng snippet_toggle'),
    priority: z.number().default(10).describe('Thứ tự thực thi (thấp hơn = chạy trước)'),
    note:     z.string().default('').describe('Mô tả nội bộ'),
    tags:     z.string().default('').describe('Tags phân cách bằng dấu phẩy'),
  },
  async (b) => j(await api('/snippets', 'POST', b)));

server.tool('snippet_update',
  'Sửa snippet (Code Snippets plugin). Chỉ gửi fields cần thay đổi. ' +
  'scope: global|admin|front-end|single-use|content.',
  {
    id:       z.number(),
    name:     z.string().optional().describe('Tên mới'),
    code:     z.string().optional().describe('Nội dung code mới'),
    type:     z.string().optional().describe('php|html|css|js'),
    scope:    z.string().optional().describe('global|admin|front-end|single-use|content'),
    active:   z.boolean().optional().describe('Trạng thái — dùng snippet_toggle nếu chỉ cần bật/tắt'),
    priority: z.number().optional().describe('Thứ tự thực thi'),
    note:     z.string().optional().describe('Mô tả nội bộ'),
    tags:     z.string().optional().describe('Tags phân cách bằng dấu phẩy'),
  },
  async ({ id, ...b }) => j(await api(`/snippets/${id}`, 'PUT', b)));

server.tool('snippet_delete',
  'Xoá vĩnh viễn một snippet theo ID.',
  { id: z.number() },
  async ({ id }) => ok(`Xoá snippet ${id}`, await api(`/snippets/${id}`, 'DELETE')));

server.tool('snippet_toggle',
  'Bật hoặc tắt snippet nhanh. Sau khi bật nhớ chạy lscache_purge_all. ' +
  'Không truyền active = tự toggle trạng thái hiện tại.',
  {
    id:     z.number(),
    active: z.boolean().optional().describe('true=bật, false=tắt, bỏ trống=toggle tự động'),
  },
  async ({ id, ...b }) => j(await api(`/snippets/${id}/toggle`, 'POST', b)));

// FLATSOME UX BUILDER
server.tool('ux_get',
  'Đọc trang Flatsome UX Builder: trả raw shortcode + mảng blocks đã parse (tag, atts, inner, children). Dùng trước khi sửa bất cứ thứ gì.',
  { id: z.number().describe('Page/post ID') },
  async ({ id }) => j(await api(`/ux/${id}`)));

server.tool('ux_blocks',
  'Danh sách top-level blocks của trang (idx, tag, atts, children). Dùng để biết idx trước khi patch.',
  {
    id:    z.number(),
    depth: z.number().default(0).describe('0=full nested, 1=chỉ top-level (tiết kiệm token)'),
  },
  async ({ id, depth }) => j(await api(`/ux/${id}/blocks?depth=${depth}`)));

server.tool('ux_update',
  'Ghi TOÀN BỘ raw shortcode content của trang (full replace). Dùng khi đã build xong shortcode hoàn chỉnh.',
  {
    id:      z.number(),
    content: z.string().describe('Toàn bộ Flatsome shortcode, VD: [section]...[/section]'),
    title:   z.string().optional(),
    status:  z.string().optional().describe('publish|draft'),
    fs_meta: z.any().optional().describe('Flatsome UX meta (_flatsome_ux)'),
  },
  async ({ id, ...b }) => j(await api(`/ux/${id}`, 'PUT', b)));

server.tool('ux_block_patch',
  'Sửa MỘT block cụ thể theo idx (top-level). Chỉ merge atts được gửi lên — không xoá atts khác. Dùng để đổi text, màu, ảnh, padding của 1 block mà không cần rebuild toàn trang.',
  {
    id:    z.number().describe('Page ID'),
    idx:   z.number().describe('Block index (lấy từ ux_blocks)'),
    atts:  z.record(z.string()).optional().describe('Attributes cần thay: {"height":"600px","bg":"#123"}'),
    inner: z.string().optional().describe('Nội dung text/html bên trong block'),
    tag:   z.string().optional().describe('Đổi tag nếu cần'),
  },
  async ({ id, idx, ...b }) => j(await api(`/ux/${id}/blocks/${idx}`, 'PUT', b)));

server.tool('ux_block_add',
  'Thêm block MỚI vào trang. Có thể chỉ định vị trí (position). Hữu ích khi thêm section mới, banner mới.',
  {
    id:       z.number(),
    tag:      z.string().describe('Flatsome tag: section|ux_banner|row|col|text_box|button|ux_products|divider|gap|title|ux_slider|ux_video|ux_countdown|ux_testimonials|ux_image_box'),
    atts:     z.record(z.string()).optional().describe('Attributes của block'),
    inner:    z.string().optional().describe('Nội dung shortcode bên trong (con cái)'),
    position: z.number().optional().describe('Vị trí chèn (0=đầu, null/bỏ trống=cuối)'),
  },
  async ({ id, ...b }) => j(await api(`/ux/${id}/blocks`, 'POST', b)));

server.tool('ux_block_delete',
  'Xoá một block theo idx. Không thể hoàn tác — nên dùng ux_get để backup raw trước.',
  {
    id:  z.number(),
    idx: z.number().describe('Block index cần xoá'),
  },
  async ({ id, idx }) => j(await api(`/ux/${id}/blocks/${idx}`, 'DELETE')));

server.tool('ux_reorder',
  'Sắp xếp lại thứ tự top-level blocks. VD: order=[2,0,1] đưa block thứ 2 lên đầu.',
  {
    id:    z.number(),
    order: z.array(z.number()).describe('Mảng idx theo thứ tự mới, VD: [2,0,1]'),
  },
  async ({ id, order }) => j(await api(`/ux/${id}/blocks/reorder`, 'POST', { order })));

server.tool('ux_block_clone',
  'Nhân đôi (duplicate) một block — bản sao được chèn ngay sau block gốc.',
  {
    id:  z.number(),
    idx: z.number().describe('Block index cần clone'),
  },
  async ({ id, idx }) => j(await api(`/ux/${id}/clone/${idx}`, 'POST')));

server.tool('ux_search',
  'Tìm kiếm text/attribute trong UX Builder blocks trên nhiều trang. Dùng để tìm tất cả nơi đang dùng shortcode/text cụ thể.',
  {
    q:         z.string().describe('Text cần tìm trong blocks'),
    post_type: z.string().default('page').describe('page|post|any'),
    field:     z.string().default('any').describe('any|inner|att|tag — tìm trong nội dung|attribute|tên tag'),
    per_page:  z.number().default(20),
  },
  async (p) => j(await api(`/ux/search?${new URLSearchParams(p)}`)));

// SERP & SITE SPEED
server.tool('rank_check',
  'Kiểm tra vị trí từ khóa trên Google (SerpApi). Trả về position, URL ranking, featured snippet.',
  {
    keyword: z.string().describe('Từ khóa cần kiểm tra'),
    domain:  z.string().default('xclipse.com').describe('Domain cần tìm trong SERP'),
    gl:      z.string().default('vn').describe('Quốc gia: vn|us|...'),
    hl:      z.string().default('vi').describe('Ngôn ngữ: vi|en|...'),
  },
  async (p) => j(await api(`/serp/rank?${new URLSearchParams(p)}`)));

server.tool('serp_overview',
  'Top 10 kết quả Google cho từ khóa + related searches (SerpApi).',
  {
    keyword: z.string().describe('Từ khóa cần xem SERP'),
    gl:      z.string().default('vn'),
    hl:      z.string().default('vi'),
  },
  async (p) => j(await api(`/serp/overview?${new URLSearchParams(p)}`)));

server.tool('rank_bulk',
  'Kiểm tra vị trí nhiều từ khóa cùng lúc (tối đa 10). Trả position cho từng keyword.',
  {
    keywords: z.string().describe('Danh sách từ khóa cách nhau bằng dấu phẩy, VD: "nhẫn vàng,nhẫn bạc,vòng vàng"'),
    domain:   z.string().default('xclipse.com'),
    gl:       z.string().default('vn'),
    hl:       z.string().default('vi'),
  },
  async (p) => j(await api(`/serp/rank-bulk?${new URLSearchParams(p)}`)));

server.tool('site_speed',
  'PageSpeed Insights + Core Web Vitals cho một URL. Trả score, LCP, CLS, FID, top opportunities.',
  {
    url:      z.string().default('https://xclipse.com').describe('URL cần kiểm tra'),
    strategy: z.enum(['mobile','desktop']).default('mobile'),
  },
  async (p) => j(await api(`/site-speed?${new URLSearchParams(p)}`)));

// LITESPEED CACHE — v3.3.0

server.tool('lscache_status',
  'Trạng thái tổng quan LiteSpeed Cache: version, cache on/off, object cache, CDN, CCSS, WebP, ESI.',
  {},
  async () => j(await api('/lscache/status')));

server.tool('lscache_purge_all',
  'Xoá TOÀN BỘ cache LiteSpeed. Tương đương nút "Purge All" trong admin. Dùng sau khi thay đổi theme/plugin lớn.',
  {
    include_object: z.boolean().default(false).describe('Cũng flush Object Cache (Redis/Memcached)'),
    include_cdn:    z.boolean().default(false).describe('Cũng purge CDN / QUIC.cloud'),
  },
  async (p) => j(await api('/lscache/purge-all', 'POST', p)));

server.tool('lscache_purge_url',
  'Purge cache 1 URL cụ thể (hoặc theo post_id). Dùng sau khi update bài viết, trang.',
  {
    url:     z.string().default('').describe('URL đầy đủ cần purge, VD: https://xclipse.com/gia-vang/'),
    post_id: z.number().default(0).describe('Post ID thay cho URL (nếu có)'),
  },
  async (p) => {
    const body = {};
    if (p.url)     body.url     = p.url;
    if (p.post_id) body.post_id = p.post_id;
    return j(await api('/lscache/purge-url', 'POST', body));
  });

server.tool('lscache_purge_tag',
  'Purge cache theo ESI tag. Tags phổ biến: home, feed, front_page, post.{id}, term.{id}, author.{id}.',
  {
    tags: z.string().describe('Danh sách tags cách nhau dấu phẩy, VD: "home,post.123,term.5"'),
  },
  async ({ tags }) => j(await api('/lscache/purge-tag', 'POST', {
    tags: tags.split(',').map(t => t.trim()).filter(Boolean),
  })));

server.tool('lscache_object_cache',
  'Trạng thái Object Cache (Redis/Memcached): enabled, driver, host, port, ping test.',
  {},
  async () => j(await api('/lscache/object-cache')));

server.tool('lscache_object_cache_action',
  'Thao tác Object Cache: flush (xoá data), enable hoặc disable.',
  {
    action: z.enum(['flush','enable','disable']).describe('flush=xoá cache, enable=bật, disable=tắt'),
  },
  async (p) => j(await api('/lscache/object-cache', 'POST', p)));

server.tool('lscache_cdn_status',
  'Trạng thái CDN và QUIC.cloud: URL CDN, Cloudflare zone, HTTP/2 push, lazy DNS.',
  {},
  async () => j(await api('/lscache/cdn')));

server.tool('lscache_cdn_purge',
  'Purge CDN cache (QUIC.cloud hoặc Cloudflare). Toàn bộ hoặc theo danh sách URL.',
  {
    purge_all: z.boolean().default(false).describe('true = purge toàn bộ CDN'),
    urls:      z.string().default('').describe('Danh sách URL cách nhau dấu phẩy (nếu không purge_all)'),
  },
  async ({ purge_all, urls }) => {
    const body = purge_all ? { purge_all: true }
      : { urls: urls.split(',').map(u => u.trim()).filter(Boolean) };
    return j(await api('/lscache/cdn/purge', 'POST', body));
  });

server.tool('lscache_ccss_status',
  'Trạng thái Critical CSS (CCSS): enabled, async load, UCSS, danh sách 20 URL đã generate gần nhất.',
  {},
  async () => j(await api('/lscache/critical-css')));

server.tool('lscache_ccss_action',
  'Quản lý Critical CSS: generate (yêu cầu QUIC.cloud tạo CCSS), clear (xoá toàn bộ), enable/disable.',
  {
    action: z.enum(['generate','clear','enable','disable']).describe('generate|clear|enable|disable'),
    urls:   z.string().default('').describe('(Tuỳ chọn) URL cụ thể cần generate CCSS, cách nhau dấu phẩy'),
  },
  async ({ action, urls }) => {
    const body = { action };
    if (urls) body.urls = urls.split(',').map(u => u.trim()).filter(Boolean);
    return j(await api('/lscache/critical-css', 'POST', body));
  });

server.tool('lscache_image_optm_status',
  'Trạng thái Image Optimization: WebP, AVIF, lazyload, số ảnh đã optimize/còn chờ.',
  {},
  async () => j(await api('/lscache/image-optm')));

server.tool('lscache_image_optm_action',
  'Thao tác Image Optimization qua QUIC.cloud. actions: request (gửi ảnh lên), pull (kéo về), revert (hoàn tác), enable/disable WebP, AVIF, lazyload.',
  {
    action: z.enum([
      'request','pull','revert',
      'enable_webp','disable_webp',
      'enable_lazyload','disable_lazyload',
      'enable_avif','disable_avif',
    ]).describe('Hành động cần thực hiện'),
    limit: z.number().default(100).describe('(request) Số ảnh tối đa gửi lên mỗi lần (1-1000)'),
  },
  async (p) => j(await api('/lscache/image-optm', 'POST', p)));

server.tool('lscache_crawl_status',
  'Trạng thái Cache Crawler (warm cache): enabled, số URL đã crawl, lần crawl cuối.',
  {},
  async () => j(await api('/lscache/crawl')));

server.tool('lscache_crawl_action',
  'Điều khiển Cache Crawler: start, stop, reset, enable, disable.',
  {
    action: z.enum(['start','stop','reset','enable','disable']).describe('start=chạy ngay, stop=dừng, reset=xoá map, enable/disable=bật tắt'),
  },
  async (p) => j(await api('/lscache/crawl', 'POST', p)));

server.tool('lscache_settings_get',
  'Đọc LiteSpeed Cache settings theo nhóm: cache|optm|img|cdn|object|esi|crawler|debug|security|all.',
  {
    group: z.enum(['cache','optm','img','cdn','object','esi','crawler','debug','security','all']).default('cache'),
  },
  async (p) => j(await api(`/lscache/settings?${new URLSearchParams(p)}`)));

server.tool('lscache_settings_set',
  'Cập nhật LiteSpeed Cache settings. Truyền object key-value, VD: {"cache-enabled":true,"optm-css_min":true}. Tự động purge cache nếu thay đổi cache/optm settings.',
  {
    settings: z.string().describe('JSON string của settings cần update, VD: {"cache-enabled":true,"optm-js_min":true}'),
  },
  async ({ settings }) => {
    const parsed = JSON.parse(settings);
    return j(await api('/lscache/settings', 'PUT', { settings: parsed }));
  });

server.tool('lscache_heartbeat',
  'Ping trang chủ và đọc LiteSpeed response headers để xác nhận cache hit/miss, server đang chạy.',
  {},
  async () => j(await api('/lscache/heartbeat')));

server.tool('audit_summary',
  'Chạy tất cả audit nhẹ cùng lúc: SEO, content, image, DB, WP health, security, performance. Dùng trước để biết vấn đề lớn nhất cần fix.',
  {},
  async () => j(await api('/audit/summary')));

server.tool('audit_seo_issues',
  'Quét tất cả bài viết/trang tìm vấn đề SEO: thiếu title/desc/focus_kw, title quá ngắn/dài, Rank Math score thấp, thiếu featured image, thin content.',
  {
    post_type: z.enum(['post','page','product','all']).default('all'),
    per_page:  z.number().default(200).describe('Số bài quét (max 500)'),
    min_score: z.number().default(50).describe('Coi là vấn đề nếu score < giá trị này'),
  },
  async (p) => j(await api(`/audit/seo-issues?${new URLSearchParams(p)}`)));

server.tool('audit_duplicate_meta',
  'Tìm bài viết bị duplicate SEO title hoặc meta description — ảnh hưởng xấu đến SEO.',
  {
    post_type: z.enum(['post','page','product','all']).default('all'),
    field:     z.enum(['title','desc','both']).default('both'),
  },
  async (p) => j(await api(`/audit/duplicate-meta?${new URLSearchParams(p)}`)));

server.tool('audit_orphan_pages',
  'Tìm bài viết không có internal link nào trỏ vào (orphan pages) — Google khó crawl, pagerank bị lãng phí.',
  {
    post_type: z.enum(['post','page','product','all']).default('post'),
    per_page:  z.number().default(500),
  },
  async (p) => j(await api(`/audit/orphan-pages?${new URLSearchParams(p)}`)));

server.tool('audit_internal_links',
  'Phân tích internal linking: đếm inbound/outbound links cho từng bài, tìm bài ít được link nhất.',
  {
    post_type: z.enum(['post','page','product','all']).default('all'),
    per_page:  z.number().default(100),
    sort:      z.enum(['least_linked','inbound','outbound']).default('least_linked'),
  },
  async (p) => j(await api(`/audit/internal-links?${new URLSearchParams(p)}`)));

server.tool('audit_images',
  'Kiểm tra thư viện ảnh: ảnh không có alt text, không có title, dung lượng lớn >200kb, chưa được tối ưu WebP.',
  {
    per_page: z.number().default(50),
    issue:    z.enum(['no_alt','no_title','large','all']).default('all'),
  },
  async (p) => j(await api(`/audit/images?${new URLSearchParams(p)}`)));

server.tool('audit_images_in_content',
  'Quét nội dung bài viết tìm thẻ <img> thiếu alt text hoặc alt rỗng — quan trọng cho SEO hình ảnh.',
  {
    post_type: z.enum(['post','page','product','all']).default('all'),
    per_page:  z.number().default(100),
  },
  async (p) => j(await api(`/audit/images-in-content?${new URLSearchParams(p)}`)));

server.tool('audit_content',
  'Phân tích chất lượng nội dung: thin content, không có H2/H3, không có internal link, nội dung quá cũ (>365 ngày chưa update), không có ảnh.',
  {
    post_type: z.enum(['post','page','product','all']).default('post'),
    per_page:  z.number().default(200),
    min_words: z.number().default(500).describe('Dưới số này = thin content'),
  },
  async (p) => j(await api(`/audit/content?${new URLSearchParams(p)}`)));

server.tool('audit_broken_links',
  'Quét external links trong nội dung bài viết và ping từng link để tìm link hỏng (4xx/5xx). CHẬM — chỉ quét tối đa 5 bài/lần.',
  {
    post_id:   z.number().default(0).describe('Quét 1 bài cụ thể (0 = dùng per_page)'),
    post_type: z.enum(['post','page','product']).default('post'),
    per_page:  z.number().default(3).describe('Số bài quét cùng lúc (max 20)'),
    timeout:   z.number().default(5).describe('Timeout ping mỗi link (giây, max 10)'),
  },
  async (p) => j(await api(`/audit/broken-links?${new URLSearchParams(p)}`)));

server.tool('audit_wp_health',
  'Kiểm tra sức khỏe WordPress: PHP version, debug mode, memory, plugin inactive, revisions, transients, cron jobs, DB size, autoload bloat.',
  {},
  async () => j(await api('/audit/wp-health')));

server.tool('audit_security',
  'Kiểm tra bảo mật cơ bản: HTTPS, XML-RPC, readme.html accessible, user enumeration, default admin, file editing, wp-config protection. Trả về security score 0-100.',
  {},
  async () => j(await api('/audit/security')));

server.tool('audit_post_performance',
  'Kết hợp Burst Stats + Rank Math để tìm: bài traffic cao nhưng score thấp, bài không có traffic sau 30 ngày, zombie content, thiếu SEO meta.',
  {
    post_type: z.enum(['post','page','product','all']).default('post'),
    per_page:  z.number().default(50),
    days:      z.number().default(30).describe('Thống kê trong X ngày gần nhất (max 90)'),
  },
  async (p) => j(await api(`/audit/post-performance?${new URLSearchParams(p)}`)));

server.tool('audit_db_health',
  'Phân tích database WordPress: kích thước từng bảng, số revision, spam comments, expired transients, orphan postmeta, auto-draft cũ, trash posts.',
  {},
  async () => j(await api('/audit/db-health')));

server.tool('audit_db_cleanup',
  'Dọn dẹp database: xóa revisions, expired transients, spam comments, auto-draft cũ, trash posts, orphan postmeta. KHÔNG THỂ HOÀN TÁC — chạy audit_db_health trước.',
  {
    actions: z.array(z.enum(['revisions','transients','spam','autodraft','trash_posts','orphan_meta']))
      .default(['transients','spam','autodraft'])
      .describe('Danh sách hành động cần thực hiện'),
  },
  async ({ actions }) => j(await api('/audit/db-cleanup', 'POST', { actions })));

server.tool('audit_redirects_health',
  'Kiểm tra Rank Math redirects: tìm redirect chains (A→B→C), redirect loops, redirect inactive, và ping destination để phát hiện redirect trỏ đến 404.',
  {
    check_live: z.boolean().default(false).describe('Ping URL đích để kiểm tra live (chậm hơn, tối đa 20 redirects)'),
  },
  async ({ check_live }) => j(await api(`/audit/redirects-health?check_live=${check_live}`)));

server.tool('rankmath_tax_list',
  'Liệt kê tất cả terms của 1 taxonomy kèm Rank Math SEO data (title, desc, focus_kw, score). taxonomy: category | post_tag | product_cat | product_tag | bất kỳ custom taxonomy nào.',
  {
    taxonomy:   z.string().describe('Tên taxonomy: category, post_tag, product_cat, product_tag, ...'),
    hide_empty: z.boolean().default(false).describe('Ẩn term không có bài (mặc định false = hiển thị hết)'),
    per_page:   z.number().default(200).describe('Số lượng tối đa (max 500)'),
    search:     z.string().default('').describe('Tìm kiếm term theo tên'),
  },
  async ({ taxonomy, hide_empty, per_page, search }) => {
    const params = new URLSearchParams({ hide_empty: String(hide_empty), per_page: String(per_page) });
    if (search) params.set('search', search);
    return j(await api(`/rankmath/tax/${taxonomy}?${params}`));
  });

server.tool('rankmath_tax_get',
  'Đọc Rank Math SEO của 1 term (category, tag, v.v.): title, desc, focus_kw, robots, canonical, seo_score.',
  {
    taxonomy: z.string().describe('Tên taxonomy: category, post_tag, product_cat, ...'),
    term_id:  z.number().describe('ID của term'),
  },
  async ({ taxonomy, term_id }) => j(await api(`/rankmath/tax/${taxonomy}/${term_id}`)));

server.tool('rankmath_tax_update',
  'Cập nhật Rank Math SEO cho 1 term (category, tag, v.v.). Dùng %term_title% %sep% %sitename% trong seo_title.',
  {
    taxonomy:  z.string().describe('Tên taxonomy: category, post_tag, product_cat, ...'),
    term_id:   z.number().describe('ID của term'),
    seo_title: z.string().optional().describe('SEO title, VD: "Vàng 9999 Thanh Hóa %sep% %sitename%"'),
    seo_desc:  z.string().optional().describe('Meta description 120-160 ký tự'),
    focus_kw:  z.string().optional().describe('Focus keyword chính'),
    canonical: z.string().optional().describe('Canonical URL (nếu cần override)'),
    robots:    z.string().optional().describe('noindex, nofollow, ... (để trống = follow default)'),
  },
  async ({ taxonomy, term_id, ...b }) => j(await api(`/rankmath/tax/${taxonomy}/${term_id}`, 'PUT', b)));

server.tool('rankmath_tax_bulk_update',
  'Bulk update Rank Math SEO cho NHIỀU terms cùng lúc. Hiệu quả hơn gọi rankmath_tax_update nhiều lần.',
  {
    items: z.array(z.object({
      taxonomy:  z.string(),
      term_id:   z.number(),
      seo_title: z.string().optional(),
      seo_desc:  z.string().optional(),
      focus_kw:  z.string().optional(),
      canonical: z.string().optional(),
      robots:    z.string().optional(),
    })).describe('[{taxonomy:"category",term_id:5,seo_title:"...",seo_desc:"...",focus_kw:"..."},…]'),
  },
  async ({ items }) => j(await api('/rankmath/tax/bulk', 'POST', { items })));

server.tool('rich_results',
  'Kiểm tra Rich Results / Structured Data của 1 URL: fetch trang, extract JSON-LD, phân tích schema types + required fields, validate qua validator.schema.org, lấy verdict từ Google Search Console (nếu có token). Thay thế cho Rich Results Test UI.',
  {
    url:      z.string().describe('URL cần kiểm tra (mặc định homepage)').default(''),
    validate: z.boolean().describe('Gọi validator.schema.org API không (mặc định true)').default(true),
  },
  async ({ url, validate }) => j(await api(`/rich-results?url=${encodeURIComponent(url)}&validate=${validate ? 'true' : 'false'}`)));

server.tool('schema_validate',
  'Validate JSON-LD schema bất kỳ qua validator.schema.org API. Nhận schema object, HTML chứa JSON-LD, hoặc URL. Trả về errors, warnings, và phân tích nhanh required fields theo Google Rich Results rules.',
  {
    schema: z.record(z.unknown()).describe('Schema object cần validate').optional(),
    html:   z.string().describe('HTML chứa <script type="application/ld+json">').optional(),
    url:    z.string().describe('URL để fetch và validate schema').optional(),
  },
  async ({ schema, html, url }) => {
    const body = {};
    if (schema) body.schema = schema;
    else if (html) body.html = html;
    else if (url) body.url = url;
    else return j({ error: 'Cần 1 trong 3: schema, html, url' });
    return j(await api('/schema-validate', 'POST', body));
  });

server.tool('fix_security',
  'Sửa lỗi bảo mật: block XML-RPC, xóa readme.html/license.txt, block user enumeration, liệt kê admins. action: block_xmlrpc|delete_readme|block_user_enum|list_admins|all',
  { action: z.enum(['block_xmlrpc','delete_readme','block_user_enum','list_admins','all']).describe('Hành động cần thực hiện') },
  async ({ action }) => j(await api('/fix/security', 'POST', { action })));

server.tool('fix_user_role',
  'Hạ cấp quyền của một user WordPress. Dùng sau khi fix_security list_admins để hạ cấp admin không cần thiết xuống editor/author.',
  {
    user_id: z.number().describe('ID của user'),
    role:    z.enum(['editor','author','contributor','subscriber']).describe('Role mới'),
  },
  async ({ user_id, role }) => j(await api('/fix/user-role', 'POST', { user_id, role })));

server.tool('fix_alt_text',
  'Bulk cập nhật alt text cho ảnh thiếu alt. Nếu không truyền ids thì tự lấy tất cả ảnh thiếu alt. pattern: text tĩnh cho tất cả; use_title=true: dùng title attachment. dry_run=true để xem trước.',
  {
    ids:     z.array(z.number()).optional().describe('Danh sách attachment ID (bỏ trống = tất cả thiếu alt)'),
    pattern: z.string().optional().describe('Alt text tĩnh áp dụng cho tất cả'),
    dry_run: z.boolean().optional().describe('true = xem trước, không ghi'),
    limit:   z.number().optional().describe('Số lượng tối đa (mặc định 50)'),
  },
  async (p) => j(await api('/fix/alt-text', 'POST', p)));

server.tool('fix_alt_text_single',
  'Cập nhật alt text cho 1 ảnh cụ thể theo ID.',
  {
    id:  z.number().describe('Attachment ID'),
    alt: z.string().describe('Alt text mới'),
  },
  async ({ id, alt }) => j(await api(`/fix/alt-text/${id}`, 'PUT', { alt })));

server.tool('fix_seo_bulk',
  'Bulk cập nhật 1 trường SEO (focus_kw|seo_title|seo_desc) cho toàn bộ posts/products thiếu trường đó. use_title=true: tự sinh từ title bài. only_empty=true (mặc định): chỉ update bài chưa có. dry_run=true: xem trước.',
  {
    post_type:  z.string().optional().describe('post_type: post|product|page (mặc định: product)'),
    field:      z.enum(['focus_kw','seo_title','seo_desc','canonical']).describe('Trường cần update'),
    value:      z.string().optional().describe('Giá trị tĩnh (dùng nếu không dùng use_title)'),
    use_title:  z.boolean().optional().describe('true: tự sinh giá trị từ title bài viết'),
    only_empty: z.boolean().optional().describe('true (mặc định): chỉ update bài chưa có trường này'),
    limit:      z.number().optional().describe('Số lượng tối đa (mặc định 50)'),
    ids:        z.array(z.number()).optional().describe('Giới hạn cho các ID này'),
    dry_run:    z.boolean().optional().describe('true = xem trước, không ghi'),
  },
  async (p) => j(await api('/fix/seo-bulk', 'POST', p)));

server.tool('fix_internal_links',
  'Thêm block internal links vào cuối nội dung 1 bài viết/sản phẩm cụ thể.',
  {
    post_id: z.number().describe('ID của bài viết'),
    links:   z.array(z.object({ url: z.string(), text: z.string() })).describe('Danh sách link [{url,text}]'),
    heading: z.string().optional().describe('Tiêu đề của block (mặc định: "Xem thêm")'),
    dry_run: z.boolean().optional().describe('true = xem trước HTML, không ghi'),
  },
  async (p) => j(await api('/fix/internal-links', 'POST', p)));

server.tool('fix_internal_links_bulk',
  'Bulk thêm internal links vào cuối nội dung của nhiều bài thiếu links. only_no_links=true: chỉ xử lý bài chưa có <a href nào.',
  {
    post_type:     z.string().optional().describe('post_type (mặc định: post)'),
    links:         z.array(z.object({ url: z.string(), text: z.string() })).describe('Links sẽ append vào TẤT CẢ bài matched'),
    heading:       z.string().optional().describe('Tiêu đề block'),
    only_no_links: z.boolean().optional().describe('true (mặc định): chỉ bài không có link nào'),
    limit:         z.number().optional().describe('Số bài tối đa (mặc định 20)'),
    dry_run:       z.boolean().optional().describe('true = xem trước, không ghi'),
  },
  async (p) => j(await api('/fix/internal-links-bulk', 'POST', p)));

server.tool('fix_lsc_optimization',
  'Áp dụng preset tối ưu LiteSpeed Cache. preset: recommended (WebP+CCSS+CSS min)|image_only|performance.',
  {
    preset: z.enum(['recommended','image_only','performance']).optional().describe('Preset tối ưu (mặc định: recommended)'),
  },
  async ({ preset = 'recommended' }) => j(await api('/fix/lsc-optimization', 'POST', { preset })));

server.tool('fix_featured_image',
  'Bulk tìm và set Featured Image cho bài viết/sản phẩm chưa có. Tự động tìm ảnh từ content hoặc attachment của post.',
  {
    post_type: z.string().optional().describe('post_type (mặc định: post)'),
    ids:       z.array(z.number()).optional().describe('Giới hạn cho các ID này'),
    limit:     z.number().optional().describe('Số lượng tối đa (mặc định 50)'),
    dry_run:   z.boolean().optional().describe('true = xem trước, không set'),
  },
  async (p) => j(await api('/fix/featured-image', 'POST', p)));

server.tool('fix_refresh_date',
  'Cập nhật post_modified date cho bài viết cũ (để Google biết nội dung đã được refresh). Có thể chỉ định post_id, mảng ids, hoặc post_type + older_than_days.',
  {
    post_id:         z.number().optional().describe('ID của 1 bài cụ thể'),
    ids:             z.array(z.number()).optional().describe('Mảng IDs'),
    post_type:       z.string().optional().describe('post_type để bulk update'),
    older_than_days: z.number().optional().describe('Chỉ update bài chưa sửa trong X ngày (mặc định 365)'),
    dry_run:         z.boolean().optional().describe('true = xem trước, không ghi'),
  },
  async (p) => j(await api('/fix/refresh-date', 'POST', p)));

server.tool('fix_redirects_bulk',
  'Tạo nhiều redirect RankMath cùng lúc. Dùng để thiết lập redirect cho URLs cũ bị hỏng.',
  {
    redirects: z.array(z.object({
      from: z.string().describe('URL nguồn (relative, vd: /trang-cu)'),
      to:   z.string().describe('URL đích (relative hoặc absolute)'),
      type: z.number().optional().describe('HTTP code: 301|302|307 (mặc định 301)'),
    })).describe('Danh sách redirects cần tạo'),
  },
  async ({ redirects }) => j(await api('/fix/redirects-bulk', 'POST', { redirects })));

server.tool('fix_product_content',
  'Cập nhật nội dung mô tả (content) và/hoặc short_description của 1 hoặc nhiều sản phẩm WooCommerce. append=true: thêm vào cuối thay vì ghi đè.',
  {
    product_id:        z.number().optional().describe('ID sản phẩm đơn'),
    ids:               z.array(z.number()).optional().describe('Mảng product IDs'),
    content:           z.string().optional().describe('Nội dung mới (post_content)'),
    short_description: z.string().optional().describe('Mô tả ngắn (post_excerpt)'),
    append:            z.boolean().optional().describe('true: thêm vào cuối; false (mặc định): ghi đè'),
    dry_run:           z.boolean().optional().describe('true = xem trước, không ghi'),
  },
  async (p) => j(await api('/fix/product-content', 'POST', p)));

server.tool('flatsome_get',
  'Lấy toàn bộ Flatsome theme settings: Customizer mods (Header, Style, Logo, Layout...), Advanced Options, Custom CSS (all screens / tablets / mobile).',
  {},
  async () => j(await api('/flatsome', 'GET')));

server.tool('flatsome_update',
  'Cập nhật Flatsome theme settings. "mods": Customizer mods (vd: logo, header_sticky, body_font...). "advanced": Flatsome Advanced Options (Performance, Site Loader...).',
  {
    mods:     z.record(z.string(), z.any()).optional().describe('Customizer theme_mods cần cập nhật, vd: {"logo":"https://...","header_sticky":"1"}'),
    advanced: z.record(z.string(), z.any()).optional().describe('Flatsome Advanced Options, vd: {"loader":"1","loader_bg":"#fff"}'),
  },
  async (p) => j(await api('/flatsome', 'PUT', p)));

server.tool('flatsome_custom_css_get',
  'Lấy Custom CSS của Flatsome: all_screens, tablets (tablets and down), mobile only.',
  {},
  async () => j(await api('/flatsome/custom-css', 'GET')));

server.tool('uxb_list',
  'Liệt kê tất cả UX Blocks của Flatsome (post_type=ux_block). Trả về id, title, shortcode [ux_block id="X"], content, status.',
  {
    search:   z.string().optional().describe('Tìm theo title'),
    status:   z.enum(['publish','draft','any']).optional().describe('Lọc theo status (mặc định: any)'),
    per_page: z.number().optional().describe('Số kết quả mỗi trang (mặc định 50)'),
    page:     z.number().optional().describe('Trang (mặc định 1)'),
  },
  async (p) => j(await api(`/ux-blocks?${qs(p)}`, 'GET')));

server.tool('uxb_create',
  'Tạo UX Block mới trong Flatsome. Content là Flatsome shortcodes. Sau khi tạo, dùng shortcode [ux_block id="X"] để nhúng vào bất kỳ trang/bài viết nào.',
  {
    title:   z.string().describe('Tên block, vd: "Header Banner", "Footer About"'),
    content: z.string().optional().describe('Nội dung Flatsome shortcodes, vd: [row][col span="12"]...[/col][/row]'),
    status:  z.enum(['publish','draft']).optional().describe('Trạng thái (mặc định publish)'),
  },
  async (p) => j(await api('/ux-blocks', 'POST', p)));

server.tool('uxb_get',
  'Lấy chi tiết một UX Block theo ID. Trả về title, content (shortcodes), shortcode để nhúng, link edit trong WP Admin.',
  {
    id: z.number().describe('ID của UX Block'),
  },
  async ({ id }) => j(await api(`/ux-blocks/${id}`, 'GET')));

server.tool('uxb_update',
  'Cập nhật UX Block (title, content shortcodes, status). Chỉ truyền field cần thay đổi.',
  {
    id:      z.number().describe('ID của UX Block'),
    title:   z.string().optional().describe('Tên mới'),
    content: z.string().optional().describe('Nội dung shortcodes mới'),
    status:  z.enum(['publish','draft','private']).optional().describe('Trạng thái mới'),
  },
  async ({ id, ...body }) => j(await api(`/ux-blocks/${id}`, 'PUT', body)));

server.tool('uxb_delete',
  'Xóa UX Block (post_type ux_block). force=false (mặc định) chuyển vào trash; force=true xóa vĩnh viễn.',
  {
    id:    z.number().describe('ID của UX Block'),
    force: z.boolean().optional().describe('true = xóa vĩnh viễn; false = trash (mặc định)'),
  },
  async ({ id, force }) => j(await api(`/ux-blocks/${id}?force=${force ?? false}`, 'DELETE')));

server.tool('flatsome_header_get',
  'Lấy toàn bộ Flatsome header settings: layout, logo (main/sticky/mobile), màu nền, text color, sticky header, transparent header, navigation style, search, cart, top bar, element rows.',
  {},
  async () => j(await api('/flatsome/header', 'GET')));

server.tool('flatsome_header_set',
  'Ghi Flatsome header mods. Truyền object với các key cần update. Keys phổ biến: header_bg_color, header_text_color, header_sticky ("1"/"0"), header_transparent, nav_uppercase, header_nav_link_color, header_nav_hover_color, header_max_width, header_main_height.',
  {
    mods: z.record(z.string(), z.any()).describe(
      'Header mods cần update. Ví dụ: {"header_bg_color":"#1a2a5e","header_text_color":"#ffffff","header_sticky":"1","nav_uppercase":"1","header_nav_link_color":"#ffffff","header_nav_hover_color":"#f5a623","header_main_height":"80"}'
    ),
  },
  async ({ mods }) => j(await api('/flatsome/header', 'PUT', mods)));

server.tool('flatsome_header_logo_set',
  'Set logo cho Flatsome header. Hỗ trợ logo chính, sticky logo, mobile logo. Truyền URL hoặc attachment ID. Có thể set width/height.',
  {
    logo:              z.string().optional().describe('URL hoặc attachment ID của logo chính'),
    logo_width:        z.string().optional().describe('Logo width (px, vd: "180")'),
    logo_height:       z.string().optional().describe('Logo height (px, vd: "50")'),
    logo_sticky:       z.string().optional().describe('URL hoặc attachment ID của sticky logo'),
    logo_sticky_width: z.string().optional().describe('Sticky logo width (px)'),
    logo_mobile:       z.string().optional().describe('URL hoặc attachment ID của mobile logo'),
    logo_mobile_width: z.string().optional().describe('Mobile logo width (px)'),
  },
  async (p) => j(await api('/flatsome/header/logo', 'PUT', p)));

server.tool('flatsome_header_topbar_get',
  'Lấy cài đặt Top Bar của Flatsome header (bật/tắt, màu nền, text color, chiều cao, nội dung trái/phải).',
  {},
  async () => j(await api('/flatsome/header/topbar', 'GET')));

server.tool('flatsome_header_topbar_set',
  'Ghi cài đặt Top Bar của Flatsome. Keys: header_top ("1"/"0"), header_top_bg_color, header_top_text_color, header_top_height, header_top_left (text/HTML bên trái), header_top_right (text/HTML bên phải).',
  {
    mods: z.record(z.string(), z.any()).describe(
      'Top bar mods. Ví dụ: {"header_top":"1","header_top_bg_color":"#1a2a5e","header_top_text_color":"#ffffff","header_top_height":"40","header_top_left":"Hotline: 1900 xxxx","header_top_right":"Email: contact@example.com"}'
    ),
  },
  async ({ mods }) => j(await api('/flatsome/header/topbar', 'PUT', mods)));

server.tool('flatsome_footer_get',
  'Lấy tất cả Flatsome footer settings (màu nền, text, copyright, số cột widget...) và danh sách widget trong footer-1, footer-2, footer-3, footer-4, footer-bar.',
  {},
  async () => j(await api('/flatsome/footer', 'GET')));

server.tool('flatsome_footer_set',
  'Ghi Flatsome footer Customizer mods. Ví dụ: footer_bg_color, footer_text_color, footer_copy, footer_widgets (số cột).',
  {
    mods: z.record(z.string(), z.any()).describe('Các footer mod cần update, vd: {"footer_bg_color":"#1a2a5e","footer_copy":"© 2025 ...","footer_widgets":"4"}'),
  },
  async ({ mods }) => j(await api('/flatsome/footer', 'PUT', mods)));

server.tool('widgets_list',
  'Liệt kê tất cả Widget Areas (sidebars) đã đăng ký trên WordPress, kèm danh sách widgets trong mỗi area. Dùng để xem footer-1, footer-2, sidebar, v.v.',
  {},
  async () => j(await api('/widgets', 'GET')));

server.tool('sidebar_get',
  'Lấy chi tiết một Widget Area và tất cả widgets trong đó. sidebar_id ví dụ: footer-1, footer-2, footer-bar, sidebar.',
  {
    sidebar_id: z.string().describe('ID của sidebar/widget area, vd: footer-1, footer-2, footer-bar, sidebar'),
  },
  async ({ sidebar_id }) => j(await api(`/widgets/${sidebar_id}`, 'GET')));

server.tool('widget_add',
  'Thêm widget mới vào một Widget Area. Hỗ trợ: text, nav_menu, custom_html, search, recent-posts, categories... Position 0 = đầu, null = cuối.',
  {
    sidebar_id: z.string().describe('ID sidebar đích, vd: footer-1'),
    type:       z.string().describe('Widget type slug: text | nav_menu | custom_html | search | recent-posts | categories | tag_cloud | media_image | ...'),
    settings:   z.record(z.string(), z.any()).describe('Settings của widget. text: {title, text, filter}. nav_menu: {title, nav_menu (menu ID)}. custom_html: {title, content}.'),
    position:   z.number().optional().describe('Vị trí trong sidebar (0 = đầu, bỏ qua = cuối)'),
  },
  async ({ sidebar_id, type, settings, position }) => j(await api(`/widgets/${sidebar_id}`, 'POST', { type, settings, position })));

server.tool('widget_update',
  'Cập nhật settings của widget đã có. widget_id dạng "text-3", "nav_menu-1". Mặc định merge với settings cũ; truyền replace:true để ghi đè hoàn toàn.',
  {
    sidebar_id: z.string().describe('ID sidebar chứa widget'),
    widget_id:  z.string().describe('Widget ID dạng type-number, vd: text-3, nav_menu-1, custom_html-2'),
    settings:   z.record(z.string(), z.any()).describe('Settings cần cập nhật (merge với cũ)'),
    replace:    z.boolean().optional().describe('true = ghi đè hoàn toàn settings cũ'),
  },
  async ({ sidebar_id, widget_id, settings, replace }) => j(await api(`/widgets/${sidebar_id}/${widget_id}`, 'PUT', { ...settings, replace })));

server.tool('widget_delete',
  'Xóa widget khỏi sidebar. permanent=true xóa luôn instance settings, false (mặc định) chỉ bỏ khỏi sidebar.',
  {
    sidebar_id: z.string().describe('ID sidebar chứa widget'),
    widget_id:  z.string().describe('Widget ID, vd: text-3'),
    permanent:  z.boolean().optional().describe('true = xóa hoàn toàn instance; false = chỉ remove khỏi sidebar'),
  },
  async ({ sidebar_id, widget_id, permanent }) => j(await api(`/widgets/${sidebar_id}/${widget_id}?permanent=${permanent ?? false}`, 'DELETE')));

server.tool('widget_reorder',
  'Sắp xếp lại thứ tự widgets trong một sidebar.',
  {
    sidebar_id: z.string().describe('ID sidebar'),
    order:      z.array(z.string()).describe('Mảng widget IDs theo thứ tự mới, vd: ["text-2","nav_menu-1","custom_html-3"]'),
  },
  async ({ sidebar_id, order }) => j(await api(`/widgets/${sidebar_id}/reorder`, 'POST', { order })));

server.tool('flatsome_custom_css_set',
  'Ghi Custom CSS của Flatsome. Chỉ truyền field cần thay đổi — field không truyền giữ nguyên.',
  {
    all_screens: z.string().optional().describe('CSS áp dụng cho tất cả màn hình'),
    tablets:     z.string().optional().describe('CSS cho tablets and down (max-width: 849px)'),
    mobile:      z.string().optional().describe('CSS cho mobile only (max-width: 549px)'),
  },
  async (p) => j(await api('/flatsome/custom-css', 'PUT', p)));

// URL INSPECT (v3.9.0)

server.tool('url_inspect',
  'Kiểm tra 1 URL trên website: HTTP status, redirect chain, canonical, robots meta, indexability. Dùng để debug URL bị chặn, redirect sai, canonical conflict.',
  { url: z.string().describe('URL cần kiểm tra, vd: https://traderviet.vn/san-forex/') },
  async ({ url }) => j(await api(`/url-inspect?url=${encodeURIComponent(url)}`)));

// CPT UI + GENERIC CPT TOOLS (v3.9.0)

server.tool('cptui_types',
  'Liệt kê tất cả Custom Post Types đăng ký bởi CPT UI plugin. Trả về slug, label, taxonomies gắn kèm, số lượng posts. include_builtin=true để kèm CPT từ plugins/themes khác.',
  { include_builtin: z.boolean().default(false).describe('true = kèm CPT từ plugins/themes khác (không phải CPT UI)') },
  async ({ include_builtin }) => j(await api(`/cptui/types?include_builtin=${include_builtin}`)));

server.tool('cptui_type_get',
  'Chi tiết 1 Custom Post Type: labels, supports, taxonomies, count publish/draft. Hoạt động cả với CPT từ CPT UI lẫn từ plugin khác.',
  { slug: z.string().describe('CPT slug, vd: vnbr_broker, product, ...') },
  async ({ slug }) => j(await api(`/cptui/types/${slug}`)));

server.tool('cptui_posts_list',
  'Danh sách posts của bất kỳ Custom Post Type nào. Dùng để xem posts của CPT từ Pixta, WooCommerce, hay plugin bất kỳ.',
  {
    slug:     z.string().describe('CPT slug, vd: vnbr_broker, ux_block, product...'),
    ...Pager,
    status:   z.enum(['publish','draft','any','pending','private']).default('publish'),
    search:   z.string().default(''),
    orderby:  z.enum(['date','title','modified','menu_order','rand']).default('date'),
    order:    z.enum(['ASC','DESC']).default('DESC'),
    tax:      z.string().optional().describe('Taxonomy slug để filter'),
    term:     z.string().optional().describe('Term slug để filter, dùng kèm tax'),
    term_id:  z.string().optional().describe('Term ID để filter'),
  },
  async ({ slug, ...p }) => j(await api(`/cptui/types/${slug}/posts?${qs(p)}`)));

server.tool('cptui_post_create',
  'Tạo post trong bất kỳ Custom Post Type nào. Hỗ trợ ACF fields, post meta, taxonomy terms, featured image, SEO.',
  {
    slug:      z.string().describe('CPT slug đích'),
    title:     z.string().describe('Tiêu đề'),
    content:   z.string().optional().describe('Nội dung HTML'),
    excerpt:   z.string().optional(),
    status:    z.enum(['publish','draft','pending']).default('draft'),
    acf:       z.record(z.string(), z.any()).optional().describe('ACF fields: {field_name: value}'),
    meta:      z.record(z.string(), z.any()).optional().describe('Post meta thường: {meta_key: value}'),
    terms:     z.record(z.string(), z.array(z.number())).optional().describe('Taxonomy terms: {"tax_slug": [term_id,...]}'),
    thumb_id:  z.number().optional().describe('Attachment ID cho featured image'),
    author_id: z.number().optional(),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async ({ slug, ...rest }) => j(await api(`/cptui/types/${slug}/posts`, 'POST', rest)));

server.tool('cptui_post_get',
  'Chi tiết đầy đủ 1 post của bất kỳ CPT nào: content, ACF fields, post meta, taxonomy terms, SEO. Dùng post ID (không cần biết CPT slug).',
  {
    slug: z.string().describe('CPT slug (dùng để route đúng endpoint)'),
    id:   z.number().describe('Post ID'),
  },
  async ({ slug, id }) => j(await api(`/cptui/types/${slug}/posts/${id}`)));

server.tool('cptui_post_update',
  'Cập nhật post của bất kỳ CPT nào. Chỉ truyền fields cần thay đổi.',
  {
    slug:    z.string().describe('CPT slug'),
    id:      z.number().describe('Post ID'),
    title:   z.string().optional(),
    content: z.string().optional(),
    excerpt: z.string().optional(),
    status:  z.enum(['publish','draft','pending','private']).optional(),
    acf:     z.record(z.string(), z.any()).optional(),
    meta:    z.record(z.string(), z.any()).optional(),
    terms:   z.record(z.string(), z.array(z.number())).optional(),
    thumb_id: z.number().optional(),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async ({ slug, id, ...rest }) => j(await api(`/cptui/types/${slug}/posts/${id}`, 'PUT', rest)));

server.tool('cptui_post_delete',
  'Xoá post CPT. Mặc định trash (khôi phục được). force=true xoá vĩnh viễn.',
  {
    slug:  z.string().describe('CPT slug'),
    id:    z.number().describe('Post ID'),
    force: z.boolean().default(false).describe('true = xoá vĩnh viễn, false = trash'),
  },
  async ({ slug, id, force }) => j(await api(`/cptui/types/${slug}/posts/${id}?force=${force}`, 'DELETE')));

server.tool('cptui_taxonomies',
  'Liệt kê tất cả taxonomies đăng ký bởi CPT UI. Trả về slug, label, hierarchical, object_types, số terms. include_builtin=true để kèm taxonomies từ plugins/themes khác.',
  { include_builtin: z.boolean().default(false) },
  async ({ include_builtin }) => j(await api(`/cptui/taxonomies?include_builtin=${include_builtin}`)));

server.tool('cptui_taxonomy_get',
  'Chi tiết 1 taxonomy + toàn bộ terms (id, name, slug, count, parent).',
  { slug: z.string().describe('Taxonomy slug, vd: broker_category, post_tag, ...') },
  async ({ slug }) => j(await api(`/cptui/taxonomies/${slug}`)));

server.tool('cptui_term_create',
  'Tạo term mới trong 1 taxonomy.',
  {
    slug:        z.string().describe('Taxonomy slug'),
    name:        z.string().describe('Tên term'),
    term_slug:   z.string().optional().describe('URL slug của term'),
    description: z.string().optional(),
    parent:      z.number().optional().describe('Term ID của parent (cho hierarchical taxonomy)'),
  },
  async ({ slug, name, term_slug, description, parent }) => j(await api(`/cptui/taxonomies/${slug}/terms`, 'POST', { name, slug: term_slug, description, parent })));

server.tool('cptui_term_update',
  'Cập nhật term trong taxonomy.',
  {
    slug:        z.string().describe('Taxonomy slug'),
    term_id:     z.number().describe('Term ID'),
    name:        z.string().optional(),
    term_slug:   z.string().optional().describe('URL slug mới'),
    description: z.string().optional(),
    parent:      z.number().optional(),
  },
  async ({ slug, term_id, term_slug, ...rest }) => j(await api(`/cptui/taxonomies/${slug}/terms/${term_id}`, 'PUT', { ...rest, slug: term_slug })));

server.tool('cptui_term_delete',
  'Xoá term khỏi taxonomy. Cẩn thận: xoá vĩnh viễn, không vào trash.',
  {
    slug:    z.string().describe('Taxonomy slug'),
    term_id: z.number().describe('Term ID cần xoá'),
  },
  async ({ slug, term_id }) => j(await api(`/cptui/taxonomies/${slug}/terms/${term_id}`, 'DELETE')));

// PIXTA REVIEW SYSTEM (v3.8.0)
// Plugin: vnbr-review-system — CPT cho broker, scam, sách

server.tool('pixta_schema',
  'Khám phá cấu trúc Pixta Review System: CPT slugs được detect tự động, ACF field groups, taxonomies + terms. Gọi trước khi dùng các tool pixta_* lần đầu để biết CPT slug thực tế.',
  {},
  async () => j(await api('/pixta/schema')));

server.tool('pixta_categories',
  'Lấy toàn bộ taxonomies + terms của các CPT Pixta (broker, scam, book). Dùng term ID cho param "cat" khi filter.',
  {},
  async () => j(await api('/pixta/categories')));

server.tool('pixta_brokers_list',
  'Danh sách broker/sàn giao dịch. Filter theo cat (term ID, dùng pixta_categories để xem ID), status, search. include_acf=true để kèm ACF fields (chậm hơn).',
  {
    ...Pager,
    status:      z.enum(['publish','draft','any']).default('publish'),
    search:      z.string().default(''),
    cat:         z.string().optional().describe('Term ID(s) taxonomy, vd: "4" (forex) hoặc "4,5"'),
    tax:         z.string().optional().describe('Taxonomy slug, dùng kèm term'),
    term:        z.string().optional().describe('Term slug, dùng kèm tax'),
    orderby:     z.enum(['date','title','modified','menu_order']).default('date'),
    order:       z.enum(['ASC','DESC']).default('DESC'),
    include_acf: z.boolean().default(false).describe('Kèm ACF fields trong list response (tăng latency)'),
  },
  async (p) => j(await api(`/pixta/brokers?${qs(p)}`)));

server.tool('pixta_broker_get',
  'Chi tiết đầy đủ 1 broker: title, content, excerpt, ACF fields, taxonomies, thumbnail, SEO. Dùng id từ pixta_brokers_list.',
  { id: z.number().describe('Broker post ID') },
  async ({ id }) => j(await api(`/pixta/brokers/${id}`)));

server.tool('pixta_broker_create',
  'Tạo broker mới. acf: object các ACF field {field_name: value}. terms: {"taxonomy_slug": [term_id,...]}. thumb_id: attachment ID ảnh đại diện.',
  {
    title:    z.string().describe('Tên broker'),
    content:  z.string().optional().describe('Nội dung bài review HTML'),
    excerpt:  z.string().optional().describe('Mô tả ngắn'),
    slug:     z.string().optional().describe('URL slug'),
    status:   z.enum(['publish','draft','pending']).default('draft'),
    acf:      z.record(z.string(), z.any()).optional().describe('ACF fields: {field_name: value, ...}'),
    terms:    z.record(z.string(), z.array(z.number())).optional().describe('Taxonomy terms: {"tax_slug": [term_id,...]}'),
    thumb_id: z.number().optional().describe('Attachment ID cho featured image'),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async (p) => j(await api('/pixta/brokers', 'POST', p)));

server.tool('pixta_broker_update',
  'Cập nhật broker. Chỉ cần truyền fields cần thay đổi. acf: object ACF fields cần update.',
  {
    id:       z.number().describe('Broker post ID'),
    title:    z.string().optional(),
    content:  z.string().optional(),
    excerpt:  z.string().optional(),
    slug:     z.string().optional(),
    status:   z.enum(['publish','draft','pending','private']).optional(),
    acf:      z.record(z.string(), z.any()).optional().describe('ACF fields cần cập nhật: {field_name: value, ...}'),
    terms:    z.record(z.string(), z.array(z.number())).optional(),
    thumb_id: z.number().optional(),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async ({ id, ...rest }) => j(await api(`/pixta/brokers/${id}`, 'PUT', rest)));

server.tool('pixta_broker_delete',
  'Xoá (trash) 1 broker theo ID.',
  { id: z.number().describe('Broker post ID') },
  async ({ id }) => j(await api(`/pixta/brokers/${id}`, 'DELETE')));

server.tool('pixta_scam_list',
  'Danh sách scam/blacklist entries. Filter theo status, search. include_acf=true để kèm ACF fields.',
  {
    ...Pager,
    status:      z.enum(['publish','draft','any']).default('publish'),
    search:      z.string().default(''),
    orderby:     z.enum(['date','title','modified']).default('date'),
    order:       z.enum(['ASC','DESC']).default('DESC'),
    include_acf: z.boolean().default(false),
  },
  async (p) => j(await api(`/pixta/scam?${qs(p)}`)));

server.tool('pixta_scam_get',
  'Chi tiết đầy đủ 1 scam entry: content, ACF fields, thumbnails, SEO.',
  { id: z.number().describe('Scam entry post ID') },
  async ({ id }) => j(await api(`/pixta/scam/${id}`)));

server.tool('pixta_scam_create',
  'Thêm scam/blacklist entry mới. acf: ACF fields, terms: taxonomy terms.',
  {
    title:    z.string().describe('Tên sàn/đơn vị scam'),
    content:  z.string().optional(),
    excerpt:  z.string().optional(),
    slug:     z.string().optional(),
    status:   z.enum(['publish','draft','pending']).default('draft'),
    acf:      z.record(z.string(), z.any()).optional(),
    terms:    z.record(z.string(), z.array(z.number())).optional(),
    thumb_id: z.number().optional(),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async (p) => j(await api('/pixta/scam', 'POST', p)));

server.tool('pixta_scam_update',
  'Cập nhật scam entry.',
  {
    id:       z.number().describe('Scam entry post ID'),
    title:    z.string().optional(),
    content:  z.string().optional(),
    excerpt:  z.string().optional(),
    slug:     z.string().optional(),
    status:   z.enum(['publish','draft','pending','private']).optional(),
    acf:      z.record(z.string(), z.any()).optional(),
    terms:    z.record(z.string(), z.array(z.number())).optional(),
    thumb_id: z.number().optional(),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async ({ id, ...rest }) => j(await api(`/pixta/scam/${id}`, 'PUT', rest)));

server.tool('pixta_scam_delete',
  'Xoá (trash) scam entry.',
  { id: z.number().describe('Scam entry post ID') },
  async ({ id }) => j(await api(`/pixta/scam/${id}`, 'DELETE')));

server.tool('pixta_books_list',
  'Danh sách sách trong Pixta Book CPT. Filter theo status, search, cat.',
  {
    ...Pager,
    status:      z.enum(['publish','draft','any']).default('publish'),
    search:      z.string().default(''),
    cat:         z.string().optional().describe('Term ID(s) của taxonomy sách'),
    orderby:     z.enum(['date','title','modified']).default('date'),
    order:       z.enum(['ASC','DESC']).default('DESC'),
    include_acf: z.boolean().default(false),
  },
  async (p) => j(await api(`/pixta/books?${qs(p)}`)));

server.tool('pixta_book_get',
  'Chi tiết đầy đủ 1 cuốn sách: content, ACF fields (tác giả, năm XB, link mua...), thumbnail, SEO.',
  { id: z.number().describe('Book post ID') },
  async ({ id }) => j(await api(`/pixta/books/${id}`)));

server.tool('pixta_book_create',
  'Tạo book/sách mới trong Pixta.',
  {
    title:    z.string().describe('Tên sách'),
    content:  z.string().optional().describe('Nội dung giới thiệu sách'),
    excerpt:  z.string().optional(),
    slug:     z.string().optional(),
    status:   z.enum(['publish','draft','pending']).default('draft'),
    acf:      z.record(z.string(), z.any()).optional().describe('ACF fields: tác giả, năm XB, link Amazon, rating...'),
    terms:    z.record(z.string(), z.array(z.number())).optional(),
    thumb_id: z.number().optional(),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async (p) => j(await api('/pixta/books', 'POST', p)));

server.tool('pixta_book_update',
  'Cập nhật thông tin sách.',
  {
    id:       z.number().describe('Book post ID'),
    title:    z.string().optional(),
    content:  z.string().optional(),
    excerpt:  z.string().optional(),
    slug:     z.string().optional(),
    status:   z.enum(['publish','draft','pending','private']).optional(),
    acf:      z.record(z.string(), z.any()).optional(),
    terms:    z.record(z.string(), z.array(z.number())).optional(),
    thumb_id: z.number().optional(),
    seo_title:       z.string().optional(),
    seo_description: z.string().optional(),
    focus_keyword:   z.string().optional(),
  },
  async ({ id, ...rest }) => j(await api(`/pixta/books/${id}`, 'PUT', rest)));

server.tool('pixta_book_delete',
  'Xoá (trash) sách.',
  { id: z.number().describe('Book post ID') },
  async ({ id }) => j(await api(`/pixta/books/${id}`, 'DELETE')));

server.tool('pixta_acf_get',
  'Đọc tất cả ACF field values của bất kỳ post ID nào. with_schema=true để kèm field definitions (key, label, type). Dùng được cho broker, scam, book, post, page...',
  {
    id:          z.number().describe('Post ID bất kỳ'),
    with_schema: z.boolean().default(false).describe('true = kèm ACF field schema (key, label, type, required)'),
  },
  async ({ id, with_schema }) => j(await api(`/pixta/acf/${id}?with_schema=${with_schema}`)));

server.tool('pixta_acf_set',
  'Ghi hàng loạt ACF fields cho bất kỳ post ID nào. Truyền object acf: {field_name: value}. Hỗ trợ text, number, image, select, repeater...',
  {
    id:  z.number().describe('Post ID'),
    acf: z.record(z.string(), z.any()).describe('Object ACF fields cần ghi: {field_name: value, ...}'),
  },
  async ({ id, acf }) => j(await api(`/pixta/acf/${id}`, 'PUT', { acf })));

// CONTENT OPERATIONS (v4.1.0)

server.tool('content_slug_update',
  'Đổi slug (URL) của bất kỳ post/page/product/CPT. Tự động tạo redirect 301 RankMath từ slug cũ sang slug mới.',
  {
    id:   z.number().describe('Post ID'),
    slug: z.string().describe('Slug mới — VD: "san-pham-moi", "broker-acb-review"'),
  },
  async ({ id, slug }) => j(await api(`/content/slug/${id}`, 'PUT', { slug })));

server.tool('content_slug_bulk',
  'Đổi slug hàng loạt cho nhiều posts cùng lúc. Truyền array items [{id, slug}].',
  {
    items: z.array(z.object({
      id:   z.number().describe('Post ID'),
      slug: z.string().describe('Slug mới'),
    })).describe('Danh sách {id, slug} cần đổi'),
  },
  async (b) => j(await api('/content/slug/bulk', 'POST', b)));

server.tool('content_flush_permalinks',
  'Flush rewrite rules WordPress. Dùng sau khi: đổi slug, thêm CPT, thay đổi cấu trúc URL permalink. Tương đương click "Lưu thay đổi" ở Settings > Permalinks.',
  {},
  async () => j(await api('/content/flush-permalinks', 'POST')));

server.tool('content_clone',
  'Clone (duplicate) bất kỳ post/page/product/CPT. Copy toàn bộ: content, meta, taxonomy terms, featured image. Trả về ID bản clone mới.',
  {
    id:     z.number().describe('Post ID cần clone'),
    title:  z.string().optional().describe('Tiêu đề bản clone (default: "<tên cũ> (Copy)")'),
    status: z.enum(['draft','publish','pending','private']).default('draft').describe('Status của bản clone'),
    slug:   z.string().optional().describe('Slug mới (bỏ trống = tự sinh)'),
  },
  async ({ id, ...rest }) => j(await api(`/content/clone/${id}`, 'POST', rest)));

server.tool('content_restore',
  'Khôi phục post từ trash. Trả về status mới sau khi restore.',
  {
    id:     z.number().describe('Post ID (đang ở trong trash)'),
    status: z.enum(['draft','publish','pending']).default('draft').describe('Status sau khi restore'),
  },
  async ({ id, ...rest }) => j(await api(`/content/restore/${id}`, 'POST', rest)));

server.tool('content_status_bulk',
  'Đổi status hàng loạt. Có 2 mode: (1) truyền ids cụ thể, (2) lọc theo post_type+current_status+date_range.',
  {
    new_status:     z.enum(['publish','draft','pending','private','trash']).describe('Status mới'),
    ids:            z.array(z.number()).optional().describe('Mode 1: danh sách ID cụ thể'),
    post_type:      z.string().optional().describe('Mode 2: post_type cần lọc (post, page, product, ...)'),
    current_status: z.string().optional().describe('Mode 2: chỉ đổi các post đang ở status này'),
    date_before:    z.string().optional().describe('Mode 2: chỉ lấy posts trước ngày này (YYYY-MM-DD)'),
    date_after:     z.string().optional().describe('Mode 2: chỉ lấy posts sau ngày này'),
    cat:            z.number().optional().describe('Mode 2: lọc theo category ID'),
    limit:          z.number().optional().describe('Mode 2: tối đa bao nhiêu posts (max 500)'),
  },
  async (b) => j(await api('/content/status/bulk', 'POST', b)));

server.tool('content_date_update',
  'Đổi ngày publish/modified của post. Dùng để "refresh" nội dung cũ lên Google. Để trống date = set = now.',
  {
    id:   z.number().describe('Post ID'),
    date: z.string().optional().describe('Ngày mới — VD: "2025-06-15 10:00:00". Bỏ trống = bây giờ'),
  },
  async ({ id, ...rest }) => j(await api(`/content/date/${id}`, 'PUT', rest)));

server.tool('content_author_bulk',
  'Reassign tác giả hàng loạt. Mode 1: danh sách IDs + to_author_id. Mode 2: from_author_id + to_author_id.',
  {
    to_author_id:   z.number().describe('ID tác giả mới'),
    ids:            z.array(z.number()).optional().describe('Mode 1: danh sách post IDs'),
    from_author_id: z.number().optional().describe('Mode 2: chuyển tất cả bài của author ID này'),
    post_type:      z.string().optional().describe('Mode 2: post_type (post, page, product...)'),
    limit:          z.number().optional().describe('Mode 2: tối đa bao nhiêu posts (max 1000)'),
  },
  async (b) => j(await api('/content/author/bulk', 'POST', b)));

server.tool('content_parent_bulk',
  'Đổi parent hàng loạt cho pages/posts. parent_id=0 = bỏ parent.',
  {
    ids:       z.array(z.number()).describe('Danh sách page/post IDs'),
    parent_id: z.number().describe('ID trang cha mới. 0 = bỏ parent (top-level)'),
  },
  async (b) => j(await api('/content/parent/bulk', 'POST', b)));

server.tool('content_meta_get',
  'Đọc tất cả post meta (custom fields) của 1 post. Có thể lọc theo prefix. Trả về object {key: value}.',
  {
    id:     z.number().describe('Post ID'),
    prefix: z.string().optional().describe('Lọc chỉ các key bắt đầu bằng prefix — VD: "rank_math_", "_acf_"'),
  },
  async ({ id, prefix }) => j(await api(`/content/meta/${id}${prefix ? '?prefix=' + encodeURIComponent(prefix) : ''}`)));

server.tool('content_meta_set',
  'Ghi hoặc xóa post meta. set: {key: value} để ghi, delete: ["key"] để xóa.',
  {
    id:     z.number().describe('Post ID'),
    set:    z.record(z.string(), z.any()).optional().describe('Object meta cần ghi: {key: value, ...}'),
    delete: z.array(z.string()).optional().describe('Array keys cần xóa'),
  },
  async ({ id, ...rest }) => j(await api(`/content/meta/${id}`, 'PUT', rest)));

server.tool('term_list',
  'Lấy danh sách terms của bất kỳ taxonomy (category, post_tag, product_cat, ...). Có tìm kiếm, filter parent.',
  {
    taxonomy:   z.string().describe('Tên taxonomy — VD: category, post_tag, product_cat, product_tag'),
    per_page:   z.number().default(100),
    page:       z.number().default(1),
    search:     z.string().optional().describe('Tìm theo tên term'),
    parent:     z.number().optional().describe('Lọc theo parent term ID (0 = top-level)'),
    hide_empty: z.boolean().default(false).describe('true = ẩn các term không có bài'),
    orderby:    z.enum(['name','count','id','slug','term_id']).default('name'),
    order:      z.enum(['ASC','DESC']).default('ASC'),
  },
  async ({ taxonomy, ...rest }) => j(await api(`/content/terms/${taxonomy}?${qs(rest)}`)));

server.tool('term_get',
  'Chi tiết 1 term: name, slug, description, parent, count, url, SEO RankMath.',
  {
    taxonomy: z.string().describe('Tên taxonomy'),
    term_id:  z.number().describe('Term ID'),
  },
  async ({ taxonomy, term_id }) => j(await api(`/content/terms/${taxonomy}/${term_id}`)));

server.tool('term_create',
  'Tạo term mới trong taxonomy bất kỳ (category, tag, product_cat...). Hỗ trợ SEO RankMath.',
  {
    taxonomy:    z.string().describe('Tên taxonomy — VD: category, post_tag, product_cat'),
    name:        z.string().describe('Tên term'),
    slug:        z.string().optional().describe('Slug (bỏ trống = tự sinh từ name)'),
    description: z.string().optional(),
    parent:      z.number().optional().describe('ID term cha (0 = không có cha)'),
    seo_title:   z.string().optional().describe('RankMath SEO title'),
    seo_desc:    z.string().optional().describe('RankMath meta description'),
    focus_kw:    z.string().optional().describe('RankMath focus keyword'),
  },
  async ({ taxonomy, ...rest }) => j(await api(`/content/terms/${taxonomy}`, 'POST', rest)));

server.tool('term_update',
  'Cập nhật term: name, slug, description, parent, SEO. Slug đổi sẽ thay đổi URL term.',
  {
    taxonomy:    z.string().describe('Tên taxonomy'),
    term_id:     z.number().describe('Term ID'),
    name:        z.string().optional(),
    slug:        z.string().optional().describe('Slug mới — sẽ đổi URL của term'),
    description: z.string().optional(),
    parent:      z.number().optional().describe('ID term cha mới (0 = top-level)'),
    seo_title:   z.string().optional(),
    seo_desc:    z.string().optional(),
    focus_kw:    z.string().optional(),
  },
  async ({ taxonomy, term_id, ...rest }) => j(await api(`/content/terms/${taxonomy}/${term_id}`, 'PUT', rest)));

server.tool('term_delete',
  'Xóa term vĩnh viễn (không có trash). Các post thuộc term này sẽ mất term đó.',
  {
    taxonomy: z.string().describe('Tên taxonomy'),
    term_id:  z.number().describe('Term ID cần xóa'),
  },
  async ({ taxonomy, term_id }) => j(await api(`/content/terms/${taxonomy}/${term_id}`, 'DELETE')));

// CONTACT FORM 7 (v4.0.0)

server.tool('cf7_forms_list',
  'Danh sách tất cả form Contact Form 7. Trả về id, title, slug, shortcode.',
  {
    per_page: z.number().default(20),
    page:     z.number().default(1),
    search:   z.string().default('').describe('Tìm theo tên form'),
  },
  async (p) => j(await api(`/cf7/forms?per_page=${p.per_page}&page=${p.page}&search=${encodeURIComponent(p.search)}`)));

server.tool('cf7_form_get',
  'Đọc chi tiết 1 form CF7: form body (HTML shortcodes), mail config, messages, additional settings.',
  { id: z.number().describe('Form ID') },
  async ({ id }) => j(await api(`/cf7/forms/${id}`)));

server.tool('cf7_form_create',
  'Tạo form CF7 mới. Truyền title và form body (HTML shortcodes của CF7). Tùy chọn: mail config, messages.',
  {
    title:               z.string().describe('Tên form'),
    form:                z.string().optional().describe('Form body — HTML với CF7 shortcodes. VD: [text* your-name] [email* your-email] [submit "Gửi"]'),
    mail:                z.object({
      to:      z.string().optional(),
      from:    z.string().optional(),
      subject: z.string().optional(),
      body:    z.string().optional(),
    }).optional().describe('Cấu hình mail gửi đi'),
    messages:            z.record(z.string(), z.string()).optional().describe('Custom messages: mail_sent_ok, mail_sent_ng, validation_error, ...'),
    additional_settings: z.string().optional().describe('Additional settings (text)'),
  },
  async (p) => j(await api('/cf7/forms', 'POST', p)));

server.tool('cf7_form_update',
  'Cập nhật form CF7. Chỉ truyền fields cần thay đổi.',
  {
    id:                  z.number().describe('Form ID'),
    title:               z.string().optional(),
    form:                z.string().optional().describe('Form body HTML mới'),
    mail:                z.object({
      to:      z.string().optional(),
      from:    z.string().optional(),
      subject: z.string().optional(),
      body:    z.string().optional(),
      active:  z.boolean().optional(),
    }).optional(),
    mail_2:              z.object({
      to:      z.string().optional(),
      from:    z.string().optional(),
      subject: z.string().optional(),
      body:    z.string().optional(),
      active:  z.boolean().optional(),
    }).optional().describe('Mail phụ (CC admin, ...)'),
    messages:            z.record(z.string(), z.string()).optional(),
    additional_settings: z.string().optional(),
    locale:              z.string().optional().describe('VD: vi, en_US'),
  },
  async ({ id, ...rest }) => j(await api(`/cf7/forms/${id}`, 'PUT', rest)));

server.tool('cf7_form_delete',
  'Xoá (trash) 1 form CF7.',
  { id: z.number().describe('Form ID') },
  async ({ id }) => j(await api(`/cf7/forms/${id}`, 'DELETE')));

server.tool('cf7_form_duplicate',
  'Clone form CF7 sang form mới với tiêu đề khác. Giữ nguyên form body, mail config, messages.',
  {
    id:    z.number().describe('Form ID cần clone'),
    title: z.string().optional().describe('Tên form mới (default: "<tên cũ> (Copy)")'),
  },
  async ({ id, ...rest }) => j(await api(`/cf7/forms/${id}/duplicate`, 'POST', rest)));

server.tool('cf7_fields_list',
  'Liệt kê tất cả input fields (tags) trong 1 form CF7: type, name, required, options.',
  { id: z.number().describe('Form ID') },
  async ({ id }) => j(await api(`/cf7/forms/${id}/fields`)));

server.tool('cf7_mail_get',
  'Đọc cấu hình mail (mail và mail_2) của 1 form CF7: to, from, subject, body, active.',
  { id: z.number().describe('Form ID') },
  async ({ id }) => j(await api(`/cf7/forms/${id}/mail`)));

server.tool('cf7_mail_update',
  'Cập nhật cấu hình mail của form CF7. Dùng để đổi email nhận, subject, template body mail.',
  {
    id:     z.number().describe('Form ID'),
    mail:   z.object({
      to:           z.string().optional().describe('Email nhận — VD: admin@site.com'),
      from:         z.string().optional().describe('Tên và email gửi — VD: [your-name] <wordpress@site.com>'),
      subject:      z.string().optional(),
      body:         z.string().optional().describe('Nội dung mail — dùng [field-name] để chèn giá trị field'),
      additional_headers: z.string().optional(),
      attachments:  z.string().optional(),
      use_html:     z.boolean().optional(),
      active:       z.boolean().optional(),
    }).optional(),
    mail_2: z.object({
      to:      z.string().optional(),
      from:    z.string().optional(),
      subject: z.string().optional(),
      body:    z.string().optional(),
      active:  z.boolean().optional().describe('true để bật mail phụ'),
    }).optional(),
  },
  async ({ id, ...rest }) => j(await api(`/cf7/forms/${id}/mail`, 'PUT', rest)));

server.tool('cf7_submissions_list',
  'Danh sách submissions (entries) đã gửi qua CF7. Yêu cầu plugin Flamingo. Lọc theo form_id nếu cần.',
  {
    per_page: z.number().default(20),
    page:     z.number().default(1),
    form_id:  z.number().optional().describe('Lọc submissions của form cụ thể (0 = tất cả)'),
  },
  async (p) => {
    let url = `/cf7/submissions?per_page=${p.per_page}&page=${p.page}`;
    if (p.form_id) url += `&form_id=${p.form_id}`;
    return j(await api(url));
  });

server.tool('cf7_submission_get',
  'Đọc chi tiết 1 submission CF7: tất cả fields đã submit, từ ai, ngày giờ, IP.',
  { id: z.number().describe('Submission ID (Flamingo post ID)') },
  async ({ id }) => j(await api(`/cf7/submissions/${id}`)));

server.tool('cf7_submission_spam',
  'Đánh dấu submission là spam (spam=true) hoặc bỏ đánh dấu spam (spam=false).',
  {
    id:   z.number().describe('Submission ID'),
    spam: z.boolean().default(true),
  },
  async ({ id, spam }) => j(await api(`/cf7/submissions/${id}/spam`, 'PUT', { spam })));

server.tool('cf7_submission_delete',
  'Xoá (trash) 1 submission CF7.',
  { id: z.number().describe('Submission ID') },
  async ({ id }) => j(await api(`/cf7/submissions/${id}`, 'DELETE')));

// CONTACT REQUESTS — Snippet 17 (KH Liên hệ giá)

server.tool('contact_requests_list',
  'Danh sách yêu cầu liên hệ giá từ khách hàng (Snippet 17). Lọc theo status, sản phẩm, tìm kiếm tên/SĐT.',
  {
    status:     z.enum(['all','new','contacted']).default('all').describe('all=tất cả | new=chưa liên hệ | contacted=đã liên hệ'),
    per_page:   z.number().default(20),
    page:       z.number().default(1),
    product_id: z.number().optional().describe('Lọc theo ID sản phẩm'),
    search:     z.string().optional().describe('Tìm theo tên hoặc SĐT'),
  },
  async ({ status, per_page, page, product_id, search }) => {
    const q = new URLSearchParams({ status, per_page, page });
    if (product_id) q.set('product_id', product_id);
    if (search)     q.set('search', search);
    return j(await api(`/contact-requests?${q}`));
  });

server.tool('contact_request_get',
  'Chi tiết 1 yêu cầu liên hệ.',
  { id: z.number().describe('Request ID') },
  async ({ id }) => j(await api(`/contact-requests/${id}`)));

server.tool('contact_request_update',
  'Cập nhật yêu cầu liên hệ: đổi trạng thái (new/contacted), sửa tên, SĐT, ghi chú.',
  {
    id:              z.number().describe('Request ID'),
    status:          z.enum(['new','contacted']).optional().describe('new=chưa gọi | contacted=đã gọi'),
    customer_name:   z.string().optional(),
    customer_phone:  z.string().optional(),
    customer_note:   z.string().optional(),
  },
  async ({ id, ...b }) => j(await api(`/contact-requests/${id}`, 'PUT', b)));

server.tool('contact_request_delete',
  'Xóa yêu cầu liên hệ. force=false = trash, force=true = xóa vĩnh viễn.',
  {
    id:    z.number().describe('Request ID'),
    force: z.boolean().default(false),
  },
  async ({ id, force }) => j(await api(`/contact-requests/${id}?force=${force}`, 'DELETE')));

server.tool('contact_requests_bulk_status',
  'Đổi trạng thái hàng loạt yêu cầu liên hệ.',
  {
    ids:    z.array(z.number()).describe('Danh sách Request ID'),
    status: z.enum(['new','contacted']).describe('Trạng thái mới'),
  },
  async (b) => j(await api('/contact-requests/bulk-status', 'POST', b)));

server.tool('contact_request_create',
  'Tạo yêu cầu liên hệ thủ công (ví dụ: khách gọi điện trực tiếp).',
  {
    customer_name:  z.string().describe('Họ tên khách'),
    customer_phone: z.string().describe('Số điện thoại'),
    customer_note:  z.string().optional().describe('Ghi chú / yêu cầu'),
    product_id:     z.number().optional().describe('ID sản phẩm quan tâm'),
    status:         z.enum(['new','contacted']).default('new'),
    ip:             z.string().optional().describe('Nguồn tạo — VD: "manual", "zalo"'),
  },
  async (b) => j(await api('/contact-requests', 'POST', b)));

// WOOCOMMERCE EXTENDED (v4.6.5)
// Tất cả tools guard bằng WC — trả lỗi graceful nếu WC chưa cài

server.tool('payment_gateways_list', 'Liệt kê tất cả payment gateways (COD, banking, Momo, v.v.): id, title, enabled, method_title.', {},
  async () => j(await api('/wc/gateways')));

server.tool('payment_gateway_get', 'Chi tiết 1 payment gateway: settings đầy đủ, enabled, recipient.',
  { id: z.string().describe('Gateway ID, vd: cod, bacs, cheque') },
  async ({ id }) => j(await api(`/wc/gateways/${id}`)));

server.tool('payment_gateway_update', 'Bật/tắt hoặc cập nhật payment gateway.',
  {
    id:          z.string().describe('Gateway ID'),
    enabled:     z.boolean().optional(),
    title:       z.string().optional(),
    description: z.string().optional(),
    instructions:z.string().optional().describe('Hiển thị sau khi thanh toán'),
  },
  async ({ id, ...body }) => j(await api(`/wc/gateways/${id}`, 'PUT', body)));

server.tool('shipping_zones_list', 'Liệt kê tất cả shipping zones (khu vực giao hàng) + số phương thức vận chuyển mỗi zone.', {},
  async () => j(await api('/wc/shipping/zones')));

server.tool('shipping_zone_get', 'Chi tiết shipping zone: địa điểm áp dụng + danh sách shipping methods trong zone.',
  { id: z.number().describe('Zone ID (0 = Rest of World)') },
  async ({ id }) => j(await api(`/wc/shipping/zones/${id}`)));

server.tool('shipping_zone_create', 'Tạo shipping zone mới.',
  { name: z.string().describe('Tên zone'), order: z.number().optional() },
  async (b) => j(await api('/wc/shipping/zones', 'POST', b)));

server.tool('shipping_zone_update', 'Cập nhật tên/thứ tự shipping zone.',
  { id: z.number(), name: z.string().optional(), order: z.number().optional() },
  async ({ id, ...b }) => j(await api(`/wc/shipping/zones/${id}`, 'PUT', b)));

server.tool('shipping_zone_delete', 'Xóa shipping zone.',
  { id: z.number() },
  async ({ id }) => j(await api(`/wc/shipping/zones/${id}`, 'DELETE')));

server.tool('shipping_method_update', 'Cập nhật settings của 1 shipping method trong zone (vd: bật flat rate, đổi phí).',
  {
    zone_id:     z.number().describe('Zone ID'),
    instance_id: z.number().describe('Method instance ID'),
    settings:    z.record(z.string(), z.any()).describe('Settings cần update, vd: {cost: "30000", title: "Giao nhanh"}'),
  },
  async ({ zone_id, instance_id, settings }) => j(await api(`/wc/shipping/zones/${zone_id}/methods/${instance_id}`, 'PUT', settings)));

server.tool('tax_classes_list', 'Liệt kê các tax class (standard, reduced-rate, zero-rate và custom).', {},
  async () => j(await api('/wc/tax/classes')));

server.tool('tax_rates_list', 'Liệt kê tax rates. Filter theo class.',
  { class: z.string().optional().describe('Tax class slug: standard, reduced-rate, zero-rate') },
  async ({ class: cls }) => j(await api(`/wc/tax/rates${cls ? '?class=' + cls : ''}`)));

server.tool('tax_rate_create', 'Tạo tax rate mới.',
  {
    country:  z.string().describe('Mã quốc gia 2 chữ, vd: VN'),
    state:    z.string().optional().describe('Mã tỉnh/thành'),
    rate:     z.number().describe('Phần trăm thuế, vd: 10'),
    name:     z.string().describe('Tên tax, vd: VAT'),
    class:    z.string().optional().describe('Tax class slug'),
    shipping: z.boolean().optional().describe('Áp dụng cho shipping không'),
  },
  async (b) => j(await api('/wc/tax/rates', 'POST', b)));

server.tool('tax_rate_update', 'Cập nhật tax rate.',
  {
    id:      z.number().describe('Tax rate ID'),
    country: z.string().optional(),
    state:   z.string().optional(),
    rate:    z.number().optional(),
    name:    z.string().optional(),
    class:   z.string().optional(),
  },
  async ({ id, ...b }) => j(await api(`/wc/tax/rates/${id}`, 'PUT', b)));

server.tool('tax_rate_delete', 'Xóa tax rate.',
  { id: z.number() },
  async ({ id }) => j(await api(`/wc/tax/rates/${id}`, 'DELETE')));

server.tool('product_variations_list', 'Liệt kê variations của sản phẩm variable: sku, giá, stock, attributes.',
  { id: z.number().describe('Product ID') },
  async ({ id }) => j(await api(`/products/${id}/variations`)));

server.tool('product_variation_get', 'Chi tiết 1 variation: giá, stock, weight, dimensions, attributes.',
  { id: z.number().describe('Product ID'), variation_id: z.number() },
  async ({ id, variation_id }) => j(await api(`/products/${id}/variations/${variation_id}`)));

server.tool('product_variation_create', 'Tạo variation mới cho sản phẩm variable.',
  {
    id:             z.number().describe('Product ID'),
    regular_price:  z.string().optional(),
    sale_price:     z.string().optional(),
    sku:            z.string().optional(),
    stock_quantity: z.number().optional(),
    stock_status:   z.enum(['instock','outofstock','onbackorder']).optional(),
    attributes:     z.record(z.string(), z.string()).optional().describe('{"pa_color":"red","pa_size":"M"}'),
    status:         z.enum(['publish','private']).optional(),
  },
  async ({ id, ...b }) => j(await api(`/products/${id}/variations`, 'POST', b)));

server.tool('product_variation_update', 'Cập nhật variation: giá, stock, status.',
  {
    id:             z.number().describe('Product ID'),
    variation_id:   z.number(),
    regular_price:  z.string().optional(),
    sale_price:     z.string().optional(),
    sku:            z.string().optional(),
    stock_quantity: z.number().optional(),
    stock_status:   z.enum(['instock','outofstock','onbackorder']).optional(),
    status:         z.enum(['publish','private']).optional(),
  },
  async ({ id, variation_id, ...b }) => j(await api(`/products/${id}/variations/${variation_id}`, 'PUT', b)));

server.tool('product_variation_delete', 'Xóa variation. force=true = xóa vĩnh viễn.',
  { id: z.number(), variation_id: z.number(), force: z.boolean().optional() },
  async ({ id, variation_id, force }) => j(await api(`/products/${id}/variations/${variation_id}?force=${force ?? false}`, 'DELETE')));

server.tool('product_attributes_list', 'Liệt kê tất cả product attributes (pa_color, pa_size, v.v.).',
  {},
  async () => j(await api('/wc/attributes')));

server.tool('product_attribute_create', 'Tạo product attribute mới (vd: Màu sắc, Kích thước).',
  {
    label:   z.string().describe('Tên hiển thị, vd: Màu sắc'),
    name:    z.string().optional().describe('Slug, vd: color (tạo pa_color)'),
    type:    z.enum(['select','text','color','image','button']).optional(),
    orderby: z.enum(['menu_order','name','name_num','id']).optional(),
    public:  z.boolean().optional().describe('Hiển thị trên trang sản phẩm không'),
  },
  async (b) => j(await api('/wc/attributes', 'POST', b)));

server.tool('product_attribute_update', 'Cập nhật product attribute.',
  {
    id:    z.number().describe('Attribute ID'),
    label: z.string().optional(),
    name:  z.string().optional(),
    type:  z.string().optional(),
  },
  async ({ id, ...b }) => j(await api(`/wc/attributes/${id}`, 'PUT', b)));

server.tool('product_attribute_delete', 'Xóa product attribute (và taxonomy pa_xxx).',
  { id: z.number() },
  async ({ id }) => j(await api(`/wc/attributes/${id}`, 'DELETE')));

server.tool('product_attribute_terms_list', 'Liệt kê terms của 1 attribute (vd: Đỏ, Xanh, Vàng của pa_color).',
  { attribute: z.string().describe('Attribute name/slug, vd: color hoặc pa_color') },
  async ({ attribute }) => j(await api(`/wc/attributes/${attribute.replace('pa_','')}/terms`)));

server.tool('product_attribute_term_create', 'Thêm term vào attribute (vd: thêm màu "Đỏ" vào pa_color).',
  {
    attribute: z.string().describe('Attribute slug'),
    name:      z.string().describe('Tên term'),
    slug:      z.string().optional(),
  },
  async ({ attribute, ...b }) => j(await api(`/wc/attributes/${attribute}/terms`, 'POST', b)));

server.tool('product_attribute_term_update', 'Cập nhật term của attribute.',
  {
    attribute: z.string().describe('Attribute slug'),
    term_id:   z.number(),
    name:      z.string().optional(),
    slug:      z.string().optional(),
  },
  async ({ attribute, term_id, ...b }) => j(await api(`/wc/attributes/${attribute}/terms/${term_id}`, 'PUT', b)));

server.tool('product_reviews_list', 'Danh sách reviews sản phẩm. Filter theo product_id, status.',
  {
    ...Pager,
    product_id: z.number().optional().describe('Lọc theo sản phẩm'),
    status:     z.enum(['approve','hold','spam','trash']).optional(),
  },
  async (p) => j(await api(`/wc/reviews?${qs(p)}`)));

server.tool('product_review_get', 'Chi tiết 1 review.',
  { id: z.number().describe('Comment/Review ID') },
  async ({ id }) => j(await api(`/wc/reviews/${id}`)));

server.tool('product_review_update', 'Cập nhật review: nội dung, rating, status (approve/hold/spam).',
  {
    id:      z.number(),
    content: z.string().optional(),
    rating:  z.number().min(1).max(5).optional(),
    status:  z.enum(['approve','hold','spam','trash']).optional(),
  },
  async ({ id, ...b }) => j(await api(`/wc/reviews/${id}`, 'PUT', b)));

server.tool('product_review_delete', 'Xóa review. force=true = xóa vĩnh viễn.',
  { id: z.number(), force: z.boolean().optional() },
  async ({ id, force }) => j(await api(`/wc/reviews/${id}?force=${force ?? false}`, 'DELETE')));

server.tool('product_tags_list', 'Liệt kê tags sản phẩm WooCommerce.',
  { search: z.string().optional(), per_page: z.number().default(50) },
  async (p) => j(await api(`/wc/tags?${qs(p)}`)));

server.tool('product_tag_create', 'Tạo tag sản phẩm mới.',
  { name: z.string(), slug: z.string().optional(), description: z.string().optional() },
  async (b) => j(await api('/wc/tags', 'POST', b)));

server.tool('product_tag_update', 'Cập nhật tag sản phẩm.',
  { id: z.number(), name: z.string().optional(), slug: z.string().optional(), description: z.string().optional() },
  async ({ id, ...b }) => j(await api(`/wc/tags/${id}`, 'PUT', b)));

server.tool('product_stock_update', 'Bulk cập nhật stock nhiều sản phẩm. Truyền array items [{id, stock_quantity?, stock_status?}].',
  {
    items: z.array(z.object({
      id:             z.number().describe('Product ID'),
      stock_quantity: z.number().optional(),
      stock_status:   z.enum(['instock','outofstock','onbackorder']).optional(),
    })).describe('[{id:1,stock_quantity:10},{id:2,stock_status:"outofstock"}]'),
  },
  async (b) => j(await api('/products/stock/bulk', 'POST', b)));

server.tool('product_duplicate', 'Duplicate sản phẩm WooCommerce sang sản phẩm mới (draft). Trả về ID sản phẩm mới.',
  { id: z.number().describe('Product ID cần nhân đôi') },
  async ({ id }) => j(await api(`/products/${id}/duplicate`, 'POST')));

server.tool('bulk_products_delete', 'Xóa nhiều sản phẩm. force=true = vĩnh viễn, false = trash.',
  {
    ids:   z.array(z.number()).describe('Danh sách Product ID cần xóa'),
    force: z.boolean().optional().describe('true = xóa vĩnh viễn; false = trash (mặc định)'),
  },
  async (b) => j(await api('/products/bulk/delete', 'POST', b)));

server.tool('order_notes_list', 'Danh sách ghi chú của đơn hàng. type: any|customer|internal.',
  {
    id:   z.number().describe('Order ID'),
    type: z.enum(['any','customer','internal']).optional(),
  },
  async ({ id, type }) => j(await api(`/orders/${id}/notes${type ? '?type='+type : ''}`)));

server.tool('order_note_create', 'Thêm ghi chú vào đơn hàng. customer_note=true thì gửi email cho KH.',
  {
    id:            z.number().describe('Order ID'),
    note:          z.string().describe('Nội dung ghi chú'),
    customer_note: z.boolean().optional().describe('true = gửi email thông báo cho khách hàng'),
  },
  async ({ id, ...b }) => j(await api(`/orders/${id}/notes`, 'POST', b)));

server.tool('order_note_delete', 'Xóa ghi chú khỏi đơn hàng.',
  { id: z.number().describe('Order ID'), note_id: z.number() },
  async ({ id, note_id }) => j(await api(`/orders/${id}/notes/${note_id}`, 'DELETE')));

server.tool('order_items_list', 'Liệt kê items (sản phẩm) trong đơn hàng: tên, qty, giá, sku, tax.',
  { id: z.number().describe('Order ID') },
  async ({ id }) => j(await api(`/orders/${id}/items`)));

server.tool('order_status_bulk', 'Đổi trạng thái hàng loạt đơn hàng cùng lúc.',
  {
    ids:    z.array(z.number()).describe('Danh sách Order ID'),
    status: z.enum(['pending','processing','on-hold','completed','cancelled','refunded','failed']).describe('Trạng thái mới'),
  },
  async (b) => j(await api('/orders/status/bulk', 'POST', b)));

server.tool('customer_create', 'Tạo khách hàng WooCommerce mới.',
  {
    email:      z.string().describe('Email (bắt buộc)'),
    first_name: z.string().optional(),
    last_name:  z.string().optional(),
    username:   z.string().optional(),
    password:   z.string().optional(),
    phone:      z.string().optional(),
  },
  async (b) => j(await api('/customers', 'POST', b)));

server.tool('customer_update', 'Cập nhật thông tin khách hàng: tên, email, địa chỉ giao/thanh toán.',
  {
    id:         z.number().describe('Customer ID'),
    first_name: z.string().optional(),
    last_name:  z.string().optional(),
    email:      z.string().optional(),
    phone:      z.string().optional(),
    billing:    z.record(z.string(), z.string()).optional().describe('Địa chỉ thanh toán: {address_1, city, country, ...}'),
    shipping:   z.record(z.string(), z.string()).optional().describe('Địa chỉ giao hàng'),
  },
  async ({ id, ...b }) => j(await api(`/customers/${id}`, 'PUT', b)));

server.tool('customer_delete', 'Xóa khách hàng WooCommerce.',
  { id: z.number().describe('Customer ID') },
  async ({ id }) => j(await api(`/customers/${id}`, 'DELETE')));

server.tool('customer_orders_list', 'Lịch sử đơn hàng của 1 khách hàng.',
  { id: z.number().describe('Customer ID'), per_page: z.number().default(20), page: z.number().default(1) },
  async ({ id, per_page, page }) => j(await api(`/customers/${id}/orders?per_page=${per_page}&page=${page}`)));

server.tool('wc_report_sales', 'Báo cáo doanh thu: tổng đơn + doanh thu theo kỳ.',
  { period: z.enum(['7d','30d','90d']).default('30d') },
  async ({ period }) => j(await api(`/wc/reports/sales?period=${period}`)));

server.tool('wc_report_top_sellers', 'Top sản phẩm bán chạy theo số lượng.',
  { days: z.number().default(30), limit: z.number().default(10) },
  async ({ days, limit }) => j(await api(`/wc/reports/top-sellers?days=${days}&limit=${limit}`)));

server.tool('wc_report_stock', 'Báo cáo tồn kho: outofstock | lowstock | all.',
  { type: z.enum(['outofstock','lowstock','all']).default('outofstock'), per_page: z.number().default(20) },
  async ({ type, per_page }) => j(await api(`/wc/reports/stock?type=${type}&per_page=${per_page}`)));

server.tool('wc_report_coupons', 'Báo cáo sử dụng coupon theo số lần dùng.',
  { days: z.number().default(30) },
  async ({ days }) => j(await api(`/wc/reports/coupons?days=${days}`)));

server.tool('wc_settings_get', 'Lấy cài đặt WooCommerce theo nhóm: general, products, tax, shipping, checkout, email, advanced.',
  { group: z.enum(['general','products','tax','shipping','checkout','email','advanced']).default('general') },
  async ({ group }) => j(await api(`/wc/settings/${group}`)));

server.tool('wc_settings_update', 'Cập nhật WC settings (option keys trực tiếp).',
  {
    group:    z.string().optional().describe('Nhóm settings để log, vd: general'),
    settings: z.record(z.string(), z.any()).describe('Object {option_key: value}, vd: {woocommerce_currency:"VND"}'),
  },
  async (b) => j(await api('/wc/settings', 'POST', b)));

server.tool('wc_email_list', 'Danh sách tất cả WooCommerce email templates: đặt hàng, hoàn tiền, xử lý, hoàn thành, v.v.',
  {},
  async () => j(await api('/wc/emails')));

server.tool('wc_email_get', 'Chi tiết 1 WC email template: subject, heading, recipient, enabled.',
  { id: z.string().describe('Email class ID, vd: WC_Email_New_Order, WC_Email_Customer_Invoice') },
  async ({ id }) => j(await api(`/wc/emails/${id}`)));

server.tool('wc_email_update', 'Cập nhật WC email template: bật/tắt, subject, heading, nội dung thêm.',
  {
    id:          z.string().describe('Email ID'),
    enabled:     z.boolean().optional(),
    subject:     z.string().optional(),
    heading:     z.string().optional(),
    recipient:   z.string().optional().describe('Email nhận (chỉ admin emails)'),
    additional:  z.string().optional().describe('Nội dung thêm cuối email'),
  },
  async ({ id, ...b }) => j(await api(`/wc/emails/${id}`, 'PUT', b)));

server.tool('webhooks_list', 'Danh sách WooCommerce webhooks: name, status, topic, delivery_url.',
  {},
  async () => j(await api('/wc/webhooks')));

server.tool('webhook_create', 'Tạo webhook WooCommerce mới. Topic vd: order.created, order.updated, product.created.',
  {
    name:         z.string().describe('Tên webhook'),
    topic:        z.string().describe('Sự kiện: order.created | order.updated | order.deleted | product.created | product.updated | customer.created | coupon.created | ...'),
    delivery_url: z.string().describe('URL nhận webhook POST'),
    secret:       z.string().optional().describe('Secret key (tự sinh nếu bỏ trống)'),
    status:       z.enum(['active','paused','disabled']).optional(),
  },
  async (b) => j(await api('/wc/webhooks', 'POST', b)));

server.tool('webhook_update', 'Cập nhật webhook: đổi URL, bật/tắt, đổi topic.',
  {
    id:           z.number().describe('Webhook ID'),
    name:         z.string().optional(),
    topic:        z.string().optional(),
    delivery_url: z.string().optional(),
    status:       z.enum(['active','paused','disabled']).optional(),
  },
  async ({ id, ...b }) => j(await api(`/wc/webhooks/${id}`, 'PUT', b)));

server.tool('webhook_delete', 'Xóa webhook vĩnh viễn.',
  { id: z.number() },
  async ({ id }) => j(await api(`/wc/webhooks/${id}`, 'DELETE')));

// ADVANCED TOOLS v4.6.6

server.tool('user_delete', 'Xóa user WordPress. Tùy chọn reassign bài viết cho user khác.',
  { id: z.number().describe('User ID cần xóa'), reassign: z.number().optional().describe('User ID nhận bài viết (0 = xóa luôn)') },
  async ({ id, reassign }) => j(await api(`/users/${id}`, 'DELETE', { reassign })));

server.tool('users_bulk_delete', 'Xóa nhiều user cùng lúc. Bỏ qua user hiện tại.',
  { ids: z.array(z.number()), reassign: z.number().optional().default(0) },
  async (b) => j(await api('/users/bulk/delete', 'POST', b)));

server.tool('comment_create', 'Tạo comment mới cho post/product.',
  {
    post_id:      z.number(),
    content:      z.string(),
    author:       z.string().optional(),
    author_email: z.string().optional(),
    approved:     z.number().optional().default(1).describe('1=approved, 0=pending'),
    parent:       z.number().optional().default(0),
  },
  async (b) => j(await api('/comments', 'POST', b)));

server.tool('post_revisions_list', 'Lấy danh sách phiên bản (revisions) của bài viết.',
  { id: z.number().describe('Post ID') },
  async ({ id }) => j(await api(`/posts/${id}/revisions`)));

server.tool('post_revision_restore', 'Khôi phục bài viết về phiên bản cũ.',
  { id: z.number().describe('Post ID'), revision_id: z.number().describe('Revision ID cần khôi phục') },
  async ({ id, revision_id }) => j(await api(`/posts/${id}/revisions/${revision_id}/restore`, 'POST')));

server.tool('options_get', 'Lấy giá trị WordPress options. Truyền ?keys=key1,key2. Không lấy được key nhạy cảm (API key, salt, password).',
  { keys: z.string().describe('Danh sách key cách nhau bằng dấu phẩy: blogname,blogdescription,timezone_string,...') },
  async ({ keys }) => j(await api(`/options?keys=${encodeURIComponent(keys)}`)));

server.tool('options_set', 'Cập nhật nhiều WordPress options cùng lúc. Không ghi được key nhạy cảm.',
  { options: z.record(z.any()).describe('Object {key: value}: {"blogname":"Tên web","blogdescription":"Mô tả"}') },
  async (b) => j(await api('/options', 'POST', b)));

server.tool('options_delete', 'Xóa WordPress options (không xóa được key hệ thống).',
  { keys: z.array(z.string()).describe('Mảng tên option cần xóa') },
  async (b) => j(await api('/options/delete', 'POST', b)));

server.tool('transients_list', 'Liệt kê WordPress transients (cache tạm thời trong DB), sắp xếp theo size giảm dần.',
  { limit: z.number().default(50), search: z.string().optional() },
  async ({ limit, search }) => j(await api(`/transients?limit=${limit}${search ? '&search='+encodeURIComponent(search) : ''}`)));

server.tool('transients_delete', 'Xóa transients. mode: specific (xóa theo keys[]), expired (xóa hết đã hết hạn), all (xóa tất).',
  {
    mode: z.enum(['specific','expired','all']).default('expired'),
    keys: z.array(z.string()).optional().describe('Chỉ cần khi mode=specific'),
  },
  async (b) => j(await api('/transients', 'DELETE', b)));

server.tool('cron_list', 'Liệt kê tất cả WP Cron jobs đã đăng ký, cho biết job nào bị overdue.',
  {},
  async () => j(await api('/cron')));

server.tool('cron_run', 'Chạy thủ công một WP Cron hook ngay lập tức.',
  { hook: z.string().describe('Tên hook cần chạy, vd: wp_scheduled_auto_draft_delete') },
  async ({ hook }) => j(await api(`/cron/run?hook=${encodeURIComponent(hook)}`, 'POST')));

server.tool('cron_delete', 'Xóa (unschedule) một WP Cron hook.',
  { hook: z.string() },
  async ({ hook }) => j(await api(`/cron/delete?hook=${encodeURIComponent(hook)}`, 'POST')));

server.tool('plugin_activate', 'Kích hoạt plugin WordPress theo file path (vd: woocommerce/woocommerce.php).',
  { plugin: z.string().describe('Plugin file path: folder/file.php') },
  async ({ plugin }) => j(await api(`/plugins/activate?plugin=${encodeURIComponent(plugin)}`, 'POST')));

server.tool('plugin_deactivate', 'Vô hiệu hóa plugin WordPress.',
  { plugin: z.string().describe('Plugin file path: folder/file.php') },
  async ({ plugin }) => j(await api(`/plugins/deactivate?plugin=${encodeURIComponent(plugin)}`, 'POST')));

server.tool('plugin_delete', 'Vô hiệu hóa và xóa plugin khỏi server.',
  { plugin: z.string().describe('Plugin file path: folder/file.php') },
  async ({ plugin }) => j(await api(`/plugins/delete?plugin=${encodeURIComponent(plugin)}`, 'POST')));

server.tool('plugin_install', 'Cài plugin mới từ WordPress.org repo theo slug.',
  { slug: z.string().describe('Plugin slug trên WordPress.org, vd: woocommerce') },
  async ({ slug }) => j(await api('/plugins/install', 'POST', { slug })));

server.tool('theme_activate', 'Kích hoạt theme WordPress theo stylesheet (folder name).',
  { stylesheet: z.string().describe('Theme folder name, vd: flatsome') },
  async ({ stylesheet }) => j(await api(`/themes/activate?stylesheet=${encodeURIComponent(stylesheet)}`, 'POST')));

server.tool('theme_install', 'Cài theme mới từ WordPress.org repo.',
  { slug: z.string().describe('Theme slug trên WordPress.org') },
  async ({ slug }) => j(await api('/themes/install', 'POST', { slug })));

server.tool('db_optimize', 'OPTIMIZE TABLE cho toàn bộ database — dọn sạch overhead, fragmentation.',
  {},
  async () => j(await api('/db/optimize', 'POST')));

server.tool('wp_option_autoload_fix', 'Phân tích autoload options nặng, tự động tắt autoload cho transients/sessions. Trả về danh sách options lớn cần xem xét.',
  {},
  async () => j(await api('/db/autoload-fix', 'POST')));

server.tool('error_log_read', 'Đọc N dòng cuối của wp-content/debug.log.',
  { lines: z.number().default(100).describe('Số dòng cuối cần đọc (max 500)') },
  async ({ lines }) => j(await api(`/error-log?lines=${lines}`)));

server.tool('error_log_clear', 'Xóa sạch nội dung debug.log (file vẫn còn, chỉ clear nội dung).',
  {},
  async () => j(await api('/error-log/clear', 'POST')));

server.tool('htaccess_get', 'Đọc nội dung file .htaccess.',
  {},
  async () => j(await api('/htaccess')));

server.tool('htaccess_set', 'Ghi đè .htaccess (backup tự động trước khi ghi). Chỉ dùng trên server Apache.',
  { content: z.string().describe('Nội dung .htaccess mới hoàn chỉnh') },
  async ({ content }) => j(await api('/htaccess', 'PUT', { content })));

server.tool('media_bulk_delete', 'Xóa nhiều media attachments cùng lúc.',
  { ids: z.array(z.number()), force: z.boolean().default(true).describe('true = xóa file vật lý') },
  async (b) => j(await api('/media/bulk/delete', 'POST', b)));

server.tool('media_search_unused', 'Tìm media không được dùng ở thumbnail hoặc content bài viết. Dùng để dọn dẹp.',
  { limit: z.number().default(50) },
  async ({ limit }) => j(await api(`/media/unused?limit=${limit}`)));

server.tool('media_regenerate_thumbnails', 'Tái tạo thumbnails cho media (khi thay đổi image sizes).',
  {
    ids:   z.array(z.number()).optional().describe('Danh sách ID cụ thể, bỏ trống = tự động lấy N ảnh đầu'),
    limit: z.number().default(20).describe('Số ảnh tối đa (bỏ qua nếu có ids)'),
  },
  async (b) => j(await api('/media/regenerate', 'POST', b)));

server.tool('media_set_featured', 'Đặt featured image (thumbnail) cho post/product.',
  { post_id: z.number(), attach_id: z.number().describe('Media attachment ID') },
  async (b) => j(await api(`/media/${b.attach_id}/set-featured`, 'POST', b)));

server.tool('posts_bulk_delete', 'Xóa nhiều bài viết/trang cùng lúc.',
  { ids: z.array(z.number()), force: z.boolean().default(false).describe('false = Trash, true = xóa vĩnh viễn') },
  async (b) => j(await api('/posts/bulk/delete', 'POST', b)));

server.tool('content_find_replace', 'Tìm và thay thế text trong nội dung bài viết/trang. Hỗ trợ dry_run để xem trước.',
  {
    find:        z.string().describe('Chuỗi cần tìm (exact match)'),
    replace:     z.string().describe('Chuỗi thay thế'),
    post_types:  z.array(z.string()).default(['post','page']),
    dry_run:     z.boolean().default(false).describe('true = chỉ xem kết quả, không thay thế'),
    limit:       z.number().default(100),
  },
  async (b) => j(await api('/content/find-replace', 'POST', b)));

server.tool('content_word_count_audit', 'Liệt kê bài viết/sản phẩm có nội dung ít hơn min_words từ. Dùng để tìm thin content.',
  {
    min_words: z.number().default(300),
    post_type: z.string().default('post'),
    limit:     z.number().default(100),
  },
  async ({ min_words, post_type, limit }) => j(await api(`/content/word-count-audit?min_words=${min_words}&post_type=${post_type}&limit=${limit}`)));

server.tool('sitemap_get', 'Lấy nội dung sitemap_index.xml (RankMath/Yoast). Trả về danh sách sub-sitemaps.',
  {},
  async () => j(await api('/sitemap')));

server.tool('sitemap_ping', 'Ping sitemap lên Google và Bing để báo nội dung mới.',
  {},
  async () => j(await api('/sitemap/ping', 'POST')));

server.tool('rankmath_redirect_update', 'Cập nhật redirect đã có trong RankMath (url_to, header code, status).',
  {
    id:      z.number().describe('Redirect ID'),
    url_to:  z.string().optional(),
    header:  z.number().optional().describe('HTTP code: 301, 302, 307...'),
    status:  z.string().optional().describe('active | inactive'),
  },
  async ({ id, ...b }) => j(await api(`/rankmath/redirects/${id}`, 'PUT', b)));

server.tool('rankmath_analytics', 'Thống kê Google Search Console từ RankMath Analytics: impressions, clicks, CTR, position trung bình và top pages.',
  { days: z.number().default(30).describe('Số ngày lookback (7, 30, 90)') },
  async ({ days }) => j(await api(`/rankmath/analytics?days=${days}`)));

server.tool('audit_404_log', 'Đọc log 404 từ RankMath Monitor — các URL gây lỗi và số lần hit.',
  {},
  async () => j(await api('/audit/404-log')));

server.tool('audit_schema_coverage', 'Kiểm tra tỷ lệ bài viết/sản phẩm có schema markup. Liệt kê những bài chưa có.',
  {},
  async () => j(await api('/audit/schema-coverage')));

server.tool('product_delete', 'Xóa sản phẩm WooCommerce (force=false → trash, force=true → xóa vĩnh viễn).',
  { id: z.number(), force: z.boolean().default(false) },
  async ({ id, force }) => j(await api(`/products/${id}?force=${force}`, 'DELETE')));

server.tool('product_category_update', 'Cập nhật danh mục sản phẩm: tên, slug, mô tả, ảnh, danh mục cha.',
  {
    id:          z.number().describe('Term ID của danh mục'),
    name:        z.string().optional(),
    slug:        z.string().optional(),
    description: z.string().optional(),
    parent:      z.number().optional(),
    image_id:    z.number().optional(),
  },
  async ({ id, ...b }) => j(await api(`/product-categories/${id}`, 'PUT', b)));

server.tool('product_category_delete', 'Xóa danh mục sản phẩm WooCommerce.',
  { id: z.number().describe('Term ID') },
  async ({ id }) => j(await api(`/product-categories/${id}`, 'DELETE')));

server.tool('coupon_get', 'Lấy chi tiết đầy đủ của một coupon theo ID.',
  { id: z.number() },
  async ({ id }) => j(await api(`/coupons/${id}`)));

server.tool('wc_coupon_bulk_create', 'Tạo hàng loạt coupons WooCommerce.',
  {
    coupons: z.array(z.object({
      code:        z.string().optional(),
      type:        z.enum(['percent','fixed_cart','fixed_product']).optional(),
      amount:      z.number().optional(),
      expiry:      z.string().optional().describe('YYYY-MM-DD'),
      limit:       z.number().optional(),
      description: z.string().optional(),
    })),
  },
  async (b) => j(await api('/coupons/bulk', 'POST', b)));

server.tool('wc_report_orders_total', 'Số đơn hàng theo từng trạng thái (pending, processing, completed, v.v.).',
  {},
  async () => j(await api('/wc/reports/orders-total')));

server.tool('wc_report_customers_total', 'Tổng số khách hàng, khách mới 30 ngày, khách có đơn hàng.',
  {},
  async () => j(await api('/wc/reports/customers-total')));

server.tool('shipping_zone_locations_update', 'Cập nhật địa điểm áp dụng cho shipping zone (quốc gia, bang, zip).',
  {
    id: z.number().describe('Zone ID (0 = Rest of World)'),
    locations: z.array(z.object({
      code: z.string().describe('VN, US:CA, 70000...'),
      type: z.enum(['country','state','postcode','continent']).default('country'),
    })),
  },
  async ({ id, locations }) => j(await api(`/wc/shipping/zones/${id}/locations`, 'PUT', { locations })));

server.tool('shipping_methods_list', 'Liệt kê tất cả phương thức giao hàng WooCommerce đã cài (flat_rate, free_shipping, local_pickup, v.v.).',
  {},
  async () => j(await api('/wc/shipping/methods')));

server.tool('wc_orders_export', 'Xuất đơn hàng WooCommerce ra danh sách JSON: customer, total, items, payment method.',
  {
    status: z.string().default('completed').describe('Trạng thái đơn: completed | processing | any'),
    days:   z.number().default(30).describe('Số ngày lookback'),
    limit:  z.number().default(200),
  },
  async ({ status, days, limit }) => j(await api(`/wc/orders/export?status=${status}&days=${days}&limit=${limit}`)));

server.tool('wc_product_import', 'Import nhiều sản phẩm WooCommerce từ JSON array. Hỗ trợ simple và variable products.',
  {
    products: z.array(z.object({
      name:               z.string(),
      type:               z.enum(['simple','variable']).optional(),
      sku:                z.string().optional(),
      regular_price:      z.string().optional(),
      sale_price:         z.string().optional(),
      description:        z.string().optional(),
      short_description:  z.string().optional(),
      status:             z.string().optional(),
      manage_stock:       z.boolean().optional(),
      stock_quantity:     z.number().optional(),
      stock_status:       z.string().optional(),
      weight:             z.string().optional(),
      categories:         z.array(z.number()).optional().describe('Mảng term IDs'),
    })),
  },
  async (b) => j(await api('/wc/products/import', 'POST', b)));

server.tool('customer_downloads_list', 'Lấy danh sách file có thể download của khách hàng (sản phẩm digital/virtual).',
  { id: z.number().describe('Customer user ID') },
  async ({ id }) => j(await api(`/customers/${id}/downloads`)));

// ELEMENTOR (v4.6.15)
server.tool('elementor_detect',
  'Kiểm tra post/page có dùng Elementor builder không. Trả edit_mode, element_count, page_settings.',
  { id: z.number().describe('Post/Page ID') },
  async ({ id }) => j(await api(`/elementor/${id}/detect`)));

server.tool('elementor_page_get',
  'Lấy toàn bộ _elementor_data (cây JSON) của 1 page/post. ' +
  'flat=true → flatten thành danh sách phẳng (tiết kiệm token, có preview). ' +
  'flat=false (mặc định) → raw JSON đầy đủ để edit.',
  {
    id:   z.number().describe('Post/Page ID'),
    flat: z.boolean().default(false).describe('true=danh sách phẳng, false=raw JSON'),
  },
  async ({ id, flat }) => j(await api(`/elementor/${id}/data?flat=${flat}`)));

server.tool('elementor_page_set',
  'Ghi toàn bộ _elementor_data (replace). Dùng sau khi chỉnh sửa JSON xong. ' +
  'Elementor CSS cache tự động được xoá sau khi ghi.',
  {
    id:   z.number().describe('Post/Page ID'),
    data: z.array(z.any()).describe('Mảng Elementor elements (root-level containers)'),
  },
  async ({ id, data }) => j(await api(`/elementor/${id}/data`, 'PUT', { data })));

server.tool('elementor_element_get',
  'Lấy 1 element cụ thể theo ID (kèm toàn bộ settings + children). ' +
  'Dùng để xem settings trước khi update.',
  {
    post_id: z.number().describe('Post/Page ID'),
    el_id:   z.string().describe('Element ID (8 ký tự hex, vd: 6af611eb)'),
  },
  async ({ post_id, el_id }) => j(await api(`/elementor/${post_id}/elements/${el_id}`)));

server.tool('elementor_element_add',
  'Thêm widget hoặc container vào 1 container. ' +
  'container_id="root" hoặc "" → thêm vào root page. ' +
  'position=-1 → append cuối, position=0 → đầu tiên. ' +
  '⚠️ element.id sẽ tự động generate nếu bỏ trống.',
  {
    post_id:      z.number().describe('Post/Page ID'),
    container_id: z.string().default('root').describe('ID của container cha, hoặc "root"'),
    position:     z.number().default(-1).describe('Vị trí chèn, -1=append cuối'),
    element:      z.any().describe('Object Elementor element (elType, widgetType, settings, elements...)'),
  },
  async (b) => j(await api(`/elementor/${b.post_id}/elements`, 'POST', {
    container_id: b.container_id,
    position:     b.position,
    element:      b.element,
  })));

server.tool('elementor_element_update',
  'Cập nhật settings của 1 element (merge — chỉ gửi fields cần thay). ' +
  'Hoặc truyền element_data để replace toàn bộ element (giữ nguyên ID).',
  {
    post_id:      z.number().describe('Post/Page ID'),
    el_id:        z.string().describe('Element ID (8 ký tự hex)'),
    settings:     z.any().optional().describe('Object settings cần merge vào element hiện tại'),
    element_data: z.any().optional().describe('Thay thế toàn bộ element (vẫn giữ ID cũ)'),
  },
  async ({ post_id, el_id, ...b }) => j(await api(`/elementor/${post_id}/elements/${el_id}`, 'PUT', b)));

server.tool('elementor_element_delete',
  'Xoá 1 element (và toàn bộ children) theo ID.',
  {
    post_id: z.number().describe('Post/Page ID'),
    el_id:   z.string().describe('Element ID cần xoá'),
  },
  async ({ post_id, el_id }) => j(await api(`/elementor/${post_id}/elements/${el_id}`, 'DELETE')));

server.tool('elementor_element_duplicate',
  'Nhân đôi 1 element (clone với ID mới) vào cùng vị trí container.',
  {
    post_id: z.number().describe('Post/Page ID'),
    el_id:   z.string().describe('Element ID cần duplicate'),
  },
  async ({ post_id, el_id }) => j(await api(`/elementor/${post_id}/elements/${el_id}/duplicate`, 'POST')));

server.tool('elementor_element_move',
  'Di chuyển 1 element sang container khác. target_container_id="root" để chuyển lên root.',
  {
    post_id:           z.number().describe('Post/Page ID'),
    el_id:             z.string().describe('Element ID cần di chuyển'),
    target_container_id: z.string().default('root').describe('ID container đích'),
    position:          z.number().default(-1).describe('Vị trí trong container đích, -1=cuối'),
  },
  async ({ post_id, ...b }) => j(await api(`/elementor/${post_id}/elements/move`, 'POST', b)));

server.tool('elementor_template_list',
  'Liệt kê Elementor templates (elementor_library). ' +
  'type: page|section|container|header|footer|popup (bỏ trống = tất cả).',
  {
    type:     z.string().default('').describe('Lọc theo type: page|section|container|header|footer|popup'),
    per_page: z.number().default(20),
  },
  async (p) => j(await api(`/elementor/templates?${qs(p)}`)));

server.tool('elementor_template_get',
  'Lấy data đầy đủ 1 template. flat=true → danh sách phẳng.',
  {
    id:   z.number().describe('Template ID'),
    flat: z.boolean().default(false),
  },
  async ({ id, flat }) => j(await api(`/elementor/templates/${id}?flat=${flat}`)));

server.tool('elementor_template_create',
  'Tạo Elementor template mới trong Library. type: page|section|container|header|footer|popup.',
  {
    name: z.string().describe('Tên template'),
    type: z.string().default('page').describe('page|section|container|header|footer|popup'),
    data: z.array(z.any()).default([]).describe('Mảng Elementor elements'),
  },
  async (b) => j(await api('/elementor/templates', 'POST', b)));

server.tool('elementor_page_settings_get',
  'Lấy _elementor_page_settings (background, padding, hide title...) của 1 page.',
  { id: z.number().describe('Post/Page ID') },
  async ({ id }) => j(await api(`/elementor/${id}/page-settings`)));

server.tool('elementor_page_settings_set',
  'Cập nhật _elementor_page_settings (merge). ' +
  'Ví dụ: ẩn title → { "hide_title": "yes" }, background → { "background_background": "classic", "background_color": "#fff" }.',
  {
    id:            z.number().describe('Post/Page ID'),
    page_settings: z.any().describe('Object settings cần merge'),
  },
  async ({ id, page_settings }) => j(await api(`/elementor/${id}/page-settings`, 'PUT', { page_settings })));

server.tool('elementor_widgets_list',
  'Liệt kê tất cả widget types đang available (cần Elementor active trên server).',
  {},
  async () => j(await api('/elementor/widgets')));

server.tool('elementor_flush_css',
  'Xoá CSS cache của Elementor. Chạy sau khi edit page/template xong để frontend hiển thị đúng.',
  {},
  async () => j(await api('/elementor/flush-css', 'POST')));

// ─── Diagnostic Tools v4.7.1 ─────────────────────────────────────────────────

server.tool('ajax_probe',
  'Gọi admin-ajax.php với action + params, trả về raw body + HTTP status. ' +
  'Dùng để debug "spinner quay mãi" — phát hiện HTML lỗi chen vào JSON, timeout, 500.',
  {
    action:  z.string().describe('Ajax action (vd: elementor_ajax)'),
    params:  z.record(z.any()).default({}).describe('Các param gửi kèm'),
    method:  z.enum(['GET','POST']).default('POST'),
    timeout: z.number().default(15).describe('Timeout giây'),
  },
  async (b) => j(await api('/diag/ajax-probe', 'POST', b)));

server.tool('rest_probe',
  'Gọi REST endpoint nội bộ với quyền admin. Xem raw response để debug.',
  {
    route:  z.string().describe('Vd: /wp/v2/posts'),
    method: z.enum(['GET','POST','PUT','DELETE']).default('GET'),
    body:   z.record(z.any()).default({}),
  },
  async (b) => j(await api('/diag/rest-probe', 'POST', b)));

server.tool('php_info',
  'PHP config: display_errors, error_reporting, memory_limit, max_execution_time, WP_DEBUG, opcache stats.',
  {},
  async () => j(await api('/diag/php-info')));

server.tool('error_log_search',
  'Tìm trong PHP error log theo pattern và/hoặc since. ' +
  'since: "2024-09-08 12:00:00" hoặc "-24h" hoặc "-7d". Trả về các dòng match.',
  {
    pattern: z.string().default('').describe('Regex hoặc text tìm kiếm'),
    since:   z.string().default('').describe('-24h | -7d | 2024-09-08 12:00:00'),
    lines:   z.number().default(100).describe('Max dòng trả về (tối đa 1000)'),
  },
  async (p) => j(await api(`/diag/error-log-search?${qs(p)}`)));

server.tool('cache_status',
  'Phát hiện cache plugin đang active: LiteSpeed, FlyingPress, WP Rocket, W3TC, WP Super Cache.',
  {},
  async () => j(await api('/diag/cache-status')));

server.tool('cache_purge',
  'Purge cache từ plugin đang active. plugin: all|litespeed|flyingpress|wp_rocket|w3tc. url: optional.',
  {
    plugin: z.enum(['all','litespeed','flyingpress','wp_rocket','w3tc','wp_super_cache']).default('all'),
    url:    z.string().default('').describe('Optional: chỉ purge URL cụ thể'),
  },
  async (b) => j(await api('/diag/cache-purge', 'POST', b)));

server.tool('file_read',
  'Đọc file trong themes/plugins/mu-plugins. path tương đối so với wp-content/.',
  {
    path: z.string().describe('Vd: themes/flatsome/functions.php hoặc plugins/myplugin/main.php'),
    from: z.number().default(1).describe('Dòng bắt đầu (1-indexed)'),
    to:   z.number().default(0).describe('Dòng kết thúc (0 = tự động 200 dòng)'),
  },
  async (p) => j(await api(`/diag/file-read?${qs(p)}`)));

server.tool('file_edit',
  'Thay thế chính xác find→replace trong file. Có PHP lint gate (từ chối nếu syntax lỗi) + auto-backup. ' +
  'Chỉ hoạt động trong themes/plugins/mu-plugins.',
  {
    path:    z.string().describe('Path tương đối so với wp-content/'),
    find:    z.string().describe('Chuỗi cần tìm (exact, case-sensitive)'),
    replace: z.string().describe('Chuỗi thay thế'),
    all:     z.boolean().default(false).describe('true = thay tất cả, false = lỗi nếu > 1 match'),
  },
  async (b) => j(await api('/diag/file-edit', 'POST', b)));

server.tool('healthcheck_troubleshoot',
  'Toggle plugin trong session hiện tại mà không ảnh hưởng user khác. ' +
  'action: status | disable_plugin | enable_plugin. Cần plugin: Health Check & Troubleshooting.',
  {
    action: z.enum(['status','disable_plugin','enable_plugin']).default('status'),
    plugin: z.string().default('').describe('Plugin file (vd: woocommerce/woocommerce.php)'),
  },
  async (b) => j(await api('/diag/troubleshoot', 'POST', b)));

server.tool('db_query',
  'Chạy SELECT query trực tiếp trên DB. Chỉ SELECT — mọi query phá hoại bị từ chối.',
  {
    sql:   z.string().describe('SELECT query (vd: SELECT * FROM wp_usermeta WHERE meta_key="meta_id" LIMIT 5)'),
    limit: z.number().default(100).describe('Max rows (tối đa 500)'),
  },
  async (p) => j(await api(`/diag/db-query?${qs(p)}`)));

server.tool('db_describe_table',
  'Mô tả cấu trúc bảng: cột, kiểu, key, row count. Không cần prefix — tự thêm.',
  {
    table: z.string().describe('Tên bảng không có prefix (vd: usermeta, postmeta, options)'),
  },
  async ({ table }) => j(await api(`/diag/db-describe?table=${encodeURIComponent(table)}`)));

// ── v4.8.0: Batch, Snapshot, Changelog ────────────────────────────────────

server.tool('batch',
  'Gửi nhiều API call trong 1 request. Tiết kiệm latency cho workflow phức tạp. ' +
  'Mỗi op: {method, path, body?, params?}. Tối đa 20 ops.',
  {
    ops: z.array(z.object({
      method: z.enum(['GET','POST','PUT','DELETE']).default('GET'),
      path:   z.string().describe('Vd: /posts?per_page=5 hoặc /posts/123'),
      body:   z.record(z.any()).optional(),
      params: z.record(z.any()).optional(),
    })).describe('Array of operations'),
  },
  async ({ ops }) => j(await api('/batch', 'POST', ops)));

server.tool('site_snapshot',
  'Snapshot toàn bộ site trong 1 call: info, content counts, recent posts, ' +
  'errors 24h, cache plugins, active plugins, last changelog entry.',
  {},
  async () => j(await api('/snapshot')));

server.tool('changelog',
  'Đọc audit log các write operations do MCP thực hiện. ' +
  'action: filter theo loại (vd bulk_posts_update). limit/offset để phân trang.',
  {
    limit:  z.number().default(50).describe('Max entries (tối đa 200)'),
    offset: z.number().default(0),
    action: z.string().default('').describe('Filter theo action type'),
  },
  async (p) => j(await api(`/changelog?${qs(p)}`)));

server.tool('changelog_clear',
  'Xóa toàn bộ audit log changelog.',
  {},
  async () => j(await api('/changelog/clear', 'POST')));

// ── v4.9.0: ACF, media base64, WP Mail SMTP ──
server.tool('media_upload_base64', 'Upload ảnh từ base64 (data URI hoặc chuỗi thuần) vào thư viện.', {
  data: z.string().describe('base64'), filename: z.string().describe('vd: anh.jpg'),
  title: z.string().optional(), alt: z.string().optional(), post_id: z.number().default(0),
}, async (b) => j(await api('/media/upload-base64', 'POST', b)));

server.tool('acf_groups', 'Liệt kê ACF field groups.', {}, async () => j(await api('/acf/groups')));
server.tool('acf_group_fields', 'Field của 1 group (group_key dạng group_xxx).', { group_key: z.string() },
  async ({ group_key }) => j(await api(`/acf/groups/${group_key}/fields`)));
server.tool('acf_fields', 'Các field ACF áp dụng cho 1 post.', { id: z.number() }, async ({ id }) => j(await api(`/acf/${id}/fields`)));
server.tool('acf_get', 'Đọc giá trị ACF của post.', { id: z.number(), format: z.boolean().default(true) },
  async ({ id, format }) => j(await api(`/acf/${id}?format=${format}`)));
server.tool('acf_set', 'Ghi giá trị ACF: fields = {tên_field: giá_trị}.', { id: z.number(), fields: z.record(z.any()) },
  async ({ id, fields }) => j(await api(`/acf/${id}`, 'PUT', { fields })));

server.tool('wpms_status', 'WP Mail SMTP: tình trạng, mailer, cấu hình đủ chưa, lỗi gần nhất, cảnh báo.', {}, async () => j(await api('/wpms/status')));
server.tool('wpms_mailers', 'WP Mail SMTP: danh sách mailer và các field cần.', {}, async () => j(await api('/wpms/mailers')));
server.tool('wpms_settings_get', 'WP Mail SMTP: đọc cấu hình (secret bị che).', {}, async () => j(await api('/wpms/settings')));
server.tool('wpms_settings_set', 'WP Mail SMTP: sửa cấu hình, merge an toàn. settings={mail:{mailer,from_email,...},smtp:{host,port,encryption,auth,user,pass},...}. dry_run=true để xem trước.', {
  settings: z.record(z.record(z.any())), dry_run: z.boolean().default(false),
}, async (b) => j(await api('/wpms/settings', 'PUT', b)));
server.tool('wpms_test_email', 'WP Mail SMTP: gửi mail thử, trả lỗi + debug events phát sinh.', {
  to: z.string().describe('email nhận'), html: z.boolean().default(true),
}, async (b) => j(await api('/wpms/test-email', 'POST', b)));
server.tool('wpms_debug_events', 'WP Mail SMTP: danh sách debug events (đã scrub secret).', {
  type: z.enum(['all', 'error', 'debug']).default('all'), search: z.string().optional(), since: z.string().optional(),
  limit: z.number().default(20), offset: z.number().default(0), max_chars: z.number().default(1500),
}, async (q) => j(await api('/wpms/debug-events?' + qs(q))));
server.tool('wpms_debug_event_get', 'WP Mail SMTP: 1 debug event đầy đủ.', { id: z.number() }, async ({ id }) => j(await api(`/wpms/debug-events/${id}`)));
server.tool('wpms_debug_events_delete', 'WP Mail SMTP: xoá events theo ids, hoặc all+confirm.', {
  ids: z.array(z.number()).optional(), all: z.boolean().optional(), confirm: z.boolean().optional(),
}, async (b) => j(await api('/wpms/debug-events', 'DELETE', b)));
server.tool('wpms_send_errors', 'WP Mail SMTP: lỗi gửi mail theo connection.', {}, async () => j(await api('/wpms/send-errors')));
server.tool('wpms_send_errors_clear', 'WP Mail SMTP: xoá lỗi gửi mail.', { connection_id: z.string().optional() },
  async (b) => j(await api('/wpms/send-errors', 'DELETE', b)));

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
    console.error('[XCLIPSE] ❌ Thiếu XCLIPSE_URL hoặc XCLIPSE_API_KEY');
    process.exit(1);
  }
  try {
    const host = new URL(env.base).hostname;
    if (!/localhost|127\.0\.0\.1|\.local$|\.test$/i.test(host)) {
      console.error(`[XCLIPSE] ⚠ đang trỏ site thật: ${host}`);
    }
  } catch {
    console.error(`[XCLIPSE] ⚠ XCLIPSE_URL không hợp lệ: ${env.base}`);
  }

  const { server: mcpServer, stats } = createMcpServer();
  const transport = new StdioServerTransport();
  await mcpServer.connect(transport);
  console.error(
    `[XCLIPSE v${VERSION}] OK ${env.base} | mode=${stats.mode} | tools ${stats.registered}/${stats.total}${env.tools.length ? ' (XCLIPSE_TOOLS=' + env.tools.join(',') + ')' : ''} | ${new Date().toLocaleTimeString('vi-VN')}`
  );
  // Kiểm tra phiên bản plugin, không chặn khởi động
  (async () => {
    try {
      const r = await fetch(`${env.base}/wp-json/xclipse-mcp/v1/info`, {
        headers: { 'X-MCP-Key': env.key },
        signal: AbortSignal.timeout(5000),
      });
      const d = JSON.parse(await r.text());
      if (d && d.api_v && d.api_v !== VERSION) {
        console.error(`[XCLIPSE] ⚠ plugin WP v${d.api_v} khác MCP server v${VERSION}`);
      }
    } catch (e) {
      console.error(`[XCLIPSE] ⚠ không kiểm tra được plugin lúc khởi động: ${String(e.message).slice(0, 80)}`);
    }
  })();
}

