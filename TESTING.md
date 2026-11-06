# ProofDesk Testing & Quality Assurance

This document details automated testing procedures, manual validation routines, and security audit checklists.

---

## 1. Automated Test Suite

ProofDesk includes an automated test runner in `tests/ApiTest.php` that verifies:
- PSR-4 Autoloading resolution
- Environment loader (`.env` file parsing)
- `RequestValidator` business logic (required, email, numeric, enum whitelist, string bounds)
- Router regular-expression parameter capture (`/api/projects/{id}`)
- `HealthController` structure and contract
- `SupabaseClient` API integration methods

### Running the Test Suite
```powershell
& C:\xampp\php\php.exe tests/ApiTest.php
```

All 10 tests must pass before deployment.

---

## 2. Integration & Manual Testing Matrix

| Feature | Test Case | Expected Outcome |
|---|---|---|
| **Registration** | Register with email, password & workspace name | User record created, profile created, workspace owner role granted, redirect to dashboard |
| **Authentication** | Sign in with valid vs invalid password | Valid credentials return JWT token; invalid credentials return HTTP 401 with error message |
| **Workspace Isolation** | User A tries to view Project from User B's workspace | PostgREST RLS returns empty set or HTTP 404/403 |
| **Deliverable Upload** | Upload 4MB PDF to project | File saved to Supabase storage, record versioned, approval request automatically created |
| **Approval Sign-off** | Client clicks "Approve Deliverable" with feedback | Approval status updated, deliverable marked approved, immutable audit event logged, uploader notified |
| **Revision Request** | Client submits revision comments | Deliverable status updated to `revision_requested`, audit log updated, uploader notified |
| **Invoice Calculation** | Add 2 items ($3,500 and $4,000) with 8% tax | Subtotal: $7,500.00, Tax: $600.00, Total: $8,100.00 accurately computed |
| **Printable Invoice** | Click "View / Print Invoice" | Clean modal formatted as invoice with line items, printable to PDF via browser dialog |
| **Dark Mode** | Click moon icon in topbar | Theme toggles immediately to dark palette; preference persisted in localStorage |

---

## 3. Security Sanity Checks

- [x] No `SUPABASE_SERVICE_ROLE_KEY` exists in any client-side `.js` or `.html` file.
- [x] All database mutations require authenticated user JWT.
- [x] Storage bucket policies deny public unauthenticated reads.
- [x] File upload endpoint inspects binary MIME type with `finfo`.
- [x] Rate limiting active on sensitive endpoints (`/api/auth/*`).
