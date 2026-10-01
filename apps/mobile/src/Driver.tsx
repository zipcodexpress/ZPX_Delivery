import { useEffect, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, Text, TextInput, useWindowDimensions, View } from 'react-native';
import * as Crypto from 'expo-crypto';
import { command, request } from './api';
import { apiDate, displayDate } from './dates';
import { Button, colors, Field, Message, pretty, styles, Tabs } from './ui';

type Run = { id: string; kind: string; state: string; revision: number; hub: string; vehicle: string; planned_start: string; expected_count: number; loaded_count: number };
type Stop = { id: string; sequence: number; state: string; location: { name: string; address: string } };
type ManifestItem = { manifest_item_id: string; public_reference: string; si: string | null; state: string; package_state: string; stop_sequence: number };
type RunDetail = Omit<Run, 'expected_count' | 'loaded_count' | 'vehicle'> & { stops: Stop[]; manifest: ManifestItem[] };
type PickupOffer = { offer_id: string; origin: string; address: string; hub: string; package_count: number; expires_at: string };
type DriverProfile = {
  name: string; status: string; verification_status: string; email: string | null; phone: string | null;
  applied_at: string | null; approved_at: string | null;
  date_of_birth: string | null;
  license: { number: string; state: string; expiry: string } | null;
  address: { line1: string; line2: string | null; city: string; state: string; postal_code: string; country_code: string } | null;
  emergency_contact: { name: string; phone: string } | null;
  vehicle: { make: string; model: string; license_plate: string; color: string | null; year: number | null; vin: string | null; registration_state: string | null; registration_expiry: string | null; insurance_provider: string | null; insurance_policy: string | null; insurance_expiry: string | null } | null;
  insurance_reference: string | null; notes: string | null;
};
type Wallet = { total_earned_cents: number; total_settled_cents: number; pending_cents: number; currency: string };
type Transaction = { transaction_id: string; amount_cents: number; kind: string; run_kind: string | null; run_id: string | null; created_at: string; settled_at: string | null };
type Tab = 'inbound' | 'outbound' | 'earnings' | 'profile';
type ScanIntent = { payload: string; version: number; key: string; eventId: string; action: 'INBOUND_PICKUP' | 'OUTBOUND_LOAD' };

