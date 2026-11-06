-- ProofDesk Row Level Security (RLS) Policies Migration
-- Ensures robust multi-tenant workspace isolation and role-based permissions

-- Enable RLS on all tables
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.workspaces ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.workspace_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.clients ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.project_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.deliverables ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.approval_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.approval_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.comments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.activity_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.invoice_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.user_settings ENABLE ROW LEVEL SECURITY;

-- Helper functions for RLS checks (SECURITY DEFINER to avoid recursion)
CREATE OR REPLACE FUNCTION public.check_is_super_admin(uid UUID)
RETURNS BOOLEAN AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM public.profiles WHERE id = uid AND role = 'super_admin'
    );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

CREATE OR REPLACE FUNCTION public.check_is_workspace_member(ws_id UUID, uid UUID)
RETURNS BOOLEAN AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM public.workspace_members WHERE workspace_id = ws_id AND user_id = uid
    ) OR EXISTS (
        SELECT 1 FROM public.workspaces WHERE id = ws_id AND owner_id = uid
    ) OR public.check_is_super_admin(uid);
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

CREATE OR REPLACE FUNCTION public.check_is_workspace_owner(ws_id UUID, uid UUID)
RETURNS BOOLEAN AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM public.workspaces WHERE id = ws_id AND owner_id = uid
    ) OR EXISTS (
        SELECT 1 FROM public.workspace_members WHERE workspace_id = ws_id AND user_id = uid AND role = 'workspace_owner'
    ) OR public.check_is_super_admin(uid);
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

CREATE OR REPLACE FUNCTION public.check_can_access_project(proj_id UUID, uid UUID)
RETURNS BOOLEAN AS $$
DECLARE
    ws_id UUID;
    proj_client_id UUID;
    client_user_id UUID;
BEGIN
    IF public.check_is_super_admin(uid) THEN
        RETURN TRUE;
    END IF;

    SELECT workspace_id, client_id INTO ws_id, proj_client_id FROM public.projects WHERE id = proj_id;
    IF ws_id IS NULL THEN
        RETURN FALSE;
    END IF;

    -- Workspace member or owner has access
    IF public.check_is_workspace_member(ws_id, uid) THEN
        RETURN TRUE;
    END IF;

    -- Check if user is associated client
    IF proj_client_id IS NOT NULL THEN
        SELECT user_id INTO client_user_id FROM public.clients WHERE id = proj_client_id;
        IF client_user_id = uid THEN
            RETURN TRUE;
        END IF;
    END IF;

    RETURN FALSE;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- 1. Profiles Policies
CREATE POLICY "Profiles are viewable by authenticated users in shared workspaces or self"
ON public.profiles FOR SELECT TO authenticated
USING (
    id = auth.uid() OR
    public.check_is_super_admin(auth.uid()) OR
    EXISTS (
        SELECT 1 FROM public.workspace_members m1
        JOIN public.workspace_members m2 ON m1.workspace_id = m2.workspace_id
        WHERE m1.user_id = auth.uid() AND m2.user_id = public.profiles.id
    )
);

CREATE POLICY "Users can update their own profile"
ON public.profiles FOR UPDATE TO authenticated
USING (id = auth.uid() OR public.check_is_super_admin(auth.uid()))
WITH CHECK (id = auth.uid() OR public.check_is_super_admin(auth.uid()));

-- 2. Workspaces Policies
CREATE POLICY "Users can view workspaces they belong to"
ON public.workspaces FOR SELECT TO authenticated
USING (
    owner_id = auth.uid() OR
    public.check_is_workspace_member(id, auth.uid()) OR
    public.check_is_super_admin(auth.uid())
);

CREATE POLICY "Authenticated users can create workspaces"
ON public.workspaces FOR INSERT TO authenticated
WITH CHECK (owner_id = auth.uid() OR public.check_is_super_admin(auth.uid()));

CREATE POLICY "Workspace owners can update their workspaces"
ON public.workspaces FOR UPDATE TO authenticated
USING (public.check_is_workspace_owner(id, auth.uid()))
WITH CHECK (public.check_is_workspace_owner(id, auth.uid()));

CREATE POLICY "Workspace owners can delete their workspaces"
ON public.workspaces FOR DELETE TO authenticated
USING (owner_id = auth.uid() OR public.check_is_super_admin(auth.uid()));

-- 3. Workspace Members Policies
CREATE POLICY "Workspace members can view member lists"
ON public.workspace_members FOR SELECT TO authenticated
USING (
    public.check_is_workspace_member(workspace_id, auth.uid()) OR
    public.check_is_super_admin(auth.uid())
);

