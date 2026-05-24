<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue"
import { getJson, getJsonWithParams, patchJson, postJson } from "./services/api"
import { createEchoClient } from "./services/realtime"
import type { ApiSuccessResponse } from "./types/api"
import type {
  BasicUser,
  CustomerDetail,
  CustomerListItem,
  OrganizationItem,
  OrganizationMember,
  TicketDetail,
  TicketListItem,
  TicketPriority,
  TicketStatus,
} from "./types/domain"

interface AuthPayload {
  token: string
  user: BasicUser
}

interface MePayload {
  user: BasicUser
  organizations: OrganizationItem[]
}

interface TicketRealtimePayload {
  ticket: {
    id: number
    subject?: string
    status?: TicketStatus
    priority?: TicketPriority
    assigned_to?: number | null
  }
  ticket_id?: number
  actor_user_id: number
}

const authMode = ref<"login" | "register">("login")
const authLoading = ref(false)
const dashboardLoading = ref(false)
const appMessage = ref("Welcome! Sign in to manage support tickets.")

const token = ref(localStorage.getItem("token") ?? "")
const currentUser = ref<BasicUser | null>(null)
const organizations = ref<OrganizationItem[]>([])
const selectedOrganizationId = ref<number | null>(null)
const members = ref<OrganizationMember[]>([])

const customers = ref<CustomerListItem[]>([])
const selectedCustomerId = ref<number | null>(null)
const selectedCustomer = ref<CustomerDetail | null>(null)

const tickets = ref<TicketListItem[]>([])
const selectedTicketId = ref<number | null>(null)
const selectedTicket = ref<TicketDetail | null>(null)

const loginForm = ref({
  email: "",
  password: "",
})

const registerForm = ref({
  name: "",
  email: "",
  password: "",
  password_confirmation: "",
})

const createOrganizationName = ref("")
const joinCode = ref("")

const ticketFilters = ref({
  search: "",
  status: "",
  priority: "",
  assignee_id: "",
  category: "",
})

const customerFilters = ref({
  search: "",
  source_channel: "",
  tag: "",
})

const customerUpdateForm = ref({
  name: "",
  email: "",
  phone: "",
  source_channel: "",
  tags_text: "",
})

const createTicketForm = ref({
  customer_name: "",
  customer_email: "",
  customer_phone: "",
  customer_source_channel: "web",
  customer_tags_text: "",
  subject: "",
  category: "",
  priority: "medium",
  source_channel: "web",
  message: "",
})

const updateStatusValue = ref<TicketStatus>("open")
const updatePriorityValue = ref<TicketPriority>("medium")
const updateCategoryValue = ref("")
const aiClassificationLoading = ref(false)
const assignAssigneeId = ref("")
const automationRules = ref<any[]>([])
const automationRuns = ref<any[]>([])
const showRuleModal = ref(false)
const ruleForm = ref({
  name: "",
  trigger_type: "ticket_created",
  conditions: [] as Array<{ field: string; value: string }>,
  actions: [] as Array<{ action_type: string; action_value: string }>,
})
const newNote = ref("")
const newMessage = ref("")
const newMessageSenderType = ref<"customer" | "agent">("agent")
const realtimeState = ref<"disconnected" | "connecting" | "connected">("disconnected")
const realtimeNotifications = ref<Array<{ id: number; text: string; created_at: string }>>([])
const typingIndicator = ref("")

const activeTab = ref<"dashboard" | "knowledge-base" | "automations">("dashboard")
const canManageKB = computed(() => roleForSelectedOrganization.value === "owner" || roleForSelectedOrganization.value === "admin")

const kbCategories = ref<any[]>([])
const kbArticles = ref<any[]>([])
const selectedKBArticleId = ref<number | null>(null)
const selectedKBArticle = ref<any | null>(null)
const kbSearchQuery = ref("")
const kbSelectedCategoryId = ref("")
const kbSelectedStatus = ref("")
const kbLoading = ref(false)

const kbCategoryForm = ref({
  id: null as number | null,
  name: "",
  slug: "",
  description: "",
})

const kbArticleForm = ref({
  id: null as number | null,
  category_id: "",
  title: "",
  slug: "",
  content: "",
  status: "draft",
})

const isEditingCategory = ref(false)
const isEditingArticle = ref(false)
const showCategoryForm = ref(false)
const showArticleForm = ref(false)

// Reference Search in Ticketing
const showKBReferenceSearch = ref(false)
const kbRefSearchQuery = ref("")
const kbRefResults = ref<any[]>([])

// AI Suggested Reply State
const aiLoading = ref(false)
const aiReferencedArticles = ref<any[]>([])
const aiSuggestionsHistory = ref<any[]>([])

const echoClient = ref<ReturnType<typeof createEchoClient> | null>(null)
const realtimeOrgChannel = ref<string | null>(null)
const realtimeTicketChannel = ref<string | null>(null)
const realtimeUserChannel = ref<string | null>(null)

let notificationCounter = 0
let typingWhisperTimer: ReturnType<typeof setTimeout> | null = null
let typingIndicatorTimer: ReturnType<typeof setTimeout> | null = null

const roleForSelectedOrganization = computed(() => {
  if (selectedOrganizationId.value === null) {
    return null
  }

  return organizations.value.find((org) => org.id === selectedOrganizationId.value)?.role ?? null
})

const canManageAssignments = computed(() => roleForSelectedOrganization.value === "owner" || roleForSelectedOrganization.value === "admin")
const canUpdateCustomer = computed(() => roleForSelectedOrganization.value === "owner" || roleForSelectedOrganization.value === "admin")

const statuses: TicketStatus[] = ["open", "pending", "resolved", "closed"]
const priorities: TicketPriority[] = ["low", "medium", "high", "urgent"]
const ticketCategories: string[] = ["billing", "refund", "technical_issue", "shipping", "product_question", "account_issue"]

function setMessage(message: string): void {
  appMessage.value = message
}

function saveToken(nextToken: string): void {
  token.value = nextToken
  localStorage.setItem("token", nextToken)
}

function clearSession(): void {
  teardownRealtimeClient()
  token.value = ""
  currentUser.value = null
  organizations.value = []
  selectedOrganizationId.value = null
  members.value = []
  customers.value = []
  selectedCustomerId.value = null
  selectedCustomer.value = null
  tickets.value = []
  selectedTicketId.value = null
  selectedTicket.value = null
  localStorage.removeItem("token")
}

function parseTags(input: string): string[] {
  return Array.from(new Set(input.split(",").map((value) => value.trim()).filter((value) => value.length > 0)))
}

function formatDate(value: string | null): string {
  if (!value) {
    return "-"
  }

  return new Date(value).toLocaleString()
}

function pushRealtimeNotification(text: string): void {
  notificationCounter += 1
  realtimeNotifications.value.unshift({
    id: notificationCounter,
    text,
    created_at: new Date().toISOString(),
  })

  if (realtimeNotifications.value.length > 8) {
    realtimeNotifications.value = realtimeNotifications.value.slice(0, 8)
  }
}

function bindRealtimeConnectionStatus(): void {
  if (!echoClient.value) {
    return
  }

  const connector = echoClient.value.connector as {
    pusher?: {
      connection?: {
        bind: (eventName: string, callback: () => void) => void
      }
    }
  }

  const connection = connector.pusher?.connection
  if (!connection) {
    return
  }

  connection.bind("connected", () => {
    realtimeState.value = "connected"
  })

  connection.bind("disconnected", () => {
    realtimeState.value = "disconnected"
  })

  connection.bind("error", () => {
    realtimeState.value = "disconnected"
  })
}

function ensureRealtimeClient(): void {
  if (!token.value || echoClient.value) {
    return
  }

  realtimeState.value = "connecting"
  echoClient.value = createEchoClient(token.value)
  bindRealtimeConnectionStatus()
}

function teardownRealtimeClient(): void {
  if (typingWhisperTimer) {
    clearTimeout(typingWhisperTimer)
    typingWhisperTimer = null
  }

  if (typingIndicatorTimer) {
    clearTimeout(typingIndicatorTimer)
    typingIndicatorTimer = null
  }

  typingIndicator.value = ""

  if (!echoClient.value) {
    realtimeState.value = "disconnected"
    realtimeOrgChannel.value = null
    realtimeTicketChannel.value = null
    realtimeUserChannel.value = null
    return
  }

  if (realtimeOrgChannel.value) {
    echoClient.value.leave(realtimeOrgChannel.value)
    realtimeOrgChannel.value = null
  }

  if (realtimeTicketChannel.value) {
    echoClient.value.leave(realtimeTicketChannel.value)
    realtimeTicketChannel.value = null
  }

  if (realtimeUserChannel.value) {
    echoClient.value.leave(realtimeUserChannel.value)
    realtimeUserChannel.value = null
  }

  echoClient.value.disconnect()
  echoClient.value = null
  realtimeState.value = "disconnected"
}

async function handleRealtimeTicketRefresh(ticketId: number | null, message: string): Promise<void> {
  await Promise.all([loadTickets(), loadCustomers()])

  if (ticketId !== null && selectedTicketId.value === ticketId) {
    await selectTicket(ticketId)
  }

  setMessage(message)
}

