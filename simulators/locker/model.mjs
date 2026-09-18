import { randomUUID, createHash } from 'node:crypto';

// Synthetic normalized evidence only. This does not implement a serial board protocol.
export class LockerSimulator {
  constructor(state) {
    this.state = state ?? {
      generation: 1, sequence: 0,
      doors: { '1:1': { owner: 'DELIVERY', status: 'CLOSED', active: null },
        '1:2': { owner: 'LEGACY', status: 'CLOSED', active: null },
        '1:3': { owner: 'FROZEN', status: 'CLOSED', active: null } },
      commands: {}, events: [],
    };
  }
  require(condition, code) { if (!condition) throw new Error(code); }
  event(command, type) {
    const event = { event_id: randomUUID(), command_id: command.command_id,
      session_id: command.session_id, address: command.address,
      ownership_generation: this.state.generation, sequence: ++this.state.sequence,
      event_type: type, observed_at: new Date().toISOString(), synthetic: true };
    this.state.events.push(event);
    return event;
  }
  dispatch(input, now = Date.now()) {
    this.require(input && typeof input === 'object', 'INVALID_COMMAND');
    const { command_id, session_id, address, ownership_generation, expires_at, scenario = 'normal' } = input;
    for (const v of [command_id, session_id, address, expires_at]) this.require(typeof v === 'string' && v.length > 0 && v.length <= 100, 'INVALID_COMMAND');
    this.require(['normal', 'timeout', 'wrong_address', 'crash_after_dispatch'].includes(scenario), 'INVALID_SCENARIO');
    const payload = { command_id, session_id, address, ownership_generation, expires_at, scenario };
    const hash = createHash('sha256').update(JSON.stringify(payload)).digest('hex');
    const previous = this.state.commands[command_id];
    if (previous) {
      this.require(previous.hash === hash, 'IDEMPOTENCY_CONFLICT');
      return { ...previous, replay: true };
    }
    const door = this.state.doors[address];
    this.require(door && door.owner === 'DELIVERY', 'OWNERSHIP_DENIED');
    this.require(ownership_generation === this.state.generation, 'STALE_GENERATION');
    this.require(Number.isFinite(Date.parse(expires_at)) && Date.parse(expires_at) > now, 'EXPIRED');
    this.require(door.status === 'CLOSED' && door.active === null, 'DOOR_BUSY');
    const command = { ...payload, hash, status: 'DISPATCH_RECORDED', open_attempts: 1 };
    this.state.commands[command_id] = command;
    door.active = command_id;
    this.event(command, 'DISPATCH_RECORDED');
    if (scenario !== 'normal') {
      command.status = 'UNKNOWN'; door.status = 'UNKNOWN';
      this.event(command, 'UNKNOWN');
    } else {
      command.status = 'OPEN_OBSERVED'; door.status = 'OPEN';
      this.event(command, 'OPEN_OBSERVED');
    }
    return { ...command, replay: false };
  }
  close(commandId, address) {
    const command = this.state.commands[commandId];
    this.require(command && command.address === address, 'CORRELATION_MISMATCH');
    if (command.status === 'CLOSE_OBSERVED') return { ...command, replay: true };
    this.require(command.status === 'OPEN_OBSERVED', 'RECONCILIATION_REQUIRED');
    const door = this.state.doors[address];
    door.status = 'CLOSED'; command.status = 'CLOSE_OBSERVED';
    // Closing is physical evidence, not custody confirmation; retain the active claim.
    this.event(command, 'CLOSE_OBSERVED');
    return { ...command, replay: false };
  }
}
