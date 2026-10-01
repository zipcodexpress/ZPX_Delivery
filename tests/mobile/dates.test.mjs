import assert from 'node:assert/strict';
import test from 'node:test';
import { apiDate, displayDate } from '../../apps/mobile/src/dates.ts';

test('PostgreSQL timestamps display on iOS-compatible Date parsing', () => {
  const value = apiDate('2026-09-23 00:46:41.938874+00');
  assert.equal(value?.toISOString(), '2026-09-23T00:46:41.938Z');
  assert.notEqual(displayDate('2026-09-23 00:46:41.938874+00'), 'Invalid Date');
});

test('invalid timestamps do not display Invalid Date or enable timed offers', () => {
  assert.equal(apiDate('not a timestamp'), null);
  assert.equal(displayDate('not a timestamp'), 'Time unavailable');
});