function subscribeUserRealtimeChannel(): void {
  if (!echoClient.value || !currentUser.value) {
    return
  }

  const channelName = `users.${currentUser.value.id}.assignments`
  if (realtimeUserChannel.value === channelName) {
    return
  }

  if (realtimeUserChannel.value) {
    echoClient.value.leave(realtimeUserChannel.value)
  }

  realtimeUserChannel.value = channelName

  echoClient.value
    .private(channelName)
    .listen(".ticket.assigned", async (payload: TicketRealtimePayload) => {
      if (payload.ticket.assigned_to !== currentUser.value?.id) {
        return
      }

      pushRealtimeNotification(`Assigned: ${payload.ticket.subject ?? `Ticket #${payload.ticket.id}`}`)
      await handleRealtimeTicketRefresh(payload.ticket.id, "Realtime: new ticket assignment received.")
    })
}

function subscribeOrganizationRealtimeChannel(): void {
  if (!echoClient.value || selectedOrganizationId.value === null) {
    return
  }

  const channelName = `organizations.${selectedOrganizationId.value}.tickets`
  if (realtimeOrgChannel.value === channelName) {
    return
  }

  if (realtimeOrgChannel.value) {
    echoClient.value.leave(realtimeOrgChannel.value)
  }

  realtimeOrgChannel.value = channelName

  const channel = echoClient.value.private(channelName)

  channel.listen(".ticket.created", async (payload: TicketRealtimePayload) => {
    pushRealtimeNotification(`New ticket: ${payload.ticket.subject ?? `#${payload.ticket.id}`}`)
    await handleRealtimeTicketRefresh(payload.ticket.id, "Realtime: new ticket created.")
  })

  channel.listen(".ticket.updated", async (payload: TicketRealtimePayload) => {
    await handleRealtimeTicketRefresh(payload.ticket.id, "Realtime: ticket updated.")
  })

  channel.listen(".ticket.assigned", async (payload: TicketRealtimePayload) => {
    await handleRealtimeTicketRefresh(payload.ticket.id, "Realtime: ticket assignment updated.")
  })

  channel.listen(".ticket.message-created", async (payload: TicketRealtimePayload) => {
    const ticketId = payload.ticket_id ?? null
    await handleRealtimeTicketRefresh(ticketId, "Realtime: conversation updated.")
  })

  channel.listen(".ticket.resolved", async (payload: TicketRealtimePayload) => {
    pushRealtimeNotification(`Resolved: ${payload.ticket.subject ?? `Ticket #${payload.ticket.id}`}`)
    await handleRealtimeTicketRefresh(payload.ticket.id, "Realtime: ticket resolved.")
  })
}

function subscribeSelectedTicketChannel(): void {
  if (!echoClient.value || selectedOrganizationId.value === null || selectedTicketId.value === null) {
    if (echoClient.value && realtimeTicketChannel.value) {
      echoClient.value.leave(realtimeTicketChannel.value)
      realtimeTicketChannel.value = null
    }
    typingIndicator.value = ""
    return
  }

  const channelName = `organizations.${selectedOrganizationId.value}.tickets.${selectedTicketId.value}`
  if (realtimeTicketChannel.value === channelName) {
    return
  }

  if (realtimeTicketChannel.value) {
    echoClient.value.leave(realtimeTicketChannel.value)
  }

  realtimeTicketChannel.value = channelName
  typingIndicator.value = ""

  echoClient.value
    .private(channelName)
    .listenForWhisper("typing", (payload: { user_id?: number; name?: string }) => {
      if (!payload || payload.user_id === currentUser.value?.id) {
        return
      }

      typingIndicator.value = `${payload.name ?? "Another agent"} is typing...`

      if (typingIndicatorTimer) {
        clearTimeout(typingIndicatorTimer)
      }

      typingIndicatorTimer = setTimeout(() => {
        typingIndicator.value = ""
      }, 1800)
    })
}

function ensureRealtimeSubscriptions(): void {
  ensureRealtimeClient()
  subscribeUserRealtimeChannel()
  subscribeOrganizationRealtimeChannel()
  subscribeSelectedTicketChannel()
}

function whisperTyping(): void {
  if (!echoClient.value || !realtimeTicketChannel.value || !currentUser.value) {
    return
  }

  if (typingWhisperTimer) {
    clearTimeout(typingWhisperTimer)
  }

  typingWhisperTimer = setTimeout(() => {
    echoClient.value
      ?.private(realtimeTicketChannel.value as string)
      .whisper("typing", {
        user_id: currentUser.value?.id,
        name: currentUser.value?.name,
      })
  }, 250)
}

async function handleLogin(): Promise<void> {
  authLoading.value = true
  try {
    const response = await postJson<AuthPayload>("/auth/login", loginForm.value)
    if (!response.success) {
      setMessage(response.message)
      return
    }

    const payload = response as ApiSuccessResponse<AuthPayload>
    saveToken(payload.data.token)
    await loadSession()
    setMessage("Login successful.")
  } finally {
    authLoading.value = false
  }
}

async function handleRegister(): Promise<void> {
  authLoading.value = true
  try {
    const response = await postJson<AuthPayload>("/auth/register", registerForm.value)
    if (!response.success) {
      setMessage(response.message)
      return
    }

    const payload = response as ApiSuccessResponse<AuthPayload>
    saveToken(payload.data.token)
    await loadSession()
    setMessage("Registration successful.")
  } finally {
    authLoading.value = false
  }
}

async function handleLogout(): Promise<void> {
  if (token.value) {
    await postJson("/auth/logout", {}, token.value)
  }
  clearSession()
  setMessage("Logged out.")
}

async function loadSession(): Promise<void> {
  if (!token.value) {
    return
  }

  dashboardLoading.value = true
  try {
    const meResponse = await getJson<MePayload>("/auth/me", token.value)
    if (!meResponse.success) {
      clearSession()
      setMessage(meResponse.message)
      return
    }

    const mePayload = meResponse as ApiSuccessResponse<MePayload>
    currentUser.value = mePayload.data.user
    organizations.value = mePayload.data.organizations

    if (selectedOrganizationId.value === null || !organizations.value.some((org) => org.id === selectedOrganizationId.value)) {
      selectedOrganizationId.value = organizations.value[0]?.id ?? null
    }

    await loadOrganizationData()
  } finally {
    dashboardLoading.value = false
  }
}

async function loadOrganizationData(): Promise<void> {
  if (selectedOrganizationId.value === null) {
    tickets.value = []
    selectedTicket.value = null
    customers.value = []
    selectedCustomer.value = null
    members.value = []
    subscribeSelectedTicketChannel()
    return
  }

  await Promise.all([loadMembers(), loadCustomers(), loadTickets(), loadKBData()])
  ensureRealtimeSubscriptions()
}

async function loadMembers(): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await getJson<OrganizationMember[]>(`/organizations/${selectedOrganizationId.value}/members`, token.value)
  if (!response.success) {
    members.value = []
    return
  }

  members.value = (response as ApiSuccessResponse<OrganizationMember[]>).data
}

async function loadCustomers(): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await getJsonWithParams<CustomerListItem[]>(
    `/organizations/${selectedOrganizationId.value}/customers`,
    customerFilters.value,
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  customers.value = (response as ApiSuccessResponse<CustomerListItem[]>).data

  if (customers.value.length === 0) {
    selectedCustomerId.value = null
    selectedCustomer.value = null
    return
  }

  if (selectedCustomerId.value === null || !customers.value.some((customer) => customer.id === selectedCustomerId.value)) {
    await selectCustomer(customers.value[0].id)
  }
}

async function selectCustomer(customerId: number): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await getJson<CustomerDetail>(
    `/organizations/${selectedOrganizationId.value}/customers/${customerId}`,
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  selectedCustomerId.value = customerId
  selectedCustomer.value = (response as ApiSuccessResponse<CustomerDetail>).data
  customerUpdateForm.value = {
    name: selectedCustomer.value.name,
    email: selectedCustomer.value.email ?? "",
    phone: selectedCustomer.value.phone ?? "",
    source_channel: selectedCustomer.value.source_channel ?? "",
    tags_text: selectedCustomer.value.tags.join(", "),
  }
}

async function updateCustomerProfile(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedCustomerId.value === null) {
    return
  }

  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/customers/${selectedCustomerId.value}`,
    {
      name: customerUpdateForm.value.name,
      email: customerUpdateForm.value.email,
      phone: customerUpdateForm.value.phone,
      source_channel: customerUpdateForm.value.source_channel,
      tags: parseTags(customerUpdateForm.value.tags_text),
    },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await Promise.all([loadCustomers(), selectCustomer(selectedCustomerId.value)])
  setMessage("Customer profile updated.")
}

async function loadTickets(): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await getJsonWithParams<TicketListItem[]>(
    `/organizations/${selectedOrganizationId.value}/tickets`,
    ticketFilters.value,
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  tickets.value = (response as ApiSuccessResponse<TicketListItem[]>).data

  if (tickets.value.length === 0) {
    selectedTicketId.value = null
    selectedTicket.value = null
    subscribeSelectedTicketChannel()
    return
  }

  if (selectedTicketId.value === null || !tickets.value.some((ticket) => ticket.id === selectedTicketId.value)) {
    await selectTicket(tickets.value[0].id)
  } else {
    await selectTicket(selectedTicketId.value)
  }
}

async function selectTicket(ticketId: number): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await getJson<TicketDetail>(
    `/organizations/${selectedOrganizationId.value}/tickets/${ticketId}`,
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  selectedTicketId.value = ticketId
  selectedTicket.value = (response as ApiSuccessResponse<TicketDetail>).data
  aiReferencedArticles.value = []
  aiSuggestionsHistory.value = []
  updateStatusValue.value = selectedTicket.value.status
  updatePriorityValue.value = selectedTicket.value.priority
  updateCategoryValue.value = selectedTicket.value.category || ""
  assignAssigneeId.value = selectedTicket.value.assignee?.id ? `${selectedTicket.value.assignee.id}` : ""
  await loadTicketRunsData()

  if (selectedTicket.value.customer?.id) {
    await selectCustomer(selectedTicket.value.customer.id)
  }

  subscribeSelectedTicketChannel()
}

async function createOrganization(): Promise<void> {
  if (createOrganizationName.value.trim() === "") {
    return
  }

  const response = await postJson("/organizations", { name: createOrganizationName.value }, token.value)
  if (!response.success) {
    setMessage(response.message)
    return
  }

  createOrganizationName.value = ""
  await loadSession()
  setMessage("Organization created.")
}

async function joinOrganization(): Promise<void> {
  if (joinCode.value.trim() === "") {
    return
  }

  const response = await postJson("/organizations/join", { join_code: joinCode.value }, token.value)
  if (!response.success) {
    setMessage(response.message)
    return
  }

  joinCode.value = ""
  await loadSession()
  setMessage("Joined organization.")
}

async function createTicket(): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/tickets`,
    {
      customer_name: createTicketForm.value.customer_name,
      customer_email: createTicketForm.value.customer_email,
      customer_phone: createTicketForm.value.customer_phone,
      customer_source_channel: createTicketForm.value.customer_source_channel,
      customer_tags: parseTags(createTicketForm.value.customer_tags_text),
      subject: createTicketForm.value.subject,
      category: createTicketForm.value.category,
      priority: createTicketForm.value.priority,
      source_channel: createTicketForm.value.source_channel,
      message: createTicketForm.value.message,
    },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  createTicketForm.value = {
    customer_name: "",
    customer_email: "",
    customer_phone: "",
    customer_source_channel: "web",
    customer_tags_text: "",
    subject: "",
    category: "",
    priority: "medium",
    source_channel: "web",
    message: "",
  }

  await Promise.all([loadTickets(), loadCustomers()])
  setMessage("Ticket created.")
}

async function updateTicketStatus(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null) {
    return
  }

  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/status`,
    { status: updateStatusValue.value },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await Promise.all([loadTickets(), selectTicket(selectedTicketId.value)])
  setMessage("Ticket status updated.")
}

async function updateTicketPriority(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null) {
    return
  }

  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/priority`,
    { priority: updatePriorityValue.value },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await Promise.all([loadTickets(), selectTicket(selectedTicketId.value)])
  setMessage("Ticket priority updated.")
}

