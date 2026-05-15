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
| `web_description` | varchar(100) NULL | Customer-facing display name (falls back to `description` on the order form). Sourced from the client's "Web Order Form Item Description" column. |
| `uom_schedule` | varchar(20) NULL | FMM UoM schedule string (`POUND-4`, `EACH-4`). Stored verbatim; ORDIMP only writes the prefix (`POUND` / `EACH`). |
| `requires_vfd` | tinyint(1) | Medicated-feed VFD/AOD flag. Sourced from the client's "VFD/AOD REQUIRED" column. |
| `minimum_order_qty` | varchar(40) NULL | Free-text minimum-order messaging (`1 TON MINIMUM`, `NO MINIMUM`, blank). Display-only. |
| `sold_individually` | tinyint(1) NULL | Yes / No / Unknown. Display flag. |
| `created_at`, `updated_at` | datetime | |

**Excluded by design** (per product-table decisions):
- No price fields — all ORDIMP records will use `pricing_flag=0` so FMM re-prices via customer price list
- No multi-UoM per product — one selling UoM, like the live form
- No `is_active` flag — delete a product to hide it
- No customer-specific visibility — all customers see all products
- No marketing fields (image, long description, etc.)

**Open items resolved:** `requires_vfd` and three other source-spreadsheet metadata fields (`web_description`, `uom_schedule`, `minimum_order_qty`, `sold_individually`) were added in DB v0.5.0 to support the client's `Order Import Item List` CSV import.

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

### 2026-05-15 — Customer/User split + combined CSV import

- Plugin version `0.7.1` → `0.8.0`; DB version `0.5.0` → `0.6.0`. dbDelta creates `mop_customers` + `mop_user_customers`, then a one-time migration moves customer-identity columns off `mop_users` into the new tables (idempotent; safe to call on every boot).
- **New schema shape**:
  - `mop_customers` — FMM-side entity (one row per Customer Number). Holds `customer_id` (UNIQUE), `company_name`, `phone`, full `bill_to_*` + `ship_to_*`. Column names preserved from where they used to live on `mop_users`.
  - `mop_users` — now login-only. `email` is NO LONGER UNIQUE. Columns: `email`, `password_hash`, `contact_first_name`, `contact_last_name`, `is_active`, reset-token fields, timestamps.
  - `mop_user_customers` — bridge. `user_id`, `customer_id` (FK to internal PK), `is_default`, `created_at`. UNIQUE on the pair → attach() is naturally idempotent.
  - `mop_sessions` gained `active_customer_id` so a session tracks which customer the user is currently acting on behalf of.
- **Login flow**: `MOP_Handlers::mop_login()` now collects every (user, customer) pair that matches the submitted email + password. One pair → direct login. Multiple pairs → stash credentials in a 5-min transient, redirect to `?mop_view=pick-account&token=...` for the new picker template (`templates/pick-account.php`). `mop_pick_account` finishes the handshake. Same email under multiple customers (`adambeck04@hotmail.com` in the source data) works cleanly.
- **Customer switching**: `MOP_Auth::switch_customer()` flips the session's active customer. `mop_switch_customer` admin-post action + a switcher form on `my-account` for users with multiple attachments.
- **Order submission**: `MOP_Order::snapshot_from_user_and_customer($user, $customer)` builds the order header snapshot from both entities. The user provides identity (email + contact name); the customer provides FMM number, company, addresses. `mop_orders` schema didn't change — snapshots are by-value.
- **Combined CSV import** (replaces the standalone users CSV import, which was retired):
  - Lives on the Customers admin (`?page=mop_customers`). `Add New / Import CSV / Export CSV / Download example CSV` page-title-actions.
  - One row per customer + up to 5 user slots inline: `user_N_email`, `user_N_first_name`, `user_N_last_name`, `user_N_password` (N = 1..5).
  - Upserts customers by `customer_id`, users by `email`, bridges by (user_id, customer_id). Idempotent re-imports.
  - **Additive only** — never deletes bridges, users, or customers. Revoking access is a manual admin action via the Customers or Users edit screen.
  - `user_N_password` is hashed at parse time; preview transient never holds plaintext. Existing users keep their password if password is blank.
  - Zero-email customer rows are accepted (placeholder customers; no users/bridges created).
  - New `MOP_Admin_Customers_Import` class encapsulates parse + apply + render logic; new `MOP_Customer` + `MOP_UserCustomer` repositories handle the new tables.
  - Bundled `data/customers-import-example.csv` shows the full layout including a row with all 5 user slots populated.
- **Admin UI**:
  - New `Customers` submenu (above Users). List view with Customer ID / Company / City-State / # Users / Active. Edit form for identity + addresses + an "Attached users" table with detach links + an "Attach a user" lookup-by-email widget.
  - `Users` admin shrunk: only email, password, contact name, active flag in the edit form; an "Attached customers" table with detach + attach-by-FMM-id widgets. CSV import button is gone (combined import lives on the Customers screen instead).
  - New bridge handlers `mop_attach_user_customer` / `mop_detach_user_customer` work from both the user and customer edit screens (controlled by a `return` POST field).
