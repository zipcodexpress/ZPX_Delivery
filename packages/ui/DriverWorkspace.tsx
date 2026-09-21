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
type DriverProfile = {
  driver_id: string; user_id: string; name: string; status: string;
  engagement_type: string; email: string | null; phone: string | null;
  applied_at: string; approved_at: string | null;
  license: { number: string; state: string; expiry: string } | null;
  date_of_birth: string | null;
  address: { line1: string; line2: string | null; city: string; state: string; postal_code: string; country_code: string } | null;
  emergency_contact: { name: string; phone: string } | null;
  vehicle_details: { make: string; model: string; year: number | null; color: string } | null;
  insurance_reference: string | null;
  notes: string | null;
  assigned_vehicle: { code: string; max_weight_g: number; max_packages: number } | null;
};
type Wallet = {
  total_earned_cents: number; total_settled_cents: number; pending_cents: number;
  currency: string;
  current_shift: { shift_id: string; compensation_cents: number; policy: string; starts_at: string; ends_at: string } | null;
};
type Transaction = {
  transaction_id: string; amount_cents: number; kind: string;
  policy_version: string; run_id: string | null; run_kind: string | null;
  run_date: string | null; created_at: string;
  settled_at: string | null; settlement_reference: string | null;
};
type TransactionList = { items: Transaction[]; next_cursor: string | null };

type Tab = 'runs' | 'profile' | 'wallet' | 'transactions';

const badge = (state: string) => {
  const cls = state === 'LOADED' ? 'badge-ok' : state === 'EXPECTED' ? 'badge-pending' : state === 'ACTIVE' ? 'badge-ok' : state === 'PENDING' ? 'badge-pending' : 'badge-muted';
  return <span className={`badge ${cls}`}>{state.toLowerCase().replace('_', ' ')}</span>;
};
const money = (cents: number) => '$' + (cents / 100).toFixed(2);

