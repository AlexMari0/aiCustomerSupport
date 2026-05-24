export interface ApiSuccessResponse<TData> {
  success: true
  message: string
  data: TData
  meta: Record<string, unknown>
}

export interface ApiErrorResponse {
  success: false
  message: string
  errors: Record<string, unknown>
}

export type ApiResponse<TData> = ApiSuccessResponse<TData> | ApiErrorResponse
