# Runtime verification — UniCredit OpenCart 3.x (Phase 0)

This document separates facts established from the workspace/references from facts that **must** be collected on the test server.

Never send real passwords, CP secrets, private keys, passphrases, bearer tokens, or EGN in chat or tickets. For certificates/secrets request only: presence, path, owner/group, permissions, and hashes/fingerprints.

Substitute `<DB_PREFIX>` (often `oc_`), `<PHP>`, and filesystem paths supplied by the operator.

---

## Known from workspace

These are source characterizations, not live shop measurements.

### OpenCart

- Local core reference `reference-oc3-core` defines `VERSION` = **`3.0.3.9`** in `index.php` / `admin/index.php`.
- Formal **targets** are also 3.0.3.6 and 3.0.3.8; those trees are **not** in the workspace.
- Native checkout on 3.0.3.9: `checkout/confirm` always `addOrder()`; `session.order_id` set immediately; payment loaded as `extension/payment/{code}`; `addOrder()` omits `order_status_id` (DB default **0**); `addOrderHistory()` updates status + history; success clears session/cart only.
- Payment discovery: `admin/controller/extension/extension/payment.php` globs `controller/extension/payment/*.php`; catalog `getMethod($address, $total)` with totals pipeline.
- Settings: `editSetting('payment_{code}', $post)` → keys `payment_{code}_*`, store-scoped.
- OCMOD: `install.xml` stored in `modification`; installer is `marketplace/installer`.
- PHP floor of this 3.0.3.9 tree: `system/startup.php` and installer require **PHP 7.3.0+** (`PHP7.3+ Required`).
- Default language pack in core: `en-gb`. `bg-bg` is a shop/language-pack runtime fact.
- Mail: `system/library/mail.php` plus `mail/mail.php` / `mail/smtp.php`. Engine is configured per shop.
- `DIR_STORAGE` is the conventional protected writable location (often `system/storage/`, sometimes moved outside the web root).

### UniCredit contracts (from `reference-uni-oc4`)

- Module code `mt_uni_credit`, version **`2.0.2`** (D2 closed).
- Module PHP floor **7.3.0+** (D1 closed) — deliberate module support across the practical OC3 matrix; not a universal OC 3.0.3.6 core requirement.
- CP API prefix `/api/v1`; SmartUCF hosts `online.ucfin.bg` / `onlinetest.ucfin.bg` (D4 closed for Phase 0).
- HMAC replay protocol as in `tests/fixtures/hmac_callback_vector.json`.
- Secret filenames `secrets/smartucf-key.php`, `keys/avalon_cert.pem`, `keys/avalon_private_key.pem` (D3 closed for Phase 0).
- Settings encryption contract: HKDF from `DB_PASSWORD` + AES-256-GCM `enc:v1:` (Phase 2 semantic verification against OC4 implementation).

### JET OC3 (`reference-jet-oc3`)

- Package: `install.xml` + `upload/{admin,catalog,system}`.
- Payment classes under `extension/payment/`.
- Product assets: default-theme path + `filemtime` + fragment `<link>`/`<script>`.
- JET `filemtime` is **unguarded**; UniCredit must guard missing files.

### What this workspace cannot prove (deferred by phase)

| Item                                                         | Blocks Phase 1? | Phase                                                           |
| ------------------------------------------------------------ | --------------- | --------------------------------------------------------------- |
| Exact OC version of the remote test shop                     | no              | runtime matrix                                                  |
| PHP 7.3.33 on that host (ENVIRONMENT.md claim)               | no              | runtime matrix                                                  |
| OpenSSL `aes-256-gcm`, `hash_hkdf`, cURL/TLS on host         | no              | runtime matrix                                                  |
| DB engine/version, SQL mode, charset, prefix                 | no              | Phase 2 install                                                 |
| Installed theme, Journal version, checkout extension         | no              | Phase 8+                                                        |
| OCMOD collision set                                          | no              | OCMOD phase gate                                                |
| Exact deployment CP hostname                                 | no              | **Phase 4**                                                     |
| Final OC3 inbound callback URLs registered with CP           | no              | **Phase 6**                                                     |
| Physical protected root for secrets/keys, owner, permissions | no              | deployment (D3)                                                 |
| HKDF/AES-GCM semantic parity with OC4 implementation         | no              | **Phase 2 local PASS**; remote crypto extensions still required |
| Outbound DNS/TLS to approved test CP host                    | no              | Phase 4                                                         |
| Mail engine, cron, NTP                                       | no              | Phase 10 / ops                                                  |

---

## Requires remote verification

