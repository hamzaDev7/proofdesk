# ProofDesk Database Schema Documentation

ProofDesk utilizes a 16-table relational PostgreSQL schema hosted on Supabase, enforcing multi-tenant isolation, foreign keys, triggers, and Row Level Security.

---

## Entity Relationship Overview

| Table | Primary Key | Foreign Keys | Purpose |
|---|---|---|---|
| `profiles` | `id (UUID)` | `auth.users(id)` | Extended profile (full name, avatar, role) |
| `workspaces` | `id (UUID)` | `owner_id -> auth.users(id)` | Tenant workspace organizations |
| `workspace_members`| `id (UUID)` | `workspace_id, user_id` | User memberships and workspace roles |
| `clients` | `id (UUID)` | `workspace_id, user_id` | Client companies and contact entities |
| `projects` | `id (UUID)` | `workspace_id, client_id, created_by` | Main project containers & milestones |
| `project_members` | `id (UUID)` | `project_id, user_id` | Team member project assignments |
| `tasks` | `id (UUID)` | `project_id, assigned_to` | Work breakdown items & statuses |
| `deliverables` | `id (UUID)` | `project_id, uploaded_by` | Digital assets with versioning |
| `approval_requests`| `id (UUID)` | `deliverable_id, client_id` | Current approval state for deliverables |
| `approval_events` | `id (UUID)` | `approval_request_id, actor_id` | Immutable audit trail of sign-off actions |
| `comments` | `id (UUID)` | `project_id, deliverable_id, user_id` | Stakeholder discussion thread |
| `activity_logs` | `id (UUID)` | `workspace_id, project_id, user_id` | Timeline event logs (JSONB metadata) |
| `notifications` | `id (UUID)` | `user_id` | User in-app notifications |
| `invoices` | `id (UUID)` | `workspace_id, client_id, project_id` | Milestone billing statements |
| `invoice_items` | `id (UUID)` | `invoice_id` | Itemized line items and sub-amounts |
| `user_settings` | `id (UUID)` | `user_id` | Theme and notification preferences |

---

## Table Definitions & Key Columns

### 1. `profiles`
- `id` (UUID, PK references `auth.users`)
- `full_name` (TEXT)
- `avatar_url` (TEXT)
- `role` (TEXT: `super_admin`, `workspace_owner`, `workspace_member`, `client`)
- `phone` (TEXT)
- `created_at`, `updated_at` (TIMESTAMPTZ)

### 2. `workspaces`
- `id` (UUID, PK)
- `name` (TEXT)
- `slug` (TEXT, UNIQUE)
- `owner_id` (UUID references `auth.users`)
- `created_at`, `updated_at` (TIMESTAMPTZ)

### 3. `workspace_members`
- `id` (UUID, PK)
- `workspace_id` (UUID references `workspaces` ON DELETE CASCADE)
- `user_id` (UUID references `auth.users` ON DELETE CASCADE)
- `role` (TEXT: `workspace_owner`, `workspace_member`, `client`)
- `CONSTRAINT unique_workspace_member UNIQUE (workspace_id, user_id)`

### 4. `clients`
- `id` (UUID, PK)
- `workspace_id` (UUID references `workspaces` ON DELETE CASCADE)
- `user_id` (UUID references `auth.users` ON DELETE SET NULL)
- `name`, `email`, `company`, `phone`, `address` (TEXT)

### 5. `projects`
- `id` (UUID, PK)
- `workspace_id` (UUID references `workspaces` ON DELETE CASCADE)
- `client_id` (UUID references `clients` ON DELETE SET NULL)
- `name` (TEXT)
- `description` (TEXT)
- `status` (TEXT: `planning`, `in_progress`, `in_review`, `completed`, `archived`)
- `priority` (TEXT: `low`, `medium`, `high`, `urgent`)
- `start_date`, `deadline` (DATE)
- `budget` (NUMERIC(12, 2))
- `created_by` (UUID references `auth.users`)

### 6. `deliverables`
- `id` (UUID, PK)
- `project_id` (UUID references `projects` ON DELETE CASCADE)
- `uploaded_by` (UUID references `auth.users`)
- `title` (TEXT)
- `description` (TEXT)
- `file_path` (TEXT)
- `file_type` (TEXT)
- `file_size` (BIGINT)
- `version` (INTEGER default 1)
- `status` (TEXT: `pending`, `approved`, `revision_requested`, `rejected`)

### 7. `approval_requests`
- `id` (UUID, PK)
- `deliverable_id` (UUID references `deliverables` ON DELETE CASCADE)
- `client_id` (UUID references `clients` ON DELETE SET NULL)
- `status` (TEXT: `pending`, `approved`, `revision_requested`, `rejected`)
- `feedback` (TEXT)
- `reviewed_at` (TIMESTAMPTZ)

### 8. `approval_events` (Immutable Audit Log)
- `id` (UUID, PK)
- `approval_request_id` (UUID references `approval_requests` ON DELETE CASCADE)
- `actor_id` (UUID references `auth.users`)
- `action` (TEXT: `created`, `viewed`, `approved`, `revision_requested`, `rejected`)
- `comment` (TEXT)
- `created_at` (TIMESTAMPTZ)

### 9. `invoices` & `invoice_items`
- `invoices`: `invoice_number`, `subtotal`, `tax`, `total`, `status` (`draft`, `sent`, `paid`, `overdue`, `cancelled`), `due_date`, `notes`.
- `invoice_items`: `description`, `quantity`, `unit_price`, `total`.
