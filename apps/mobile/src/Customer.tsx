import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Linking, Platform, Pressable, RefreshControl, ScrollView, Text, View, useWindowDimensions } from 'react-native';
import * as Crypto from 'expo-crypto';
import { command, request, type Profile, type Schemas } from './api';
import { displayDate } from './dates';
import { LocationMap, mapPosition } from './LocationMap';
import { Locker } from './Locker';
import { Button, colors, Field, Message, pretty, styles, Tabs } from './ui';

type Shipment = Schemas['Shipment'];
type Location = Schemas['Location'];
type ViewMode = 'sending' | 'receiving' | 'history';
type Tab = 'home' | 'shipments' | 'locations' | 'account';
const initialForm = { name: '', email: '', phone: '', line1: '', city: '', region: 'TX', postal_code: '', width_mm: '', height_mm: '', depth_mm: '', weight_g: '' };

function locationAddress(location: Location) {
  const cityLine = [location.address.city, location.address.region, location.address.postal_code].filter(Boolean).join(' ');
  return [location.address.line1, cityLine].filter(Boolean).join(', ');
}

function LocationPicker({ label, locations, value, onChange }: { label: string; locations: Location[]; value: string; onChange: (id: string) => void }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const chosen = locations.find(location => location.id === value);
  const matches = locations.filter(location => (location.name + ' ' + location.address.line1).toLowerCase().includes(query.toLowerCase())).slice(0, 6);
  return <View style={{ gap: 8 }}>
    <Text style={styles.eyebrow}>{label}</Text>
    <Button secondary onPress={() => setOpen(!open)}>{chosen ? chosen.name + ' · Change' : 'Choose a location'}</Button>
    {open && <View style={styles.card}>
      <Field label={`Search ${label.toLowerCase()}`} value={query} onChangeText={setQuery} placeholder="Site name or address" />
      {matches.map(location => <Button key={location.id} secondary onPress={() => { onChange(location.id); setOpen(false); setQuery(''); }}>{location.name}</Button>)}
      {matches.length === 0 && <Text style={styles.muted}>No matching sites.</Text>}
    </View>}
  </View>;
}

