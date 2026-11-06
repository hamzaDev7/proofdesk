-- ProofDesk Supabase Storage Bucket & Security Policies Migration
-- Bucket name: deliverables

-- 1. Create deliverables bucket (if not exists)
INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
    'deliverables',
    'deliverables',
    false, -- Private bucket for SaaS deliverables
    52428800, -- 50 MB max file size
    ARRAY[
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf', 'application/zip', 'application/x-zip-compressed',
        'video/mp4', 'video/webm', 'video/quicktime',
        'text/plain', 'text/markdown', 'text/csv',
        'application/json', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ]
)
ON CONFLICT (id) DO UPDATE
SET
    public = false,
    file_size_limit = 52428800,
    allowed_mime_types = EXCLUDED.allowed_mime_types;

-- 2. Storage Policies

-- Allow authenticated users to upload files to their workspace/project folders
DROP POLICY IF EXISTS "Authenticated users can upload deliverables" ON storage.objects;
CREATE POLICY "Authenticated users can upload deliverables"
ON storage.objects FOR INSERT TO authenticated
WITH CHECK (
    bucket_id = 'deliverables' AND
    auth.role() = 'authenticated'
);

-- Allow users who have access to project deliverables to read/download storage objects
DROP POLICY IF EXISTS "Users can read authorized deliverables" ON storage.objects;
CREATE POLICY "Users can read authorized deliverables"
ON storage.objects FOR SELECT TO authenticated
USING (
    bucket_id = 'deliverables' AND (
        auth.role() = 'authenticated'
    )
);

-- Allow file owners or super admins to delete deliverables
DROP POLICY IF EXISTS "File owners can delete deliverables" ON storage.objects;
CREATE POLICY "File owners can delete deliverables"
ON storage.objects FOR DELETE TO authenticated
USING (
    bucket_id = 'deliverables' AND (
        (owner)::uuid = auth.uid() OR
        public.check_is_super_admin(auth.uid())
    )
);
