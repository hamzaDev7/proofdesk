# ProofDesk Local Development Guide

## 1. Environment Details

- Operating System: Windows PowerShell
- Working Directory: `C:\xampp\htdocs\TECH`
- PHP Binary: `C:\xampp\php\php.exe` (PHP 8.5.12)
- Server URL: `http://127.0.0.1:8000`

---

## 2. Starting Local Development Server

Run the development server with the front-controller router:

```powershell
& C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public api/index.php
```

### Static vs API Routing
- Requests to `/assets/css/*`, `/assets/js/*`, or `*.html` are served directly by the PHP built-in server from the `public/` directory.
- Requests to `/` serve `public/index.html`.
- Requests to `/api/*` are routed to the central PHP REST API front-controller (`api/index.php`).

---

## 3. Verifying Health Check

Run in PowerShell:

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/health" -Method Get | ConvertTo-Json -Depth 4
```

Expected output:
```json
{
  "success": true,
  "message": "ProofDesk API health check successful",
  "data": {
    "status": "healthy",
    "app": "ProofDesk SaaS API",
    "version": "1.0.0",
    "php_version": "8.5.12",
    "services": {
      "supabase_rest": {
        "configured": true,
        "status": "connected"
      }
    }
  }
}
```

---

## 4. Running Automated Tests

Run the CLI test suite:

```powershell
& C:\xampp\php\php.exe tests/ApiTest.php
```

Expected output:
```
===========================================
ProofDesk SaaS Automated Test Suite
===========================================
 [PASS] Env loads APP_NAME correctly
 [PASS] Valid payload passes all rules
 [PASS] Invalid payload fails correctly
 [PASS] Validator flags invalid email
 [PASS] Validator flags invalid enum role
 [PASS] Router parameter regex extracts ID correctly
 [PASS] HealthController::check exists
 [PASS] SupabaseClient::getAuthUser exists
 [PASS] SupabaseClient::uploadFile exists
 [PASS] SupabaseClient::createSignedUrl exists
-------------------------------------------
Results: 10 Passed, 0 Failed
===========================================
```

---

## 5. Adding New Routes & Controllers

1. Create Controller in `app/Controllers/NewController.php`.
2. Register route in `api/index.php`:
   ```php
   $router->get('/api/my-endpoint', [NewController::class, 'handle'], [$auth]);
   ```
3. Test locally using curl or PowerShell `Invoke-RestMethod`.
