import { useMemo } from 'react';
import { Text, View } from 'react-native';
import MapView, { Marker } from 'react-native-maps';
import type { Schemas } from './api';
import { colors, styles } from './ui';

type Location = Schemas['Location'];
type Position = { latitude: number; longitude: number; illustrative: boolean };

export function mapPosition(location: Location): Position | null {
  const position = location.map_position ?? (typeof location.latitude === 'number' && typeof location.longitude === 'number'
    ? { latitude: location.latitude, longitude: location.longitude, illustrative: false } : null);
  if (!position || !Number.isFinite(position.latitude) || !Number.isFinite(position.longitude)
    || Math.abs(position.latitude) > 90 || Math.abs(position.longitude) > 180) return null;
  return position;
}

export function LocationMap({ locations, focusedId, onSelect }: { locations: Location[]; focusedId: string | null; onSelect: (id: string) => void }) {
  const pins = useMemo(() => locations.flatMap(location => {
    const position = mapPosition(location);
    return position ? [{ location, position }] : [];
  }), [locations]);
  const focused = pins.find(pin => pin.location.id === focusedId);
  if (pins.length === 0) return <View style={styles.card}><Text style={styles.heading}>Map unavailable</Text><Text style={styles.muted}>These sites do not have coordinates yet. Use their listed addresses and instructions.</Text></View>;

  const latitudes = pins.map(pin => pin.position.latitude);
  const longitudes = pins.map(pin => pin.position.longitude);
  const south = Math.min(...latitudes), north = Math.max(...latitudes);
  const west = Math.min(...longitudes), east = Math.max(...longitudes);
  const region = focused ? {
    latitude: focused.position.latitude, longitude: focused.position.longitude, latitudeDelta: 0.016, longitudeDelta: 0.016,
  } : {
    latitude: (south + north) / 2, longitude: (west + east) / 2,
    latitudeDelta: Math.max(0.02, (north - south) * 1.5), longitudeDelta: Math.max(0.02, (east - west) * 1.5),
  };

  return <View style={{ gap: 8 }}>
    <MapView key={focusedId ?? 'all'} style={{ width: '100%', height: 340, borderRadius: 20 }} initialRegion={region}
      scrollEnabled={false} zoomEnabled={false} pitchEnabled={false} rotateEnabled={false} accessibilityLabel="Locker location map">
      {pins.map(({ location, position }) => <Marker key={location.id} coordinate={position}
        title={location.name} description={position.illustrative ? 'Illustrative test position; not a real locker address' : location.address.line1}
        pinColor={position.illustrative ? colors.orange : colors.blue} onPress={() => onSelect(location.id)} />)}
    </MapView>
    {pins.some(pin => pin.position.illustrative) && <Text style={styles.muted}>Orange pins are illustrative test positions. They must not be used for travel or parcel drop-off.</Text>}
  </View>;
}
