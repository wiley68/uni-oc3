# UniCredit for OpenCart 3.x v2.0.4

**OC3-01–OC3-08: 8/8 PASS (owner verified)**

## Advertising

The module administration form no longer has a homepage advertising toggle. The Control Panel flag `uni_container_status` controls the banner and its fonts, CSS and JavaScript. The module still requires an eligible homepage route, an active module, a UNICID and a valid snapshot for the current store. The CP `uni_status` flag and the existing advertising payload checks continue to apply. Desktop and mobile use the existing rendering and links.

The former `module_mt_uni_credit_advertising_enabled` value is ignored. Existing stored values are preserved on settings save, and fresh installs omit the legacy default.

The CP snapshot is scoped by store and UNICID, with a 24-hour TTL. Missing or expired snapshots use the existing coordinated refresh. Presentation may use the last known good snapshot for up to six hours after expiry when this request's own refresh fails transiently. A valid signed CP push or successful manual **Refresh bank data** replaces the snapshot and renews the TTL.

## Automated validation

- **PASS** — AUD-029 (133 checks), canonical offline suite (14 suites, including 187 cache lifecycle checks), Phase 11 (138), and AUD-030 installer/uninstaller contracts (72).
- **PASS** — Phases 4, 7 and 8, including current CP payload version and archive checks. AUD-031 release content guard (28) and builder regressions (74) also passed.
- **PASS** — PHP 8.3 syntax, XML and version consistency, Git whitespace checks, ZIP integrity and per-entry source parity. The archive contains 260 files under `upload/` and at `install.xml`.
- **FAIL, reproduced at baseline** — Phase 0 has 17 historical reference and source-scan failures. Phase 1 has six historical failures. AUD-003 and AUD-022 require the removed external OpenCart core reference.
- **SKIP** — PHP 7.3 CLI syntax validation, which was unavailable locally.

Build command: `php scripts/package.php`

Acceptance installer: `dist/oc3_uni_2.0.4.ocmod.zip` (521,924 bytes)

SHA-256: `e370ccd0b7420cf6ed31a574719a83e5f52e7d024122aef867fc80628359104b`