Run on the test server (SSH, panel, or `php -r` as appropriate). Record sanitized output only.

### 1. OpenCart version

```text
# catalog or admin index.php
php -r "require 'index.php';"   # may bootstrap fully — prefer reading the define:
grep -n "define('VERSION'" index.php admin/index.php
```

Expected for the local reference: `3.0.3.9`. Remote may differ; record the exact string.

Also:

```sql
SELECT * FROM <DB_PREFIX>setting WHERE `key` IN ('config_meta_title','config_name','config_theme','theme_default_directory','config_store_id') AND store_id IN (0,1);
SELECT store_id, name, url, ssl FROM <DB_PREFIX>store;
```

### 2. PHP version, handler, modules

```text
php -v
php -m
php -i | grep -E 'PHP Version|Server API|disable_functions|open_basedir|allow_url_fopen|upload_max_filesize|post_max_size|memory_limit|date.timezone|openssl|curl'
```

Confirm claimed **7.3.33** or record the actual version.

### 3. OpenSSL, cURL, JSON, hash, random

```text
php -m | grep -Ei 'openssl|curl|json|hash'
php -r "echo 'openssl: ', extension_loaded('openssl') ? 'yes' : 'no', PHP_EOL;
echo 'aes-256-gcm: ', in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true) ? 'yes' : 'no', PHP_EOL;
echo 'curl: ', extension_loaded('curl') ? 'yes' : 'no', PHP_EOL;
echo 'json: ', extension_loaded('json') ? 'yes' : 'no', PHP_EOL;
echo 'hash_hmac: ', function_exists('hash_hmac') ? 'yes' : 'no', PHP_EOL;
echo 'hash_equals: ', function_exists('hash_equals') ? 'yes' : 'no', PHP_EOL;
echo 'hash_hkdf: ', function_exists('hash_hkdf') ? 'yes' : 'no', PHP_EOL;
echo 'random_bytes: ', function_exists('random_bytes') ? 'yes' : 'no', PHP_EOL;"
php -r "if (function_exists('curl_version')) { print_r(curl_version()); }"
```

Do not print `DB_PASSWORD` or other config secrets.

### 4. CA bundle / outbound TLS / DNS

```text
php -r "echo openssl_get_cert_locations()['default_cert_file'] ?? 'n/a', PHP_EOL;"
# After operator confirms test CP host (do not probe production unless approved):
curl -I --max-time 15 https://<APPROVED_TEST_CP_HOST>/
# SmartUCF test host only if Process 1 test is in scope later:
# curl -I --max-time 15 https://onlinetest.ucfin.bg/
getent hosts <APPROVED_TEST_CP_HOST>
getent hosts onlinetest.ucfin.bg
getent hosts online.ucfin.bg
```

Phase 0 does **not** require a successful CP login. Record whether DNS/TLS handshake works.

### 5. Database

```text
mysql --version
# or: mariadb --version
```

```sql
SELECT VERSION();
SELECT @@sql_mode;
SELECT @@character_set_server, @@collation_server;
SELECT @@character_set_database, @@collation_database;
SHOW VARIABLES LIKE 'innodb_file_per_table';
```

Record `DB_PREFIX` from `config.php` **name only** (e.g. `oc_`), never the password.

```sql
SHOW TABLES LIKE '<DB_PREFIX>order';
SHOW CREATE TABLE <DB_PREFIX>order\G
```

Confirm `order_status_id` default 0 if possible.

### 6. Filesystem, owner, writable protected locations

```text
# PHP / web-server user
ps aux | grep -E 'php-fpm|apache|nginx|lshttpd' | head
# paths from OpenCart config.php — record DIR_STORAGE, DIR_APPLICATION, DIR_SYSTEM names only
ls -ld <DIR_STORAGE> <DIR_STORAGE>/download <DIR_STORAGE>/modification <DIR_STORAGE>/session <DIR_STORAGE>/upload
touch <DIR_STORAGE>/.mtuc_write_probe && rm <DIR_STORAGE>/.mtuc_write_probe
```

Owner/group of `catalog/`, `system/storage/`, and the intended secrets/keys directory.

### 7. Secret / certificate layout (presence only)

Ask whether these exist and their mode/owner (no contents):

```text
# Candidates — operator states which layout is in use
ls -l secrets/smartucf-key.php keys/avalon_cert.pem keys/avalon_private_key.pem
# and/or
ls -l <DIR_STORAGE>/mt_uni_credit/secrets/smartucf-key.php \
      <DIR_STORAGE>/mt_uni_credit/keys/avalon_cert.pem \
      <DIR_STORAGE>/mt_uni_credit/keys/avalon_private_key.pem
```