async function updateTicketCategory(categoryVal?: string): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null) {
    return
  }

  const categoryToUpdate = categoryVal !== undefined ? categoryVal : updateCategoryValue.value;

  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/category`,
    { category: categoryToUpdate || null },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await Promise.all([loadTickets(), selectTicket(selectedTicketId.value)])
  updateCategoryValue.value = selectedTicket.value?.category || ""
  setMessage("Ticket category updated.")
}

async function quickApplyPriority(priorityVal: string): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null) {
    return
  }

  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/priority`,
    { priority: priorityVal },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await Promise.all([loadTickets(), selectTicket(selectedTicketId.value)])
  updatePriorityValue.value = selectedTicket.value?.priority || "medium"
  setMessage("Ticket priority updated.")
}

async function runClassification(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null) {
    return
  }

  aiClassificationLoading.value = true
  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/classify`,
    {},
    token.value,
  )
  aiClassificationLoading.value = false

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await Promise.all([loadTickets(), selectTicket(selectedTicketId.value)])
  setMessage("Ticket AI classification completed.")
}

async function loadAutomationsData(): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await getJson<any[]>(
    `/organizations/${selectedOrganizationId.value}/automations/rules`,
    token.value,
  )

  if (response.success) {
    automationRules.value = (response as ApiSuccessResponse<any[]>).data
  }
}

async function loadTicketRunsData(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null) {
    return
  }

  const response = await getJson<any[]>(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/automation-runs`,
    token.value,
  )

  if (response.success) {
    automationRuns.value = (response as ApiSuccessResponse<any[]>).data
  }
}

async function toggleRule(ruleId: number): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/automations/rules/${ruleId}/toggle`,
    {},
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await loadAutomationsData()
  setMessage("Rule status updated.")
}

async function deleteRule(ruleId: number): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/automations/rules/${ruleId}`,
    { _method: "DELETE" },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await loadAutomationsData()
  setMessage("Automation rule deleted.")
}

function addConditionInForm(): void {
  ruleForm.value.conditions.push({ field: "category", value: "" })
}

function removeConditionInForm(index: number): void {
  ruleForm.value.conditions.splice(index, 1)
}

function addActionInForm(): void {
  ruleForm.value.actions.push({ action_type: "assign_to_agent", action_value: "" })
}

function removeActionInForm(index: number): void {
  ruleForm.value.actions.splice(index, 1)
}

async function createAutomationRule(): Promise<void> {
  if (selectedOrganizationId.value === null) {
    return
  }

  if (ruleForm.value.name.trim() === "" || ruleForm.value.actions.length === 0) {
    setMessage("Please provide a name and at least one action.")
    return
  }

  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/automations/rules`,
    ruleForm.value,
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  ruleForm.value = {
    name: "",
    trigger_type: "ticket_created",
    conditions: [],
    actions: [],
  }
  showRuleModal.value = false
  await loadAutomationsData()
  setMessage("Automation rule created successfully.")
}

async function assignTicket(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null || assignAssigneeId.value === "") {
    return
  }

  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/assign`,
    { assignee_id: Number(assignAssigneeId.value) },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  await Promise.all([loadTickets(), selectTicket(selectedTicketId.value), loadCustomers()])
  setMessage("Ticket assigned.")
}

async function addNote(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null || newNote.value.trim() === "") {
    return
  }

  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/notes`,
    { note: newNote.value, is_private: true },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  newNote.value = ""
  await selectTicket(selectedTicketId.value)
  setMessage("Internal note added.")
}

async function addMessage(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null || newMessage.value.trim() === "") {
    return
  }

  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/messages`,
    { sender_type: newMessageSenderType.value, body: newMessage.value },
    token.value,
  )

  if (!response.success) {
    setMessage(response.message)
    return
  }

  newMessage.value = ""
  await Promise.all([loadTickets(), selectTicket(selectedTicketId.value), loadCustomers()])
  setMessage("Ticket reply added.")
}

// Knowledge Base API Calls and Methods
async function loadKBData(): Promise<void> {
  if (selectedOrganizationId.value === null) return
  kbLoading.value = true
  try {
    await Promise.all([loadKBCategories(), loadKBArticles()])
  } finally {
    kbLoading.value = false
  }
}

async function loadKBCategories(): Promise<void> {
  if (selectedOrganizationId.value === null) return
  const response = await getJson<any[]>(
    `/organizations/${selectedOrganizationId.value}/knowledge-base/categories`,
    token.value
  )
  if (response.success) {
    kbCategories.value = (response as ApiSuccessResponse<any[]>).data
  }
}

async function loadKBArticles(): Promise<void> {
  if (selectedOrganizationId.value === null) return
  const params = {
    search: kbSearchQuery.value,
    category_id: kbSelectedCategoryId.value,
    status: kbSelectedStatus.value,
  }
  const response = await getJsonWithParams<any[]>(
    `/organizations/${selectedOrganizationId.value}/knowledge-base/articles`,
    params,
    token.value
  )
  if (response.success) {
    kbArticles.value = (response as ApiSuccessResponse<any[]>).data
    // Auto-select first article if none selected or if previously selected is missing
    if (kbArticles.value.length > 0) {
      if (selectedKBArticleId.value === null || !kbArticles.value.some(a => a.id === selectedKBArticleId.value)) {
        await selectKBArticle(kbArticles.value[0].id)
      } else {
        await selectKBArticle(selectedKBArticleId.value)
      }
    } else {
      selectedKBArticleId.value = null
      selectedKBArticle.value = null
    }
  }
}

async function selectKBArticle(id: number): Promise<void> {
  if (selectedOrganizationId.value === null) return
  const response = await getJson<any>(
    `/organizations/${selectedOrganizationId.value}/knowledge-base/articles/${id}`,
    token.value
  )
  if (response.success) {
    selectedKBArticleId.value = id
    selectedKBArticle.value = (response as ApiSuccessResponse<any>).data
  }
}

function generateCategorySlug(): void {
  if (!kbCategoryForm.value.id) {
    kbCategoryForm.value.slug = kbCategoryForm.value.name
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
  }
}

function generateArticleSlug(): void {
  if (!kbArticleForm.value.id) {
    kbArticleForm.value.slug = kbArticleForm.value.title
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
  }
}

function openCreateCategory(): void {
  isEditingCategory.value = false
  kbCategoryForm.value = { id: null, name: "", slug: "", description: "" }
  showCategoryForm.value = true
}

function openEditCategory(category: any): void {
  isEditingCategory.value = true
  kbCategoryForm.value = {
    id: category.id,
    name: category.name,
    slug: category.slug,
    description: category.description || "",
  }
  showCategoryForm.value = true
}

async function saveCategory(): Promise<void> {
  if (selectedOrganizationId.value === null) return
  const url = kbCategoryForm.value.id
    ? `/organizations/${selectedOrganizationId.value}/knowledge-base/categories/${kbCategoryForm.value.id}`
    : `/organizations/${selectedOrganizationId.value}/knowledge-base/categories`

  const method = kbCategoryForm.value.id ? patchJson : postJson
  const response = await method(url, kbCategoryForm.value, token.value)

  if (response.success) {
    showCategoryForm.value = false
    await loadKBCategories()
    setMessage(kbCategoryForm.value.id ? "Category updated." : "Category created.")
  } else {
    setMessage(response.message)
  }
}