- **Email audit**: all six emails (`new_user`, `password_reset`, `password_update`, `account_change`, `order_notification`, `order_submission`) use a shared `MOP_Email::greeting_name($user)` helper that returns `first last` when present, else the email address. No "Hi ," artifacts when names are blank — the common case for users created via CSV import. `account_change` and `order_submission` were updated to take a `$customer` argument so the admin-side body can render the customer label correctly.
- **Order snapshot path**: `mop_save_account` + `apply_account_edits_from_post` were re-split to write user fields to `mop_users` and customer fields to `mop_customers` separately. Email-uniqueness validation now checks *within the active customer's user set* (not globally) — the same email under different customers is allowed by design.
- **Retired**: `data/users-import-example.csv`, the standalone Users CSV import handlers (`mop_users_csv_export/example/import_preview/import_apply`), `MOP_User::csv_columns()`, the bulk-import-from-users-CSV UI on the Users admin. The customer CSV is now the single bulk path.

### 2026-05-15 — Category column in product CSV, example-CSV buttons on list views, order delete

- Plugin version `0.7.0` → `0.7.1`. Schema unchanged.
- **Category is now a CSV column.** `MOP_Product::csv_columns()` includes `category` (4th position, between `web_description` and `uom_schedule`). The example CSV shows real category values per row. Behavior:
  - Non-empty in CSV → overwrites the row's category.
  - Blank or missing → on update, existing category is preserved; on create, the new row's category is `NULL` ("Uncategorized" on the order form).
  - The export now emits the category. Round-trip works: export, edit categories in Excel, re-import.
- **"Download example CSV" page-title-action** added to the Products list (`?page=mop_products`) and the Users list (`?page=mop_users`). Previously the example was only linked from inside the Import sub-view; now it's reachable directly from the main admin list. Both routes go through the same nonced admin-post endpoint so the capability gate is unchanged.
- **Orders admin gains delete actions** (`?page=mop_orders`):
  - Per-row `Delete` link in the row-actions strip with a JS `confirm()` prompt. Calls `mop_delete_order` — nonce-protected by order id. Removes the order row, its line items, and the ORDIMP.dat file on disk (and the per-order parent dir if it becomes empty).
  - `Delete all orders (N)` page-title-action (red) shown only when N > 0. Goes to a dedicated `?action=delete-all-confirm` view with a required text input — admin must type `DELETE` (case-insensitive). Submits to `mop_delete_all_orders` which validates the typed string, then calls `MOP_Order::delete_all()` to wipe both tables + every ORDIMP file. Mismatched confirmation bounces back to the same form with a notice; no destructive action happens.
  - Customer accounts, products, and the underlying `wp-content/order/` deny-all `.htaccess` + `index.html` are not touched. Order history snapshots are gone — there is no soft-delete or trash.

### 2026-05-15 — Products CSV bulk import + export (client Order Import Item List)

- Plugin version `0.7.0`; DB version `0.5.0`. `dbDelta` auto-adds five new columns to `mop_products` on next boot: `web_description`, `uom_schedule`, `requires_vfd`, `minimum_order_qty`, `sold_individually`. No data loss; `wp mop rebuild-db` available as a fallback.
- `data/products-import-example.csv` (new): downloadable template matching the client's source spreadsheet column layout exactly.
- `includes/class-mop-product.php`: new helpers
  - `csv_columns()` — single source of truth for the import/export column order: `fmm_item_number, fmm_description, web_description, uom_schedule, selling_uom, vfd_required, minimum_order, sold_individually`. Admin-only metadata (category, sort_order, base_uom, conversion_factor, site_id) is intentionally NOT in this list.
  - `derive_base_uom($uom_schedule)` — returns `POUND` / `EACH` / `null` (Section 6 of FMM reference says those are the only two valid base UoMs; anything else fails import).
  - `derive_conversion_factor($selling_uom)` — parses the trailing number out of `BAG-50` / `PAIL-20` / etc. Returns `null` for ambiguous strings like `EACH 7.5`, which fail import.
  - `parse_bool($val)` — tolerant of the client's vocabulary (`VFD/AOD REQUIRED`, `YES`, `NO`, blank).
- `includes/class-mop-handlers.php`: five new capability-gated admin-post handlers:
  - `mop_products_csv_export` — dumps all products in the 8-column source layout. `requires_vfd=1` emits the literal `VFD/AOD REQUIRED` to round-trip with the client's spreadsheet. Formula-injection defang applied to text cells.
  - `mop_products_csv_example` — serves the bundled example file.
  - `mop_products_import_preview` — parses + validates upload, partitions into create / update / errors / normalization-notices, stashes in a 1-hour transient, redirects to preview.
  - `mop_products_import_apply` — applies the plan. Updates touch only the 8 CSV-sourced fields + derived `base_uom` + `conversion_factor` — `category`, `sort_order`, and `site_id` are preserved on existing rows.
  - `mop_products_wipe_seed` — one-shot teardown matching FMM numbers like `LIN-%`, `SUN-%`, `MFG-%`, `SR-%`, `SHO-%` (the placeholder patterns from the original `data/products-seed.php`). Order history preserved (orders snapshot product fields at submit time).
