# Packaging under WSL/Linux

Use PHP CLI 8.0+ with `zip` (ZipArchive/libzip) and `SimpleXML` plus `mbstring` for UTF-8 case collision checks. No Composer packages, shell ZIP tool or PowerShell runtime are required. The module's PHP compatibility floor remains unchanged; the packaging runtime is separate.

```bash
php scripts/package.php
```

The version comes from `install.xml`; the default output is `dist/oc3_uni_<version>.ocmod.zip`. The installer payload contains only `install.xml` and files below `upload/`, including required hidden `.htaccess` files. Never manually zip the repository.

Review the source revision and clean working tree before creating a release. The builder performs full source preflight, rejects forbidden files/content, symlinks, non-regular filesystem entries, path traversal and case collisions, and validates every archive entry, the complete manifest and per-file SHA-256 before publication. Source bytes and filename case are preserved. Public `.crt`/`.cer` files are not blanket-excluded; content sentinels are targeted checks, not universal credential discovery.

Output directories must be trusted and not concurrently changed by other processes. Custom output directories must already exist and use absolute canonical Linux paths without symlink components:

```bash
php scripts/package.php --output-dir=/absolute/path/inside/uni-oc3/disposable-directory
```

Use repository-owned disposable directories for tests. Source paths must not be mutated concurrently. The builder uses an exclusively created temporary file in the output directory, validates it, then publishes with an atomic no-clobber hard link on that filesystem. Existing ZIPs, including frozen releases, are always refused; there is no overwrite switch. Cleanup removes only the current invocation's temporary file, never a directory tree. Failed preflight creates no output. No staging tree is used.

Entries are sorted bytewise, permissions are fixed at regular-file 0644, and compression is set explicitly. The builder captures the current time once per package and writes that time to every ZIP entry. It temporarily sets the process `TZ` to UTC for libzip and restores the caller's setting afterward.

ZIP DOS timestamps have two-second precision and carry no timezone. Builds in the same two-second interval can have identical entry timestamps. For successive packages with changed source targeting the same shop, wait until a different two-second UTC interval before building. Use a trusted build clock and verify that the PHP entries in the new archive have a different timestamp from those in the package being replaced. For example, inspect the entry times with `TZ=UTC unzip -l path/to/package.ocmod.zip`. Separate builds may have different ZIP hashes while their entry bytes match.

After update, verify the installed PHP file hashes and compare their filesystem modification times before and after installation. Extraction can interpret DOS timestamps using the destination timezone. The deployment check is that successive changed files have distinguishable installed modification times. Full source-to-archive byte parity remains required. Verify and record an individual SHA-256 for each delivered archive and retain the exact tested ZIP with its checksum after acceptance.

With `opcache.validate_timestamps=1`, allow for the configured `opcache.revalidate_freq` interval before testing. With `opcache.validate_timestamps=0`, use the hosting provider’s supported cache invalidation or PHP worker reload procedure. Apply it to the actual web PHP SAPI and its serving workers. PHP CLI has a separate execution context. Current entry timestamps reduce the observed stale opcode risk, and hosting cache controls remain applicable.

Run offline packaging regressions:

```bash
php tests/phase_aud031_packaging_check.php
```

The regression suite requires the PHP POSIX extension for its FIFO rejection fixture. It creates and removes only its unique directory under `.tmp-authority/`; it does not publish into `dist/` or replace release artifacts. Legacy source-contract tests inspect the PHP entrypoint and shared policy. Runtime shop/database checks and dependency-installing tests are separate and must not be run as packaging verification.