async function deleteCategory(id: number): Promise<void> {
  if (selectedOrganizationId.value === null) return
  if (!confirm("Are you sure you want to delete this category? Articles under it will become uncategorized.")) return

  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/knowledge-base/categories/${id}`,
    { _method: "DELETE" },
    token.value
  )

  if (response.success) {
    await loadKBData()
    setMessage("Category deleted.")
  } else {
    setMessage(response.message)
  }
}

function openCreateArticle(): void {
  isEditingArticle.value = false
  kbArticleForm.value = {
    id: null,
    category_id: kbSelectedCategoryId.value || "",
    title: "",
    slug: "",
    content: "",
    status: "draft",
  }
  showArticleForm.value = true
}

function openEditArticle(article: any): void {
  isEditingArticle.value = true
  kbArticleForm.value = {
    id: article.id,
    category_id: article.category?.id ? `${article.category.id}` : "",
    title: article.title,
    slug: article.slug,
    content: article.content,
    status: article.status,
  }
  showArticleForm.value = true
}

async function saveArticle(): Promise<void> {
  if (selectedOrganizationId.value === null) return
  const url = kbArticleForm.value.id
    ? `/organizations/${selectedOrganizationId.value}/knowledge-base/articles/${kbArticleForm.value.id}`
    : `/organizations/${selectedOrganizationId.value}/knowledge-base/articles`

  const method = kbArticleForm.value.id ? patchJson : postJson
  
  // Format body
  const body = {
    category_id: kbArticleForm.value.category_id ? Number(kbArticleForm.value.category_id) : null,
    title: kbArticleForm.value.title,
    slug: kbArticleForm.value.slug,
    content: kbArticleForm.value.content,
    status: kbArticleForm.value.status,
  }

  const response = await method(url, body, token.value)

  if (response.success) {
    showArticleForm.value = false
    await loadKBArticles()
    setMessage(kbArticleForm.value.id ? "Article updated." : "Article created.")
  } else {
    setMessage(response.message)
  }
}

async function deleteArticle(id: number): Promise<void> {
  if (selectedOrganizationId.value === null) return
  if (!confirm("Are you sure you want to delete this article?")) return

  const response = await postJson(
    `/organizations/${selectedOrganizationId.value}/knowledge-base/articles/${id}`,
    { _method: "DELETE" },
    token.value
  )

  if (response.success) {
    if (selectedKBArticleId.value === id) {
      selectedKBArticleId.value = null
      selectedKBArticle.value = null
    }
    await loadKBArticles()
    setMessage("Article deleted.")
  } else {
    setMessage(response.message)
  }
}

async function toggleArticleStatus(article: any): Promise<void> {
  if (selectedOrganizationId.value === null) return
  const newStatus = article.status === "published" ? "draft" : "published"
  const response = await patchJson(
    `/organizations/${selectedOrganizationId.value}/knowledge-base/articles/${article.id}`,
    { status: newStatus },
    token.value
  )

  if (response.success) {
    await loadKBArticles()
    setMessage(`Article status updated to ${newStatus}.`)
  } else {
    setMessage(response.message)
  }
}

async function searchKBReferences(): Promise<void> {
  if (selectedOrganizationId.value === null) return
  if (kbRefSearchQuery.value.trim() === "") {
    kbRefResults.value = []
    return
  }

  const response = await getJsonWithParams<any[]>(
    `/organizations/${selectedOrganizationId.value}/knowledge-base/articles`,
    { search: kbRefSearchQuery.value, status: "published" },
    token.value
  )

  if (response.success) {
    kbRefResults.value = (response as ApiSuccessResponse<any[]>).data
  }
}

function insertKBReference(art: any): void {
  newMessage.value += (newMessage.value ? "\n\n" : "") + `Reference Article: ${art.title} (Slug: ${art.slug})`
  setMessage("Inserted reference link into reply.")
}

function insertKBContent(art: any): void {
  newMessage.value += (newMessage.value ? "\n\n" : "") + art.content
  setMessage("Inserted article body into reply.")
}

// AI Suggested Reply API Integration
async function generateAiReply(): Promise<void> {
  if (selectedOrganizationId.value === null || selectedTicketId.value === null) {
    return
  }

  aiLoading.value = true
  aiReferencedArticles.value = []
  try {
    const response = await postJson<any>(
      `/organizations/${selectedOrganizationId.value}/tickets/${selectedTicketId.value}/ai-suggest`,
      {},
      token.value
    )

    if (response.success) {
      const payload = (response as ApiSuccessResponse<any>).data
      newMessage.value = payload.suggested_reply
      aiReferencedArticles.value = payload.referenced_articles || []
      aiSuggestionsHistory.value = payload.history || []
      setMessage("AI suggested reply generated successfully.")
    } else {
      setMessage(response.message || "Failed to generate AI suggestion.")
    }
  } catch (error) {
    setMessage("Failed to contact the AI suggestion service. Please try again.")
  } finally {
    aiLoading.value = false
  }
}

function applyHistoricalAiSuggestion(replyText: string): void {
  newMessage.value = replyText
  setMessage("Historical AI suggestion applied to reply editor.")
}

onMounted(async () => {
  if (token.value) {
    await loadSession()
    ensureRealtimeSubscriptions()
  }
})

onBeforeUnmount(() => {
  teardownRealtimeClient()
})
</script>

<template>
  <main class="mx-auto max-w-7xl px-4 py-6 lg:px-8">
    <header class="mb-6 rounded-2xl border border-slate-200 bg-white/90 p-4 shadow-sm">
      <h1 class="text-2xl font-extrabold text-slate-900 lg:text-3xl">Customer Support Dashboard</h1>
      <p class="mt-1 text-sm text-slate-600">{{ appMessage }}</p>
      <p class="mt-2 text-xs font-semibold uppercase tracking-wide" :class="realtimeState === 'connected' ? 'text-emerald-600' : realtimeState === 'connecting' ? 'text-amber-600' : 'text-slate-500'">
        Realtime: {{ realtimeState }}
      </p>
    </header>

    <section v-if="!token" class="grid gap-6 lg:grid-cols-2">
      <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex gap-2">
          <button
            class="rounded-lg px-3 py-2 text-sm font-semibold"
            :class="authMode === 'login' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700'"
            @click="authMode = 'login'"
          >
            Login
          </button>
          <button
            class="rounded-lg px-3 py-2 text-sm font-semibold"
            :class="authMode === 'register' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700'"
            @click="authMode = 'register'"
          >
            Register
          </button>
        </div>

        <form v-if="authMode === 'login'" class="space-y-3" @submit.prevent="handleLogin">
          <input v-model="loginForm.email" type="email" required placeholder="Email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <input v-model="loginForm.password" type="password" required placeholder="Password" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <button :disabled="authLoading" class="w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">
            {{ authLoading ? "Please wait..." : "Sign In" }}
          </button>
        </form>

        <form v-else class="space-y-3" @submit.prevent="handleRegister">
          <input v-model="registerForm.name" type="text" required placeholder="Full name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <input v-model="registerForm.email" type="email" required placeholder="Email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <input v-model="registerForm.password" type="password" required placeholder="Password (min 8)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <input v-model="registerForm.password_confirmation" type="password" required placeholder="Confirm password" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <button :disabled="authLoading" class="w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">
            {{ authLoading ? "Please wait..." : "Create Account" }}
          </button>
        </form>
      </article>

      <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-900">Quick Flow</h2>
        <ol class="mt-3 space-y-2 text-sm text-slate-600">
          <li>1. Register or login</li>
          <li>2. Create or join organization</li>
          <li>3. Create customer ticket with tags/source</li>
          <li>4. Assign ticket and reply with context</li>
          <li>5. Resolve ticket</li>
        </ol>
      </article>
    </section>

    <section v-else class="grid gap-6 lg:grid-cols-[280px_1fr]">
      <aside class="space-y-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Signed in as</p>
          <p class="mt-1 text-sm font-semibold text-slate-900">{{ currentUser?.name }}</p>
          <p class="text-xs text-slate-600">{{ currentUser?.email }}</p>
          <button class="mt-3 w-full rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700" @click="handleLogout">
            Logout
          </button>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-900">Live Notifications</h2>
          <ul v-if="realtimeNotifications.length > 0" class="mt-2 max-h-48 space-y-2 overflow-y-auto">
            <li v-for="notification in realtimeNotifications" :key="notification.id" class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">
              <p>{{ notification.text }}</p>
              <p class="text-[11px] text-slate-500">{{ formatDate(notification.created_at) }}</p>
            </li>
          </ul>
          <p v-else class="mt-2 text-xs text-slate-500">No live notifications yet.</p>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Organization</label>
          <select
            v-model="selectedOrganizationId"
            class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            @change="loadOrganizationData"
          >
            <option v-for="organization in organizations" :key="organization.id" :value="organization.id">
              {{ organization.name }} ({{ organization.role }})
            </option>
          </select>
          <p v-if="selectedOrganizationId" class="mt-2 text-xs text-slate-500">
            Join code:
            {{ organizations.find((organization) => organization.id === selectedOrganizationId)?.join_code }}
          </p>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-900">Create Organization</h2>
          <form class="mt-2 space-y-2" @submit.prevent="createOrganization">
            <input v-model="createOrganizationName" type="text" placeholder="Workspace name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <button class="w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Create</button>
          </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-900">Join Organization</h2>
          <form class="mt-2 space-y-2" @submit.prevent="joinOrganization">
            <input v-model="joinCode" type="text" placeholder="Join code" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <button class="w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Join</button>
          </form>
        </article>
      </aside>

      <section class="space-y-4">
        <!-- Tabs Navigation -->
        <div class="flex border-b border-slate-200 bg-white rounded-2xl shadow-sm overflow-hidden p-1 gap-1">
          <button
            class="flex-1 py-2 text-sm font-semibold rounded-lg transition-all duration-200"
            :class="activeTab === 'dashboard' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
            @click="activeTab = 'dashboard'"
          >
            Tickets & Customers
          </button>
          <button
            class="flex-1 py-2 text-sm font-semibold rounded-lg transition-all duration-200"
            :class="activeTab === 'knowledge-base' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
            @click="activeTab = 'knowledge-base'; loadKBData()"
          >
            Knowledge Base
          </button>
          <button
            class="flex-1 py-2 text-sm font-semibold rounded-lg transition-all duration-200 flex items-center justify-center gap-1"
            :class="activeTab === 'automations' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
            @click="activeTab = 'automations'; loadAutomationsData()"
          >
            ⚡ Automations
          </button>
        </div>

        <!-- ⚡ Automations Tab Content -->
        <div v-if="activeTab === 'automations'" class="space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Workflow Automation</h2>
              <p class="text-xs text-slate-500">Automate your support event workflows with custom rule builders.</p>
            </div>
            <button
              v-if="canManageKB"
              @click="showRuleModal = true"
              class="rounded-lg bg-blue-600 hover:bg-blue-700 px-3.5 py-2 text-sm font-semibold text-white transition cursor-pointer"
            >
              + Create Rule
            </button>
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <!-- Active Rules Card -->
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm space-y-4">
              <h3 class="text-sm font-bold text-slate-800 flex items-center gap-1.5">
                ⚡ Active Automation Rules ({{ automationRules.length }})
              </h3>

              <div v-if="automationRules.length > 0" class="space-y-3">
                <div
                  v-for="rule in automationRules"
                  :key="rule.id"
                  class="rounded-xl border border-slate-100 bg-slate-50/50 p-3.5 space-y-2 relative"
                >
                  <div class="flex items-start justify-between">
                    <div>
                      <h4 class="text-sm font-extrabold text-slate-800">{{ rule.name }}</h4>
                      <p class="text-[10px] text-slate-400 font-medium uppercase mt-0.5">
                        Trigger: <span class="text-purple-600 font-bold capitalize">{{ rule.trigger_type.replace('_', ' ') }}</span>
                      </p>
                    </div>
                    <div class="flex items-center gap-2">
                      <button
                        v-if="canManageKB"
                        @click="toggleRule(rule.id)"
                        class="text-[10px] font-bold px-2 py-1 rounded-md transition duration-200 cursor-pointer border"
                        :class="rule.is_active 
                          ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' 
                          : 'bg-slate-100 text-slate-600 border-slate-200 hover:bg-slate-200'"
                      >
                        {{ rule.is_active ? 'Active' : 'Disabled' }}
                      </button>
                      <button
                        v-if="canManageKB"
                        @click="deleteRule(rule.id)"
                        class="text-[10px] font-bold text-red-600 hover:text-red-800 bg-red-50 hover:bg-red-100 px-2 py-1 rounded-md transition cursor-pointer"
                      >
                        Delete
                      </button>
                    </div>
                  </div>

                  <!-- Conditions -->
                  <div class="text-xs space-y-1" v-if="rule.conditions && rule.conditions.length > 0">
                    <span class="text-slate-400 font-semibold uppercase tracking-wider text-[10px]">Conditions:</span>
                    <div class="flex flex-wrap gap-1 mt-0.5">
                      <span
                        v-for="cond in rule.conditions"
                        :key="cond.id"
                        class="bg-blue-50/80 border border-blue-100 text-blue-700 px-2 py-0.5 rounded-md text-[10px] capitalize font-medium"
                      >
                        IF <span class="font-bold text-blue-800">{{ cond.field }}</span> is {{ cond.value }}
                      </span>
                    </div>
                  </div>

                  <!-- Actions -->
                  <div class="text-xs space-y-1">
                    <span class="text-slate-400 font-semibold uppercase tracking-wider text-[10px]">Actions:</span>
                    <div class="flex flex-wrap gap-1 mt-0.5">
                      <span
                        v-for="act in rule.actions"
                        :key="act.id"
                        class="bg-purple-50/80 border border-purple-100 text-purple-700 px-2 py-0.5 rounded-md text-[10px] font-medium"
                      >
                        THEN <span class="font-bold text-purple-800">{{ act.action_type.replace(/_/g, ' ') }}</span>
                        <span v-if="act.action_value" class="text-purple-600 font-bold">({{ act.action_value }})</span>
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div v-else class="text-center py-8">
                <p class="text-sm text-slate-500 italic">No rules created yet. Automate your support flow by creating a rule!</p>
              </div>
            </article>

            <!-- Guide Card -->
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
              <h3 class="text-sm font-bold text-slate-800">⚡ Automation Quick Reference</h3>
              <p class="text-xs text-slate-600">
                Workflows follow a standard event-driven architecture using trigger-condition-action logic:
              </p>
              <ul class="space-y-2 text-xs text-slate-600">
                <li class="flex items-start gap-2">
                  <span class="bg-blue-100 text-blue-800 font-extrabold px-2 py-0.5 rounded-md text-[10px]">1. Event Triggers</span>
                  <span>Rule executes when events like ticket creation or AI classification sentiment/category changes occur.</span>
                </li>
                <li class="flex items-start gap-2">
                  <span class="bg-blue-100 text-blue-800 font-extrabold px-2 py-0.5 rounded-md text-[10px]">2. Filters / Conditions</span>
                  <span>Restricts triggers by evaluating specific ticket parameters (e.g. status is open, sentiment is angry).</span>
                </li>
                <li class="flex items-start gap-2">
                  <span class="bg-blue-100 text-blue-800 font-extrabold px-2 py-0.5 rounded-md text-[10px]">3. Auto Actions</span>
                  <span>Performs backend automation (e.g. assign key agents, raise ticket priorities, add system notes).</span>
                </li>
              </ul>

              <div class="rounded-xl bg-purple-50/50 border border-purple-100 p-3 mt-4 space-y-1.5">
                <h4 class="text-xs font-bold text-purple-800">✨ CV Value Focus</h4>
                <p class="text-[11px] text-purple-700">
                  This engine demonstrates production-quality expertise in building event-driven microservices, clean relational SQL schemas, multi-tenant workspace scoped engines, and seamless AI integrations.
                </p>
              </div>
            </article>
          </div>
        </div>

        <div v-if="activeTab === 'dashboard'" class="space-y-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-900">Create Ticket</h2>
          <form class="mt-3 grid gap-2 md:grid-cols-3" @submit.prevent="createTicket">
            <input v-model="createTicketForm.customer_name" type="text" required placeholder="Customer name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input v-model="createTicketForm.customer_email" type="email" placeholder="Customer email" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input v-model="createTicketForm.customer_phone" type="text" placeholder="Customer phone" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input v-model="createTicketForm.customer_tags_text" type="text" placeholder="Customer tags (comma separated)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-2" />
            <input v-model="createTicketForm.customer_source_channel" type="text" placeholder="Customer source channel" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input v-model="createTicketForm.subject" type="text" required placeholder="Ticket subject" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-3" />
            <input v-model="createTicketForm.category" type="text" placeholder="Category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <select v-model="createTicketForm.priority" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
            </select>
            <input v-model="createTicketForm.source_channel" type="text" placeholder="Ticket source channel" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <textarea v-model="createTicketForm.message" required placeholder="Customer message" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-3" rows="3"></textarea>
            <button class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white md:col-span-3">Create Ticket</button>
          </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-900">Ticket Filters</h2>
          <div class="mt-3 grid gap-2 md:grid-cols-5">
            <input v-model="ticketFilters.search" type="text" placeholder="Search" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <select v-model="ticketFilters.status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All status</option>
              <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
            </select>
            <select v-model="ticketFilters.priority" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All priority</option>
              <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
            </select>
            <select v-model="ticketFilters.assignee_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">All assignees</option>
              <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
            </select>
            <input v-model="ticketFilters.category" type="text" placeholder="Category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <button class="mt-3 rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white" @click="loadTickets">
            Apply Filters
          </button>
        </article>

        <section class="grid gap-4 lg:grid-cols-2">
          <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-900">Customers</h2>
            <div class="mt-3 grid gap-2 md:grid-cols-3">
              <input v-model="customerFilters.search" type="text" placeholder="Search customer" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              <input v-model="customerFilters.source_channel" type="text" placeholder="Source channel" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              <input v-model="customerFilters.tag" type="text" placeholder="Tag" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <button class="mt-2 rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white" @click="loadCustomers">Apply Customer Filters</button>

            <ul class="mt-3 max-h-72 space-y-2 overflow-y-auto pr-1">
              <li v-for="customer in customers" :key="customer.id">
                <button
                  class="w-full rounded-lg border px-3 py-2 text-left text-sm"
                  :class="selectedCustomerId === customer.id ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-white'"
                  @click="selectCustomer(customer.id)"
                >
                  <p class="font-semibold text-slate-900">{{ customer.name }}</p>
                  <p class="text-xs text-slate-600">{{ customer.email || '-' }} • {{ customer.source_channel || 'unknown source' }}</p>
                  <p class="text-xs text-slate-500">Tags: {{ customer.tags.length > 0 ? customer.tags.join(', ') : 'none' }}</p>
                </button>
              </li>
            </ul>
          </article>

          <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <template v-if="selectedCustomer">
              <h2 class="text-sm font-semibold text-slate-900">Customer Detail</h2>
              <p class="mt-1 text-xs text-slate-600">Last contacted: {{ formatDate(selectedCustomer.last_contacted_at) }}</p>

              <div class="mt-3 grid gap-2 md:grid-cols-2">
                <input v-model="customerUpdateForm.name" type="text" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" :disabled="!canUpdateCustomer" />
                <input v-model="customerUpdateForm.email" type="email" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" :disabled="!canUpdateCustomer" />
                <input v-model="customerUpdateForm.phone" type="text" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" :disabled="!canUpdateCustomer" />
                <input v-model="customerUpdateForm.source_channel" type="text" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" :disabled="!canUpdateCustomer" />
                <input v-model="customerUpdateForm.tags_text" type="text" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-2" :disabled="!canUpdateCustomer" />
              </div>

              <button
                class="mt-2 rounded-lg px-3 py-2 text-sm font-semibold text-white"
                :class="canUpdateCustomer ? 'bg-blue-600' : 'bg-slate-300'"
                :disabled="!canUpdateCustomer"
                @click="updateCustomerProfile"
              >
                Update Customer
              </button>

              <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Customer Ticket History</h3>
              <ul class="mt-2 max-h-36 space-y-2 overflow-y-auto pr-1">
                <li v-for="history in selectedCustomer.ticket_history" :key="history.id" class="rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm">
                  <p class="font-semibold text-slate-900">{{ history.subject }}</p>
                  <p class="text-xs text-slate-600">{{ history.status }} • {{ history.priority }} • {{ history.category || 'uncategorized' }}</p>
                </li>
              </ul>
            </template>
            <template v-else>
              <p class="text-sm text-slate-500">Select a customer to view details.</p>
            </template>
          </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
          <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-900">Tickets</h2>
            <p v-if="dashboardLoading" class="mt-3 text-sm text-slate-500">Loading tickets...</p>
            <ul v-else class="mt-3 max-h-72 space-y-2 overflow-y-auto pr-1">
              <li v-for="ticket in tickets" :key="ticket.id">
                <button
                  class="w-full rounded-lg border px-3 py-2 text-left text-sm"
                  :class="selectedTicketId === ticket.id ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-white'"
                  @click="selectTicket(ticket.id)"
                >
                  <p class="font-semibold text-slate-900">{{ ticket.subject }}</p>
                  <p class="text-xs text-slate-600">
                    {{ ticket.status }} • {{ ticket.priority }} • {{ ticket.category || "uncategorized" }}
                  </p>
                  <p class="text-xs text-slate-500">
                    {{ ticket.customer?.name || "No customer" }} • {{ ticket.assignee?.name || "Unassigned" }}
                  </p>
                </button>
              </li>
            </ul>
          </article>

          <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <template v-if="selectedTicket">
              <h2 class="text-sm font-semibold text-slate-900">{{ selectedTicket.subject }}</h2>
              <p class="mt-1 text-xs text-slate-600">
                Customer: {{ selectedTicket.customer?.name || "-" }} • Assignee: {{ selectedTicket.assignee?.name || "Unassigned" }}
              </p>

              <div class="mt-2 rounded-lg border border-blue-100 bg-blue-50 p-3 text-xs text-slate-700" v-if="selectedTicket.customer">
                <p><span class="font-semibold">Customer Source:</span> {{ selectedTicket.customer.source_channel || "unknown" }}</p>
                <p><span class="font-semibold">Tags:</span> {{ selectedTicket.customer.tags.length > 0 ? selectedTicket.customer.tags.join(', ') : 'none' }}</p>
                <p><span class="font-semibold">Last Contacted:</span> {{ formatDate(selectedTicket.customer.last_contacted_at) }}</p>
              </div>

              <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Customer Ticket History</h3>
              <ul class="mt-1 max-h-24 space-y-1 overflow-y-auto pr-1">
                <li v-for="history in selectedTicket.customer_ticket_history" :key="history.id" class="rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
                  {{ history.subject }} ({{ history.status }} / {{ history.priority }})
                </li>
              </ul>

              <!-- ✨ AI Ticket Classification Widget -->
              <div class="mt-3.5 bg-purple-50/50 border border-purple-100 rounded-xl p-3.5 space-y-2">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-1.5">
                    <span class="text-xs font-extrabold uppercase tracking-wider text-purple-700 bg-purple-100/80 px-2 py-0.5 rounded-md flex items-center gap-1">
                      ✨ AI Classification
                    </span>
                  </div>
                  <button
                    @click="runClassification"
                    :disabled="aiClassificationLoading"
                    class="text-[10px] font-bold text-purple-700 hover:text-purple-900 bg-purple-100/50 hover:bg-purple-100 px-2 py-1 rounded-md transition duration-200 cursor-pointer disabled:opacity-50"
                  >
                    {{ aiClassificationLoading ? "Analyzing..." : "Re-classify" }}
                  </button>
                </div>

                <div v-if="selectedTicket.ai_category || selectedTicket.ai_sentiment || selectedTicket.ai_priority" class="space-y-1.5 pt-1">
                  <!-- Category Suggestion -->
                  <div class="flex items-center justify-between text-xs">
                    <span class="text-slate-500">Suggested Category:</span>
                    <div class="flex items-center gap-1">
                      <span class="font-bold text-purple-900 bg-purple-100/30 px-2 py-0.5 rounded-md border border-purple-100/50 capitalize">
                        {{ selectedTicket.ai_category?.replace('_', ' ') }} ({{ Math.round((selectedTicket.ai_confidence || 0) * 100) }}%)
                      </span>
                      <button
                        v-if="selectedTicket.ai_category && selectedTicket.ai_category !== selectedTicket.category"
                        @click="updateTicketCategory(selectedTicket.ai_category)"
                        title="Apply Category Suggestion"
                        class="text-[10px] bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-1.5 py-0.5 rounded-md transition cursor-pointer flex items-center gap-0.5"
                      >
                        ✓ Apply
                      </button>
                    </div>
                  </div>

                  <!-- Sentiment Analysis -->
                  <div class="flex items-center justify-between text-xs">
                    <span class="text-slate-500">Detected Sentiment:</span>
                    <span class="font-semibold capitalize text-purple-800 flex items-center gap-1">
                      <span v-if="selectedTicket.ai_sentiment === 'angry'">😠 Angry</span>
                      <span v-else-if="selectedTicket.ai_sentiment === 'frustrated'">😟 Frustrated</span>
                      <span v-else-if="selectedTicket.ai_sentiment === 'satisfied'">😊 Satisfied</span>
                      <span v-else>😐 Neutral</span>
                    </span>
                  </div>

                  <!-- Priority Suggestion -->
                  <div class="flex items-center justify-between text-xs">
                    <span class="text-slate-500">Suggested Priority:</span>
                    <div class="flex items-center gap-1">
                      <span class="font-bold uppercase tracking-wider text-xs px-2 py-0.5 rounded-md border" 
                        :class="{
                          'bg-red-50 text-red-700 border-red-100': selectedTicket.ai_priority === 'urgent',
                          'bg-amber-50 text-amber-700 border-amber-100': selectedTicket.ai_priority === 'high',
                          'bg-blue-50 text-blue-700 border-blue-100': selectedTicket.ai_priority === 'medium',
                          'bg-slate-50 text-slate-700 border-slate-100': selectedTicket.ai_priority === 'low'
                        }"
                      >
                        {{ selectedTicket.ai_priority }}
                      </span>
                      <button
                        v-if="selectedTicket.ai_priority && selectedTicket.ai_priority !== selectedTicket.priority"
                        @click="quickApplyPriority(selectedTicket.ai_priority)"
                        title="Apply Priority Suggestion"
                        class="text-[10px] bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-1.5 py-0.5 rounded-md transition cursor-pointer flex items-center gap-0.5"
                      >
                        ✓ Apply
                      </button>
                    </div>
                  </div>
                </div>

                <div v-else class="text-xs text-slate-500 italic text-center py-2">
                  No AI insights generated yet. Click Re-classify to analyze.
                </div>
              </div>

              <div class="mt-3 grid gap-2 md:grid-cols-3">
                <div>
                  <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                  <select v-model="updateStatusValue" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                  </select>
                  <button class="mt-2 w-full rounded-lg bg-slate-900 px-3 py-2 text-[11px] font-semibold text-white" @click="updateTicketStatus">
                    Update Status
                  </button>
                </div>

                <div>
                  <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Category</label>
                  <select v-model="updateCategoryValue" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm capitalize">
                    <option value="">None</option>
                    <option v-for="cat in ticketCategories" :key="cat" :value="cat">{{ cat.replace('_', ' ') }}</option>
                  </select>
                  <button class="mt-2 w-full rounded-lg bg-slate-900 px-3 py-2 text-[11px] font-semibold text-white" @click="updateTicketCategory(undefined)">
                    Update Category
                  </button>
                </div>

                <div>
                  <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Priority</label>
                  <select v-model="updatePriorityValue" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
                  </select>
                  <button
                    class="mt-2 w-full rounded-lg bg-slate-900 px-3 py-2 text-[11px] font-semibold text-white"
                    @click="updateTicketPriority"
                  >
                    Update Priority
                  </button>
                </div>
              </div>

              <!-- ⚡ Automation Runs Execution Logs Widget -->
              <div v-if="automationRuns.length > 0" class="mt-3.5 bg-slate-50/50 border border-slate-200 rounded-xl p-3.5 space-y-2">
                <h4 class="text-xs font-bold text-slate-700 flex items-center gap-1">
                  ⚡ Rule Executions ({{ automationRuns.length }})
                </h4>
                <div class="space-y-1.5 max-h-24 overflow-y-auto pr-1">
                  <div v-for="run in automationRuns" :key="run.id" class="flex items-start justify-between text-[11px] rounded-lg border border-slate-100 bg-white p-2">
                    <div>
                      <span class="font-bold text-slate-800 text-left block">{{ run.rule?.name || 'Automation Rule' }}</span>
                      <p class="text-[9px] text-slate-400 font-medium mt-0.5 text-left">
                        Trigger: {{ run.logs?.trigger_type?.replace('_', ' ') }}
                      </p>
                    </div>
                    <span
                      class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded-md"
                      :class="run.status === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-red-50 text-red-700 border border-red-100'"
                    >
                      {{ run.status }}
                    </span>
                  </div>
                </div>
              </div>

              <div class="mt-3">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Assign to Agent</label>
                <div class="mt-1 flex gap-2">
                  <select v-model="assignAssigneeId" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Select assignee</option>
                    <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }} ({{ member.role }})</option>
                  </select>
                  <button
                    class="rounded-lg px-3 py-2 text-sm font-semibold text-white"
                    :class="canManageAssignments ? 'bg-slate-900' : 'bg-slate-300'"
                    :disabled="!canManageAssignments"
                    @click="assignTicket"
                  >
                    Assign
                  </button>
                </div>
              </div>

              <div class="mt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Conversation</h3>
                <ul class="mt-2 max-h-40 space-y-2 overflow-y-auto pr-1">
                  <li v-for="message in selectedTicket.messages" :key="message.id" class="rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm">
                    <p class="font-semibold text-slate-800">
                      {{ message.sender_type }}<span v-if="message.sender_user">: {{ message.sender_user.name }}</span>
                    </p>
                    <p class="text-slate-700">{{ message.body }}</p>
                  </li>
                </ul>

                <!-- AI Copilot Trigger Bar -->
                <div class="mt-3 mb-2 flex items-center justify-between bg-purple-50/50 border border-purple-100 rounded-xl p-2.5">
                  <div class="flex items-center gap-2">
                    <span class="text-xs font-extrabold uppercase tracking-wider text-purple-700 bg-purple-100/80 px-2 py-0.5 rounded-md flex items-center gap-1">
                      ✨ AI Copilot
                    </span>
                    <span class="text-xs text-purple-600 hidden sm:inline">Draft grounded solutions in one click.</span>
                  </div>
                  <button
                    type="button"
                    class="rounded-lg bg-purple-600 hover:bg-purple-700 px-3 py-1.5 text-xs font-bold text-white shadow-xs transition duration-200 flex items-center gap-1 cursor-pointer"
                    :disabled="aiLoading"
                    @click="generateAiReply"
                  >
                    <span v-if="aiLoading" class="animate-spin inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full mr-1"></span>
                    {{ aiLoading ? 'Reading KB...' : 'Generate AI Reply' }}
                  </button>
                </div>

                <div class="mt-2 flex gap-2">
                  <select v-model="newMessageSenderType" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="agent">agent</option>
                    <option value="customer">customer</option>
                  </select>
                  <input
                    v-model="newMessage"
                    type="text"
                    placeholder="Add reply"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @input="whisperTyping"
                  />
                  <button class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white" @click="addMessage">
                    Send
                  </button>
                </div>
                <p v-if="typingIndicator" class="mt-1 text-xs text-emerald-700">{{ typingIndicator }}</p>

                <!-- AI Grounding References & History Panel -->
                <div v-if="aiReferencedArticles.length > 0 || aiSuggestionsHistory.length > 0" class="mt-3 bg-purple-50/30 border border-purple-100/50 rounded-xl p-3 space-y-2.5">
                  <!-- Grounding References -->
                  <div v-if="aiReferencedArticles.length > 0">
                    <h4 class="text-[10px] font-bold text-purple-700 uppercase tracking-wider mb-1 flex items-center gap-1">
                      📚 Articles Referenced by AI
                    </h4>
                    <div class="flex flex-wrap gap-1.5">
                      <span
                        v-for="refArt in aiReferencedArticles"
                        :key="refArt.id"
                        class="text-[10px] bg-purple-100/60 border border-purple-200/50 text-purple-800 px-2 py-0.5 rounded-md font-semibold"
                      >
                        {{ refArt.title }}
                      </span>
                    </div>
                  </div>

                  <!-- History Accordion -->
                  <div v-if="aiSuggestionsHistory.length > 1">
                    <h4 class="text-[10px] font-bold text-purple-700 uppercase tracking-wider mb-1.5 flex items-center gap-1">
                      🕰️ Suggestion History
                    </h4>
                    <div class="max-h-24 overflow-y-auto space-y-1.5 pr-0.5">
                      <div
                        v-for="hist in aiSuggestionsHistory.slice(1)"
                        :key="hist.id"
                        class="p-2 bg-white border border-purple-100/50 rounded-lg flex items-center justify-between gap-3 shadow-2xs"
                      >
                        <p class="text-[11px] text-slate-600 truncate flex-1 italic">"{{ hist.suggested_reply }}"</p>
                        <button
                          type="button"
                          class="text-[10px] font-bold text-purple-600 hover:text-purple-700 shrink-0 uppercase tracking-wider underline cursor-pointer"
                          @click="applyHistoricalAiSuggestion(hist.suggested_reply)"
                        >
                          Apply
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Knowledge Base Reference Attach Widget -->
                <div class="mt-3 bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-xs">
                  <div class="flex items-center justify-between mb-1.5">
                    <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">KB References</h4>
                    <button
                      type="button"
                      class="text-xs text-blue-600 hover:text-blue-700 hover:underline font-bold transition"
                      @click="showKBReferenceSearch = !showKBReferenceSearch"
                    >
                      {{ showKBReferenceSearch ? 'Hide Search' : 'Search Articles' }}
                    </button>
                  </div>
                  
                  <div v-if="showKBReferenceSearch" class="space-y-2">
                    <input
                      v-model="kbRefSearchQuery"
                      type="text"
                      placeholder="Search refund, shipping, account..."
                      class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                      @input="searchKBReferences"
                    />
                    
                    <ul v-if="kbRefResults.length > 0" class="max-h-28 overflow-y-auto space-y-1 pr-0.5">
                      <li v-for="art in kbRefResults" :key="art.id" class="flex items-center justify-between p-1.5 bg-white border border-slate-100 rounded-lg hover:bg-slate-50 shadow-2xs gap-2">
                        <div class="min-w-0 flex-1">
                          <p class="text-xs font-bold text-slate-800 truncate">{{ art.title }}</p>
                          <p class="text-[10px] text-slate-400 mt-0.5">{{ art.category?.name || 'Uncategorized' }}</p>
                        </div>
                        <div class="flex gap-1 shrink-0">
                          <button
                            type="button"
                            class="text-[10px] px-2 py-1 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-md border border-blue-200 font-bold transition"
                            @click="insertKBReference(art)"
                          >
                            Link
                          </button>
                          <button
                            type="button"
                            class="text-[10px] px-2 py-1 bg-slate-50 text-slate-700 hover:bg-slate-100 rounded-md border border-slate-200 font-bold transition"
                            @click="insertKBContent(art)"
                          >
                            Text
                          </button>
                        </div>
                      </li>
                    </ul>
                    <p v-else-if="kbRefSearchQuery.trim() !== ''" class="text-[10px] text-slate-400 text-center py-2">No articles matched. Search terms like 'Refund' or 'Shipping'.</p>
                  </div>
                </div>
              </div>

              <div class="mt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Internal Notes</h3>
                <ul class="mt-2 max-h-32 space-y-2 overflow-y-auto pr-1">
                  <li v-for="note in selectedTicket.notes" :key="note.id" class="rounded-lg border border-slate-200 bg-amber-50 p-2 text-sm">
                    <p class="font-semibold text-slate-800">{{ note.user?.name || "Unknown" }}</p>
                    <p class="text-slate-700">{{ note.note }}</p>
                  </li>
                </ul>
                <div class="mt-2 flex gap-2">
                  <input v-model="newNote" type="text" placeholder="Add internal note" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <button class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white" @click="addNote">
                    Add
                  </button>
                </div>
              </div>
            </template>

            <template v-else>
              <p class="text-sm text-slate-500">Select a ticket to view details.</p>
            </template>
          </article>
        </section>
        </div>

        <!-- Knowledge Base View -->
        <div v-else-if="activeTab === 'knowledge-base'" class="space-y-6">
          <!-- Top Filtering Actions -->
          <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div>
                <h2 class="text-lg font-bold text-slate-900">Knowledge Base Articles</h2>
                <p class="text-xs text-slate-500">Manage business guidelines, refund instructions, and shipping procedures.</p>
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  v-if="canManageKB"
                  class="rounded-lg bg-blue-600 hover:bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition duration-200"
                  @click="openCreateArticle"
                >
                  Create Article
                </button>
                <button
                  v-if="canManageKB"
                  class="rounded-lg bg-slate-100 hover:bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition duration-200"
                  @click="openCreateCategory"
                >
                  Manage Categories
                </button>
              </div>
            </div>

            <!-- Filters -->
            <div class="mt-4 grid gap-2 sm:grid-cols-3">
              <input
                v-model="kbSearchQuery"
                type="text"
                placeholder="Search title, content, or tags..."
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                @input="loadKBArticles"
              />
              <select
                v-model="kbSelectedCategoryId"
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                @change="loadKBArticles"
              >
                <option value="">All Categories</option>
                <option v-for="cat in kbCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
              </select>
              <select
                v-model="kbSelectedStatus"
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                @change="loadKBArticles"
              >
                <option value="">All Statuses</option>
                <option value="published">Published</option>
                <option value="draft">Draft</option>
              </select>
            </div>
          </article>

          <!-- Main Layout Grid -->
          <div class="grid gap-6 lg:grid-cols-[1fr_360px] xl:grid-cols-[1fr_420px]">
            <!-- Articles List & View -->
            <div class="space-y-4">
              <div class="grid gap-4 md:grid-cols-2">
                <!-- Left Pane: Articles List -->
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm flex flex-col h-[580px]">
                  <h3 class="text-sm font-bold text-slate-800 mb-3 uppercase tracking-wider">Articles</h3>
                  
                  <div v-if="kbLoading" class="text-slate-500 text-sm py-4 text-center">Loading articles...</div>
                  <div v-else-if="kbArticles.length === 0" class="text-slate-500 text-sm py-8 text-center flex-1 flex flex-col items-center justify-center">
                    <p class="font-semibold text-slate-700">No articles found</p>
                    <p class="text-xs mt-1">Try resetting your search filters or write a new article.</p>
                  </div>
                  
                  <ul v-else class="space-y-2 overflow-y-auto pr-1 flex-1">
                    <li v-for="art in kbArticles" :key="art.id">
                      <button
                        class="w-full rounded-xl border p-3 text-left transition-all duration-200"
                        :class="selectedKBArticleId === art.id ? 'border-blue-300 bg-blue-50/50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                        @click="selectKBArticle(art.id)"
                      >
                        <div class="flex items-start justify-between gap-2">
                          <h4 class="font-bold text-slate-900 text-sm line-clamp-1">{{ art.title }}</h4>
                          <span
                            class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider"
                            :class="art.status === 'published' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                          >
                            {{ art.status }}
                          </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1 line-clamp-2">{{ art.content }}</p>
                        <div class="mt-2 flex items-center justify-between text-[10px] text-slate-400 font-semibold">
                          <span>Category: {{ art.category?.name || 'Uncategorized' }}</span>
                          <span>Updated: {{ formatDate(art.updated_at) }}</span>
                        </div>
                      </button>
                    </li>
                  </ul>
                </div>

                <!-- Right Pane: Active Article View -->
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm flex flex-col h-[580px]">
                  <template v-if="selectedKBArticle">
                    <div class="flex items-start justify-between gap-4 pb-3 border-b border-slate-100">
                      <div>
                        <span class="text-[10px] bg-slate-100 border border-slate-200 text-slate-600 px-2 py-0.5 rounded-md font-bold uppercase tracking-wide">
                          {{ selectedKBArticle.category?.name || 'Uncategorized' }}
                        </span>
                        <h3 class="text-lg font-extrabold text-slate-900 mt-1">{{ selectedKBArticle.title }}</h3>
                        <p class="text-[10px] text-slate-500 mt-1">
                          Created by: {{ selectedKBArticle.creator?.name || 'System' }} • Updated: {{ formatDate(selectedKBArticle.updated_at) }}
                        </p>
                      </div>
                      <span
                        class="text-[10px] px-2.5 py-1 rounded-full font-bold uppercase tracking-wider shadow-sm"
                        :class="selectedKBArticle.status === 'published' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                      >
                        {{ selectedKBArticle.status }}
                      </span>
                    </div>

                    <!-- Article Content Body -->
                    <div class="flex-1 overflow-y-auto py-3 text-sm text-slate-700 whitespace-pre-wrap leading-relaxed">
                      {{ selectedKBArticle.content }}
                    </div>

                    <!-- Article Actions -->
                    <div v-if="canManageKB" class="pt-3 border-t border-slate-100 flex flex-wrap gap-2">
                      <button
                        class="flex-1 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 px-3 py-2 text-xs font-bold border border-blue-200 transition"
                        @click="openEditArticle(selectedKBArticle)"
                      >
                        Edit Article
                      </button>
                      <button
                        class="flex-1 rounded-lg px-3 py-2 text-xs font-bold transition border"
                        :class="selectedKBArticle.status === 'published' ? 'bg-amber-50 text-amber-700 hover:bg-amber-100 border-amber-200' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border-emerald-200'"
                        @click="toggleArticleStatus(selectedKBArticle)"
                      >
                        {{ selectedKBArticle.status === 'published' ? 'Unpublish' : 'Publish' }}
                      </button>
                      <button
                        class="rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100 px-3 py-2 text-xs font-bold border border-rose-200 transition"
                        @click="deleteArticle(selectedKBArticle.id)"
                      >
                        Delete
                      </button>
                    </div>
                  </template>
                  <template v-else>
                    <div class="flex flex-col items-center justify-center h-full text-center text-slate-500">
                      <p class="font-bold text-slate-700">No article selected</p>
                      <p class="text-xs mt-1">Select an article from the list to view its contents.</p>
                    </div>
                  </template>
                </div>
              </div>
            </div>

            <!-- Categories Management Pane -->
            <div class="space-y-4">
              <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                  <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Categories</h3>
                  <button
                    v-if="canManageKB"
                    class="text-xs text-blue-600 hover:underline font-bold"
                    @click="openCreateCategory"
                  >
                    + Add New
                  </button>
                </div>

                <ul class="space-y-2 max-h-[460px] overflow-y-auto pr-1">
                  <li v-for="cat in kbCategories" :key="cat.id" class="rounded-xl border border-slate-100 bg-slate-50/50 p-3">
                    <div class="flex items-start justify-between">
                      <div>
                        <h4 class="font-bold text-slate-900 text-sm">{{ cat.name }}</h4>
                        <p class="text-[11px] text-slate-500 mt-0.5">{{ cat.description || 'No description provided.' }}</p>
                        <p class="text-[10px] text-slate-400 font-bold mt-1.5 uppercase">Articles: {{ cat.articles_count ?? 0 }}</p>
                      </div>
                      <div v-if="canManageKB" class="flex gap-1">
                        <button
                          class="text-[10px] text-blue-600 hover:underline font-bold px-1.5 py-0.5 bg-white border border-slate-200 rounded shadow-xs"
                          @click="openEditCategory(cat)"
                        >
                          Edit
                        </button>
                        <button
                          class="text-[10px] text-rose-600 hover:underline font-bold px-1.5 py-0.5 bg-white border border-slate-200 rounded shadow-xs"
                          @click="deleteCategory(cat.id)"
                        >
                          Delete
                        </button>
                      </div>
                    </div>
                  </li>
                </ul>
              </article>
            </div>
          </div>

          <!-- Modals & Overlays -->

          <!-- 1. Category Form Modal -->
          <div v-if="showCategoryForm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-100 transition-all duration-300">
              <h3 class="text-lg font-bold text-slate-900 mb-4">{{ isEditingCategory ? 'Edit Category' : 'Create Category' }}</h3>
              <form class="space-y-4" @submit.prevent="saveCategory">
                <div>
                  <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Name</label>
                  <input
                    v-model="kbCategoryForm.name"
                    type="text"
                    required
                    placeholder="e.g. Refund Rules"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    @input="generateCategorySlug"
                  />
                </div>
                <div>
                  <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Slug</label>
                  <input
                    v-model="kbCategoryForm.slug"
                    type="text"
                    required
                    placeholder="e.g. refund-rules"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Description</label>
                  <textarea
                    v-model="kbCategoryForm.description"
                    placeholder="Short description of articles..."
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    rows="3"
                  ></textarea>
                </div>
                <div class="flex gap-2 justify-end pt-2">
                  <button
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white hover:bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700"
                    @click="showCategoryForm = false"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    class="rounded-lg bg-blue-600 hover:bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-sm"
                  >
                    Save Category
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- 2. Article Form Modal -->
          <div v-if="showArticleForm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl border border-slate-100 transition-all duration-300">
              <h3 class="text-lg font-bold text-slate-900 mb-4">{{ isEditingArticle ? 'Edit KB Article' : 'Create KB Article' }}</h3>
              <form class="space-y-4" @submit.prevent="saveArticle">
                <div class="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Title</label>
                    <input
                      v-model="kbArticleForm.title"
                      type="text"
                      required
                      placeholder="e.g. How to process standard refunds"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                      @input="generateArticleSlug"
                    />
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Slug</label>
                    <input
                      v-model="kbArticleForm.slug"
                      type="text"
                      required
                      placeholder="e.g. how-to-process-standard-refunds"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    />
                  </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Category</label>
                    <select
                      v-model="kbArticleForm.category_id"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    >
                      <option value="">Uncategorized</option>
                      <option v-for="cat in kbCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Status</label>
                    <select
                      v-model="kbArticleForm.status"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    >
                      <option value="draft">Draft</option>
                      <option value="published">Published</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Content</label>
                  <textarea
                    v-model="kbArticleForm.content"
                    required
                    placeholder="Write article details..."
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    rows="10"
                  ></textarea>
                </div>

                <div class="flex gap-2 justify-end pt-2">
                  <button
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white hover:bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700"
                    @click="showArticleForm = false"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    class="rounded-lg bg-blue-600 hover:bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-sm"
                  >
                    Save Article
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </section>
    </section>
  </main>

  <!-- Create Rule Modal -->
  <div v-if="showRuleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="relative w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl space-y-4">
      <div class="flex items-center justify-between border-b border-slate-100 pb-3">
        <h3 class="text-base font-extrabold text-slate-900">Create Workflow Rule</h3>
        <button @click="showRuleModal = false" class="text-slate-400 hover:text-slate-600 font-bold text-sm cursor-pointer">✕</button>
      </div>

      <form @submit.prevent="createAutomationRule" class="space-y-4">
        <!-- Rule Name -->
        <div>
          <label class="text-xs font-bold uppercase tracking-wider text-slate-500">Rule Name</label>
          <input v-model="ruleForm.name" type="text" required placeholder="e.g. Angry Refund Escalation" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-hidden" />
        </div>

        <!-- Trigger Type -->
        <div>
          <label class="text-xs font-bold uppercase tracking-wider text-slate-500">Event Trigger</label>
          <select v-model="ruleForm.trigger_type" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-hidden">
            <option value="ticket_created">Ticket Created</option>
            <option value="ticket_updated">Ticket Status Updated</option>
            <option value="priority_changed">Ticket Priority Updated</option>
            <option value="category_detected">AI Category Detected</option>
            <option value="sentiment_detected">AI Sentiment Detected</option>
          </select>
        </div>

        <!-- Conditions (IF) -->
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <label class="text-xs font-bold uppercase tracking-wider text-slate-500">Conditions (IF)</label>
            <button type="button" @click="addConditionInForm" class="text-xs font-bold text-blue-600 hover:text-blue-800 cursor-pointer">+ Add Condition</button>
          </div>

          <div v-if="ruleForm.conditions.length > 0" class="space-y-2">
            <div v-for="(cond, index) in ruleForm.conditions" :key="index" class="flex gap-2 items-center">
              <span class="text-xs font-semibold text-slate-400">If</span>
              <select v-model="cond.field" class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                <option value="category">Category</option>
                <option value="status">Status</option>
                <option value="priority">Priority</option>
                <option value="sentiment">Sentiment</option>
              </select>
              <span class="text-xs font-semibold text-slate-400">equals</span>
              <input v-model="cond.value" type="text" required placeholder="e.g. refund, angry, open" class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
              <button type="button" @click="removeConditionInForm(index)" class="text-red-500 hover:text-red-700 text-xs font-bold cursor-pointer">✕</button>
            </div>
          </div>
          <p v-else class="text-xs text-slate-400 italic">Rule will run on all triggered events (no filters applied).</p>
        </div>

        <!-- Actions (THEN) -->
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <label class="text-xs font-bold uppercase tracking-wider text-slate-500">Actions (THEN)</label>
            <button type="button" @click="addActionInForm" class="text-xs font-bold text-blue-600 hover:text-blue-800 cursor-pointer">+ Add Action</button>
          </div>

          <div v-if="ruleForm.actions.length > 0" class="space-y-2">
            <div v-for="(act, index) in ruleForm.actions" :key="index" class="flex gap-2 items-start">
              <span class="text-xs font-semibold text-slate-400 mt-2">Then</span>
              <div class="flex-1 space-y-1">
                <select v-model="act.action_type" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                  <option value="assign_to_agent">Assign to Agent</option>
                  <option value="change_priority">Change Priority</option>
                  <option value="add_internal_note">Add Internal Note</option>
                  <option value="send_notification">Send system message notification</option>
                  <option value="mark_as_pending">Mark as Pending status</option>
                </select>
                
                <!-- Action Value Input Conditional on Action Type -->
                <div class="mt-1" v-if="act.action_type === 'assign_to_agent'">
                  <select v-model="act.action_value" required class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                    <option value="">Select agent...</option>
                    <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
                  </select>
                </div>
                <div class="mt-1" v-else-if="act.action_type === 'change_priority'">
                  <select v-model="act.action_value" required class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs">
                    <option value="">Select priority...</option>
                    <option v-for="p in priorities" :key="p" :value="p">{{ p }}</option>
                  </select>
                </div>
                <div class="mt-1" v-else-if="act.action_type === 'add_internal_note' || act.action_type === 'send_notification'">
                  <input v-model="act.action_value" required type="text" placeholder="Enter text context..." class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs focus:outline-hidden focus:border-blue-500" />
                </div>
              </div>
              <button type="button" @click="removeActionInForm(index)" class="text-red-500 hover:text-red-700 text-xs font-bold cursor-pointer mt-2">✕</button>
            </div>
          </div>
          <p v-else class="text-xs text-red-500 italic">Please define at least one action to execute.</p>
        </div>

        <!-- Buttons -->
        <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-3.5 mt-2">
          <button type="button" @click="showRuleModal = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">Cancel</button>
          <button type="submit" class="rounded-lg bg-blue-600 hover:bg-blue-700 px-4 py-2 text-sm font-semibold text-white cursor-pointer">Create Rule</button>
        </div>
      </form>
    </div>
  </div>
</template>
