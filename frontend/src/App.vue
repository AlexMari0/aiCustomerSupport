<script setup lang="ts">
import { computed, onMounted, ref } from "vue"
import { getJson, getJsonWithParams, patchJson, postJson } from "./services/api"
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
const assignAssigneeId = ref("")
const newNote = ref("")
const newMessage = ref("")
const newMessageSenderType = ref<"customer" | "agent">("agent")

const roleForSelectedOrganization = computed(() => {
  if (selectedOrganizationId.value === null) {
    return null
  }

  return organizations.value.find((org) => org.id === selectedOrganizationId.value)?.role ?? null
})

const canManageAssignments = computed(() => roleForSelectedOrganization.value === "owner" || roleForSelectedOrganization.value === "admin")
const canManagePriority = computed(() => roleForSelectedOrganization.value === "owner" || roleForSelectedOrganization.value === "admin")
const canUpdateCustomer = computed(() => roleForSelectedOrganization.value === "owner" || roleForSelectedOrganization.value === "admin")

const statuses: TicketStatus[] = ["open", "pending", "resolved", "closed"]
const priorities: TicketPriority[] = ["low", "medium", "high", "urgent"]

function setMessage(message: string): void {
  appMessage.value = message
}

function saveToken(nextToken: string): void {
  token.value = nextToken
  localStorage.setItem("token", nextToken)
}

function clearSession(): void {
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
    return
  }

  await Promise.all([loadMembers(), loadCustomers(), loadTickets()])
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
  updateStatusValue.value = selectedTicket.value.status
  updatePriorityValue.value = selectedTicket.value.priority
  assignAssigneeId.value = selectedTicket.value.assignee?.id ? `${selectedTicket.value.assignee.id}` : ""

  if (selectedTicket.value.customer?.id) {
    await selectCustomer(selectedTicket.value.customer.id)
  }
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

onMounted(async () => {
  if (token.value) {
    await loadSession()
  }
})
</script>

<template>
  <main class="mx-auto max-w-7xl px-4 py-6 lg:px-8">
    <header class="mb-6 rounded-2xl border border-slate-200 bg-white/90 p-4 shadow-sm">
      <h1 class="text-2xl font-extrabold text-slate-900 lg:text-3xl">Customer Support Dashboard</h1>
      <p class="mt-1 text-sm text-slate-600">{{ appMessage }}</p>
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

              <div class="mt-3 grid gap-2 md:grid-cols-2">
                <div>
                  <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                  <select v-model="updateStatusValue" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                  </select>
                  <button class="mt-2 w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white" @click="updateTicketStatus">
                    Update Status
                  </button>
                </div>

                <div>
                  <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Priority</label>
                  <select v-model="updatePriorityValue" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
                  </select>
                  <button
                    class="mt-2 w-full rounded-lg px-3 py-2 text-sm font-semibold text-white"
                    :class="canManagePriority ? 'bg-slate-900' : 'bg-slate-300'"
                    :disabled="!canManagePriority"
                    @click="updateTicketPriority"
                  >
                    Update Priority
                  </button>
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

                <div class="mt-2 flex gap-2">
                  <select v-model="newMessageSenderType" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="agent">agent</option>
                    <option value="customer">customer</option>
                  </select>
                  <input v-model="newMessage" type="text" placeholder="Add reply" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <button class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white" @click="addMessage">
                    Send
                  </button>
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
      </section>
    </section>
  </main>
</template>
