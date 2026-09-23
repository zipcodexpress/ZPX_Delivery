import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import type { components } from '../contracts/generated/api';
import { api } from './api';
import './shipping.css';
import { LockerMap } from './LockerMap';
import { HostedPopup } from './HostedPopup';

type S = components['schemas'];
type View = 'sending' | 'receiving' | 'history' | 'operations';
const label = (value: string) => value.toLowerCase().replaceAll('_', ' ').replace(/^./, c => c.toUpperCase());
const date = (value: string) => new Date(value).toLocaleString();

export function Shipping({ profile, audience, onAccount, onLogout, embedded = false, initialView, initialCreate = false, initialShipmentId = '', initialOrigin = '', initialDestination = '' }: {
  embedded?: boolean; initialView?: View; initialCreate?: boolean; initialShipmentId?: string; initialOrigin?: string; initialDestination?: string;
  profile: S['Profile']; audience: 'customer' | 'operations'; onAccount: () => void; onLogout: () => void;
}) {
  const operations = audience === 'operations';
  const customerAccess = profile.roles.includes('CUSTOMER');
  const [view, setView] = useState<View>(operations ? 'operations' : initialView || 'sending');
  const [list, setList] = useState<S['ShipmentList']>({ items: [], next_cursor: null });
  const [locations, setLocations] = useState<S['Location'][]>([]);
  const [selected, setSelected] = useState<S['Shipment'] | null>(null);
  const [timeline, setTimeline] = useState<S['Tracking'] | null>(null);
  const [quote, setQuote] = useState<S['Quote'] | null>(null);
  const [payment, setPayment] = useState<S['PaymentSession'] | null>(null);
  const [paymentHistory, setPaymentHistory] = useState<S['PaymentHistory'] | null>(null);
  const [shippingLabel, setShippingLabel] = useState<S['Label'] | null>(null);
  const [challenge, setChallenge] = useState<S['ContactChallenge'] | null>(null);
  const [creating, setCreating] = useState(initialCreate);
  const [origin, setOrigin] = useState(initialOrigin);
  const [destination, setDestination] = useState(initialDestination);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [packageQuery, setPackageQuery] = useState('');
  // Keep a command identity across transport retries; a changed payload gets a new identity.
  const commands = useRef(new Map<string, string>());
  const generation = useRef(0);
  const detail = useRef<HTMLElement>(null);
  useEffect(() => { if ((selected || creating) && window.matchMedia('(max-width: 800px)').matches) detail.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, [selected, creating]);
  const prefix = operations ? '/operations/shipments' : '/shipments';
  const reload = useCallback(async (cursor?: string) => {
    const current = generation.current;
    const params = new URLSearchParams(operations ? {} : { view });
    if (cursor) params.set('cursor', cursor);
    const result = await api<S['ShipmentList']>(prefix + '?' + params);
    if (current === generation.current) setList(previous => cursor ? { ...result, items: [...previous.items, ...result.items] } : result);
  }, [operations, prefix, view]);
  useEffect(() => {
    generation.current += 1;
    let active = true; setLoading(true); setSelected(null); setTimeline(null); setQuote(null); setPayment(null); setShippingLabel(null); setCreating(initialCreate); setError('');
    Promise.all([reload(), operations ? Promise.resolve() : api<S['LocationList']>('/locations').then(r => { if (active) setLocations(r.items); })])
      .catch(e => { if (active) setError(e.message); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; generation.current += 1; };
  }, [reload, operations]);
  useEffect(() => { if (!loading && initialShipmentId) { void run(async () => { await open(await api<S['Shipment']>(prefix + '/' + initialShipmentId)); }); } }, [loading, initialShipmentId]);
  async function run(action: () => Promise<void>) {
    setBusy(true); setError(''); setNotice('');
    try { await action(); } catch (e) { setError(e instanceof Error ? e.message : 'Please try again.'); }
    finally { setBusy(false); }
  }
  async function command<T>(path: string, body: unknown, version?: number): Promise<T> {
    const fingerprint = JSON.stringify([path, body, version]);
    const key = commands.current.get(fingerprint) ?? crypto.randomUUID(); commands.current.set(fingerprint, key);
    const result = await api<T>(path, body, { 'Idempotency-Key': key, 'X-CSRF-Token': profile.csrf_token || '', ...(version === undefined ? {} : { 'If-Match': `"${version}"` }) });
    commands.current.delete(fingerprint); return result;
  }
  async function open(shipment: S['Shipment']) {
    const current = await api<S['Shipment']>(prefix + '/' + shipment.shipment_id);
    const tracking = await api<S['Tracking']>(prefix + '/' + shipment.shipment_id + '/tracking');
    setPaymentHistory(operations ? await api<S['PaymentHistory']>('/operations/shipments/' + current.shipment_id + '/payments') : null);
    setSelected(current); setTimeline(tracking); setQuote(null); setShippingLabel(null); setCreating(false);
    setPayment(!operations && current.relationship === 'SENDER' && current.payment_status === 'PENDING' ? await api<S['PaymentSession']>('/shipments/' + current.shipment_id + '/pending-payment') : null);
  }
  function create(e: FormEvent<HTMLFormElement>) {
    e.preventDefault(); const f = new FormData(e.currentTarget);
    void run(async () => {
      const shipment = await command<S['Shipment']>('/shipments', {
        origin_location_id: f.get('origin'), destination_location_id: f.get('destination'), service_level: 'STANDARD',
        recipient: { name: f.get('name'), email: f.get('email'), phone: f.get('phone') },
        package: Object.fromEntries(['width_mm', 'height_mm', 'depth_mm', 'weight_g'].map(k => [k, Number(f.get(k))])),
      });
      await reload(); await open(shipment); setNotice('Draft saved. Your parcel remains with you.');
    });
  }
  function searchPackages(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    void run(async () => {
      const params = new URLSearchParams({ q: packageQuery.trim() });
      const result = await api<S['ShipmentList']>('/operations/packages/search?' + params);
      setList(result);
      if (result.items[0]) { await open(result.items[0]); setNotice('Package found within your current staff assignment.'); }
      else { setSelected(null); setTimeline(null); setPaymentHistory(null); setNotice('No package in your current staff assignment matched that exact identifier.'); }
    });
  }
  return <main className={"shipping-page" + (embedded ? " embedded-shipping" : "")}>
    {!embedded && <header><strong>ZipcodeXpress<span className="brand-dot">.</span></strong><nav aria-label="Account"><span>{profile.name}</span><button onClick={onAccount} disabled={busy}>Account</button><button onClick={onLogout} disabled={busy}>Sign out</button></nav></header>}
    <div className="shipping-heading"><div><p className="eyebrow">{operations ? 'NETWORK OPERATIONS' : 'YOUR DELIVERY SPACE'}</p><h1>{operations ? 'Shipment workspace' : 'Send it. Receive it. All here.'}</h1><p>{operations ? 'Follow shipments within your staff assignments.' : 'One customer account, both sides of every delivery.'}</p></div>{!operations && <button className="primary" disabled={busy || !customerAccess} onClick={() => { setCreating(true); setSelected(null); setError(''); setNotice(''); }}>＋ New shipment</button>}</div>
    <p className="development-banner">Development preview · Create shipments, use sandbox checkout and print test labels. No real charges or physical drop-off.</p>
    {!operations && !customerAccess && <p className="notice">You are signed in with a staff account. Use your customer account to send or claim a shipment.</p>}
    <div className="shipping-tabs" aria-label="Shipment views">{(operations ? ['operations'] : ['history', 'sending', 'receiving']).map(v => <button key={v} disabled={busy} aria-pressed={view === v} onClick={() => setView(v as View)}>{v === 'operations' ? 'Shipment queue' : label(v)}</button>)}<button className="refresh" disabled={busy || loading} onClick={() => void run(async () => { await reload(); if (selected) await open(selected); })}>Refresh</button></div>
    {error && <p className="error" role="alert">{error}</p>}{notice && <p className="notice" role="status">{notice}</p>}
    <div className="shipping-grid">
      <section className="shipment-list" aria-label="Shipment list"><h2>{operations ? 'Assigned visibility' : view === 'sending' ? 'Your outgoing shipments' : view === 'history' ? 'Your shipment history' : 'Your incoming shipments'}</h2>
        {operations && <form className="package-search" onSubmit={searchPackages}><label>Find package<input value={packageQuery} onChange={e => setPackageQuery(e.target.value)} required maxLength={100} placeholder="Reference, package UUID, SI, or package ID" /></label><div className="button-row"><button className="primary" disabled={busy || !packageQuery.trim()}>Search</button><button type="button" disabled={busy} onClick={() => void run(async () => { setPackageQuery(''); await reload(); setSelected(null); setTimeline(null); setPaymentHistory(null); })}>Clear</button></div></form>}
        {loading ? <p role="status">Loading shipments…</p> : list.items.length === 0 ? <div className="empty-state"><h3>{view === 'receiving' ? 'Expecting a delivery?' : operations ? 'No shipments in your scope' : 'Your next delivery starts here'}</h3><p>{view === 'receiving' ? 'Add the shipment reference shared by your sender, then verify the claim below.' : operations ? 'Hub staff see parcels at their assigned hub. Administrators see their network or assigned sites.' : 'Choose two lockers and add your recipient and parcel details.'}</p></div> : <ul>{list.items.map(s => <li key={s.shipment_id}><button className="shipment-item" aria-pressed={selected?.shipment_id === s.shipment_id} disabled={busy} onClick={() => void run(() => open(s))}><span className="shipment-ref">{s.public_reference}</span>{s.development_only && <small>LOCAL TEST SHIPMENT</small>}<strong>{s.origin_name} <span aria-hidden="true">→</span> {s.destination_name}</strong><span><span className="status-pill">{label(s.order_status)}</span> <small>{s.development_only && s.payment_status === 'PAID' ? 'Test payment confirmed' : label(s.payment_status)}</small></span><small>{date(s.created_at)}</small></button></li>)}</ul>}
        {list.next_cursor && <button disabled={busy} onClick={() => void run(() => reload(list.next_cursor!))}>Load more</button>}
        {view === 'receiving' && customerAccess && <div className="claim-box"><h3>Add an incoming shipment</h3><p>Your verified email and phone must match the sender’s recipient details. We then ask for a fresh email code.</p><form onSubmit={e => { e.preventDefault(); const ref = String(new FormData(e.currentTarget).get('reference')); void run(async () => { setChallenge(await command<S['ContactChallenge']>('/recipient-claims/challenges', { public_reference: ref })); setNotice('If the reference and contacts match, your email code is queued. Approved test addresses receive email; synthetic accounts use the private development inbox.'); }); }}><label>Shipment reference<input name="reference" required maxLength={64} placeholder="ZPX-ORDER-…" /></label><button disabled={busy}>Request claim code</button></form>
          {challenge && <form onSubmit={e => { e.preventDefault(); const code = String(new FormData(e.currentTarget).get('code')); void run(async () => { const s = await command<S['Shipment']>('/recipient-claims', { challenge_id: challenge.challenge_id, code }); setChallenge(null); await reload(); await open(s); setNotice('Shipment added to Receiving. This does not authorize locker pickup.'); }); }}><label>Six-digit email code<input name="code" required inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} /></label><button disabled={busy}>Add to Receiving</button></form>}
        </div>}
      </section>
      <section ref={detail} className="shipment-detail" aria-label={creating ? 'New shipment' : 'Shipment details'}>
        {creating ? <><p className="eyebrow">NEW SHIPMENT</p><h2>Where is your parcel going?</h2><form onSubmit={create}>
          <fieldset><legend>1. Choose your lockers</legend><LockerMap locations={locations} origin={origin} destination={destination} onSelect={(id, side) => side === 'origin' ? setOrigin(id) : setDestination(id)} /><label>Origin locker<select name="origin" required value={origin} onChange={e => setOrigin(e.target.value)}><option value="" disabled>Select origin</option>{locations.filter(l => l.draft_eligible).map(l => <option key={l.id} value={l.id}>{l.name}{l.development_only ? ' · test site' : ''}</option>)}</select></label><label>Destination locker<select name="destination" required value={destination} onChange={e => setDestination(e.target.value)}><option value="" disabled>Select destination</option>{locations.filter(l => l.draft_eligible).map(l => <option key={l.id} value={l.id}>{l.name}{l.development_only ? ' · test site' : ''}</option>)}</select></label><small>Standard locker-to-locker service. Test sites are not real drop-off locations.</small></fieldset>
          <fieldset><legend>2. Who is receiving?</legend><label>Recipient name<input name="name" required maxLength={160} autoComplete="off" /></label><label>Recipient email<input name="email" type="email" required maxLength={254} autoComplete="off" /></label><label>Recipient phone<input name="phone" type="tel" required pattern="\+[1-9][0-9]{7,14}" placeholder="+12025550101" autoComplete="off" /></label><small>The recipient uses a normal customer account. These details remain private.</small></fieldset>
          <fieldset><legend>3. Measure your parcel</legend><div className="measure-grid">{[['width_mm', 'Width (mm)'], ['height_mm', 'Height (mm)'], ['depth_mm', 'Depth (mm)'], ['weight_g', 'Weight (g)']].map(([name, title]) => <label key={name}>{title}<input name={name} type="number" inputMode="numeric" required min={1} max={100000} step={1} /></label>)}</div><small>One parcel per shipment. Its declared orientation must fit at both sites.</small></fieldset><div className="button-row"><button className="primary" disabled={busy || locations.length === 0}>Save shipment draft</button><button type="button" disabled={busy} onClick={() => setCreating(false)}>Close</button></div>
        </form></> : selected ? <><p className="eyebrow">{selected.relationship === 'RECIPIENT' ? 'RECEIVING' : operations ? 'SHIPMENT DETAIL' : 'SENDING'}</p><h2>{selected.origin_name} → {selected.destination_name}</h2><p className="reference-text">{selected.public_reference}</p><dl className="shipment-facts"><div><dt>Order</dt><dd>{label(selected.order_status)}</dd></div><div><dt>Payment</dt><dd>{selected.development_only && selected.payment_status === 'PAID' ? 'Test payment confirmed · no charge' : label(selected.payment_status)}</dd></div><div><dt>Parcel</dt><dd>{selected.package.width_mm} × {selected.package.height_mm} × {selected.package.depth_mm} mm · {selected.package.weight_g} g</dd></div><div><dt>Physical progress</dt><dd>{selected.package_state === 'CREATED' ? 'Still with sender · no deposit recorded' : label(selected.package_state)}</dd></div></dl>
          {!operations && selected.relationship === 'SENDER' && selected.order_status === 'DRAFT' && selected.payment_status !== 'PENDING' && <div className="button-row"><button className="primary" disabled={busy} onClick={() => void run(async () => { setQuote(await command<S['Quote']>('/shipments/' + selected.shipment_id + '/quotes', { service_level: selected.service_level }, selected.version)); setTimeline(await api<S['Tracking']>(prefix + '/' + selected.shipment_id + '/tracking')); })}>Get test quote</button><button disabled={busy} onClick={() => void run(async () => { await command('/shipments/' + selected.shipment_id + '/cancel', { reason: 'Sender cancelled draft' }, selected.version); await reload(); await open(selected); setNotice('Draft cancelled. No payment was taken.'); })}>Cancel draft</button></div>}
          {quote && <div className="quote-card"><span>TEST QUOTE · NOT A LIVE RATE</span><strong>{new Intl.NumberFormat('en-US', { style: 'currency', currency: quote.currency }).format(quote.amount_cents / 100)}</strong><p>Expires {date(quote.expires_at)}. No payment has been taken.</p><button className="primary" disabled={busy || Date.parse(quote.expires_at) <= Date.now()} onClick={() => void run(async () => { await command<S['PaymentSession']>('/shipments/' + selected.shipment_id + '/payment-session', { quote_id: quote.quote_id }, selected.version); await reload(); await open(selected); })}>Continue to test checkout</button></div>}
          {payment && payment.provider !== 'AUTHORIZE_NET_SANDBOX' && <div className="quote-card"><span>LOCAL TEST CHECKOUT · NO CARD REQUIRED</span><h3>Choose a test payment result</h3><p>This simulates a server-confirmed payment. It does not contact Authorize.net or charge you.</p><div className="button-row">{(['SUCCEEDED', 'FAILED'] as const).map(outcome => <button key={outcome} className={outcome === 'SUCCEEDED' ? 'primary' : ''} disabled={busy} onClick={() => void run(async () => { const result = await command<S['PaymentSession']>('/development/payments/' + payment.payment_id + '/confirm', { outcome }); await reload(); await open(selected); setNotice(result.status === 'PAID' ? 'Test payment confirmed. You can now generate a test label.' : 'Test payment failed or the quote expired. Request a new quote to try again.'); })}>{outcome === 'SUCCEEDED' ? 'Simulate successful payment' : 'Simulate failed payment'}</button>)}</div></div>}
          {payment?.provider === 'AUTHORIZE_NET_SANDBOX' && <div className="quote-card"><span>AUTHORIZE.NET SANDBOX · NO REAL CHARGE</span><h3>Secure test checkout</h3><p>Enter sandbox test card details on Authorize.net. Keep this page open. ZPX verifies payment automatically and closes the popup when confirmed.</p>
            {payment.checkout_token && payment.checkout_url === 'https://test.authorize.net/payment/payment' ? <HostedPopup payment={payment} csrf={profile.csrf_token || ''} onPaid={async () => { await reload(); await open(selected); setNotice('Payment verified. Your checkout popup has closed and your label is ready.'); }} /> : payment.checkout_expired ? <p>This checkout link expired. If you paid, check the receipt below. An unresolved payment needs review before another checkout.</p> : <button disabled={busy} onClick={() => void run(async () => { setPayment(await command<S['PaymentSession']>('/payments/' + payment.payment_id + '/hosted-session', {})); })}>Prepare checkout link</button>}
            <form onSubmit={e => { e.preventDefault(); const id = String(new FormData(e.currentTarget).get('transaction_id') || '').trim(); void run(async () => { const result = await command<S['PaymentSession']>('/payments/' + payment.payment_id + '/reconcile', id ? { transaction_id: id } : {}); await reload(); await open(selected); setNotice(result.status === 'PAID' ? 'Sandbox payment verified. Your test label is ready.' : 'No confirmed payment found yet. Wait briefly and check again, or enter the transaction ID from your receipt.'); }); }}><label>Receipt transaction ID (optional)<input name="transaction_id" inputMode="numeric" pattern="[1-9][0-9]{0,19}" maxLength={20} autoComplete="off" /></label><button disabled={busy}>Check payment status</button></form>
          </div>}
          {!operations && selected.relationship === 'SENDER' && selected.order_status === 'READY' && selected.payment_status === 'PAID' && <div className="quote-card"><span>READY FOR A TEST LABEL</span><p>The parcel still remains with you. This label is for local testing only.</p><button className="primary" disabled={busy} onClick={() => void run(async () => { setShippingLabel(await command<S['Label']>('/packages/' + selected.package_id + '/labels', {})); setTimeline(await api<S['Tracking']>(prefix + '/' + selected.shipment_id + '/tracking')); })}>Generate / reprint test label</button>{shippingLabel && <div><p className="reference-text">{shippingLabel.si}</p><a className="label-download" href={shippingLabel.pdf_url} target="_blank" rel="noreferrer">Open printable 4 × 6 label</a><p>Print at actual size / 100%. The label contains no recipient contact details or pickup secret.</p></div>}</div>}
          {operations && timeline?.package && timeline.current_custody && <><h3>Package custody</h3><dl className="shipment-facts"><div><dt>Package UUID</dt><dd className="reference-text">{timeline.package.package_uuid}</dd></div><div><dt>Shipping identifier</dt><dd className="reference-text">{timeline.package.si || 'Not assigned'}</dd></div><div><dt>Current custody</dt><dd>{label(timeline.current_custody.type)} · {timeline.current_custody.location_name || timeline.current_custody.reference}</dd></div><div><dt>Package version</dt><dd>{timeline.package.version}</dd></div></dl></>}
          {operations && paymentHistory && <div className="quote-card"><h3>Payment history</h3>{paymentHistory.items.length === 0 ? <p>No checkout started.</p> : paymentHistory.items.map(p => <div key={p.payment_id}><strong>{label(p.provider)} · {label(p.status)}</strong><p>{new Intl.NumberFormat('en-US', { style: 'currency', currency: p.currency }).format(p.amount_cents / 100)} · {date(p.created_at)}</p><p className="reference-text">Invoice: {p.reference}{p.transaction_id && <> · Transaction: {p.transaction_id}</>}</p>{p.status === 'PENDING' && Date.parse(p.quote_expires_at) <= Date.now() && <p>Expired quote with unresolved checkout. Review the sandbox receipt before any new payment attempt.</p>}</div>)}</div>}
          <h3>{operations ? 'Custody timeline' : 'Shipment history'}</h3>{operations && timeline?.events?.length ? <ol className="timeline operations-timeline">{timeline.events.map(event => <li key={event.source + '-' + event.source_id}><strong>{label(event.code)}{event.result && <> · {label(event.result)}</>}</strong><small>{event.source.replaceAll('_', ' ')} · {date(event.occurred_at)}{event.actor && <> · {event.actor}</>}{event.location_name && <> · {event.location_name}</>}</small>{Object.keys(event.details).length > 0 && <code>{JSON.stringify(event.details)}</code>}</li>)}</ol> : timeline?.milestones.length ? <ol className="timeline">{timeline.milestones.map((m, i) => <li key={i}><strong>{label(m.code)}</strong><small>{date(m.occurred_at)}</small></li>)}</ol> : <p>No shipment events recorded yet.</p>}
        </> : <div className="empty-detail"><p className="eyebrow">{operations ? 'CLEAR HANDOFFS' : 'ONE ACCOUNT. BOTH DIRECTIONS.'}</p><h2>{operations ? 'Select a shipment to inspect its progress.' : 'Your parcel’s story, in one place.'}</h2><p>{operations ? 'Order and payment status are separate from physical parcel progress. A draft or quote never records a handoff.' : 'Create a shipment to send something, or open Receiving to add an incoming delivery. Only shipments you own or have verified are shown.'}</p></div>}
      </section>
    </div><footer>ZipcodeXpress · Austin pilot development · Synthetic sites and test quotes</footer>
  </main>;
}
