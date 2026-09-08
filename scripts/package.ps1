# Build deterministic UniCredit OpenCart 3 distributable package.
# Output (default): dist/CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip
#
# AUD-031-F02: exclusion policy + full manifest / byte-integrity validation.
# Optional -OutputPath for disposable test ZIPs (must not be required to overwrite frozen artifact).

param(
    [string]$OutputPath = '',
    [string]$StagingDirName = 'package-staging',
    [string]$PackageRoot = '',
    [switch]$SkipLegacyEntryChecklist
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($PackageRoot)) {
    $Root = Split-Path -Parent $PSScriptRoot
}
else {
    $Root = $PackageRoot
}
. (Join-Path $PSScriptRoot 'package_policy.ps1')

$DistDir = Join-Path $Root 'dist'
if (-not [string]::IsNullOrWhiteSpace($PackageRoot)) {
    # Disposable fixtures keep staging/output under the fixture tree.
    $DistDir = Join-Path $Root 'dist'
}
$StagingDir = Join-Path $DistDir $StagingDirName
$Version = '2.0.2'
$PackageName = "CC_OpenCartv.3.x_UNI_v.$Version.ocmod.zip"
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $DistDir $PackageName
}

$RequiredPaths = @(
    (Join-Path $Root 'install.xml'),
    (Join-Path $Root 'upload')
)

foreach ($path in $RequiredPaths) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Missing required package path: $path"
    }
}

# ---- Source preflight + approved file set (includes hidden files) ----
$approvedSources = Get-ApprovedPackageSourceFiles -Root $Root
$approvedMap = @{}
foreach ($item in $approvedSources) {
    $key = Normalize-PackageRelativePath -Path $item.RelativePath
    if ($approvedMap.ContainsKey($key)) {
        throw "Duplicate approved source path: $key"
    }
    $approvedMap[$key] = $item
}

# Retain exact required/forbidden names for phase4 + human clarity.
$expectedEntries = @(
    'install.xml',
    'upload/admin/controller/extension/module/mt_uni_credit.php',
    'upload/admin/controller/extension/payment/mt_uni_credit.php',
    'upload/admin/view/image/payment/uni_logo.svg',
    'upload/admin/view/stylesheet/mt_uni_credit_module.css',
    'upload/admin/view/javascript/mt_uni_credit_module.js',
    'upload/catalog/controller/extension/payment/mt_uni_credit.php',
    'upload/catalog/controller/extension/mt_uni_credit/product.php',
    'upload/catalog/controller/extension/mt_uni_credit/cart.php',
    'upload/catalog/model/extension/payment/mt_uni_credit.php',
    'upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig',
    'upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.js',
    'upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.css',
    'upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront_fonts.css',
    'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo.svg',
    'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_logo_red.svg',
    'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/uni_mini_logo.png',
    'upload/catalog/view/theme/default/template/extension/mt_uni_credit/image/popup-calc-bg.png',
    'upload/system/library/mt_uni_credit/constants.php',
    'upload/system/library/mt_uni_credit/config/environment.php',
    'upload/system/library/mt_uni_credit/keys/.htaccess',
    'upload/system/library/mt_uni_credit/keys/index.php',
    'upload/system/library/mt_uni_credit/secrets/.htaccess',
    'upload/system/library/mt_uni_credit/secrets/index.php',
    'upload/system/library/mt_uni_credit/secrets/smartucf-key.php'
)
$forbiddenEntries = @(
    'upload/config/environment.php',
    'upload/catalog/view/image/mt_uni_credit/uni_logo.svg',
    'upload/catalog/view/image/mt_uni_credit/uni_logo_red.svg',
    'upload/system/library/mt_uni_credit/keys/avalon_cert.pem',
    'upload/system/library/mt_uni_credit/keys/avalon_private_key.pem',
    'upload/system/library/mt_uni_credit/recording_process_two_mailer.php'
)

foreach ($entry in $expectedEntries) {
    if (-not $approvedMap.ContainsKey($entry)) {
        if (-not $SkipLegacyEntryChecklist) {
            throw "Required package source missing from approved set: $entry"
        }
    }
}
foreach ($entry in $forbiddenEntries) {
    if ($approvedMap.ContainsKey($entry)) {
        throw "Forbidden entry unexpectedly approved: $entry"
    }
}

if (Test-Path -LiteralPath $StagingDir) {
    Remove-Item -LiteralPath $StagingDir -Recurse -Force
}
New-Item -ItemType Directory -Path $StagingDir -Force | Out-Null
New-Item -ItemType Directory -Path $DistDir -Force | Out-Null

