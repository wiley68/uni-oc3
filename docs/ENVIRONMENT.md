Target platform: OpenCart 3.x
Primary core reference: OpenCart 3.0.3.9
Module PHP compatibility floor: PHP 7.3.0+ (see `docs/CONTRACTS.md` Compatibility matrix)
Test runtime reference: PHP 7.3.33
Database target: generic MySQL/MariaDB supported by OC3
Theme baseline: default OpenCart theme
Journal: must be supported via proven compatibility pattern (presentation only; does not change bank-status business semantics — CONTRACTS §F STATUS-PUBLIC-017)
Checkout baseline: standard OpenCart checkout
Integration mechanism: OCMOD install.xml + OC3 events (placement ≠ bank-status authority — CONTRACTS §F STATUS-PUBLIC-016)
Authoritative public bank status / leasing rules: `docs/CONTRACTS.md` section F (`STATUS-PUBLIC-*`)
