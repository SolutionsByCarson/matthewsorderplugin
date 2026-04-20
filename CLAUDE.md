# Matthews Order Plugin — CLAUDE.md

Shortcode-based order submission system for Matthews Feed and Grain. Generates Feed Mill Manager (FMM) `ORDIMP.DAT` import files from customer web orders.

## Workflow

- Every code change is committed to git with a descriptive message.
- This file is updated and committed alongside each change so the history is human-readable.

## Architecture overview

- **Own tables** (not WP standard): `mop_users`, `mop_products`, `mop_orders`, `mop_order_lines`. Tables are created on activation and **never** dropped on deactivation or uninstall.
- **Own auth**: cookie-based session (`MOP_COOKIE_NAME`), separate from `wp_users`.
- **Single shortcode** `[matthews_order]` with a view router driven by the `?mop_view=` query var. Views: `login`, `request-password-reset`, `update-password`, `my-account`, `edit-account`, `create-order`, `order-confirmation`.
- **Conditional enqueue**: front-end CSS/JS only load on posts whose `post_content` contains `[matthews_order]`.
- **Admin menu** (capability `edit_pages`, so editors can access): Users, Products, Orders, Settings.
- **Uploads**: `wp-content/order/{user_id}/{order_id}/ORDIMP.dat` — directory tree created on activation, protected by `.htaccess` deny-all.
- **Email system** with five notifications: `password_reset`, `password_update`, `account_change`, `order_notification`, `order_submission` (latter attaches ORDIMP.dat).

## FMM ORDIMP.DAT format — reference

Source: `FMM_Order_Import_Reference_Guide-V2.pdf`

- Comma-delimited ASCII, no header row, no quoted strings.
- **Windows CRLF (`\r\n`) line endings required.**
- Record 100 = 9 fields (order header). Record 110 = 2 fields (comment, optional). Record 200 = **25 fields** (stock item order line — spec says 24, FMM wants 25; trailing empty required or the line is silently dropped).
- Record 200 pos 6 = qty in **base UoM**; pos 7 always `0`; pos 8 = base UoM string. `BAG-50` → `POUND` conversion (×50) is done in PHP before writing.
- Customer PO number (Record 100 pos 2) must be **globally unique**. Suggested format: `WEB-MFG-YYYYMMDD-NNN`.
- Site ID for Matthews: `MATTHEWS`.

## Gameplan

1. ✅ **Phase 1 — Foundation**: plugin bootstrap, activator (uploads dir + `.htaccess`), settings page, shortcode view router, conditional enqueue, cookie-auth stub, email stub, ORDIMP builder stub, admin menu stubs, `uninstall.php` that preserves data.
2. **Phase 2 — Database**: `dbDelta` schemas for `mop_users` / `mop_products` / `mop_orders` / `mop_order_lines`, schema-version option, migration handling.
3. **Phase 3 — Admin screens**: `WP_List_Table` for each entity, CSV import/export with FMM-shape validation, ORDIMP download + email resend from orders.
4. **Phase 4 — Customer front-end**: real login, password reset flow, my account / edit account, AJAX order builder, confirmation.
5. **Phase 5 — ORDIMP + email wiring**: real generator (CRLF, 25 fields, UoM math), writes file, attaches to Order Submission email.
6. **Phase 6 — Hardening**: nonce + capability checks, rate limiting on login + reset, audit log.

## Directory layout

```
matthewsorderplugin/
├── matthewsorderplugin.php     # Plugin header + bootstrap
├── uninstall.php               # Intentional no-op (preserve data)
├── CLAUDE.md
├── FMM_Order_Import_Reference_Guide-V2.pdf
├── ORDIMP.dat                  # sample import file
├── includes/
│   ├── class-mop-plugin.php
│   ├── class-mop-activator.php
│   ├── class-mop-deactivator.php
│   ├── class-mop-database.php
│   ├── class-mop-settings.php
│   ├── class-mop-auth.php
│   ├── class-mop-assets.php
│   ├── class-mop-shortcode.php
│   ├── class-mop-email.php
│   ├── class-mop-ordimp.php
│   └── class-mop-admin.php
├── templates/                  # front-end view partials
│   ├── login.php
│   ├── request-password-reset.php
│   ├── update-password.php
│   ├── my-account.php
│   ├── edit-account.php
│   ├── create-order.php
│   └── order-confirmation.php
└── assets/
    ├── css/matthewsorder.css
    └── js/matthewsorder.js
```

## Changelog

### 2026-04-20 — Initial scaffolding + Phase 1 wire-up

- Created plugin at `wp-content/plugins/matthewsorderplugin/` and initialized git.
- Remote: `https://github.com/SolutionsByCarson/matthewsorderplugin.git`.
- `matthewsorderplugin.php` — plugin header, constants (`MOP_*`), includes, activation/deactivation/plugins_loaded hooks.
- `uninstall.php` — deliberate no-op so data survives plugin removal.
- `includes/class-mop-plugin.php` — bootstrap that wires settings, auth, assets, shortcode, admin.
- `includes/class-mop-activator.php` — creates custom tables (via `MOP_Database`), the `wp-content/order/` tree with `.htaccess` deny-all + empty `index.html`, seeds default `mop_settings`.
- `includes/class-mop-deactivator.php` — no-op (data persists).
- `includes/class-mop-database.php` — schema manager stub, table-name helper, DDL deferred to Phase 2.
- `includes/class-mop-settings.php` — admin settings page with `shortcode_url` and `admin_email` fields, Settings API registration, `get()` accessor.
- `includes/class-mop-auth.php` — cookie-based auth stub: `current_user()`, `is_logged_in()`, `require_login()` with redirect to login view.
- `includes/class-mop-assets.php` — `wp_enqueue_scripts` handler that only enqueues when `has_shortcode($post->post_content, 'matthews_order')`; localizes `MOP.ajaxUrl` + nonce.
- `includes/class-mop-shortcode.php` — registers `[matthews_order]`, resolves view from `?mop_view`, enforces auth for non-public views, includes `templates/{view}.php`.
- `includes/class-mop-email.php` — five notification method stubs with documented recipients.
- `includes/class-mop-ordimp.php` — ORDIMP builder stub with CRLF / field-count constants, `storage_path()` helper, `format_record()` with sanitizer.
- `includes/class-mop-admin.php` — top-level "Matthews Orders" menu with Users / Products / Orders / Settings submenus, capability-gated to `edit_pages`.
- `templates/*.php` — seven placeholder partials for the shortcode router.
- `assets/css/matthewsorder.css`, `assets/js/matthewsorder.js` — minimal starter files.
