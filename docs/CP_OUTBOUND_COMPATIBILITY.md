# Control Panel outbound remediation

The previous policy rejected a correctly configured staging CP before HTTP because it independently enumerated trusted hosts. The destination now comes only from `upload/system/library/mt_uni_credit/config/environment.php`, key `control_panel_url`. The file is unchanged by remediation and packaging. Client construction accepts a deployment environment object for isolated file fixtures, rather than a raw URL override. Factory, admin and catalog use the same reader and policy. No CP hostname constants, allowlist or production fallback remain.

The policy validates an absolute HTTPS origin, a public DNS hostname with valid labels, port 443/default, no userinfo/query/fragment and only an empty/root origin path. `/api/v1` is the sole API base path. IP literals, numeric IP spellings and special-use/local namespaces are rejected. cURL resolves and validates all returned IPv4/IPv6 addresses, rejects private/reserved or mixed answers, and pins the public answers with `CURLOPT_RESOLVE` (the first answer on libcurl older than 7.59); the original host remains the TLS identity. Redirects and implicit proxies are disabled. This requires working DNS resolution and direct public HTTPS access. No live CP requests run in regression tests.

Admin refresh obtains `$this->log` through `Model::__get`, then checks its `write` method. Both real core versions expose the logger while `isset($this->log)` is false. Missing logger is harmless. Failure logs and order-journal rows add payload-free operation, phase, actual transport flag/status, exception class, fixed reason, validated hostname, static endpoint and existing attempt ID. Failure messages, payloads, tokens and secrets are excluded. Authentication sub-operation failures retain the login/refresh endpoint and actual HTTP evidence. Existing terminal/ambiguous states, token behavior and duplicate-create protection are preserved; legacy journal event status fields retain their existing meaning, while new `http_status` records the actual response or null.

| Core behavior | 3.0.3.9 | 3.0.5.1 | Module regression |
|---|---|---|---|
| Core PHP floor, `system/startup.php` | 7.3 | 8.1 | Module source stays PHP 7.3 compatible; actual version runtimes still require live testing |
| Registry-backed Model logger | `__get`; no `__isset` | Same | Real admin refresh logs; missing logger produces no warning |
| Request `clean()` | Keeps outer whitespace | Trims; null becomes empty | Module UNICID trim remains consistent |
| Loader/Proxy classic extension paths | Callable model methods | Callable model methods; Ref unwrapping removed | Real admin/catalog models execute through core Loader/Proxy |
| Effective admin view-before events | language priority 0 | language 999, theme 1000 | Real language event preserves module-owned view data |
| Catalog router event data | implicit null | explicit array | Actual wildcard module guard accepts both by reference |
| Native extension uninstall | Leaves permissions | Removes route permissions | Real module/payment install, uninstall, reinstall and runtime permission delegation tested with stale user permissions |
| Session data API/file adapter | Persists data | Same | Module store/order/session context survives reopen |
| Default-theme OCMOD searches | 7 unique matches | 7 unique matches | Official pinned sources; same installer XML |

Fixtures are under `tests/fixtures/oc3_core`; every upstream URL and checksum is in `manifest.json`. `tests/fixtures/compatibility_matrix.json` adds 3.0.5.1 without removing the earlier 3.0.3.x targets. `core_fixture_tested` describes the focused probes; `locally_tested`/`remotely_tested` remain false because no full installed store has been exercised. These probes do not certify a theme, browser session cookies, MySQL deployment or modified core.

Run `php scripts/run_canonical_safe_tests.php`. It includes the existing canonical client/cache/lifecycle suites, structural destination tests, actual cURL code with disabled native sockets and fake DNS, safe outbound lifecycle diagnostics, and both core fixture probes. Additional focused historical checks cover broken-CP materialization, Registry/Proxy behavior and canonical shop identity. JavaScript is unchanged.

Live acceptance matrix for **both** core versions:

