import { createHmac } from 'node:crypto'

export function hmacSHA512(data: string, secret: string): string {
  return createHmac('sha512', secret).update(data).digest('hex')
}

export function hmacSHA256(data: string, secret: string): string {
  return createHmac('sha256', secret).update(data).digest('hex')
}

export function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false
  const bufA = Buffer.from(a, 'hex')
  const bufB = Buffer.from(b, 'hex')
  if (bufA.length !== bufB.length) return false
  return bufA.every((byte, i) => byte === bufB[i])
}

/** Format a Date as VNPay's yyyyMMddHHmmss in local timezone */
export function formatVNPayDate(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return (
    date.getFullYear() +
    pad(date.getMonth() + 1) +
    pad(date.getDate()) +
    pad(date.getHours()) +
    pad(date.getMinutes()) +
    pad(date.getSeconds())
  )
}

/** Build a sorted query string for signing (no encoding on key, URL-encoded values) */
export function buildSortedQueryString(params: Record<string, string>): string {
  return Object.keys(params)
    .sort()
    .map((key) => `${key}=${encodeURIComponent(params[key] ?? '')}`)
    .join('&')
}

/** Build a sorted raw string (no URL encoding) for signing — used by MoMo */
export function buildRawString(params: Record<string, string>, keys: string[]): string {
  return keys.map((k) => `${k}=${params[k] ?? ''}`).join('&')
}
