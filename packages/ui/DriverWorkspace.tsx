import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import type { components } from '../contracts/generated/api';
import { api } from './api';
import './driver.css';

type Run = {
  id: string; kind: string; state: string; revision: number;
  hub: string; vehicle: string;
  planned_start: string; planned_end: string; departed_at: string | null;
  expected_count: number; loaded_count: number;
};
type RunList = { items: Run[] };
type ManifestItem = {
  manifest_item_id: string; package_id: string; package_uuid: string;
  public_reference: string; si: string | null;
  state: string; package_state: string;
  custodian_type: string; custodian_ref: string; package_version: number;
  destination: { name: string; code: string };
  stop_sequence: number;
};
type Stop = { id: string; sequence: number; state: string; location: { name: string; code: string; address: string } };
type RunDetail = {
  id: string; kind: string; state: string; revision: number;
  hub: string; planned_start: string; planned_end: string; departed_at: string | null;
  stops: Stop[]; manifest: ManifestItem[];
};
type ScanResult = {
  package_id: string; package_version: number; state: string;
  result_code: string; loaded_count: number; expected_count: number;
};

const badge = (state: string) => {
  const cls = state === 'LOADED' ? 'badge-ok' : state === 'EXPECTED' ? 'badge-pending' : 'badge-muted';
  return <span className={`badge ${cls}`}>{state.toLowerCase().replace('_', ' ')}</span>;
};

