import { useEffect, useRef, useState } from 'react';
import { api } from './api';
import type { components } from '../contracts/generated/api';
type Payment = components['schemas']['PaymentSession'];
export function HostedPopup({ payment, csrf, onPaid }: { payment: Payment; csrf: string; onPaid: () => Promise<void> }) {
  const popup = useRef<Window | null>(null);
  const target = useRef('zpx_checkout_' + crypto.randomUUID());
  const [active, setActive] = useState(false);
  const [message, setMessage] = useState('');
  const paid = useRef(onPaid); paid.current = onPaid;
  useEffect(() => {
    if (!active) return;
    let cancelled = false; let timer: ReturnType<typeof setTimeout>; const started = Date.now();
    async function check() {
      try {
        const result = await api<Payment>('/payments/' + payment.payment_id + '/reconcile', {}, { 'X-CSRF-Token': csrf, 'Idempotency-Key': crypto.randomUUID() });
        if (cancelled) return;
        if (result.status === 'PAID') { popup.current?.close(); setActive(false); await paid.current(); return; }
        setMessage(popup.current?.closed ? 'Checkout window closed. Payment is still pending; you can reopen it or check your receipt.' : 'Waiting for payment. This popup closes automatically after ZPX verifies it.');
      } catch (e) { if (!cancelled) setMessage(e instanceof Error ? e.message : 'Unable to check payment.'); }
      if (!cancelled && Date.now() - started < 15 * 60_000) timer = setTimeout(check, 5000);
      else if (!cancelled) { setActive(false); setMessage('Automatic checking paused. Use Check payment status to continue.'); }
    }
    timer = setTimeout(check, 4000);
    return () => { cancelled = true; clearTimeout(timer); };
  }, [active, payment.payment_id, csrf]);
  return <div>
    <form method="post" action="https://test.authorize.net/payment/payment" target={target.current} onSubmit={e => {
      if (active && popup.current && !popup.current.closed) { e.preventDefault(); popup.current.focus(); return; }
      const w = window.open('', target.current, 'popup,width=560,height=780,resizable=yes,scrollbars=yes');
      if (!w) { e.preventDefault(); setMessage('Your browser blocked the popup. Allow popups for this site and try again.'); return; }
      w.opener = null; popup.current = w; setActive(true); setMessage('Secure checkout opened. Keep this page open while you pay.');
    }}><input type="hidden" name="token" value={payment.checkout_token || ''} /><button className="primary">{active ? 'Return to payment popup' : 'Pay securely in popup'}</button></form>
    {message && <p role="status">{message}</p>}
  </div>;
}
