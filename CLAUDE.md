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
2. ✅ **Phase 2 — Database + auth**: `mop_users`, `mop_sessions`, `mop_products`, `mop_orders`, `mop_order_lines` via dbDelta, real cookie auth + login/logout/password-reset flow, `wp mop rebuild-db` CLI.
3. 🟨 **Phase 3 — Admin screens**: Users + Products list/add/edit/delete done. Orders admin (read-only list/detail + CSV export + ORDIMP download) done in Phase 4c. CSV **import** for users + products still to come.
4. ✅ **Phase 4 — Customer front-end**: edit account, order builder, submit, confirmation receipt.
5. ✅ **Phase 5 — ORDIMP + email wiring**: real FMM generator (CRLF, 25 fields, UoM math), writes to `wp-content/order/{user_id}/{order_id}/ORDIMP.dat`, attached to admin order-submission email.
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

### `mop_orders`

Snapshot-at-submit-time — once placed, an order never reflects later user/product edits. Customers cannot view their own history on the front-end (intentional: admin is the sole source of truth for past orders).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `po_number` | varchar(20) UNIQUE | `WEB-MFG-YYYYMMDD-NNN`; NNN resets daily but uniqueness is global |
| `user_id` | bigint FK | → mop_users.id — kept for filtering/reporting only |
| `customer_id_snapshot`, `company_snapshot`, `contact_first_name_snapshot`, `contact_last_name_snapshot`, `email_snapshot` | varchar | Snapshots — used to populate ORDIMP Record 100 + emails even if the user later edits their profile |
| `bill_to_*_snapshot`, `ship_to_*_snapshot` | varchar | Same pattern; `ship_to_*` is what FMM actually cares about |
| `order_type` | varchar(20) | `delivery` / `pickup` / `dock` |
| `comments` | text | Becomes Record 110 (chunked ≤100 chars, ≤500 total, commas/CRLF stripped) |
| `ordered_date`, `ordered_time` | date, time | Captured in WP timezone at submit |
| `ordimp_path` | varchar(500) | Absolute path to the generated `.dat` file; NULL until generation succeeds |
| `ordimp_generated_at` | datetime | |
| `created_at` | datetime | |

### `mop_order_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | bigint FK | → mop_orders.id |
| `line_number` | int | 1-based, assigned in submission order |
| `product_id` | bigint NULL | → mop_products.id; nullable so deleting a product doesn't orphan history |
| `fmm_item_number`, `description`, `category_snapshot` | varchar | Snapshots of the product at submit time |
| `selling_uom`, `base_uom`, `conversion_factor` | varchar, varchar, decimal(12,4) | |
| `qty_selling`, `qty_base` | decimal(12,4) | Both stored — `qty_selling` for display, `qty_base` for ORDIMP Record 200 pos 6 |
| `site_id` | varchar(10) | Usually `MATTHEWS` |
| `created_at` | datetime | |

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
│   ├── class-mop-admin.php
│   ├── class-mop-admin-products.php
│   ├── class-mop-admin-users.php
│   ├── class-mop-cli.php
│   ├── class-mop-email.php
│   ├── class-mop-handlers.php
│   ├── class-mop-ordimp.php
│   ├── class-mop-product.php
│   ├── class-mop-session.php
│   └── class-mop-user.php
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

### 2026-04-20 — Phase 4c: order submit, ORDIMP generator, emails, orders admin

