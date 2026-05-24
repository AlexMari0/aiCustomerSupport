import type { ApiResponse } from "../types/api"

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? "http://127.0.0.1:8000/api/v1"

function makeHeaders(token?: string): HeadersInit {
  const headers: HeadersInit = {
    Accept: "application/json",
  }

  if (token && token.trim() !== "") {
    headers.Authorization = `Bearer ${token}`
  }

  return headers
}

async function requestJson<TData>(
  method: "GET" | "POST" | "PATCH",
  path: string,
  token?: string,
  body?: unknown,
): Promise<ApiResponse<TData>> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers: {
      ...makeHeaders(token),
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  })

  return (await response.json()) as ApiResponse<TData>
}

export async function getJson<TData>(path: string, token?: string): Promise<ApiResponse<TData>> {
  return requestJson<TData>("GET", path, token)
}

export async function postJson<TData>(path: string, body: unknown, token?: string): Promise<ApiResponse<TData>> {
  return requestJson<TData>("POST", path, token, body)
}

export async function patchJson<TData>(path: string, body: unknown, token?: string): Promise<ApiResponse<TData>> {
  return requestJson<TData>("PATCH", path, token, body)
}

export async function getJsonWithParams<TData>(
  path: string,
  params: Record<string, string | number | null | undefined>,
  token?: string,
): Promise<ApiResponse<TData>> {
  const queryParams = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && `${value}`.trim() !== "") {
      queryParams.set(key, `${value}`)
    }
  })

  const query = queryParams.toString()
  const resolvedPath = query === "" ? path : `${path}?${query}`

  return requestJson<TData>("GET", resolvedPath, token)
}