function ProfileForm({ profile: p, busy, onError, onNotice, onSaved, csrfToken }: {
  profile: DriverProfile; busy: boolean;
  onError: (msg: string) => void; onNotice: (msg: string) => void;
  onSaved: (p: DriverProfile) => void; csrfToken: string;
}) {
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({
    name: p.name, email: p.email || '', phone: p.phone || '',
    license_number: p.license?.number || '', license_state: p.license?.state || '', license_expiry: p.license?.expiry?.slice(0, 10) || '',
    date_of_birth: p.date_of_birth?.slice(0, 10) || '',
    address_line1: p.address?.line1 || '', address_line2: p.address?.line2 || '', address_city: p.address?.city || '',
    address_state: p.address?.state || '', address_postal_code: p.address?.postal_code || '', address_country_code: p.address?.country_code || 'US',
    emergency_contact_name: p.emergency_contact?.name || '', emergency_contact_phone: p.emergency_contact?.phone || '',
    vehicle_make: p.vehicle_details?.make || '', vehicle_model: p.vehicle_details?.model || '',
    vehicle_year: p.vehicle_details?.year?.toString() || '', vehicle_color: p.vehicle_details?.color || '',
    insurance_reference: p.insurance_reference || '', notes: p.notes || '',
  });

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true); onError(''); onNotice('');
    try {
      const body: Record<string, string> = {};
      for (const [k, v] of Object.entries(form)) { if (v !== '') body[k] = v; }
      const result = await api<DriverProfile>('/driver/profile/update', body, {
        'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': csrfToken,
      });
      onSaved(result);
      setEditing(false);
      onNotice('Profile updated successfully.');
    } catch (e) { onError(e instanceof Error ? e.message : 'Update failed.'); }
    finally { setSaving(false); }
  }

  const field = (label: string, key: keyof typeof form, type = 'text', placeholder = '') => (
    <label className="profile-input"><span>{label}</span>
      <input type={type} value={form[key]} placeholder={placeholder} disabled={!editing || saving}
        onChange={e => setForm(prev => ({ ...prev, [key]: e.target.value }))} />
    </label>
  );

  return <div className="profile-card">
    <div className="profile-header">
      <div><p className="eyebrow">DRIVER PROFILE</p><h2>{p.name}</h2></div>
      <div className="profile-actions">
        {badge(p.status)}
        {!editing ? <button onClick={() => setEditing(true)}>Edit Profile</button>
          : <><button className="secondary" onClick={() => setEditing(false)}>Cancel</button></>}
      </div>
    </div>
    <form onSubmit={save}>
      <fieldset><legend>Personal Information</legend>
        <div className="profile-form-grid">
          {field('Full Name', 'name')}
          {field('Email', 'email', 'email')}
          {field('Phone', 'phone', 'tel', '+12025550100')}
          {field('Date of Birth', 'date_of_birth', 'date')}
        </div>
      </fieldset>
      <fieldset><legend>Driver's License</legend>
        <div className="profile-form-grid">
          {field('License Number', 'license_number', 'text', 'D1234567')}
          {field('State', 'license_state', 'text', 'TX')}
          {field('Expiry Date', 'license_expiry', 'date')}
        </div>
      </fieldset>
      <fieldset><legend>Address</legend>
        <div className="profile-form-grid full-first">
          {field('Street Address', 'address_line1')}
          {field('Apt / Suite', 'address_line2')}
          {field('City', 'address_city')}
          {field('State', 'address_state', 'text', 'TX')}
          {field('ZIP Code', 'address_postal_code', 'text', '73301')}
          {field('Country', 'address_country_code', 'text', 'US')}
        </div>
      </fieldset>
      <fieldset><legend>Emergency Contact</legend>
        <div className="profile-form-grid">
          {field('Contact Name', 'emergency_contact_name')}
          {field('Contact Phone', 'emergency_contact_phone', 'tel')}
        </div>
      </fieldset>
      <fieldset><legend>Vehicle Details</legend>
        <div className="profile-form-grid">
          {field('Make', 'vehicle_make', 'text', 'Ford')}
          {field('Model', 'vehicle_model', 'text', 'Transit')}
          {field('Year', 'vehicle_year', 'number', '2024')}
          {field('Color', 'vehicle_color', 'text', 'White')}
        </div>
      </fieldset>
      <fieldset><legend>Insurance & Notes</legend>
        <div className="profile-form-grid">
          {field('Insurance Reference', 'insurance_reference')}
        </div>
        <label className="profile-input full-width"><span>Notes</span>
          <textarea value={form.notes} disabled={!editing || saving} rows={3}
            onChange={e => setForm(prev => ({ ...prev, notes: e.target.value }))} />
        </label>
      </fieldset>
      {editing && <div className="profile-save-bar"><button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save Changes'}</button></div>}
    </form>
    {p.assigned_vehicle && <div className="profile-shift-info"><h3>Assigned Vehicle</h3><p>{p.assigned_vehicle.code} · Max {(p.assigned_vehicle.max_weight_g / 1000).toFixed(0)}kg · {p.assigned_vehicle.max_packages} packages</p></div>}
  </div>;
}

