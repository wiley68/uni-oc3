# OpenCart core fixtures

These unmodified files come from the official `opencart/opencart` GitHub tags `3.0.3.9` and `3.0.5.1`. `manifest.json` records the exact source URL and SHA-256 for each file; the compatibility test checks every checksum before probing either version. Upstream copyright/license headers are retained (OpenCart GPL-3.0).

This is a focused source fixture set, not an installed OpenCart store. It exercises actual engine and library classes and native extension installer controllers, with offline DB/permission/HTTP dependencies. Seven OCMOD searches are checked on each version's default-theme sources. Tests and fixtures are not part of the installer ZIP.

The scoped `.gitattributes` rule preserves these upstream bytes across Windows/Linux checkouts so checksum verification does not depend on Git line-ending conversion.