CREATE POLICY "Workspace owners can manage members"
ON public.workspace_members FOR ALL TO authenticated
USING (public.check_is_workspace_owner(workspace_id, auth.uid()))
WITH CHECK (public.check_is_workspace_owner(workspace_id, auth.uid()));

-- 4. Clients Policies
CREATE POLICY "Workspace members and client can view client records"
ON public.clients FOR SELECT TO authenticated
USING (
    public.check_is_workspace_member(workspace_id, auth.uid()) OR
    user_id = auth.uid() OR
    public.check_is_super_admin(auth.uid())
);

CREATE POLICY "Workspace owners and members can insert clients"
ON public.clients FOR INSERT TO authenticated
WITH CHECK (public.check_is_workspace_member(workspace_id, auth.uid()));

CREATE POLICY "Workspace owners and members can update clients"
ON public.clients FOR UPDATE TO authenticated
USING (public.check_is_workspace_member(workspace_id, auth.uid()))
WITH CHECK (public.check_is_workspace_member(workspace_id, auth.uid()));

CREATE POLICY "Workspace owners can delete clients"
ON public.clients FOR DELETE TO authenticated
USING (public.check_is_workspace_owner(workspace_id, auth.uid()));

-- 5. Projects Policies
CREATE POLICY "Users can view projects they have access to"
ON public.projects FOR SELECT TO authenticated
USING (public.check_can_access_project(id, auth.uid()));

CREATE POLICY "Workspace members can create projects"
ON public.projects FOR INSERT TO authenticated
WITH CHECK (public.check_is_workspace_member(workspace_id, auth.uid()));

CREATE POLICY "Workspace members can update projects"
ON public.projects FOR UPDATE TO authenticated
USING (public.check_is_workspace_member(workspace_id, auth.uid()))
WITH CHECK (public.check_is_workspace_member(workspace_id, auth.uid()));

CREATE POLICY "Workspace owners can delete projects"
ON public.projects FOR DELETE TO authenticated
USING (public.check_is_workspace_owner(workspace_id, auth.uid()));

-- 6. Project Members Policies
CREATE POLICY "Users can view project members if they can access project"
ON public.project_members FOR SELECT TO authenticated
USING (public.check_can_access_project(project_id, auth.uid()));

CREATE POLICY "Workspace members can manage project members"
ON public.project_members FOR ALL TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.projects p
        WHERE p.id = public.project_members.project_id
        AND public.check_is_workspace_member(p.workspace_id, auth.uid())
    )
);

-- 7. Tasks Policies
CREATE POLICY "Users can view tasks if they can access project"
ON public.tasks FOR SELECT TO authenticated
USING (public.check_can_access_project(project_id, auth.uid()));

CREATE POLICY "Workspace members can manage tasks"
ON public.tasks FOR ALL TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.projects p
        WHERE p.id = public.tasks.project_id
        AND public.check_is_workspace_member(p.workspace_id, auth.uid())
    )
)
WITH CHECK (
    EXISTS (
        SELECT 1 FROM public.projects p
        WHERE p.id = public.tasks.project_id
        AND public.check_is_workspace_member(p.workspace_id, auth.uid())
    )
);

-- 8. Deliverables Policies
CREATE POLICY "Users can view deliverables if they can access project"
ON public.deliverables FOR SELECT TO authenticated
USING (public.check_can_access_project(project_id, auth.uid()));

CREATE POLICY "Workspace members can upload and manage deliverables"
ON public.deliverables FOR ALL TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.projects p
        WHERE p.id = public.deliverables.project_id
        AND public.check_is_workspace_member(p.workspace_id, auth.uid())
    )
)
WITH CHECK (
    EXISTS (
        SELECT 1 FROM public.projects p
        WHERE p.id = public.deliverables.project_id
        AND public.check_is_workspace_member(p.workspace_id, auth.uid())
    )
);

-- 9. Approval Requests Policies
CREATE POLICY "Users can view approval requests for accessible deliverables"
ON public.approval_requests FOR SELECT TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.deliverables d
        WHERE d.id = public.approval_requests.deliverable_id
        AND public.check_can_access_project(d.project_id, auth.uid())
    )
);

CREATE POLICY "Members and Clients can update approval requests"
ON public.approval_requests FOR UPDATE TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.deliverables d
        WHERE d.id = public.approval_requests.deliverable_id
        AND public.check_can_access_project(d.project_id, auth.uid())
    )
);

