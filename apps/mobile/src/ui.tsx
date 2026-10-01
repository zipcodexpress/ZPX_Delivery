import type { PropsWithChildren } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

export const colors = { ink: '#102C38', blue: '#007B8A', muted: '#58717B', border: '#DCE8E9', paper: '#FFFFFF', canvas: '#F4F8F7', danger: '#B42318', navy: '#103845', mint: '#DDF5F0', orange: '#F5A344' };

export const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.canvas },
  content: { padding: 16, gap: 12, paddingBottom: 24 },
  title: { fontSize: 24, lineHeight: 29, fontWeight: '800', letterSpacing: -0.5, color: colors.ink },
  heading: { fontSize: 16, lineHeight: 22, fontWeight: '700', letterSpacing: -0.15, color: colors.ink },
  body: { fontSize: 15, lineHeight: 21, color: colors.ink },
  muted: { fontSize: 13, lineHeight: 18, color: colors.muted },
  eyebrow: { fontSize: 10, lineHeight: 14, letterSpacing: 1, textTransform: 'uppercase', fontWeight: '800', color: colors.blue },
  error: { fontSize: 14, lineHeight: 20, color: colors.danger },
  card: { backgroundColor: colors.paper, borderWidth: 1, borderColor: colors.border, borderRadius: 16, padding: 14, gap: 6 },
  formSection: { paddingVertical: 8, gap: 8 },
  fieldLabel: { fontSize: 12, lineHeight: 16, fontWeight: '600', color: colors.muted },
  formActions: { paddingHorizontal: 16, paddingVertical: 10, gap: 8, backgroundColor: colors.paper, borderTopWidth: 1, borderTopColor: colors.border },
  shipmentTab: { flex: 1, minHeight: 44, paddingHorizontal: 2, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: colors.border, borderRadius: 11, backgroundColor: colors.paper },
  shipmentTabActive: { backgroundColor: colors.blue, borderColor: colors.blue },
  shipmentNewDraft: { flex: 1.15, backgroundColor: colors.navy, borderColor: colors.navy },
  shipmentTabText: { color: colors.ink, fontSize: 11, fontWeight: '700' },
  shipmentTabTextActive: { color: colors.paper, fontSize: 11, fontWeight: '700' },
  shipmentRow: { minHeight: 74, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, paddingVertical: 8, backgroundColor: colors.paper, borderWidth: 1, borderColor: colors.border, borderRadius: 14 },
  shipmentRoute: { fontSize: 14, lineHeight: 19, fontWeight: '700', color: colors.ink },
  hero: { backgroundColor: colors.navy, borderRadius: 18, padding: 16, gap: 8, overflow: 'hidden' },
  heroTitle: { color: colors.paper, fontSize: 23, fontWeight: '800', letterSpacing: -0.4, lineHeight: 28 },
  heroBody: { color: '#CBE2E5', fontSize: 13, lineHeight: 19 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap' },
  field: { borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, paddingVertical: 10, minHeight: 48, fontSize: 14, lineHeight: 20, color: colors.ink, backgroundColor: colors.paper },
  button: { backgroundColor: colors.blue, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 10, alignItems: 'center', justifyContent: 'center', minHeight: 48 },
  secondary: { backgroundColor: colors.paper, borderWidth: 1, borderColor: colors.border },
  buttonText: { color: colors.paper, fontWeight: '700', fontSize: 13 },
  secondaryText: { color: colors.ink },
  nav: { flexDirection: 'row', backgroundColor: colors.paper, borderTopWidth: 1, borderTopColor: colors.border, paddingHorizontal: 8, paddingTop: 7, paddingBottom: 5 },
  navItem: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3, minHeight: 48, borderRadius: 12 },
  navText: { fontSize: 11, fontWeight: '700', color: colors.muted },
  navActiveText: { color: colors.blue },
});

export function Button({ children, onPress, secondary = false, disabled = false }: PropsWithChildren<{ onPress: () => void; secondary?: boolean; disabled?: boolean }>) {
  return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} style={[styles.button, secondary && styles.secondary, disabled && { opacity: 0.5 }]}>
    <Text style={[styles.buttonText, secondary && styles.secondaryText]}>{children}</Text>
  </Pressable>;
}

export function Field({ label, value, onChangeText, keyboardType, secureTextEntry, placeholder, disabled = false }: {
  label: string; value: string; onChangeText: (value: string) => void;
  keyboardType?: 'default' | 'email-address' | 'phone-pad' | 'numeric'; secureTextEntry?: boolean; placeholder?: string; disabled?: boolean;
}) {
  return <View style={{ gap: 4 }}><Text style={styles.fieldLabel}>{label}</Text><TextInput
    accessibilityLabel={label} autoCapitalize={keyboardType === 'email-address' || secureTextEntry ? 'none' : 'sentences'}
    keyboardType={keyboardType} secureTextEntry={secureTextEntry} value={value} editable={!disabled}
    onChangeText={onChangeText} placeholder={placeholder} style={styles.field}
  /></View>;
}

export function Message({ error }: { error: string }) { return error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null; }

export function Tabs<T extends string>({ items, selected, onSelect }: { items: { id: T; label: string; symbol: string }[]; selected: T; onSelect: (id: T) => void }) {
  return <View style={styles.nav}>{items.map(item => <Pressable key={item.id} accessibilityRole="tab" accessibilityState={{ selected: selected === item.id }} accessibilityLabel={item.label}
    onPress={() => onSelect(item.id)} style={styles.navItem}>
    <Text style={[styles.navText, selected === item.id && styles.navActiveText, { fontSize: 19 }]}>{item.symbol}</Text>
    <Text style={[styles.navText, selected === item.id && styles.navActiveText]}>{item.label}</Text>
  </Pressable>)}</View>;
}

export function pretty(value: string) { return value.replaceAll('_', ' ').toLowerCase().replace(/^./, letter => letter.toUpperCase()); }
