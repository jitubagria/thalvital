param(
    [Parameter(Mandatory=$true)][string]$Tag,
    [Parameter(Mandatory=$true)][string]$RemoteHost,
    [Parameter(Mandatory=$true)][string]$RemoteUser,
    [string]$RemoteRoot = '/var/www/thalvital'
)

$ErrorActionPreference = 'Stop'
$dirty = @(git status --porcelain)
if ($dirty.Count -gt 0) { throw 'Working tree or index is dirty. Commit or stash before releasing.' }
git rev-parse -q --verify "refs/tags/$Tag" | Out-Null
$sha = (git rev-list -n 1 $Tag).Trim()
$releaseId = Get-Date -Format 'yyyyMMddHHmmss'
$temp = Join-Path ([IO.Path]::GetTempPath()) "thalvital-release-$releaseId"
$archive = Join-Path $temp 'release.tar'
New-Item -ItemType Directory -Force $temp | Out-Null
try {
    # A tag archive, never the working tree, defines the application payload.
    git archive --format=tar --prefix=public/ $Tag -o $archive
    $release = "tag=$Tag`ncommit=$sha`nbuilt_at=$((Get-Date).ToUniversalTime().ToString('o'))`n"
    [IO.File]::WriteAllText((Join-Path $temp 'RELEASE.txt'), $release)
    tar -rf $archive -C $temp RELEASE.txt
    $remoteTar = "/tmp/thalvital-$releaseId.tar"
    scp $archive "$RemoteUser@$RemoteHost`:$remoteTar"
    $cmd = "set -e; release='$RemoteRoot/releases/$releaseId'; mkdir -p `$release; tar -xf '$remoteTar' -C `$release; ln -sfn '$RemoteRoot/shared/config/config.php' `$release/public/config.php; test -f `$release/RELEASE.txt; ln -sfnT `$release '$RemoteRoot/current'; rm -f '$remoteTar'; readlink -f '$RemoteRoot/current'"
    ssh "$RemoteUser@$RemoteHost" $cmd
    Write-Host "Released $Tag ($sha) as $releaseId."
} finally { Remove-Item -Recurse -Force $temp -ErrorAction SilentlyContinue }
