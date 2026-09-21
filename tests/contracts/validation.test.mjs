import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { validateContract, renderTypes } from '../../scripts/contracts.mjs';

const canonical = JSON.parse(await readFile(new URL('../../docs/handoff/contracts/openapi.json', import.meta.url), 'utf8'));

test('canonical API has 71 validated operations and deterministic generated types', async () => {
  assert.equal(await validateContract(canonical), 71);
  assert.equal(await renderTypes(canonical), await renderTypes(canonical));
});
test('reject broken response schemas and unresolved references', async () => {
  const bad = structuredClone(canonical);
  bad.paths['/auth/challenges'].post.responses = { '200': {} };
  await assert.rejects(validateContract(bad));
  const ref = structuredClone(canonical);
  ref.components.schemas.Broken = { $ref: '#/components/schemas/DoesNotExist' };
  await assert.rejects(validateContract(ref));
});
test('reject external references before resolving them', async () => {
  const bad = structuredClone(canonical);
  bad.components.schemas.External = { $ref: 'file:///not-a-contract.json' };
  await assert.rejects(validateContract(bad), /Only internal/);
});
test('reject duplicate operation IDs and missing required path parameters', async () => {
  const bad = structuredClone(canonical);
  bad.paths['/duplicate'] = structuredClone(bad.paths['/auth/challenges']);
  await assert.rejects(validateContract(bad), /duplicate operationId/);
  const missing = structuredClone(canonical);
  missing.paths['/missing/{id}'] = { get: { operationId: 'missingParameter', responses: { '200': { description: 'ok' } } } };
  await assert.rejects(validateContract(missing), /path parameter/);
});
