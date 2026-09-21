import { useEffect, useRef, useState } from 'react';
import * as L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import type { components } from '../contracts/generated/api';
type Location = components['schemas']['Location'];
export function LockerMap({ locations, origin, destination, onSelect }: { locations: Location[]; origin?: string; destination?: string; onSelect?: (id: string, side: 'origin' | 'destination') => void }) {
  const host = useRef<HTMLDivElement>(null);
  const [side, setSide] = useState<'origin' | 'destination'>('origin');
  const [query, setQuery] = useState('');
  const [focused, setFocused] = useState<Location | null>(null);
  const [tilesFailed, setTilesFailed] = useState(false);
  const matches = locations.filter(l => `${l.name} ${l.code} ${l.address.line1}`.toLowerCase().includes(query.toLowerCase()));
  useEffect(() => {
    if (!host.current) return;
    const map = L.map(host.current, { scrollWheelZoom: false }).setView([30.2672, -97.7431], 11);
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
    tiles.on('tileerror', () => setTilesFailed(true));
    const bounds: L.LatLngTuple[] = [];
    for (const l of matches) {
      const p = l.map_position; if (!p) continue;
      const chosen = l.id === origin ? 'A' : l.id === destination ? 'B' : '•';
      const icon = L.divIcon({ className: 'locker-pin ' + (chosen === 'A' ? 'from' : chosen === 'B' ? 'to' : ''), html: `<span>${chosen}</span>`, iconSize: [32, 32], iconAnchor: [16, 32] });
      L.marker([p.latitude, p.longitude], { icon, title: l.name, alt: l.name }).addTo(map).on('click', () => setFocused(l));
      bounds.push([p.latitude, p.longitude]);
    }
    if (bounds.length) map.fitBounds(bounds, { padding: [35, 35], maxZoom: 13 });
    const observer = new ResizeObserver(() => map.invalidateSize()); observer.observe(host.current);
    return () => { observer.disconnect(); map.remove(); };
  }, [locations, query, origin, destination]);
  return <div className="locker-picker">
    <div className="map-tools"><label>Find a locker<input value={query} onChange={e => setQuery(e.target.value)} placeholder="Search name or address" /></label>{onSelect && <div className="segmented"><button type="button" aria-pressed={side === 'origin'} onClick={() => setSide('origin')}>A · Send from</button><button type="button" aria-pressed={side === 'destination'} onClick={() => setSide('destination')}>B · Deliver to</button></div>}</div>
    {locations.some(l => l.map_position?.illustrative) && <p className="map-demo">Demo map · Marker positions are illustrative, not real ZPX locker addresses. Do not travel to these sites.</p>}
    <div ref={host} className="locker-map" aria-label="Locker location map" />
    {tilesFailed && <p role="status">Map tiles could not load. You can still choose a locker from the list below.</p>}
    {focused && <div className="map-selection"><strong>{focused.name}</strong><p>{focused.address.line1}</p><small>{focused.access_instructions}</small>{onSelect && <button type="button" className="primary" disabled={!focused.draft_eligible || focused.id === (side === 'origin' ? destination : origin)} onClick={() => { onSelect(focused.id, side); if (side === 'origin') setSide('destination'); }}>Use as {side === 'origin' ? 'origin' : 'destination'}</button>}</div>}
    <div className="locker-results">{matches.map(l => <button type="button" key={l.id} onClick={() => setFocused(l)} aria-pressed={focused?.id === l.id}><strong>{l.id === origin ? 'A · ' : l.id === destination ? 'B · ' : ''}{l.name}</strong><small>{l.development_only ? 'Demo locker' : l.address.line1}{!l.map_position ? ' · map position unavailable' : ''}</small></button>)}</div>
  </div>;
}