function profileForm(profile: DriverProfile) {
  return {
    name: profile.name, email: profile.email ?? '', phone: profile.phone ?? '',
    date_of_birth: profile.date_of_birth?.slice(0, 10) ?? '',
    license_number: profile.license?.number ?? '', license_state: profile.license?.state ?? '', license_expiry: profile.license?.expiry?.slice(0, 10) ?? '',
    address_line1: profile.address?.line1 ?? '', address_line2: profile.address?.line2 ?? '', address_city: profile.address?.city ?? '',
    address_state: profile.address?.state ?? '', address_postal_code: profile.address?.postal_code ?? '', address_country_code: profile.address?.country_code ?? 'US',
    emergency_contact_name: profile.emergency_contact?.name ?? '', emergency_contact_phone: profile.emergency_contact?.phone ?? '',
    vehicle_make: profile.vehicle?.make ?? '', vehicle_model: profile.vehicle?.model ?? '', vehicle_year: profile.vehicle?.year?.toString() ?? '',
    vehicle_color: profile.vehicle?.color ?? '', vehicle_license_plate: profile.vehicle?.license_plate ?? '', vehicle_vin: profile.vehicle?.vin ?? '',
    vehicle_registration_state: profile.vehicle?.registration_state ?? '', vehicle_registration_expiry: profile.vehicle?.registration_expiry?.slice(0, 10) ?? '',
    vehicle_insurance_provider: profile.vehicle?.insurance_provider ?? '', vehicle_insurance_policy: profile.vehicle?.insurance_policy ?? '',
    vehicle_insurance_expiry: profile.vehicle?.insurance_expiry?.slice(0, 10) ?? '', insurance_reference: profile.insurance_reference ?? '', notes: profile.notes ?? '',
  };
}
type DriverForm = ReturnType<typeof profileForm>;
type FormField = { key: keyof DriverForm; label: string; placeholder?: string; keyboardType?: 'default' | 'email-address' | 'phone-pad' | 'numeric' };
const profileSections: { title: string; fields: FormField[] }[] = [
  { title: 'Personal information', fields: [{ key: 'name', label: 'Full name' }, { key: 'email', label: 'Email', keyboardType: 'email-address' }, { key: 'phone', label: 'Phone', keyboardType: 'phone-pad' }, { key: 'date_of_birth', label: 'Date of birth', placeholder: 'YYYY-MM-DD' }] },
  { title: 'Driver license', fields: [{ key: 'license_number', label: 'License number' }, { key: 'license_state', label: 'License state' }, { key: 'license_expiry', label: 'Expiry date', placeholder: 'YYYY-MM-DD' }] },
  { title: 'Address', fields: [{ key: 'address_line1', label: 'Street address' }, { key: 'address_line2', label: 'Apt or suite' }, { key: 'address_city', label: 'City' }, { key: 'address_state', label: 'State' }, { key: 'address_postal_code', label: 'ZIP code' }, { key: 'address_country_code', label: 'Country code' }] },
  { title: 'Emergency contact', fields: [{ key: 'emergency_contact_name', label: 'Contact name' }, { key: 'emergency_contact_phone', label: 'Contact phone', keyboardType: 'phone-pad' }] },
  { title: 'Your vehicle', fields: [{ key: 'vehicle_make', label: 'Make' }, { key: 'vehicle_model', label: 'Model' }, { key: 'vehicle_year', label: 'Year', keyboardType: 'numeric' }, { key: 'vehicle_color', label: 'Color' }, { key: 'vehicle_license_plate', label: 'License plate' }, { key: 'vehicle_vin', label: 'VIN' }, { key: 'vehicle_registration_state', label: 'Registration state' }, { key: 'vehicle_registration_expiry', label: 'Registration expiry', placeholder: 'YYYY-MM-DD' }] },
  { title: 'Vehicle insurance', fields: [{ key: 'vehicle_insurance_provider', label: 'Provider' }, { key: 'vehicle_insurance_policy', label: 'Policy number' }, { key: 'vehicle_insurance_expiry', label: 'Insurance expiry', placeholder: 'YYYY-MM-DD' }, { key: 'insurance_reference', label: 'Insurance reference' }] },
];
const fullWidthFields: (keyof DriverForm)[] = ['name', 'email', 'license_number', 'address_line1', 'address_line2', 'emergency_contact_name', 'emergency_contact_phone', 'vehicle_vin', 'vehicle_insurance_provider', 'vehicle_insurance_policy', 'insurance_reference'];
const dateFields: (keyof DriverForm)[] = ['date_of_birth', 'license_expiry', 'vehicle_registration_expiry', 'vehicle_insurance_expiry'];

function validateProfileChanges(changes: Partial<DriverForm>) {
  if (changes.name !== undefined && !changes.name.trim()) throw new Error('Enter your full name.');
  for (const key of dateFields) {
    const value = changes[key];
    if (value && (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number.isNaN(Date.parse(value)) || new Date(value).toISOString().slice(0, 10) !== value)) {
      throw new Error('Enter a valid calendar date (YYYY-MM-DD).');
    }
  }
  if (changes.vehicle_year && (!/^\d{4}$/.test(changes.vehicle_year) || Number(changes.vehicle_year) < 1900 || Number(changes.vehicle_year) > 2100)) throw new Error('Enter a valid four-digit vehicle year.');
  for (const key of ['license_state', 'vehicle_registration_state'] as const) {
    if (changes[key] && !/^[A-Za-z]{2}$/.test(changes[key])) throw new Error('Use a two-letter state code for license and registration.');
  }
  if (changes.address_country_code && !/^[A-Z]{2}$/.test(changes.address_country_code)) throw new Error('Use a two-letter uppercase country code.');
  if (changes.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(changes.email)) throw new Error('Enter a valid email address.');
}

