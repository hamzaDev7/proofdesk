# ProofDesk REST API Documentation

Base URL: `/api`  
All protected endpoints require the header:  
`Authorization: Bearer <jwt_access_token>`  
Workspace-scoped endpoints optionally accept:  
`X-Workspace-ID: <workspace_uuid>`

Standard Response Envelopes:
```json
// Success Response (HTTP 200 / 201)
{
  "success": true,
  "message": "Operation successful",
  "data": { ... }
}

// Error Response (HTTP 4xx / 5xx)
{
  "success": false,
  "message": "Error description",
  "code": "ERROR_CODE",
  "errors": { ... }
}
```

---

## 1. System & Health

### `GET /api/health`
Checks API operational status and Supabase cloud connectivity without exposing secrets.
- **Auth**: None required.
- **Response**:
  ```json
  {
    "status": "healthy",
    "version": "1.0.0",
    "php_version": "8.5.12",
    "services": {
      "supabase_rest": { "configured": true, "status": "connected" }
    }
  }
  ```

---

## 2. Authentication

### `POST /api/auth/register`
Creates user profile and initializes default workspace.
- **Payload**:
  ```json
  {
    "email": "alex@agency.com",
    "password": "SecurePassword123!",
    "full_name": "Alex Morgan",
    "workspace_name": "Acme Creative Studio",
    "role": "workspace_owner"
  }
  ```

### `POST /api/auth/login`
Authenticates user and returns JWT token.
- **Payload**:
  ```json
  {
    "email": "alex@agency.com",
    "password": "SecurePassword123!"
  }
  ```

### `GET /api/auth/profile`
Retrieves current authenticated user's profile and workspace list.

---

## 3. Projects

### `GET /api/projects`
List projects in active workspace.
- **Query Params**: `status`, `priority`, `client_id`, `limit`, `offset`.

### `POST /api/projects`
Create a new project.
- **Payload**:
  ```json
  {
    "name": "Q4 Brand Redesign",
    "client_id": "uuid-here",
    "description": "Identity and packaging suite",
    "status": "planning",
    "priority": "high",
    "deadline": "2026-12-15",
    "budget": 12500.00
  }
  ```

### `GET /api/projects/{id}`
Retrieve single project with client, tasks, deliverables, and members.

### `PUT /api/projects/{id}`
Update project details.

### `DELETE /api/projects/{id}`
Delete project (Workspace Owner / Super Admin only).

---

## 4. Deliverables & Files

### `GET /api/deliverables`
List deliverables filtered by project or review status.

### `POST /api/deliverables`
Upload a deliverable asset and automatically trigger an approval request.
- **Encoding**: `multipart/form-data`
- **Fields**:
  - `project_id`: Project UUID
  - `title`: Deliverable title
  - `description`: Notes for client
  - `file`: Binary file upload (max 50MB)

### `GET /api/deliverables/{id}/download`
Generates a temporary signed HMAC download URL.

---

## 5. Approval Workflow

### `GET /api/approvals`
List pending and historical approval requests.

### `POST /api/approvals/{id}/review`
Submit client review decision and record immutable audit event.
- **Payload**:
  ```json
  {
    "action": "approved", // or "revision_requested", "rejected"
    "feedback": "Approved with color adjustments on slide 4."
  }
  ```

---

## 6. Tasks

### `GET /api/tasks`
List project tasks.

### `POST /api/tasks`
Create a task.
- **Payload**:
  ```json
  {
    "project_id": "uuid",
    "title": "Design dark mode variant",
    "priority": "medium",
    "due_date": "2026-11-20"
  }
  ```

### `PUT /api/tasks/{id}`
Update task status (`todo`, `in_progress`, `in_review`, `done`).

---

## 7. Invoices

### `GET /api/invoices`
List invoices with calculated totals and payment statuses.

### `POST /api/invoices`
Create itemized invoice with automatic subtotal and tax calculation.
- **Payload**:
  ```json
  {
    "client_id": "uuid",
    "project_id": "uuid",
    "invoice_number": "INV-2026-001",
    "status": "sent",
    "due_date": "2026-12-01",
    "tax_rate": 0.08,
    "items": [
      { "description": "Phase 1 Brand Strategy", "quantity": 1, "unit_price": 3500.00 },
      { "description": "Vector Assets", "quantity": 1, "unit_price": 4000.00 }
    ]
  }
  ```
