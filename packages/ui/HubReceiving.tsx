import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import type { components } from '../contracts/generated/api';
import { api } from './api';
import './hub-receiving.css';

type ReceivingSession = {
  receiving_session_id: string; hub_id: string; inbound_run_id: string;
  expected_count: number; received_count: number; state: string;
  short_count?: number;
  damaged_count?: number; extra_count?: number;
};
type ScanResult = {
  package_id: string; package_version: number; state: string;
  result_code: string; received_count: number; expected_count: number;
  disposition?: string;
};
type ResolvedLabel = { package_id: string; state: string; version: number; allowed_actions: string[] };
type Discrepancy = { id: string; package_id: string; run_id: string | null; public_reference: string; type: string; status: string; notes: string | null; package_state: string; custodian_type: string; reported_at: string; resolution_code: string | null };

export function HubReceiving({ profile, onLogout, embedded = false, show = 'all' }: { profile: components['schemas']['Profile']; onLogout: () => void; embedded?: boolean; show?: 'all' | 'receiving' | 'exceptions' }) {
  const [session, setSession] = useState<ReceivingSession | null>(null);
  const [hubId, setHubId] = useState('');
  const [runId, setRunId] = useState('');
  const [scanInput, setScanInput] = useState('');
  const [scanResult, setScanResult] = useState<ScanResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [disposition, setDisposition] = useState<'RECEIVED' | 'DAMAGED'>('RECEIVED');
  const [notes, setNotes] = useState('');
  const [discrepancies, setDiscrepancies] = useState<Discrepancy[]>([]);

  const loadDiscrepancies = useCallback(async () => {
    try { setDiscrepancies((await api<{ items: Discrepancy[] }>('/hub/receiving-discrepancies')).items); }
    catch (e) { setError(e instanceof Error ? e.message : 'Failed to load discrepancies.'); }
  }, []);

  useEffect(() => { void loadDiscrepancies(); }, [loadDiscrepancies]);

  const openSession = useCallback(async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!hubId.trim() || !runId.trim() || busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const result = await api<ReceivingSession>('/hub/receiving-sessions', {
        hub_id: hubId.trim(), inbound_run_id: runId.trim(),
      }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      setSession(result);
      setNotice(`Session opened. ${result.expected_count} packages expected.`);
    } catch (e) { setError(e instanceof Error ? e.message : 'Failed to open session.'); }
    finally { setBusy(false); }
  }, [hubId, runId, busy, profile.csrf_token]);

  async function scanPackage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!session || !scanInput.trim() || busy) return;
    const labelPayload = scanInput.trim();
    setBusy(true); setError(''); setNotice('');
    try {
      const resolved = await api<ResolvedLabel>('/scans/resolve', { label_payload: labelPayload, action: 'HUB_RECEIVE', run_id: session.inbound_run_id }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      const result = await api<ScanResult>('/hub/receiving-scans', {
        label_payload: labelPayload,
        inbound_run_id: session.inbound_run_id,
        receiving_session_id: session.receiving_session_id,
        expected_package_version: resolved.version,
        disposition, notes: notes.trim(),
      }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      setScanResult(result);
      setScanInput('');
      setNotes(''); setDisposition('RECEIVED');
      setSession(prev => prev ? { ...prev, received_count: result.received_count } : null);
      setNotice(result.result_code === 'EXTRA_RECORDED'
        ? `Extra parcel recorded. Custody was not changed. ${result.received_count}/${result.expected_count} expected parcels received.`
        : `${result.disposition === 'DAMAGED' ? 'Damaged parcel received and flagged.' : 'Received.'} ${result.received_count}/${result.expected_count} scanned.`);
      await loadDiscrepancies();
    } catch (e) { setError(e instanceof Error ? e.message : 'Scan failed.'); }
    finally { setBusy(false); }
  }

  async function closeSession() {
    if (!session || busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const result = await api<ReceivingSession>(`/hub/receiving-sessions/${session.receiving_session_id}/close`, {}, {
        'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '',
      });
      setSession(result);
      setNotice(`Session closed. ${result.received_count}/${result.expected_count} received. ${result.short_count ?? 0} short.`);
      await loadDiscrepancies();
    } catch (e) { setError(e instanceof Error ? e.message : 'Failed to close session.'); }
    finally { setBusy(false); }
  }

  function reset() {
    setSession(null); setScanResult(null); setScanInput(''); setError(''); setNotice('');
  }

  async function resolveDiscrepancy(id: string) {
    setBusy(true); setError('');
    try {
      await api(`/hub/receiving-discrepancies/${id}/resolve`, { resolution_code: 'REVIEWED' }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      setNotice('Discrepancy marked resolved.'); await loadDiscrepancies();
    } catch (e) { setError(e instanceof Error ? e.message : 'Failed to resolve discrepancy.'); }
    finally { setBusy(false); }
  }

  const Root = embedded ? 'div' : 'main';
  return <Root className="hub-page">
    {!embedded && <header>
      <strong>ZipcodeXpress<span className="brand-dot">.</span></strong>
      <span>HUB RECEIVING</span>
      <button className="secondary small" onClick={onLogout}>Sign out</button>
    </header>}

    {error && <p className="error" role="alert">{error}</p>}
    {notice && <p className="notice" role="status">{notice}</p>}

    {show !== 'exceptions' && (!session ? <div className="hub-card">
      <p className="eyebrow">OPEN RECEIVING SESSION</p>
      <h2>Receive inbound shipment</h2>
      <p className="hub-intro">Open a session to start scanning packages from a driver's inbound run. Each scan transfers custody from the driver to the hub independently.</p>
      <form onSubmit={openSession}>
        <label>Hub ID<input value={hubId} onChange={e => setHubId(e.target.value)} placeholder="e.g. 1" disabled={busy} /></label>
        <label>Inbound Run ID<input value={runId} onChange={e => setRunId(e.target.value)} placeholder="e.g. 1" disabled={busy} /></label>
        <button type="submit" disabled={busy || !hubId.trim() || !runId.trim()}>{busy ? 'Opening…' : 'Open Session'}</button>
      </form>
    </div> : <div className="hub-card">
      <div className="session-header">
        <div>
          <p className="eyebrow">SESSION {session.receiving_session_id}</p>
          <h2>Run {session.inbound_run_id}</h2>
          <p className="session-meta">Hub {session.hub_id} · {session.state}</p>
        </div>
        <div className="session-progress">
          <span className="progress-count">{session.received_count} / {session.expected_count}</span>
          <span className="progress-label">packages received</span>
        </div>
      </div>

      {session.state === 'OPEN' && <>
        <form className="scan-bar" onSubmit={scanPackage}>
          <label>Scan or enter label token
            <input value={scanInput} onChange={e => setScanInput(e.target.value)} placeholder="ZPX1:L:..." autoFocus disabled={busy} />
          </label>
          <label>Condition<select value={disposition} onChange={e => setDisposition(e.target.value as 'RECEIVED' | 'DAMAGED')} disabled={busy}><option value="RECEIVED">Received</option><option value="DAMAGED">Damaged</option></select></label>
          {disposition === 'DAMAGED' && <label>Damage notes<input value={notes} onChange={e => setNotes(e.target.value)} maxLength={1000} placeholder="Describe visible damage" disabled={busy} /></label>}
          <button type="submit" disabled={busy || !scanInput.trim()}>{busy ? 'Scanning…' : 'Receive package'}</button>
        </form>

        {scanResult && <div className="scan-result">
          <span className={scanResult.result_code === 'ACCEPTED' ? 'scan-ok' : 'scan-fail'}>{scanResult.result_code}</span>
          <span>Package {scanResult.package_id} → {scanResult.state}</span>
          <span>{scanResult.received_count}/{scanResult.expected_count} received</span>
        </div>}

        <div className="session-actions">
          <button className="close-btn" onClick={closeSession} disabled={busy}>
            {busy ? 'Closing…' : `Close Session${session.received_count < session.expected_count ? ' (' + (session.expected_count - session.received_count) + ' short)' : ''}`}
          </button>
        </div>
      </>}

      {session.state === 'CLOSED' && <div className="session-summary">
        <h3>Session Summary</h3>
        <div className="summary-grid">
          <div className="summary-stat"><label>Expected</label><strong>{session.expected_count}</strong></div>
          <div className="summary-stat"><label>Received</label><strong className="ok">{session.received_count}</strong></div>
          <div className="summary-stat"><label>Short</label><strong className={session.short_count ? 'warn' : ''}>{session.short_count ?? 0}</strong></div>
          <div className="summary-stat"><label>Damaged</label><strong className={session.damaged_count ? 'warn' : ''}>{session.damaged_count ?? 0}</strong></div>
          <div className="summary-stat"><label>Extra</label><strong className={session.extra_count ? 'warn' : ''}>{session.extra_count ?? 0}</strong></div>
        </div>
        <button onClick={reset}>Open New Session</button>
      </div>}
    </div>)}

    {show !== 'receiving' && <section className="hub-card discrepancy-workbench">
      <p className="eyebrow">DISCREPANCY WORKBENCH</p><h2>Receiving issues</h2>
      {discrepancies.length === 0 ? <p className="hub-intro">No receiving discrepancies.</p> : <div className="discrepancy-list">{discrepancies.map(item => <article key={item.id}>
        <div><strong>{item.type} · {item.public_reference}</strong><small>{item.package_state} · custody {item.custodian_type} · {item.status.toLowerCase()}</small>{item.notes && <p>{item.notes}</p>}</div>
        {item.status === 'OPEN' && <button type="button" onClick={() => void resolveDiscrepancy(item.id)} disabled={busy}>Mark reviewed</button>}
      </article>)}</div>}
    </section>}

    {!embedded && <footer>ZipcodeXpress · Austin pilot · Hub receiving workspace</footer>}
  </Root>;
}
