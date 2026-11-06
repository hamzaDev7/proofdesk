# ProofDesk Installation & Setup Guide

This guide walks you through setting up ProofDesk locally with your Supabase database.

---

## 1. Prerequisites

- **PHP 8.2+** (PHP 8.5+ included in XAMPP or native binary)
  - Required extensions: `curl`, `json`, `mbstring`, `openssl`, `fileinfo`, `pdo`
- **Git**
- **Supabase Account** (Free tier supported at [supabase.com](https://supabase.com))

---

## 2. Supabase Project Setup

1. Log in to [Supabase Dashboard](https://app.supabase.com) and click **New Project**.
2. Name your project (e.g. `proofdesk-prod`) and choose a strong database password.
3. Once the database provisions, navigate to **Project Settings -> API**:
   - Copy the **Project URL** (e.g., `https://xyzcompany.supabase.co`)
   - Copy the **anon (public)** key
   - Copy the **service_role (secret)** key (keep confidential, used only on server)

---

## 3. Database Migration Execution

Navigate to **SQL Editor** in your Supabase Dashboard:

### Step 3.1: Execute Schema Migration
Copy the contents of `database/migrations/001_initial_schema.sql` into the SQL Editor and click **Run**.
This creates:
- 16 PostgreSQL tables
- Foreign key constraints & triggers
- User registration hook `handle_new_user()`

### Step 3.2: Execute Row Level Security (RLS) Migration
Copy the contents of `database/migrations/002_rls_policies.sql` into the SQL Editor and click **Run**.
This activates:
- RLS across all tables
- Security-definer helper functions
- Multi-tenant workspace isolation

### Step 3.3: Execute Storage Bucket Migration
Copy the contents of `database/migrations/003_storage_policies.sql` into the SQL Editor and click **Run**.
This creates:
- The private `deliverables` bucket
- 50MB file size limits and MIME-type restrictions
- Storage access policies

---

## 4. Local Configuration

In your project directory:

```powershell
# Copy environment configuration
Copy-Item .env.example .env
```

Open `.env` and fill in your Supabase credentials:

```ini
APP_ENV=development
APP_NAME=ProofDesk
APP_URL=http://localhost:8000

SUPABASE_URL=https://your-project-id.supabase.co
SUPABASE_ANON_KEY=eyJhbGciOi...
SUPABASE_SERVICE_ROLE_KEY=eyJhbGciOi...
```

---

## 5. Start the Server

Run the built-in PHP development server:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public api/index.php
```

Open your browser to:
**`http://localhost:8000`**

Verify API health:
**`http://localhost:8000/api/health`**

---

## 6. (Optional) Demonstration Seed Data

1. Register an initial user in your app via `http://localhost:8000/register.html`.
2. Go to Supabase SQL Editor and execute `database/seed/seed_data.sql`.
3. The seed script attaches sample clients, projects, tasks, deliverables, and invoices to your account.