If PEM files exist:

```text
# fingerprint of the certificate only (public)
openssl x509 -noout -fingerprint -sha256 -in keys/avalon_cert.pem
# public modulus hash to compare cert/key match without exposing the key:
openssl x509 -noout -modulus -in keys/avalon_cert.pem | openssl sha256
openssl rsa -noout -modulus -in keys/avalon_private_key.pem | openssl sha256
stat -c '%a %U %G %n' secrets/smartucf-key.php keys/avalon_cert.pem keys/avalon_private_key.pem
```

If the passphrase file exists: confirm it is a PHP file returning `passphrase`, mode `0600` (or equivalent), **do not print the passphrase**.

### 8. Mail engine

```sql
SELECT store_id, `key`, `value` FROM <DB_PREFIX>setting
WHERE `key` IN ('config_mail_engine','config_mail_parameter','config_mail_smtp_hostname','config_mail_smtp_port','config_mail_smtp_timeout','config_email')
ORDER BY store_id, `key`;
```

Redact SMTP username/password if those keys appear. Engine/hostname/port only.

### 9. Theme, Journal, checkout

```sql
SELECT store_id, `key`, `value` FROM <DB_PREFIX>setting
WHERE `key` IN ('config_theme','theme_default_directory','config_template')
   OR `key` LIKE '%journal%'
   OR `key` LIKE 'module_journal%';
SELECT * FROM <DB_PREFIX>extension WHERE type IN ('payment','module','theme','total');
```

Filesystem:

```text
ls catalog/view/theme
# Journal 3 typical:
ls catalog/view/theme/journal3 2>/dev/null
grep -R "Journal" catalog/view/theme --include='*.twig' -m 1
```

Record Journal version from its admin/module setting or `version` file **if present**. Checkout: native vs named one-page extension (code + version). Do not assume Journal.

### 10. OCMOD / modification state

```sql
SELECT modification_id, name, code, status, date_added FROM <DB_PREFIX>modification;
SELECT * FROM <DB_PREFIX>extension_install ORDER BY date_added DESC LIMIT 20;
```

```text
ls -l <DIR_STORAGE>/modification
# compiled files exist only after refresh
```

Note collisions with JET (`cc_jet` / `mt_jet_credit`) if installed.

### 11. Timezone / NTP / cron

```text
date -u
timedatectl           # if systemd
php -r "echo date('c'), PHP_EOL, date_default_timezone_get(), PHP_EOL;"
crontab -l            # PHP/web user and root, as permitted
```

Clock skew vs HMAC ±300 s is operationally important.

### 12. Sanitized logs

Collect **redacted** tails only:

```text
# paths vary — operator supplies
tail -n 100 <DIR_STORAGE>/logs/error.log
tail -n 50 /var/log/nginx/error.log
tail -n 50 /var/log/php*.log
```

Strip emails, tokens, URLs with secrets, EGN, phone numbers before sharing.

---

## Phase 0 remote checklist (no deployment)

- [ ] Exact OC `VERSION`
- [ ] PHP version + SAPI + `disable_functions`
- [ ] openssl / curl / json / hash / `aes-256-gcm` / `hash_hkdf` / `random_bytes`
- [ ] DB version, sql_mode, charset/collation, prefix
- [ ] `DIR_STORAGE` writable; file owner/group
- [ ] secrets/keys presence+mode **or** explicit “not deployed yet”
- [ ] mail engine (no passwords)
- [ ] theme + Journal version if any
- [ ] checkout extension/version
- [ ] OCMOD list
- [ ] outbound DNS/TLS to approved **test** CP host
- [ ] CA bundle location
- [ ] timezone / NTP
- [ ] cron availability
- [ ] sanitized error log sample

Phase 0 does not install the module. D1–D4 Phase 0 blockers are closed; remaining items above feed their dependent phase STOP GATEs.

---

## Phase 1 remote checklist (admin skeleton)

Build locally with `powershell -File scripts/package.ps1`, then install `dist/CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip` on the test shop. Record sanitized results only; do **not** mark PASS until each item is verified on the server.

### Global

1. [ ] Package installs through **Extensions → Installer** (or documented manual staging of `upload/` + `install.xml`) without fatal errors.
2. [ ] **Modifications** lists OCMOD code `mt_uni_credit` v2.0.2; refresh completes cleanly (no file operations in Phase 1 XML).
3. [ ] No storefront output yet; no CP/SmartUCF network calls; no Phase 2 schema/tables.
4. [ ] OpenCart / PHP error logs remain clean after install, configure, disable, uninstall, reinstall.

