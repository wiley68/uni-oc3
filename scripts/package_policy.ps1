# AUD-031-F02 — shared packaging exclusion / path policy (Windows PowerShell 5.1+).
# Dot-sourced by scripts/package.ps1 and packaging harness tests.

function Normalize-PackageRelativePath {
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$Path
    )

    $n = [string]$Path
    $n = $n.Replace('\', '/')
    while ($n.StartsWith('./')) {
        $n = $n.Substring(2)
    }
    while ($n.StartsWith('/')) {
        $n = $n.Substring(1)
    }
    # Collapse duplicate slashes without rewriting meaningful content.
    while ($n.Contains('//')) {
        $n = $n.Replace('//', '/')
    }
    return $n.TrimEnd('/')
}

function Test-PackagePathTraversal {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    $raw = [string]$Path
    $n = $raw.Replace('\', '/')

    if ($n -match '(^|/)\.\.(/|$)') {
        return $true
    }
    if ($raw -match '\.\.\\') {
        return $true
    }
    if ($n.StartsWith('/')) {
        return $true
    }
    if ($raw -match '^[A-Za-z]:') {
        return $true
    }
    if ($raw.StartsWith('\\') -or $n.StartsWith('//')) {
        return $true
    }

    return $false
}

function Get-PackagePathSegments {
    param(
        [Parameter(Mandatory = $true)]
        [string]$NormalizedPath
    )

    if ([string]::IsNullOrEmpty($NormalizedPath)) {
        return @()
    }

    return @($NormalizedPath.Split('/') | Where-Object { $_ -ne '' })
}

function Get-PackagePathForbiddenReason {
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$RelativePath
    )

    if (Test-PackagePathTraversal -Path $RelativePath) {
        return 'path-traversal'
    }

    $n = Normalize-PackageRelativePath -Path $RelativePath
    if ([string]::IsNullOrEmpty($n)) {
        return 'empty-path'
    }

    $lower = $n.ToLowerInvariant()
    $fileName = Split-Path -Leaf $n
    $fileLower = $fileName.ToLowerInvariant()
    $segments = @(Get-PackagePathSegments -NormalizedPath $lower)

    # Exact legacy / credential locations (keep explicit; also covered by extensions).
    $exactForbidden = @(
        'upload/config/environment.php',
        'upload/system/library/mt_uni_credit/keys/avalon_cert.pem',
        'upload/system/library/mt_uni_credit/keys/avalon_private_key.pem',
        'upload/system/library/mt_uni_credit/recording_process_two_mailer.php'
    )
    foreach ($exact in $exactForbidden) {
        if ($lower -eq $exact) {
            return 'exact-forbidden'
        }
    }

    if ($lower.StartsWith('upload/catalog/view/image/')) {
        return 'catalog-view-image'
    }

    # Repo/dev roots that must never appear inside a release ZIP.
    $blockedRoots = @('tests', 'test', 'scripts', 'reference', 'references', 'docs', '.git', '.github', '.vscode', '.idea')
    if ($segments.Count -gt 0 -and ($blockedRoots -contains $segments[0])) {
        return 'blocked-root'
    }

    # Path segments under upload/ (narrow whole-segment match only).
    $blockedSegments = @('.git', '.github', '.vscode', '.idea', 'tests', 'test', 'fixtures', 'coverage')
    foreach ($seg in $segments) {
        if ($blockedSegments -contains $seg) {
            return 'blocked-segment:' + $seg
        }
    }

    if ($fileLower -eq '.env' -or $fileLower.StartsWith('.env.')) {
        return 'env-file'
    }
    if ($fileLower -eq 'config.local.php') {
        return 'local-config'
    }
    if ($fileLower.EndsWith('.local.php') -or $fileLower.EndsWith('.local')) {
        return 'local-suffix'
    }

    if ($fileLower.EndsWith('.code-workspace') -or
        $fileLower.EndsWith('.sublime-project') -or
        $fileLower.EndsWith('.sublime-workspace') -or
        $fileLower.EndsWith('.swp') -or
        $fileLower.EndsWith('.swo') -or
        $fileLower.EndsWith('~')) {
        return 'ide-editor'
    }

    if ($fileLower -eq '.gitignore' -or $fileLower -eq '.gitattributes') {
        return 'git-metadata'
    }

    if ($fileLower -eq 'phpunit.xml' -or $fileLower -eq 'phpunit.xml.dist') {
        return 'phpunit'
    }

    if ($fileLower.EndsWith('.bak') -or
        $fileLower.EndsWith('.orig') -or
        $fileLower.EndsWith('.rej') -or
        $fileLower.EndsWith('.tmp') -or
        $fileLower.EndsWith('.temp') -or
        $fileLower.EndsWith('.log')) {
        return 'temp-backup'
    }

    if ($fileLower -eq 'thumbs.db' -or $fileLower -eq 'desktop.ini' -or $fileLower -eq '.ds_store') {
        return 'os-junk'
    }

    # Credential-bearing extensions (public .crt/.cer not blanket-excluded).
    if ($fileLower.EndsWith('.pem') -or
        $fileLower.EndsWith('.key') -or
        $fileLower.EndsWith('.p12') -or
        $fileLower.EndsWith('.pfx') -or
        $fileLower.EndsWith('.jks')) {
        return 'credential-extension'
    }

    return $null
}

