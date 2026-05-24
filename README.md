# 🤖 Omnispeak: AI Customer Support & Workflow Automation SaaS

An enterprise-ready, multi-tenant AI customer support and event-driven workflow automation platform built to show production-quality full-stack engineering. Omnispeak bridges live support operations, automated workflow engines, AI suggested grounding replies, webhook channel sandboxes, and security audit logs in a stunning single-page Vue 3 dashboard.

Designed primarily as a high-fidelity learning showcase, this repository demonstrates clean architectural patterns, comprehensive integration testing, and performance-minded SaaS best practices.

---

## 🚀 Core Features & Technical Highlights

* **👥 Single-Database Multi-Tenant SaaS Architectures**: Enforces absolute data boundaries across workspace scopes (`organization_id` scoping) at the middleware, Eloquent query builder, and policy layers.
* **✨ AI Copilot Suggested Replies**: Harnesses OpenAI (GPT-4o-mini & Text-Embedding-3) to dynamically analyze ticket threads, fetch grounded context, and output suggestion drafts that support agents can edit and inspect.
* **🔍 Semantic Retrieval Q&A (Cosine Similarity)**: Builds an asynchronous vector embedding pipeline storing 1536 float arrays in Postgres. Custom-designed offline-ready mathematical cos-similarity algorithms allow semantic searches in PHP, with direct fallback keyword search.
* **⚡ Event-Driven Workflow Automation Engine**: Enables merchants to build conditional rules (Triggers like `ticket_created`, `sentiment_detected` -> Conditions like `sentiment is angry` -> Actions like `assign_to_agent`, `change_priority`, `add_internal_note`). Runs asynchronously in queue worker streams.
* **🔌 Channel Ingestion webhook Sandbox**: Simulates real external inbound streams (WhatsApp phone lines, rich HTML emails, live chat widgets) using a public webhook engine with processing logs, exception tracebacks, and instant event retries.
* **📊 Operations Analytics Dashboard**: Aggregates ticket composition graphs, agent workloads, AI usage tallies, and automation success ratios database-agnostically with beautiful pure CSS/Tailwind progress charts.
* **📋 Secure Audit Log Explorer**: Logs and tracks 6 critical business activities (`ticket_created`, `status_changed`, `assigned_agent_changed`, `ai_reply_generated`, `workflow_executed`, `knowledge_article_updated`) with raw JSON payload detail inspectors, scoped strictly to Workspace Owners and Admins.
* **🔄 Real-Time WebSockets Sync**: Propagates new ticket alerts, internal notes, and typing indicators instantly across client views using Laravel Reverb and Echo.

---

## 🗺️ Entity-Relationship Database Architecture

```txt
  ┌─────────────────────────────────────────────────────────────┐
  │                        organizations                        │
  └──────────────────────────────┬──────────────────────────────┘
                                 │ 1
                                 │
                                 │ *
  ┌──────────────────────────────▼──────────────────────────────┐
  │                            users                            │
  └──────────────────────────────┬──────────────────────────────┘
                                 │ 1
                                 │
         ┌───────────────────────┴───────────────────────┐
         │ *                                             │ *
┌────────▼────────┐                             ┌────────▼────────┐
│     tickets     │◄───────────────────────────┬┤    audit_logs   │
└────────┬────────┘ 1                         │ └─────────────────┘
         │                                     │
         ├───────────────────────┐             │ *
         │ *                     │ *           │
┌────────▼────────┐     ┌────────▼────────┐    │
│ ticket_messages │     │  ticket_notes   │    │
└─────────────────┘     └─────────────────┘    │
                                               │
                                               │
┌──────────────────────────────────────────────┼────────────────┐
│            knowledge_base_articles           │◄───────────────┘
└──────────────────────────────────────────────┘
```

---

## 🛠️ Technology Stack & Requirements

* **Core Backend**: Laravel 12 & PHP 8.4
* **Core Frontend**: Vue 3 (Composition API), Vite, Tailwind CSS, & Strict TypeScript
* **Database**: PostgreSQL (pgvector compatible) or SQLite (in-memory testing)
* **Caching & Queuing**: Redis (Horizon dashboard enabled)
* **Real-time Pipeline**: Laravel Reverb WebSocket Server & Echo JS Client
* **Authentication**: Laravel Sanctum JWT Token protection
* **Testing Engines**: Pest PHP Feature Test Framework

