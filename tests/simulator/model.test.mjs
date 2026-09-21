import { test } from 'node:test';
import assert from 'node:assert/strict';
import { LockerSimulator } from '../../simulators/locker/model.mjs';
const command = (overrides = {}) => ({ command_id: 'cmd-1', session_id: 'session-1', address: '1:1', ownership_generation: 1, expires_at: '2099-01-01T00:00:00Z', ...overrides });

test('replay does not actuate twice, including after expiry', () => {
  const m = new LockerSimulator(); m.dispatch(command());
  const replay = m.dispatch(command(), Date.parse('2100-01-01'));
  assert.equal(replay.replay, true); assert.equal(replay.open_attempts, 1); assert.equal(m.state.events.length, 2);
  assert.throws(() => m.dispatch(command({ session_id: 'different' })), /IDEMPOTENCY_CONFLICT/);
});
test('ownership, generation and expiry fence new commands', () => {
  for (const [input, code] of [[{address:'1:2'},'OWNERSHIP_DENIED'], [{address:'1:3'},'OWNERSHIP_DENIED'], [{ownership_generation:0},'STALE_GENERATION'], [{expires_at:'2000-01-01'},'EXPIRED']]) {
    const m = new LockerSimulator(); assert.throws(() => m.dispatch(command(input)), new RegExp(code)); assert.equal(m.state.events.length, 0);
  }
});
test('timeout/wrong-address/crash retains claim across restart and never blindly reopens', () => {
  for (const scenario of ['timeout', 'wrong_address', 'crash_after_dispatch']) {
    const m = new LockerSimulator(); m.dispatch(command({scenario}));
    const restarted = new LockerSimulator(JSON.parse(JSON.stringify(m.state)));
    assert.equal(restarted.dispatch(command({scenario})).open_attempts, 1);
    assert.throws(() => restarted.dispatch(command({command_id:'cmd-2'})), /DOOR_BUSY/);
    assert.throws(() => restarted.close('cmd-1','1:1'), /RECONCILIATION_REQUIRED/);
  }
});
test('close correlates correctly and does not free custody/occupancy claim', () => {
  const m = new LockerSimulator(); m.dispatch(command());
  assert.throws(() => m.close('cmd-1','1:2'), /CORRELATION_MISMATCH/);
  m.close('cmd-1','1:1'); assert.equal(m.close('cmd-1','1:1').replay, true);
  assert.equal(m.state.events.length, 3);
  assert.throws(() => m.dispatch(command({command_id:'cmd-2'})), /DOOR_BUSY/);
});
