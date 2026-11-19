---
name: xclipse-wordpress-mcp
description: Manage live WordPress & WooCommerce sites via Xclipse WordPress MCP Server (390+ tools across Core, WooCommerce, Rank Math SEO, Elementor, Flatsome UX Builder, LiteSpeed Cache, CF7, WP Mail SMTP, and Pixta). Supports Compact Gateway Mode (~90% fewer schema tokens), multi-site routing, and safe read-before-write workflows.
---

# Xclipse WordPress MCP Skill

Use this skill whenever managing, auditing, or automating a live WordPress or WooCommerce site connected via the **Xclipse WordPress MCP Server** (`xclipse-mcp`).

## 1. Compact Gateway Mode (Recommended)

When `XCLIPSE_TOOL_MODE=compact` is active (default on remote HTTP `/mcp`), the server exposes **29 essential tools** directly plus two meta-tools:

- **`xclipse_discover`**: Search the full catalog of **390+ tools** by `query` or `group` (`core`, `woo`, `seo`, `elementor`, `flatsome`, `cache`, `pixta`, `forms`, `mail`).
- **`xclipse_call`**: Execute any discovered tool by name (`tool`) and arguments (`args`).

Always call `xclipse_discover` before guessing non-core tool names or parameters.

## 2. Tool Groups Overview (390+ Tools)

| Group | Count | Capabilities |
| --- | ---: | --- |
| `core` | 162 | Posts, pages, media (+ `media_upload_base64`), users & `user_change_password`, menus, widgets, ACF, taxonomies, revisions, `batch`, `site_snapshot`, `changelog`, diagnostics |
| `woo` | 85 | WooCommerce products, variations, orders, refunds, coupons, customers, shipping zones, taxes, `bulk_products_update` |
| `seo` | 42 | Rank Math SEO meta, global settings, redirects, sitemaps, schema/rich snippets, `audit_summary`, broken-link checks |
| `flatsome` | 25 | Flatsome UX Builder shortcodes, block patching, theme mods |
| `cache` | 19 | LiteSpeed Cache purge, object cache, CCSS, image optimization, CDN |
| `pixta` | 19 | Pixta stock & media workflows |
| `elementor` | 16 | Elementor page data, widget edits, CSS regeneration |
| `forms` | 13 | Contact Form 7 forms, mail templates, submissions |
| `mail` | 10 | WP Mail SMTP status, mailers, test emails, debug events |

## 3. Mandatory Safety Workflow

Follow this sequence for any non-trivial site modification:

1. **Snapshot First:** Run `site_snapshot` or `site_info` to verify WordPress, WooCommerce, theme, and active plugins before mutating state.
2. **Read Before Write:** Always fetch the target entity (`post_get`, `page_get`, `product_get`, `ux_get`, `elementor_get`, `rankmath_get`) before updating it so you preserve existing fields and structure.
3. **Dry-Run Bulk Edits:** Use `dry_run: true` on `bulk_posts_update` or `pages_bulk_update` to preview affected IDs before applying changes.
4. **Batch & Compact:** Prefer `batch` (up to 20 operations) or `bulk_*_update` over looping single-item calls. Use `fields` on list tools to minimize response size.
5. **Purge Cache After Visual/SEO Changes:** After updating pages, Elementor/Flatsome layouts, menus, or Rank Math settings, run `lscache_purge_url` or `lscache_purge_all` (and `elementor_clear_css` if editing Elementor).
6. **Password Changes:** Use `user_change_password` (supports `id`, `username`, or `email`, `generate: true` for a 16-char password, and `logout_sessions: true` by default). Never store or echo passwords in changelog notes.

## 4. Multi-Site Routing (Remote HTTP `/mcp`)

When connected to a shared remote server configured with `XCLIPSE_SITES`:
- Route by alias via query param `?site=<alias>` or HTTP header `X-WP-Site: <alias>` (keeps API keys out of client URLs).
- Or override per-request via `X-WP-URL` and `X-WP-Key` headers.

## 5. Error Handling

Structured errors return `{ error, code, status, hint }`:
- `AUTH_ERROR` (401/403): Verify `XCLIPSE_API_KEY` / `X-MCP-Key` and that `xclipse-mcp-api` plugin is active.
- `NOT_FOUND` (404): Verify entity ID and WordPress permalink/REST API availability.
- `RATE_LIMIT` (429) / `SERVER_ERROR` (5xx): `GET` requests automatically retry with backoff; for writes, wait briefly and inspect `changelog_list` before retrying.