- Plugin version bumped to `0.6.0`; schema version `0.4.0` (two new tables → auto-converges on next boot; `wp mop rebuild-db` not required but available).
- `includes/class-mop-database.php`: added `ddl_orders()` + `ddl_order_lines()`. Orders carry snapshot-at-submit copies of every user field so later profile edits don't retroactively change placed orders. `product_id` on lines is nullable so deleting a product doesn't orphan history. Indexed on `user_id` + `ordered_date`.
- `includes/class-mop-order.php` (new): repository. `find`, `find_by_po`, `get_lines`, `all_with_summary` (subquery line_count, used by admin list + CSV), `create($header, $lines)` (inserts header + loops lines with auto line_number + inside a transaction-like flow), `set_ordimp_path`, `next_po_number()` (`WEB-MFG-YYYYMMDD-NNN`, greatest-existing + 1 for today, zero-padded 3-digit — race protected by a 5× retry on the UNIQUE constraint in the submit handler), `snapshot_from_user($user)` maps user fields → `*_snapshot`, `order_type_label`.
- `includes/class-mop-ordimp.php`: real `generate($order, $lines)` — builds Record 100 (9 fields), Record 110 chunks (2 fields each, customer comments chunked ≤100 chars ≤500 total, commas/CRLF stripped — anything else would break delimiter/line structure), Record 200 per line with **25 fields** per FMM quirk (spec says 24; FMM silently drops lines <25). Record 200 uses pricing_flag=0, qty_base in pos 6, base UoM in pos 8, site `MATTHEWS` in pos 12. CRLF line endings + trailing CRLF. Writes to `wp-content/order/{user_id}/{order_id}/ORDIMP.dat`. Confirmed against `FMM_Order_Import_Reference_Guide-V2.pdf` + `ORDIMP.dat` sample.
- `includes/class-mop-handlers.php`: new `mop_submit_order` — validates cart + order type, saves any account edits (same validation as `mop_save_account` but silent — order emails already carry snapshots), assigns PO with retry, creates order, generates ORDIMP, wires path back onto the order, fires `order_notification` (customer) + `order_submission` (admin with attachment), redirects to confirmation. `collect_cart_lines()` parses `mop_line[<id>][product_id/qty]` from the JS cart and resolves product snapshots + base qty.
- `includes/class-mop-handlers.php`: new `mop_admin_download_ordimp` (capability-gated) + `mop_orders_csv` (wide format, one CSV row per line). **No customer-facing ORDIMP download endpoint** — customers can't download the file or re-view past orders.
- `includes/class-mop-email.php`: `order_notification($user, $order, $lines)` → customer receipt; `order_submission($user, $order, $lines, $ordimp_path)` → admin with `.dat` attached via `wp_mail`. Shared `render_order_summary()` renders the PO/items/ship-to block.
- `includes/class-mop-admin-orders.php` (new): admin Orders screen. List view with PO / Submitted / Customer / Type / Lines / ORDIMP columns + row actions + `Export CSV` page-title action. Detail view with header info + lines table. **Explicitly read-only** — no edit/add. Corrections are made by the customer re-submitting with a new PO.
- `templates/order-confirmation.php` (new): customer receipt shown immediately after submit. Owner-only (blocks sharing the URL). Items + comments + type + "Place another order" / "Back to account" actions. **Does not** expose the ORDIMP file or any link to past orders — customers never see order history.
- `templates/create-order.php`: submit no longer intercepted by JS; real error-alert block reads `?mop_error=` (empty_cart, invalid_order_type, email_*, save_failed, ordimp_failed).
- `templates/my-account.php`: **unchanged** — intentionally does not show any order history. Past orders are only viewable in the WP admin.
- `assets/js/matthewsorder.js`: removed the "submit not wired yet" preventDefault — only guard left is empty-cart.
- `matthewsorderplugin.php`: registers the new `class-mop-order.php` + `class-mop-admin-orders.php`.

### 2026-04-20 — Phase 4b: order creation UI (catalog search, modal, cart, order details) + brand color

