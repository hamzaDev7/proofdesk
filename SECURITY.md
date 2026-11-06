# ProofDesk Security Guide

Security and multi-tenant isolation are core foundations of ProofDesk.

---

## 1. Multi-Tenant Data Isolation (Row Level Security)

- In ProofDesk, every database table is protected by PostgreSQL **Row Level Security (RLS)**.
- RLS rules evaluate `auth.uid()` against workspace membership.
- Users from Workspace A are mathematically blocked by PostgreSQL from querying, reading, updating, or deleting records belonging to Workspace B.
- Security-definer SQL functions (`check_is_workspace_member`, `check_can_access_project`) prevent infinite recursion while keeping policies clean and auditable.

---

## 2. Token Forwarding & Key Hygiene

- **Never Expose Service Role Keys**: The Supabase service-role secret key is strictly stored server-side in `.env` and is NEVER included in client-side JavaScript.
- **Client Authentication**: Browser clients authenticate with Supabase Auth using the publishable/anon key.
- **Backend Verification**: The PHP backend extracts the Bearer token, validates it against `/auth/v1/user`, and executes PostgREST queries using the user's JWT. This ensures all RLS checks remain active even when executing queries from PHP.

---

## 3. Secure File Upload Protection

- **File Size Caps**: Uploaded deliverables are capped at **50 MB** server-side.
- **MIME Whitelisting**: Strict MIME-type inspection using PHP's native `finfo` (Magic Byte inspection) rejects disguised executable files (`.exe`, `.php`, `.sh`, `.bat`).
- **Filename Sanitization**: Uploaded filenames are sanitized and stored under randomized UUID prefixes inside private buckets.
- **Signed Storage URLs**: File downloads require HMAC-signed URLs with a 60-minute time-to-live (`3600` seconds). Direct public bucket access is disabled.

---

## 4. API & Application Defenses

- **SQL Injection Prevention**: Parameterized PostgREST query parameters and PDO prepared statements prevent SQL injection.
- **Cross-Site Scripting (XSS)**: All user-generated content rendered in HTML is sanitized or set via `.textContent` / encoded templates.
- **Rate Limiting**: Critical endpoints (`/api/auth/login`, `/api/auth/register`, `/api/auth/forgot-password`) are protected by `RateLimitMiddleware` (sliding window rate limiter returning `HTTP 429 Too Many Requests`).
- **Security Headers**: Standard headers are injected on all API responses:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `X-XSS-Protection: 1; mode=block`
  - `Referrer-Policy: strict-origin-when-cross-origin`
