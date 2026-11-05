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

Entries are sorted bytewise, timestamps fixed at 1980-01-01, permissions fixed at regular-file 0644 and compression set explicitly. Repeated builds on the same runtime are tested for identical hashes. Identical ZIP bytes across different PHP/libzip/zlib versions are not promised. Record the final printed SHA-256 and source commit for a release; a disposable working-tree build is not a frozen release.

Run offline packaging regressions:

```bash
php tests/phase_aud031_packaging_check.php
```

The regression suite requires the PHP POSIX extension for its FIFO rejection fixture. It creates and removes only its unique directory under `.tmp-authority/`; it does not publish into `dist/` or replace release artifacts. Legacy source-contract tests inspect the PHP entrypoint and shared policy. Runtime shop/database checks and dependency-installing tests are separate and must not be run as packaging verification.