| Area | Required checks |
|---|---|
| Environment/package | Prepare the same source/package by changing only `control_panel_url`; confirm the deployed value and no fallback; include a future public hostname |
| Admin refresh | Cold login + `/shop`, valid-token refresh, invalid environment with zero HTTP, local logger entry and fixed safe reason; store 0 and a second store |
| Order submission | Product, Cart and standard Checkout; one CP create, persisted CP ID, correct `(store_id, UNICID, Shop.name)`; revisit creates no duplicate |
| Failure/recovery | Local config rejection, 401, canonical 422, timeout, DNS/TLS failure, 5xx and malformed response; existing terminal/ambiguous recovery semantics |
| Transport | Real public DNS/CNAME/AAAA resolution, TLS/SNI/CA bundle, direct HTTPS, bounded body, no redirect; rejected internal/mixed DNS answers never reach HTTP |
| Installation | Fresh install, uninstall/reinstall, OCMOD refresh, native module/payment permissions with current and stale sessions |
| Session/events/UI | Navigation and checkout continuity across cookies/redirects, timezone/DB session expiry, language/template events, default theme and separately Journal |
| PHP/DB | Actual PHP 7.3 for 3.0.3.9 and PHP 8.1+ for 3.0.5.1; MySQL/MariaDB advisory-lock ownership, token/cache persistence and EUR amounts |

Runtime hostname scan: the only CP hostname literal under `upload/` is the selected `control_panel_url` in `config/environment.php`. Regression tests deliberately contain example CP hosts and the offline network guard names hosts it must never contact; those are test data, not deployment authorities. SmartUCF's independent bank endpoint policy is unchanged.

## Validation and artifact (2026-10-09)

- Full canonical safe runner: **14 passed, 0 failed**. Destination policy: **128/0**; outbound diagnostics/lifecycle: **71/0**, including native cURL code exercised by a separate socket-free probe (**30/0**).
- Core 3.0.3.9 and 3.0.5.1: **25 admin + 13 catalog checks per version**, **7/7 unique default-theme OCMOD anchors each**, and all **60 upstream checksums verified**.
- PHP lint: **422 files, 0 failures**, on PHP **8.4.25**; actual PHP 7.3/8.1 runtimes were unavailable. JavaScript was unchanged, so no JS suite was needed for this change.
- Additional focused checks: **10/14 suites passed** (broken-CP materialization, shop identity, bounded response, create identity, Registry/Proxy, installation schema, auth recovery, EUR, Phase 7, Phase 9). Three historical AUD-009 suites retain exactly the same **57/25/18** failed assertions as an isolated clean HEAD. Phase 0 has **17** failures versus **18** on clean HEAD: existing absent references and static-scan false positives; its former destination-test scan warning disappears. No new failed assertion was introduced. These historical suites were not changed to erase failures.
- Packaging exclusion harness: **48/0**; packaging PHP suite: **19/0**; package build policy: **PASS**. `git diff --check`: **PASS**.
- Artifact: `dist/CC_OpenCartv.3.x_UNI_v.2.0.3.ocmod.zip`; **516777 bytes**, **259 entries**; SHA-256: `1C7C31D8943EFA46A65F8E45E4EBA5C33B00263224559A5876F65DA5CED14183`. All 11 changed/new runtime files and the unchanged environment file were checked against ZIP bytes. Tests/docs/core fixtures are excluded from the installer. No version/name change.
- `config/environment.php` matches its HEAD blob and its packaged bytes. Its existing selected origin is unchanged. Changes are uncommitted; no reference repository was modified.

## Complete changed-file inventory

Modified (20) — none deleted:

