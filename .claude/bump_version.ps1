# Called as a PostToolUse hook after Edit/Write (see .claude/settings.json).
# Reads Claude Code hook JSON from stdin; when the edited file is
# lsl/FishingHUD.lsl, bumps its HUD_VERSION ("YYYY.MM.DD.NN"): same day ->
# NN + 1, new day -> today's date with NN = 01.

$stdin = [Console]::In.ReadToEnd()
try { $data = $stdin | ConvertFrom-Json } catch { exit 0 }

$filePath = $data.tool_input.file_path
if (-not $filePath) { exit 0 }
if ($filePath -notlike "*FishingHUD.lsl") { exit 0 }
if (-not (Test-Path $filePath)) { exit 0 }

$content = [System.IO.File]::ReadAllText($filePath)
if ($content -notmatch '(string\s+\bHUD_VERSION\s+=\s+")(\d{4}\.\d{2}\.\d{2})\.(\d{2})(")') { exit 0 }
$currentDate = $Matches[2]
$currentIter = [int]$Matches[3]

$today = (Get-Date).ToString("yyyy.MM.dd")
if ($currentDate -eq $today) { $newIter = $currentIter + 1 } else { $newIter = 1 }
$newVersion = $today + "." + $newIter.ToString("D2")

$pattern = '(string\s+\bHUD_VERSION\s+=\s+")\d{4}\.\d{2}\.\d{2}\.\d{2}(")'
$newContent = $content -replace $pattern, ('${1}' + $newVersion + '${2}')

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($filePath, $newContent, $utf8NoBom)
