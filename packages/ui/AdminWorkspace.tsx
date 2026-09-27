import { useEffect, useState } from 'react';
import type { components } from '../contracts/generated/api';
import { api } from './api';
import { Shipping } from './Shipping';
import './hub-receiving.css';

type Profile = components['schemas']['Profile'];
type PendingDriver = { driver_id: string; name: string; engagement_type: string; applied_at: string };

export function AdminWorkspace({ profile, onLogout }: { profile: Profile; onLogout: () => void }) {
  const [page, setPage] = useState<'shipments' | 'drivers'>('shipments');
  const [drivers, setDrivers] = useState<PendingDriver[]>([]);
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  async function loadDrivers() {
    const result = await api<{ items: PendingDriver[] }>('/admin/drivers/pending');
    setDrivers(result.items);
  }
  useEffect(() => { if (page === 'drivers') void loadDrivers().catch(e => setError(e instanceof Error ? e.message : 'Unable to load drivers.')); }, [page]);
  async function decide(driver: PendingDriver, action: 'approve' | 'reject') {
    if (busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      await api(`/admin/drivers/${driver.driver_id}/${action}`, action === 'reject' ? { reason: reasons[driver.driver_id]?.trim() } : {}, {
        'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '',
      });
      await loadDrivers();
      setNotice(`${driver.name} ${action === 'approve' ? 'approved' : 'rejected'}.`);
    } catch (e) { setError(e instanceof Error ? e.message : 'Unable to update driver.'); }
    finally { setBusy(false); }
  }
  return <main className="hub-operations">
    <header><strong>ZipcodeXpress<span className="brand-dot">.</span></strong><span>ADMIN OPERATIONS</span><button className="secondary small" onClick={onLogout}>Sign out</button></header>
    <nav className="hub-tabs" aria-label="Admin operations"><button aria-pressed={page === 'shipments'} onClick={() => setPage('shipments')}>Shipments</button><button aria-pressed={page === 'drivers'} onClick={() => setPage('drivers')}>Driver approvals</button></nav>
    {error && <p className="error" role="alert">{error}</p>}{notice && <p className="notice" role="status">{notice}</p>}
    {page === 'shipments' ? <Shipping profile={profile} audience="operations" embedded onAccount={() => {}} onLogout={onLogout} /> : <section className="hub-page hub-card">
      <h1>Pending drivers</h1><button type="button" disabled={busy} onClick={() => void loadDrivers().catch(e => setError(e instanceof Error ? e.message : 'Unable to load drivers.'))}>Refresh</button>
      {drivers.length === 0 ? <p>No pending applications.</p> : <ul>{drivers.map(driver => <li key={driver.driver_id}>
        <strong>{driver.name}</strong> · {driver.engagement_type} · Applied {new Date(driver.applied_at).toLocaleDateString()}
        <button type="button" disabled={busy} onClick={() => void decide(driver, 'approve')}>Approve</button>
        <label>Rejection reason<input value={reasons[driver.driver_id] || ''} onChange={e => setReasons(current => ({ ...current, [driver.driver_id]: e.target.value }))} maxLength={500} /></label>
        <button type="button" disabled={busy || !reasons[driver.driver_id]?.trim()} onClick={() => void decide(driver, 'reject')}>Reject</button>
      </li>)}</ul>}
    </section>}
  </main>;
}