---

## ⚡ Quick Start & Deployment Guide

### 1. Boot up Caches, Queues, and DB Services
Ensure you have Docker installed and launch local PostgreSQL and Redis servers:
```bash
docker compose up -d
```

### 2. Backend Application Setup
Navigate into the backend, initialize packages, configure environment settings, and seed demo workspaces:
```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate

# Run fresh migrations and seed high-fidelity demo cases out-of-the-box
php artisan migrate:fresh --seed
```

Start the application services in separate windows (or run `composer run dev`):
```bash
# Start serve API
php artisan serve

# Start queue workers (for AI embeddings, webhook processing, and workflows)
php artisan queue:work

# Start real-time WebSockets broadcaster
php artisan reverb:start
```
* **API Server**: Runs at `http://127.0.0.1:8000`
* **WebSocket Server**: Runs at `ws://127.0.0.1:8080`

### 3. Frontend Web Client Setup
Initialize NPM modules and run the Vite TypeScript hot-reload server:
```bash
cd ../frontend
cp .env.example .env
npm install
npm run dev
```
* **Vite Dev Server**: Active at `http://127.0.0.1:5173`
* **Credentials**: Log in using `test@example.com` / `password` to interact with pre-loaded demo tickets immediately!

---

## 🧪 Comprehensive Automated Testing

The backend suite covers 45 test cases (over 210 strict assertions) protecting auth, tenant boundaries, role permissions, semantic similarity scores, jobs, automations, and webhook retry processors.

Launch the test execution environment:
```bash
cd backend
php artisan test
```

Test coverage categories include:
* **Authentication**: User registrations, login validation, token issuance, profile queries, and logout states.
* **Workspace RBAC**: Scoping middleware ensuring Workspace Owners/Admins manage members and automations, while Agents are restricted to ticket resolution flows.
* **Webhooks & Ingestions**: Testing token verification, raw payload processing, automatic customer lookups, queue dispatching, and retry workers.
* **AISuggestedReply**: Mocking OpenAI responses, generating vector records, and testing cosine similarity values.
* **AuditLogs**: Verifying that core events automatically log accurate payloads in the relational DB, scoping queries correctly, and rejecting unprivileged requests.

---

## 💼 Technical Interview Playbook (Hiring Managers Q&A)

### Q1: How did you implement Cosine Similarity search in PHP and handle local fallbacks?
> **Answer**: In production, PostgreSQL handles vector math. However, to keep this project completely local-developer friendly and enable in-memory SQLite feature tests without external database plugin dependencies, we wrote a native cosine similarity engine in PHP. Whenever articles are saved, an asynchronous `GenerateKnowledgeEmbeddingJob` requests a 1536 float array from OpenAI (or generates a deterministic MD5-hash vector if offline) and saves it to `knowledge_embeddings`. The `KnowledgeSearchService` maps the query string vector, calculates the dot product divided by vector norms against DB elements, and returns the top 3 results sorted descending.

### Q2: Why did you choose explicit event-driven auditing over Eloquent model observers?
> **Answer**: Global model observers can cause silent side-effects, complicate bulk data imports, and decrease test performance. In Omnispeak, we designed an explicit `AuditLogger` service. Explicit injection points in controllers (`TicketController`, `AiReplyController`, `KnowledgeArticleController`) and engines (`WorkflowEngine`) make the log generation transparent, maintainable, and extremely easy for junior developers to understand during Pair Programming code reviews.

### Q3: How do you prevent infinite execution loops in the Workflow Automation Engine?
> **Answer**: When rules automatically trigger updates (e.g. priority escalation), there is a risk of trigger loops (e.g. update triggers rule -> rule updates ticket -> triggers update again). We implemented two safety checks: 
> 1. Triggers are scoped strictly to individual controller actions (explicit trigger methods).
> 2. The `WorkflowEngine` blocks recursive triggering by tracking active execution stacks or limiting actions from triggering secondary event dispatches.

---
