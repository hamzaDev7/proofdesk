# ProofDesk Deployment Guide

ProofDesk is architected to run seamlessly on both serverless cloud environments (Vercel) and traditional PHP application hosting environments.

---

## Path A: Deploying on Vercel

Vercel provides edge hosting for static assets and supports PHP serverless execution via the community-maintained `vercel-php` runtime.

> [!IMPORTANT]
> Vercel's PHP runtime is community-maintained (`vercel-php@0.7.2`). Verify your Supabase production URLs and secrets are populated in Vercel Project Settings.

### Step 1: Initialize Git Repository
```powershell
git init
git add .
git commit -m "Initial ProofDesk SaaS Release"
```

### Step 2: Push to GitHub
```powershell
git remote add origin https://github.com/your-username/proofdesk.git
git branch -M main
git push -u origin main
```

### Step 3: Connect to Vercel
1. Log into your [Vercel Dashboard](https://vercel.com).
2. Click **Add New Project** -> **Import Git Repository**.
3. Select your `proofdesk` repository.
4. Set Framework Preset to **Other**.
5. Set Root Directory to `./`.
6. Add the following **Environment Variables**:
   - `SUPABASE_URL`: `https://your-production-id.supabase.co`
   - `SUPABASE_ANON_KEY`: `eyJ...` (Your production anon key)
   - `SUPABASE_SERVICE_ROLE_KEY`: `eyJ...` (Your production service role key)
   - `APP_ENV`: `production`
   - `APP_NAME`: `ProofDesk`
7. Click **Deploy**.

The `vercel.json` file in the project automatically directs `/api/*` to `api/index.php` using `vercel-php@0.7.2` while serving all assets in `public/` as high-speed static CDN files.

---

## Path B: Deploying on Traditional PHP Hosting (Apache / Nginx / Railway / VPS)

If you prefer conventional PHP hosting:

### 1. Requirements
- Linux or Windows server with PHP 8.2+ installed.
- Web server: Apache (with `mod_rewrite`) or Nginx.
- Extensions: `php-curl`, `php-mbstring`, `php-openssl`, `php-fileinfo`, `php-pdo`.

### 2. Apache VirtualHost
Point the DocumentRoot to the project root:
```apache
<VirtualHost *:80>
    ServerName proofdesk.yourdomain.com
    DocumentRoot /var/www/proofdesk/public

    <Directory /var/www/proofdesk/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 3. Nginx Configuration
```nginx
server {
    listen 80;
    server_name proofdesk.yourdomain.com;
    root /var/www/proofdesk/public;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME /var/www/proofdesk/api/index.php;
        include fastcgi_params;
    }
}
```

---

## Post-Deployment Checklist

- [ ] Run `GET /api/health` on production URL to verify status `healthy`.
- [ ] Sign up an initial user account to verify Supabase Auth email triggers.
- [ ] Test deliverable upload to confirm Supabase Storage bucket policy.
- [ ] Confirm RLS multi-tenant isolation by registering two test users in separate workspaces.
