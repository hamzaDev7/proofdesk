# ProofDesk Architecture & System Design

ProofDesk adheres to a clean, separation-of-concerns architecture designed for high security, horizontal scalability, and multi-tenant performance.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        BROWSER / CLIENT LAYER                          │
│                                                                        │
│   Vanilla JS (ES6+) • Fetch API Client • Lucide Icons • Chart.js       │
│   (Bearer Token stored in localStorage, forwarded on every call)       │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                         HTTP REST API (JSON)
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│                         PHP BACKEND ARCHITECTURE                       │
│                                                                        │
│   api/index.php ────────► Front Controller & Router                    │
│                                 │                                      │
│   Middleware Pipeline ──────────┼─► CorsMiddleware                     │
│                                 ├─► RateLimitMiddleware                │
│                                 ├─► AuthMiddleware (JWT verification)  │
│                                 └─► RoleMiddleware (RBAC checks)       │
│                                 │                                      │
│   Controllers ──────────────────┼─► Project, Task, Deliverable,        │
│                                 │   Approval, Invoice, Report, Admin   │
│                                 │                                      │
│   Services / Helpers ───────────┼─► ActivityLogger, NotificationService│
│                                 │                                      │
│   Core Layer ───────────────────┴─► SupabaseClient (cURL + JWT forward)│
│                                     PDO Database (Optional Direct)     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                       PostgREST API + Bearer JWT
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│                    SUPABASE / POSTGRESQL CLOUD                         │
│                                                                        │
│   • PostgreSQL 15+ Schema                                              │
│   • Row Level Security (RLS) Enforced at Engine Level                  │
│   • Supabase Auth (JWT Issuance & Refresh Tokens)                      │
│   • Supabase Storage (Private 'deliverables' bucket with signed URLs)  │
│   • Immutable Audit Events Trail                                       │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 1. Zero-Trust Authentication Pattern

1. **Authentication Handshake**:
   - The user authenticates via `POST /api/auth/login`.
   - The PHP backend relays the request to Supabase Auth (`/auth/v1/token?grant_type=password`).
   - The resulting access token (JWT) is returned to the client and stored in browser storage.
2. **Session Verification**:
   - Every protected API request carries `Authorization: Bearer <jwt>`.
   - `AuthMiddleware` verifies the cryptographic validity of the token with Supabase Auth (`/auth/v1/user`).
   - The user's active workspace and role are extracted server-side.
3. **RLS Context Preservation**:
   - When the PHP backend executes queries via `SupabaseClient`, it attaches the user's JWT as the Bearer token.
   - PostgreSQL identifies `auth.uid()` and evaluates RLS policies natively.
   - Cross-workspace data leakage is mathematically impossible at the database engine level.

---

## 2. Immutable Approval Event Audit Trail

Deliverable review workflows require non-repudiation. ProofDesk splits approvals into two tables:
- **`approval_requests`**: Mutable table representing current state (`pending`, `approved`, `revision_requested`, `rejected`).
- **`approval_events`**: Append-only immutable log. Updates and deletes are prohibited. Every state transition records:
  - `approval_request_id`
  - `actor_id` (User who performed the action)
  - `action` (`created`, `viewed`, `approved`, `revision_requested`, `rejected`)
  - `comment` (Feedback notes from client)
  - `created_at` (Server timestamp)

---

## 3. Storage Architecture

- Deliverables are stored in a private Supabase Storage bucket named `deliverables`.
- Files are namespaced under: `projects/<project_id>/<file_unique_name>`.
- Client browsers never receive long-lived direct links. Instead, the backend generates short-lived **HMAC-signed URLs** (`3600` seconds expiry) only after verifying the user's project membership.
