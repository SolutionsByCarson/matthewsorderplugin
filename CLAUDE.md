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
2. 🟨 **Phase 2 — Database + auth**: `mop_users` + `mop_sessions` + `mop_products` via dbDelta (done), real cookie auth + login/logout/password-reset flow (done). Orders + order-lines schemas still to come.
3. **Phase 3 — Admin screens**: `WP_List_Table` for each entity, CSV import/export with FMM-shape validation, ORDIMP download + email resend from orders.
4. **Phase 4 — Customer front-end**: edit account, AJAX order builder, confirmation.
5. **Phase 5 — ORDIMP + email wiring**: real generator (CRLF, 25 fields, UoM math), writes file, attaches to Order Submission email.
6. **Phase 6 — Hardening**: rate limiting on login + reset, audit log.

## Data model — current

### `mop_users`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | Auto-increment surrogate — used by sessions + orders FK |
| `customer_id` | varchar(15) UNIQUE | **FMM Customer ID** — must match FMM exactly (Record 100 pos 3). Not editable after creation |
| `company_name` | varchar(64) | Matches FMM "Customer Name" display field (Record 100 pos 4) |
| `contact_first_name` | varchar(50) | |
| `contact_last_name` | varchar(50) | |
| `email` | varchar(190) UNIQUE | Login identity |
| `password_hash` | varchar(255) | `wp_hash_password()` output |
| `bill_to_line1/2`, `bill_to_city`, `bill_to_state`, `bill_to_zip` | varchar | 2-char state, 10-char zip |
| `ship_to_line1/2`, `ship_to_city`, `ship_to_state`, `ship_to_zip` | varchar | |
| `is_active` | tinyint | Soft-disable login without deletion |
| `reset_token_hash`, `reset_token_expires_at` | varchar(64), datetime | SHA-256 of raw token; raw never stored |
| `last_login_at`, `created_at`, `updated_at` | datetime | |

### `mop_sessions`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint FK | → mop_users.id |
| `token_hash` | varchar(64) UNIQUE | SHA-256 of the raw auth cookie value |
| `ip_address`, `user_agent` | varchar | Audit context |
| `created_at`, `expires_at` | datetime | Default 30-day session (`MOP_SESSION_DAYS`) |

### `mop_products`

Modeled after the existing live order form (matthewsfeedandgrain.com/order-form/): 4 brand sections, one fixed selling UoM per product, qty-only entry.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `fmm_item_number` | varchar(30) UNIQUE | **FMM Line Code** (Record 200 pos 4). Always upper-cased on write |
| `description` | varchar(50) | FMM Line Description (Record 200 pos 5) |
| `category` | varchar(100) | Free-text brand/grouping, e.g. "Lindner Feed". No lookup table — admins can add/rename by editing a product |
| `sort_order` | int | Controls within-category order AND implicit category order (categories render in ascending MIN(sort_order) of their products) |
| `selling_uom` | varchar(20) | Free text — `BAG-50`, `POUND`, `EACH`, `QT`, `GAL`, `CASE`, etc. Intentionally no controlled vocabulary |
| `base_uom` | varchar(10) | `POUND` or `EACH` — what FMM actually wants in Record 200 pos 8 |
| `conversion_factor` | decimal(12,4) | Multiplier: `qty_selling × factor = qty_base`. `1.0` for POUND→POUND or EACH→EACH, `50` for BAG-50→POUND, etc. |
| `site_id` | varchar(10) | Default `MATTHEWS` (Record 200 pos 12) |
| `created_at`, `updated_at` | datetime | |

**Excluded by design** (per product-table decisions):
- No price fields — all ORDIMP records will use `pricing_flag=0` so FMM re-prices via customer price list
- No multi-UoM per product — one selling UoM, like the live form
- No `is_active` flag — delete a product to hide it
- No customer-specific visibility — all customers see all products
- No marketing fields (image, long description, etc.)

**Still open:** `requires_vfd` flag for medicated feed (visible on the live form). Decide before Phase 4 (order form UI); costs nothing to add later since the column will default to `0`.

## Auth workflow walkthrough

**Login**
1. Visitor hits any page containing `[matthews_order]`. Shortcode router calls `MOP_Auth::current_user()` → no cookie → renders `templates/login.php`.
2. Form POSTs to `admin-post.php` with `action=mop_login` + nonce.
3. `MOP_Handlers::mop_login()` calls `MOP_User::find_by_email()` + `verify_password()`. On failure, redirects back to `?mop_view=login&mop_error=bad_credentials`.
4. On success, `MOP_Auth::login()` calls `MOP_Session::create()` → inserts a `mop_sessions` row with SHA-256(token), sets an HttpOnly + SameSite=Lax cookie `mop_auth` carrying the raw token. Redirects to `?mop_view=my-account`.
5. On subsequent requests `current_user()` reads the cookie, hashes it, looks up the session, resolves the user, caches per-request.