### Module (`Extensions → Extensions → Modules`)

1. [ ] **УниКредит покупки на Кредит** (`mt_uni_credit`) appears under Modules with the established title.
2. [ ] Module **Install** succeeds.
3. [ ] **Modify** opens the module admin page without PHP/Twig warnings.
4. [ ] Field order matches established UniCredit family: Status, UNICID, Secret, Advertising, Debug, Product button, Button top spacing, operational buttons.
5. [ ] **Environment (`Среда`) is absent** from Module admin.
6. [ ] **Health/readiness panel is absent** from Module admin.
7. [ ] Status, Advertising and Debug use checkbox/toggle controls; values save as `0`/`1`.
8. [ ] Product button select has exactly **Добави в количката** / **Купи** (`add_to_cart` / `buy`).
9. [ ] Button top spacing accepts `0..200` and persists.
10. [ ] **Обнови данните от банката** visible; POST shows Phase 1 unavailable message (no CP call).
11. [ ] **Изтегли журнал операции** visible; POST downloads empty/sanitized JSON (no Phase 9 persistence).
12. [ ] UNICID required; secret behaviour correct; save/reload works.
13. [ ] Module **Uninstall** removes only `module_mt_uni_credit` settings.
14. [ ] Module **Reinstall** succeeds with idempotent defaults.

### Payment (`Extensions → Extensions → Payments`)

1. [ ] **УниКредит покупки на Кредит** appears under Payments.
2. [ ] UniCredit logo displays at reasonable width (**max ~200 px**, aspect ratio preserved).
3. [ ] Payment **Modify** opens payment-only page (order status, geo zone, status, sort order).
4. [ ] Fresh install: order status defaults to **Processing**.
5. [ ] Manually changed order status persists after save and reopen.
6. [ ] Payment **Uninstall/Reinstall** behaviour unchanged.

Optional evidence to attach (sanitized):

```sql
SELECT * FROM <DB_PREFIX>extension WHERE code = 'mt_uni_credit' AND type IN ('module','payment');
SELECT store_id, `code`, `key`, LEFT(`value`, 20) AS value_prefix FROM <DB_PREFIX>setting
  WHERE `code` IN ('module_mt_uni_credit','payment_mt_uni_credit') ORDER BY store_id, `code`, `key`;
SELECT modification_id, name, code, version, status FROM <DB_PREFIX>modification WHERE code = 'mt_uni_credit';
```

Never paste full `module_mt_uni_credit_secret` values — report only prefix (`enc:v1:`), masked length, and whether decrypt/read succeeds.

---

## Phase 2 remote checklist (persistence + encrypted secrets)

Local checks: `php tests/phase0_check.php`, `php tests/phase1_check.php`, `php tests/phase2_check.php`.

### PHP crypto extensions

```bash
php -r "echo 'openssl=' . (extension_loaded('openssl') ? 'yes' : 'no') . PHP_EOL;"
php -r "echo 'gcm=' . (in_array('aes-256-gcm', openssl_get_cipher_methods(), true) ? 'yes' : 'no') . PHP_EOL;"
php -r "echo 'hash_hkdf=' . (function_exists('hash_hkdf') ? 'yes' : 'no') . PHP_EOL;"
php -r "echo 'hash_hmac=' . (function_exists('hash_hmac') ? 'yes' : 'no') . PHP_EOL;"
php -r "echo 'random_bytes=' . (function_exists('random_bytes') ? 'yes' : 'no') . PHP_EOL;"
```

Expected: all **yes** on PHP 7.3+ test host.

### Database schema (after Module or Payment install)

Run install twice; second run must not error.

```sql
SHOW TABLES LIKE '<DB_PREFIX>mt_uni_credit_%';
SHOW CREATE TABLE `<DB_PREFIX>mt_uni_credit_api_nonce`;
SHOW CREATE TABLE `<DB_PREFIX>mt_uni_credit_operation_lock`;
```

Expected Phase 2 tables only:

- `<DB_PREFIX>mt_uni_credit_api_nonce` — replay/nonces (`UNIQUE(store_id, unicid, nonce_hash)`, expiry index)
- `<DB_PREFIX>mt_uni_credit_operation_lock` — atomic locks (`UNIQUE(store_id, entry_point, operation_key_hash)`, expiry index)

Record engine, charset/collation (prefer InnoDB + utf8mb4; note if fallback required).

**Uninstall policy:** Module/Payment uninstall removes extension **settings** only. Phase 2 tables are **preserved** (future financing evidence). No `DROP TABLE` on ordinary uninstall.