export function Driver({ onNameChange }: { onNameChange: (name: string) => void }) {
  const { width, fontScale } = useWindowDimensions();
  const [tab, setTab] = useState<Tab>('inbound');
  const [runs, setRuns] = useState<Run[]>([]);
  const [offers, setOffers] = useState<PickupOffer[]>([]);
  const [selected, setSelected] = useState<RunDetail | null>(null);
  const [profile, setProfile] = useState<DriverProfile | null>(null);
  const [editingProfile, setEditingProfile] = useState<DriverForm | null>(null);
  const [originalProfileForm, setOriginalProfileForm] = useState<DriverForm | null>(null);
  const [expandedProfileSection, setExpandedProfileSection] = useState<string | null>('Personal information');
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [transactionCursor, setTransactionCursor] = useState<string | null>(null);
  const [label, setLabel] = useState('');
  const [scanIntent, setScanIntent] = useState<ScanIntent | null>(null);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  async function loadRuns() {
    const [runList, offerList] = await Promise.all([
      request<{ items: Run[] }>('/driver/runs'),
      request<{ items: PickupOffer[] }>('/driver/pickup-offers'),
    ]);
    setRuns(runList.items); setOffers(offerList.items);
  }

  async function loadProfile() {
    const [driver, balance, history] = await Promise.all([
      request<DriverProfile>('/driver/profile'),
      request<Wallet>('/driver/wallet'),
      request<{ items: Transaction[]; next_cursor: string | null }>('/driver/transactions'),
    ]);
    setProfile(driver); setWallet(balance); setTransactions(history.items); setTransactionCursor(history.next_cursor);
  }

  useEffect(() => {
    let active = true;
    setLoading(true); setError('');
    const load = tab === 'inbound' || tab === 'outbound' ? loadRuns : loadProfile;
    void load().catch(e => { if (active) setError(e instanceof Error ? e.message : 'Could not load driver information.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [tab]);

  async function run(action: () => Promise<void>) {
    setLoading(true); setError(''); setNotice('');
    try { await action(); }
    catch (e) { setError(e instanceof Error ? e.message : 'Please try again.'); }
    finally { setLoading(false); }
  }

  async function refresh() {
    setRefreshing(true); setError('');
    try { await (tab === 'inbound' || tab === 'outbound' ? loadRuns() : loadProfile()); }
    catch (e) { setError(e instanceof Error ? e.message : 'Could not refresh driver information.'); }
    finally { setRefreshing(false); }
  }

  function beginProfileEdit() {
    if (!profile) return;
    const form = profileForm(profile);
    setOriginalProfileForm(form); setEditingProfile(form); setExpandedProfileSection('Personal information'); setError(''); setNotice('');
  }

  async function saveProfile() {
    if (!editingProfile || !originalProfileForm) return;
    const changes = Object.fromEntries((Object.keys(editingProfile) as (keyof DriverForm)[])
      .filter(key => editingProfile[key].trim() !== originalProfileForm[key].trim())
      .map(key => [key, editingProfile[key].trim()])) as Partial<DriverForm>;
    if (Object.keys(changes).length === 0) { setEditingProfile(null); setOriginalProfileForm(null); return; }
    validateProfileChanges(changes);
    const updated = await command<DriverProfile>('/driver/profile/update', changes);
    setProfile(updated); onNameChange(updated.name);
    setEditingProfile(null); setOriginalProfileForm(null);
    setNotice('Driver profile saved.');
  }

  async function accept(offer: PickupOffer) {
    await command('/driver/pickup-offers/' + offer.offer_id + '/accept', {});
    await loadRuns();
    setNotice('Pickup accepted. Open your assigned run for the current stop list.');
  }

  async function open(id: string) {
    setSelected(await request<RunDetail>('/runs/' + id));
    setLabel(''); setScanIntent(null);
  }

  async function acknowledge() {
    if (!selected) return;
    await command('/runs/' + selected.id + '/acknowledgments', {});
    await open(selected.id); await loadRuns();
    setNotice('Run accepted. Follow the stop list and scan each parcel before transfer.');
  }

  async function resolveLabel() {
    if (!selected || !label.trim()) throw new Error('Enter or scan a package label first.');
    const action = selected.kind === 'INBOUND' ? 'INBOUND_PICKUP' : 'OUTBOUND_LOAD';
    const resolved = await command<{ version: number; allowed_actions: string[]; si: string | null }>('/scans/resolve', { label_payload: label.trim(), action, run_id: selected.id });
    if (!resolved.allowed_actions.includes(action)) throw new Error('This package is not eligible for this run.');
    setScanIntent({ payload: label.trim(), version: resolved.version, key: Crypto.randomUUID(), eventId: Crypto.randomUUID(), action });
    setNotice(`Label verified${resolved.si ? ` · ${resolved.si}` : ''}. Confirm the physical parcel before recording custody.`);
  }

  async function recordScan() {
    if (!selected || !scanIntent) return;
    await command('/runs/' + selected.id + '/scans', {
      label_payload: scanIntent.payload, action: scanIntent.action, client_event_id: scanIntent.eventId,
      run_revision: selected.revision, expected_package_version: scanIntent.version,
      client_occurred_at: new Date().toISOString(),
    }, selected.revision, scanIntent.key);
    await open(selected.id); await loadRuns();
    setNotice('Scan recorded. The run and parcel counts have been refreshed.');
  }

  async function depart() {
    if (!selected) return;
    await command('/runs/' + selected.id + '/depart', { expected_revision: selected.revision }, selected.revision);
    await open(selected.id); await loadRuns();
    setNotice('Departure recorded. Continue to the first stop.');
  }

  async function arrive(stop: Stop) {
    if (!selected) return;
    await command('/runs/' + selected.id + '/stops/' + stop.id + '/arrive', { expected_revision: selected.revision }, selected.revision);
    await open(selected.id); await loadRuns();
    setNotice(`Arrival at ${stop.location.name} recorded.`);
  }

  const runTab = tab === 'inbound' || tab === 'outbound';
  const visibleRuns = runs.filter(item => item.kind === (tab === 'inbound' ? 'INBOUND' : 'OUTBOUND'));
  const expectedCount = selected?.manifest.filter(item => item.state !== 'RELEASED').length ?? 0;
  const loadedCount = selected?.manifest.filter(item => item.state === 'LOADED').length ?? 0;
  const profileEditorOpen = tab === 'profile' && editingProfile !== null;
  const hasProfileChanges = editingProfile && originalProfileForm && (Object.keys(editingProfile) as (keyof DriverForm)[])
    .some(key => editingProfile[key].trim() !== originalProfileForm[key].trim());
  function cancelProfileEdit() { setEditingProfile(null); setOriginalProfileForm(null); setError(''); }
  return <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.page}><ScrollView style={styles.page}
    contentContainerStyle={[styles.content, runTab && !selected && { gap: 10, paddingTop: 12 }]}
    keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" refreshControl={!selected && !(tab === 'profile' && editingProfile)
      ? <RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} /> : undefined}>
    {!profileEditorOpen && <Message error={error} />}{notice ? <View style={[styles.card, { backgroundColor: colors.mint }]}><Text style={styles.body}>{notice}</Text></View> : null}
    {loading && <ActivityIndicator />}
    {runTab && (selected ? <>
      <Button secondary onPress={() => { setSelected(null); setScanIntent(null); }}>← All {pretty(tab)} runs</Button>
      <View style={styles.hero}>
        <Text style={[styles.eyebrow, { color: '#8BE1D2' }]}>{pretty(selected.kind)} RUN · #{selected.id}</Text>
        <Text numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.75} style={[styles.heroTitle, { fontSize: 21, lineHeight: 27 }]}>{selected.hub}</Text>
        <Text style={styles.heroBody}>{pretty(selected.state)} · {displayDate(selected.planned_start)}</Text>
        <Text style={[styles.heroBody, { fontWeight: '700' }]}>{selected.state === 'COMPLETED' ? `${expectedCount} parcels on manifest` : `${loadedCount} of ${expectedCount} parcels loaded`}</Text>
      </View>
      {selected.state === 'PUBLISHED' && <Button onPress={() => void run(acknowledge)}>Accept assigned run</Button>}
      {selected.state === 'ACKNOWLEDGED' || (selected.state === 'IN_PROGRESS' && selected.kind === 'INBOUND') ? <View style={styles.card}>
        <Text style={styles.eyebrow}>PARCEL HANDOFF</Text>
        <Text style={styles.heading}>{selected.kind === 'INBOUND' ? 'Driver in · collect parcel' : 'Driver out · load vehicle'}</Text>
        <Text style={styles.muted}>Check the physical label and the parcel before recording custody. An uncertain network result must be retried with the same request.</Text>
        <Field label="Label token" value={label} onChangeText={value => { setLabel(value); setScanIntent(null); }} placeholder="Scan or enter label token" />
        <Button secondary disabled={loading || !label.trim()} onPress={() => void run(resolveLabel)}>Verify label</Button>
        {scanIntent && <View style={{ gap: 8 }}><Text style={styles.body}>Verified for {pretty(scanIntent.action)}. Confirm the parcel in your hands.</Text><Button disabled={loading} onPress={() => void run(recordScan)}>Record custody scan</Button></View>}
      </View> : null}
      {selected.kind === 'OUTBOUND' && selected.state === 'ACKNOWLEDGED' && expectedCount > 0 && loadedCount === expectedCount && <Button onPress={() => void run(depart)}>Confirm departure</Button>}
      <Text style={styles.heading}>Stops & manifest</Text>
      {selected.stops.map(stop => <View key={stop.id} style={styles.card}>
        <Text style={styles.eyebrow}>STOP {stop.sequence} · {pretty(stop.state)}</Text>
        <Text style={styles.heading}>{stop.location.name}</Text>
        <Text style={styles.muted}>{stop.location.address}</Text>
        {selected.manifest.filter(item => item.stop_sequence === stop.sequence).map(item => <Text key={item.manifest_item_id} style={styles.body}>
          {item.public_reference} · {pretty(item.state)} · {pretty(item.package_state)}
        </Text>)}
        {selected.kind === 'OUTBOUND' && selected.state === 'IN_PROGRESS' && stop.state === 'EXPECTED' && <Button secondary onPress={() => void run(() => arrive(stop))}>Report arrival</Button>}
      </View>)}
      <Text style={styles.muted}>Final locker deposit requires terminal pairing and stays in the existing driver workflow until mobile hardware checks are complete.</Text>
      <Button secondary onPress={() => void run(() => open(selected.id))}>Refresh run</Button>
    </> : <>
      <View><Text style={styles.eyebrow}>YOUR RUNS</Text><Text style={styles.title}>{tab === 'inbound' ? 'Driver in' : 'Driver out'}</Text>
        <Text style={styles.muted}>{tab === 'inbound' ? 'Collect at origin sites and return to the hub.' : 'Load at the hub, then deliver to each stop.'}</Text></View>
      <Text style={[styles.heading, { fontSize: 16 }]}>Assigned {pretty(tab)} runs</Text>
      {visibleRuns.length === 0 && !loading && <View style={styles.card}><Text style={styles.body}>No {tab} runs assigned right now.</Text></View>}
      <View style={{ gap: 7 }}>{visibleRuns.map(item => <Pressable key={item.id} accessibilityRole="button"
        accessibilityLabel={`Open ${pretty(item.kind)} run ${item.id}, ${pretty(item.state)}, ${item.hub}`}
        onPress={() => void run(() => open(item.id))} style={styles.shipmentRow}>
        <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
          <Text numberOfLines={1} style={styles.eyebrow}>{pretty(item.state)} · RUN #{item.id}</Text>
          <Text numberOfLines={1} style={styles.shipmentRoute}>{item.hub}</Text>
          <Text numberOfLines={1} style={styles.muted}>{item.state === 'COMPLETED' ? `${item.expected_count} parcels` : `${item.loaded_count} / ${item.expected_count} loaded`} · {displayDate(item.planned_start)}</Text>
        </View><Text style={{ color: colors.blue, fontSize: 20 }}>›</Text>
      </Pressable>)}</View>
      {tab === 'inbound' && <><Text style={styles.heading}>Pickup offers</Text>
        {offers.length === 0 && !loading && <Text style={styles.muted}>No pickup offers right now.</Text>}
        {offers.map(offer => <View key={offer.offer_id} style={styles.card}>
          <Text style={styles.heading}>{offer.origin}</Text><Text style={styles.body}>{offer.address}</Text>
          <Text style={styles.muted}>{offer.package_count} parcels · Hub: {offer.hub}</Text>
          <Text style={styles.muted}>Expires {displayDate(offer.expires_at)}</Text>
          <Button disabled={loading || (apiDate(offer.expires_at)?.getTime() ?? 0) <= Date.now()} onPress={() => void run(() => accept(offer))}>Accept pickup</Button>
        </View>)}</>}
    </>)}
    {tab === 'earnings' && <>
      <View><Text style={styles.eyebrow}>DRIVER WALLET</Text><Text style={styles.title}>Earnings</Text></View>
      {wallet && <View style={styles.hero}><Text style={[styles.eyebrow, { color: '#8BE1D2' }]}>TOTAL EARNED</Text><Text style={styles.heroTitle}>${(wallet.total_earned_cents / 100).toFixed(2)}</Text><Text style={styles.heroBody}>Settled ${(wallet.total_settled_cents / 100).toFixed(2)} · Pending ${(wallet.pending_cents / 100).toFixed(2)}</Text></View>}
      <Text style={styles.heading}>Transactions</Text>
      {transactions.length === 0 && !loading && <Text style={styles.muted}>No transactions yet.</Text>}
      {transactions.map(item => <View key={item.transaction_id} style={styles.card}><Text style={styles.heading}>{pretty(item.kind)} · ${(item.amount_cents / 100).toFixed(2)}</Text><Text style={styles.muted}>{item.run_kind ? pretty(item.run_kind) + ' run #' + item.run_id : 'Adjustment'} · {displayDate(item.created_at)}</Text><Text style={styles.muted}>{item.settled_at ? 'Settled ' + displayDate(item.settled_at) : 'Pending settlement'}</Text></View>)}
      {transactionCursor && <Button secondary onPress={() => void run(async () => { const more = await request<{ items: Transaction[]; next_cursor: string | null }>('/driver/transactions?cursor=' + transactionCursor); setTransactions(previous => [...previous, ...more.items]); setTransactionCursor(more.next_cursor); })}>Load older</Button>}
    </>}
    {tab === 'profile' && <>
      <View><Text style={styles.eyebrow}>YOUR DRIVER ACCOUNT</Text><Text style={styles.title}>Driver profile</Text></View>
      {profile && !editingProfile && <><View style={styles.card}>
        <Text style={styles.heading}>{profile.name}</Text><Text style={styles.body}>Status: {pretty(profile.status)}</Text><Text style={styles.body}>Verification: {pretty(profile.verification_status)}</Text>
        {profile.email && <Text style={styles.muted}>{profile.email}</Text>}{profile.phone && <Text style={styles.muted}>{profile.phone}</Text>}
        {profile.approved_at && <Text style={styles.muted}>Approved {displayDate(profile.approved_at)}</Text>}
      </View><View style={styles.card}><Text style={styles.heading}>Vehicle</Text>
        {profile.vehicle ? <><Text style={styles.body}>{profile.vehicle.year ? `${profile.vehicle.year} ` : ''}{profile.vehicle.make} {profile.vehicle.model}</Text><Text style={styles.muted}>Plate {profile.vehicle.license_plate}{profile.vehicle.color ? ` · ${profile.vehicle.color}` : ''}</Text>
          {profile.vehicle.registration_expiry && <Text style={styles.muted}>Registration expires {profile.vehicle.registration_expiry}</Text>}
          {profile.vehicle.insurance_provider && <Text style={styles.muted}>Insurance: {profile.vehicle.insurance_provider}{profile.vehicle.insurance_expiry ? ` · Expires ${profile.vehicle.insurance_expiry}` : ''}</Text>}
        </> : <Text style={styles.muted}>No vehicle recorded.</Text>}
      </View><View style={styles.card}><Text style={styles.heading}>Driver details</Text>
        {!profile.license && !profile.address && !profile.emergency_contact && !profile.notes && <Text style={styles.muted}>Add your license, address and emergency contact in Edit profile.</Text>}
        {profile.license && <Text style={styles.body}>License {profile.license.state} · Expires {profile.license.expiry}</Text>}
        {profile.address && <Text style={styles.body}>{profile.address.line1}, {profile.address.city}, {profile.address.state} {profile.address.postal_code}</Text>}
        {profile.emergency_contact && <Text style={styles.muted}>Emergency contact: {profile.emergency_contact.name} · {profile.emergency_contact.phone}</Text>}
        {profile.notes && <Text style={styles.muted}>Notes: {profile.notes}</Text>}
      </View></>}
      {profile && !editingProfile && <Button onPress={beginProfileEdit}>Edit driver profile</Button>}
      {editingProfile && <>
        {profileSections.map(section => <View key={section.title} style={[styles.card, styles.formSection]}>
          <Pressable accessibilityRole="button" accessibilityState={{ expanded: expandedProfileSection === section.title }}
            onPress={() => setExpandedProfileSection(current => current === section.title ? null : section.title)}
            style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 48 }}>
            <Text style={styles.heading}>{section.title}</Text><Text style={styles.heading}>{expandedProfileSection === section.title ? '−' : '+'}</Text>
          </Pressable>
          {expandedProfileSection === section.title && <>
            {(section.title === 'Driver license' || section.title === 'Vehicle insurance') && <Text style={styles.muted}>Contact operations for review after changing these details. Verification does not update automatically.</Text>}
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', rowGap: 10, paddingBottom: 6 }}>
              {section.fields.map(field => <View key={field.key} style={{ width: width < 360 || fontScale > 1.2 || fullWidthFields.includes(field.key) ? '100%' : '48.5%' }}>
                <Field label={field.label} value={editingProfile[field.key]} disabled={loading} placeholder={field.placeholder}
                  keyboardType={field.keyboardType} onChangeText={value => setEditingProfile(current => current ? { ...current, [field.key]: value } : current)} />
              </View>)}
            </View>
          </>}
        </View>)}
        <View style={[styles.card, styles.formSection]}><Pressable accessibilityRole="button" accessibilityState={{ expanded: expandedProfileSection === 'Notes' }}
          onPress={() => setExpandedProfileSection(current => current === 'Notes' ? null : 'Notes')}
          style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 48 }}>
          <Text style={styles.heading}>Notes</Text><Text style={styles.heading}>{expandedProfileSection === 'Notes' ? '−' : '+'}</Text>
        </Pressable>{expandedProfileSection === 'Notes' && <TextInput accessibilityLabel="Additional notes" multiline editable={!loading}
          value={editingProfile.notes} onChangeText={value => setEditingProfile(current => current ? { ...current, notes: value } : current)}
          placeholder="Optional notes" style={[styles.field, { minHeight: 80, textAlignVertical: 'top' }]} />}</View>
      </>}
    </>}
  </ScrollView>{profileEditorOpen ? <View style={styles.formActions}>
    <Message error={error} />
    <View style={{ flexDirection: 'row', gap: 8 }}>
      <View style={{ flex: 1 }}><Button secondary disabled={loading} onPress={cancelProfileEdit}>Cancel</Button></View>
      <View style={{ flex: 2 }}><Button disabled={loading || !hasProfileChanges} onPress={() => void run(saveProfile)}>{loading ? 'Saving…' : 'Save changes'}</Button></View>
    </View>
  </View> : <Tabs items={[{ id: 'inbound', label: 'Driver in', symbol: '↓' }, { id: 'outbound', label: 'Driver out', symbol: '↑' }, { id: 'earnings', label: 'Earnings', symbol: '$' }, { id: 'profile', label: 'Profile', symbol: '◉' }]} selected={tab} onSelect={next => { setTab(next); setSelected(null); setScanIntent(null); }} />}</KeyboardAvoidingView>;
}
