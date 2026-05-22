# Multi-Shipping-Address Feature — Implementation Plan

> Self-contained spec for adding **multiple shipping addresses per customer**.
> Written so it survives a context compaction — assume the implementer has
> NO memory of the conversation that produced it. Everything needed is here.

## Goal

Today a customer (`mop_customers`) has exactly **one** embedded shipping
address (`ship_to_*` columns). We need a customer to have **many** shipping
addresses (an address book), while keeping **one billing address** (billing
stays on `mop_customers` — FMM invoices to the single customer number).

Customers place orders against a chosen shipping address (pick an existing
one or add a new one at order time). Admins manage the address book per
customer. The CSV import still imports **only one** shipping address per
customer row (becomes that customer's default address).

## Hard constraint — ORDIMP is unaffected

The FMM stock-item `ORDIMP.DAT` format carries **no ship-to address**:
- Record 100 (9 fields): record type, PO #, Customer ID, Customer Name,
  ordered date/time, delivery date/time, split-billing ID.
- Record 110: comment.
- Record 200 (25 fields): line items + Site ID; the location codes
  (Group/Farm/Barn/Room/Pen/Bin, fields 13–18) are explicitly "leave blank".

So the selected shipping address is captured in the **order snapshot**
(`mop_orders.ship_to_*_snapshot`, already present) and shown in the
**admin notification email** — that's how the Matthews team knows where to
ship. `MOP_Ordimp::generate()` needs **no change**.

## Environment facts (for the implementer)

- WordPress table prefix on the dev box: `wp_x391l5lk80_` (so plugin tables
  are e.g. `wp_x391l5lk80_mop_customers`). Always use `MOP_Database::table()`
  / the repository `table()` helpers — never hardcode the prefix.
- Local MySQL (dev): host `127.0.0.1`, port `10006`, db `local`, user/pass
  `root`/`root`. MySQL 8.0.35. mysql.exe at
  `…/Local/resources/extraResources/lightning-services/mysql-8.0.35+4/bin/win64/bin/mysql.exe`.
- PHP lint binary:
  `…/Local/resources/extraResources/lightning-services/php-8.2.29+0/bin/win64/php.exe -l <file>`
- Current versions: plugin `0.8.0`, `MOP_DB_VERSION` `0.6.0`.
- Brand color `#2b2976`.

## Current schema (DB v0.6.0) — what exists now

- `mop_customers`: `id`, `customer_id` (FMM #, UNIQUE), `company_name`,
  `bill_to_line1/2`, `bill_to_city/state/zip`, `ship_to_line1/2`,
  `ship_to_city/state/zip`, `phone`, `is_active`, `created_at`, `updated_at`.
- `mop_users`: `id`, `email` (NOT unique), `password_hash`,
  `contact_first_name`, `contact_last_name`, `is_active`, reset fields,
  `last_login_at`, timestamps.
- `mop_user_customers`: bridge `id`, `user_id`, `customer_id` (→customers.id),
  `is_default`, `created_at`. UNIQUE(user_id, customer_id).
- `mop_sessions`: `id`, `user_id`, `active_customer_id`, `token_hash`, …
- `mop_orders`: snapshots incl. `ship_to_line1/2_snapshot`,
  `ship_to_city/state/zip_snapshot`, plus `customer_id_snapshot`,
  `company_snapshot`, `bill_to_*_snapshot`, etc.
- `mop_products`, `mop_order_lines`.

## Target schema (DB v0.7.0)

### New table `mop_customer_addresses`
```
id            bigint(20) unsigned PK AUTO_INCREMENT
customer_id   bigint(20) unsigned NOT NULL          -- FK → mop_customers.id
label         varchar(60) DEFAULT NULL              -- e.g. "Main Barn", "South Field"
ship_to_line1 varchar(100) DEFAULT NULL
ship_to_line2 varchar(100) DEFAULT NULL
ship_to_city  varchar(50)  DEFAULT NULL
ship_to_state varchar(2)   DEFAULT NULL
ship_to_zip   varchar(10)  DEFAULT NULL
is_default    tinyint(1) NOT NULL DEFAULT 0         -- one default per customer
is_active     tinyint(1) NOT NULL DEFAULT 1
created_at    datetime NOT NULL
updated_at    datetime NOT NULL
PRIMARY KEY (id)
KEY customer_id (customer_id)
KEY is_default (is_default)
```

### `mop_customers` — drop the embedded ship-to
Remove `ship_to_line1`, `ship_to_line2`, `ship_to_city`, `ship_to_state`,
`ship_to_zip`. Keep billing (`bill_to_*`) on the customer. Single source of
truth for shipping becomes `mop_customer_addresses`.

### `mop_orders` — no change
`ship_to_*_snapshot` already exist; we just populate them from the chosen
address at submit time.

### One-time migration `migrate_customer_ship_to_addresses()`
Run in `MOP_Database::install()` AFTER `dbDelta` creates the new table.
Idempotent (guard on whether `mop_customers.ship_to_line1` column still
exists):
1. If `mop_customers` still has `ship_to_line1` column:
   - For each customer row with ANY `ship_to_*` populated, insert one
     `mop_customer_addresses` row: copy the 5 ship fields, `label = 'Primary'`,
     `is_default = 1`, `is_active = 1`.
   - Customers with all-empty ship_to get no address row (fine).
   - `ALTER TABLE mop_customers DROP COLUMN ship_to_line1 … ship_to_zip` (only
     columns that still exist — check `SHOW COLUMNS`).
2. Bump `MOP_DB_VERSION` to `0.7.0` in `matthewsorderplugin.php`.

Follow the existing pattern of `migrate_user_customer_split()` in
`includes/class-mop-database.php` (column-existence guards via `SHOW COLUMNS`,
`SHOW INDEX`, manual `ALTER`).

## New repository — `includes/class-mop-customer-address.php`

`class MOP_Customer_Address` (mirror the style of `MOP_Customer`):
- `table()` → `MOP_Database::table('customer_addresses')`
- `find($id)`
- `all_for_customer($customer_id)` — `ORDER BY is_default DESC, label ASC, id ASC`
- `active_for_customer($customer_id)` — same but `is_active = 1`
- `default_for_customer($customer_id)` — the `is_default=1` row, else the
  earliest active row, else null
- `count_for_customer($customer_id)`
- `create($data)` — if this is the customer's first address, force
  `is_default = 1`
- `update($id, $data)`
- `delete($id)` — if the deleted row was the default and others remain,
  promote the earliest remaining to default
- `set_default($customer_id, $address_id)` — clears `is_default` on all of the
  customer's rows, sets it on `$address_id` (verify it belongs to the customer)
- `format_one_line($address)` — "line1, line2, city, ST zip" for dropdowns
- `label_for($address)` — `label` or the one-line address as fallback

Register the file in `matthewsorderplugin.php` requires (next to
`class-mop-customer.php`).

## `MOP_Customer` changes (`includes/class-mop-customer.php`)
- Remove `ship_to_*` from `defaults()`.
- `create()` / `update()` no longer accept ship_to columns (they're gone).
- `delete($id)` — also delete the customer's address rows
  (`MOP_Customer_Address::delete_all_for_customer()` — add that helper).
- Optional convenience: `default_ship_address($customer)` proxy.

## Import changes (`includes/class-mop-admin-customers-import.php` + handlers)

CSV format **unchanged** — still one shipping address per row
(`ship_to_line1/2/city/state/zip`). Behavior changes under the hood:
- `parse_csv()`: build `customer_data` WITHOUT ship_to columns. Capture the
  ship_to fields into a separate per-row `address` array.
- `apply()`:
  - Create/update the customer (billing + company + phone only).
  - Then **upsert ONE default address**:
    - On create: insert a `mop_customer_addresses` row (`is_default=1`,
      `label='Primary'`) if any ship field is non-empty.
    - On update (existing customer): update that customer's existing default
      address in place if one exists; else insert one. **Never delete other
      addresses** (import is additive — matches the existing additive policy).
- Preview tables: the "city/state" columns now read from the per-row
  `address` array instead of `customer_data`.
- Export (`mop_customers_csv_export`): emit the customer's **default**
  address into the single `ship_to_*` columns.

## Order snapshot (`includes/class-mop-order.php`)
- Change `snapshot_from_user_and_customer($user, $customer)` →
  `snapshot_from_user_and_customer($user, $customer, $address = null)`.
- `ship_to_*_snapshot` come from `$address` (the chosen
  `mop_customer_addresses` row); fall back to customer default if null.
- Keep all other snapshot fields as-is.

## Order submission (`includes/class-mop-handlers.php :: mop_submit_order`)
- Read `$_POST['ship_address_id']`:
  - If `> 0`: load the address, verify it belongs to the active customer
    (`MOP_Customer_Address::find` + `customer_id` match). Reject otherwise.
  - If `new` (sentinel) or `0`: read the new-address fields
    (`new_ship_to_line1/2/city/state/zip`, `new_ship_label`), validate (line1
    + city + state + zip minimally), create the address on the active
    customer (becomes default only if it's the customer's first), use it.
- Pass the resolved `$address` into `snapshot_from_user_and_customer()`.
- `apply_account_edits_from_post()` no longer writes ship_to (those fields
  leave the inline account form). Billing + company still update the customer;
  email + contact name still update the user.
- Add error codes: `no_ship_address` (none selected and none on file),
  `ship_address_invalid`.

## Shared partial (`templates/partials/account-fields.php`)
- Remove the **Shipping address** fieldset. Keep **Contact** (user) +
  **Billing address** (customer) only.
- The shipping UI is rendered separately by each caller (different UX for
  edit-account vs create-order).

## Front-end — `templates/create-order.php`
- Add a **Shipping** section above/within Order details:
  - `<select name="ship_address_id">` listing
    `MOP_Customer_Address::active_for_customer($customer['id'])` with the
    default pre-selected; each option shows `label_for()` / one-line address.
  - A final option `+ Add a new shipping address` that reveals a fieldset of
    `new_ship_*` inputs (JS toggle in `assets/js/matthewsorder.js`).
  - If the customer has zero addresses, show the new-address fields directly
    and require them.
- The cart/products UI is unchanged.

## Front-end — `templates/edit-account.php` + my-account
- `edit-account.php`: Contact + Billing (via partial) **plus** a
  **Shipping address book** section:
  - Table of the customer's addresses (label, one-line, Default badge,
    Active) with Edit / Delete / Make default actions.
  - An "Add shipping address" form.
- `my-account.php`: Shipping section now lists all active addresses (default
  marked) instead of the single address. Read via
  `MOP_Customer_Address::all_for_customer()`.

### New front-end handlers (`includes/class-mop-handlers.php`)
All verify `MOP_Auth::current_user()` + `current_customer()` and that the
target address belongs to the **active** customer:
- `mop_add_ship_address`
- `mop_edit_ship_address`
- `mop_delete_ship_address`
- `mop_set_default_ship_address`
Register in the `$public_actions` list. Redirect back to `edit-account`
(or `my-account`) with `mop_msg` / `mop_error`.

## Admin — Customers screen (`includes/class-mop-admin-customers.php`)
- Edit Customer view: replace the single "Shipping address" fieldset with an
  **address book** table (label / one-line / default / active) + row actions
  (Edit, Delete, Make default) + an "Add address" form. Billing fieldset
  stays.
- Customers **list** view: add an "Addresses" count column (optional, nice).
- New admin-post handlers (capability-gated, `MOP_Admin::CAPABILITY`):
  - `mop_admin_save_address` (add/edit)
  - `mop_admin_delete_address`
  - `mop_admin_set_default_address`
  Register in `$admin_actions`. These can share validation helpers with the
  front-end ones but keep separate auth (capability vs session-owner).

## Admin — Dashboard (`includes/class-mop-admin.php`)
- No structural change required. Optional: the Customers card description can
  mention address books. Counts query unaffected.

## Emails (`includes/class-mop-email.php`)
- `order_notification` + `order_submission`: **no change needed** — they
  already render `ship_to_*_snapshot`, which now reflects the chosen address.
- `account_change`: address-book edits do **not** fire account_change emails
  (would be noisy). Document this. (If desired later, add a dedicated
  "shipping address added/changed" notice — out of scope for v1.)
- Verify the admin order email clearly labels which shipping location was
  selected (it already prints the ship-to block).

## CSV export note
`mop_customers_csv_export` emits one shipping address (the default) so the
exported file round-trips through the importer. Non-default addresses are NOT
exported (the CSV format only has one ship slot, by design).

## File-by-file task checklist

- [ ] `matthewsorderplugin.php` — bump `MOP_VERSION` 0.8.0→0.9.0,
      `MOP_DB_VERSION` 0.6.0→0.7.0; `require_once` new
      `class-mop-customer-address.php`.
- [ ] `includes/class-mop-database.php` — `ddl_customer_addresses()`; add to
      `install()`; add `'customer_addresses'` to `known_tables()`; write
      `migrate_customer_ship_to_addresses()`; call it in `install()`.
- [ ] `includes/class-mop-customer-address.php` — NEW repository.
- [ ] `includes/class-mop-customer.php` — drop ship_to from defaults/CRUD;
      cascade-delete addresses; optional `default_ship_address()`.
- [ ] `includes/class-mop-order.php` — `snapshot_from_user_and_customer()`
      gains `$address`.
- [ ] `includes/class-mop-handlers.php` — `mop_submit_order` address
      resolution; new front-end address handlers; new admin address handlers;
      register both in action lists; export reads default address;
      `apply_account_edits_from_post` drops ship_to.
- [ ] `includes/class-mop-admin-customers.php` — address book UI + notices.
- [ ] `includes/class-mop-admin-customers-import.php` — route ship_to into the
      address table on apply; preview reads from address array.
- [ ] `templates/partials/account-fields.php` — remove Shipping fieldset.
- [ ] `templates/create-order.php` — shipping selector + add-new reveal.
- [ ] `templates/edit-account.php` — shipping address book section.
- [ ] `templates/my-account.php` — list all addresses, mark default.
- [ ] `assets/js/matthewsorder.js` — toggle the "add new shipping address"
      fieldset on the order form.
- [ ] `CLAUDE.md` — changelog entry for DB v0.7.0 / plugin 0.9.0.
- [ ] Lint every changed PHP file.
- [ ] `data/customers-import-example.csv` — unchanged (still one ship address)
      but re-verify it parses.

## Test checklist (post-build)

- [ ] Fresh boot runs the migration: existing single ship_to becomes a
      default address row; `mop_customers` loses ship_to columns.
- [ ] Re-boot is a no-op (idempotent migration).
- [ ] Customer admin: add 2nd/3rd address, set default, delete default
      (promotes another), delete non-default.
- [ ] Import a customer CSV: customer gets exactly one default address; a
      re-import updates that default and does NOT duplicate or wipe extras
      added manually.
- [ ] Front-end edit-account: full address-book CRUD scoped to active
      customer; cannot touch another customer's addresses (ownership check).
- [ ] create-order: pick an existing address → order snapshot matches it;
      add a new address at order time → it's saved on the customer AND
      snapshotted; customer with zero addresses is forced to enter one.
- [ ] Multi-customer user: switch active customer → address list changes to
      the new customer's book.
- [ ] Order confirmation + admin email show the chosen shipping address.
- [ ] ORDIMP.dat is byte-identical to pre-feature output for the same line
      items (address never entered the file).
- [ ] CSV export emits the default address; round-trips through import.
- [ ] All admin list filters + dashboards still render.

## Versioning
- Plugin `0.8.0` → `0.9.0`
- DB `0.6.0` → `0.7.0`

## Deferred / out of scope (note for later)
- Per-address contact person, delivery instructions, geocoding.
- Emailing on address-book changes.
- Multi-address columns in the CSV import (intentionally single).
- Exposing non-default addresses in the CSV export.
