import { networkError, timeoutError, gatewayError, PaymentSDKError } from '../errors.js'
import { isRetryable } from '../errors.js'

export interface HttpRequestOptions {
  timeout?: number
  retries?: number
  retryDelay?: number
  headers?: Record<string, string>
}

const DEFAULT_TIMEOUT = 30_000
const DEFAULT_RETRIES = 2
const DEFAULT_RETRY_DELAY = 500

async function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function fetchWithTimeout(
  url: string,
  init: RequestInit,
  timeoutMs: number,
): Promise<Response> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)
  try {
    return await fetch(url, { ...init, signal: controller.signal })
  } catch (err) {
    if (err instanceof Error && err.name === 'AbortError') {
      throw timeoutError(`Request to ${url} timed out after ${timeoutMs}ms`)
    }
    throw networkError(`Request to ${url} failed: ${String(err)}`)
  } finally {
    clearTimeout(timer)
  }
}

export async function httpPost<T>(
  url: string,
  body: unknown,
  options: HttpRequestOptions = {},
): Promise<T> {
  const {
    timeout = DEFAULT_TIMEOUT,
    retries = DEFAULT_RETRIES,
    retryDelay = DEFAULT_RETRY_DELAY,
    headers = {},
  } = options

  const init: RequestInit = {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: JSON.stringify(body),
  }

  let lastError: PaymentSDKError | undefined

  for (let attempt = 0; attempt <= retries; attempt++) {
    if (attempt > 0) await sleep(retryDelay * attempt)

    try {
      const res = await fetchWithTimeout(url, init, timeout)

      if (!res.ok) {
        const text = await res.text().catch(() => '')
        const err = gatewayError(
          `Gateway returned HTTP ${res.status}`,
          String(res.status),
          text,
        )
        if (!isRetryable(err) || attempt === retries) throw err
        lastError = err
        continue
      }

      return (await res.json()) as T
    } catch (err) {
      const sdkErr = PaymentSDKError.fromUnknown(err)
      if (!isRetryable(sdkErr) || attempt === retries) throw sdkErr
      lastError = sdkErr
    }
  }

  throw lastError!
}

export async function httpGet<T>(
  url: string,
  params: Record<string, string> = {},
  options: HttpRequestOptions = {},
): Promise<T> {
  const {
    timeout = DEFAULT_TIMEOUT,
    retries = DEFAULT_RETRIES,
    retryDelay = DEFAULT_RETRY_DELAY,
    headers = {},
  } = options

  const qs = new URLSearchParams(params).toString()
  const fullUrl = qs ? `${url}?${qs}` : url
  const init: RequestInit = { method: 'GET', headers }

  let lastError: PaymentSDKError | undefined

  for (let attempt = 0; attempt <= retries; attempt++) {
    if (attempt > 0) await sleep(retryDelay * attempt)

    try {
      const res = await fetchWithTimeout(fullUrl, init, timeout)

      if (!res.ok) {
        const text = await res.text().catch(() => '')
        const err = gatewayError(
          `Gateway returned HTTP ${res.status}`,
          String(res.status),
          text,
        )
        if (!isRetryable(err) || attempt === retries) throw err
        lastError = err
        continue
      }

      return (await res.json()) as T
    } catch (err) {
      const sdkErr = PaymentSDKError.fromUnknown(err)
      if (!isRetryable(sdkErr) || attempt === retries) throw sdkErr
      lastError = sdkErr
    }
  }

  throw lastError!
}
