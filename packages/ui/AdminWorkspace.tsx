import { useEffect, useState } from 'react';
import type { components } from '../contracts/generated/api';
import { api } from './api';
import { Shipping } from './Shipping';
import './hub-receiving.css';

type Profile = components['schemas']['Profile'];
type PendingDriver = { driver_id: string; name: string; engagement_type: string; applied_at: string };
type PickupRoutes = {
  origins: { origin_location_id: string; code: string; name: string; status: string; hub_id: string | null; hub_name: string | null; latitude: number | null; longitude: number | null; version: number }[];
  hubs: { hub_id: string; code: string; name: string }[];
};

export function AdminWorkspace({ profile, onLogout }: { profile: Profile; onLogout: () => void }) {
  const [page, setPage] = useState<'shipments' | 'drivers' | 'routes'>('shipments');
  const [drivers, setDrivers] = useState<PendingDriver[]>([]);
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const [routes, setRoutes] = useState<PickupRoutes>({ origins: [], hubs: [] });
  const [routeEdits, setRouteEdits] = useState<Record<string, { hub_id: string; latitude: string; longitude: string }>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  async function loadDrivers() {
    const result = await api<{ items: PendingDriver[] }>('/admin/drivers/pending');
    setDrivers(result.items);
  }
  async function loadRoutes() {
    const result = await api<PickupRoutes>('/admin/pickup-routes');
    setRoutes(result);
    setRouteEdits(Object.fromEntries(result.origins.map(origin => [origin.origin_location_id, {
      hub_id: origin.hub_id || (result.hubs.length === 1 ? result.hubs[0].hub_id : ''),
      latitude: origin.latitude?.toString() || '', longitude: origin.longitude?.toString() || '',
    }])));
  }
  useEffect(() => {
    if (page === 'drivers') void loadDrivers().catch(e => setError(e instanceof Error ? e.message : 'Unable to load drivers.'));
    if (page === 'routes') void loadRoutes().catch(e => setError(e instanceof Error ? e.message : 'Unable to load pickup routes.'));
  }, [page]);
  async function saveRoute(origin: PickupRoutes['origins'][number]) {
    const edit = routeEdits[origin.origin_location_id];
    if (!edit?.hub_id || busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      await api('/admin/pickup-routes/assign', {
        origin_location_id: origin.origin_location_id, hub_id: edit.hub_id, expected_version: origin.version,
        ...(edit.latitude && edit.longitude ? { latitude: Number(edit.latitude), longitude: Number(edit.longitude) } : {}),
      }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      await loadRoutes();
      setNotice(`Pickup route saved for ${origin.name}. Existing assigned runs keep their hub.`);
    } catch (e) { setError(e instanceof Error ? e.message : 'Unable to save pickup route.'); }
    finally { setBusy(false); }
  }
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
    <nav className="hub-tabs" aria-label="Admin operations"><button aria-pressed={page === 'shipments'} onClick={() => setPage('shipments')}>Shipments</button><button aria-pressed={page === 'drivers'} onClick={() => setPage('drivers')}>Driver approvals</button><button aria-pressed={page === 'routes'} onClick={() => setPage('routes')}>Pickup routes</button></nav>
    {error && <p className="error" role="alert">{error}</p>}{notice && <p className="notice" role="status">{notice}</p>}
    {page === 'shipments' ? <Shipping profile={profile} audience="operations" embedded onAccount={() => {}} onLogout={onLogout} /> : page === 'routes' ? <section className="hub-page hub-card">
      <h1>Origin pickup routes</h1><p>Choose the hub that receives pickups from each origin. Confirm the locker coordinates before enabling nearby driver offers.</p>
      <button type="button" disabled={busy} onClick={() => void loadRoutes().catch(e => setError(e instanceof Error ? e.message : 'Unable to load pickup routes.'))}>Refresh routes</button>
      {routes.hubs.length === 0 ? <p>No active hubs are available.</p> : <ul>{routes.origins.map(origin => {
        const edit = routeEdits[origin.origin_location_id];
        return <li key={origin.origin_location_id}>
          <strong>{origin.name}</strong> · {origin.code} · {origin.status} · {origin.hub_name || 'No explicit route'}
          <label>Destination hub <select aria-label={`${origin.code} destination hub`} value={edit?.hub_id || ''} disabled={busy || origin.status !== 'ACTIVE'} onChange={e => setRouteEdits(current => ({ ...current, [origin.origin_location_id]: { ...current[origin.origin_location_id], hub_id: e.target.value } }))}>
            <option value="">Choose hub</option>{routes.hubs.map(hub => <option key={hub.hub_id} value={hub.hub_id}>{hub.name}</option>)}
          </select></label>
          <label>Latitude <input aria-label={`${origin.code} latitude`} type="number" step="any" value={edit?.latitude || ''} disabled={busy || origin.status !== 'ACTIVE'} onChange={e => setRouteEdits(current => ({ ...current, [origin.origin_location_id]: { ...current[origin.origin_location_id], latitude: e.target.value } }))} /></label>
          <label>Longitude <input aria-label={`${origin.code} longitude`} type="number" step="any" value={edit?.longitude || ''} disabled={busy || origin.status !== 'ACTIVE'} onChange={e => setRouteEdits(current => ({ ...current, [origin.origin_location_id]: { ...current[origin.origin_location_id], longitude: e.target.value } }))} /></label>
          <button type="button" disabled={busy || !edit?.hub_id || Boolean(edit?.latitude) !== Boolean(edit?.longitude) || origin.status !== 'ACTIVE'} onClick={() => void saveRoute(origin)}>Save route</button>
        </li>;
      })}</ul>}
    </section> : <section className="hub-page hub-card">
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
