# Xclipse WordPress MCP Server

Hệ thống **MCP Server + WordPress Plugin** quản lý toàn diện WordPress, WooCommerce, Rank Math SEO, Elementor, Flatsome, LiteSpeed Cache, ACF, Contact Form 7 và WP Mail SMTP (389+ tools).

## Cấu trúc dự án
- `mcp-server/` — Node.js MCP Server v5.0.0 (hỗ trợ cả **Remote Streamable HTTP URL** cho Gemini Spark / Cloud và **Stdio** local).
- `xclipse-mcp-api/` — WordPress Plugin (`xclipse-mcp-api.php`) cung cấp REST API `/wp-json/xclipse-mcp/v1/*`.
- `render.yaml` — Cấu hình deploy 1-click lên Render.com thành MCP Server URL.

Xem hướng dẫn cài đặt và deploy chi tiết tại [mcp-server/README.md](mcp-server/README.md).
