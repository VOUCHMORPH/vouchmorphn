-- Migration: Standardize audit_logs table schema
-- Purpose: Add missing columns and ensure consistency across environments
-- Version: 1
-- Created: 2026-07-15
-- Status: PENDING REVIEW

BEGIN;

-- ============================================================
-- 1. Add missing columns to audit_logs
-- ============================================================

-- Add changes column for audit trail data
ALTER TABLE IF EXISTS public.audit_logs 
ADD COLUMN IF NOT EXISTS changes JSONB DEFAULT NULL;

-- Add integrity_hash for verifying audit trail hasn't been tampered with
ALTER TABLE IF EXISTS public.audit_logs 
ADD COLUMN IF NOT EXISTS integrity_hash VARCHAR(255) DEFAULT NULL;

-- Add severity for filtering critical vs info-level audit entries
ALTER TABLE IF EXISTS public.audit_logs 
ADD COLUMN IF NOT EXISTS severity VARCHAR(50) DEFAULT 'INFO'
CHECK (severity IN ('CRITICAL', 'WARNING', 'INFO', 'DEBUG'));

-- Add user_agent for tracking client context
ALTER TABLE IF EXISTS public.audit_logs 
ADD COLUMN IF NOT EXISTS user_agent TEXT DEFAULT NULL;

-- Add country_code for multi-country deployments
ALTER TABLE IF EXISTS public.audit_logs 
ADD COLUMN IF NOT EXISTS country_code CHAR(2) DEFAULT 'BW';

-- ============================================================
-- 2. Standardize performed_by_id type for flexibility
-- ============================================================

-- If performed_by_id is currently restricted to integer,
-- change to TEXT to support both integer and UUID formats
-- This handles both traditional admin_id (int) and UUID-based identifiers
ALTER TABLE IF EXISTS public.audit_logs 
ALTER COLUMN performed_by_id TYPE TEXT;

-- ============================================================
-- 3. Add performance indexes
-- ============================================================

-- Index for filtering by severity (used in dashboards)
CREATE INDEX IF NOT EXISTS idx_audit_severity 
ON public.audit_logs(severity);

-- Index for filtering by country (multi-country support)
CREATE INDEX IF NOT EXISTS idx_audit_country 
ON public.audit_logs(country_code);

-- Index for filtering by performed_by_id (common queries)
CREATE INDEX IF NOT EXISTS idx_audit_performed_by_id 
ON public.audit_logs(performed_by_id);

-- Composite index for common query patterns
CREATE INDEX IF NOT EXISTS idx_audit_entity_action 
ON public.audit_logs(entity_type, action, performed_at DESC);

-- ============================================================
-- 4. Ensure admin_id and user_id types are compatible
-- ============================================================

-- These may need to be TEXT if performed_by_id is TEXT
-- Uncomment if needed after testing:
-- ALTER TABLE IF EXISTS public.admins 
-- ALTER COLUMN admin_id TYPE TEXT;

-- ALTER TABLE IF EXISTS public.users 
-- ALTER COLUMN user_id TYPE TEXT;

-- ============================================================
-- 5. Add NOT NULL constraints where appropriate
-- ============================================================

-- performed_by_type should always be set
ALTER TABLE IF EXISTS public.audit_logs 
ALTER COLUMN performed_by_type SET NOT NULL;

-- performed_at should always be set
ALTER TABLE IF EXISTS public.audit_logs 
ALTER COLUMN performed_at SET NOT NULL;

-- ============================================================
-- 6. Verify audit_logs structure is complete
-- ============================================================

-- This query can be run after migration to verify all columns exist:
-- SELECT column_name, data_type, is_nullable 
-- FROM information_schema.columns 
-- WHERE table_name = 'audit_logs' 
-- ORDER BY ordinal_position;

COMMIT;

-- ============================================================
-- Verification Queries (run after migration)
-- ============================================================

/*
-- Check all expected columns exist:
SELECT 
    column_name,
    data_type,
    is_nullable
FROM information_schema.columns 
WHERE table_name = 'audit_logs'
ORDER BY ordinal_position;

-- Check indexes were created:
SELECT indexname FROM pg_indexes WHERE tablename = 'audit_logs';

-- Check constraints:
SELECT constraint_name, constraint_type 
FROM information_schema.table_constraints 
WHERE table_name = 'audit_logs';
*/
