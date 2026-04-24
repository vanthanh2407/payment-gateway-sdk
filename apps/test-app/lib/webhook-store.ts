import type { WebhookEvent } from '@payment-sdk/node'

export interface StoredWebhookEvent {
  id: string
  event: WebhookEvent
  rawBody: string
  receivedAt: string
}

const MAX_EVENTS = 100

declare global {
  // eslint-disable-next-line no-var
  var __webhookStore: StoredWebhookEvent[] | undefined
}

function getStore(): StoredWebhookEvent[] {
  if (!global.__webhookStore) global.__webhookStore = []
  return global.__webhookStore
}

export function addEvent(event: WebhookEvent, rawBody: string): void {
  const store = getStore()
  store.unshift({
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    event,
    rawBody,
    receivedAt: new Date().toISOString(),
  })
  if (store.length > MAX_EVENTS) store.splice(MAX_EVENTS)
}

export function getEvents(): StoredWebhookEvent[] {
  return [...getStore()]
}

export function clearEvents(): void {
  global.__webhookStore = []
}
