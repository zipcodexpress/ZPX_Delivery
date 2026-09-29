#!/usr/bin/env python3
"""Local development runner. Does not deploy, format disks, or remove data volumes."""
import argparse
import http.client
import json
import os
from pathlib import Path
import platform
import plistlib
import secrets
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
SECRET_KEYS = ('DB_PASSWORD', 'DB_ROOT_PASSWORD', 'DB_MIGRATION_PASSWORD', 'SIMULATOR_TOKEN', 'AUTH_ENCRYPTION_KEY', 'AUTH_LOOKUP_KEY')

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
        volume = ROOT.resolve()
        while not volume.is_mount() and volume != volume.parent:
            volume = volume.parent
        raw = subprocess.check_output(['diskutil', 'info', '-plist', str(volume)])
        validate_mac_volume(ROOT, plistlib.loads(raw))
        print(f'External APFS checkout: {ROOT}')
        print(f'Mac architecture: {platform.machine()} (M4 should report arm64)')

def env_path():
    # Explicit development file wins; never auto-load production configuration.
    return ROOT / ('.env.dev' if (ROOT / '.env.dev').is_file() else '.env')

def database_host():
    if 'DB_HOST' in os.environ:
        return os.environ['DB_HOST']
    for line in env_path().read_text().splitlines():
        if line.startswith('DB_HOST='):
            return line.partition('=')[2].strip()
    return 'postgres'

def init_env():
    check_storage()
    path = env_path()
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:
        # Add new keys without rotating credentials already used by persistent volumes.
        existing = {line.split('=', 1)[0].strip() for line in path.read_text().splitlines() if '=' in line}
        missing = [key for key in SECRET_KEYS if key not in existing]
        if missing:
            with path.open('a') as file:
                for key in missing:
                    file.write('\n' + key + '=' + secrets.token_hex(32) + '\n')
            print('Added missing local secrets; existing credentials preserved.')
        else:
            print(f'Existing {path.name} preserved.')
        return
    with os.fdopen(fd, 'w') as file:
        file.write('# Local development only; generated credentials.\n')
        for key in SECRET_KEYS:
            file.write(f'{key}={secrets.token_hex(32)}\n')
    print('Created .env with random local credentials (not displayed).')

def docker():
    if not shutil.which('docker'):
        raise RuntimeError('Install Docker and start Colima or Docker Desktop, then retry.')
    subprocess.run(['docker', 'compose', 'version'], check=True)
    subprocess.run(['docker', 'info'], check=True, stdout=subprocess.DEVNULL)

def compose(*args, capture=False):
    cmd = ['docker', 'compose', '--project-directory', str(ROOT), '--env-file', str(env_path()), '-f', str(ROOT / 'deployment/compose.yaml'), *args]
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
            except (urllib.error.URLError, TimeoutError, ConnectionError, http.client.RemoteDisconnected):
                pass
        if pending: time.sleep(1)
    if pending: raise RuntimeError('Services not ready: ' + ', '.join(pending) + '. Run the logs command.')
    # This GET was added with resumable receiving. An older API image returns 405 even while health is green.
    route = 'http://127.0.0.1:5174/api/delivery/v1/hub/receiving-sessions'
    try:
        urllib.request.urlopen(route, timeout=2)
    except urllib.error.HTTPError as error:
        try:
            if error.code != 401:
                raise RuntimeError(f'Operations API is out of date: receiving route returned HTTP {error.code}. Rebuild with dev.py up.') from error
            print('PASS receiving route revision')
        finally:
            error.close()
    else:
        raise RuntimeError('Unauthenticated receiving route did not require sign-in.')
    print('Foundation smoke passed; this is not a parcel-delivery end-to-end test.')

def test_db():
    # Fresh, uniquely named test stack; never remove the developer's data volumes.
    project = 'zpx-delivery-tests-' + secrets.token_hex(6)
    previous_host = os.environ.get('DB_HOST')
    os.environ['DB_HOST'] = 'postgres'
    try:
        compose('-p', project, 'build', 'migrate', 'db-tests')
        compose('-p', project, 'up', '-d', '--wait', 'postgres')
        compose('-p', project, 'run', '--rm', 'db-tests')
    finally:
        try: compose('-p', project, '--profile', 'container-db', 'down', '--volumes', '--remove-orphans')
        finally:
            if previous_host is None: os.environ.pop('DB_HOST', None)
            else: os.environ['DB_HOST'] = previous_host