export function Customer({ profile, onProfile }: { profile: Profile; onProfile: (profile: Profile) => void }) {
  const [lockerOpen, setLockerOpen] = useState(false);
  const [tab, setTab] = useState<Tab>('home');
  const [view, setView] = useState<ViewMode>('sending');
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [locations, setLocations] = useState<Location[]>([]);
  const [locationView, setLocationView] = useState<'map' | 'list'>('map');
  const [locationQuery, setLocationQuery] = useState('');
  const [focusedLocationId, setFocusedLocationId] = useState<string | null>(null);
  const [sizeClasses, setSizeClasses] = useState<Schemas['SizeClass'][]>([]);
  const [selected, setSelected] = useState<Shipment | null>(null);
  const [tracking, setTracking] = useState<Schemas['Tracking'] | null>(null);
  const [creating, setCreating] = useState(false);
  const [draftStep, setDraftStep] = useState(0);
  const [origin, setOrigin] = useState('');
  const [destination, setDestination] = useState('');
  const [size, setSize] = useState<'SMALL' | 'MEDIUM' | 'LARGE'>('SMALL');
  const [form, setForm] = useState(initialForm);
  const [self, setSelf] = useState(false);
  const [loading, setLoading] = useState(false);
  const [refreshingShipments, setRefreshingShipments] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [accountView, setAccountView] = useState<'profile' | 'payments'>('profile');
  const [paymentMethods, setPaymentMethods] = useState<Schemas['WalletMethods'] | null>(null);
  const [paymentHistory, setPaymentHistory] = useState<Schemas['WalletHistory'] | null>(null);
  const [profileEdit, setProfileEdit] = useState(false);
  const [profileForm, setProfileForm] = useState(() => ({ name: profile.name, line1: profile.addresses[0]?.line1 ?? '', city: profile.addresses[0]?.city ?? '', region: profile.addresses[0]?.region ?? '', postal_code: profile.addresses[0]?.postal_code ?? '' }));
  const draftKey = useRef(Crypto.randomUUID());
  const { width, fontScale } = useWindowDimensions();
  const profileEditorOpen = tab === 'account' && accountView === 'profile' && profileEdit;
  const savedProfileForm = { name: profile.name, line1: profile.addresses[0]?.line1 ?? '', city: profile.addresses[0]?.city ?? '', region: profile.addresses[0]?.region ?? '', postal_code: profile.addresses[0]?.postal_code ?? '' };
  const hasProfileChanges = (Object.keys(profileForm) as (keyof typeof profileForm)[]).some(key => profileForm[key].trim() !== savedProfileForm[key]);
  const pairProfileFields = width >= 360 && fontScale <= 1.2;

  function cancelProfileEdit() { setProfileEdit(false); setError(''); }

  async function loadShipments(next?: string, mode: ViewMode = view) {
    const query = 'view=' + mode + (next ? '&cursor=' + encodeURIComponent(next) : '');
    const result = await request<Schemas['ShipmentList']>('/shipments?' + query);
    setShipments(previous => next ? [...previous, ...result.items] : result.items);
    setCursor(result.next_cursor ?? null);
  }

  async function loadLocations() {
    const result = await request<Schemas['LocationList']>('/locations');
    setLocations(result.items);
    setSizeClasses(result.size_policy.classes);
  }

  async function loadPayments() {
    const [methods, history] = await Promise.all([
      request<Schemas['WalletMethods']>('/me/payment-methods'),
      request<Schemas['WalletHistory']>('/me/payments'),
    ]);
    setPaymentMethods(methods); setPaymentHistory(history);
  }

  async function saveProfile() {
    if (!profileForm.name.trim()) throw new Error('Enter your full name.');
    const updated = await request<Profile>('/me/profile', { method: 'POST', body: JSON.stringify({
      name: profileForm.name.trim(),
      address: { line1: profileForm.line1.trim(), city: profileForm.city.trim(), region: profileForm.region.trim(), postal_code: profileForm.postal_code.trim(), country_code: 'US' },
    }) });
    onProfile(updated); setProfileEdit(false); setNotice('Profile saved.');
  }

  useEffect(() => {
    let active = true;
    setLoading(true); setError(''); setSelected(null); setTracking(null);
    void request<Schemas['ShipmentList']>('/shipments?view=' + view).then(result => {
      if (active) { setShipments(result.items); setCursor(result.next_cursor ?? null); }
    }).catch(e => { if (active) setError(e instanceof Error ? e.message : 'Could not load shipments.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [view]);

  useEffect(() => {
    void loadLocations().catch(e => setError(e instanceof Error ? e.message : 'Could not load locations.'));
  }, []);

  useEffect(() => {
    if (tab === 'account' && accountView === 'payments') void loadPayments().catch(e => setError(e instanceof Error ? e.message : 'Could not load payments.'));
  }, [tab, accountView]);

  async function run(action: () => Promise<void>) {
    setLoading(true); setError(''); setNotice('');
    try { await action(); }
    catch (e) { setError(e instanceof Error ? e.message : 'Please try again.'); }
    finally { setLoading(false); }
  }

  async function refreshShipmentList() {
    setRefreshingShipments(true); setError('');
    try { await loadShipments(undefined, view); }
    catch (e) { setError(e instanceof Error ? e.message : 'Could not refresh shipments.'); }
    finally { setRefreshingShipments(false); }
  }

  async function open(shipment: Shipment) {
    const [detail, timeline] = await Promise.all([
      request<Shipment>('/shipments/' + shipment.shipment_id),
      request<Schemas['Tracking']>('/shipments/' + shipment.shipment_id + '/tracking'),
    ]);
    setSelected(detail); setTracking(timeline); setCreating(false);
  }

  function update(field: keyof typeof form, value: string) {
    setForm(previous => ({ ...previous, [field]: value }));
    draftKey.current = Crypto.randomUUID();
  }

  async function create() {
    if (!origin || !destination || origin === destination) throw new Error('Choose different origin and destination locations.');
    const address = self ? profile.addresses[0] : {
      line1: form.line1.trim(), city: form.city.trim(), region: form.region.trim(),
      postal_code: form.postal_code.trim(), country_code: 'US',
    };
    if (!address) throw new Error('Add a profile address before sending to yourself.');
    const recipient = self ? { name: profile.name, email: profile.email, phone: profile.phone, address }
      : { name: form.name.trim(), email: form.email.trim(), phone: form.phone.trim(), address };
    if (!recipient.name || !recipient.email || !recipient.phone || !address.line1 || !address.city || !address.region || !address.postal_code) {
      throw new Error('Complete the recipient and address fields.');
    }
    const dimensions = [form.width_mm, form.height_mm, form.depth_mm, form.weight_g].map(Number);
    if (dimensions.some(value => !Number.isInteger(value) || value <= 0)) throw new Error('Enter positive whole-number parcel measurements.');
    const shipment = await command<Shipment>('/shipments', {
      origin_location_id: origin, destination_location_id: destination, service_level: 'STANDARD', recipient,
      package: { size_class: size, width_mm: dimensions[0], height_mm: dimensions[1], depth_mm: dimensions[2], weight_g: dimensions[3] },
    }, undefined, draftKey.current);
    draftKey.current = Crypto.randomUUID();
    setCreating(false); setDraftStep(0); setView('sending'); await loadShipments(undefined, 'sending'); await open(shipment);
    setNotice('Draft saved. Your parcel has not been deposited.');
  }

  const field = (name: keyof typeof form, label: string, keyboardType?: 'default' | 'email-address' | 'phone-pad' | 'numeric') =>
    <Field label={label} value={form[name]} onChangeText={value => update(name, value)} keyboardType={keyboardType} />;

  const filteredLocations = locations.filter(location => (location.name + ' ' + location.address.line1 + ' ' + location.address.city).toLowerCase().includes(locationQuery.toLowerCase()));
  const focusedLocation = locations.find(location => location.id === focusedLocationId) ?? null;

  async function directions(location: Location) {
    const position = mapPosition(location);
    if (!position || position.illustrative) throw new Error('Directions require a verified site position.');
    const destination = encodeURIComponent(`${position.latitude},${position.longitude}`);
    const url = Platform.OS === 'ios' ? `https://maps.apple.com/?daddr=${destination}` : `https://www.google.com/maps/dir/?api=1&destination=${destination}`;
    await Linking.openURL(url);
  }

  function nextDraftStep() {
    if (draftStep === 0 && (!origin || !destination || origin === destination)) { setError('Choose different origin and destination locations.'); return; }
    if (draftStep === 1 && [form.width_mm, form.height_mm, form.depth_mm, form.weight_g].map(Number).some(value => !Number.isInteger(value) || value <= 0)) { setError('Enter positive whole-number parcel measurements.'); return; }
    setError(''); setDraftStep(step => step + 1);
  }

  if (lockerOpen) return <Locker role="customer" onClose={() => { setLockerOpen(false); void loadShipments().catch(e => setError(e.message)); }} />;
  return <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.page}><ScrollView style={styles.page}
    contentContainerStyle={[styles.content, tab === 'shipments' && !creating && !selected && { gap: 10, paddingTop: 12 }]}
    keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" refreshControl={tab === 'shipments' && !creating && !selected
      ? <RefreshControl refreshing={refreshingShipments} onRefresh={() => void refreshShipmentList()} /> : undefined}>
    {!profileEditorOpen && <Message error={error} />}{notice ? <Text style={styles.body}>{notice}</Text> : null}
    {loading && <ActivityIndicator />}
    {!profileEditorOpen && <Button secondary onPress={() => setLockerOpen(true)}>Use locker · Scan terminal QR</Button>}
    {tab === 'home' && <>
      <View style={styles.hero}>
        <Text style={[styles.eyebrow, { color: '#8BE1D2' }]}>YOUR DELIVERY HUB</Text>
        <Text style={styles.heroTitle}>Good to see you, {profile.name.split(' ')[0]}.</Text>
        <Text style={styles.heroBody}>Plan your next shipment or follow every parcel from the first scan to pickup.</Text>
        <View style={[styles.row, { marginTop: 8 }]}><Button onPress={() => { setCreating(true); setDraftStep(0); setTab('shipments'); }}>Send a parcel</Button><Button secondary onPress={() => { setCreating(false); setTab('shipments'); }}>Track shipments</Button></View>
      </View>
      <View style={styles.row}>
        <View style={[styles.card, { flex: 1, minWidth: 140 }]}><Text style={styles.eyebrow}>SHIPMENTS</Text><Text style={styles.title}>{shipments.length}</Text><Text style={styles.muted}>Recent sending</Text></View>
        <View style={[styles.card, { flex: 1, minWidth: 140 }]}><Text style={styles.eyebrow}>NEARBY SITES</Text><Text style={styles.title}>{locations.length}</Text><Text style={styles.muted}>Planning locations</Text></View>
      </View>
      <Text style={styles.heading}>Latest shipments</Text>
      {shipments.length === 0 && <Text style={styles.muted}>Your shipments will appear here.</Text>}
      <View style={{ gap: 7 }}>{shipments.slice(0, 3).map(shipment => <Pressable key={shipment.shipment_id} accessibilityRole="button"
        accessibilityLabel={`Track ${shipment.public_reference}, ${shipment.origin_name} to ${shipment.destination_name}, ${pretty(shipment.journey_status)}`}
        onPress={() => void run(async () => { await open(shipment); setTab('shipments'); })} style={styles.shipmentRow}>
        <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
          <Text numberOfLines={1} style={styles.eyebrow}>{pretty(shipment.journey_status)}</Text>
          <Text numberOfLines={1} style={styles.shipmentRoute}>{shipment.origin_name} → {shipment.destination_name}</Text>
          <Text numberOfLines={1} style={styles.muted}>{shipment.public_reference}</Text>
        </View><Text style={{ color: colors.blue, fontSize: 20 }}>›</Text>
      </Pressable>)}</View>
    </>}
    {tab === 'account' && <>
      <View><Text style={styles.eyebrow}>YOUR ACCOUNT</Text><Text style={styles.title}>{profileEditorOpen ? 'Edit profile' : 'Profile & payments'}</Text></View>
      {!profileEditorOpen && <View style={styles.row}><Button secondary={accountView !== 'profile'} onPress={() => setAccountView('profile')}>Profile</Button><Button secondary={accountView !== 'payments'} onPress={() => setAccountView('payments')}>Payments</Button></View>}
      {accountView === 'profile' && <>
        {!profileEdit && <>
        <View style={styles.card}>
          <Text style={styles.heading}>{profile.name}</Text>
          <Text style={styles.muted}>Customer ID {profile.user_id}</Text>
          <Text style={styles.fieldLabel}>Email · {profile.email_verified ? 'Verified' : 'Unverified'}</Text><Text style={styles.body}>{profile.email || 'Not provided'}</Text>
          <Text style={styles.fieldLabel}>Phone · {profile.phone_verified ? 'Verified' : 'Unverified'}</Text><Text style={styles.body}>{profile.phone || 'Not provided'}</Text>
          {(!profile.email_verified || !profile.phone_verified) && <Text style={styles.muted}>Verify both contacts in the customer website before shipping.</Text>}
        </View>
        <View style={styles.card}>
          <Text style={styles.heading}>Saved addresses</Text>
          {profile.addresses.length === 0 && <Text style={styles.muted}>No address saved yet.</Text>}
          {profile.addresses.map((address, index) => <View key={index} style={{ paddingVertical: 6 }}><Text style={styles.eyebrow}>{index === 0 ? 'PRIMARY' : `ADDRESS ${index + 1}`}</Text><Text style={styles.body}>{address.line1}, {address.city}, {address.region} {address.postal_code}</Text></View>)}
        </View>
        </>}
        {profileEdit ? <View style={styles.card}>
          <Text style={styles.heading}>Personal details & primary address</Text>
          <Field disabled={loading} label="Full name" value={profileForm.name} onChangeText={name => setProfileForm(previous => ({ ...previous, name }))} />
          <Field disabled={loading} label="Address line 1" value={profileForm.line1} onChangeText={line1 => setProfileForm(previous => ({ ...previous, line1 }))} />
          <Field disabled={loading} label="City" value={profileForm.city} onChangeText={city => setProfileForm(previous => ({ ...previous, city }))} />
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
            <View style={{ width: pairProfileFields ? '48.5%' : '100%' }}><Field disabled={loading} label="State" value={profileForm.region} onChangeText={region => setProfileForm(previous => ({ ...previous, region }))} /></View>
            <View style={{ width: pairProfileFields ? '48.5%' : '100%' }}><Field disabled={loading} label="ZIP code" value={profileForm.postal_code} onChangeText={postal_code => setProfileForm(previous => ({ ...previous, postal_code }))} /></View>
          </View>
        </View> : <Button secondary onPress={() => { setProfileForm(savedProfileForm); setError(''); setNotice(''); setProfileEdit(true); }}>Edit profile & primary address</Button>}
      </>}
      {accountView === 'payments' && <>
        <View style={styles.card}>
          <Text style={styles.heading}>Payment methods</Text>
          <Text style={styles.muted}>Only card brand and last four digits are shown. Card details stay with the payment provider.</Text>
          {paymentMethods?.items.map((method, index) => <Text key={index} style={styles.body}>{method.brand} ending in {method.last4}</Text>)}
          {paymentMethods && paymentMethods.items.length === 0 && <Text style={styles.body}>No saved payment method.</Text>}
          {paymentMethods && !paymentMethods.configured && <Text style={styles.muted}>Sandbox card management is not configured for this environment.</Text>}
        </View>
        <View style={styles.card}><Text style={styles.heading}>Payment history</Text>
          {paymentHistory?.items.map(item => <View key={item.payment_id} style={{ borderTopWidth: 1, borderTopColor: colors.border, paddingVertical: 9 }}>
            <Text style={styles.body}>{item.shipment_reference}</Text><Text style={styles.muted}>{pretty(item.status)} · ${(item.amount_cents / 100).toFixed(2)} {item.currency} · {displayDate(item.created_at)}</Text>
          </View>)}
          {paymentHistory && paymentHistory.items.length === 0 && <Text style={styles.muted}>No payments yet.</Text>}
          {paymentHistory?.next_cursor && <Button secondary onPress={() => void run(async () => { const more = await request<Schemas['WalletHistory']>('/me/payments?cursor=' + paymentHistory.next_cursor); setPaymentHistory({ items: [...paymentHistory.items, ...more.items], next_cursor: more.next_cursor }); })}>Load older payments</Button>}
        </View>
        <Button secondary onPress={() => void run(loadPayments)}>Refresh payments</Button>
      </>}
    </>}
    {tab === 'locations' && <>
      <View style={styles.hero}><Text style={[styles.eyebrow, { color: '#8BE1D2' }]}>FIND A SITE</Text><Text style={styles.heroTitle}>Locations near your route</Text><Text style={styles.heroBody}>Explore pickup and drop-off sites before planning a shipment. A listed site does not confirm an available door.</Text></View>
      <Field label="Search locations" value={locationQuery} onChangeText={value => { setLocationQuery(value); setFocusedLocationId(null); }} placeholder="Site name, street or city" />
      <View style={styles.row}><Button secondary={locationView !== 'map'} onPress={() => setLocationView('map')}>Map</Button><Button secondary={locationView !== 'list'} onPress={() => setLocationView('list')}>List</Button><Button secondary onPress={() => void run(loadLocations)}>Refresh</Button></View>
      {locationView === 'map' && <>
        {focusedLocation && <Button secondary onPress={() => setFocusedLocationId(null)}>← Show all locations</Button>}
        {focusedLocation && <View style={styles.card}>
          <Text style={styles.eyebrow}>{focusedLocation.development_only ? 'DEVELOPMENT SITE' : focusedLocation.eligible ? 'ELIGIBLE SITE' : 'PLANNING ONLY'}</Text>
          <Text style={styles.heading}>{focusedLocation.name}</Text>
          <Text style={styles.body}>{locationAddress(focusedLocation)}</Text>
          <Text style={styles.muted}>{focusedLocation.access_instructions}</Text>
          <Text style={styles.muted}>{focusedLocation.printer_available ? 'Printer available' : 'Bring a printed label'}</Text>
          {mapPosition(focusedLocation)?.illustrative ? <Text style={styles.muted}>This pin is illustrative and is not a real locker address.</Text>
            : mapPosition(focusedLocation) && <Button onPress={() => void run(() => directions(focusedLocation))}>Get directions</Button>}
        </View>}
        <LocationMap locations={filteredLocations} focusedId={focusedLocationId} onSelect={setFocusedLocationId} />
        {!focusedLocation && <Text style={styles.muted}>Tap a pin to view site details, or switch to the list for accessible addresses and instructions.</Text>}
      </>}
      {locationView === 'list' && filteredLocations.map(location => <View key={location.id} style={styles.card}>
        <Text style={styles.eyebrow}>{location.development_only ? 'DEVELOPMENT SITE' : location.eligible ? 'ELIGIBLE SITE' : 'PLANNING ONLY'}</Text>
        <Text style={styles.heading}>{location.name}</Text>
        <Text style={styles.body}>{locationAddress(location)}</Text>
        <Text style={styles.muted}>{location.access_instructions}</Text>
        <Text style={styles.muted}>{location.printer_available ? 'Printer available' : 'Bring a printed label'}</Text>
        {mapPosition(location) ? <Button secondary onPress={() => { setFocusedLocationId(location.id); setLocationView('map'); }}>Show on map</Button> : <Text style={styles.muted}>Map position not available yet.</Text>}
      </View>)}
      {filteredLocations.length === 0 && <Text style={styles.muted}>No locations match your search.</Text>}
    </>}
    {tab === 'shipments' && <>
      <View><Text style={styles.eyebrow}>YOUR PARCELS</Text><Text style={styles.title}>Shipments</Text></View>
      {!creating && !selected && <View style={{ flexDirection: 'row', gap: 5 }}>
        {(['sending', 'receiving', 'history'] as const).map(mode => <Pressable key={mode} accessibilityRole="button"
          accessibilityState={{ selected: view === mode }} onPress={() => setView(mode)}
          style={[styles.shipmentTab, view === mode && styles.shipmentTabActive]}>
          <Text numberOfLines={1} style={[styles.shipmentTabText, view === mode && styles.shipmentTabTextActive]}>{pretty(mode)}</Text>
        </Pressable>)}
        <Pressable accessibilityRole="button" onPress={() => { setCreating(true); setDraftStep(0); setSelected(null); }}
          style={[styles.shipmentTab, styles.shipmentNewDraft]}><Text numberOfLines={1} style={styles.shipmentTabTextActive}>New draft</Text></Pressable>
      </View>}
      {creating ? <View style={styles.card}>
        <Text style={styles.heading}>Create shipment draft</Text>
        <Text style={styles.muted}>This does not reserve a locker or take payment.</Text>
        <Text style={styles.eyebrow}>STEP {draftStep + 1} OF 3 · {['ROUTE', 'PARCEL', 'RECIPIENT'][draftStep]}</Text>
        {draftStep === 0 && <>
        <LocationPicker label="Origin" locations={locations} value={origin} onChange={id => { setOrigin(id); draftKey.current = Crypto.randomUUID(); }} />
        <LocationPicker label="Destination" locations={locations} value={destination} onChange={id => { setDestination(id); draftKey.current = Crypto.randomUUID(); }} />
        </>}
        {draftStep === 1 && <>
        <Text style={styles.body}>Parcel size</Text>
        <View style={styles.row}>{sizeClasses.map(item => <Button key={item.code} secondary={size !== item.code} onPress={() => { setSize(item.code); draftKey.current = Crypto.randomUUID(); }}>{pretty(item.code)}</Button>)}</View>
        <Text style={styles.muted}>Measure your packed parcel in millimeters and grams.</Text>
        {field('width_mm', 'Width (mm)', 'numeric')}{field('height_mm', 'Height (mm)', 'numeric')}
        {field('depth_mm', 'Depth (mm)', 'numeric')}{field('weight_g', 'Weight (g)', 'numeric')}
        </>}
        {draftStep === 2 && <>
        <Button secondary={!self} onPress={() => { setSelf(!self); draftKey.current = Crypto.randomUUID(); }}>Send to myself: {self ? 'Yes' : 'No'}</Button>
        {!self && <>
          {field('name', 'Recipient name')}{field('email', 'Recipient email', 'email-address')}{field('phone', 'Recipient phone', 'phone-pad')}
          {field('line1', 'Address line 1')}{field('city', 'City')}{field('region', 'State')}{field('postal_code', 'ZIP code')}
        </>}
        </>}
        {draftStep < 2 ? <Button onPress={nextDraftStep}>Continue</Button> : <Button disabled={loading || !profile.email_verified || !profile.phone_verified} onPress={() => void run(create)}>Save draft</Button>}
        <View style={styles.row}>{draftStep > 0 && <Button secondary onPress={() => { setError(''); setDraftStep(step => step - 1); }}>Back</Button>}<Button secondary onPress={() => { setError(''); setCreating(false); }}>Cancel</Button></View>
      </View> : selected ? <View style={{ gap: 16 }}>
        <Button secondary onPress={() => { setSelected(null); setTracking(null); }}>Back to list</Button>
        <View style={styles.hero}><Text style={[styles.eyebrow, { color: '#8BE1D2' }]}>{pretty(selected.journey_status)}</Text><Text numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.75} style={[styles.heroTitle, { fontSize: 21, lineHeight: 27 }]}>{selected.origin_name} → {selected.destination_name}</Text><Text style={styles.heroBody}>{selected.public_reference}</Text></View>
        <View style={styles.card}><Text style={styles.heading}>Shipment details</Text>
          <Text style={styles.body}>Order: {pretty(selected.order_status)} · Payment: {pretty(selected.payment_status)}</Text>
          <Text style={styles.body}>Parcel: {pretty(selected.package_state)}</Text>
          <Text style={styles.muted}>Shipping ID: {selected.si || 'Not assigned'}</Text>
          {selected.package_state === 'CREATED' && <Text style={styles.muted}>The parcel remains with the sender; no deposit is recorded.</Text>}
        </View>
        <View style={styles.card}><Text style={styles.heading}>Tracking timeline</Text>
          {tracking?.milestones.length ? tracking.milestones.map((event, index) => <View key={`${event.code}-${index}`} style={{ borderLeftWidth: 2, borderLeftColor: colors.blue, paddingLeft: 12, paddingVertical: 7 }}>
            <Text style={styles.body}>{pretty(event.code)}</Text><Text style={styles.muted}>{displayDate(event.occurred_at)}</Text>
          </View>) : <Text style={styles.muted}>No events yet.</Text>}
        </View>
        <Button secondary onPress={() => void run(() => open(selected))}>Refresh tracking</Button>
      </View> : <>
        <Text style={[styles.heading, { fontSize: 16 }]}>{pretty(view)} shipments</Text>
        {shipments.length === 0 && !loading && <Text style={styles.muted}>No shipments in this view yet.</Text>}
        <View style={{ gap: 7 }}>{shipments.map(shipment => <Pressable key={shipment.shipment_id} accessibilityRole="button"
          accessibilityLabel={`${shipment.origin_name} to ${shipment.destination_name}, ${pretty(shipment.journey_status)}, ${shipment.public_reference}. View tracking`}
          onPress={() => void run(() => open(shipment))} style={styles.shipmentRow}>
          <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
            <Text numberOfLines={1} style={styles.eyebrow}>{pretty(shipment.journey_status)}{shipment.package_state !== shipment.journey_status ? ` · ${pretty(shipment.package_state)}` : ''}</Text>
            <Text numberOfLines={1} style={styles.shipmentRoute}>{shipment.origin_name} → {shipment.destination_name}</Text>
            <Text numberOfLines={1} style={styles.muted}>{shipment.public_reference}</Text>
          </View><Text style={{ color: colors.blue, fontSize: 20 }}>›</Text>
        </Pressable>)}</View>
        {cursor && <Button secondary onPress={() => void run(() => loadShipments(cursor))}>Load older</Button>}
      </>}
    </>}
  </ScrollView>{profileEditorOpen ? <View style={styles.formActions}>
    <Message error={error} />
    <View style={{ flexDirection: 'row', gap: 8 }}>
      <View style={{ flex: 1 }}><Button secondary disabled={loading} onPress={cancelProfileEdit}>Cancel</Button></View>
      <View style={{ flex: 2 }}><Button disabled={loading || !hasProfileChanges} onPress={() => void run(saveProfile)}>{loading ? 'Saving…' : 'Save changes'}</Button></View>
    </View>
  </View> : <Tabs items={[{ id: 'home', label: 'Home', symbol: '⌂' }, { id: 'shipments', label: 'Shipments', symbol: '▣' }, { id: 'locations', label: 'Locations', symbol: '⌖' }, { id: 'account', label: 'Account', symbol: '◉' }]} selected={tab} onSelect={setTab} />}</KeyboardAvoidingView>;
}
