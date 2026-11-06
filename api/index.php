<?php

declare(strict_types=1);

// Handle Vercel and proxy original requested URI
if (isset($_GET['__route'])) {
    $_SERVER['REQUEST_URI'] = $_GET['__route'];
} elseif (isset($_SERVER['HTTP_X_VERCEL_PATH'])) {
    $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_VERCEL_PATH'];
} elseif (isset($_SERVER['HTTP_X_NOW_PATH'])) {
    $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_NOW_PATH'];
}

// Static file handling for PHP built-in web server (php -S)
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$rootDir = dirname(__DIR__);
$publicFile = $rootDir . '/public' . $path;

if ($path !== '/' && $path !== '' && file_exists($publicFile) && !is_dir($publicFile)) {
    return false; // Serve static file as-is
}

if ($path === '/' && file_exists($rootDir . '/public/index.html')) {
    readfile($rootDir . '/public/index.html');
    exit;
}

// Handle custom autoloader and composer autoloader
if (file_exists($rootDir . '/vendor/autoload.php')) {
    require_once $rootDir . '/vendor/autoload.php';
}
require_once $rootDir . '/app/Core/Autoloader.php';
\ProofDesk\Core\Autoloader::register();

use ProofDesk\Core\Env;
use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\Router;
use ProofDesk\Middleware\CorsMiddleware;
use ProofDesk\Middleware\AuthMiddleware;
use ProofDesk\Middleware\RoleMiddleware;
use ProofDesk\Middleware\RateLimitMiddleware;

use ProofDesk\Controllers\HealthController;
use ProofDesk\Controllers\AuthController;
use ProofDesk\Controllers\ProjectController;
use ProofDesk\Controllers\TaskController;
use ProofDesk\Controllers\ClientController;
use ProofDesk\Controllers\DeliverableController;
use ProofDesk\Controllers\ApprovalController;
use ProofDesk\Controllers\CommentController;
use ProofDesk\Controllers\InvoiceController;
use ProofDesk\Controllers\ReportController;
use ProofDesk\Controllers\NotificationController;
use ProofDesk\Controllers\UserController;
use ProofDesk\Controllers\AdminController;
use ProofDesk\Controllers\UploadController;

// Load .env
Env::load($rootDir . '/.env');

