import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import MagicMock, patch

spec=importlib.util.spec_from_file_location('dev', Path(__file__).resolve().parents[1]/'scripts/dev.py')
dev=importlib.util.module_from_spec(spec); spec.loader.exec_module(dev)

class SetupTests(unittest.TestCase):
    def test_smoke_retries_connection_closed_during_startup(self):
        response = MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.read.return_value = b'"database":"ready" ZPX Customer ZPX Operations "synthetic":true'
        with patch.object(dev.urllib.request, 'urlopen', side_effect=[dev.http.client.RemoteDisconnected()] + [response] * 6) as request, patch.object(dev.time, 'sleep'):
            dev.smoke(timeout=5)
        self.assertEqual(request.call_count, 7)
    def test_storage_queries_mount_instead_of_checkout(self):
        root = Path('/Volumes/Development SSD/Developer/ZPX_Delivery')
        info = {'FilesystemType': 'apfs', 'Internal': False}
        with patch.object(dev, 'ROOT', root), patch.object(dev.platform, 'system', return_value='Darwin'), patch.object(Path, 'resolve', lambda p: p), patch.object(Path, 'is_mount', lambda p: p == Path('/Volumes/Development SSD')), patch.object(dev.subprocess, 'check_output', return_value=dev.plistlib.dumps(info)) as query:
            dev.check_storage()
        query.assert_called_once_with(['diskutil', 'info', '-plist', '/Volumes/Development SSD'])
    def test_external_path_with_spaces(self):
        dev.validate_mac_volume('/Volumes/Development SSD/Developer/ZPX_Delivery', {'FilesystemType':'apfs','Internal':False})
    def test_reject_internal_and_exfat(self):
        for path, info in [('/Users/test/project',{'FilesystemType':'apfs','Internal':False}),('/Volumes/SSD/project',{'FilesystemType':'exfat','Internal':False}),('/Volumes/SSD/project',{'FilesystemType':'apfs','Internal':True})]:
            with self.assertRaises(RuntimeError): dev.validate_mac_volume(path,info)
    def test_generated_secrets_preserved_and_private(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(dev,'ROOT',Path(directory)), patch.object(dev,'check_storage'):
            dev.init_env(); p=Path(directory)/'.env'; first=p.read_text()
            dev.init_env(); self.assertEqual(first,p.read_text()); self.assertEqual(p.stat().st_mode & 0o777,0o600)
            values=[x.split('=')[1] for x in first.splitlines() if '=' in x]
            self.assertEqual(len(set(values)),4); self.assertTrue(all(len(v)==64 for v in values))
    def test_existing_mysql_env_gets_only_missing_key(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(dev,'ROOT',Path(directory)), patch.object(dev,'check_storage'):
            p=Path(directory)/'.env'; p.write_text('DB_PASSWORD=keep\nDB_ROOT_PASSWORD=keep_root\nSIMULATOR_TOKEN=keep_sim\n')
            dev.init_env(); updated=p.read_text(); dev.init_env()
            self.assertTrue(updated.startswith('DB_PASSWORD=keep\nDB_ROOT_PASSWORD=keep_root\nSIMULATOR_TOKEN=keep_sim\n'))
            self.assertEqual(p.read_text(),updated); self.assertIn('DB_MIGRATION_PASSWORD=',updated)

if __name__=='__main__': unittest.main()
