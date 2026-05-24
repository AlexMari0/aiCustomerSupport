import type { ApiResponse } from "../types/api"

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? "http://127.0.0.1:8000/api/v1"

export async function getJson<TData>(path: string): Promise<ApiResponse<TData>> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: {
      Accept: "application/json",
    },
  })

  return (await response.json()) as ApiResponse<TData>
}
