import { useEffect, useState, type FormEvent } from 'react';
import type { components } from '../contracts/generated/api';
import './foundation.css';

type Profile = components['schemas']['Profile'];
type Challenge = components['schemas']['ContactChallenge'];
import { api, RequestError } from './api';
import { Shipping } from './Shipping';
import { CustomerPortal } from './CustomerPortal';
import { DriverWorkspace } from './DriverWorkspace';
import { HubOperations } from './HubOperations';

export function Account({ audience }: { audience: 'customer' | 'operations' }) {
  const customer = audience === 'customer';
  const [profile, setProfile] = useState<Profile | null>(null);
  const [accountOnly, setAccountOnly] = useState(false);
  const [loading, setLoading] = useState(true);
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [challenge, setChallenge] = useState<Challenge | null>(null);
  const [kind, setKind] = useState<'EMAIL' | 'PHONE'>('EMAIL');
  useEffect(() => {
    let active = true;
    api<Profile>('/me').then(p => { if (active) setProfile(p); }).catch(e => {
      if (active && (!(e instanceof RequestError) || e.status !== 401)) setError('We could not connect. Please try again.');
    }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);
  async function run(action: () => Promise<void>) {
    setBusy(true); setError(''); setNotice('');
    try { await action(); } catch (e) { setError(e instanceof Error ? e.message : 'Please try again.'); }
    finally { setBusy(false); }
  }
  function verifyContact(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!challenge || busy) return;
    const code = String(new FormData(event.currentTarget).get('code') || '').replace(/\s/g, '');
    setNotice('');
    if (!/^[0-9]{6}$/.test(code)) { setError('Enter the six digits from your email or phone verification message, then click Verify code.'); return; }
    if (Date.parse(challenge.expires_at) <= Date.now()) { setError('This code has expired. Request a new code and use the newest message.'); return; }
    void run(async () => {
      await api('/auth/verify-contact', { challenge_id: challenge.challenge_id, code });
      setProfile(await api<Profile>('/me')); setChallenge(null); setAccountOnly(true);
      setNotice('Contact verified successfully. You can continue below.');
    });
  }
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const password = String(fields.get('password'));
    void run(async () => {
      if (mode === 'register') {
        await api('/auth/register', {
          name: fields.get('name'), email, phone, password,
          address: { line1: fields.get('line1'), city: fields.get('city'), region: fields.get('region'), postal_code: fields.get('postal_code'), country_code: 'US' },
        });
      }
      await api('/auth/login', { email, password, client_kind: 'BROWSER' });
      setProfile(await api<Profile>('/me')); setAccountOnly(false);
      form.reset();
      setNotice(mode === 'register' ? 'Your account is created. Verify both contacts before shipping.' : 'Welcome back.');
    });
  }
  function logout() {
    void run(async () => {
      try { await api('/auth/logout', {}, { 'Idempotency-Key': crypto.randomUUID(), 'X-CSRF-Token': profile?.csrf_token || '' }); }
      catch (e) { if (!(e instanceof RequestError) || e.status !== 401) throw e; }
      setProfile(null); setChallenge(null); setMode('login');
    });
  }
  const operationsAccess = profile?.roles.some(role => ['ADMIN', 'DISPATCHER', 'HUB_STAFF', 'HUB_SUPERVISOR', 'DRIVER'].includes(role));
  const isDriver = profile?.roles.includes('DRIVER') && !customer;
  const isHubStaff = profile?.roles.includes('HUB_STAFF') && !customer;
  if (profile && profile.email_verified && profile.phone_verified && (customer || operationsAccess) && !accountOnly) return isDriver ? <DriverWorkspace profile={profile} onLogout={logout} /> : isHubStaff ? <HubOperations profile={profile} onLogout={logout} /> : customer && profile.roles.includes('CUSTOMER') ? <CustomerPortal profile={profile} onProfileUpdate={setProfile} onVerification={() => setAccountOnly(true)} onLogout={logout} /> : <Shipping profile={profile} audience={audience} onAccount={() => setAccountOnly(true)} onLogout={logout} />;
  return <main className="account-page">
    <header><strong>ZipcodeXpress<span className="brand-dot">.</span></strong><span>LOCAL DEVELOPMENT</span></header>
    <div className="account-layout">
      <div className="account-intro">
        <p className="eyebrow">{customer ? 'AUSTIN DELIVERY NETWORK' : 'HUB & OPERATIONS'}</p>
        <h1>{customer ? 'A simpler way to send. A better way to receive.' : 'Every handoff, accounted for.'}</h1>
        <p className="intro">{customer ? 'One account for your ZPX deliveries. Start with your details and verify how we can reach you.' : 'Sign in with your assigned staff account. Your access follows your site and hub responsibilities.'}</p>
        <div className="journey"><span>01 &nbsp; Your account</span><span>02 &nbsp; Verify contacts</span><span className="future-step">03 &nbsp; Send & receive</span></div>
        <p className="local-note">Development preview: approved test email addresses can receive verification codes. Synthetic email addresses and phone codes use the private development inbox. Physical locker commands are not enabled.</p>
      </div>
      <section className="account-card" aria-label="Account access">
        {loading ? <p role="status">Checking your session…</p> : profile ? <>
          <p className="eyebrow">YOUR ACCOUNT</p><h2>Welcome, {profile.name}.</h2>
          <div className="verification-list"><p><span>Email</span><strong className={profile.email_verified ? 'verified' : ''}>{profile.email_verified ? 'Verified' : 'Needs verification'}</strong></p><p><span>Phone</span><strong className={profile.phone_verified ? 'verified' : ''}>{profile.phone_verified ? 'Verified' : 'Needs verification'}</strong></p></div>
          {!customer && !operationsAccess && <p className="notice">This customer account has no operations access. A staff assignment is required.</p>}
          {(!profile.email_verified || !profile.phone_verified) && <form onSubmit={e => { e.preventDefault(); void run(async () => {
            setChallenge(await api<Challenge>('/auth/challenges', { kind, contact_value: kind === 'EMAIL' ? email : phone, purpose: 'REGISTER' }));
            setNotice('If this contact belongs to your account, a verification message is queued.');
          }); }}>
            <h3>Verify your contact</h3>
            <label>Contact type<select value={kind} onChange={e => { setKind(e.target.value as 'EMAIL' | 'PHONE'); setChallenge(null); }}><option value="EMAIL">Email</option><option value="PHONE">Phone</option></select></label>
            <label>{kind === 'EMAIL' ? 'Email address' : 'Phone number'}<input required type={kind === 'EMAIL' ? 'email' : 'tel'} value={kind === 'EMAIL' ? email : phone} onChange={e => { (kind === 'EMAIL' ? setEmail : setPhone)(e.target.value); setChallenge(null); }} placeholder={kind === 'EMAIL' ? 'you@example.com' : '+12025550101'} /></label>
            <button disabled={busy} type="submit">{busy ? 'Please wait…' : 'Request verification code'}</button>
          </form>}
          {challenge && <form noValidate onSubmit={verifyContact} aria-busy={busy}>
            <p>Enter the code from the newest message, then click <strong>Verify code</strong>. This request expires at {new Date(challenge.expires_at).toLocaleTimeString()}.</p>
            <label>Six-digit code<input name="code" inputMode="numeric" autoComplete="one-time-code" required aria-describedby="verification-help" onChange={() => setError('')} /></label>
            <small id="verification-help">Pasted spaces are accepted. Requesting a new code means you must use that new request’s message.</small>
            <button type="submit" disabled={busy}>{busy ? 'Please wait…' : 'Verify code'}</button>
          </form>}
          {profile.email_verified && profile.phone_verified && <p className="notice">Your contacts are verified. You can send and receive with this account.</p>}
          {profile.email_verified && profile.phone_verified && (customer || operationsAccess) && <button onClick={() => setAccountOnly(false)}>Open shipments</button>}
          <button className="secondary" onClick={logout} disabled={busy}>Sign out</button>
        </> : <>
          <h2>{mode === 'login' ? 'Welcome back' : 'Create your account'}</h2><p className="card-intro">{mode === 'login' ? 'Sign in to your delivery account.' : 'Your contact details stay private.'}</p>
          {customer && <div className="account-tabs" aria-label="Account options"><button type="button" aria-pressed={mode === 'login'} onClick={() => { setMode('login'); setError(''); }} disabled={busy}>Sign in</button><button type="button" aria-pressed={mode === 'register'} onClick={() => { setMode('register'); setError(''); }} disabled={busy}>Create account</button></div>}
          <form key={mode} onSubmit={submit}>
            {mode === 'register' && <label>Full name<input name="name" autoComplete="name" required maxLength={160} /></label>}
            <label>Email address<input name="email" type="email" autoComplete="email" value={email} onChange={e => setEmail(e.target.value)} required maxLength={254} /></label>
            {mode === 'register' && <label>Phone number<input name="phone" type="tel" autoComplete="tel" placeholder="+12025550101" value={phone} onChange={e => setPhone(e.target.value)} required pattern="\+[1-9][0-9]{7,14}" /><small>Include your country code.</small></label>}
            <label>Password<input name="password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} required minLength={mode === 'register' ? 12 : 1} maxLength={72} />{mode === 'register' && <small>Use at least 12 characters.</small>}</label>
            {mode === 'register' && <fieldset><legend>Your address</legend><label>Street address<input name="line1" autoComplete="address-line1" required maxLength={200} /></label><div className="form-grid"><label>City<input name="city" autoComplete="address-level2" required maxLength={200} /></label><label>State<input name="region" autoComplete="address-level1" required maxLength={200} /></label></div><label>ZIP code<input name="postal_code" autoComplete="postal-code" required maxLength={200} /></label><small>United States · This is your profile address, not a destination locker.</small></fieldset>}
            <button className="primary" type="submit" disabled={busy}>{busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account'}</button>
          </form>
        </>}
        {error && <p className="error" role="alert">{error}</p>}{notice && <p className="notice" role="status">{notice}</p>}
      </section>
    </div>
    <footer>ZipcodeXpress · Austin pilot development · No live shipping or payments</footer>
  </main>;
}
