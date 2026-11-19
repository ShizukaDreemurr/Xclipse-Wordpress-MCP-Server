# xclipse-mcp (v5.0.0)

MCP Server quản lý WordPress / WooCommerce / SEO / Elementor / WP Mail SMTP qua REST namespace `xclipse-mcp/v1` của plugin `xclipse-mcp-api`.

Hỗ trợ **2 chế độ transport**:
- **Remote Streamable HTTP URL** (`/mcp`): deploy lên cloud (Render, Railway, Fly.io, VPS, Docker) để kết nối trực tiếp từ **Gemini Spark**, Claude Remote MCP, Cursor, MCP Inspector bằng URL.
- **Stdio** (local): chạy trực tiếp qua `node src/index.js`.

## Điểm mới v5.0.0 (Tối ưu AI & Token)
- **Compact Gateway Mode (`XCLIPSE_TOOL_MODE=compact`)**: chỉ nạp ~28 tool thiết yếu + `xclipse_discover` + `xclipse_call` vào `tools/list`, **giảm ~90% token schema** mỗi lượt gọi AI (và tương thích giới hạn số tool của Gemini), nhưng vẫn gọi được toàn bộ **389 tools**.
- **Response Compaction (`XCLIPSE_COMPACT=1`)**: tự động loại bỏ `null`, `""`, `[]` trong JSON trả về để tiết kiệm thêm 20–40% token.
- **TTL Cache & Retry thông minh**: cache 15s cho các endpoint danh mục tĩnh (tự xóa khi có thao tác ghi), tự retry `429` / `502` / `503` / `504` / lỗi mạng, phân loại mã lỗi kèm gợi ý sửa.
- **Multi-site qua HTTP**: có thể truyền `X-WP-URL`, `X-WP-Key`, `X-Tool-Mode` qua header hoặc `?wp_url=...&wp_key=...&mode=compact` trên URL.

## Cài đặt & Chạy
```bash
cd mcp-server
npm install
npm run check       # kiểm tra cú pháp
npm test            # chạy 12 smoke tests (stdio + HTTP)
npm start           # chạy stdio local
npm run start:http  # chạy HTTP server tại http://0.0.0.0:3000/mcp
```
Yêu cầu Node >= 18.

## Deploy Remote MCP Server URL (cho Gemini Spark)
1. **Render.com**: kết nối repo GitHub này, Render tự nhận `render.yaml`. Điền `XCLIPSE_URL` và `XCLIPSE_API_KEY`.
2. **Docker**:
   ```bash
   docker build -t xclipse-mcp ./mcp-server
   docker run -p 3000:3000 \
     -e XCLIPSE_URL=https://your-site.com \
     -e XCLIPSE_API_KEY=your-key \
     -e MCP_AUTH_TOKEN=your-secret \
     -e XCLIPSE_TOOL_MODE=compact \
     xclipse-mcp
   ```
3. **Kết nối trên Gemini Spark**:
   - **MCP Server URL**: `https://your-domain.com/mcp` (hoặc `https://your-domain.com/mcp?mode=compact`)
   - **Header xác thực** (nếu đặt `MCP_AUTH_TOKEN`): `Authorization: Bearer <MCP_AUTH_TOKEN>` (hoặc dùng query `?token=<MCP_AUTH_TOKEN>`).
   - **Health check**: `GET https://your-domain.com/health`