### Admin Secret (Module settings)

1. [ ] First save with UNICID + Secret succeeds.
2. [ ] DB value for `module_mt_uni_credit_secret` starts with `enc:v1:` (report prefix + length only).
3. [ ] Reopen Module admin: Secret password field is **empty**; UI indicates secret is configured.
4. [ ] Save with blank Secret preserves prior encrypted value (prefix unchanged).
5. [ ] Save with new Secret changes encrypted value (prefix stays `enc:v1:`, body differs).
6. [ ] Corrupt encrypted value (test shop only) fails closed in admin/health without exposing plaintext.

Sanitized SQL example:

```sql
SELECT store_id, `key`, LEFT(`value`, 12) AS value_prefix, LENGTH(`value`) AS value_len
FROM <DB_PREFIX>setting
WHERE `key` = 'module_mt_uni_credit_secret';
```

Never paste full ciphertext or plaintext Secret in tickets.

### Concurrency (optional on shared test DB)

- Duplicate nonce insert for same `(store_id, unicid, nonce_hash)` must reject replay atomically.
- Active operation lock must reject second acquire until TTL stale recovery.

These are fully covered offline in `tests/phase2_check.php`; remote verification confirms MySQL/MariaDB behaviour matches.

---

## Phase 3 remote checklist (shop cache + calculator domain)

Local checks: `php tests/phase0_check.php`, `php tests/phase1_check.php`, `php tests/phase2_check.php`, `php tests/phase3_check.php`.

Phase 3 adds **offline** shop snapshot validation/cache and the calculator domain only. No storefront UI, no CP/SmartUCF network, no order creation.

### Database schema (after Module or Payment install)

Run install twice; second run must not error. Phase 2 tables must remain intact.

```sql
SHOW TABLES LIKE '<DB_PREFIX>mt_uni_credit_%';
SHOW CREATE TABLE `<DB_PREFIX>mt_uni_credit_shop_cache`\G
```

Expected Phase 3 table:

- `<DB_PREFIX>mt_uni_credit_shop_cache` — validated CP shop snapshot per `(store_id, unicid)` (`UNIQUE(store_id, unicid)`, expiry index)

**Uninstall policy:** unchanged — extension uninstall removes **settings only**; Phase 2/3 tables are preserved.

### Shop cache smoke (sanitized fixture)

Optional CLI on test shop (insert sanitized fixture only; no CP):

1. [ ] Module install creates `mt_uni_credit_shop_cache` with expected charset/indexes.
2. [ ] Validated snapshot replace for `(store_id, unicid)` persists JSON and timestamps (`fetched_at`, `expires_at`).
3. [ ] Fresh read succeeds while `expires_at` is in the future.
4. [ ] Store `N` does **not** fall back to store `0`.
5. [ ] Invalid snapshot replace does **not** overwrite known-good cache.
6. [ ] Reinstall does not `DROP` existing cache rows.

Sanitized SQL example:

```sql
SELECT store_id, unicid, LEFT(shop_data, 40) AS shop_data_prefix, LENGTH(shop_data) AS shop_data_len,
       fetched_at, expires_at
FROM `<DB_PREFIX>mt_uni_credit_shop_cache`
ORDER BY store_id, unicid;
```

Never paste full `shop_data` if it contains operational bank configuration you treat as sensitive.

### Calculator domain

1. [ ] No Product/Cart/checkout controllers or storefront assets added in Phase 3.
2. [ ] **Обнови данните от банката** was a Phase 3 placeholder; real CP refresh is verified in Phase 4 below.
3. [ ] Module admin: UNICID/Secret labels and help text use standard colour; red styling only on validation errors.

Golden parity is verified locally via `tests/phase3_check.php` and `tests/fixtures/calculator_golden.json`. Optional deployed-runtime helper (if provided later): evaluate golden fixture through PHP CLI and print sanitized financial outputs only — no DB mutation beyond safe test records.

---

## Phase 4 — Outbound Control Panel client (remote verification)

Local gate: `php tests/phase4_check.php` (fake transport; no live network).

### CP host configuration (packaging-time)

1. [ ] `system/library/mt_uni_credit/config/environment.php` exists on the server after install (from module ZIP).
2. [ ] Shop-root `config/environment.php` is **absent** (OC3 installer does not allow that destination).
3. [ ] `control_panel_url` points to the approved test/production CP host for this deployment.
4. [ ] No arbitrary CP URL field exists in Module admin UI.
5. [ ] Outbound API base resolves to `{control_panel_url}/api/v1` only.

