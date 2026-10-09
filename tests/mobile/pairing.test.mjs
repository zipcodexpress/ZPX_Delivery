import assert from 'node:assert/strict';
import test from 'node:test';
import { pairingId, allowedWorkflow, canApprovePairing, canConfirmHandoff } from '../../apps/mobile/src/pairing.ts';

test('terminal pairing accepts the exact scene and rejects labels, URLs, malformed UUIDs and injected IDs', () => {
  assert.equal(pairingId(' ZPXPAIR:102:550e8400-e29b-41d4-a716-446655440000 '), '102');
  for (const input of ['ZPXPAIR:0:550e8400-e29b-41d4-a716-446655440000', 'ZPXPAIR:1:------------------------------------', 'https://evil.test/ZPXPAIR:1:550e8400-e29b-41d4-a716-446655440000', 'ZPX:parcel', 'ZPXPAIR:1/approve:550e8400-e29b-41d4-a716-446655440000']) assert.throws(() => pairingId(input));
});
test('customer and carrier pairing screens enforce their workflow roles', () => {
  for (const workflow of ['ORIGIN_DEPOSIT', 'RECIPIENT_PICKUP']) { assert.equal(allowedWorkflow('customer', workflow), true); assert.equal(allowedWorkflow('carrier', workflow), false); }
  for (const workflow of ['INBOUND_PICKUP', 'FINAL_DEPOSIT']) { assert.equal(allowedWorkflow('carrier', workflow), true); assert.equal(allowedWorkflow('customer', workflow), false); }
  assert.equal(allowedWorkflow('customer', 'OPEN_ANY_DOOR'), false);
});
test('expired or consumed scenes cannot be approved and unclosed doors cannot be attested', () => {
  const now = Date.parse('2026-10-02T12:00:00Z');
  assert.equal(canApprovePairing('PENDING', '2026-10-02T12:01:00Z', now), true);
  assert.equal(canApprovePairing('APPROVED', '2026-10-02T12:00:00Z', now), false);
  assert.equal(canApprovePairing('CONSUMED', '2026-10-02T12:01:00Z', now), false);
  assert.equal(canApprovePairing('PENDING', 'invalid', now), false);
  for (const status of ['READY', 'OPEN', 'UNKNOWN', 'CANCELLED', 'CONFIRMED']) assert.equal(canConfirmHandoff(status, false), false);
  assert.equal(canConfirmHandoff('CLOSED', false), true);
  assert.equal(canConfirmHandoff('CLOSED', true), false);
});