- [docs/CONTRACTS.md](../docs/CONTRACTS.md)
- [docs/ENVIRONMENT.md](../docs/ENVIRONMENT.md)
- [tests/fixtures/compatibility_matrix.json](../tests/fixtures/compatibility_matrix.json)
- [tests/fixtures/cp_environments.json](../tests/fixtures/cp_environments.json)
- [tests/fixtures/php_floor.json](../tests/fixtures/php_floor.json)
- [tests/phase_aud008_f02_cp_destination_policy_check.php](../tests/phase_aud008_f02_cp_destination_policy_check.php)
- [tests/phase_aud008_f04_canonical_shop_url_check.php](../tests/phase_aud008_f04_canonical_shop_url_check.php)
- [tests/phase_broken_cp_definitive_failure_check.php](../tests/phase_broken_cp_definitive_failure_check.php)
- [tests/phase_canonical_aggregate_check.php](../tests/phase_canonical_aggregate_check.php)
- [tests/support/phase4_harness.php](../tests/support/phase4_harness.php)
- [upload/admin/model/extension/module/mt_uni_credit.php](../upload/admin/model/extension/module/mt_uni_credit.php)
- [upload/system/library/mt_uni_credit/bootstrap.php](../upload/system/library/mt_uni_credit/bootstrap.php)
- [upload/system/library/mt_uni_credit/control_panel_client.php](../upload/system/library/mt_uni_credit/control_panel_client.php)
- [upload/system/library/mt_uni_credit/control_panel_order_lifecycle_service.php](../upload/system/library/mt_uni_credit/control_panel_order_lifecycle_service.php)
- [upload/system/library/mt_uni_credit/cp_destination_policy.php](../upload/system/library/mt_uni_credit/cp_destination_policy.php)
- [upload/system/library/mt_uni_credit/cp_exception.php](../upload/system/library/mt_uni_credit/cp_exception.php)
- [upload/system/library/mt_uni_credit/cp_service_factory.php](../upload/system/library/mt_uni_credit/cp_service_factory.php)
- [upload/system/library/mt_uni_credit/curl_cp_http_transport.php](../upload/system/library/mt_uni_credit/curl_cp_http_transport.php)
- [upload/system/library/mt_uni_credit/deployment_environment.php](../upload/system/library/mt_uni_credit/deployment_environment.php)
- [upload/system/library/mt_uni_credit/diagnostic_debug_log_repository.php](../upload/system/library/mt_uni_credit/diagnostic_debug_log_repository.php)

Created (72) — core files are enumerated individually by version/path/source/hash in the linked manifest:

- [.gitattributes](../.gitattributes)
- [docs/CP_OUTBOUND_COMPATIBILITY.md](../docs/CP_OUTBOUND_COMPATIBILITY.md)
- [tests/fixtures/cp_broken_environment.php](../tests/fixtures/cp_broken_environment.php)
- [tests/fixtures/oc3_core/README.md](../tests/fixtures/oc3_core/README.md)
- [tests/fixtures/oc3_core/manifest.json](../tests/fixtures/oc3_core/manifest.json)
- [tests/phase_cp_outbound_remediation_check.php](../tests/phase_cp_outbound_remediation_check.php)
- [tests/phase_oc3_outbound_compatibility_check.php](../tests/phase_oc3_outbound_compatibility_check.php)
- [tests/support/core_compatibility_event_db.php](../tests/support/core_compatibility_event_db.php)
- [tests/support/cp_curl_transport_probe.php](../tests/support/cp_curl_transport_probe.php)
- [tests/support/cp_outbound_harness.php](../tests/support/cp_outbound_harness.php)
- [tests/support/oc3_outbound_core_probe.php](../tests/support/oc3_outbound_core_probe.php)
- [upload/system/library/mt_uni_credit/cp_outbound_diagnostics.php](../upload/system/library/mt_uni_credit/cp_outbound_diagnostics.php)
- 60 official core source fixtures under `tests/fixtures/oc3_core/3.0.3.9/` and `tests/fixtures/oc3_core/3.0.5.1/`; exact inventory: [manifest.json](../tests/fixtures/oc3_core/manifest.json).

## Every CP hostname occurrence

A full `upload/` scan has exactly one match: `upload/system/library/mt_uni_credit/config/environment.php:14`, the configured CP origin. No other runtime PHP, template, asset or configuration knows a CP hostname. The structural policy contains no host constants/list. SmartUCF bank endpoint names are a separate unchanged authority.

The repository-wide scan outside runtime reports only the following test/reference occurrences (not deployment destination selection):

| File/line | Role |
|---|---|
| `docs/RUNTIME_VERIFICATION.md:1188` | Local CP reference-copy description |
| `tests/phase4_check.php:88` | Existing packaged-origin regression |
| `tests/phase_aud008_f02_cp_destination_policy_check.php:17` | Three example deployments in environment-only acceptance tests |
| `tests/phase_aud028_diagnostic_redaction_check.php:451,452` | Read-only local CP reference fixture path/presence check |
| `tests/phase_cp_outbound_remediation_check.php:22,53,65` | Staging fake-transport input and expected safe diagnostic hostname |
| `tests/support/oc3_outbound_core_probe.php:105` | Staging admin refresh fixture on both cores |
| `tests/support/no_network.php:198` | Offline guard identifies a host it must never contact |
| `tests/support/cp_smartucf_debug_sanitize.php:7` | Source-provenance comment for local CP reference |