Sanitized check (no credentials):

```text
php -r "$p = DIR_SYSTEM . 'library/mt_uni_credit/config/environment.php'; var_export(is_file($p) && array_key_exists('control_panel_url', include $p));"
```

### Connectivity (from OC3 server)

1. [ ] DNS resolves the approved CP host from the shop server.
2. [ ] HTTPS/TLS handshake succeeds with system CA verification (`curl -I --max-time 15 https://<cp-host>/api/v1/...` — use a safe path or OPTIONS if available; do not send credentials).
3. [ ] No redirect to an unexpected host (`curl -I --max-redirs 0`).

### Auth and shop refresh (admin)

Prerequisites: valid UNICID + Secret saved for the target store scope; catalog URL configured (`config_ssl` / `config_url` or `HTTPS_CATALOG` / `HTTP_CATALOG`).

1. [ ] Open Module admin → **Обнови данните от банката** (POST only).
2. [ ] Success flash shows refresh confirmation, `fetched_at`, and scheme count.
3. [ ] No access token, Secret, or raw CP JSON appears in page HTML or admin logs.
4. [ ] Sanitized log lines may include classifications such as `bank_data_refreshed` or `authentication_failed` only.

### Shop cache after refresh

```sql
SELECT store_id, unicid, fetched_at, expires_at, LENGTH(shop_data) AS shop_data_len
FROM `<DB_PREFIX>mt_uni_credit_shop_cache`
WHERE store_id = <current_store_id>
ORDER BY fetched_at DESC
LIMIT 5;
```

1. [ ] Row updated for exact `(store_id, unicid)` matching configured credentials.
2. [ ] `fetched_at` / `expires_at` reflect the refresh time.

Token persistence (encrypted settings — no plaintext):

```sql
SELECT store_id, `key`, LENGTH(`value`) AS value_len, LEFT(`value`, 7) AS value_prefix
FROM `<DB_PREFIX>setting`
WHERE `key` LIKE 'module_mt_uni_credit_cp_%'
  AND store_id = <current_store_id>;
```

1. [ ] Token settings use `enc:v1:` prefix when present; never log or paste full values.

### Failure test (test environment only)

1. [ ] Temporarily save an invalid Secret → refresh fails with safe admin error; prior cache row unchanged.
2. [ ] Restore correct Secret → refresh succeeds again.

### Multistore note (OC3 admin)

OpenCart 3 Module extension settings use `config_store_id` from the active admin store context. The default admin route edits **store 0** unless the operator switches store context via native multistore UI. Each store’s UNICID/Secret/tokens/cache are isolated by `store_id`; there is no fallback from store N to store 0.

### Explicit exclusions (Phase 4)

- [ ] No storefront Product/Cart/Checkout code added.
- [ ] No CP order create/update, inbound callbacks, or SmartUCF traffic.
- [ ] **Изтегли журнал операции** remains Phase 1 sanitized placeholder export.

---

## Phase 5 — Payment method and standard checkout preparation

Baseline: commit `2f9e6ca2c222379e0c3f9696d0995d3fbb2e5a01` + Phase 5 local implementation.

Prerequisites: Phase 4 remote verification passed; fresh shop cache for target store; module **Enabled**; payment **Enabled**; valid UNICID + readable Secret; cart total within bank min/max; at least one eligible scheme; supported currency (BGN per default fixture).

### Checkout visibility (positive)

1. [ ] Add eligible product(s) to cart; proceed through native checkout to **Payment Method**.
2. [ ] **УниКредит покупки на Кредит** appears in the payment list when all prerequisites hold.
3. [ ] Select UniCredit and continue to **Confirm order** — payment panel shows minimal instruction + confirm button (no EGN, no scheme modal, no calculator UI).

### Checkout visibility (negative)

1. [ ] Disable module (`module_mt_uni_credit_status`) → UniCredit disappears from payment methods (refresh payment step).
2. [ ] Re-enable module; disable payment (`payment_mt_uni_credit_status`) → disappears.
3. [ ] Let shop cache expire or delete cache row → disappears (no auto CP refresh from checkout).
4. [ ] Cart total below `uni_minstojnost` or above `uni_maxstojnost` → disappears.
5. [ ] Unsupported session currency (when shop snapshot does not allow it) → disappears.
6. [ ] Geo zone restriction: set `payment_mt_uni_credit_geo_zone_id` to a zone that excludes shipping address → disappears.

### Native order reuse (critical)

Characterized OC3 flow (`reference-oc3-core` `catalog/controller/checkout/confirm.php`):