function Test-PackageTextualExtension {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RelativePath
    )

    $lower = (Normalize-PackageRelativePath -Path $RelativePath).ToLowerInvariant()
    $exts = @(
        '.php', '.js', '.css', '.twig', '.xml', '.json', '.txt', '.md',
        '.htaccess', '.svg', '.html', '.htm', '.ini', '.yml', '.yaml'
    )
    foreach ($ext in $exts) {
        if ($lower.EndsWith($ext)) {
            return $true
        }
    }
    # Dotfiles like .htaccess already covered; bare names without extension: skip binary scan.
    $leaf = Split-Path -Leaf $lower
    if ($leaf -eq '.htaccess') {
        return $true
    }

    return $false
}

function Get-PackageTextScanHits {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Content
    )

    $hits = @()
    $debugNeedles = @(
        'force_test_cp_create_422',
        'X-UniPayment-Test-Failure',
        'UNIPAYMENT_ENABLE_TEST_FAILURES',
        'ShopApiTestFailure'
    )
    foreach ($needle in $debugNeedles) {
        if ($Content.Contains($needle)) {
            $hits += ('debug-hook:' + $needle)
        }
    }

    $keyNeedles = @(
        '-----BEGIN PRIVATE KEY-----',
        '-----BEGIN RSA PRIVATE KEY-----',
        '-----BEGIN EC PRIVATE KEY-----',
        '-----BEGIN OPENSSH PRIVATE KEY-----'
    )
    foreach ($needle in $keyNeedles) {
        if ($Content.Contains($needle)) {
            $hits += ('private-key-block')
            break
        }
    }

    return $hits
}

function Get-FileSha256Hex {
    param(
        [Parameter(Mandatory = $true)]
        [string]$LiteralPath
    )

    $hash = Get-FileHash -LiteralPath $LiteralPath -Algorithm SHA256
    return [string]$hash.Hash.ToUpperInvariant()
}

function Get-ApprovedPackageSourceFiles {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Root
    )

    $approved = @()

    $installXml = Join-Path $Root 'install.xml'
    if (-not (Test-Path -LiteralPath $installXml -PathType Leaf)) {
        throw 'Missing required package path: install.xml'
    }
    $reason = Get-PackagePathForbiddenReason -RelativePath 'install.xml'
    if ($null -ne $reason) {
        throw ("Forbidden package source install.xml ($reason)")
    }
    $approved += ,[pscustomobject]@{
        RelativePath = 'install.xml'
        FullPath     = $installXml
    }

    $uploadRoot = Join-Path $Root 'upload'
    if (-not (Test-Path -LiteralPath $uploadRoot -PathType Container)) {
        throw 'Missing required package path: upload'
    }

    $files = @(Get-ChildItem -LiteralPath $uploadRoot -Recurse -Force -File | Sort-Object FullName)
    foreach ($file in $files) {
        $rel = 'upload/' + (Normalize-PackageRelativePath -Path $file.FullName.Substring($uploadRoot.Length))
        $reason = Get-PackagePathForbiddenReason -RelativePath $rel
        if ($null -ne $reason) {
            throw ("Forbidden package source: $rel ($reason)")
        }
        $approved += ,[pscustomobject]@{
            RelativePath = $rel
            FullPath     = $file.FullName
        }
    }

    return $approved
}
