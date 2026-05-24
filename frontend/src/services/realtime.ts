import Echo from "laravel-echo"
import Pusher from "pusher-js"
import { API_BASE_URL } from "./api"

function resolveApiOrigin(): string {
  try {
    return new URL(API_BASE_URL).origin
  } catch {
    return "http://127.0.0.1:8000"
  }
}

function resolveAuthEndpoint(): string {
  return `${resolveApiOrigin()}/api/v1/broadcasting/auth`
}

function parsePort(value: string | undefined, fallback: number): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback
}

export function createEchoClient(token: string): Echo<"reverb"> {
  ;(window as Window & { Pusher?: typeof Pusher }).Pusher = Pusher

  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? "http"

  return new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? "127.0.0.1",
    wsPort: parsePort(import.meta.env.VITE_REVERB_PORT, 8080),
    wssPort: parsePort(import.meta.env.VITE_REVERB_PORT, 443),
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: resolveAuthEndpoint(),
    auth: {
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
    },
  })
}
