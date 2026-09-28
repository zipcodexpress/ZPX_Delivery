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
type RecoveryParcel = { package_id: string; reference: string; si: string | null; state: string; can_release: boolean };
type RecoveryRun = { run_id: string; state: string; revision: number; driver: string; planned_end: string; package_count: number; collected_count: number; released_count: number; can_release: boolean; parcels: RecoveryParcel[] };

export function AdminWorkspace({ profile, onLogout }: { profile: Profile; onLogout: () => void }) {
  const [page, setPage] = useState<'shipments' | 'drivers' | 'routes' | 'recovery'>('shipments');
  const [drivers, setDrivers] = useState<PendingDriver[]>([]);
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const [routes, setRoutes] = useState<PickupRoutes>({ origins: [], hubs: [] });
  const [routeEdits, setRouteEdits] = useState<Record<string, { hub_id: string; latitude: string; longitude: string }>>({});
  const [recoveryRuns, setRecoveryRuns] = useState<RecoveryRun[]>([]);
  const [recoveryReasons, setRecoveryReasons] = useState<Record<string, string>>({});
  const [parcelRecovery, setParcelRecovery] = useState<Record<string, { reason: string; evidence_kind: string; evidence_reference: string }>>({});
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
  async function loadRecovery() {
    const result = await api<{ items: RecoveryRun[] }>('/admin/pickup-recovery');
    setRecoveryRuns(result.items);
  }
  useEffect(() => {
    if (page === 'drivers') void loadDrivers().catch(e => setError(e instanceof Error ? e.message : 'Unable to load drivers.'));
    if (page === 'routes') void loadRoutes().catch(e => setError(e instanceof Error ? e.message : 'Unable to load pickup routes.'));
    if (page === 'recovery') void loadRecovery().catch(e => setError(e instanceof Error ? e.message : 'Unable to load pickup runs.'));
  }, [page]);
  async function releaseRun(run: RecoveryRun) {
    const reason = recoveryReasons[run.run_id]?.trim();
    if (!reason || busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      await api(`/admin/pickup-recovery/${run.run_id}/release`, { reason, expected_revision: run.revision }, {
        'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '',
      });
      await loadRecovery();
      setNotice(`Run ${run.run_id} cancelled. Its uncollected parcels remain at the origin locker and are available for a new pickup offer.`);
    } catch (e) { setError(e instanceof Error ? e.message : 'Unable to release pickup run.'); }
    finally { setBusy(false); }
  }
  async function releaseParcel(run: RecoveryRun, parcel: RecoveryParcel) {
    const edit = parcelRecovery[parcel.package_id];
    if (!edit?.reason.trim() || !edit.evidence_reference.trim() || busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      await api(`/admin/pickup-recovery/${run.run_id}/parcels/${parcel.package_id}/release`, {
        reason: edit.reason.trim(), evidence_kind: edit.evidence_kind || 'SITE_INSPECTION',
        evidence_reference: edit.evidence_reference.trim(), expected_revision: run.revision,
      }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
      await loadRecovery();
      setNotice(`${parcel.reference} released for a new pickup offer. Collected parcels remain with the original driver.`);
    } catch (e) { setError(e instanceof Error ? e.message : 'Unable to release parcel.'); }
    finally { setBusy(false); }
  }
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
    <nav className="hub-tabs" aria-label="Admin operations"><button aria-pressed={page === 'shipments'} onClick={() => setPage('shipments')}>Shipments</button><button aria-pressed={page === 'drivers'} onClick={() => setPage('drivers')}>Driver approvals</button><button aria-pressed={page === 'routes'} onClick={() => setPage('routes')}>Pickup routes</button><button aria-pressed={page === 'recovery'} onClick={() => setPage('recovery')}>Pickup recovery</button></nav>
    {error && <p className="error" role="alert">{error}</p>}{notice && <p className="notice" role="status">{notice}</p>}
    {page === 'shipments' ? <Shipping profile={profile} audience="operations" embedded onAccount={() => {}} onLogout={onLogout} /> : page === 'recovery' ? <section className="hub-page hub-card">
      <h1>Pickup recovery</h1><p>Release a wholly uncollected run, or verify an individual parcel still at its origin locker before removing it from a partly collected run. A driver must request a new offer.</p>
      <button type="button" disabled={busy} onClick={() => void loadRecovery().catch(e => setError(e instanceof Error ? e.message : 'Unable to load pickup runs.'))}>Refresh runs</button>
      {recoveryRuns.length === 0 ? <p>No active pickup runs.</p> : <ul>{recoveryRuns.map(run => <li key={run.run_id}>
        <strong>Run {run.run_id}</strong> · {run.driver} · {run.state} · {run.collected_count} collected / {run.package_count - run.released_count} assigned · Due {new Date(run.planned_end).toLocaleString()}
        {run.can_release ? <><label>Reason for reassignment <input aria-label={`Run ${run.run_id} recovery reason`} value={recoveryReasons[run.run_id] || ''} maxLength={500} onChange={e => setRecoveryReasons(current => ({ ...current, [run.run_id]: e.target.value }))} /></label>
          <button type="button" disabled={busy || (recoveryReasons[run.run_id]?.trim().length || 0) < 3} onClick={() => void releaseRun(run)}>Release for new offer</button></> : run.parcels.length ? <ul>{run.parcels.map(parcel => {
            const edit = parcelRecovery[parcel.package_id] || { reason: '', evidence_kind: 'SITE_INSPECTION', evidence_reference: '' };
            const setEdit = (patch: Partial<typeof edit>) => setParcelRecovery(current => ({ ...current, [parcel.package_id]: { ...edit, ...patch } }));
            return <li key={parcel.package_id}><strong>{parcel.reference}</strong> · {parcel.si || 'No SI'} · {parcel.state}
              {parcel.can_release && <><p>Record a site inspection or locker inventory reference. This is an operator assertion, not device proof.</p>
                <label>Reason <input aria-label={`${parcel.reference} recovery reason`} value={edit.reason} maxLength={500} onChange={e => setEdit({ reason: e.target.value })} /></label>
                <label>Verification method <select aria-label={`${parcel.reference} verification method`} value={edit.evidence_kind} onChange={e => setEdit({ evidence_kind: e.target.value })}><option value="SITE_INSPECTION">Site inspection</option><option value="LOCKER_INVENTORY">Locker inventory</option></select></label>
                <label>Evidence reference <input aria-label={`${parcel.reference} evidence reference`} value={edit.evidence_reference} maxLength={200} onChange={e => setEdit({ evidence_reference: e.target.value })} /></label>
                <button type="button" disabled={busy || edit.reason.trim().length < 3 || edit.evidence_reference.trim().length < 3} onClick={() => void releaseParcel(run, parcel)}>Release this parcel</button></>}
            </li>;
          })}</ul> : <p>Custody or assignment needs reconciliation before this run can be released.</p>}
      </li>)}</ul>}
    </section> : page === 'routes' ? <section className="hub-page hub-card">
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
