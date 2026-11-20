# Xclipse WordPress MCP Server (Lite / Core Edition)

[![Full 390+ Tool Pro Suite on Agensi.io](https://img.shields.io/badge/Agensi.io-Full%20390%2B%20Tool%20Pro%20Suite%20(%2429)-6366f1?style=for-the-badge)](https://www.agensi.io/r/XCLIPEWPMCPSRVR)

Open-source **Core Lite** edition of **Xclipse WordPress MCP Server** — connect Claude Code, Cursor, GitHub Copilot, Windsurf, or Gemini to your WordPress site for basic content, pages, media, taxonomy, and comment management.

> **Want WooCommerce, Elementor, Flatsome, Rank Math SEO, LiteSpeed Cache, CF7, WP Mail SMTP, Batch & Audit?**  
> **[Upgrade to the Full 390+ Tool Pro Suite on Agensi.io ($29) →](https://www.agensi.io/r/XCLIPEWPMCPSRVR)**

---

## Free (Lite) vs Pro (Agensi.io)

| Feature | GitHub Lite (Free) | Agensi Pro (\$29) |
| --- | :---: | :---: |
| Core Posts, Pages, Media, Categories, Tags, Comments | 18 tools | 162 core tools |
| WooCommerce (Products, Variations, Orders, Coupons, Customers) | — | 85 tools |
| Rank Math & Technical SEO (Meta, Redirects, Schema, Audits) | — | 42 tools |
| Elementor & Flatsome UX Builder | — | 41 tools |
| LiteSpeed Cache & WP Mail SMTP | — | 29 tools |
| Contact Form 7 & Pixta Media | — | 32 tools |
| Compact Gateway Mode (~90% token savings) & `XCLIPSE_SITES` | — | Included |
| Standardized `SKILL.md` + `user_change_password` + `batch` | — | Included |

---

## Quick Start (Lite)

1. Upload `dist/xclipse-mcp-api.zip` to your WordPress site (**Plugins → Add New → Upload Plugin**) and copy your `X-MCP-Key`.
2. Install dependencies:
   ```bash
   cd mcp-server && npm install
   ```
3. Run via `stdio` (Cursor / Claude Code / Antigravity):
   ```json
   {
     "mcpServers": {
       "xclipse-wordpress-lite": {
         "command": "node",
         "args": ["/path/to/mcp-server/src/index.js"],
         "env": {
           "XCLIPSE_URL": "https://your-site.com",
           "XCLIPSE_API_KEY": "YOUR_KEY"
         }
       }
     }
   }
   ```
4. Or run via Streamable HTTP:
   ```bash
   MCP_AUTH_TOKEN=your-secret XCLIPSE_URL=https://your-site.com XCLIPSE_API_KEY=YOUR_KEY npm run start:http
   ```
