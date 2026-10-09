Target platform: OpenCart 3.x
Primary core reference: OpenCart 3.0.3.9
Additional core compatibility target: OpenCart 3.0.5.1 (core requires PHP 8.1+)
Module PHP compatibility floor: PHP 7.3.0+ (see `docs/CONTRACTS.md` Compatibility matrix)
Test runtime reference: PHP 7.3.33
Database target: generic MySQL/MariaDB supported by OC3
Theme baseline: default OpenCart theme
Journal: must be supported via proven compatibility pattern (presentation only; does not change bank-status business semantics — CONTRACTS §F STATUS-PUBLIC-017)
Checkout baseline: standard OpenCart checkout
Integration mechanism: OCMOD install.xml + OC3 events (placement ≠ bank-status authority — CONTRACTS §F STATUS-PUBLIC-016)
Authoritative public bank status / leasing rules: `docs/CONTRACTS.md` section F (`STATUS-PUBLIC-*`)

Control Panel destination authority: `upload/system/library/mt_uni_credit/config/environment.php`, key `control_panel_url`. Change only that value before packaging to select a public HTTPS CP environment. The package command copies this file without generating or rewriting it. Runtime code has no CP hostname allowlist or URL fallback.

CP HTTP transport resolves DNS before HTTP, rejects private/reserved or mixed public/private answers, and pins the validated public addresses for that request (the first address on libcurl older than 7.59). TLS validation remains bound to the configured hostname. Redirects and implicit proxies are disabled. Deployments need working PHP `dns_get_record`, public DNS answers and direct outbound HTTPS; proxy-only/private-network deployments are outside this policy.

Official core fixture probes and the remaining live regression matrix are documented in `CP_OUTBOUND_COMPATIBILITY.md`.