# Stage only approved files (preserves bytes; includes hidden .htaccess).
foreach ($item in $approvedSources) {
    $dest = Join-Path $StagingDir (($item.RelativePath -replace '/', [IO.Path]::DirectorySeparatorChar))
    $destDir = Split-Path -Parent $dest
    if (-not (Test-Path -LiteralPath $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $item.FullPath -Destination $dest -Force
}

$outDir = Split-Path -Parent $OutputPath
if (-not (Test-Path -LiteralPath $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}
if (Test-Path -LiteralPath $OutputPath) {
    Remove-Item -LiteralPath $OutputPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$archive = [System.IO.Compression.ZipFile]::Open($OutputPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($item in ($approvedSources | Sort-Object RelativePath)) {
        $entryName = Normalize-PackageRelativePath -Path $item.RelativePath
        $staged = Join-Path $StagingDir (($entryName -replace '/', [IO.Path]::DirectorySeparatorChar))
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $staged,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    $archive.Dispose()
}

Remove-Item -LiteralPath $StagingDir -Recurse -Force

$hash = Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256
Write-Output "Package: $OutputPath"
Write-Output "SHA256: $($hash.Hash)"

# ---- Full ZIP manifest validation ----
$zipRead = [System.IO.Compression.ZipFile]::OpenRead($OutputPath)
try {
    $zipFiles = @{}
    $zipNormCase = @{}

    foreach ($entry in $zipRead.Entries) {
        $name = Normalize-PackageRelativePath -Path $entry.FullName
        if ($entry.FullName.EndsWith('/') -or $entry.FullName.EndsWith('\')) {
            # Directory markers are optional; ignore for file-level manifest.
            continue
        }
        if ($entry.Length -eq 0 -and ($entry.FullName.EndsWith('/') -or $entry.Name -eq '')) {
            continue
        }

        if (Test-PackagePathTraversal -Path $entry.FullName) {
            throw "ZIP path traversal rejected: $($entry.FullName)"
        }

        $reason = Get-PackagePathForbiddenReason -RelativePath $name
        if ($null -ne $reason) {
            throw "ZIP contains forbidden entry: $name ($reason)"
        }

        # Root layout: only install.xml and upload/...
        if ($name -ne 'install.xml' -and -not $name.StartsWith('upload/')) {
            throw "Unexpected ZIP root payload: $name"
        }

        $caseKey = $name.ToLowerInvariant()
        if ($zipNormCase.ContainsKey($caseKey)) {
            throw "Duplicate ZIP path (normalized/case): $name"
        }
        if ($zipFiles.ContainsKey($name)) {
            throw "Duplicate ZIP entry: $name"
        }
        $zipNormCase[$caseKey] = $name
        $zipFiles[$name] = $entry
    }

    foreach ($entry in $expectedEntries) {
        if (-not $zipFiles.ContainsKey($entry)) {
            if (-not $SkipLegacyEntryChecklist) {
                throw "Package missing required entry: $entry"
            }
        }
    }
    foreach ($entry in $forbiddenEntries) {
        if ($zipFiles.ContainsKey($entry)) {
            throw "Package must not contain forbidden entry: $entry"
        }
    }

    # Complete manifest: ZIP files == approved sources (exact set).
    foreach ($key in $approvedMap.Keys) {
        if (-not $zipFiles.ContainsKey($key)) {
            throw "ZIP missing approved source file: $key"
        }
    }
    foreach ($key in $zipFiles.Keys) {
        if (-not $approvedMap.ContainsKey($key)) {
            throw "ZIP contains extra file not in approved source set: $key"
        }
    }

    # Byte integrity + content sentinels.
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        foreach ($key in ($approvedMap.Keys | Sort-Object)) {
            $srcPath = [string]$approvedMap[$key].FullPath
            $srcHash = Get-FileSha256Hex -LiteralPath $srcPath

            $zipEntry = $zipFiles[$key]
            $stream = $zipEntry.Open()
            try {
                $ms = New-Object System.IO.MemoryStream
                try {
                    $stream.CopyTo($ms)
                    $bytes = $ms.ToArray()
                }
                finally {
                    $ms.Dispose()
                }
            }
            finally {
                $stream.Dispose()
            }

            $zipHashBytes = $sha.ComputeHash($bytes)
            $zipHash = ([BitConverter]::ToString($zipHashBytes) -replace '-', '').ToUpperInvariant()
            if ($zipHash -ne $srcHash) {
                throw "ZIP byte mismatch for $key"
            }

            if (Test-PackageTextualExtension -RelativePath $key) {
                $text = [System.Text.Encoding]::UTF8.GetString($bytes)
                $hits = @(Get-PackageTextScanHits -Content $text)
                if ($hits.Count -gt 0) {
                    throw ("ZIP content policy failed for ${key}: " + ($hits -join ', '))
                }
            }
        }
    }
    finally {
        $sha.Dispose()
    }
}
finally {
    $zipRead.Dispose()
}

Write-Output 'Package policy validation: PASS'
