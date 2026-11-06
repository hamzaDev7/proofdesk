<?php

namespace ProofDesk\Middleware;

use ProofDesk\Core\Request;
use ProofDesk\Core\Response;

class RoleMiddleware
{
    private array $allowedRoles;

    public function __construct(array $allowedRoles = [])
    {
        $this->allowedRoles = $allowedRoles;
    }

    public static function requireRoles(string ...$roles): \Closure
    {
        return function (Request $request) use ($roles) {
            if (!$request->user) {
                Response::error('Authentication required.', 401, null, 'UNAUTHORIZED');
            }

            $userRole = $request->user['role'] ?? 'workspace_member';
            $wsRole = $request->user['active_workspace_role'] ?? $userRole;

            // Super Admin can bypass all role checks
            if ($userRole === 'super_admin') {
                return;
            }

            $hasAccess = in_array($userRole, $roles) || in_array($wsRole, $roles);

            if (!$hasAccess) {
                Response::error('Forbidden: You do not have permission to perform this action.', 403, null, 'FORBIDDEN');
            }
        };
    }
}
