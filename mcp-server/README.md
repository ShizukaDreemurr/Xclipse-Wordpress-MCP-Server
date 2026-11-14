# xclipse-mcp

MCP server (stdio) cho phép Claude quản lý WordPress/WooCommerce của xclipse.com
qua REST namespace `xclipse-mcp/v1` của plugin PHP đi kèm.

## Cài đặt
```bash
cd mcp-server
npm install
npm run check   # kiểm tra cú pháp
npm start       # chạy server stdio
```
Yêu cầu Node >= 18.

## Cấu hình (.env)
Tạo `mcp-server/.env` (hoặc truyền qua biến môi trường của client MCP):
```
XCLIPSE_URL=https://xclipse.com
XCLIPSE_API_KEY=your-secret-key
XCLIPSE_TIMEOUT_MS=30000
XCLIPSE_MAX_OUT=20000
XCLIPSE_TOOLS=core,woo,seo
```
- `XCLIPSE_URL`, `XCLIPSE_API_KEY`: bắt buộc.
- `XCLIPSE_TIMEOUT_MS`: timeout mỗi request (ms).
- `XCLIPSE_MAX_OUT`: số ký tự tối đa của kết quả trả về, quá sẽ bị cắt.
- `XCLIPSE_TOOLS`: chỉ nạp các nhóm tool liệt kê (để trống = tất cả). Nhóm:
  `core, woo, seo, elementor, flatsome, cache, pixta, forms, mail`.

## Chạy test
```bash
npm test
```
Smoke test tự dựng server giả, không cần site thật. Kiểm tra: số lượng tool (>= 380),
tên không trùng, có inputSchema, gọi thử `site_info`, `posts_list`, `wpms_status`,
và lỗi HTML 502 phải trả `isError` mà không làm client chết. Thoát mã khác 0 nếu có mục FAIL.