- `includes/class-mop-admin-products.php`: list view gains `Add New / Import CSV / Export CSV / Wipe placeholder seed` page-title-actions (Wipe shows only when seed rows exist). VFD column added to the list. Edit form gains web_description / uom_schedule / requires_vfd / minimum_order_qty / sold_individually fields. Two new sub-views (`?action=import` / `?action=import-preview`) follow the same pattern as the users CSV flow.
- **Wipe-and-replace mode**: the upload form has an optional "Wipe ALL existing products before applying" checkbox for first-real-import scenarios. Preview screen surfaces a red second-confirmation checkbox when this is on.
- **Validation rules grounded in FMM reference**:
  - `uom_schedule` must match `POUND`, `EACH`, `POUND-N`, or `EACH-N` (N digits only). Bare prefixes accepted for forward-compatibility; anything else (e.g. `EACH-LBS`) is rejected, since FMM Section 6 only accepts POUND or EACH as base UoMs.
  - `selling_uom` must derive to a positive conversion factor (`POUND`, `EACH`, or `PREFIX-N` like `BAG-50`).
  - `EACH 7.5`, `EACH-LBS`, and similar ambiguous one-offs go in the errors bucket — admin fixes in the source spreadsheet OR adds the product manually via Add Product, where the conversion factor + base UoM can be set explicitly.
  - Lowercase `bag-50` is silently normalized to `BAG-50` and logged as a normalization notice.
  - `fmm_description > 50` is truncated to 50 (FMM Record 200 pos 5 limit) and logged as a notice.
  - In-file duplicate `fmm_item_number` → row 2 errors, row 1 wins.

### 2026-05-01 — Users CSV bulk import + export

- Plugin version bumped to `0.6.1` (schema unchanged).
- `data/users-import-example.csv` (new): downloadable template with the canonical column order and three sample rows (one with full address, one minimal, one inactive).
- `includes/class-mop-user.php`: added `csv_columns()` — single source of truth for the column order shared by export, import, the example file, and the admin UI hint text.
- `includes/class-mop-handlers.php`: four new admin-post handlers, all capability-gated:
  - `mop_users_csv_export` — streams all users as CSV. Cells starting with `=` `+` `-` `@` `\t` `\r` are prefixed with `'` to neutralize Formula Injection (OWASP).
  - `mop_users_csv_example` — serves the bundled example file (same gate, no reason to hand the template to non-admins).
  - `mop_users_import_preview` — accepts the CSV upload, parses + validates every row (required customer_id + email; valid email format; no duplicate customer_ids; no email collision with a different customer_id; UTF-8 BOM-tolerant), partitions rows into create / update / errors, stashes the result in a 1-hour transient keyed by a token, redirects to the preview screen.
  - `mop_users_import_apply` — reads the transient, applies inserts via `MOP_User::create()` and overwrite-updates via `MOP_User::update()`, deletes the transient, redirects with counts.
- `includes/class-mop-admin-users.php`: list view now has `Add New / Import CSV / Export CSV` page-title-actions. Two new sub-views:
  - `?action=import` — upload form with the example-CSV download link, a warning notice explaining the customer_id-as-key overwrite semantics, and an inline column reference (required vs optional).
  - `?action=import-preview&token=...` — totals line ("X created, Y OVERWRITTEN, Z skipped"), per-row tables for each bucket, and a confirmation form. If any updates are present the admin must tick a "I understand this will overwrite N existing users" checkbox before the apply button submits. New `users_imported` notice on success carries the create/update/error counts in the redirect URL.
- **Password column is optional.** Plaintext on import (≥8 chars), always blank on export (we never expose the hash either). On parse, the plaintext is hashed via `wp_hash_password()` immediately and stored as `password_hash` in the preview transient — the transient never holds plaintext. On import:
  - new user with password → user is created and can sign in
  - new user without password → user is created with no `password_hash`; cannot sign in until admin sets one (Edit User) or they use forgot-password
  - existing user with password → password is **reset** (warned about with a separate red confirmation checkbox)
  - existing user without password → existing password preserved
  - Preview shows per-row "Will be set / Will be reset / None / Unchanged" so the admin sees exactly which rows touch credentials.
  - Upload screen carries a notice telling admins to treat any CSV containing passwords as sensitive (don't email, don't commit, delete after import).
- Order history is preserved across overwrites — orders snapshot user fields at submit time, so a CSV import can't retroactively change past orders.

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
