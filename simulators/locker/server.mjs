import { createServer } from 'node:http';
import { readFile, mkdir, open, rename } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { timingSafeEqual } from 'node:crypto';
import { LockerSimulator } from './model.mjs';

export async function startSimulator({ token, statePath, host = '127.0.0.1', port = 8090 }) {
  if (!token || token.length < 32) throw new Error('SIMULATOR_TOKEN must have at least 32 characters');
  let state;
  try { state = JSON.parse(await readFile(statePath, 'utf8')); }
  catch (e) { if (e.code !== 'ENOENT') throw e; }
  let model = new LockerSimulator(state);
  await mkdir(dirname(statePath), { recursive: true });
  let queue = Promise.resolve();
  const save = async (next) => {
    const temp = statePath + '.tmp';
    const fd = await open(temp, 'w', 0o600);
    try { await fd.writeFile(JSON.stringify(next.state)); await fd.sync(); } finally { await fd.close(); }
    await rename(temp, statePath);
    model = next;
  };
  const server = createServer(async (req, res) => {
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Cache-Control', 'no-store');
    const reply = (status, data) => { res.statusCode = status; res.end(JSON.stringify(data)); };
    if (req.method === 'GET' && req.url === '/health/live') return reply(200, { status: 'ok', synthetic: true });
    const actual = Buffer.from(req.headers.authorization ?? '');
    const expected = Buffer.from('Bearer ' + token);
    if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) return reply(401, { code: 'UNAUTHORIZED' });
    if (req.method === 'GET' && req.url === '/state') return reply(200, model.state);
    if (req.method !== 'POST' || !['/commands', '/close'].includes(req.url)) return reply(404, { code: 'NOT_FOUND' });
    try {
      let body = '';
      for await (const chunk of req) { body += chunk; if (body.length > 8192) return reply(413, { code: 'BODY_TOO_LARGE' }); }
      const input = JSON.parse(body);
      const task = queue.then(async () => {
        const next = new LockerSimulator(structuredClone(model.state));
        const result = req.url === '/commands' ? next.dispatch(input) : next.close(input.command_id, input.address);
        await save(next); // Persist before acknowledging even simulated effects.
        return result;
      });
      queue = task.catch(() => {});
      reply(200, await task);
    } catch (e) {
      const known = ['INVALID_COMMAND','INVALID_SCENARIO','IDEMPOTENCY_CONFLICT','OWNERSHIP_DENIED','STALE_GENERATION','EXPIRED','DOOR_BUSY','CORRELATION_MISMATCH','RECONCILIATION_REQUIRED'];
      reply(e instanceof SyntaxError ? 400 : known.includes(e.message) ? 409 : 503,
        { code: e instanceof SyntaxError ? 'INVALID_JSON' : known.includes(e.message) ? e.message : 'JOURNAL_UNAVAILABLE' });
    }
  });
  await new Promise((done, reject) => { server.once('error', reject); server.listen(port, host, done); });
  return server;
}
if (process.argv[1] && resolve(process.argv[1]) === resolve(new URL(import.meta.url).pathname)) {
  await startSimulator({ token: process.env.SIMULATOR_TOKEN,
    statePath: process.env.SIMULATOR_STATE_PATH || '.local/simulator/state.json',
    host: process.env.SIMULATOR_HOST || '127.0.0.1', port: Number(process.env.PORT || 8090) });
  console.log('Synthetic locker simulator listening; no physical hardware connection.');
}
