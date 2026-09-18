import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import SwaggerParser from '@apidevtools/swagger-parser';
import openapiTS, { astToString } from 'openapi-typescript';

const source = new URL('../docs/handoff/contracts/openapi.json', import.meta.url);
const output = new URL('../packages/contracts/generated/api.ts', import.meta.url);
const methods = new Set(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace']);

export async function validateContract(document) {
  // Keep builds reproducible and prevent a changed contract from reading arbitrary files/URLs.
  function visit(value) {
    if (!value || typeof value !== 'object') return;
    if ('$ref' in value && (typeof value.$ref !== 'string' || !value.$ref.startsWith('#/')))
      throw new Error('Only internal contract references are allowed');
    for (const child of Object.values(value)) visit(child);
  }
  visit(document);
  await SwaggerParser.validate(structuredClone(document), { resolve: { external: false } });
  const ids = new Set();
  for (const [path, item] of Object.entries(document.paths)) {
    for (const [method, operation] of Object.entries(item)) {
      if (!methods.has(method)) continue;
      if (!operation.operationId || ids.has(operation.operationId)) throw new Error('Missing or duplicate operationId');
      ids.add(operation.operationId);
      const params = [...(item.parameters ?? []), ...(operation.parameters ?? [])];
      for (const [, name] of path.matchAll(/\{([^}]+)\}/g)) {
        if (!params.some(p => p.in === 'path' && p.name === name && p.required === true))
          throw new Error(`Missing required path parameter ${name} in ${method} ${path}`);
      }
    }
  }
  return ids.size;
}

export async function renderTypes(document) {
  await validateContract(document);
  return '// Generated from docs/handoff/contracts/openapi.json. Do not edit.\n' +
    astToString(await openapiTS(document));
}

async function main(mode) {
  if (!['check', 'generate'].includes(mode)) throw new Error('Usage: node scripts/contracts.mjs check|generate');
  const document = JSON.parse(await readFile(source, 'utf8'));
  const rendered = await renderTypes(document);
  if (mode === 'generate') {
    await mkdir(new URL('../packages/contracts/generated/', import.meta.url), { recursive: true });
    await writeFile(output, rendered);
    console.log('Generated API types from the canonical contract.');
  } else {
    const existing = await readFile(output, 'utf8').catch(() => '');
    if (existing !== rendered) throw new Error('Generated API types are stale. Run npm run contracts:generate and commit the result.');
    console.log('PASS: OpenAPI validation and generated type consistency.');
  }
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main(process.argv[2]).catch(error => { console.error(error.message); process.exitCode = 1; });
}
