import { useEffect, useState } from 'react';
import './foundation.css';

export function Foundation({ audience }: { audience: 'customer' | 'operations' }) {
  const [health, setHealth] = useState('Checking connection…');
  useEffect(() => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    fetch('/health/ready', { signal: controller.signal }).then(async response => {
      const result = await response.json();
      setHealth(response.ok ? `API connected · ${result.draft_tables} draft database tables` : 'Database not ready');
    }).catch(() => setHealth('API unavailable — check the local services')).finally(() => clearTimeout(timeout));
    return () => { clearTimeout(timeout); controller.abort(); };
  }, []);
  const customer = audience === 'customer';
  return <main>
    <header><strong>ZipcodeXpress</strong><span>Development environment</span></header>
    <p className="eyebrow">{customer ? 'CUSTOMER PORTAL' : 'HUB & OPERATIONS'}</p>
    <h1>{customer ? 'Your delivery network starts here.' : 'One place to follow every parcel.'}</h1>
    <p className="intro">{customer ? 'The foundation for sending and receiving packages across Austin.' : 'The foundation for hub receiving, dispatch and locker operations.'}</p>
    <section aria-label="Connection status"><h2>Local connection</h2><p role="status">{health}</p></section>
    <section><h2>What comes next</h2><ul>{(customer ? ['Verified accounts and location selection', 'Shipping quotes, SI and printable labels', 'Deposit pairing, tracking and recipient pickup'] : ['Hub intake and discrepancy reconciliation', 'Destination staging and individual loading scans', 'Assigned routes, exceptions and device health']).map(x => <li key={x}>{x}</li>)}</ul></section>
    <footer>This build verifies the development environment. Shipping, authentication and live locker control are not enabled.</footer>
  </main>;
}