```text
checkout/confirm → addOrder() → session.order_id → payment extension confirm
```

Before clicking UniCredit confirm:

```sql
SELECT COUNT(*) AS order_count FROM `<DB_PREFIX>order`;
-- note count and latest order_id
```

1. [ ] Complete checkout confirm page with UniCredit selected; note `session.order_id` exists **before** payment confirm AJAX.
2. [ ] Click UniCredit confirm once → browser lands on **`extension/payment/mt_uni_credit/prepared`**, **not** native `checkout/success`.
3. [ ] Prepared page shows non-final message (financing prepared, not submitted); cart and checkout session remain intact (`session.order_id` still set).
4. [ ] Re-run order count — **must not increase** on payment confirm (only one new order from native confirm).
5. [ ] Latest order row matches session order; `order_status_id` remains **0** (Phase 5 does not call `addOrderHistory`).

Double-click / refresh guard:

1. [ ] Double-click confirm quickly → second request returns safe customer error or same prepared continuation (no duplicate order).
2. [ ] Refresh prepared page — no additional orders created; still not on native success page.

### Cart/order parity

1. [ ] After native confirm creates order, change cart quantity in another tab **before** payment confirm → confirm fails with non-technical error; order count unchanged.

### Store scope

1. [ ] Multistore: verify payment availability uses exact `config_store_id` cache/credentials (no store 0 fallback for store N).
2. [ ] Order `store_id` mismatch (if reproducible in test harness) → confirm rejected safely.

### Logs and network

1. [ ] No PHP fatal during payment method load or confirm.
2. [ ] Server/access logs show **no** outbound CP `/api/v1` traffic during checkout payment selection or confirm.
3. [ ] No CP login, `/shop`, or order endpoints from catalog routes.

### Explicit exclusions (Phase 5)

- [ ] No CP order creation or SmartUCF redirect.
- [ ] No inbound callback/API handling.
- [ ] No Product/Cart calculator UI or Product Buy.
- [ ] No EGN / Process 2 collection.
- [ ] No Journal storefront asset injection.

---

## Phase 6 — Inbound authenticated API bridge (local PASS)

Automated gate: `php tests/phase6_check.php` (52 checks, no live network).

### CP route registration (remote)

Register with CP (relative paths, no SEO):

```text
index.php?route=extension/mt_uni_credit/api/shop_cache
index.php?route=extension/mt_uni_credit/api/order_bank_status
index.php?route=extension/mt_uni_credit/api/smartucf_debug_log
```

### Shop cache push (signed)

1. [ ] Valid signed POST with full `data` snapshot → HTTP 200, cache row updated for exact `(store_id, unicid)`.
2. [ ] DB check: `shop_data` JSON length/hash changed; **must not** contain plaintext `uni_password` or `uni_user`.
3. [ ] Encrypted SmartUCF credential exists in `oc_setting` (`module_mt_uni_credit_smartucf_password` prefix only if inspecting).
4. [ ] Invalid snapshot → HTTP 422; prior valid cache unchanged.
5. [ ] Replay exact signed request → **HTTP 401** clean JSON (`Content-Type: application/json; charset=utf-8`); no duplicate mutation.
   - Must **not** emit PHP `mysqli::query` Duplicate entry warning (OC3 `MYSQLI_REPORT_ERROR`).
   - Body must be JSON only: `{"success":false,"message":"Невалидна или изтекла заявка към модула.","error":"invalid_signature"}`.
6. [ ] Alter body after signing → HTTP 401; no mutation.

### Bank status

1. [ ] Signed POST for owned UniCredit order → HTTP 200; `mt_uni_credit_order_bank_status` updated.
2. [ ] Duplicate same `status_id` + label → idempotent HTTP 200.
3. [ ] Cross-store order id → HTTP 404.
4. [ ] Native `oc_order.order_status_id` unchanged.

### Debug log retrieval

1. [ ] Signed POST for order with diagnostic row → HTTP 200 redacted payload.
2. [ ] Missing log or unauthorized order → HTTP 404 (no oracle).
3. [ ] Response/logs contain no EGN, tokens, secrets, or passwords.

### Security hygiene

1. [ ] Application logs after negative tests: no Secret, HMAC, bearer token, or `uni_password`.
2. [ ] GET on any inbound route → HTTP 405 JSON.
3. [ ] Exact replay must not produce HTML-wrapped PHP warnings before the JSON body (OC3 mysqli duplicate-key warning regression).

### Explicit exclusions (Phase 6)

