import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { apiBase, onSessionExpired, restore, signIn, signOut, type Profile } from './api';
import { Button, colors, Field, Message, styles } from './ui';
import { Customer } from './Customer';
import { Driver } from './Driver';

type Role = 'CUSTOMER' | 'DRIVER';

export default function App() {
  const [profile, setProfile] = useState<Profile | null>(null);
  const [role, setRole] = useState<Role>('CUSTOMER');
  const [loading, setLoading] = useState(true);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    onSessionExpired(() => { setProfile(null); setError('Your session expired. Please sign in again.'); });
    void restore().then(p => {
      setProfile(p);
      if (p && !p.roles.includes('CUSTOMER') && p.roles.includes('DRIVER')) setRole('DRIVER');
    }).catch(e => setError(e instanceof Error ? e.message : 'Could not restore your session.')).finally(() => setLoading(false));
    return () => onSessionExpired(() => {});
  }, []);

  async function login() {
    setLoading(true); setError('');
    try {
      const p = await signIn(email, password);
      setProfile(p);
      setPassword('');
      setRole(p.roles.includes('CUSTOMER') ? 'CUSTOMER' : 'DRIVER');
    } catch (e) { setError(e instanceof Error ? e.message : 'Sign-in failed.'); }
    finally { setLoading(false); }
  }

  async function logout() {
    setLoading(true);
    try { await signOut(); }
    catch { /* The local token has still been deleted. */ }
    finally { setProfile(null); setLoading(false); }
  }

  return <SafeAreaProvider><SafeAreaView style={[styles.page, { flex: 1 }]}>
    {loading ? <View style={[styles.content, { flex: 1, justifyContent: 'center' }]}><ActivityIndicator /><Text style={styles.muted}>Connecting to Delivery…</Text></View>
      : !profile ? <ScrollView contentContainerStyle={[styles.content, { flexGrow: 1, justifyContent: 'center' }]} keyboardShouldPersistTaps="handled">
        <View style={styles.hero}>
          <View style={{ width: 52, height: 52, borderRadius: 16, backgroundColor: colors.orange, alignItems: 'center', justifyContent: 'center' }}><Text style={{ fontSize: 28, fontWeight: '900', color: colors.navy }}>Z</Text></View>
          <Text style={[styles.eyebrow, { color: '#8BE1D2' }]}>ZIPCODEXPRESS</Text>
          <Text style={styles.heroTitle}>Every delivery, in your hands.</Text>
          <Text style={styles.heroBody}>Send, track and receive parcels. Drivers can manage inbound and outbound work in the same app.</Text>
        </View>
        <View style={[styles.card, { padding: 22, gap: 16 }]}>
          <View><Text style={styles.heading}>Welcome back</Text><Text style={styles.muted}>Sign in to continue your journey.</Text></View>
          <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" />
          <Field label="Password" value={password} onChangeText={setPassword} secureTextEntry />
          <Message error={error} />
          <Button onPress={() => void login()} disabled={!email.trim() || !password}>Sign in</Button>
        </View>
        {__DEV__ && <Text style={[styles.muted, { textAlign: 'center' }]}>Development API: {apiBase || 'Not configured'}</Text>}
      </ScrollView>
      : <View style={[styles.page, { flex: 1 }]}>
        <View style={{ backgroundColor: colors.navy, paddingHorizontal: 16, paddingVertical: 8, gap: 6 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
            <View style={{ width: 30, height: 30, borderRadius: 9, backgroundColor: colors.orange, alignItems: 'center', justifyContent: 'center' }}><Text style={{ fontSize: 18, fontWeight: '900', color: colors.navy }}>Z</Text></View>
            <View style={{ flex: 1, minWidth: 0 }}><Text numberOfLines={1} style={{ color: colors.paper, fontSize: 18, fontWeight: '800', letterSpacing: -0.3 }}>ZipcodeXpress</Text>
              <Text style={{ color: '#CBE2E5', fontSize: 11, lineHeight: 16 }}>{role === 'DRIVER' ? 'Driver workspace' : 'Customer workspace'}</Text></View>
            <Pressable accessibilityRole="button" onPress={() => void logout()} style={{ minHeight: 48, justifyContent: 'center', paddingHorizontal: 10 }}>
              <Text style={{ color: colors.paper, fontSize: 12, fontWeight: '600' }}>Sign out</Text>
            </Pressable>
          </View>
          {profile.roles.includes('CUSTOMER') && profile.roles.includes('DRIVER') && <View style={styles.row}>
              <Button secondary={role !== 'CUSTOMER'} onPress={() => setRole('CUSTOMER')}>Customer</Button>
              <Button secondary={role !== 'DRIVER'} onPress={() => setRole('DRIVER')}>Driver</Button>
          </View>}
        </View>
        {role === 'CUSTOMER' && profile.roles.includes('CUSTOMER') ? <Customer profile={profile} onProfile={setProfile} />
          : role === 'DRIVER' && profile.roles.includes('DRIVER') ? <Driver onNameChange={name => setProfile(current => current ? { ...current, name } : current)} />
          : <View style={styles.content}><Text style={styles.body}>This account has no customer or approved driver role.</Text></View>}
      </View>}
  </SafeAreaView></SafeAreaProvider>;
}
