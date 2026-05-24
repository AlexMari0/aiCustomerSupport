export type OrganizationRole = "owner" | "admin" | "agent"
export type TicketStatus = "open" | "pending" | "resolved" | "closed"
export type TicketPriority = "low" | "medium" | "high" | "urgent"

export interface OrganizationItem {
  id: number
  name: string
  slug: string
  join_code: string
  role: OrganizationRole
}

export interface BasicUser {
  id: number
  name: string
  email: string
}

export interface OrganizationMember extends BasicUser {
  role: OrganizationRole
}

export interface TicketListItem {
  id: number
  subject: string
  status: TicketStatus
  priority: TicketPriority
  category: string | null
  source_channel: string
  customer: {
    id: number
    name: string
    email: string | null
  } | null
  assignee: BasicUser | null
  messages_count: number
  notes_count: number
  created_at: string
  updated_at: string
}

export interface TicketDetail {
  id: number
  subject: string
  status: TicketStatus
  priority: TicketPriority
  category: string | null
  source_channel: string
  customer: {
    id: number
    name: string
    email: string | null
    phone: string | null
    source_channel: string | null
    tags: string[]
    last_contacted_at: string | null
  } | null
  assignee: BasicUser | null
  creator: BasicUser | null
  messages: Array<{
    id: number
    sender_type: "customer" | "agent"
    sender_user: BasicUser | null
    body: string
    created_at: string
  }>
  notes: Array<{
    id: number
    note: string
    is_private: boolean
    user: BasicUser | null
    created_at: string
  }>
  created_at: string
  updated_at: string
  customer_ticket_history: Array<{
    id: number
    subject: string
    status: TicketStatus
    priority: TicketPriority
    category: string | null
    assignee: BasicUser | null
    created_at: string
    updated_at: string
  }>
}

export interface CustomerListItem {
  id: number
  name: string
  email: string | null
  phone: string | null
  source_channel: string | null
  tags: string[]
  last_contacted_at: string | null
  tickets_count: number
}

export interface CustomerDetail {
  id: number
  name: string
  email: string | null
  phone: string | null
  source_channel: string | null
  tags: string[]
  last_contacted_at: string | null
  ticket_history: Array<{
    id: number
    subject: string
    status: TicketStatus
    priority: TicketPriority
    category: string | null
    assignee: BasicUser | null
    created_at: string
    updated_at: string
  }>
}
