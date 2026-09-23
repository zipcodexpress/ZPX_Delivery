import { useCallback, useEffect, useState, type FormEvent } from 'react';
import type { components } from '../contracts/generated/api';
import { api } from './api';
import { HubReceiving } from './HubReceiving';
import { Shipping } from './Shipping';
import './hub-receiving.css';

type Profile = components['schemas']['Profile'];
type Tab = 'receiving' | 'staging' | 'dispatch' | 'exceptions' | 'history';
type Slot = { slot_id: string; code: string; status: string; destination: string; destination_name: string; staged_count: number };
type DispatchCall = { dispatch_call_id: string; slot_id: string | null; slot_code: string | null; destination: string; destination_name: string; package_count: number; loaded_count: number; remaining_count: number; status: string; driver_name: string | null; run_id: string | null; called_at: string; expires_at: string; expected_pickup_at: string | null; actual_pickup_at: string | null };
type Resolved = { package_id: string; package_state: string; package_version: number };
type Staged = { package_id: string; package_version: number; state: string; slot_code: string; destination_location_id: string };

const title = (value: string) => value.toLowerCase().replaceAll('_', ' ').replace(/^./, c => c.toUpperCase());
const when = (value: string | null) => value ? new Date(value).toLocaleString() : '—';

function StagingDispatch({ profile, view }: { profile: Profile; view: 'staging' | 'dispatch' }) {
  const [slots, setSlots] = useState<Slot[]>([]);
  const [calls, setCalls] = useState<DispatchCall[]>([]);
  const [token, setToken] = useState('');
  const [resolved, setResolved] = useState<Resolved | null>(null);
  const [slotCode, setSlotCode] = useState('');
  const [minutes, setMinutes] = useState(30);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const load = useCallback(async () => {
    const [slotResult, callResult] = await Promise.all([
      api<{ items: Slot[] }>('/hub/slots'), api<{ items: DispatchCall[] }>('/hub/dispatch-calls'),
    ]);
    setSlots(slotResult.items); setCalls(callResult.items);
  }, []);
  useEffect(() => { void load().catch(e => setError(e instanceof Error ? e.message : 'Unable to load hub operations.')); }, [load]);
  async function run(action: () => Promise<void>) {
    setBusy(true); setError(''); setNotice('');
    try { await action(); } catch (e) { setError(e instanceof Error ? e.message : 'Please try again.'); }
    finally { setBusy(false); }
  }
  function resolve(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void run(async () => {
      const result = await api<Resolved>('/scans/resolve?label=' + encodeURIComponent(token.trim()));
      setResolved(result); setNotice(`Package ${result.package_id} resolved. Confirm its destination slot.`);
    });
  }
  function stage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void run(async () => {
      const result = await api<Staged>('/hub/stage-scans', { label_payload: token.trim(), slot_code: slotCode }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      setNotice(`Package ${result.package_id} staged to ${result.slot_code}.`); setToken(''); setResolved(null); setSlotCode(''); await load();
    });
  }
  function dispatch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    void run(async () => {
      await api('/hub/dispatch-calls', { slot_id: form.get('slot_id'), minutes_to_pickup: minutes }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      setNotice('Dispatch call created for available drivers.'); await load();
    });
  }
  return <div className="hub-page embedded-hub">
    {error && <p className="error" role="alert">{error}</p>}{notice && <p className="notice" role="status">{notice}</p>}
    {view === 'staging' ? <>
      <section className="hub-card"><p className="eyebrow">DESTINATION STAGING</p><h2>Resolve, verify, and stage</h2><p className="hub-intro">Resolve the label first, then select the slot matching the package destination. The server rejects a mismatched destination.</p>
        {!resolved ? <form className="scan-bar" onSubmit={resolve}><label>Label token<input value={token} onChange={e => setToken(e.target.value)} required placeholder="Scan or paste label token" /></label><button disabled={busy || !token.trim()}>Resolve package</button></form>
          : <form onSubmit={stage}><div className="scan-result"><span className="scan-ok">RESOLVED</span><span>Package {resolved.package_id}</span><span>{title(resolved.package_state)} · version {resolved.package_version}</span></div><label>Destination slot<select value={slotCode} onChange={e => setSlotCode(e.target.value)} required><option value="">Choose the verified destination slot</option>{slots.map(slot => <option key={slot.slot_id} value={slot.code}>{slot.code} · {slot.destination_name} · {slot.staged_count} ready</option>)}</select></label><div className="hub-actions"><button type="submit" disabled={busy || !slotCode}>Confirm stage</button><button type="button" onClick={() => { setResolved(null); setSlotCode(''); }} disabled={busy}>Cancel</button></div></form>}
      </section>
      <section className="hub-card hub-table-card"><h2>Staging slots</h2><div className="hub-table"><div className="hub-table-head"><span>Slot</span><span>Destination</span><span>Ready</span><span>Status</span></div>{slots.map(slot => <div key={slot.slot_id}><strong>{slot.code}</strong><span>{slot.destination_name}</span><span>{slot.staged_count}</span><span>{title(slot.status)}</span></div>)}</div></section>
    </> : <>
      <section className="hub-card"><p className="eyebrow">DRIVER DISPATCH</p><h2>Create dispatch</h2><form onSubmit={dispatch}><label>Ready staging slot<select name="slot_id" required><option value="">Choose a slot</option>{slots.filter(slot => slot.staged_count > 0 && slot.status !== 'DISPATCHED').map(slot => <option key={slot.slot_id} value={slot.slot_id}>{slot.code} · {slot.destination_name} · {slot.staged_count} packages</option>)}</select></label><label>Driver response window (minutes)<input type="number" min={5} max={120} value={minutes} onChange={e => setMinutes(Number(e.target.value))} /></label><button type="submit" disabled={busy}>Create dispatch call</button></form></section>
      <section className="hub-card hub-table-card"><div className="workbench-heading"><div><p className="eyebrow">LIVE WORKBENCH</p><h2>Dispatch state</h2></div><button className="small" onClick={() => void run(load)} disabled={busy}>Refresh</button></div>{calls.length === 0 ? <p className="hub-intro">No dispatch calls for this hub.</p> : <div className="dispatch-list">{calls.map(call => <article key={call.dispatch_call_id}><div><strong>{call.destination_name}</strong><small>{call.slot_code || 'No slot'} · Call {call.dispatch_call_id}{call.run_id && ` · Run ${call.run_id}`}</small></div><div><span className="status-pill">{title(call.status)}</span><small>{call.driver_name || 'Awaiting driver'}</small></div><div><strong>{call.loaded_count}/{call.package_count}</strong><small>{call.remaining_count} remaining</small></div><div><small>Pickup {when(call.expected_pickup_at)}</small><small>Called {when(call.called_at)}</small></div></article>)}</div>}</section>
    </>}
  </div>;
}

export function HubOperations({ profile, onLogout }: { profile: Profile; onLogout: () => void }) {
  const [tab, setTab] = useState<Tab>('receiving');
  return <main className="hub-operations"><header><strong>ZipcodeXpress<span className="brand-dot">.</span></strong><span>HUB OPERATIONS</span><button className="secondary small" onClick={onLogout}>Sign out</button></header><nav className="hub-tabs" aria-label="Hub operations">{(['receiving', 'staging', 'dispatch', 'exceptions', 'history'] as Tab[]).map(item => <button key={item} aria-pressed={tab === item} onClick={() => setTab(item)}>{title(item)}</button>)}</nav>
    {tab === 'receiving' && <HubReceiving profile={profile} onLogout={onLogout} embedded show="receiving" />}
    {tab === 'exceptions' && <HubReceiving profile={profile} onLogout={onLogout} embedded show="exceptions" />}
    {(tab === 'staging' || tab === 'dispatch') && <StagingDispatch profile={profile} view={tab} />}
    {tab === 'history' && <Shipping profile={profile} audience="operations" embedded onAccount={() => {}} onLogout={onLogout} />}
    <footer>ZipcodeXpress · Austin pilot · Hub operations workspace</footer>
  </main>;
}