CREATE POLICY "Members can insert approval requests"
ON public.approval_requests FOR INSERT TO authenticated
WITH CHECK (
    EXISTS (
        SELECT 1 FROM public.deliverables d
        WHERE d.id = public.approval_requests.deliverable_id
        AND EXISTS (
            SELECT 1 FROM public.projects p
            WHERE p.id = d.project_id
            AND public.check_is_workspace_member(p.workspace_id, auth.uid())
        )
    )
);

-- 10. Approval Events Policies (Immutable Audit Trail)
CREATE POLICY "Users can view approval events for accessible approvals"
ON public.approval_events FOR SELECT TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.approval_requests r
        JOIN public.deliverables d ON d.id = r.deliverable_id
        WHERE r.id = public.approval_events.approval_request_id
        AND public.check_can_access_project(d.project_id, auth.uid())
    )
);

CREATE POLICY "Users can append approval events"
ON public.approval_events FOR INSERT TO authenticated
WITH CHECK (
    EXISTS (
        SELECT 1 FROM public.approval_requests r
        JOIN public.deliverables d ON d.id = r.deliverable_id
        WHERE r.id = public.approval_events.approval_request_id
        AND public.check_can_access_project(d.project_id, auth.uid())
    )
);

-- 11. Comments Policies
CREATE POLICY "Users can view comments on accessible projects"
ON public.comments FOR SELECT TO authenticated
USING (public.check_can_access_project(project_id, auth.uid()));

CREATE POLICY "Users can insert comments on accessible projects"
ON public.comments FOR INSERT TO authenticated
WITH CHECK (public.check_can_access_project(project_id, auth.uid()) AND user_id = auth.uid());

CREATE POLICY "Users can update or delete their own comments"
ON public.comments FOR UPDATE TO authenticated
USING (user_id = auth.uid() OR public.check_is_super_admin(auth.uid()))
WITH CHECK (user_id = auth.uid() OR public.check_is_super_admin(auth.uid()));

CREATE POLICY "Users can delete their own comments"
ON public.comments FOR DELETE TO authenticated
USING (user_id = auth.uid() OR public.check_is_super_admin(auth.uid()));

-- 12. Activity Logs Policies
CREATE POLICY "Members can view workspace activity logs"
ON public.activity_logs FOR SELECT TO authenticated
USING (
    public.check_is_workspace_member(workspace_id, auth.uid()) OR
    public.check_is_super_admin(auth.uid())
);

CREATE POLICY "Authenticated users can insert activity logs"
ON public.activity_logs FOR INSERT TO authenticated
WITH CHECK (
    public.check_is_workspace_member(workspace_id, auth.uid()) OR
    public.check_is_super_admin(auth.uid())
);

-- 13. Notifications Policies
CREATE POLICY "Users can manage their own notifications"
ON public.notifications FOR ALL TO authenticated
USING (user_id = auth.uid())
WITH CHECK (user_id = auth.uid());

-- 14. Invoices Policies
CREATE POLICY "Members and associated clients can view invoices"
ON public.invoices FOR SELECT TO authenticated
USING (
    public.check_is_workspace_member(workspace_id, auth.uid()) OR
    EXISTS (
        SELECT 1 FROM public.clients c
        WHERE c.id = public.invoices.client_id AND c.user_id = auth.uid()
    ) OR
    public.check_is_super_admin(auth.uid())
);

CREATE POLICY "Workspace members can manage invoices"
ON public.invoices FOR ALL TO authenticated
USING (public.check_is_workspace_member(workspace_id, auth.uid()))
WITH CHECK (public.check_is_workspace_member(workspace_id, auth.uid()));

-- 15. Invoice Items Policies
CREATE POLICY "Users can view invoice items if they can view invoice"
ON public.invoice_items FOR SELECT TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.invoices inv
        WHERE inv.id = public.invoice_items.invoice_id
        AND (
            public.check_is_workspace_member(inv.workspace_id, auth.uid()) OR
            EXISTS (
                SELECT 1 FROM public.clients c
                WHERE c.id = inv.client_id AND c.user_id = auth.uid()
            ) OR
            public.check_is_super_admin(auth.uid())
        )
    )
);

CREATE POLICY "Workspace members can manage invoice items"
ON public.invoice_items FOR ALL TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.invoices inv
        WHERE inv.id = public.invoice_items.invoice_id
        AND public.check_is_workspace_member(inv.workspace_id, auth.uid())
    )
);

-- 16. User Settings Policies
CREATE POLICY "Users can manage their own settings"
ON public.user_settings FOR ALL TO authenticated
USING (user_id = auth.uid())
WITH CHECK (user_id = auth.uid());
