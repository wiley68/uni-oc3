# Build deterministic UniCredit OpenCart 3 distributable package.
# Output: dist/CC_OpenCartv.3.x_UNI_v.2.0.2.ocmod.zip

$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $PSScriptRoot
$DistDir = Join-Path $Root 'dist'
$StagingDir = Join-Path $DistDir 'package-staging'
$Version = '2.0.2'
$PackageName = "CC_OpenCartv.3.x_UNI_v.$Version.ocmod.zip"
$OutputPath = Join-Path $DistDir $PackageName

$RequiredPaths = @(
    (Join-Path $Root 'install.xml'),
    (Join-Path $Root 'upload')
)

foreach ($path in $RequiredPaths) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Missing required package path: $path"
    }
}

if (Test-Path -LiteralPath $StagingDir) {
    Remove-Item -LiteralPath $StagingDir -Recurse -Force
}

New-Item -ItemType Directory -Path $StagingDir -Force | Out-Null
New-Item -ItemType Directory -Path $DistDir -Force | Out-Null

Copy-Item -LiteralPath (Join-Path $Root 'install.xml') -Destination (Join-Path $StagingDir 'install.xml')
Copy-Item -LiteralPath (Join-Path $Root 'upload') -Destination (Join-Path $StagingDir 'upload') -Recurse

if (Test-Path -LiteralPath $OutputPath) {
    Remove-Item -LiteralPath $OutputPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Add-DirectoryToZip {
    param(
        [Parameter(Mandatory = $true)][string]$SourceDir,
        [Parameter(Mandatory = $true)][System.IO.Compression.ZipArchive]$Archive,
        [string]$EntryPrefix = ''
    )

    $files = Get-ChildItem -LiteralPath $SourceDir -Recurse -File | Sort-Object FullName
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($SourceDir.Length).TrimStart('\', '/')
        if ($EntryPrefix -eq '') {
            $entryName = $relative.Replace('\', '/')
        }
        else {
            $entryName = ($EntryPrefix.TrimEnd('\', '/') + '/' + $relative.Replace('\', '/')).TrimStart('/')
        }
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($Archive, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
}

$archive = [System.IO.Compression.ZipFile]::Open($OutputPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Add-DirectoryToZip -SourceDir $StagingDir -Archive $archive -EntryPrefix ''
}
finally {
    $archive.Dispose()
}

Remove-Item -LiteralPath $StagingDir -Recurse -Force

$hash = Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256
Write-Output "Package: $OutputPath"
Write-Output "SHA256: $($hash.Hash)"

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
    'upload/system/library/mt_uni_credit/config/environment.php'
)
$forbiddenEntries = @(
    'upload/config/environment.php',
    'upload/catalog/view/image/mt_uni_credit/uni_logo.svg',
    'upload/catalog/view/image/mt_uni_credit/uni_logo_red.svg'
)
$zipRead = [System.IO.Compression.ZipFile]::OpenRead($OutputPath)
try {
    foreach ($entry in $expectedEntries) {
        if ($null -eq $zipRead.GetEntry($entry)) {
            throw "Package missing required entry: $entry"
        }
    }
    foreach ($entry in $forbiddenEntries) {
        if ($null -ne $zipRead.GetEntry($entry)) {
            throw "Package must not contain forbidden entry: $entry"
        }
    }
    foreach ($entry in $zipRead.Entries) {
        $name = $entry.FullName.Replace('\', '/')
        if ($name -like 'upload/catalog/view/image/*') {
            throw "OC3 package must not write to catalog/view/image: $name"
        }
    }
}
finally {
    $zipRead.Dispose()
}
