"""Static handoff checks only; not a MySQL execution or full OpenAPI validator."""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
api = json.loads((ROOT / 'contracts/openapi.json').read_text())
assert api['openapi'] == '3.0.3'
schemas = api['components']['schemas']
operations = []

def walk(value):
    if isinstance(value, dict):
        if '$ref' in value:
            target = value['$ref']
            assert target.startswith('#/components/schemas/'), target
            assert target.rsplit('/', 1)[1] in schemas, target
        if value.get('type') == 'object':
            assert set(value.get('required', [])) <= set(value.get('properties', {}))
        for child in value.values(): walk(child)
    elif isinstance(value, list):
        for child in value: walk(child)

walk(api)
for path, methods in api['paths'].items():
    for method, operation in methods.items():
        operations.append(operation['operationId'])
        params = {p['name'] for p in operation.get('parameters', []) if p['in'] == 'path'}
        assert params == set(re.findall(r'\{([^}]+)\}', path)), path
        assert operation['responses'], path
        for security in operation.get('security', api['security']):
            assert set(security) <= set(api['components']['securitySchemes'])
assert len(operations) == len(set(operations))

sql = '\n'.join(p.read_text() for p in sorted((ROOT / 'sql').glob('*.sql')))
tables = re.findall(r'CREATE TABLE\s+(\w+)', sql, re.I)
assert len(tables) == len(set(tables))
for target in re.findall(r'REFERENCES\s+(\w+)\s*\(', sql, re.I):
    assert target in tables, target
for target in re.findall(r'ALTER TABLE\s+(\w+)', sql, re.I):
    assert target in tables, target

fixture = json.loads((ROOT / 'fixtures/pilot.json').read_text())
assert fixture['synthetic'] and len(fixture['locations']) == 20
assert sum(x['zone'] == 'DOWNTOWN' for x in fixture['locations']) == 3
groups = [s['package_ids'] for s in fixture['run']['stops']]
assert list(map(len, groups)) == [6, 4]
assert len(set(sum(groups, []))) == 10

repos = json.loads((ROOT / 'evidence/repositories.json').read_text())
assert len(repos) == 4 and all(re.fullmatch('[a-f0-9]{40}', x['revision']) for x in repos)
inventory = json.loads((ROOT / 'evidence/source_inventory.json').read_text())
assert all(x['revision'] in {r['revision'] for r in repos} for x in inventory)
assert all(not r['tree_truncated'] for r in repos)
for file in ROOT.rglob('*.md'):
    for target in re.findall(r'\]\(([^)]+)\)', file.read_text()):
        if not target.startswith(('https:', 'http:', '#')):
            assert (file.parent / target.split('#')[0]).exists(), (file, target)

print(f'PASS: {len(operations)} API operations, {len(schemas)} schemas, {len(tables)} proposed SQL tables, 20-site/10-parcel fixture, 4 repository revisions, {len(inventory)} source references.')
print('NOT VERIFIED: OpenAPI semantic conformance by external validator; MySQL execution; application builds; physical hardware; production integration.')
