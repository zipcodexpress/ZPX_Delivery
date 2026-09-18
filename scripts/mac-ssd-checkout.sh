#!/bin/bash
set -euo pipefail
if [[ "$(uname -s)" != Darwin ]]; then echo "Run this script on your Mac." >&2; exit 1; fi
if [[ $# -lt 1 || $# -gt 2 ]]; then echo 'Usage: bash mac-ssd-checkout.sh "/Volumes/Your SSD" [branch]' >&2; exit 1; fi
volume="$1"
branch="${2:-feature/P0.1-m4-foundation}"
if [[ ! -d "$volume" ]]; then echo "SSD is not mounted: $volume" >&2; exit 1; fi
command -v python3 >/dev/null || { echo 'Install Python 3 first.' >&2; exit 1; }
python3 - "$volume" <<'PY'
import pathlib, plistlib, subprocess, sys
volume=pathlib.Path(sys.argv[1]).resolve()
info=plistlib.loads(subprocess.check_output(['diskutil','info','-plist',str(volume)]))
if len(volume.parts)!=3 or volume.parts[1]!='Volumes' or info.get('MountPoint')!=str(volume):
    sys.exit('Pass the mounted volume root, such as /Volumes/Development SSD.')
if info.get('FilesystemType','').lower()!='apfs' or info.get('Internal') is not False or info.get('ReadOnlyVolume') is True:
    sys.exit('An external writable APFS volume is required. No disk changes were made.')
PY
target="$volume/Developer/ZPX_Delivery"
if [[ -e "$target" ]]; then echo "Path already exists; left unchanged: $target"; exit 1; fi
mkdir -p "$volume/Developer"
if command -v gh >/dev/null 2>&1; then
  gh auth status
  gh repo clone zipcodexpress/ZPX_Delivery "$target" -- --branch "$branch"
else
  git clone --branch "$branch" https://github.com/zipcodexpress/ZPX_Delivery.git "$target"
fi
printf 'Checkout created at: %s\nNext: cd "%s" and run python3 scripts/dev.py up\n' "$target" "$target"
