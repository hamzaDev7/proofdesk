# ProofDesk – Client Project & Approval Management SaaS

ProofDesk is a complete, production-quality SaaS platform designed for design agencies, software consultancies, creative teams, and their clients. It provides seamless project tracking, milestone task management, deliverable version control, an immutable approval audit trail, real-time stakeholder feedback, and integrated invoice generation.

---

## 🌟 Key Highlights

- **Pure Modern Frontend**: Built with HTML5, CSS3, Vanilla ES6+ JavaScript, Fetch API, Lucide Icons, and Chart.js. Zero frontend framework overhead or bloated bundle sizes.
- **Enterprise-Grade PHP Backend**: Modern Object-Oriented PHP 8.2+ REST API architecture with clean routing, request/response lifecycle, input validation, role-based middleware, and error handling.
- **Supabase PostgreSQL & Row Level Security (RLS)**: Cryptographically validated user sessions where user JWT tokens are forwarded to PostgREST to guarantee strict multi-tenant workspace isolation.
- **Immutable Approval Engine**: Every client review decision (`approved`, `revision_requested`, `rejected`) is recorded with tamper-proof event timestamps and stakeholder commentary.
- **Automated Billing & Milestones**: Itemized invoice builder with tax calculation, status workflow, and print-to-PDF invoice view.
- **Multi-Cloud Ready**: Runs on local PHP development servers, traditional Apache/Nginx web servers, Docker, or serverless Vercel with the community PHP runtime.

---

## 🏗️ Architecture Overview

```
ProofDesk/
├── api/
│   └── index.php                 # Central REST API front-controller & router
├── public/                       # Static web root
│   ├── index.html                # High-converting SaaS landing page
│   ├── login.html                # Authentication login portal
│   ├── register.html             # Multi-tenant workspace registration
│   ├── forgot-password.html      # Self-service password recovery
│   ├── dashboard.html            # Workspace KPI overview & activity feed
│   ├── projects.html             # Projects list & CRUD management
│   ├── project-details.html      # Project deep-dive with tabs
│   ├── tasks.html                # Task tracking & milestone check-off
│   ├── deliverables.html         # Digital assets & file uploads (50MB)
│   ├── approvals.html            # Client review center & immutable audit trail
│   ├── clients.html              # Client contact & company directory
│   ├── invoices.html             # Itemized invoice builder & PDF statements
│   ├── reports.html              # Chart.js analytics & delivery pipeline
│   ├── settings.html             # Profile preferences, dark mode & members
│   ├── admin.html                # Platform-wide Super Admin control plane
│   └── assets/
│       ├── css/ (main.css, components.css)
│       └── js/  (api.js, auth.js, ui.js, charts.js)
├── app/
│   ├── Controllers/              # RESTful API handlers
│   ├── Core/                     # Router, Request, Response, SupabaseClient, Env
│   ├── Middleware/               # Auth, Roles, CORS, RateLimiting
│   ├── Services/                 # Business logic, audit logging, notifications
│   └── Validators/               # Server-side input validation
├── database/
│   ├── migrations/               # PostgreSQL DDL, RLS policies, Storage buckets
│   └── seed/                     # Fictional demonstration data
├── storage/                      # System logs & rate limiting tokens
├── tests/                        # Automated unit & integration tests
├── composer.json                 # PSR-4 Autoloading definition
├── vercel.json                   # Vercel deployment configuration
└── .env.example                  # Environment configuration template
```

---

## 🚀 Quick Start (Local Development)

```powershell
# 1. Clone repository
cd C:\xampp\htdocs\TECH

# 2. Configure environment
Copy-Item .env.example .env
# Edit .env with your Supabase URL & publishable anon key

# 3. Start local PHP development server
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public api/index.php

# 4. Open browser
http://127.0.0.1:8000
```

---

## 🔒 Security & Roles

| Role | Permissions |
|---|---|
| **Super Admin** | Platform-wide oversight, user role alteration, workspace audits |
| **Workspace Owner** | Project creation, invoice generation, member invitations, client management |
| **Workspace Member** | Task execution, deliverable uploads, client collaboration |
| **Client** | Portal access to assigned projects, deliverable review/approval, invoice viewing |

---

## 📄 Documentation Directory

- [`INSTALLATION.md`](INSTALLATION.md): Step-by-step setup and Supabase configuration guide.
- [`ARCHITECTURE.md`](ARCHITECTURE.md): Deep-dive architectural patterns and security design.
- [`DATABASE_SCHEMA.md`](DATABASE_SCHEMA.md): Complete 16-table PostgreSQL schema, indexes, and RLS policies.
- [`API_DOCUMENTATION.md`](API_DOCUMENTATION.md): REST API endpoints reference with payloads.
- [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md): Daily development commands, testing, and debugging.
- [`DEPLOYMENT.md`](DEPLOYMENT.md): Vercel and traditional PHP hosting deployment guide.
- [`SECURITY.md`](SECURITY.md): Threat mitigation, token forwarding, and storage security.
- [`TESTING.md`](TESTING.md): Running automated unit tests and quality verification.
