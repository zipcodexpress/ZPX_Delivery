export function apiDate(value: string): Date | null {
  const normalized = value.trim()
    .replace(' ', 'T')
    .replace(/(\.\d{3})\d+/, '$1')
    .replace(/([+-]\d{2})$/, '$1:00');
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function displayDate(value: string): string {
  return apiDate(value)?.toLocaleString() ?? 'Time unavailable';
}
