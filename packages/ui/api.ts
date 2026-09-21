export class RequestError extends Error { constructor(message: string, public status: number) { super(message); } }
const base = '/api/delivery/v1';
export async function api<T>(path: string, body?: unknown, headers: Record<string, string> = {}): Promise<T> {
  const response = await fetch(base + path, {
    method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(15000),
    headers: body === undefined ? headers : { 'Content-Type': 'application/json', ...headers },
    body: body === undefined ? undefined : JSON.stringify(body),
  }).catch(() => { throw new RequestError('We could not reach the service. Please try again.', 503); });
  const data = await response.json().catch(() => { throw new RequestError('The service is unavailable. Please try again.', response.status); });
  if (!response.ok) throw new RequestError(data?.message || 'Please try again.', response.status);
  return data as T;
}
