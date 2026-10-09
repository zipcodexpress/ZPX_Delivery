export type LockerWorkflow = 'ORIGIN_DEPOSIT' | 'RECIPIENT_PICKUP' | 'INBOUND_PICKUP' | 'FINAL_DEPOSIT';
export const workflowLabels: Record<LockerWorkflow, string> = {
  ORIGIN_DEPOSIT: 'Send a parcel', RECIPIENT_PICKUP: 'Pick up your parcel',
  INBOUND_PICKUP: 'Collect parcels for your run', FINAL_DEPOSIT: 'Deliver parcels to this locker',
};
export function pairingId(payload: string): string {
  const match = /^ZPXPAIR:([1-9][0-9]{0,17}):[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.exec(payload.trim());
  if (!match) throw new Error('Scan the pairing QR code shown on the locker terminal.');
  return match[1];
}
export function allowedWorkflow(role: 'customer' | 'carrier', workflow: string): workflow is LockerWorkflow {
  return (role === 'customer' ? ['ORIGIN_DEPOSIT', 'RECIPIENT_PICKUP'] : ['INBOUND_PICKUP', 'FINAL_DEPOSIT']).includes(workflow);
}
export function canApprovePairing(status: string, expiresAt: string, now = Date.now()): boolean {
  return ['PENDING', 'APPROVED'].includes(status) && Date.parse(expiresAt) > now;
}
export function canConfirmHandoff(status: string, transferred: boolean): boolean {
  return status === 'CLOSED' && !transferred;
}