- Plugin version bumped to `0.5.2` (schema unchanged — submit handler still unwired).
- `templates/partials/account-fields.php` (new): shared Contact / Billing / Shipping fieldsets + US state `<select>`. Caller must have `$user` in scope. Used by both `edit-account.php` and `create-order.php` so the editable-account form has exactly one source of truth.
- `templates/edit-account.php`: refactored to `include` the new partial — no behavioral change.
- `templates/create-order.php`: full UI implementation (no submit wiring yet). Layout: header with company + customer ID + "← Back to account"; Products section with `#mop-product-search` + scrollable `#mop-product-catalog` grouped by category with sticky category headings; "Your products" table with right-aligned qty + Modify/Remove buttons per row; Order details form including account-fields partial + "Type of order" select (Delivery / Pick up / Dock order) + Comments textarea (maxlength 1000). Form POSTs to unimplemented `mop_submit_order` action — JS intercepts with an alert.
- `assets/js/matthewsorder.js`: front-end cart behavior. `var cart = {}` keyed by product_id. Search filters items live (desc / fmm / category / uom), hides empty categories. Clicking a product opens a modal (`#mop-product-modal`) showing meta + qty input + live "order qty in base UoM" calculation via `qty × conversion_factor`. Cart renders `mop_line[<id>][product_id]` + `mop_line[<id>][qty]` hidden inputs so the DOM is ready for Phase 4c's backend. Modify button reopens modal with existing qty; Remove deletes and re-renders. ESC key + backdrop click close modal; `body.mop-modal-open` locks scroll.
- `includes/class-mop-assets.php`: `wp_localize_script` now includes a `strings` sub-object (add / update / modify / remove / emptyCart / notReady / invalidQty / totalBase) for JS i18n.
- `assets/css/matthewsorder.css`: styles for `.mop-order-section`, `.mop-product-search`, `.mop-product-catalog` (480px max-height, scrollable), sticky `.mop-product-category__heading`, `.mop-product-item__btn` flex row with UoM pill + monospace FMM, `.mop-cart-count` badge, `.mop-cart-table` with right-aligned qty + actions, `.mop-btn--sm`/`--danger`, `.mop-form-note` warning banner, `.mop-modal-overlay` fixed backdrop (z-9999) + `.mop-modal` dialog with meta grid, mobile @media breakpoint hiding FMM column on narrow screens.
- **Brand color switch: green → `#2b2976`** (indigo/purple). Applied everywhere in `matthewsorder.css`: primary button bg, page-title eyebrow + divider, category heading text, UoM pill bg+text, cart-count badge, modal total text, success alert, form button, etc. Darker hover state uses `#201f59`. Light tinted backgrounds (previously green-tinged) switched to purple-tinged equivalents (`#f7f7fc`, `#ecebf4`, `#ececf7`).

### 2026-04-20 — Phase 4a polish: page title, sign-out relocation, product catalog seed

- Plugin version bumped to `0.5.1` (schema unchanged).
- `includes/class-mop-shortcode.php`: every view now wraps with a shared `mop-page-title` banner ("Matthews Feed and Grain — Dealer Order Form") so customers always know which tool they're in. Rendered above the view container; templates keep their own h2 as the subsection header.
- `templates/my-account.php`: sign-out button relocated from the bottom of the page into the account header, positioned next to the company/contact name.
- `assets/css/matthewsorder.css`: styles for `.mop-page` / `.mop-page-title` banner (green eyebrow + bold heading, underlined divider). `.mop-account-header` now a flex row so sign-out sits top-right of the header block.
- `includes/data/products-seed.php` (new): full 159-product catalog scraped from the live https://matthewsfeedandgrain.com/order-form/ as of 2026-04-20. Organized by 4 brand categories (Lindner Feed, Sunglo Feed, Matthews Feed & Private Label, Show-Rite Feed). Each entry has a PLACEHOLDER FMM item number (brand-prefixed sequence, e.g. `LIN-001`) that must be replaced with real FMM Line Codes before production use. UoM / base / factor chosen per observed container (bagged feed → POUND, liquids/tools → EACH). 5 items had no size on the live form and were flagged inline with assumed defaults.
- `includes/class-mop-cli.php`: added `wp mop seed-products [--reset] [--dry-run]` command. Idempotent (upsert by `fmm_item_number`), applies `sort_order` in 100-steps per category with +10 between products so the admin has room to manually reorder.
- Seed executed locally: 159 products created, split 33 / 32 / 59 / 35 across the four categories.

