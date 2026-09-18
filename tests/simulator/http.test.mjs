import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { startSimulator } from '../../simulators/locker/server.mjs';
test('HTTP authentication, parallel duplicate requests, and durable restart', async () => {
  const dir = await mkdtemp(join(tmpdir(),'zpx-sim-')); const token = 't'.repeat(48);
  let server;
  const close = async () => { if (server) { server.closeAllConnections(); await new Promise(r=>server.close(r)); } };
  try {
    const options = {token, statePath:join(dir,'state.json'), port:0};
    server = await startSimulator(options);
    let url = `http://127.0.0.1:${server.address().port}`;
    assert.equal((await fetch(url+'/state')).status,401);
    const body={command_id:'a',session_id:'s',address:'1:1',ownership_generation:1,expires_at:'2099-01-01',scenario:'crash_after_dispatch'};
    const request = () => fetch(url+'/commands',{method:'POST',headers:{Authorization:'Bearer '+token},body:JSON.stringify(body)}).then(r=>r.json());
    const results = await Promise.all([request(),request()]);
    assert.equal(results.filter(x=>x.replay).length,1);
    await close(); server = await startSimulator(options); url=`http://127.0.0.1:${server.address().port}`;
    const replay=await request(); assert.equal(replay.status,'UNKNOWN'); assert.equal(replay.open_attempts,1);
  } finally { await close(); await rm(dir,{recursive:true,force:true}); }
});