// Set exception handler to always return standardized JSON
set_exception_handler(function (\Throwable $e) {
    error_log(sprintf('[ProofDesk Exception] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    $isDev = Env::get('APP_ENV') === 'development';
    Response::error(
        $isDev ? $e->getMessage() : 'An unexpected server error occurred.',
        500,
        $isDev ? ['trace' => $e->getTraceAsString(), 'file' => $e->getFile(), 'line' => $e->getLine()] : null,
        'INTERNAL_SERVER_ERROR'
    );
});

// Initialize Router & Middlewares
$router = new Router();
$router->use(CorsMiddleware::class);

$auth = AuthMiddleware::class;
$ownerOnly = RoleMiddleware::requireRoles('workspace_owner', 'super_admin');
$adminOnly = RoleMiddleware::requireRoles('super_admin');
$rateLimitAuth = RateLimitMiddleware::limit(15, 60);

// Health Check
$router->get('/api/health', [HealthController::class, 'check']);

// Public Authentication Endpoints
$router->post('/api/auth/register', [AuthController::class, 'register'], [$rateLimitAuth]);
$router->post('/api/auth/login', [AuthController::class, 'login'], [$rateLimitAuth]);
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword'], [$rateLimitAuth]);

// User & Workspaces Endpoints (Authenticated)
$router->get('/api/auth/profile', [AuthController::class, 'profile'], [$auth]);
$router->put('/api/auth/profile', [AuthController::class, 'updateProfile'], [$auth]);
$router->get('/api/auth/workspaces', [AuthController::class, 'getWorkspaces'], [$auth]);
$router->post('/api/auth/workspaces', [AuthController::class, 'createWorkspace'], [$auth]);

// Projects Endpoints
$router->get('/api/projects', [ProjectController::class, 'index'], [$auth]);
$router->post('/api/projects', [ProjectController::class, 'store'], [$auth]);
$router->get('/api/projects/{id}', [ProjectController::class, 'show'], [$auth]);
$router->put('/api/projects/{id}', [ProjectController::class, 'update'], [$auth]);
$router->delete('/api/projects/{id}', [ProjectController::class, 'destroy'], [$auth, $ownerOnly]);

// Tasks Endpoints
$router->get('/api/tasks', [TaskController::class, 'index'], [$auth]);
$router->post('/api/tasks', [TaskController::class, 'store'], [$auth]);
$router->get('/api/tasks/{id}', [TaskController::class, 'show'], [$auth]);
$router->put('/api/tasks/{id}', [TaskController::class, 'update'], [$auth]);
$router->delete('/api/tasks/{id}', [TaskController::class, 'destroy'], [$auth]);

// Clients Endpoints
$router->get('/api/clients', [ClientController::class, 'index'], [$auth]);
$router->post('/api/clients', [ClientController::class, 'store'], [$auth]);
$router->get('/api/clients/{id}', [ClientController::class, 'show'], [$auth]);
$router->put('/api/clients/{id}', [ClientController::class, 'update'], [$auth]);
$router->delete('/api/clients/{id}', [ClientController::class, 'destroy'], [$auth, $ownerOnly]);

// Deliverables Endpoints
$router->get('/api/deliverables', [DeliverableController::class, 'index'], [$auth]);
$router->post('/api/deliverables', [DeliverableController::class, 'store'], [$auth]);
$router->get('/api/deliverables/{id}', [DeliverableController::class, 'show'], [$auth]);
$router->get('/api/deliverables/{id}/download', [DeliverableController::class, 'download'], [$auth]);

// Approval Requests Endpoints
$router->get('/api/approvals', [ApprovalController::class, 'index'], [$auth]);
$router->get('/api/approvals/{id}', [ApprovalController::class, 'show'], [$auth]);
$router->post('/api/approvals/{id}/review', [ApprovalController::class, 'review'], [$auth]);

// Comments Endpoints
$router->get('/api/comments', [CommentController::class, 'index'], [$auth]);
$router->post('/api/comments', [CommentController::class, 'store'], [$auth]);
$router->delete('/api/comments/{id}', [CommentController::class, 'destroy'], [$auth]);

// Invoices Endpoints
$router->get('/api/invoices', [InvoiceController::class, 'index'], [$auth]);
$router->post('/api/invoices', [InvoiceController::class, 'store'], [$auth]);
$router->get('/api/invoices/{id}', [InvoiceController::class, 'show'], [$auth]);
$router->put('/api/invoices/{id}', [InvoiceController::class, 'update'], [$auth]);
$router->delete('/api/invoices/{id}', [InvoiceController::class, 'destroy'], [$auth, $ownerOnly]);

// Reports & Dashboard Endpoints
$router->get('/api/reports/dashboard', [ReportController::class, 'dashboard'], [$auth]);

// Notifications Endpoints
$router->get('/api/notifications', [NotificationController::class, 'index'], [$auth]);
$router->put('/api/notifications/{id}/read', [NotificationController::class, 'markAsRead'], [$auth]);
$router->post('/api/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'], [$auth]);

// User Settings & Workspace Members
$router->get('/api/user/settings', [UserController::class, 'getSettings'], [$auth]);
$router->put('/api/user/settings', [UserController::class, 'updateSettings'], [$auth]);
$router->get('/api/workspace/members', [UserController::class, 'getWorkspaceMembers'], [$auth]);
$router->post('/api/workspace/members', [UserController::class, 'addWorkspaceMember'], [$auth, $ownerOnly]);
$router->delete('/api/workspace/members/{id}', [UserController::class, 'removeWorkspaceMember'], [$auth, $ownerOnly]);

// Image Upload Endpoint
$router->post('/api/upload/image', [UploadController::class, 'uploadImage'], [$auth]);

// Admin Endpoints
$router->get('/api/admin/overview', [AdminController::class, 'overview'], [$auth, $adminOnly]);
$router->get('/api/admin/users', [AdminController::class, 'users'], [$auth, $adminOnly]);
$router->put('/api/admin/users/{id}/role', [AdminController::class, 'updateUserRole'], [$auth, $adminOnly]);
$router->get('/api/admin/workspaces', [AdminController::class, 'workspaces'], [$auth, $adminOnly]);

// Dispatch request
$request = new Request();
$router->dispatch($request);