**Logout**
1. "Sign out" button on My Account POSTs `action=mop_logout` + nonce.
2. Handler deletes the session row by hashed token, clears the cookie, redirects to `?mop_view=login&mop_msg=logged_out`.

**Password reset (forgot password)**
1. Login page's "Forgot password?" → `?mop_view=request-password-reset`.
2. User submits email. `MOP_Handlers::mop_request_reset()`:
   - Looks up user; if found + active, generates a 32-byte random token, stores SHA-256(token) + 60-min expiry (`MOP_RESET_MINUTES`), then `MOP_Email::password_reset()` emails the user a link: `{shortcode_url}?mop_view=update-password&uid={id}&token={raw}`.
   - Regardless of match, shows the same "if registered, a link has been sent" message (enumeration defense).
3. User clicks link. `templates/update-password.php` validates the token via `MOP_User::find_by_reset_token()` (checks expiry + hash_equals). Invalid → redirect back to request flow.
4. User submits new password. `mop_reset_password()` validates length ≥ 8 + match, hashes via `wp_hash_password`, clears the reset token, **destroys all existing sessions for the user** (`MOP_Session::delete_all_for_user()` — forces re-login everywhere), sends `MOP_Email::password_update()` to user AND admin, redirects to login with success message.

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
│   ├── class-mop-handlers.php
│   ├── class-mop-ordimp.php
│   ├── class-mop-product.php
│   ├── class-mop-session.php
│   ├── class-mop-user.php
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

### 2026-04-20 — Phase 2b: products table

- Plugin/DB version bumped to `0.3.0`.
- `includes/class-mop-database.php`: added `mop_products` DDL via `dbDelta`. Columns: `id`, `fmm_item_number` (UNIQUE, 30, upper-cased), `description` (50), `category`, `sort_order`, `selling_uom`, `base_uom`, `conversion_factor`, `site_id`, timestamps. Categories are free text; ordering driven by `sort_order`.
- `includes/class-mop-product.php` (new): repository — `find / find_by_item_number / all / all_grouped_by_category / create / update / delete / convert_to_base / normalize_item_number`. `all_grouped_by_category()` returns the shape the order form will render: `[ "Lindner Feed" => [...products], "Sunglo Feed" => [...], ... ]` with stable category ordering.
- Schema decisions reflect: no pricing (always `pricing_flag=0` in ORDIMP), one selling UoM per product, no `is_active`, no customer-specific visibility, no marketing fields. All per product-model decisions from modeling the live matthewsfeedandgrain.com/order-form/ page.
- `requires_vfd` flag intentionally deferred — flagged as open in data-model section.

### 2026-04-20 — Phase 2a: users table + auth flow

- Plugin version bumped to `0.2.0`; added `MOP_SESSION_DAYS=30` and `MOP_RESET_MINUTES=60` constants.
- `includes/class-mop-database.php`: real DDL for `mop_users` and `mop_sessions` via `dbDelta`; install() now runs on both activation AND every boot so schema upgrades converge.
- `includes/class-mop-user.php` (new): user repository — `find / find_by_email / find_by_customer_id / create / update / verify_password / touch_last_login / issue_reset_token / find_by_reset_token / clear_reset_token / full_name`. Passwords via `wp_hash_password` + `wp_check_password`. Reset tokens: 32 random bytes, only SHA-256 hash persisted.
- `includes/class-mop-session.php` (new): session repository — `create` (returns row + raw token), `find_by_raw_token`, `delete_by_raw_token / _id / _all_for_user`, `purge_expired`.
- `includes/class-mop-auth.php`: real implementation — `current_user()` cached per request, cookie is HttpOnly + SameSite=Lax + Secure-when-SSL, 30-day expiry. `login()` / `logout()` / `require_login()`.
- `includes/class-mop-handlers.php` (new): admin-post handlers `mop_login`, `mop_logout`, `mop_request_reset`, `mop_reset_password` — all nonce-verified, all redirect back to the shortcode URL with `mop_msg` / `mop_error` query vars.
- `includes/class-mop-email.php`: real `password_reset` (user only) and `password_update` (user + admin) bodies; others still stubbed.
- `includes/class-mop-plugin.php`: boot now runs `MOP_Database::install()` + registers `MOP_Handlers`.
- `templates/login.php`, `templates/request-password-reset.php`, `templates/update-password.php`, `templates/my-account.php`: real forms with nonces, inline error/success messages, and a sign-out button on my-account.

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
