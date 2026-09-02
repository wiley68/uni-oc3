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
2. [ ] **Обнови данните от банката** remains a non-network placeholder (real CP refresh is Phase 4).
3. [ ] Module admin: UNICID/Secret labels and help text use standard colour; red styling only on validation errors.

Golden parity is verified locally via `tests/phase3_check.php` and `tests/fixtures/calculator_golden.json`. Optional deployed-runtime helper (if provided later): evaluate golden fixture through PHP CLI and print sanitized financial outputs only — no DB mutation beyond safe test records.
