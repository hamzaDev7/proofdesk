-- ProofDesk Optional Seed Script with Demonstration Data
-- Execute after migrations have run and at least one user has signed up.

DO $$
DECLARE
    first_user_id UUID;
    sample_ws_id UUID;
    sample_client_id UUID;
    sample_project_id UUID;
    sample_task_id UUID;
    sample_deliverable_id UUID;
    sample_approval_id UUID;
    sample_invoice_id UUID;
BEGIN
    -- Select the first existing user in auth.users or create a placeholder if none
    SELECT id INTO first_user_id FROM auth.users ORDER BY created_at ASC LIMIT 1;

    IF first_user_id IS NULL THEN
        RAISE NOTICE 'No users found in auth.users. Please register at least one user before running seed script.';
        RETURN;
    END IF;

    -- Update user profile to workspace_owner
    UPDATE public.profiles
    SET full_name = 'Alex Morgan',
        role = 'workspace_owner'
    WHERE id = first_user_id;

    -- Create or fetch Workspace
    INSERT INTO public.workspaces (id, name, slug, owner_id)
    VALUES (gen_random_uuid(), 'Acme Creative Studio', 'acme-creative', first_user_id)
    RETURNING id INTO sample_ws_id;

    -- Add Workspace Member
    INSERT INTO public.workspace_members (workspace_id, user_id, role)
    VALUES (sample_ws_id, first_user_id, 'workspace_owner')
    ON CONFLICT DO NOTHING;

    -- Create Client
    INSERT INTO public.clients (id, workspace_id, name, email, company, phone)
    VALUES (
        gen_random_uuid(),
        sample_ws_id,
        'Sarah Jenkins',
        'sarah@nexusretail.com',
        'Nexus Retail Brands',
        '+1 (555) 234-5678'
    )
    RETURNING id INTO sample_client_id;

    -- Create Project
    INSERT INTO public.projects (id, workspace_id, client_id, name, description, status, priority, start_date, deadline, budget, created_by)
    VALUES (
        gen_random_uuid(),
        sample_ws_id,
        sample_client_id,
        'Nexus Q4 Brand Identity & Packaging',
        'Complete brand identity overhaul including packaging design, retail point-of-sale assets, and digital guidelines.',
        'in_progress',
        'high',
        CURRENT_DATE - INTERVAL '14 days',
        CURRENT_DATE + INTERVAL '28 days',
        18500.00,
        first_user_id
    )
    RETURNING id INTO sample_project_id;

    -- Assign Project Member
    INSERT INTO public.project_members (project_id, user_id, role)
    VALUES (sample_project_id, first_user_id, 'lead')
    ON CONFLICT DO NOTHING;

    -- Create Tasks
    INSERT INTO public.tasks (id, project_id, title, description, assigned_to, status, priority, due_date)
    VALUES
    (
        gen_random_uuid(),
        sample_project_id,
        'Finalize Packaging Color Palette & Pantone Swatches',
        'Coordinate with commercial printing vendor for CMYK and Pantone solid coated matches.',
        first_user_id,
        'in_progress',
        'high',
        CURRENT_DATE + INTERVAL '5 days'
    ),
    (
        gen_random_uuid(),
        sample_project_id,
        'Draft Typography & Brand Guidelines Document',
        'Comprehensive 32-page guide detailing logo clearance, typography scales, and incorrect usages.',
        first_user_id,
        'todo',
        'medium',
        CURRENT_DATE + INTERVAL '12 days'
    ),
    (
        gen_random_uuid(),
        sample_project_id,
        'Client Discovery & Competitive Audit Presentation',
        'Audit presentation covering top 5 competitors in retail apparel space.',
        first_user_id,
        'done',
        'medium',
        CURRENT_DATE - INTERVAL '3 days'
    );

    -- Create Deliverable
    INSERT INTO public.deliverables (id, project_id, uploaded_by, title, description, file_path, file_type, file_size, version, status)
    VALUES (
        gen_random_uuid(),
        sample_project_id,
        first_user_id,
        'Nexus_Primary_Logo_Package_v2.pdf',
        'Vector lockups for horizontal, vertical, and sub-brand emblems with dark and light variants.',
        'deliverables/nexus-logo-v2.pdf',
        'application/pdf',
        4829100,
        2,
        'pending'
    )
    RETURNING id INTO sample_deliverable_id;

    -- Create Approval Request
    INSERT INTO public.approval_requests (id, deliverable_id, client_id, status, feedback)
    VALUES (
        gen_random_uuid(),
        sample_deliverable_id,
        sample_client_id,
        'pending',
        'Ready for review. Please check the emblem clear space on page 4.'
    )
    RETURNING id INTO sample_approval_id;

    -- Log Approval Event
    INSERT INTO public.approval_events (approval_request_id, actor_id, action, comment)
    VALUES (
        sample_approval_id,
        first_user_id,
        'created',
        'Deliverable submitted for client review.'
    );

    -- Add Project Comment
    INSERT INTO public.comments (project_id, deliverable_id, user_id, content)
    VALUES (
        sample_project_id,
        sample_deliverable_id,
        first_user_id,
        'Updated typography to Inter Display and calibrated contrast for digital billboards.'
    );

    -- Log Activity
    INSERT INTO public.activity_logs (workspace_id, project_id, user_id, action, metadata)
    VALUES (
        sample_ws_id,
        sample_project_id,
        first_user_id,
        'deliverable_uploaded',
        '{"title": "Nexus_Primary_Logo_Package_v2.pdf", "version": 2}'::jsonb
    );

    -- Create Notification
    INSERT INTO public.notifications (user_id, title, message, link)
    VALUES (
        first_user_id,
        'Welcome to ProofDesk',
        'Your sample workspace and demonstration project have been initialized.',
        'projects.html'
    );

    -- Create Invoice
    INSERT INTO public.invoices (id, workspace_id, client_id, project_id, invoice_number, subtotal, tax, total, status, due_date, notes)
    VALUES (
        gen_random_uuid(),
        sample_ws_id,
        sample_client_id,
        sample_project_id,
        'INV-2026-001',
        7500.00,
        600.00,
        8100.00,
        'sent',
        CURRENT_DATE + INTERVAL '14 days',
        'Milestone 1: Brand Strategy and Primary Identity Design.'
    )
    RETURNING id INTO sample_invoice_id;

    -- Add Invoice Items
    INSERT INTO public.invoice_items (invoice_id, description, quantity, unit_price, total)
    VALUES
    (sample_invoice_id, 'Brand Discovery & Market Strategy', 1, 3500.00, 3500.00),
    (sample_invoice_id, 'Primary & Secondary Vector Logo Suite', 1, 4000.00, 4000.00);

    RAISE NOTICE 'Demo data populated successfully for user: % in workspace: %', first_user_id, sample_ws_id;
END $$;