def test_browser():
    # An isolated seeded stack avoids consuming or resetting the developer's parcel workflow.
    project = 'zpx-delivery-browser-' + secrets.token_hex(6)
    def free_port():
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            return listener.getsockname()[1]
    ports = [free_port() for _ in range(4)]
    if len(set(ports)) != 4:
        raise RuntimeError('Could not reserve distinct browser-test ports; retry.')
    names = ('ZPX_API_PORT', 'ZPX_CUSTOMER_PORT', 'ZPX_OPERATIONS_PORT', 'ZPX_SIMULATOR_PORT')
    previous = {name: os.environ.get(name) for name in (*names, 'DB_HOST', 'ZPX_AUTH_ALLOWED_ORIGINS', 'ZPX_ORGANIZATION_ID', 'PAYMENT_PROVIDER')}
    try:
        os.environ['DB_HOST'] = 'postgres'
        for name, port in zip(names, ports): os.environ[name] = str(port)
        os.environ['ZPX_ORGANIZATION_ID'] = '1'
        os.environ['PAYMENT_PROVIDER'] = 'LOCAL_TEST'
        os.environ['ZPX_AUTH_ALLOWED_ORIGINS'] = ','.join(f'http://{host}:{port}' for port in ports[1:3] for host in ('localhost', '127.0.0.1'))
        compose('-p', project, 'build')
        compose('-p', project, 'up', '-d', '--wait', 'postgres')
        compose('-p', project, 'run', '--rm', 'migrate')
        compose('-p', project, 'up', '-d', 'api', 'customer-web', 'operations-web', 'simulator')
        deadline = time.monotonic() + 180
        for port in (ports[1], ports[2]):
            while True:
                try:
                    with urllib.request.urlopen(f'http://127.0.0.1:{port}/health/ready', timeout=2) as response:
                        if response.status == 200 and b'"database":"ready"' in response.read(): break
                except (urllib.error.URLError, TimeoutError, ConnectionError, http.client.RemoteDisconnected):
                    pass
                if time.monotonic() >= deadline: raise RuntimeError('Browser test stack did not become ready.')
                time.sleep(1)
        seed_output = compose('-p', project, 'run', '--rm', 'seed', capture=True)
        seeded = None
        for stream in (seed_output.stdout, seed_output.stderr):
            for index, character in enumerate(stream):
                if character != '{': continue
                try: candidate, _ = json.JSONDecoder().raw_decode(stream[index:])
                except json.JSONDecodeError: continue
                if isinstance(candidate, dict) and 'credentials' in candidate:
                    seeded = candidate
                    break
            if seeded is not None: break
        if seeded is None: raise RuntimeError('Synthetic seed did not return credentials; no private output displayed.')
        if not seeded.get('created') or len(seeded.get('credentials', [])) < 4:
            raise RuntimeError('Browser test requires a fresh isolated synthetic seed.')
        env = os.environ.copy()
        env['ZPX_E2E_CREDENTIALS'] = json.dumps(seeded['credentials'])
        env['ZPX_E2E_OPERATIONS_URL'] = f'http://127.0.0.1:{ports[2]}'
        env['ZPX_E2E_CUSTOMER_URL'] = f'http://127.0.0.1:{ports[1]}'
        subprocess.run(['npx', 'playwright', 'test', 'tests/browser/operations.spec.mjs'], cwd=ROOT, env=env, check=True)
    finally:
        try: compose('-p', project, '--profile', 'container-db', '--profile', 'mail', 'down', '--volumes', '--remove-orphans')
        finally:
            for name, value in previous.items():
                if value is None: os.environ.pop(name, None)
                else: os.environ[name] = value

def inbox():
    result = compose('exec', '-T', 'api', 'php', 'bin/messages.php', capture=True)
    messages = json.loads(result.stdout)
    if not isinstance(messages, list):
        raise RuntimeError('Unexpected development inbox response.')
    directory = ROOT / '.local'
    directory.mkdir(mode=0o700, exist_ok=True)
    path = directory / 'verification-inbox.json'
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as file:
        os.fchmod(file.fileno(), 0o600)
        json.dump(messages, file, indent=2)
        file.write('\n')
    print(f'Saved {len(messages)} local verification messages privately to {path}.')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['init','doctor','up','down','logs','smoke','test-db','test-browser','config','migrate','seed','demo-accounts','inbox'])
    args = parser.parse_args()
    if args.command == 'init': return init_env()
    check_storage()
    if args.command == 'smoke': return smoke()
    docker()
    if args.command == 'doctor':
        print('Docker available. Named volumes use the active container engine disk storage, not automatically the repo SSD.'); return
    if args.command == 'up':
        init_env(); compose('build')
        if database_host() == 'postgres': compose('up', '-d', '--wait', 'postgres')
        compose('run', '--rm', 'migrate'); compose('up', '-d'); smoke(); return
    if args.command == 'test-browser': return test_browser()
    if not env_path().exists(): raise RuntimeError('Run python3 scripts/dev.py init first.')
    if args.command == 'down':
        compose(*(['--profile', 'container-db'] if database_host() == 'postgres' else []), 'down')
        print('Stopped. Database and simulator volumes retained.')
    elif args.command == 'logs': compose('logs', '--tail', '100')
    elif args.command == 'config': compose('config', '--quiet')
    elif args.command == 'test-db': test_db()
    elif args.command == 'migrate': compose('run', '--rm', 'migrate')
    elif args.command == 'seed': compose('run', '--rm', '--build', 'seed')
    elif args.command == 'demo-accounts': compose('exec', '-T', 'api', 'php', 'bin/identity-demo.php')
    elif args.command == 'inbox': inbox()

if __name__ == '__main__':
    try: main()
    except (RuntimeError, subprocess.CalledProcessError, OSError) as error:
        print(f'ERROR: {error}', file=sys.stderr); sys.exit(1)
