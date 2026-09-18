#!/usr/bin/env python3
"""Local development runner. Does not deploy, format disks, or remove data volumes."""
import argparse
import json
import os
from pathlib import Path
import platform
import plistlib
import secrets
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]

def validate_mac_volume(root, info):
    parts = Path(root).resolve().parts
    if len(parts) < 4 or parts[1] != 'Volumes':
        raise RuntimeError('Checkout must be on the external SSD under /Volumes/<volume>/Developer/ZPX_Delivery.')
    if info.get('FilesystemType', '').lower() != 'apfs':
        raise RuntimeError('The checkout volume must be APFS. This tool will not reformat it.')
    if info.get('Internal') is not False:
        raise RuntimeError('Cannot verify this is an external volume. Check diskutil info for the SSD.')
    if info.get('ReadOnlyVolume') is True:
        raise RuntimeError('The external volume is read-only.')

def check_storage():
    if platform.system() == 'Darwin':
        raw = subprocess.check_output(['diskutil', 'info', '-plist', str(ROOT)])
        validate_mac_volume(ROOT, plistlib.loads(raw))
        print(f'External APFS checkout: {ROOT}')
        print(f'Mac architecture: {platform.machine()} (M4 should report arm64)')

def init_env():
    check_storage()
    path = ROOT / '.env'
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:
        # Add new keys without rotating credentials already used by persistent volumes.
        existing = {line.split('=', 1)[0].strip() for line in path.read_text().splitlines() if '=' in line}
        if 'DB_MIGRATION_PASSWORD' not in existing:
            with path.open('a') as file:
                file.write('\nDB_MIGRATION_PASSWORD=' + secrets.token_hex(32) + '\n')
            print('Added missing migration credential; existing credentials preserved.')
        else:
            print('Existing .env preserved.')
        return
    with os.fdopen(fd, 'w') as file:
        file.write('# Local development only; generated credentials.\n')
        for key in ('DB_PASSWORD', 'DB_ROOT_PASSWORD', 'DB_MIGRATION_PASSWORD', 'SIMULATOR_TOKEN'):
            file.write(f'{key}={secrets.token_hex(32)}\n')
    print('Created .env with random local credentials (not displayed).')

def docker():
    if not shutil.which('docker'):
        raise RuntimeError('Install Docker Desktop for Apple Silicon, open it, then retry.')
    subprocess.run(['docker', 'compose', 'version'], check=True)
    subprocess.run(['docker', 'info'], check=True, stdout=subprocess.DEVNULL)

def compose(*args, capture=False):
    cmd = ['docker', 'compose', '--project-directory', str(ROOT), '--env-file', str(ROOT / '.env'), '-f', str(ROOT / 'deployment/compose.yaml'), *args]
    return subprocess.run(cmd, cwd=ROOT, check=True, text=True, capture_output=capture)

def smoke(timeout=180):
    targets = {
        'api': ('http://127.0.0.1:8000/health/ready', b'"database":"ready"'),
        'customer': ('http://127.0.0.1:5173', b'ZPX Customer'),
        'operations': ('http://127.0.0.1:5174', b'ZPX Operations'),
        'customer API proxy': ('http://127.0.0.1:5173/health/ready', b'"database":"ready"'),
        'operations API proxy': ('http://127.0.0.1:5174/health/ready', b'"database":"ready"'),
        'simulator': ('http://127.0.0.1:8090/health/live', b'"synthetic":true'),
    }
    deadline = time.monotonic() + timeout
    pending = dict(targets)
    while pending and time.monotonic() < deadline:
        for name, (url, marker) in list(pending.items()):
            try:
                with urllib.request.urlopen(url, timeout=2) as response:
                    if response.status == 200 and marker in response.read():
                        print(f'PASS {name}'); del pending[name]
            except (urllib.error.URLError, TimeoutError):
                pass
        if pending: time.sleep(1)
    if pending: raise RuntimeError('Services not ready: ' + ', '.join(pending) + '. Run the logs command.')
    print('Foundation smoke passed; this is not a parcel-delivery end-to-end test.')

def test_db():
    # Integration tests use synthetic records and roll back their data.
    compose('run', '--rm', 'db-tests')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['init','doctor','up','down','logs','smoke','test-db','config','migrate'])
    args = parser.parse_args()
    if args.command == 'init': return init_env()
    check_storage()
    if args.command == 'smoke': return smoke()
    docker()
    if args.command == 'doctor':
        print('Docker available. Named volumes use Docker Desktop disk storage, not automatically the repo SSD.'); return
    if args.command == 'up':
        init_env(); compose('build'); compose('up', '-d', 'postgres'); compose('run', '--rm', 'migrate'); compose('up', '-d'); smoke(); return
    if not (ROOT / '.env').exists(): raise RuntimeError('Run python3 scripts/dev.py init first.')
    if args.command == 'down': compose('down'); print('Stopped. Database and simulator volumes retained.')
    elif args.command == 'logs': compose('logs', '--tail', '100')
    elif args.command == 'config': compose('config', '--quiet')
    elif args.command == 'test-db': test_db()
    elif args.command == 'migrate': compose('run', '--rm', 'migrate')

if __name__ == '__main__':
    try: main()
    except (RuntimeError, subprocess.CalledProcessError, OSError) as error:
        print(f'ERROR: {error}', file=sys.stderr); sys.exit(1)
