import { useEffect, useState, type FormEvent } from 'react';
import type { components, paths } from '../contracts/generated/api';
import { api, RequestError } from './api';
import { HostedPopup } from './HostedPopup';

type Shipment = components['schemas']['Shipment'];
type Payment = components['schemas']['PaymentSession'];
type Options = paths['/packages/{package_id}/origin-size-options']['get']['responses'][200]['content']['application/json'];
type UpgradeQuote = paths['/packages/{package_id}/origin-upgrade-quotes']['post']['responses'][200]['content']['application/json'];
type Session = paths['/development/origin-deposits']['post']['responses'][200]['content']['application/json'];
type Door = paths['/development/origin-deposits/{session_id}/events']['post']['responses'][200]['content']['application/json'];

export function OriginDepositTest({ shipment, labelPayload, csrf, send, onRefresh }: {
  shipment: Shipment; labelPayload?: string; csrf: string;
  send: <T>(path: string, body: unknown, version?: number) => Promise<T>;
  onRefresh: () => Promise<void>;
}) {
  const [options, setOptions] = useState<Options | null>(null);
  const [quote, setQuote] = useState<UpgradeQuote | null>(null);
  const [payment, setPayment] = useState<Payment | null>(null);
  const [session, setSession] = useState<Session | null>(null);
  const [door, setDoor] = useState<Door | null>(null);
  const [token, setToken] = useState(labelPayload || '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  useEffect(() => { if (labelPayload) setToken(labelPayload); }, [labelPayload]);
  useEffect(() => {
    let active = true;
    void api<Options>('/packages/' + shipment.package_id + '/origin-size-options')
      .then(result => { if (active) setOptions(result); })
      .catch(e => { if (active) setError(e instanceof Error ? e.message : 'Size options are unavailable.'); });
    void api<Payment>('/shipments/' + shipment.shipment_id + '/pending-payment')
      .then(result => { if (active) setPayment(result); })
      .catch(e => { if (active && !(e instanceof RequestError && e.status === 404)) setError(e instanceof Error ? e.message : 'Checkout is unavailable.'); });
    return () => { active = false; };
  }, [shipment.package_id, shipment.shipment_id, shipment.version]);
  async function run(action: () => Promise<void>) {
    setBusy(true); setError(''); setNotice('');
    try { await action(); } catch (e) { setError(e instanceof Error ? e.message : 'Please try again.'); }
    finally { setBusy(false); }
  }
  const upgrades = options?.options.filter(option => option.size_class !== options.current_size_class) || [];
  function requestUpgrade(e: FormEvent<HTMLFormElement>) {
    e.preventDefault(); const form = new FormData(e.currentTarget);
    void run(async () => {
      const body = { target_size_class: String(form.get('target_size_class')),
        width_mm: Number(form.get('width_mm')), height_mm: Number(form.get('height_mm')),
        depth_mm: Number(form.get('depth_mm')), weight_g: Number(form.get('weight_g')) };
      setQuote(await send<UpgradeQuote>('/packages/' + shipment.package_id + '/origin-upgrade-quotes', body, shipment.version));
      setNotice('Review the additional test charge before checkout. No compartment has opened.');
    });
  }
  return <div className="quote-card" aria-label="Origin deposit development simulation">
    <span>ORIGIN LOCKER · DEVELOPMENT SIMULATION</span>
    <h3>Try the origin deposit flow</h3>
    <p>This uses a virtual compartment. No real locker opens and no physical handoff is verified.</p>
    {error && <p className="error" role="alert">{error}</p>}{notice && <p className="notice" role="status">{notice}</p>}
    {options && !session && <>
      <p>Paid size: <strong>{options.current_size_class}</strong>. An upgrade uses your original paid rate card, {options.policy_version}.</p>
      {upgrades.length > 0 && !payment && <form onSubmit={requestUpgrade}>
        <label>Need a larger compartment?<select name="target_size_class" required defaultValue=""><option value="" disabled>Choose larger size</option>{upgrades.map(option => <option key={option.size_class} value={option.size_class}>{option.size_class} · +${(option.additional_amount_cents / 100).toFixed(2)}</option>)}</select></label>
        <div className="measure-grid">{[['width_mm', 'Width (mm)'], ['height_mm', 'Height (mm)'], ['depth_mm', 'Depth (mm)'], ['weight_g', 'Weight (g)']].map(([name, title]) => <label key={name}>{title}<input name={name} type="number" min="1" step="1" required inputMode="numeric" defaultValue={shipment.package[name as keyof Shipment['package']] as number} /></label>)}</div>
        <button type="submit" disabled={busy}>Get size-upgrade quote</button>
      </form>}
      {quote && !payment && <div><p>Upgrade to {quote.target_size_class}: <strong>+${(quote.additional_amount_cents / 100).toFixed(2)}</strong>. Expires {new Date(quote.expires_at).toLocaleString()}.</p><button className="primary" disabled={busy || Date.parse(quote.expires_at) <= Date.now()} onClick={() => void run(async () => { setPayment(await send<Payment>('/packages/' + shipment.package_id + '/origin-upgrade-payment', { quote_id: quote.quote_id }, shipment.version)); })}>Continue to test checkout</button></div>}
      {payment?.provider === 'LOCAL_TEST' && <div><p>Local test checkout. No card is charged.</p><div className="button-row">{(['SUCCEEDED', 'FAILED'] as const).map(outcome => <button key={outcome} disabled={busy} onClick={() => void run(async () => {
        const result = await send<Payment>('/development/payments/' + payment.payment_id + '/confirm', { outcome });
        setPayment(null); setQuote(null); await onRefresh();
        setNotice(result.status === 'PAID' ? 'Test size difference confirmed. The parcel remains with you until simulated deposit.' : 'Test adjustment failed. No larger compartment was opened.');
      })}>{outcome === 'SUCCEEDED' ? 'Simulate payment success' : 'Simulate payment failure'}</button>)}</div></div>}
      {payment?.provider === 'AUTHORIZE_NET_SANDBOX' && <div><p>Authorize.net sandbox. No real charge.</p>{payment.checkout_token ? <HostedPopup payment={payment} csrf={csrf} onPaid={async () => { await onRefresh(); setNotice('Sandbox upgrade payment verified.'); }} /> : <button disabled={busy} onClick={() => void run(async () => { setPayment(await send<Payment>('/payments/' + payment.payment_id + '/hosted-session', {})); })}>Prepare test checkout</button>}</div>}
      {!payment && <form onSubmit={e => { e.preventDefault(); void run(async () => {
        setSession(await send<Session>('/development/origin-deposits', { package_id: shipment.package_id, location_id: shipment.origin_location_id, label_payload: token.trim(), expected_package_version: shipment.package_version }));
        setNotice('Virtual compartment claimed. Simulate the door observations to continue.');
      }); }}><label>Scan or paste the primary test label<input value={token} onChange={e => setToken(e.target.value)} required maxLength={500} placeholder="ZPX1:L:…" /></label><button className="primary" disabled={busy || !token.trim()}>Pair with virtual origin locker</button></form>}
    </>}
    {session && <div><p>Virtual compartment: <strong>{session.compartment_code}</strong>. Session {session.session_id}. No physical door has opened.</p>
      {!door && <button disabled={busy} onClick={() => void run(async () => { setDoor(await send<Door>('/development/origin-deposits/' + session.session_id + '/events', { event: 'OPEN_OBSERVED' })); })}>Simulate door open</button>}
      {door?.status === 'OPEN' && <button disabled={busy} onClick={() => void run(async () => { setDoor(await send<Door>('/development/origin-deposits/' + session.session_id + '/events', { event: 'CLOSE_OBSERVED' })); })}>Simulate door close</button>}
      {door?.status === 'CLOSED' && <button className="primary" disabled={busy} onClick={() => void run(async () => {
        await send('/development/origin-deposits/' + session.session_id + '/confirm', { placed: true });
        await onRefresh(); setNotice('Simulated origin custody recorded and pickup demand opened. No physical deposit occurred.');
      })}>Confirm virtual placement</button>}
    </div>}
  </div>;
}