export function DriverWorkspace({ profile, onLogout }: { profile: components['schemas']['Profile']; onLogout: () => void }) {
  const [runs, setRuns] = useState<Run[]>([]);
  const [selected, setSelected] = useState<RunDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [scanInput, setScanInput] = useState('');
  const [scanResult, setScanResult] = useState<ScanResult | null>(null);
  const scanKey = useRef('');

  const loadRuns = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const result = await api<RunList>('/driver/runs');
      setRuns(result.items);
    } catch (e) { setError(e instanceof Error ? e.message : 'Failed to load runs.'); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { void loadRuns(); }, [loadRuns]);

  const openRun = useCallback(async (runId: string) => {
    setLoading(true); setError(''); setScanResult(null); setScanInput('');
    try {
      const detail = await api<RunDetail>('/runs/' + runId);
      setSelected(detail);
    } catch (e) { setError(e instanceof Error ? e.message : 'Failed to load run.'); }
    finally { setLoading(false); }
  }, []);

  async function acknowledge() {
    if (!selected || busy) return;
    setBusy(true); setError(''); setNotice('');
    scanKey.current = crypto.randomUUID();
    try {
      await api('/runs/' + selected.id + '/acknowledgments', {}, {
        'Idempotency-Key': scanKey.current,
        'X-CSRF-Token': profile.csrf_token || '',
      });
      setNotice('Run acknowledged. You can start scanning.');
      await openRun(selected.id);
    } catch (e) { setError(e instanceof Error ? e.message : 'Acknowledgment failed.'); }
    finally { setBusy(false); }
  }

  async function scanPackage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected || !scanInput.trim() || busy) return;
    setBusy(true); setError(''); setNotice('');
    const key = crypto.randomUUID();
    try {
      const result = await api<ScanResult>('/runs/' + selected.id + '/scans', {
        label_token: scanInput.trim(),
      }, { 'Idempotency-Key': key, 'X-CSRF-Token': profile.csrf_token || '' });
      setScanResult(result);
      setScanInput('');
      setNotice(`Package scanned. ${result.loaded_count}/${result.expected_count} loaded.`);
      await openRun(selected.id);
    } catch (e) { setError(e instanceof Error ? e.message : 'Scan failed.'); }
    finally { setBusy(false); }
  }

  return <main className="driver-page">
    <header>
      <strong>ZipcodeXpress<span className="brand-dot">.</span></strong>
      <span>DRIVER WORKSPACE</span>
      <button className="secondary small" onClick={onLogout}>Sign out</button>
    </header>

    <div className="driver-layout">
      <aside className="driver-sidebar">
        <h2>Your Runs</h2>
        {loading && !runs.length ? <p role="status">Loading…</p> : runs.length === 0 ? <p className="muted">No assigned runs.</p> : (
          <ul className="run-list">{runs.map(r => (
            <li key={r.id} className={selected?.id === r.id ? 'active' : ''} onClick={() => void openRun(r.id)}>
              <strong>{r.kind} #{r.id}</strong>
              <span className="run-meta">{r.hub} · {r.vehicle}</span>
              <div className="run-counts">
                <span className={r.loaded_count === r.expected_count ? 'count-complete' : ''}>{r.loaded_count}/{r.expected_count}</span>
                <span className="run-state">{r.state.toLowerCase()}</span>
              </div>
            </li>
          ))}</ul>
        )}
      </aside>

      <section className="driver-main">
        {error && <p className="error" role="alert">{error}</p>}
        {notice && <p className="notice" role="status">{notice}</p>}

        {!selected ? <div className="empty-state">
          <h2>Select a run</h2>
          <p>Choose a run from the sidebar to view its manifest and start scanning packages.</p>
        </div> : <>
          <div className="run-header">
            <div>
              <p className="eyebrow">RUN {selected.id}</p>
              <h2>{selected.kind} · {selected.hub}</h2>
              <p className="run-detail-meta">
                Revision {selected.revision} · State: <strong>{selected.state}</strong>
                {' · '}Planned: {new Date(selected.planned_start).toLocaleTimeString()} – {new Date(selected.planned_end).toLocaleTimeString()}
              </p>
            </div>
            <div className="run-progress">
              <span className="progress-count">{selected.manifest.filter(m => m.state === 'LOADED').length} / {selected.manifest.length}</span>
              <span className="progress-label">packages loaded</span>
            </div>
          </div>

          {selected.state === 'PUBLISHED' && <div className="action-bar">
            <p>This run is published but not yet acknowledged. Acknowledge to begin scanning.</p>
            <button onClick={() => void acknowledge()} disabled={busy}>{busy ? 'Please wait…' : 'Acknowledge run'}</button>
          </div>}

          {(selected.state === 'ACKNOWLEDGED' || selected.state === 'IN_PROGRESS') && <form className="scan-bar" onSubmit={scanPackage}>
            <label>Scan or enter label token
              <input value={scanInput} onChange={e => setScanInput(e.target.value)} placeholder="ZPX1:L:..." autoFocus disabled={busy} />
            </label>
            <button type="submit" disabled={busy || !scanInput.trim()}>{busy ? 'Scanning…' : 'Scan package'}</button>
          </form>}

          {scanResult && <div className="scan-result">
            <span className={scanResult.result_code === 'ACCEPTED' ? 'scan-ok' : 'scan-fail'}>{scanResult.result_code}</span>
            <span>Package {scanResult.package_id} · v{scanResult.package_version} → {scanResult.state}</span>
            <span>{scanResult.loaded_count}/{scanResult.expected_count} loaded</span>
          </div>}

          <div className="manifest-stops">
            {selected.stops.map(stop => {
              const items = selected.manifest.filter(m => m.stop_sequence === stop.sequence);
              return <div key={stop.id} className="stop-group">
                <h3>Stop {stop.sequence} · {stop.location.name} <small>{stop.location.code}</small></h3>
                <table className="manifest-table">
                  <thead><tr><th>Package</th><th>SI</th><th>Destination</th><th>State</th></tr></thead>
                  <tbody>{items.map(item => (
                    <tr key={item.manifest_item_id} className={item.state === 'LOADED' ? 'loaded' : ''}>
                      <td><code>{item.public_reference}</code></td>
                      <td>{item.si || '—'}</td>
                      <td>{item.destination.code}</td>
                      <td>{badge(item.state)}</td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>;
            })}
          </div>
        </>}
      </section>
    </div>

    <footer>ZipcodeXpress · Austin pilot · No live shipping or physical custody</footer>
  </main>;
}