- [ ] No CP order creation, SmartUCF outbound, Process 1/2, or customer financing UI.
- [ ] No native order status transition from bank-status callback.
- [ ] No native order status transition to configured `payment_mt_uni_credit_order_status_id` (financing not completed).

---

## Phase 7 — Local financing attempt + CP order lifecycle (local PASS)

Automated gate: `php tests/phase7_check.php` (no live network). Includes GET side-effect closure: GET `prepared` is read-only; explicit POST `submit` + PRG.

### Happy path (GET read-only + explicit POST)

1. [ ] Native UniCredit checkout creates OC order (`order_status_id = 0`), payment confirm redirects to **GET** `extension/payment/mt_uni_credit/prepared`.
2. [ ] On first GET prepared: verify **no** CP order exists yet (Control Panel / attempt still not `cp_created`).
3. [ ] Refresh prepared several times (or open/prefetch repeatedly) → still **no** CP order; page remains ready-to-submit.
4. [ ] Click explicit submit button (**POST** `extension/payment/mt_uni_credit/submit` with CSRF token) → PRG redirect back to GET prepared.
5. [ ] Exactly one CP `POST /api/v1/orders`; attempt `state = cp_created`; `control_panel_order_id` persisted; `cp_payload` frozen.
6. [ ] Exactly one row in `{prefix}mt_uni_credit_financing_attempt` for `(store_id, order_id)`.
7. [ ] Refresh GET prepared again → success render; **no** second CP order.
8. [ ] On first definitive CP success only, native order history may advance to configured `payment_mt_uni_credit_order_status_id` (Checkout only). Not on GET / local replay.

### Duplicate click / concurrency

1. [ ] Parallel POST submit → operation lock allows one owner; at most one remote POST; both land back on GET prepared.

### Definitive rejection

1. [ ] If test CP can return 422 for a controlled invalid payload → `cp_failed_retryable`, not ambiguous.

### Ambiguous outcome

1. [ ] Prefer local fake-transport coverage (`phase7_check.php`) for timeout/connection after send.
2. [ ] Do **not** force production timeouts unless operator-approved.
3. [ ] Expected local policy: `cp_outcome_unknown` blocks further `POST /orders`.

### DB inspection (sanitized)

Inspect only: attempt `state`, `store_id`, `order_id`, `control_panel_order_id`, fingerprint prefix/hash, timestamps. Do not dump customer payloads/secrets.

### Explicit exclusions (Phase 7)

- [ ] No Product/Cart UI, OCMOD, SmartUCF, Process 1/2, EGN, mail, or final Thank You financing UX.

---

## Phase 8 — Product/Cart storefront + OCMOD/Journal (local PASS)

Automated gate: `php tests/phase8_check.php` (no live network).

### Product page

1. [ ] Eligible product (fresh shop cache, supported currency, amount in range) shows `#mt-uni-credit-product-root` with offer buttons.
2. [ ] Changing quantity/options recalculates via AJAX; financed amount is server-authoritative (`unitWithTax × qty`), not DOM price.
3. [ ] Modal opens; scheme select updates displays from presenter JSON (no JS calculator math).
4. [ ] Secondary **Add to cart** triggers native `#button-cart` only — no order created.
5. [ ] Secondary **Buy** stashes preference + adds to cart + redirects checkout — no order fabricated on Buy.
6. [ ] Primary Apply → customer form → Submit materializes **one** OC order (`addOrder`) then shared Phase 7 CP lifecycle; cart is not cleared.
7. [ ] Fresh shop cache only; stale/missing cache hides UI (fail soft).
8. [ ] Assets load via fragment-local `<link>`/`<script>` with guarded `filemtime` (Journal-compatible).

### Cart page

1. [ ] Eligible cart shows `#mt-uni-credit-cart-root` with `data-hide-secondary=1`.
2. [ ] Financed amount = live `$this->cart->getTotal()` with CartSchemeResolver intersection.
3. [ ] Submit preserves live cart (`cart_unchanged`); fingerprint mismatch fails soft.
4. [ ] Double submit / refresh does not create a second local order for the same operation key.

### OCMOD / themes

1. [ ] Refresh modifications; product + cart anchors match on default theme.
2. [ ] Journal 3: product widget visible with caches on/off; no duplicate handlers (`data-mtuc-bound`).
3. [ ] Theme wildcards use `error="skip"`; missing optional theme files do not abort refresh.

### Explicit exclusions (Phase 8)

- [ ] No SmartUCF / Process 1/2 / EGN / email / Thank You / homepage ads (Phase 9+).
- [ ] Product Buy payment preselect OCMOD skipped (soft session preference only).
