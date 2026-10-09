import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Linking, ScrollView, Text, View } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import * as Crypto from 'expo-crypto';
import { command, request, type Schemas } from './api';
import { Button, Field, Message, styles } from './ui';
import { allowedWorkflow, canApprovePairing, canConfirmHandoff, pairingId, workflowLabels, type LockerWorkflow } from './pairing';

type Pairing = { pairing_id: string; workflow: LockerWorkflow; status: string; expires_at: string; location_id: string; location_name: string; session_id: string | null };
type Session = { session_id: string; status: string; workflow: LockerWorkflow; package_id: string; expected_package_version: number; compartment_code: string; si: string; custody_transferred: boolean; workflow_context: { run_id?: string; stop_id?: string; expected_revision?: number } };

/** Both mobile roles use their existing authenticated session; cabinet credentials stay at the terminal. */
export function Locker({ role, onClose }: { role: 'customer' | 'carrier'; onClose: () => void }) {
  const [permission, requestPermission] = useCameraPermissions();
  const [camera, setCamera] = useState(false);
  const [code, setCode] = useState('');
  const [scene, setScene] = useState('');
  const [pair, setPair] = useState<Pairing | null>(null);
  const [approved, setApproved] = useState(false);
  const [session, setSession] = useState<Session | null>(null);
  const [parcels, setParcels] = useState<Schemas['Shipment'][]>([]);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const locked = useRef(false);
  const scanLocked = useRef(false);
  const keys = useRef({ approve: Crypto.randomUUID(), grant: Crypto.randomUUID(), prepare: Crypto.randomUUID(), confirm: Crypto.randomUUID() });
  const chosenParcel = useRef<string | null>(null);

  async function act(work: () => Promise<void>) {
    if (locked.current) return;
    locked.current = true; setBusy(true); setError('');
    try { await work(); } catch (e) { setError(e instanceof Error ? e.message : 'Could not connect to this locker.'); }
    finally { locked.current = false; setBusy(false); }
  }
  async function inspect(payload: string) {
    const clean = payload.trim(); const id = pairingId(clean);
    const result = await command<Pairing>('/pairings/' + id + '/inspect', { scene_payload: clean });
    if (!allowedWorkflow(role, result.workflow)) throw new Error('Choose a ' + (role === 'customer' ? 'customer' : 'carrier') + ' task on the terminal.');
    if (!result.session_id && !canApprovePairing(result.status, result.expires_at)) throw new Error('This pairing has ended. Start a new task at the terminal.');
    setScene(clean); setPair(result); setApproved(result.status === 'APPROVED' || !!result.session_id); setCamera(false);
    if (result.session_id) setSession(await request<Session>('/locker-sessions/' + result.session_id));
    else if (result.status === 'APPROVED' && result.workflow === 'RECIPIENT_PICKUP') await loadParcels(result.location_id);
  }
  async function loadParcels(location = pair?.location_id) {
    const result = await request<Schemas['ShipmentList']>('/shipments?view=receiving');
    setParcels(result.items.filter(p => p.package_state === 'AT_DESTINATION' && p.destination_location_id === location));
  }
  async function approve() {
    if (!pair || !canApprovePairing(pair.status, pair.expires_at)) throw new Error('This pairing expired. Scan a new code.');
    await command('/pairings/' + pair.pairing_id + '/approve', { workflow: pair.workflow, location_id: pair.location_id, scene_payload: scene }, undefined, keys.current.approve);
    setApproved(true);
    if (pair.workflow === 'RECIPIENT_PICKUP') await loadParcels();
  }
  async function pickup(parcel: Schemas['Shipment']) {
    if (!pair) return;
    if (chosenParcel.current !== parcel.package_id) {
      chosenParcel.current = parcel.package_id;
      keys.current.grant = Crypto.randomUUID(); keys.current.prepare = Crypto.randomUUID();
    }
    const grant = await command<{ pickup_token: string }>('/pickup-grants', { package_id: parcel.package_id, expected_package_version: parcel.package_version }, undefined, keys.current.grant);
    const prepared = await command<{ session_id: string }>('/locker-sessions', { workflow: 'RECIPIENT_PICKUP', package_id: parcel.package_id, pairing_id: pair.pairing_id, expected_package_version: parcel.package_version, pickup_token: grant.pickup_token }, undefined, keys.current.prepare);
    setSession(await request<Session>('/locker-sessions/' + prepared.session_id));
  }
  useEffect(() => { keys.current.confirm = Crypto.randomUUID(); }, [session?.session_id]);
  // Poll only in the foreground, never overlap mutations or issue automatic door opens.
  useEffect(() => {
    if (!approved || !pair || session?.custody_transferred) return;
    let active = true; let timer: ReturnType<typeof setTimeout>;
    async function poll() {
      if (active && AppState.currentState === 'active' && !locked.current) {
        locked.current = true;
        try {
          const current = await command<Pairing>('/pairings/' + pair!.pairing_id + '/inspect', { scene_payload: scene });
          if (active) setPair(current);
          if (current.session_id) {
            const latest = await request<Session>('/locker-sessions/' + current.session_id);
            if (active) { setSession(latest); setError(''); }
          } else if (!canApprovePairing(current.status, current.expires_at)) {
            if (active) setError('This pairing has ended. Start a new task at the terminal.');
          }
        } catch (e) { if (active) setError(e instanceof Error ? e.message : 'Connection lost. Follow the terminal instructions.'); }
        finally { locked.current = false; }
      }
      if (active) timer = setTimeout(() => void poll(), 2000);
    }
    timer = setTimeout(() => void poll(), 1000);
    return () => { active = false; clearTimeout(timer); };
  }, [approved, pair?.pairing_id, scene, session?.custody_transferred]);

  async function confirm() {
    if (!session || !canConfirmHandoff(session.status, session.custody_transferred)) return;
    let path = '/locker-sessions/' + session.session_id + '/confirm';
    let body: object = { attested: true, expected_package_version: session.expected_package_version };
    if (session.workflow === 'FINAL_DEPOSIT') {
      const ctx = session.workflow_context;
      if (!ctx.run_id || !ctx.stop_id || ctx.expected_revision === undefined) throw new Error('Scan the terminal code again to resume this delivery.');
      path = '/runs/' + ctx.run_id + '/stops/' + ctx.stop_id + '/final-deposits/' + session.session_id + '/confirm';
      body = { placed: true, expected_package_version: session.expected_package_version, expected_revision: ctx.expected_revision };
    }
    await command(path, body, undefined, keys.current.confirm);
    setSession(await request<Session>('/locker-sessions/' + session.session_id));
  }

  return <ScrollView style={styles.page} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
    <Button secondary onPress={onClose}>← Back</Button>
    <Text style={styles.title}>Use locker</Text>
    <Message error={error} />{busy && <ActivityIndicator />}
    {!pair && <>
      <Text style={styles.body}>Choose your task on the locker terminal, then scan its pairing QR code.</Text>
      <Button disabled={busy} onPress={() => void act(async () => {
        const result = permission?.granted ? permission : await requestPermission();
        if (!result.granted) throw new Error('Allow camera access in Settings, or enter the terminal pairing code below.');
        scanLocked.current = false; setCamera(true);
      })}>Scan terminal QR code</Button>
      {permission && !permission.granted && !permission.canAskAgain && <Button secondary onPress={() => void Linking.openSettings()}>Open camera settings</Button>}
      {camera && <><CameraView style={{ height: 280, borderRadius: 16 }} barcodeScannerSettings={{ barcodeTypes: ['qr'] }} onBarcodeScanned={({ data }) => {
        if (scanLocked.current || locked.current) return;
        scanLocked.current = true; setCamera(false); setCode(data); void act(() => inspect(data));
      }} /><Button secondary onPress={() => setCamera(false)}>Close camera</Button></>}
      <Field label="Or enter the full terminal pairing code" value={code} onChangeText={setCode} placeholder="ZPXPAIR:…" />
      <Button secondary disabled={busy || !code.trim()} onPress={() => void act(() => inspect(code))}>Check locker</Button>
    </>}
    {pair && <View style={styles.card}>
      <Text style={styles.heading}>{pair.location_name}</Text><Text style={styles.body}>{workflowLabels[pair.workflow]}</Text>
      {!approved && <><Text style={styles.muted}>Check that this is the locker and action you intend to use.</Text><Button disabled={busy} onPress={() => void act(approve)}>Approve this locker</Button></>}
      {approved && !session && pair.workflow !== 'RECIPIENT_PICKUP' && <Text style={styles.body}>Scan the parcel’s shipping label at the terminal. {role === 'carrier' ? 'Your assigned run stop must be marked Arrived.' : 'The shipment must be paid and ready to send.'}</Text>}
      {approved && !session && pair.workflow === 'RECIPIENT_PICKUP' && <>
        <Text style={styles.body}>Choose the parcel you are collecting.</Text>
        {parcels.map(parcel => <Button key={parcel.package_id} disabled={busy} onPress={() => void act(() => pickup(parcel))}>{parcel.si}</Button>)}
        {parcels.length === 0 && <Text style={styles.muted}>No parcels ready for pickup at this locker.</Text>}
        <Button secondary disabled={busy} onPress={() => void act(loadParcels)}>Refresh parcels</Button>
      </>}
    </View>}
    {session && <View style={styles.card}>
      <Text style={styles.heading}>{session.si} · {session.compartment_code}</Text>
      <Text accessibilityLiveRegion="polite" style={styles.body}>{session.custody_transferred ? 'Parcel handoff recorded.' : session.status === 'CANCELLED' ? 'Deposit declined. Choose another size on the terminal.' : session.status === 'UNKNOWN' ? 'Request assistance. Do not reopen the door.' : session.status === 'CLOSED' ? 'Door closed. Confirm only what you actually placed or removed.' : 'Follow the terminal instructions. Parcel custody has not changed yet.'}</Text>
      {canConfirmHandoff(session.status, session.custody_transferred) && <>
        <Button disabled={busy} onPress={() => void act(confirm)}>{session.workflow.includes('DEPOSIT') ? 'Yes, I placed the parcel and closed the door' : 'Yes, I removed the parcel and closed the door'}</Button>
        {session.workflow.includes('DEPOSIT') && <Text style={styles.muted}>If you did not deposit it, select “No, I didn’t” on the terminal to choose another size.</Text>}
      </>}
      {session.custody_transferred && <Button onPress={onClose}>Done</Button>}
    </View>}
  </ScrollView>;
}
