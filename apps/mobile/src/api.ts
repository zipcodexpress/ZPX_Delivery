import * as SecureStore from 'expo-secure-store';
import * as Crypto from 'expo-crypto';
import { Platform } from 'react-native';
import type { components } from '../../../packages/contracts/generated/api';

export type Schemas = components['schemas'];
export type Profile = Schemas['Profile'];

const key = 'zpx_delivery_native_refresh';
const configuredUrl = process.env.EXPO_PUBLIC_API_URL?.trim();
export const apiBase = (configuredUrl && (__DEV__ || configuredUrl.startsWith('https://')) ? configuredUrl : (__DEV__ ? Platform.select({ ios: 'http://localhost:8000', android: 'http://10.0.2.2:8000' }) : ''))?.replace(/\/$/, '') || '';
let accessToken: string | null = null;
let refreshInFlight: Promise<void> | null = null;
let sessionExpired: (() => void) | null = null;

export class ApiError extends Error {
  constructor(public status: number, message: string) { super(message); }
}

type Session = { access_token: string; refresh_token: string };

export function onSessionExpired(callback: () => void) { sessionExpired = callback; }

async function json<T>(path: string, init: RequestInit = {}): Promise<T> {
  if (!apiBase) throw new Error('Set EXPO_PUBLIC_API_URL to a reachable HTTPS Delivery API origin.');
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15000);
  let response: Response;
  try {
    response = await fetch(apiBase + '/api/delivery/v1' + path, {
      ...init, signal: controller.signal,
      headers: { Accept: 'application/json', ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...init.headers },
    });
  } catch { throw new Error('Could not reach Delivery. Check your connection and try again.'); }
  finally { clearTimeout(timeout); }
  const body: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const error = body && typeof body === 'object' ? body as { message?: string } : null;
    throw new ApiError(response.status, error?.message || `Request failed (${response.status}).`);
  }
  return body as T;
}

async function saveSession(session: Session) {
  await SecureStore.setItemAsync(key, session.refresh_token);
  accessToken = session.access_token;
}

export async function clearSession() {
  accessToken = null;
  await SecureStore.deleteItemAsync(key);
}

async function refresh() {
  if (!refreshInFlight) {
    refreshInFlight = (async () => {
      const token = await SecureStore.getItemAsync(key);
      if (!token) throw new ApiError(401, 'Sign in to continue.');
      try {
        const session = await json<Session>('/auth/refresh', { method: 'POST', body: JSON.stringify({ refresh_token: token }) });
        await saveSession(session);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          await clearSession();
          sessionExpired?.();
        }
        throw error;
      }
    })().finally(() => { refreshInFlight = null; });
  }
  return refreshInFlight;
}

export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  if (!accessToken) await refresh();
  const usedToken = accessToken;
  const authorized = () => json<T>(path, { ...init, headers: { ...init.headers, Authorization: `Bearer ${accessToken}` } });
  try { return await authorized(); }
  catch (error) {
    if (!(error instanceof ApiError) || error.status !== 401) throw error;
    if (accessToken === usedToken) await refresh();
    return authorized();
  }
}

export async function restore(): Promise<Profile | null> {
  if (!await SecureStore.getItemAsync(key)) return null;
  try { return await request<Profile>('/me'); }
  catch (error) {
    if (error instanceof ApiError && error.status === 401) return null;
    throw error;
  }
}

export async function signIn(email: string, password: string): Promise<Profile> {
  const session = await json<Session>('/auth/login', {
    method: 'POST', body: JSON.stringify({ email: email.trim(), password, client_kind: 'NATIVE' }),
  });
  await saveSession(session);
  return request<Profile>('/me');
}

export async function signOut() {
  try {
    if (accessToken) await request('/auth/logout', {
      method: 'POST', body: '{}', headers: { 'Idempotency-Key': Crypto.randomUUID() },
    });
  } finally { await clearSession(); }
}

export function command<T>(path: string, body: unknown, version?: number, requestKey = Crypto.randomUUID()): Promise<T> {
  return request<T>(path, {
    method: 'POST', body: JSON.stringify(body),
    headers: { 'Idempotency-Key': requestKey, ...(version === undefined ? {} : { 'If-Match': `"${version}"` }) },
  });
}
