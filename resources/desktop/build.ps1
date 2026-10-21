# Builds public/downloads/COLISAP.exe (one file, WebView2 libraries inside) with the C# compiler that ships
# with Windows (.NET Framework 4), and writes public/downloads/COLISAP.version so installed apps update themselves.
# Before publishing a new build, raise Program.Version in ColisapApp.cs.
# Run from the project root:  powershell -ExecutionPolicy Bypass -File resources/desktop/build.ps1

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Resolve-Path (Join-Path $here '..\..')
$out = Join-Path $root 'public\downloads'
$wv = Join-Path $here 'webview2'
$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'

$source = Get-Content (Join-Path $here 'ColisapApp.cs') -Raw
if ($source -notmatch 'const string Version = "([0-9.]+)"') { throw 'Program.Version not found in ColisapApp.cs' }
$version = $Matches[1]

New-Item -ItemType Directory -Force $out | Out-Null

& $csc /nologo /target:winexe /optimize+ /codepage:65001 /platform:anycpu `
    "/win32icon:$here\colisap.ico" `
    "/out:$out\COLISAP.exe" `
    /r:System.Windows.Forms.dll /r:System.Drawing.dll `
    "/r:$wv\Microsoft.Web.WebView2.Core.dll" "/r:$wv\Microsoft.Web.WebView2.WinForms.dll" `
    "/resource:$wv\Microsoft.Web.WebView2.Core.dll,Microsoft.Web.WebView2.Core.dll" `
    "/resource:$wv\Microsoft.Web.WebView2.WinForms.dll,Microsoft.Web.WebView2.WinForms.dll" `
    "/resource:$wv\x64\WebView2Loader.dll,WebView2Loader.x64.dll" `
    "/resource:$wv\x86\WebView2Loader.dll,WebView2Loader.x86.dll" `
    "$here\ColisapApp.cs"

if ($LASTEXITCODE -ne 0) { throw 'Build failed' }

Set-Content -Path (Join-Path $out 'COLISAP.version') -Value $version -NoNewline -Encoding ascii

Write-Output "Built $out\COLISAP.exe (version $version)"