### 2026-04-20 — Phase 4a: my-account + edit-account wire-up + account_change email

- Plugin version bumped to `0.5.0` (schema unchanged).
- `templates/my-account.php`: real layout — header with company/contact name + customer_id, primary "Submit an Order" CTA, account summary grid (company / contact / email), formatted billing + shipping address blocks, "Edit account info" button linking to `?mop_view=edit-account`, and a "Sign out" form. Renders success notice for `?mop_msg=account_updated`.
- `templates/edit-account.php`: real form — Contact / Billing / Shipping fieldsets. All fields editable EXCEPT `customer_id` (shown read-only in the header; it's the FMM key and must not drift). States rendered as a US state `<select>`. POSTs to `admin-post.php?action=mop_save_account` with nonce. Inline errors for `email_required` / `email_invalid` / `email_in_use`.
- `includes/class-mop-handlers.php`: added `mop_save_account` to the public actions list (nopriv too — MOP users aren't WP users). Handler verifies nonce + logged-in MOP session, validates email (required, valid, unique), normalizes all inputs, computes a human-readable diff vs. the stored row via new `diff_user_fields()` helper, calls `MOP_User::update()`, then fires `MOP_Email::account_change()` with the diff. Redirects to `?mop_view=my-account&mop_msg=account_updated`.
- `includes/class-mop-email.php`: implemented `account_change( $user, $changes )`. Sends to BOTH `user['email']` AND the admin email (per spec). Body includes a change-summary table rendered by `render_change_summary()` — one row per changed field with old (strikethrough) → new values. No-op if the diff is empty, so an unchanged form resubmit won't spam anyone.
- `assets/css/matthewsorder.css`: layout styles for the new views — `.mop-account-header`, `.mop-cta-row`, button system (`.mop-btn`, `--primary`, `--secondary`, `--large`, `--link`), `.mop-summary-grid` (responsive 1/3 col), `.mop-address-block`, `.mop-fieldset`, `.mop-form-row` + `--city-state-zip`, `.mop-form-actions`.

### 2026-04-20 — Phase 3a: CLI rebuild + users/products admin + new-user email

- Plugin version bumped to `0.4.0` (schema unchanged).
- `includes/class-mop-cli.php` (new): registers `wp mop rebuild-db [--yes]`. Lists the tables that will be dropped, confirms, then calls `MOP_Database::drop_all()` + `install()`.
- `includes/class-mop-database.php`: added `known_tables()` and `drop_all()` (drops every `mop_*` table and deletes `mop_db_version` so install re-runs).
- `includes/class-mop-admin-users.php` (new): Users admin — list (WP-style table with row actions), Add New / Edit form with Identity / Contact / Billing / Shipping sections. Customer ID is read-only after creation. Password field is required on add, optional on edit; plaintext "Generate" helper. "Send credentials email" checkbox, default-on for new users.
- `includes/class-mop-admin-products.php` (new): Products admin — list grouped by category, Add New / Edit form. `base_uom` is a controlled `POUND / EACH` select; `selling_uom` stays free text. `conversion_factor` is numeric with 4-decimal precision.
- `includes/class-mop-admin.php`: delegated `render_users` / `render_products` to the new classes.
- `includes/class-mop-handlers.php`: added `mop_save_user`, `mop_delete_user`, `mop_save_product`, `mop_delete_product` (admin-only via capability check + nonce). Uniqueness checks on email + customer_id + fmm_item_number. Sends `MOP_Email::new_user()` when "send credentials" is checked and a password is present.
- `includes/class-mop-email.php`: added `new_user()` — sends login URL, email (as username), and plaintext password.
- `includes/class-mop-user.php`: added `all()` and `delete()`.
- `includes/class-mop-plugin.php`: registers `MOP_CLI::register()` on boot.
- `assets/css/matthewsorder.css`: polished front-end auth forms — layout, typography, buttons, alert boxes, account summary grid.

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