export function DriverWorkspace({ profile, onLogout }: { profile: components['schemas']['Profile']; onLogout: () => void }) {
  const [tab, setTab] = useState<Tab>('runs');
  const [runs, setRuns] = useState<Run[]>([]);
  const [selected, setSelected] = useState<RunDetail | null>(null);
  const [driverProfile, setDriverProfile] = useState<DriverProfile | null>(null);
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [scanInput, setScanInput] = useState('');
  const [scanResult, setScanResult] = useState<ScanResult | null>(null);

  const loadRuns = useCallback(async () => {
    setLoading(true); setError('');
    try { setRuns((await api<RunList>('/driver/runs')).items); }
    catch (e) { setError(e instanceof Error ? e.message : 'Failed to load runs.'); }
    finally { setLoading(false); }
  }, []);

  const loadProfile = useCallback(async () => {
    setLoading(true); setError('');
    try { setDriverProfile(await api<DriverProfile>('/driver/profile')); }
    catch (e) { setError(e instanceof Error ? e.message : 'Failed to load profile.'); }
    finally { setLoading(false); }
  }, []);

  const loadWallet = useCallback(async () => {
    setLoading(true); setError('');
    try { setWallet(await api<Wallet>('/driver/wallet')); }
    catch (e) { setError(e instanceof Error ? e.message : 'Failed to load wallet.'); }
    finally { setLoading(false); }
  }, []);

  const loadTransactions = useCallback(async () => {
    setLoading(true); setError('');
    try { setTransactions((await api<TransactionList>('/driver/transactions')).items); }
    catch (e) { setError(e instanceof Error ? e.message : 'Failed to load transactions.'); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => {
    if (tab === 'runs') void loadRuns();
    else if (tab === 'profile') void loadProfile();
    else if (tab === 'wallet') void loadWallet();
    else if (tab === 'transactions') void loadTransactions();
  }, [tab, loadRuns, loadProfile, loadWallet, loadTransactions]);

  const openRun = useCallback(async (runId: string) => {
    setLoading(true); setError(''); setScanResult(null); setScanInput('');
    try { setSelected(await api<RunDetail>('/runs/' + runId)); }
    catch (e) { setError(e instanceof Error ? e.message : 'Failed to load run.'); }
    finally { setLoading(false); }
  }, []);

  async function acknowledge() {
    if (!selected || busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      await api('/runs/' + selected.id + '/acknowledgments', {}, {
        'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '',
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
    try {
      const result = await api<ScanResult>('/runs/' + selected.id + '/scans', {
        label_token: scanInput.trim(),
      }, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile.csrf_token || '' });
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

    <nav className="driver-tabs">
      {([['runs', 'Runs'], ['profile', 'Profile'], ['wallet', 'Wallet'], ['transactions', 'Transactions']] as [Tab, string][]).map(([key, label]) => (
        <button key={key} className={tab === key ? 'active' : ''} onClick={() => { setTab(key); setSelected(null); setError(''); setNotice(''); }}>
          {label}
        </button>
      ))}
    </nav>

    {error && <p className="error" role="alert">{error}</p>}
    {notice && <p className="notice" role="status">{notice}</p>}

    {tab === 'runs' && <div className="driver-layout">
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
        {!selected ? <div className="empty-state"><h2>Select a run</h2><p>Choose a run from the sidebar to view its manifest and start scanning packages.</p></div> : <>
          <div className="run-header">
            <div>
              <p className="eyebrow">RUN {selected.id}</p>
              <h2>{selected.kind} · {selected.hub}</h2>
              <p className="run-detail-meta">Revision {selected.revision} · State: <strong>{selected.state}</strong>{' · '}Planned: {new Date(selected.planned_start).toLocaleTimeString()} – {new Date(selected.planned_end).toLocaleTimeString()}</p>
            </div>
            <div className="run-progress">
              <span className="progress-count">{selected.manifest.filter(m => m.state === 'LOADED').length} / {selected.manifest.length}</span>
              <span className="progress-label">packages loaded</span>
            </div>
          </div>
          {selected.state === 'PUBLISHED' && <div className="action-bar"><p>Acknowledge to begin scanning.</p><button onClick={() => void acknowledge()} disabled={busy}>{busy ? 'Please wait…' : 'Acknowledge run'}</button></div>}
          {(selected.state === 'ACKNOWLEDGED' || selected.state === 'IN_PROGRESS') && <form className="scan-bar" onSubmit={scanPackage}>
            <label>Scan or enter label token<input value={scanInput} onChange={e => setScanInput(e.target.value)} placeholder="ZPX1:L:..." autoFocus disabled={busy} /></label>
            <button type="submit" disabled={busy || !scanInput.trim()}>{busy ? 'Scanning…' : 'Scan package'}</button>
          </form>}
          {scanResult && <div className="scan-result"><span className={scanResult.result_code === 'ACCEPTED' ? 'scan-ok' : 'scan-fail'}>{scanResult.result_code}</span><span>Package {scanResult.package_id} · v{scanResult.package_version} → {scanResult.state}</span><span>{scanResult.loaded_count}/{scanResult.expected_count} loaded</span></div>}
          <div className="manifest-stops">{selected.stops.map(stop => {
            const items = selected.manifest.filter(m => m.stop_sequence === stop.sequence);
            return <div key={stop.id} className="stop-group"><h3>Stop {stop.sequence} · {stop.location.name} <small>{stop.location.code}</small></h3>
              <table className="manifest-table"><thead><tr><th>Package</th><th>SI</th><th>Destination</th><th>State</th></tr></thead>
                <tbody>{items.map(item => (<tr key={item.manifest_item_id} className={item.state === 'LOADED' ? 'loaded' : ''}><td><code>{item.public_reference}</code></td><td>{item.si || '—'}</td><td>{item.destination.code}</td><td>{badge(item.state)}</td></tr>))}</tbody></table></div>;
          })}</div>
        </>}
      </section>
    </div>}

    {tab === 'profile' && <section className="driver-main">
      {loading ? <p role="status">Loading…</p> : driverProfile ? <ProfileForm profile={driverProfile} busy={busy} onError={setError} onNotice={setNotice} onSaved={setDriverProfile} csrfToken={profile.csrf_token || ''} /> : <p className="muted">No driver profile found.</p>}
    </section>}

    {tab === 'wallet' && <section className="driver-main">
      {loading ? <p role="status">Loading…</p> : wallet ? <div className="wallet-card">
        <p className="eyebrow">EARNINGS</p>
        <div className="wallet-summary">
          <div className="wallet-stat"><label>Total Earned</label><strong>{money(wallet.total_earned_cents)}</strong></div>
          <div className="wallet-stat"><label>Settled</label><strong>{money(wallet.total_settled_cents)}</strong></div>
          <div className="wallet-stat pending"><label>Pending</label><strong>{money(wallet.pending_cents)}</strong></div>
        </div>
        {wallet.current_shift && <div className="wallet-shift">
          <h3>Current Shift</h3>
          <div className="profile-grid">
            <div className="profile-field"><label>Compensation</label><strong>{money(wallet.current_shift.compensation_cents)}</strong></div>
            <div className="profile-field"><label>Policy</label><span>{wallet.current_shift.policy.replace('_', ' ')}</span></div>
            <div className="profile-field"><label>Start</label><span>{new Date(wallet.current_shift.starts_at).toLocaleString()}</span></div>
            <div className="profile-field"><label>End</label><span>{new Date(wallet.current_shift.ends_at).toLocaleString()}</span></div>
          </div>
        </div>}
        <p className="wallet-note">Compensation is calculated per fixed shift/route rate configured by operations. Payments are recorded after run completion.</p>
      </div> : <p className="muted">Wallet not available.</p>}
    </section>}

    {tab === 'transactions' && <section className="driver-main">
      {loading ? <p role="status">Loading…</p> : transactions.length === 0 ? <div className="empty-state"><h2>No transactions yet</h2><p>Transaction history will appear here after completed runs are compensated.</p></div> : (
        <table className="manifest-table"><thead><tr><th>Date</th><th>Type</th><th>Run</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>{transactions.map(t => (<tr key={t.transaction_id}>
            <td>{new Date(t.created_at).toLocaleDateString()}</td>
            <td>{t.kind.replace('_', ' ')}</td>
            <td>{t.run_id ? `${t.run_kind} #${t.run_id}` : '—'}</td>
            <td><strong>{money(t.amount_cents)}</strong></td>
            <td>{t.settled_at ? <span className="badge badge-ok">settled</span> : <span className="badge badge-pending">pending</span>}</td>
          </tr>))}</tbody></table>
      )}
    </section>}

    <footer>ZipcodeXpress · Austin pilot · No live shipping or physical custody</footer>
  </main>;
}
