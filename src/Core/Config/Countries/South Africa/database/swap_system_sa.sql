    
--
-- PostgreSQL database dump
--

\restrict e385Tco0wzyxGxejaBEUCTmnWALpjQpoSgKj51Dpd1SJyJmqoWN09NIK43YuFwe

-- Dumped from database version 17.10 (Debian 17.10-1.pgdg13+1)
-- Dumped by pg_dump version 17.10 (Ubuntu 17.10-1.pgdg24.04+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

ALTER TABLE IF EXISTS ONLY public.transaction_splits DROP CONSTRAINT IF EXISTS transaction_splits_transaction_id_fkey;
ALTER TABLE IF EXISTS ONLY public.transaction_splits DROP CONSTRAINT IF EXISTS transaction_splits_credited_account_fkey;
ALTER TABLE IF EXISTS ONLY public.swap_vouchers DROP CONSTRAINT IF EXISTS swap_vouchers_swap_id_fkey;
ALTER TABLE IF EXISTS ONLY public.swap_transactions DROP CONSTRAINT IF EXISTS swap_transactions_transaction_id_fkey;
ALTER TABLE IF EXISTS ONLY public.swap_transactions DROP CONSTRAINT IF EXISTS swap_transactions_ledger_entry_id_fkey;
ALTER TABLE IF EXISTS ONLY public.sandbox_disclosures DROP CONSTRAINT IF EXISTS sandbox_disclosures_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.regulatory_reports DROP CONSTRAINT IF EXISTS regulatory_reports_generated_by_fkey;
ALTER TABLE IF EXISTS ONLY public.regulator_notifications DROP CONSTRAINT IF EXISTS regulator_notifications_read_by_fkey;
ALTER TABLE IF EXISTS ONLY public.participant_fee_overrides DROP CONSTRAINT IF EXISTS participant_fee_overrides_participant_id_fkey;
ALTER TABLE IF EXISTS ONLY public.message_cards DROP CONSTRAINT IF EXISTS message_cards_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.message_cards DROP CONSTRAINT IF EXISTS message_cards_swap_reference_fkey;
ALTER TABLE IF EXISTS ONLY public.message_cards DROP CONSTRAINT IF EXISTS message_cards_hold_reference_fkey;
ALTER TABLE IF EXISTS ONLY public.message_cards DROP CONSTRAINT IF EXISTS message_cards_batch_id_fkey;
ALTER TABLE IF EXISTS ONLY public.ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_transaction_id_fkey;
ALTER TABLE IF EXISTS ONLY public.ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_debit_account_id_fkey;
ALTER TABLE IF EXISTS ONLY public.ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_credit_account_id_fkey;
ALTER TABLE IF EXISTS ONLY public.kyc_documents DROP CONSTRAINT IF EXISTS kyc_documents_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.kyc_documents DROP CONSTRAINT IF EXISTS kyc_documents_admin_reviewer_id_fkey;
ALTER TABLE IF EXISTS ONLY public.hold_transactions DROP CONSTRAINT IF EXISTS hold_transactions_participant_id_fkey;
ALTER TABLE IF EXISTS ONLY public.hold_transactions DROP CONSTRAINT IF EXISTS hold_transactions_destination_participant_id_fkey;
ALTER TABLE IF EXISTS ONLY public.card_transactions DROP CONSTRAINT IF EXISTS card_transactions_settlement_queue_id_fkey;
ALTER TABLE IF EXISTS ONLY public.card_transactions DROP CONSTRAINT IF EXISTS card_transactions_card_id_fkey;
ALTER TABLE IF EXISTS ONLY public.card_applications DROP CONSTRAINT IF EXISTS card_applications_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.card_applications DROP CONSTRAINT IF EXISTS card_applications_card_id_fkey;
ALTER TABLE IF EXISTS ONLY public.api_message_logs DROP CONSTRAINT IF EXISTS api_message_logs_participant_id_fkey;
ALTER TABLE IF EXISTS ONLY public.aml_checks DROP CONSTRAINT IF EXISTS aml_checks_user_id_fkey;
ALTER TABLE IF EXISTS ONLY public.admins DROP CONSTRAINT IF EXISTS admins_updated_by_fkey;
ALTER TABLE IF EXISTS ONLY public.admins DROP CONSTRAINT IF EXISTS admins_role_id_fkey;
ALTER TABLE IF EXISTS ONLY public.admins DROP CONSTRAINT IF EXISTS admins_created_by_fkey;
ALTER TABLE IF EXISTS ONLY public.admin_actions DROP CONSTRAINT IF EXISTS admin_actions_admin_id_fkey;
DROP TRIGGER IF EXISTS update_users_updated_at ON public.users;
DROP TRIGGER IF EXISTS update_transactions_updated_at ON public.transactions;
DROP TRIGGER IF EXISTS update_swap_transactions_updated_at ON public.swap_transactions;
DROP TRIGGER IF EXISTS update_swap_fee_collections_updated_at ON public.swap_fee_collections;
DROP TRIGGER IF EXISTS update_settlement_queue_updated_at ON public.settlement_queue;
DROP TRIGGER IF EXISTS update_participants_updated_at ON public.participants;
DROP TRIGGER IF EXISTS update_net_positions_updated_at ON public.net_positions;
DROP TRIGGER IF EXISTS update_ledger_entries_updated_at ON public.ledger_entries;
DROP TRIGGER IF EXISTS update_ledger_accounts_updated_at ON public.ledger_accounts;
DROP TRIGGER IF EXISTS update_kyc_documents_updated_at ON public.kyc_documents;
DROP TRIGGER IF EXISTS update_hold_transactions_updated_at ON public.hold_transactions;
DROP TRIGGER IF EXISTS update_admins_updated_at ON public.admins;
DROP TRIGGER IF EXISTS trg_users_updated ON public.users;
DROP TRIGGER IF EXISTS trg_transactions_updated ON public.transactions;
DROP TRIGGER IF EXISTS trg_transaction_splits_updated ON public.transaction_splits;
DROP TRIGGER IF EXISTS trg_swap_transactions_updated ON public.swap_transactions;
DROP TRIGGER IF EXISTS trg_supervisory_heartbeat_updated ON public.supervisory_heartbeat;
DROP TRIGGER IF EXISTS trg_sandbox_disclosures_updated ON public.sandbox_disclosures;
DROP TRIGGER IF EXISTS trg_regulator_outbox_updated ON public.regulator_outbox;
DROP TRIGGER IF EXISTS trg_otp_logs_updated ON public.otp_logs;
DROP TRIGGER IF EXISTS trg_ledger_entries_updated ON public.ledger_entries;
DROP TRIGGER IF EXISTS trg_ledger_accounts_updated ON public.ledger_accounts;
DROP TRIGGER IF EXISTS trg_kyc_documents_updated ON public.kyc_documents;
DROP TRIGGER IF EXISTS trg_card_applications_updated ON public.card_applications;
DROP TRIGGER IF EXISTS trg_audit_logs_updated ON public.audit_logs;
DROP TRIGGER IF EXISTS trg_audit_logs_integrity ON public.audit_logs;
DROP TRIGGER IF EXISTS trg_aml_checks_updated ON public.aml_checks;
DROP TRIGGER IF EXISTS trg_admins_updated ON public.admins;
DROP INDEX IF EXISTS public.uniq_session_key;
DROP INDEX IF EXISTS public.idx_swap_vouchers_status;
DROP INDEX IF EXISTS public.idx_swap_vouchers_phone;
DROP INDEX IF EXISTS public.idx_swap_vouchers_code_hash;
DROP INDEX IF EXISTS public.idx_swap_requests_uuid;
DROP INDEX IF EXISTS public.idx_swap_requests_status;
DROP INDEX IF EXISTS public.idx_settlement_reports_date;
DROP INDEX IF EXISTS public.idx_settlement_queue_created;
DROP INDEX IF EXISTS public.idx_settlement_messages_status;
DROP INDEX IF EXISTS public.idx_send_status;
DROP INDEX IF EXISTS public.idx_send_sender;
DROP INDEX IF EXISTS public.idx_send_receiver;
DROP INDEX IF EXISTS public.idx_regulatory_reports_type_date;
DROP INDEX IF EXISTS public.idx_regulator_notifications_severity;
DROP INDEX IF EXISTS public.idx_regulator_notifications_read;
DROP INDEX IF EXISTS public.idx_net_positions_debtor_creditor;
DROP INDEX IF EXISTS public.idx_net_positions_debtor;
DROP INDEX IF EXISTS public.idx_net_positions_creditor;
DROP INDEX IF EXISTS public.idx_message_outbox_status;
DROP INDEX IF EXISTS public.idx_message_outbox_created;
DROP INDEX IF EXISTS public.idx_message_cards_user;
DROP INDEX IF EXISTS public.idx_message_cards_lifecycle;
DROP INDEX IF EXISTS public.idx_message_cards_hold;
DROP INDEX IF EXISTS public.idx_message_cards_hash;
DROP INDEX IF EXISTS public.idx_message_cards_batch;
DROP INDEX IF EXISTS public.idx_holds_swap;
DROP INDEX IF EXISTS public.idx_holds_status;
DROP INDEX IF EXISTS public.idx_holds_reference;
DROP INDEX IF EXISTS public.idx_holds_expiry_status;
DROP INDEX IF EXISTS public.idx_fee_collections_swap_ref;
DROP INDEX IF EXISTS public.idx_fee_collections_status;
DROP INDEX IF EXISTS public.idx_fee_collections_date;
DROP INDEX IF EXISTS public.idx_deposit_status;
DROP INDEX IF EXISTS public.idx_deposit_phone;
DROP INDEX IF EXISTS public.idx_deposit_created;
DROP INDEX IF EXISTS public.idx_cashout_status;
DROP INDEX IF EXISTS public.idx_cashout_phone;
DROP INDEX IF EXISTS public.idx_cashout_expiry;
DROP INDEX IF EXISTS public.idx_cashout_code;
DROP INDEX IF EXISTS public.idx_card_transactions_date;
DROP INDEX IF EXISTS public.idx_card_transactions_card;
DROP INDEX IF EXISTS public.idx_card_transactions_auth;
DROP INDEX IF EXISTS public.idx_card_auth_swap_ref;
DROP INDEX IF EXISTS public.idx_card_auth_status;
DROP INDEX IF EXISTS public.idx_card_auth_hold_ref;
DROP INDEX IF EXISTS public.idx_card_auth_expiry;
DROP INDEX IF EXISTS public.idx_card_auth_card_suffix;
DROP INDEX IF EXISTS public.idx_card_applications_user;
DROP INDEX IF EXISTS public.idx_card_applications_status;
DROP INDEX IF EXISTS public.idx_card_applications_phone;
DROP INDEX IF EXISTS public.idx_card_applications_email;
DROP INDEX IF EXISTS public.idx_card_applications_created;
DROP INDEX IF EXISTS public.idx_api_logs_type;
DROP INDEX IF EXISTS public.idx_api_logs_success;
DROP INDEX IF EXISTS public.idx_api_logs_participant;
DROP INDEX IF EXISTS public.idx_api_logs_message_id;
DROP INDEX IF EXISTS public.idx_api_logs_created;
DROP INDEX IF EXISTS public.idx_admin_actions_type;
DROP INDEX IF EXISTS public.idx_admin_actions_created;
DROP INDEX IF EXISTS public.idx_admin_actions_admin;
ALTER TABLE IF EXISTS ONLY public.vouchmorph_notifications DROP CONSTRAINT IF EXISTS vouchmorph_notifications_pkey;
ALTER TABLE IF EXISTS ONLY public.ussd_sessions DROP CONSTRAINT IF EXISTS ussd_sessions_pkey;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_pkey;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_phone_key;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_email_key;
ALTER TABLE IF EXISTS ONLY public.user_hooks DROP CONSTRAINT IF EXISTS user_hooks_user_identifier_hook_name_key;
ALTER TABLE IF EXISTS ONLY public.user_hooks DROP CONSTRAINT IF EXISTS user_hooks_pkey;
ALTER TABLE IF EXISTS ONLY public.transactions DROP CONSTRAINT IF EXISTS transactions_reference_uuid_key;
ALTER TABLE IF EXISTS ONLY public.transactions DROP CONSTRAINT IF EXISTS transactions_pkey;
ALTER TABLE IF EXISTS ONLY public.transaction_splits DROP CONSTRAINT IF EXISTS transaction_splits_pkey;
ALTER TABLE IF EXISTS ONLY public.transaction_fees DROP CONSTRAINT IF EXISTS transaction_fees_transaction_type_key;
ALTER TABLE IF EXISTS ONLY public.transaction_fees DROP CONSTRAINT IF EXISTS transaction_fees_pkey;
ALTER TABLE IF EXISTS ONLY public.swap_vouchers DROP CONSTRAINT IF EXISTS swap_vouchers_pkey;
ALTER TABLE IF EXISTS ONLY public.swap_transactions DROP CONSTRAINT IF EXISTS swap_transactions_pkey;
ALTER TABLE IF EXISTS ONLY public.swap_requests DROP CONSTRAINT IF EXISTS swap_requests_swap_uuid_key;
ALTER TABLE IF EXISTS ONLY public.swap_requests DROP CONSTRAINT IF EXISTS swap_requests_pkey;
ALTER TABLE IF EXISTS ONLY public.swap_ledgers DROP CONSTRAINT IF EXISTS swap_ledgers_pkey;
ALTER TABLE IF EXISTS ONLY public.swap_fee_collections DROP CONSTRAINT IF EXISTS swap_fee_collections_pkey;
ALTER TABLE IF EXISTS ONLY public.supervisory_heartbeat DROP CONSTRAINT IF EXISTS supervisory_heartbeat_pkey;
ALTER TABLE IF EXISTS ONLY public.settlement_reports DROP CONSTRAINT IF EXISTS settlement_reports_report_hash_key;
ALTER TABLE IF EXISTS ONLY public.settlement_reports DROP CONSTRAINT IF EXISTS settlement_reports_pkey;
ALTER TABLE IF EXISTS ONLY public.settlement_queue DROP CONSTRAINT IF EXISTS settlement_queue_pkey;
ALTER TABLE IF EXISTS ONLY public.settlement_queue DROP CONSTRAINT IF EXISTS settlement_queue_debtor_creditor_key;
ALTER TABLE IF EXISTS ONLY public.settlement_messages DROP CONSTRAINT IF EXISTS settlement_messages_pkey;
ALTER TABLE IF EXISTS ONLY public.send_to_other_transactions DROP CONSTRAINT IF EXISTS send_to_other_transactions_transaction_reference_key;
ALTER TABLE IF EXISTS ONLY public.send_to_other_transactions DROP CONSTRAINT IF EXISTS send_to_other_transactions_pkey;
ALTER TABLE IF EXISTS ONLY public.sandbox_disclosures DROP CONSTRAINT IF EXISTS sandbox_disclosures_pkey;
ALTER TABLE IF EXISTS ONLY public.roles DROP CONSTRAINT IF EXISTS roles_role_name_key;
ALTER TABLE IF EXISTS ONLY public.roles DROP CONSTRAINT IF EXISTS roles_pkey;
ALTER TABLE IF EXISTS ONLY public.regulatory_reports DROP CONSTRAINT IF EXISTS regulatory_reports_pkey;
ALTER TABLE IF EXISTS ONLY public.regulator_outbox DROP CONSTRAINT IF EXISTS regulator_outbox_report_id_key;
ALTER TABLE IF EXISTS ONLY public.regulator_outbox DROP CONSTRAINT IF EXISTS regulator_outbox_pkey;
ALTER TABLE IF EXISTS ONLY public.regulator_notifications DROP CONSTRAINT IF EXISTS regulator_notifications_pkey;
ALTER TABLE IF EXISTS ONLY public.participants DROP CONSTRAINT IF EXISTS participants_pkey;
ALTER TABLE IF EXISTS ONLY public.participants DROP CONSTRAINT IF EXISTS participants_name_key;
ALTER TABLE IF EXISTS ONLY public.participant_fee_overrides DROP CONSTRAINT IF EXISTS participant_fee_overrides_pkey;
ALTER TABLE IF EXISTS ONLY public.participant_fee_overrides DROP CONSTRAINT IF EXISTS participant_fee_overrides_participant_id_transaction_type_key;
ALTER TABLE IF EXISTS ONLY public.participant_currencies DROP CONSTRAINT IF EXISTS participant_currencies_pkey;
ALTER TABLE IF EXISTS ONLY public.otp_logs DROP CONSTRAINT IF EXISTS otp_logs_pkey;
ALTER TABLE IF EXISTS ONLY public.net_positions DROP CONSTRAINT IF EXISTS net_positions_pkey;
ALTER TABLE IF EXISTS ONLY public.net_positions DROP CONSTRAINT IF EXISTS net_positions_debtor_creditor_key;
ALTER TABLE IF EXISTS ONLY public.message_outbox DROP CONSTRAINT IF EXISTS message_outbox_pkey;
ALTER TABLE IF EXISTS ONLY public.message_cards DROP CONSTRAINT IF EXISTS message_cards_pkey;
ALTER TABLE IF EXISTS ONLY public.message_cards DROP CONSTRAINT IF EXISTS message_cards_card_number_hash_key;
ALTER TABLE IF EXISTS ONLY public.ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_pkey;
ALTER TABLE IF EXISTS ONLY public.ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_pkey;
ALTER TABLE IF EXISTS ONLY public.ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_account_code_key;
ALTER TABLE IF EXISTS ONLY public.kyc_documents DROP CONSTRAINT IF EXISTS kyc_documents_pkey;
ALTER TABLE IF EXISTS ONLY public.hold_transactions DROP CONSTRAINT IF EXISTS hold_transactions_pkey;
ALTER TABLE IF EXISTS ONLY public.hold_transactions DROP CONSTRAINT IF EXISTS hold_transactions_hold_reference_key;
ALTER TABLE IF EXISTS ONLY public.fx_rates DROP CONSTRAINT IF EXISTS fx_rates_pkey;
ALTER TABLE IF EXISTS ONLY public.fx_quotes DROP CONSTRAINT IF EXISTS fx_quotes_quote_uuid_key;
ALTER TABLE IF EXISTS ONLY public.fx_quotes DROP CONSTRAINT IF EXISTS fx_quotes_pkey;
ALTER TABLE IF EXISTS ONLY public.fx_providers DROP CONSTRAINT IF EXISTS fx_providers_pkey;
ALTER TABLE IF EXISTS ONLY public.deposit_transactions DROP CONSTRAINT IF EXISTS deposit_transactions_transaction_reference_key;
ALTER TABLE IF EXISTS ONLY public.deposit_transactions DROP CONSTRAINT IF EXISTS deposit_transactions_pkey;
ALTER TABLE IF EXISTS ONLY public.cashout_retry_tracking DROP CONSTRAINT IF EXISTS cashout_retry_tracking_pkey;
ALTER TABLE IF EXISTS ONLY public.cashout_retry_tracking DROP CONSTRAINT IF EXISTS cashout_retry_tracking_client_identifier_original_swap_ref_key;
ALTER TABLE IF EXISTS ONLY public.cashout_authorizations DROP CONSTRAINT IF EXISTS cashout_authorizations_swap_reference_key;
ALTER TABLE IF EXISTS ONLY public.cashout_authorizations DROP CONSTRAINT IF EXISTS cashout_authorizations_swap_code_key;
ALTER TABLE IF EXISTS ONLY public.cashout_authorizations DROP CONSTRAINT IF EXISTS cashout_authorizations_pkey;
ALTER TABLE IF EXISTS ONLY public.card_transactions DROP CONSTRAINT IF EXISTS card_transactions_pkey;
ALTER TABLE IF EXISTS ONLY public.card_batches DROP CONSTRAINT IF EXISTS card_batches_pkey;
ALTER TABLE IF EXISTS ONLY public.card_batches DROP CONSTRAINT IF EXISTS card_batches_batch_reference_key;
ALTER TABLE IF EXISTS ONLY public.card_authorizations DROP CONSTRAINT IF EXISTS card_authorizations_vrn_key;
ALTER TABLE IF EXISTS ONLY public.card_authorizations DROP CONSTRAINT IF EXISTS card_authorizations_pkey;
ALTER TABLE IF EXISTS ONLY public.card_applications DROP CONSTRAINT IF EXISTS card_applications_pkey;
ALTER TABLE IF EXISTS ONLY public.audit_logs DROP CONSTRAINT IF EXISTS audit_logs_pkey;
ALTER TABLE IF EXISTS ONLY public.audit_logs DROP CONSTRAINT IF EXISTS audit_logs_audit_uuid_key;
ALTER TABLE IF EXISTS ONLY public.api_message_logs DROP CONSTRAINT IF EXISTS api_message_logs_pkey;
ALTER TABLE IF EXISTS ONLY public.aml_checks DROP CONSTRAINT IF EXISTS aml_checks_pkey;
ALTER TABLE IF EXISTS ONLY public.admins DROP CONSTRAINT IF EXISTS admins_username_key;
ALTER TABLE IF EXISTS ONLY public.admins DROP CONSTRAINT IF EXISTS admins_pkey;
ALTER TABLE IF EXISTS ONLY public.admins DROP CONSTRAINT IF EXISTS admins_email_key;
ALTER TABLE IF EXISTS ONLY public.admin_actions DROP CONSTRAINT IF EXISTS admin_actions_pkey;
ALTER TABLE IF EXISTS public.vouchmorph_notifications ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.ussd_sessions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.users ALTER COLUMN user_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.user_hooks ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.transactions ALTER COLUMN transaction_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.transaction_splits ALTER COLUMN split_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.transaction_fees ALTER COLUMN fee_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.swap_vouchers ALTER COLUMN voucher_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.swap_transactions ALTER COLUMN swap_transaction_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.swap_requests ALTER COLUMN swap_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.swap_ledgers ALTER COLUMN ledger_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.swap_fee_collections ALTER COLUMN fee_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.supervisory_heartbeat ALTER COLUMN heartbeat_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.settlement_reports ALTER COLUMN settlement_report_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.settlement_queue ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.settlement_messages ALTER COLUMN message_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.send_to_other_transactions ALTER COLUMN send_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.sandbox_disclosures ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.regulatory_reports ALTER COLUMN report_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.regulator_outbox ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.regulator_notifications ALTER COLUMN notification_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.participants ALTER COLUMN participant_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.participant_fee_overrides ALTER COLUMN override_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.participant_currencies ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.otp_logs ALTER COLUMN otp_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.net_positions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.message_cards ALTER COLUMN card_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.ledger_entries ALTER COLUMN entry_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.ledger_accounts ALTER COLUMN account_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.kyc_documents ALTER COLUMN kyc_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.hold_transactions ALTER COLUMN hold_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.fx_rates ALTER COLUMN rate_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.fx_quotes ALTER COLUMN quote_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.fx_providers ALTER COLUMN fx_provider_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.deposit_transactions ALTER COLUMN deposit_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.cashout_retry_tracking ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.cashout_authorizations ALTER COLUMN auth_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.card_transactions ALTER COLUMN transaction_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.card_batches ALTER COLUMN batch_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.card_authorizations ALTER COLUMN authorization_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.audit_logs ALTER COLUMN audit_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.api_message_logs ALTER COLUMN log_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.aml_checks ALTER COLUMN check_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.admins ALTER COLUMN admin_id DROP DEFAULT;
ALTER TABLE IF EXISTS public.admin_actions ALTER COLUMN action_id DROP DEFAULT;
DROP SEQUENCE IF EXISTS public.vouchmorph_notifications_id_seq;
DROP TABLE IF EXISTS public.vouchmorph_notifications;
DROP SEQUENCE IF EXISTS public.ussd_sessions_id_seq;
DROP TABLE IF EXISTS public.ussd_sessions;
DROP SEQUENCE IF EXISTS public.users_user_id_seq;
DROP TABLE IF EXISTS public.users;
DROP SEQUENCE IF EXISTS public.user_hooks_id_seq;
DROP TABLE IF EXISTS public.user_hooks;
DROP SEQUENCE IF EXISTS public.transactions_transaction_id_seq;
DROP TABLE IF EXISTS public.transactions;
DROP SEQUENCE IF EXISTS public.transaction_splits_split_id_seq;
DROP TABLE IF EXISTS public.transaction_splits;
DROP VIEW IF EXISTS public.transaction_log_view;
DROP SEQUENCE IF EXISTS public.transaction_fees_fee_id_seq;
DROP TABLE IF EXISTS public.transaction_fees;
DROP SEQUENCE IF EXISTS public.swap_vouchers_voucher_id_seq;
DROP TABLE IF EXISTS public.swap_vouchers;
DROP SEQUENCE IF EXISTS public.swap_transactions_swap_transaction_id_seq;
DROP TABLE IF EXISTS public.swap_transactions;
DROP SEQUENCE IF EXISTS public.swap_requests_swap_id_seq;
DROP TABLE IF EXISTS public.swap_requests;
DROP SEQUENCE IF EXISTS public.swap_ledgers_ledger_id_seq;
DROP TABLE IF EXISTS public.swap_ledgers;
DROP SEQUENCE IF EXISTS public.swap_fee_collections_fee_id_seq;
DROP TABLE IF EXISTS public.swap_fee_collections;
DROP SEQUENCE IF EXISTS public.supervisory_heartbeat_heartbeat_id_seq;
DROP TABLE IF EXISTS public.supervisory_heartbeat;
DROP SEQUENCE IF EXISTS public.settlement_reports_settlement_report_id_seq;
DROP TABLE IF EXISTS public.settlement_reports;
DROP SEQUENCE IF EXISTS public.settlement_queue_id_seq;
DROP TABLE IF EXISTS public.settlement_queue;
DROP SEQUENCE IF EXISTS public.settlement_messages_message_id_seq;
DROP TABLE IF EXISTS public.settlement_messages;
DROP SEQUENCE IF EXISTS public.send_to_other_transactions_send_id_seq;
DROP TABLE IF EXISTS public.send_to_other_transactions;
DROP SEQUENCE IF EXISTS public.sandbox_disclosures_id_seq;
DROP TABLE IF EXISTS public.sandbox_disclosures;
DROP TABLE IF EXISTS public.roles;
DROP SEQUENCE IF EXISTS public.regulatory_reports_report_id_seq;
DROP TABLE IF EXISTS public.regulatory_reports;
DROP SEQUENCE IF EXISTS public.regulator_outbox_id_seq;
DROP TABLE IF EXISTS public.regulator_outbox;
DROP SEQUENCE IF EXISTS public.regulator_notifications_notification_id_seq;
DROP TABLE IF EXISTS public.regulator_notifications;
DROP SEQUENCE IF EXISTS public.participants_participant_id_seq;
DROP TABLE IF EXISTS public.participants;
DROP SEQUENCE IF EXISTS public.participant_fee_overrides_override_id_seq;
DROP TABLE IF EXISTS public.participant_fee_overrides;
DROP SEQUENCE IF EXISTS public.participant_currencies_id_seq;
DROP TABLE IF EXISTS public.participant_currencies;
DROP SEQUENCE IF EXISTS public.otp_logs_otp_id_seq;
DROP TABLE IF EXISTS public.otp_logs;
DROP SEQUENCE IF EXISTS public.net_positions_id_seq;
DROP TABLE IF EXISTS public.net_positions;
DROP TABLE IF EXISTS public.message_outbox;
DROP SEQUENCE IF EXISTS public.message_cards_card_id_seq;
DROP TABLE IF EXISTS public.message_cards;
DROP SEQUENCE IF EXISTS public.ledger_entries_entry_id_seq;
DROP TABLE IF EXISTS public.ledger_entries;
DROP SEQUENCE IF EXISTS public.ledger_accounts_account_id_seq;
DROP TABLE IF EXISTS public.ledger_accounts;
DROP SEQUENCE IF EXISTS public.kyc_documents_kyc_id_seq;
DROP TABLE IF EXISTS public.kyc_documents;
DROP SEQUENCE IF EXISTS public.hold_transactions_hold_id_seq;
DROP TABLE IF EXISTS public.hold_transactions;
DROP SEQUENCE IF EXISTS public.fx_rates_rate_id_seq;
DROP TABLE IF EXISTS public.fx_rates;
DROP SEQUENCE IF EXISTS public.fx_quotes_quote_id_seq;
DROP TABLE IF EXISTS public.fx_quotes;
DROP SEQUENCE IF EXISTS public.fx_providers_fx_provider_id_seq;
DROP TABLE IF EXISTS public.fx_providers;
DROP SEQUENCE IF EXISTS public.deposit_transactions_deposit_id_seq;
DROP TABLE IF EXISTS public.deposit_transactions;
DROP SEQUENCE IF EXISTS public.cashout_retry_tracking_id_seq;
DROP TABLE IF EXISTS public.cashout_retry_tracking;
DROP SEQUENCE IF EXISTS public.cashout_authorizations_auth_id_seq;
DROP TABLE IF EXISTS public.cashout_authorizations;
DROP SEQUENCE IF EXISTS public.card_transactions_transaction_id_seq;
DROP TABLE IF EXISTS public.card_transactions;
DROP SEQUENCE IF EXISTS public.card_batches_batch_id_seq;
DROP TABLE IF EXISTS public.card_batches;
DROP SEQUENCE IF EXISTS public.card_authorizations_authorization_id_seq;
DROP TABLE IF EXISTS public.card_authorizations;
DROP TABLE IF EXISTS public.card_applications;
DROP SEQUENCE IF EXISTS public.audit_logs_audit_id_seq;
DROP TABLE IF EXISTS public.audit_logs;
DROP SEQUENCE IF EXISTS public.api_message_logs_log_id_seq;
DROP TABLE IF EXISTS public.api_message_logs;
DROP SEQUENCE IF EXISTS public.aml_checks_check_id_seq;
DROP TABLE IF EXISTS public.aml_checks;
DROP SEQUENCE IF EXISTS public.admins_admin_id_seq;
DROP TABLE IF EXISTS public.admins;
DROP SEQUENCE IF EXISTS public.admin_actions_action_id_seq;
DROP TABLE IF EXISTS public.admin_actions;
DROP FUNCTION IF EXISTS public.update_updated_at_column();
DROP FUNCTION IF EXISTS public.update_card_applications_updated_at();
DROP FUNCTION IF EXISTS public.load_participants_from_json(json_file_path text);
DROP FUNCTION IF EXISTS public.fn_update_timestamp();
DROP FUNCTION IF EXISTS public.audit_log_integrity_trigger();
DROP TYPE IF EXISTS public.swap_status;
DROP TYPE IF EXISTS public.ledger_type;
DROP TYPE IF EXISTS public.kyc_status;
DROP TYPE IF EXISTS public.fraud_status;
DROP TYPE IF EXISTS public.account_type;
DROP EXTENSION IF EXISTS pgcrypto;
--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS '';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: account_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.account_type AS ENUM (
    'asset',
    'liability',
    'equity',
    'revenue',
    'expense'
);


--
-- Name: fraud_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.fraud_status AS ENUM (
    'unchecked',
    'passed',
    'failed',
    'manual_review'
);


--
-- Name: kyc_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.kyc_status AS ENUM (
    'pending',
    'approved',
    'rejected',
    'expired'
);


--
-- Name: ledger_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.ledger_type AS ENUM (
    'customer',
    'escrow',
    'treasury',
    'fee',
    'settlement'
);


--
-- Name: swap_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.swap_status AS ENUM (
    'pending',
    'processing',
    'completed',
    'failed',
    'cancelled'
);


--
-- Name: audit_log_integrity_trigger(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.audit_log_integrity_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.integrity_hash = encode(
        sha256(
            COALESCE(NEW.timestamp::text, '') || 
            COALESCE(NEW.entity_type, '') || 
            COALESCE(NEW.entity_id::text, '') || 
            COALESCE(NEW.performed_by_id::text, '') || 
            COALESCE(NEW.action, '')
        ),
        'hex'
    );
    RETURN NEW;
END;
$$;


--
-- Name: fn_update_timestamp(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_update_timestamp() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


--
-- Name: load_participants_from_json(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.load_participants_from_json(json_file_path text) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    json_content TEXT;
    json_data JSONB;
    participant_key TEXT;
    participant_data JSONB;
    inserted_count INT := 0;
    updated_count INT := 0;
BEGIN
    -- Read the JSON file
    BEGIN
        json_content := pg_read_file(json_file_path, 0, 1000000);
    EXCEPTION WHEN OTHERS THEN
        RETURN 'Error reading file: ' || SQLERRM;
    END;
    
    -- Parse JSON
    BEGIN
        json_data := json_content::JSONB;
    EXCEPTION WHEN OTHERS THEN
        RETURN 'Error parsing JSON: ' || SQLERRM;
    END;
    
    -- Check if it has the expected structure
    IF json_data ? 'participants' THEN
        -- Loop through each participant
        FOR participant_key, participant_data IN SELECT * FROM jsonb_each(json_data->'participants')
        LOOP
            INSERT INTO participants (
                name,
                type,
                category,
                provider_code,
                auth_type,
                base_url,
                system_user_id,
                legal_entity_identifier,
                license_number,
                settlement_account,
                settlement_type,
                status,
                capabilities,
                resource_endpoints,
                phone_format,
                security_config,
                message_profile,
                routing_info
            ) VALUES (
                participant_key,
                participant_data->>'type',
                participant_data->>'category',
                participant_data->>'provider_code',
                participant_data->>'auth_type',
                participant_data->>'base_url',
                (participant_data->'identity'->>'system_user_id')::BIGINT,
                participant_data->'identity'->>'legal_entity_identifier',
                participant_data->'identity'->>'license_number',
                participant_data->'routing'->>'settlement_account',
                participant_data->'routing'->>'settlement_type',
                COALESCE(participant_data->>'status', 'ACTIVE'),
                participant_data->'capabilities',
                participant_data->'resource_endpoints',
                participant_data->'phone_format',
                participant_data->'security',
                participant_data->'message_profile',
                participant_data->'routing'
            )
            ON CONFLICT (name) DO UPDATE SET
                type = EXCLUDED.type,
                category = EXCLUDED.category,
                provider_code = EXCLUDED.provider_code,
                auth_type = EXCLUDED.auth_type,
                base_url = EXCLUDED.base_url,
                system_user_id = EXCLUDED.system_user_id,
                legal_entity_identifier = EXCLUDED.legal_entity_identifier,
                license_number = EXCLUDED.license_number,
                settlement_account = EXCLUDED.settlement_account,
                settlement_type = EXCLUDED.settlement_type,
                status = EXCLUDED.status,
                capabilities = EXCLUDED.capabilities,
                resource_endpoints = EXCLUDED.resource_endpoints,
                phone_format = EXCLUDED.phone_format,
                security_config = EXCLUDED.security_config,
                message_profile = EXCLUDED.message_profile,
                routing_info = EXCLUDED.routing_info,
                updated_at = CURRENT_TIMESTAMP;
                
            GET DIAGNOSTICS updated_count = ROW_COUNT;
            IF updated_count > 0 THEN
                inserted_count := inserted_count + 1;
            END IF;
        END LOOP;
        
        RETURN format('Loaded/Updated %s participants successfully.', inserted_count);
    ELSE
        RETURN 'Invalid JSON format: missing "participants" key';
    END IF;
END;
$$;


--
-- Name: update_card_applications_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_card_applications_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_actions (
    action_id bigint NOT NULL,
    admin_id integer NOT NULL,
    action_type character varying(50) NOT NULL,
    entity_type character varying(50),
    entity_id character varying(100),
    ip_address inet,
    user_agent text,
    request_data jsonb,
    response_data jsonb,
    status character varying(20) DEFAULT 'SUCCESS'::character varying,
    error_message text,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: admin_actions_action_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_actions_action_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_actions_action_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_actions_action_id_seq OWNED BY public.admin_actions.action_id;


--
-- Name: admins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admins (
    admin_id bigint NOT NULL,
    username character varying(100) NOT NULL,
    email character varying(150) NOT NULL,
    phone character varying(20),
    password_hash character varying(255) NOT NULL,
    role_id bigint,
    mfa_enabled boolean DEFAULT false,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    full_name character varying(100),
    country_code character varying(2),
    mfa_secret character varying(255),
    last_login_ip inet,
    last_login_at timestamp with time zone,
    created_by integer,
    updated_by integer,
    deleted_at timestamp with time zone
);


--
-- Name: admins_admin_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admins_admin_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admins_admin_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admins_admin_id_seq OWNED BY public.admins.admin_id;


--
-- Name: aml_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.aml_checks (
    check_id bigint NOT NULL,
    user_id bigint,
    check_type character varying(50),
    check_reference character varying(255),
    risk_score numeric(5,2),
    status character varying(20) DEFAULT 'pending'::character varying,
    findings jsonb,
    performed_by character varying(100),
    performed_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    expiry_date timestamp with time zone,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: aml_checks_check_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.aml_checks_check_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: aml_checks_check_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.aml_checks_check_id_seq OWNED BY public.aml_checks.check_id;


--
-- Name: api_message_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_message_logs (
    log_id bigint NOT NULL,
    message_id character varying(100) NOT NULL,
    message_type character varying(50) NOT NULL,
    direction character varying(10) NOT NULL,
    participant_id bigint,
    participant_name character varying(100),
    endpoint character varying(255),
    request_payload jsonb,
    response_payload jsonb,
    http_status_code integer,
    curl_error text,
    success boolean DEFAULT false,
    duration_ms integer,
    retry_count integer DEFAULT 0,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    processed_at timestamp with time zone
);


--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_message_logs_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_message_logs_log_id_seq OWNED BY public.api_message_logs.log_id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    audit_id bigint NOT NULL,
    audit_uuid uuid DEFAULT gen_random_uuid(),
    entity_type character varying(50),
    entity_id bigint,
    action character varying(50),
    category character varying(50),
    severity character varying(20) DEFAULT 'info'::character varying,
    old_value jsonb,
    new_value jsonb,
    changes jsonb,
    performed_by_type character varying(20),
    performed_by_id bigint,
    ip_address inet,
    user_agent text,
    geo_location jsonb,
    request_id character varying(100),
    performed_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    integrity_hash character varying(64),
    "timestamp" timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT audit_logs_performed_by_type_check CHECK (((performed_by_type)::text = ANY (ARRAY[('user'::character varying)::text, ('admin'::character varying)::text, ('system'::character varying)::text]))),
    CONSTRAINT audit_logs_severity_check CHECK (((severity)::text = ANY (ARRAY[('info'::character varying)::text, ('warning'::character varying)::text, ('error'::character varying)::text, ('critical'::character varying)::text])))
);


--
-- Name: audit_logs_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_logs_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_logs_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_logs_audit_id_seq OWNED BY public.audit_logs.audit_id;


--
-- Name: card_applications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.card_applications (
    application_id character varying(50) NOT NULL,
    user_id bigint NOT NULL,
    card_id bigint,
    full_name character varying(200) NOT NULL,
    id_number character varying(50) NOT NULL,
    id_type character varying(30) NOT NULL,
    date_of_birth date NOT NULL,
    phone character varying(20) NOT NULL,
    email character varying(150) NOT NULL,
    address jsonb,
    delivery_address jsonb,
    occupation character varying(100),
    employer character varying(200),
    income_range character varying(50),
    source_of_funds text,
    card_type character varying(20) NOT NULL,
    delivery_method character varying(50),
    branch_location character varying(200),
    status character varying(30) DEFAULT 'PENDING_KYC'::character varying,
    consent jsonb,
    created_at timestamp with time zone DEFAULT now(),
    submitted_at timestamp with time zone DEFAULT now(),
    kyc_submitted_at timestamp with time zone,
    kyc_verified_at timestamp with time zone,
    card_assigned_at timestamp with time zone,
    completed_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now(),
    metadata jsonb DEFAULT '{}'::jsonb
);


--
-- Name: card_authorizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.card_authorizations (
    authorization_id integer NOT NULL,
    swap_id integer NOT NULL,
    swap_reference character varying(64) NOT NULL,
    card_suffix character varying(10) NOT NULL,
    authorized_amount numeric(10,2) NOT NULL,
    remaining_balance numeric(10,2) NOT NULL,
    used_amount numeric(10,2) DEFAULT 0.00,
    hold_reference character varying(64) NOT NULL,
    source_institution character varying(50) NOT NULL,
    fee_amount numeric(10,2) DEFAULT 0.00,
    vat_amount numeric(10,2) DEFAULT 0.00,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    expiry_at timestamp without time zone NOT NULL,
    expired_at timestamp without time zone,
    voided_at timestamp without time zone,
    void_reason text,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    net_amount numeric(10,2),
    vrn character varying(64),
    vrn_signature text,
    vrn_format character varying(20) DEFAULT 'ISO-8583-COMPLIANT'::character varying
);


--
-- Name: card_authorizations_authorization_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.card_authorizations_authorization_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: card_authorizations_authorization_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.card_authorizations_authorization_id_seq OWNED BY public.card_authorizations.authorization_id;


--
-- Name: card_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.card_batches (
    batch_id bigint NOT NULL,
    batch_reference character varying(50) NOT NULL,
    bin_prefix character varying(6) NOT NULL,
    card_scheme character varying(20) NOT NULL,
    card_type character varying(20) DEFAULT 'PHYSICAL'::character varying NOT NULL,
    quantity_produced integer NOT NULL,
    quantity_remaining integer NOT NULL,
    expiry_year integer NOT NULL,
    expiry_month integer NOT NULL,
    status character varying(20) DEFAULT 'PRODUCED'::character varying,
    ordered_at timestamp with time zone,
    produced_at timestamp with time zone,
    received_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    metadata jsonb DEFAULT '{}'::jsonb
);


--
-- Name: card_batches_batch_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.card_batches_batch_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: card_batches_batch_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.card_batches_batch_id_seq OWNED BY public.card_batches.batch_id;


--
-- Name: card_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.card_transactions (
    transaction_id bigint NOT NULL,
    card_id bigint NOT NULL,
    transaction_type character varying(30) NOT NULL,
    amount numeric(20,4) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    auth_code character varying(20),
    auth_status character varying(20) DEFAULT 'APPROVED'::character varying,
    merchant_name character varying(200),
    merchant_id character varying(50),
    merchant_category character varying(10),
    terminal_id character varying(50),
    atm_id character varying(50),
    atm_location character varying(200),
    channel character varying(20),
    settlement_queue_id bigint,
    hold_reference character varying(100),
    reference character varying(100),
    created_at timestamp with time zone DEFAULT now(),
    settled_at timestamp with time zone,
    response_code character varying(2),
    response_message text
);


--
-- Name: card_transactions_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.card_transactions_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: card_transactions_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.card_transactions_transaction_id_seq OWNED BY public.card_transactions.transaction_id;


--
-- Name: cashout_authorizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cashout_authorizations (
    auth_id bigint NOT NULL,
    swap_reference character varying(100) NOT NULL,
    client_phone character varying(20) NOT NULL,
    source_institution character varying(100) NOT NULL,
    source_wallet character varying(100) NOT NULL,
    amount numeric(20,2) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar NOT NULL,
    fee_amount numeric(20,2) DEFAULT 0.00,
    swap_code character varying(50),
    pin_code character varying(10),
    code_expiry timestamp with time zone,
    cashout_point character varying(50) NOT NULL,
    cashout_provider character varying(100),
    status character varying(50) DEFAULT 'PENDING'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    completed_at timestamp with time zone,
    code_used_at timestamp with time zone,
    metadata jsonb DEFAULT '{}'::jsonb
);


--
-- Name: cashout_authorizations_auth_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cashout_authorizations_auth_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cashout_authorizations_auth_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cashout_authorizations_auth_id_seq OWNED BY public.cashout_authorizations.auth_id;


--
-- Name: cashout_retry_tracking; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cashout_retry_tracking (
    id bigint NOT NULL,
    client_identifier character varying(100) NOT NULL,
    original_swap_ref character varying(100) NOT NULL,
    retry_count integer DEFAULT 0,
    free_retry_used boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: cashout_retry_tracking_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cashout_retry_tracking_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cashout_retry_tracking_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cashout_retry_tracking_id_seq OWNED BY public.cashout_retry_tracking.id;


--
-- Name: deposit_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.deposit_transactions (
    deposit_id bigint NOT NULL,
    transaction_reference character varying(100) NOT NULL,
    client_phone character varying(20) NOT NULL,
    source_type character varying(50) NOT NULL,
    source_institution character varying(100) NOT NULL,
    source_account character varying(100) NOT NULL,
    destination_type character varying(50) NOT NULL,
    destination_institution character varying(100),
    destination_account character varying(100),
    amount numeric(20,2) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar NOT NULL,
    fee_amount numeric(20,2) DEFAULT 0.00,
    status character varying(50) DEFAULT 'PENDING'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    completed_at timestamp with time zone,
    metadata jsonb DEFAULT '{}'::jsonb,
    CONSTRAINT deposit_transactions_amount_check CHECK ((amount > (0)::numeric))
);


--
-- Name: deposit_transactions_deposit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.deposit_transactions_deposit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: deposit_transactions_deposit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.deposit_transactions_deposit_id_seq OWNED BY public.deposit_transactions.deposit_id;


--
-- Name: fx_providers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fx_providers (
    fx_provider_id bigint NOT NULL,
    participant_id bigint,
    provider_type character varying(50),
    supports_local_fx boolean DEFAULT true,
    supports_cross_border boolean DEFAULT true,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: fx_providers_fx_provider_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fx_providers_fx_provider_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fx_providers_fx_provider_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fx_providers_fx_provider_id_seq OWNED BY public.fx_providers.fx_provider_id;


--
-- Name: fx_quotes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fx_quotes (
    quote_id bigint NOT NULL,
    quote_uuid uuid NOT NULL,
    swap_reference character varying(100),
    source_participant_id bigint NOT NULL,
    destination_participant_id bigint NOT NULL,
    fx_provider_participant_id bigint,
    source_currency character(3) NOT NULL,
    destination_currency character(3) NOT NULL,
    source_amount numeric(18,2) NOT NULL,
    rate numeric(24,10) NOT NULL,
    destination_amount numeric(18,2) NOT NULL,
    rate_source character varying(50),
    markup_amount numeric(18,2) DEFAULT 0,
    fee_amount numeric(18,2) DEFAULT 0,
    status character varying(20) DEFAULT 'QUOTED'::character varying,
    expires_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: fx_quotes_quote_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fx_quotes_quote_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fx_quotes_quote_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fx_quotes_quote_id_seq OWNED BY public.fx_quotes.quote_id;


--
-- Name: fx_rates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fx_rates (
    rate_id bigint NOT NULL,
    fx_provider_id bigint,
    from_currency character(3),
    to_currency character(3),
    market_rate numeric(24,10),
    provider_rate numeric(24,10),
    your_markup_percent numeric(10,6),
    your_final_rate numeric(24,10),
    valid_from timestamp without time zone,
    valid_until timestamp without time zone,
    liquidity_available numeric(18,2),
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: fx_rates_rate_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fx_rates_rate_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fx_rates_rate_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fx_rates_rate_id_seq OWNED BY public.fx_rates.rate_id;


--
-- Name: hold_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hold_transactions (
    hold_id bigint NOT NULL,
    hold_reference character varying(100) NOT NULL,
    swap_reference character varying(100) NOT NULL,
    participant_id bigint,
    participant_name character varying(100),
    asset_type character varying(50) NOT NULL,
    amount numeric(20,8) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    hold_expiry timestamp with time zone,
    source_details jsonb,
    destination_institution character varying(100),
    destination_participant_id bigint,
    metadata jsonb,
    placed_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    released_at timestamp with time zone,
    debited_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    source_institution character varying(50)
);


--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hold_transactions_hold_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hold_transactions_hold_id_seq OWNED BY public.hold_transactions.hold_id;


--
-- Name: kyc_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kyc_documents (
    kyc_id bigint NOT NULL,
    user_id bigint,
    document_type character varying(50),
    document_number character varying(100),
    status public.kyc_status DEFAULT 'pending'::public.kyc_status,
    document_path character varying(255),
    document_hash character varying(255),
    expiry_date date,
    admin_reviewer_id bigint,
    review_date timestamp with time zone,
    review_notes text,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    submitted_at timestamp with time zone DEFAULT now(),
    reviewed_at timestamp with time zone,
    CONSTRAINT kyc_documents_document_type_check CHECK (((document_type)::text = ANY ((ARRAY['passport'::character varying, 'national_id'::character varying, 'drivers_license'::character varying, 'utility_bill'::character varying, 'bank_statement'::character varying, 'identity'::character varying])::text[])))
);


--
-- Name: kyc_documents_kyc_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.kyc_documents_kyc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kyc_documents_kyc_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.kyc_documents_kyc_id_seq OWNED BY public.kyc_documents.kyc_id;


--
-- Name: ledger_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ledger_accounts (
    account_id bigint NOT NULL,
    account_code character varying(20),
    account_name character varying(100) NOT NULL,
    account_type public.ledger_type NOT NULL,
    balance numeric(20,8) DEFAULT 0,
    participant_id bigint,
    currency_code character(3) DEFAULT 'BWP'::bpchar,
    is_active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ledger_accounts_account_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ledger_accounts_account_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ledger_accounts_account_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ledger_accounts_account_id_seq OWNED BY public.ledger_accounts.account_id;


--
-- Name: ledger_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ledger_entries (
    entry_id bigint NOT NULL,
    transaction_id bigint,
    debit_account_id bigint,
    credit_account_id bigint,
    amount numeric(20,8) NOT NULL,
    currency_code character(3) DEFAULT 'BWP'::bpchar,
    reference character varying(50),
    split_type character varying(50) DEFAULT 'main'::character varying,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ledger_entries_entry_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ledger_entries_entry_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ledger_entries_entry_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ledger_entries_entry_id_seq OWNED BY public.ledger_entries.entry_id;


--
-- Name: message_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.message_cards (
    card_id bigint NOT NULL,
    card_number_hash character varying(255) NOT NULL,
    card_suffix character varying(4) NOT NULL,
    cvv_hash character varying(255) NOT NULL,
    card_category character varying(20) DEFAULT 'PHYSICAL'::character varying NOT NULL,
    card_scheme character varying(20) DEFAULT 'VOUCHMORPH'::character varying NOT NULL,
    batch_id bigint,
    batch_sequence integer,
    lifecycle_status character varying(30) DEFAULT 'IN_BATCH'::character varying NOT NULL,
    financial_status character varying(20) DEFAULT 'UNFUNDED'::character varying NOT NULL,
    expiry_year integer NOT NULL,
    expiry_month integer NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    hold_reference character varying(100),
    swap_reference character varying(100),
    user_id bigint,
    cardholder_name character varying(200),
    cardholder_phone character varying(20),
    initial_amount numeric(20,4) DEFAULT 0,
    remaining_amount numeric(20,4) DEFAULT 0,
    currency character(3) DEFAULT 'BWP'::bpchar,
    activated_at timestamp with time zone,
    last_used_at timestamp with time zone,
    blocked_at timestamp with time zone,
    block_reason text,
    daily_limit numeric(20,4),
    monthly_limit numeric(20,4),
    atm_daily_limit numeric(20,4),
    pin_hash character varying(255),
    batch_assigned_at timestamp with time zone,
    delivery_method character varying(50),
    delivery_address jsonb,
    delivery_status character varying(30)
);


--
-- Name: message_cards_card_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.message_cards_card_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: message_cards_card_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.message_cards_card_id_seq OWNED BY public.message_cards.card_id;


--
-- Name: message_outbox; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.message_outbox (
    message_id character varying(50) NOT NULL,
    channel character varying(20) NOT NULL,
    destination character varying(100) NOT NULL,
    payload jsonb NOT NULL,
    status character varying(20) DEFAULT 'PENDING'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    sent_at timestamp without time zone
);


--
-- Name: net_positions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.net_positions (
    id bigint NOT NULL,
    debtor character varying(100) NOT NULL,
    creditor character varying(100) NOT NULL,
    amount numeric(20,8) DEFAULT 0,
    currency_code character(3) DEFAULT 'BWP'::bpchar,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: net_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.net_positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: net_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.net_positions_id_seq OWNED BY public.net_positions.id;


--
-- Name: otp_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.otp_logs (
    otp_id bigint NOT NULL,
    identifier character varying(255) NOT NULL,
    identifier_type character varying(20),
    code_hash character varying(255) NOT NULL,
    purpose character varying(50),
    expires_at timestamp with time zone NOT NULL,
    used_at timestamp with time zone,
    attempts integer DEFAULT 0,
    ip_address inet,
    user_agent text,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT otp_logs_identifier_type_check CHECK (((identifier_type)::text = ANY (ARRAY[('phone'::character varying)::text, ('email'::character varying)::text, ('user_id'::character varying)::text]))),
    CONSTRAINT otp_logs_purpose_check CHECK (((purpose)::text = ANY (ARRAY[('login'::character varying)::text, ('transaction'::character varying)::text, ('kyc'::character varying)::text, ('password_reset'::character varying)::text, ('phone_verification'::character varying)::text])))
);


--
-- Name: otp_logs_otp_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.otp_logs_otp_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: otp_logs_otp_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.otp_logs_otp_id_seq OWNED BY public.otp_logs.otp_id;


--
-- Name: participant_currencies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participant_currencies (
    id bigint NOT NULL,
    participant_id bigint NOT NULL,
    country_code character(2) NOT NULL,
    currency_code character(3) NOT NULL,
    asset_type character varying(50) NOT NULL,
    can_send boolean DEFAULT true,
    can_receive boolean DEFAULT true,
    can_hold boolean DEFAULT true,
    can_cashout boolean DEFAULT false,
    can_settle boolean DEFAULT true,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: participant_currencies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.participant_currencies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: participant_currencies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.participant_currencies_id_seq OWNED BY public.participant_currencies.id;


--
-- Name: participant_fee_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participant_fee_overrides (
    override_id bigint NOT NULL,
    participant_id bigint,
    transaction_type character varying(20),
    fee_amount numeric(12,2),
    split jsonb,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: participant_fee_overrides_override_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.participant_fee_overrides_override_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: participant_fee_overrides_override_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.participant_fee_overrides_override_id_seq OWNED BY public.participant_fee_overrides.override_id;


--
-- Name: participants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.participants (
    participant_id bigint NOT NULL,
    name character varying(100) NOT NULL,
    type character varying(50),
    category character varying(50),
    provider_code character varying(50),
    auth_type character varying(50),
    base_url text,
    system_user_id bigint,
    legal_entity_identifier character varying(50),
    license_number character varying(50),
    settlement_account character varying(50),
    settlement_type character varying(50),
    status character varying(20),
    capabilities jsonb,
    resource_endpoints jsonb,
    phone_format jsonb,
    security_config jsonb,
    message_profile jsonb,
    routing_info jsonb,
    metadata jsonb
);


--
-- Name: participants_participant_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.participants_participant_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: participants_participant_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.participants_participant_id_seq OWNED BY public.participants.participant_id;


--
-- Name: regulator_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regulator_notifications (
    notification_id bigint NOT NULL,
    notification_type character varying(50) NOT NULL,
    severity character varying(20) NOT NULL,
    title character varying(200) NOT NULL,
    message text NOT NULL,
    related_entity_type character varying(50),
    related_entity_id character varying(100),
    is_read boolean DEFAULT false,
    read_by integer,
    read_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: regulator_notifications_notification_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.regulator_notifications_notification_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: regulator_notifications_notification_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.regulator_notifications_notification_id_seq OWNED BY public.regulator_notifications.notification_id;


--
-- Name: regulator_outbox; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regulator_outbox (
    id integer NOT NULL,
    report_id text NOT NULL,
    payload jsonb NOT NULL,
    integrity_hash text NOT NULL,
    status text NOT NULL,
    attempts integer DEFAULT 0,
    last_attempt timestamp with time zone,
    created_at timestamp with time zone DEFAULT now()
);


--
-- Name: regulator_outbox_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.regulator_outbox_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: regulator_outbox_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.regulator_outbox_id_seq OWNED BY public.regulator_outbox.id;


--
-- Name: regulatory_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regulatory_reports (
    report_id bigint NOT NULL,
    report_type character varying(50) NOT NULL,
    report_date date NOT NULL,
    generated_by integer NOT NULL,
    generated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    report_format character varying(10) NOT NULL,
    report_data jsonb,
    file_path character varying(255),
    integrity_hash character varying(64),
    regulator_acknowledged boolean DEFAULT false,
    regulator_acknowledged_at timestamp with time zone,
    regulator_notes text
);


--
-- Name: regulatory_reports_report_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.regulatory_reports_report_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: regulatory_reports_report_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.regulatory_reports_report_id_seq OWNED BY public.regulatory_reports.report_id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    role_id integer NOT NULL,
    role_name character varying(50) NOT NULL,
    role_level integer DEFAULT 999 NOT NULL,
    description text,
    can_manage_admins boolean DEFAULT false,
    can_view_transactions boolean DEFAULT true,
    can_edit_config boolean DEFAULT false,
    can_broadcast boolean DEFAULT false,
    can_trigger_cron boolean DEFAULT false,
    can_generate_reports boolean DEFAULT true,
    can_export_data boolean DEFAULT false,
    can_view_audit_logs boolean DEFAULT false,
    permissions jsonb DEFAULT '[]'::jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: sandbox_disclosures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sandbox_disclosures (
    id integer NOT NULL,
    user_id bigint,
    consent_version character varying(10),
    has_accepted boolean DEFAULT false,
    disclosure_text text,
    experimental_risk_acknowledged_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: sandbox_disclosures_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sandbox_disclosures_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sandbox_disclosures_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sandbox_disclosures_id_seq OWNED BY public.sandbox_disclosures.id;


--
-- Name: send_to_other_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.send_to_other_transactions (
    send_id bigint NOT NULL,
    transaction_reference character varying(100) NOT NULL,
    sender_phone character varying(20) NOT NULL,
    sender_institution character varying(100) NOT NULL,
    sender_account character varying(100) NOT NULL,
    receiver_phone character varying(20) NOT NULL,
    receiver_institution character varying(100) NOT NULL,
    receiver_account character varying(100) NOT NULL,
    amount numeric(20,2) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar NOT NULL,
    fee_amount numeric(20,2) DEFAULT 0.00,
    status character varying(50) DEFAULT 'PENDING'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    completed_at timestamp with time zone,
    notification_sent boolean DEFAULT false,
    metadata jsonb DEFAULT '{}'::jsonb
);


--
-- Name: send_to_other_transactions_send_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.send_to_other_transactions_send_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: send_to_other_transactions_send_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.send_to_other_transactions_send_id_seq OWNED BY public.send_to_other_transactions.send_id;


--
-- Name: settlement_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.settlement_messages (
    message_id integer NOT NULL,
    transaction_id character varying(64) NOT NULL,
    from_participant character varying(50) NOT NULL,
    to_participant character varying(50) NOT NULL,
    amount numeric(15,2) NOT NULL,
    type character varying(50) NOT NULL,
    status character varying(20) DEFAULT 'PENDING'::character varying,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    processed_at timestamp without time zone
);


--
-- Name: settlement_messages_message_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.settlement_messages_message_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: settlement_messages_message_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.settlement_messages_message_id_seq OWNED BY public.settlement_messages.message_id;


--
-- Name: settlement_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.settlement_queue (
    id bigint NOT NULL,
    debtor character varying(100) NOT NULL,
    creditor character varying(100) NOT NULL,
    amount numeric(20,8) DEFAULT 0,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: settlement_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.settlement_queue_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: settlement_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.settlement_queue_id_seq OWNED BY public.settlement_queue.id;


--
-- Name: settlement_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.settlement_reports (
    settlement_report_id bigint NOT NULL,
    report_date date NOT NULL,
    cycle_id integer,
    total_settlements integer NOT NULL,
    total_amount numeric(18,2) NOT NULL,
    net_positions jsonb,
    participant_breakdown jsonb,
    biss_references jsonb,
    generated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    report_hash character varying(64)
);


--
-- Name: settlement_reports_settlement_report_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.settlement_reports_settlement_report_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: settlement_reports_settlement_report_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.settlement_reports_settlement_report_id_seq OWNED BY public.settlement_reports.settlement_report_id;


--
-- Name: supervisory_heartbeat; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supervisory_heartbeat (
    heartbeat_id integer NOT NULL,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    latency_ms integer DEFAULT 0,
    system_load numeric(5,2) DEFAULT 0
);


--
-- Name: supervisory_heartbeat_heartbeat_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supervisory_heartbeat_heartbeat_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supervisory_heartbeat_heartbeat_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supervisory_heartbeat_heartbeat_id_seq OWNED BY public.supervisory_heartbeat.heartbeat_id;


--
-- Name: swap_fee_collections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.swap_fee_collections (
    fee_id bigint NOT NULL,
    swap_reference character varying(100) NOT NULL,
    fee_type character varying(20) NOT NULL,
    total_amount numeric(20,8) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    source_institution character varying(100) NOT NULL,
    destination_institution character varying(100) NOT NULL,
    split_config jsonb NOT NULL,
    vat_amount numeric(20,8) DEFAULT 0,
    status character varying(20) DEFAULT 'COLLECTED'::character varying,
    collected_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    settled_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: swap_fee_collections_fee_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.swap_fee_collections_fee_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: swap_fee_collections_fee_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.swap_fee_collections_fee_id_seq OWNED BY public.swap_fee_collections.fee_id;


--
-- Name: swap_ledgers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.swap_ledgers (
    ledger_id integer NOT NULL,
    swap_reference character varying(64) NOT NULL,
    from_institution character varying(50) NOT NULL,
    to_institution character varying(50) NOT NULL,
    amount numeric(15,2) NOT NULL,
    currency_code character varying(3) NOT NULL,
    swap_fee numeric(15,2) NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.swap_ledgers_ledger_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.swap_ledgers_ledger_id_seq OWNED BY public.swap_ledgers.ledger_id;


--
-- Name: swap_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.swap_requests (
    swap_id integer NOT NULL,
    swap_uuid character varying(100) NOT NULL,
    from_currency character varying(3) NOT NULL,
    to_currency character varying(3) NOT NULL,
    amount numeric(15,2) NOT NULL,
    source_details jsonb NOT NULL,
    destination_details jsonb NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    metadata jsonb DEFAULT '{}'::jsonb,
    retry_count integer DEFAULT 0,
    original_swap_ref character varying(100),
    source_country character(2),
    destination_country character(2),
    fee_breakdown jsonb DEFAULT '{}'::jsonb,
    trade_metadata jsonb DEFAULT '{}'::jsonb,
    forex_rate numeric(20,10) DEFAULT 1.0 NOT NULL,
    rate_locked_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    expected_to_amount numeric(20,8) GENERATED ALWAYS AS ((amount * forex_rate)) STORED
);


--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.swap_requests_swap_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.swap_requests_swap_id_seq OWNED BY public.swap_requests.swap_id;


--
-- Name: swap_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.swap_transactions (
    swap_transaction_id bigint NOT NULL,
    swap_id bigint,
    transaction_id bigint,
    from_account_details jsonb NOT NULL,
    to_account_details jsonb NOT NULL,
    amount numeric(20,8) NOT NULL,
    ledger_entry_id bigint,
    settlement_batch_id bigint,
    status public.swap_status DEFAULT 'pending'::public.swap_status,
    error_message text,
    retry_count integer DEFAULT 0,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: swap_transactions_swap_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.swap_transactions_swap_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: swap_transactions_swap_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.swap_transactions_swap_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: swap_transactions_swap_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.swap_transactions_swap_transaction_id_seq OWNED BY public.swap_transactions.swap_transaction_id;


--
-- Name: swap_vouchers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.swap_vouchers (
    voucher_id integer NOT NULL,
    swap_id integer,
    code_hash character varying(255) NOT NULL,
    code_suffix character varying(4) NOT NULL,
    amount numeric(15,2) NOT NULL,
    expiry_at timestamp without time zone NOT NULL,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    claimant_phone character varying(20),
    is_cardless_redemption boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    attempts integer DEFAULT 0
);


--
-- Name: swap_vouchers_voucher_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.swap_vouchers_voucher_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: swap_vouchers_voucher_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.swap_vouchers_voucher_id_seq OWNED BY public.swap_vouchers.voucher_id;


--
-- Name: transaction_fees; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transaction_fees (
    fee_id bigint NOT NULL,
    transaction_type character varying(20) NOT NULL,
    amount numeric(12,2) NOT NULL,
    currency character varying(5) DEFAULT 'BWP'::character varying,
    split_config jsonb,
    taxable boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: transaction_fees_fee_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.transaction_fees_fee_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: transaction_fees_fee_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.transaction_fees_fee_id_seq OWNED BY public.transaction_fees.fee_id;


--
-- Name: transaction_log_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.transaction_log_view AS
 SELECT COALESCE(ht.swap_reference, aml.message_id) AS transaction_id,
    ht.hold_reference,
    ht.status AS hold_status,
    ht.placed_at AS hold_placed_at,
    ht.debited_at,
    ht.amount AS hold_amount,
    ht.asset_type,
    p.name AS participant_name,
    p.provider_code,
    p.type AS participant_type,
    aml.message_type,
    aml.success AS api_success,
    aml.http_status_code,
    aml.created_at AS api_called_at,
    aml.endpoint,
    aml.direction
   FROM ((public.hold_transactions ht
     FULL JOIN public.api_message_logs aml ON (((ht.swap_reference)::text = (aml.message_id)::text)))
     LEFT JOIN public.participants p ON ((COALESCE(ht.participant_id, aml.participant_id) = p.participant_id)));


--
-- Name: transaction_splits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transaction_splits (
    split_id bigint NOT NULL,
    transaction_id bigint,
    split_type character varying(50),
    amount numeric(20,8) NOT NULL,
    currency_code character(3) DEFAULT 'BWP'::bpchar,
    credited_account bigint,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: transaction_splits_split_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.transaction_splits_split_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: transaction_splits_split_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.transaction_splits_split_id_seq OWNED BY public.transaction_splits.split_id;


--
-- Name: transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transactions (
    transaction_id bigint NOT NULL,
    transaction_type character varying(50) NOT NULL,
    amount numeric(20,8) DEFAULT 0,
    fee numeric(20,8) DEFAULT 0,
    sat_purchased numeric(20,8) DEFAULT 0,
    currency_code character(3) DEFAULT 'BWP'::bpchar,
    status character varying(20) DEFAULT 'PDNG'::character varying,
    sca_required boolean DEFAULT false,
    sca_verified_at timestamp with time zone,
    reference_uuid uuid DEFAULT gen_random_uuid(),
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    origin_participant_id bigint,
    destination_participant_id bigint,
    origin_name character varying(100),
    destination_name character varying(100)
);


--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.transactions_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.transactions_transaction_id_seq OWNED BY public.transactions.transaction_id;


--
-- Name: user_hooks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_hooks (
    id bigint NOT NULL,
    user_identifier character varying(100) NOT NULL,
    hook_name character varying(50) NOT NULL,
    asset_type character varying(30) NOT NULL,
    institution character varying(100) NOT NULL,
    asset_reference character varying(100) NOT NULL,
    credentials jsonb DEFAULT '{}'::jsonb,
    is_active boolean DEFAULT true,
    priority integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: user_hooks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_hooks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_hooks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_hooks_id_seq OWNED BY public.user_hooks.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    user_id bigint NOT NULL,
    username character varying(100) NOT NULL,
    email character varying(150) NOT NULL,
    phone character varying(20) NOT NULL,
    password_hash character varying(255) NOT NULL,
    role_id bigint DEFAULT 1,
    verified boolean DEFAULT false,
    kyc_verified boolean DEFAULT false,
    aml_score numeric(5,2) DEFAULT 0,
    mfa_enabled boolean DEFAULT false,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: users_user_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_user_id_seq OWNED BY public.users.user_id;


--
-- Name: ussd_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ussd_sessions (
    id bigint NOT NULL,
    session_id character varying(100) NOT NULL,
    session_key character varying(100) NOT NULL,
    session_value text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ussd_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ussd_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ussd_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ussd_sessions_id_seq OWNED BY public.ussd_sessions.id;


--
-- Name: vouchmorph_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vouchmorph_notifications (
    id integer NOT NULL,
    swap_number character varying(255) NOT NULL,
    swap_pin character varying(255) NOT NULL,
    amount numeric(20,4) NOT NULL,
    user_phone character varying(20),
    destination_bank_id integer NOT NULL,
    status character varying(50) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT now(),
    acknowledged_at timestamp without time zone
);


--
-- Name: vouchmorph_notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vouchmorph_notifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vouchmorph_notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vouchmorph_notifications_id_seq OWNED BY public.vouchmorph_notifications.id;


--
-- Name: admin_actions action_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_actions ALTER COLUMN action_id SET DEFAULT nextval('public.admin_actions_action_id_seq'::regclass);


--
-- Name: admins admin_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins ALTER COLUMN admin_id SET DEFAULT nextval('public.admins_admin_id_seq'::regclass);


--
-- Name: aml_checks check_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.aml_checks ALTER COLUMN check_id SET DEFAULT nextval('public.aml_checks_check_id_seq'::regclass);


--
-- Name: api_message_logs log_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_message_logs ALTER COLUMN log_id SET DEFAULT nextval('public.api_message_logs_log_id_seq'::regclass);


--
-- Name: audit_logs audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN audit_id SET DEFAULT nextval('public.audit_logs_audit_id_seq'::regclass);


--
-- Name: card_authorizations authorization_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_authorizations ALTER COLUMN authorization_id SET DEFAULT nextval('public.card_authorizations_authorization_id_seq'::regclass);


--
-- Name: card_batches batch_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_batches ALTER COLUMN batch_id SET DEFAULT nextval('public.card_batches_batch_id_seq'::regclass);


--
-- Name: card_transactions transaction_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions ALTER COLUMN transaction_id SET DEFAULT nextval('public.card_transactions_transaction_id_seq'::regclass);


--
-- Name: cashout_authorizations auth_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cashout_authorizations ALTER COLUMN auth_id SET DEFAULT nextval('public.cashout_authorizations_auth_id_seq'::regclass);


--
-- Name: cashout_retry_tracking id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cashout_retry_tracking ALTER COLUMN id SET DEFAULT nextval('public.cashout_retry_tracking_id_seq'::regclass);


--
-- Name: deposit_transactions deposit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deposit_transactions ALTER COLUMN deposit_id SET DEFAULT nextval('public.deposit_transactions_deposit_id_seq'::regclass);


--
-- Name: fx_providers fx_provider_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_providers ALTER COLUMN fx_provider_id SET DEFAULT nextval('public.fx_providers_fx_provider_id_seq'::regclass);


--
-- Name: fx_quotes quote_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_quotes ALTER COLUMN quote_id SET DEFAULT nextval('public.fx_quotes_quote_id_seq'::regclass);


--
-- Name: fx_rates rate_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_rates ALTER COLUMN rate_id SET DEFAULT nextval('public.fx_rates_rate_id_seq'::regclass);


--
-- Name: hold_transactions hold_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hold_transactions ALTER COLUMN hold_id SET DEFAULT nextval('public.hold_transactions_hold_id_seq'::regclass);


--
-- Name: kyc_documents kyc_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kyc_documents ALTER COLUMN kyc_id SET DEFAULT nextval('public.kyc_documents_kyc_id_seq'::regclass);


--
-- Name: ledger_accounts account_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts ALTER COLUMN account_id SET DEFAULT nextval('public.ledger_accounts_account_id_seq'::regclass);


--
-- Name: ledger_entries entry_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_entries ALTER COLUMN entry_id SET DEFAULT nextval('public.ledger_entries_entry_id_seq'::regclass);


--
-- Name: message_cards card_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_cards ALTER COLUMN card_id SET DEFAULT nextval('public.message_cards_card_id_seq'::regclass);


--
-- Name: net_positions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.net_positions ALTER COLUMN id SET DEFAULT nextval('public.net_positions_id_seq'::regclass);


--
-- Name: otp_logs otp_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_logs ALTER COLUMN otp_id SET DEFAULT nextval('public.otp_logs_otp_id_seq'::regclass);


--
-- Name: participant_currencies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_currencies ALTER COLUMN id SET DEFAULT nextval('public.participant_currencies_id_seq'::regclass);


--
-- Name: participant_fee_overrides override_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_fee_overrides ALTER COLUMN override_id SET DEFAULT nextval('public.participant_fee_overrides_override_id_seq'::regclass);


--
-- Name: participants participant_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participants ALTER COLUMN participant_id SET DEFAULT nextval('public.participants_participant_id_seq'::regclass);


--
-- Name: regulator_notifications notification_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulator_notifications ALTER COLUMN notification_id SET DEFAULT nextval('public.regulator_notifications_notification_id_seq'::regclass);


--
-- Name: regulator_outbox id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulator_outbox ALTER COLUMN id SET DEFAULT nextval('public.regulator_outbox_id_seq'::regclass);


--
-- Name: regulatory_reports report_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_reports ALTER COLUMN report_id SET DEFAULT nextval('public.regulatory_reports_report_id_seq'::regclass);


--
-- Name: sandbox_disclosures id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sandbox_disclosures ALTER COLUMN id SET DEFAULT nextval('public.sandbox_disclosures_id_seq'::regclass);


--
-- Name: send_to_other_transactions send_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.send_to_other_transactions ALTER COLUMN send_id SET DEFAULT nextval('public.send_to_other_transactions_send_id_seq'::regclass);


--
-- Name: settlement_messages message_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_messages ALTER COLUMN message_id SET DEFAULT nextval('public.settlement_messages_message_id_seq'::regclass);


--
-- Name: settlement_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_queue ALTER COLUMN id SET DEFAULT nextval('public.settlement_queue_id_seq'::regclass);


--
-- Name: settlement_reports settlement_report_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_reports ALTER COLUMN settlement_report_id SET DEFAULT nextval('public.settlement_reports_settlement_report_id_seq'::regclass);


--
-- Name: supervisory_heartbeat heartbeat_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisory_heartbeat ALTER COLUMN heartbeat_id SET DEFAULT nextval('public.supervisory_heartbeat_heartbeat_id_seq'::regclass);


--
-- Name: swap_fee_collections fee_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_fee_collections ALTER COLUMN fee_id SET DEFAULT nextval('public.swap_fee_collections_fee_id_seq'::regclass);


--
-- Name: swap_ledgers ledger_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_ledgers ALTER COLUMN ledger_id SET DEFAULT nextval('public.swap_ledgers_ledger_id_seq'::regclass);


--
-- Name: swap_requests swap_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_requests ALTER COLUMN swap_id SET DEFAULT nextval('public.swap_requests_swap_id_seq'::regclass);


--
-- Name: swap_transactions swap_transaction_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_transactions ALTER COLUMN swap_transaction_id SET DEFAULT nextval('public.swap_transactions_swap_transaction_id_seq'::regclass);


--
-- Name: swap_vouchers voucher_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_vouchers ALTER COLUMN voucher_id SET DEFAULT nextval('public.swap_vouchers_voucher_id_seq'::regclass);


--
-- Name: transaction_fees fee_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_fees ALTER COLUMN fee_id SET DEFAULT nextval('public.transaction_fees_fee_id_seq'::regclass);


--
-- Name: transaction_splits split_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_splits ALTER COLUMN split_id SET DEFAULT nextval('public.transaction_splits_split_id_seq'::regclass);


--
-- Name: transactions transaction_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions ALTER COLUMN transaction_id SET DEFAULT nextval('public.transactions_transaction_id_seq'::regclass);


--
-- Name: user_hooks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_hooks ALTER COLUMN id SET DEFAULT nextval('public.user_hooks_id_seq'::regclass);


--
-- Name: users user_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN user_id SET DEFAULT nextval('public.users_user_id_seq'::regclass);


--
-- Name: ussd_sessions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ussd_sessions ALTER COLUMN id SET DEFAULT nextval('public.ussd_sessions_id_seq'::regclass);


--
-- Name: vouchmorph_notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vouchmorph_notifications ALTER COLUMN id SET DEFAULT nextval('public.vouchmorph_notifications_id_seq'::regclass);


--
-- Name: admin_actions admin_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_actions
    ADD CONSTRAINT admin_actions_pkey PRIMARY KEY (action_id);


--
-- Name: admins admins_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_email_key UNIQUE (email);


--
-- Name: admins admins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_pkey PRIMARY KEY (admin_id);


--
-- Name: admins admins_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_username_key UNIQUE (username);


--
-- Name: aml_checks aml_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.aml_checks
    ADD CONSTRAINT aml_checks_pkey PRIMARY KEY (check_id);


--
-- Name: api_message_logs api_message_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_message_logs
    ADD CONSTRAINT api_message_logs_pkey PRIMARY KEY (log_id);


--
-- Name: audit_logs audit_logs_audit_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_audit_uuid_key UNIQUE (audit_uuid);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (audit_id);


--
-- Name: card_applications card_applications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_applications
    ADD CONSTRAINT card_applications_pkey PRIMARY KEY (application_id);


--
-- Name: card_authorizations card_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_authorizations
    ADD CONSTRAINT card_authorizations_pkey PRIMARY KEY (authorization_id);


--
-- Name: card_authorizations card_authorizations_vrn_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_authorizations
    ADD CONSTRAINT card_authorizations_vrn_key UNIQUE (vrn);


--
-- Name: card_batches card_batches_batch_reference_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_batches
    ADD CONSTRAINT card_batches_batch_reference_key UNIQUE (batch_reference);


--
-- Name: card_batches card_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_batches
    ADD CONSTRAINT card_batches_pkey PRIMARY KEY (batch_id);


--
-- Name: card_transactions card_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_pkey PRIMARY KEY (transaction_id);


--
-- Name: cashout_authorizations cashout_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cashout_authorizations
    ADD CONSTRAINT cashout_authorizations_pkey PRIMARY KEY (auth_id);


--
-- Name: cashout_authorizations cashout_authorizations_swap_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cashout_authorizations
    ADD CONSTRAINT cashout_authorizations_swap_code_key UNIQUE (swap_code);


--
-- Name: cashout_authorizations cashout_authorizations_swap_reference_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cashout_authorizations
    ADD CONSTRAINT cashout_authorizations_swap_reference_key UNIQUE (swap_reference);


--
-- Name: cashout_retry_tracking cashout_retry_tracking_client_identifier_original_swap_ref_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cashout_retry_tracking
    ADD CONSTRAINT cashout_retry_tracking_client_identifier_original_swap_ref_key UNIQUE (client_identifier, original_swap_ref);


--
-- Name: cashout_retry_tracking cashout_retry_tracking_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cashout_retry_tracking
    ADD CONSTRAINT cashout_retry_tracking_pkey PRIMARY KEY (id);


--
-- Name: deposit_transactions deposit_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deposit_transactions
    ADD CONSTRAINT deposit_transactions_pkey PRIMARY KEY (deposit_id);


--
-- Name: deposit_transactions deposit_transactions_transaction_reference_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.deposit_transactions
    ADD CONSTRAINT deposit_transactions_transaction_reference_key UNIQUE (transaction_reference);


--
-- Name: fx_providers fx_providers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_providers
    ADD CONSTRAINT fx_providers_pkey PRIMARY KEY (fx_provider_id);


--
-- Name: fx_quotes fx_quotes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_quotes
    ADD CONSTRAINT fx_quotes_pkey PRIMARY KEY (quote_id);


--
-- Name: fx_quotes fx_quotes_quote_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_quotes
    ADD CONSTRAINT fx_quotes_quote_uuid_key UNIQUE (quote_uuid);


--
-- Name: fx_rates fx_rates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fx_rates
    ADD CONSTRAINT fx_rates_pkey PRIMARY KEY (rate_id);


--
-- Name: hold_transactions hold_transactions_hold_reference_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_hold_reference_key UNIQUE (hold_reference);


--
-- Name: hold_transactions hold_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_pkey PRIMARY KEY (hold_id);


--
-- Name: kyc_documents kyc_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT kyc_documents_pkey PRIMARY KEY (kyc_id);


--
-- Name: ledger_accounts ledger_accounts_account_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_account_code_key UNIQUE (account_code);


--
-- Name: ledger_accounts ledger_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_pkey PRIMARY KEY (account_id);


--
-- Name: ledger_entries ledger_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_pkey PRIMARY KEY (entry_id);


--
-- Name: message_cards message_cards_card_number_hash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_cards
    ADD CONSTRAINT message_cards_card_number_hash_key UNIQUE (card_number_hash);


--
-- Name: message_cards message_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_cards
    ADD CONSTRAINT message_cards_pkey PRIMARY KEY (card_id);


--
-- Name: message_outbox message_outbox_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_outbox
    ADD CONSTRAINT message_outbox_pkey PRIMARY KEY (message_id);


--
-- Name: net_positions net_positions_debtor_creditor_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.net_positions
    ADD CONSTRAINT net_positions_debtor_creditor_key UNIQUE (debtor, creditor);


--
-- Name: net_positions net_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.net_positions
    ADD CONSTRAINT net_positions_pkey PRIMARY KEY (id);


--
-- Name: otp_logs otp_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.otp_logs
    ADD CONSTRAINT otp_logs_pkey PRIMARY KEY (otp_id);


--
-- Name: participant_currencies participant_currencies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_currencies
    ADD CONSTRAINT participant_currencies_pkey PRIMARY KEY (id);


--
-- Name: participant_fee_overrides participant_fee_overrides_participant_id_transaction_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_fee_overrides
    ADD CONSTRAINT participant_fee_overrides_participant_id_transaction_type_key UNIQUE (participant_id, transaction_type);


--
-- Name: participant_fee_overrides participant_fee_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_fee_overrides
    ADD CONSTRAINT participant_fee_overrides_pkey PRIMARY KEY (override_id);


--
-- Name: participants participants_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participants
    ADD CONSTRAINT participants_name_key UNIQUE (name);


--
-- Name: participants participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participants
    ADD CONSTRAINT participants_pkey PRIMARY KEY (participant_id);


--
-- Name: regulator_notifications regulator_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulator_notifications
    ADD CONSTRAINT regulator_notifications_pkey PRIMARY KEY (notification_id);


--
-- Name: regulator_outbox regulator_outbox_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulator_outbox
    ADD CONSTRAINT regulator_outbox_pkey PRIMARY KEY (id);


--
-- Name: regulator_outbox regulator_outbox_report_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulator_outbox
    ADD CONSTRAINT regulator_outbox_report_id_key UNIQUE (report_id);


--
-- Name: regulatory_reports regulatory_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_reports
    ADD CONSTRAINT regulatory_reports_pkey PRIMARY KEY (report_id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (role_id);


--
-- Name: roles roles_role_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_role_name_key UNIQUE (role_name);


--
-- Name: sandbox_disclosures sandbox_disclosures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sandbox_disclosures
    ADD CONSTRAINT sandbox_disclosures_pkey PRIMARY KEY (id);


--
-- Name: send_to_other_transactions send_to_other_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.send_to_other_transactions
    ADD CONSTRAINT send_to_other_transactions_pkey PRIMARY KEY (send_id);


--
-- Name: send_to_other_transactions send_to_other_transactions_transaction_reference_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.send_to_other_transactions
    ADD CONSTRAINT send_to_other_transactions_transaction_reference_key UNIQUE (transaction_reference);


--
-- Name: settlement_messages settlement_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_messages
    ADD CONSTRAINT settlement_messages_pkey PRIMARY KEY (message_id);


--
-- Name: settlement_queue settlement_queue_debtor_creditor_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_queue
    ADD CONSTRAINT settlement_queue_debtor_creditor_key UNIQUE (debtor, creditor);


--
-- Name: settlement_queue settlement_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_queue
    ADD CONSTRAINT settlement_queue_pkey PRIMARY KEY (id);


--
-- Name: settlement_reports settlement_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_reports
    ADD CONSTRAINT settlement_reports_pkey PRIMARY KEY (settlement_report_id);


--
-- Name: settlement_reports settlement_reports_report_hash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settlement_reports
    ADD CONSTRAINT settlement_reports_report_hash_key UNIQUE (report_hash);


--
-- Name: supervisory_heartbeat supervisory_heartbeat_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supervisory_heartbeat
    ADD CONSTRAINT supervisory_heartbeat_pkey PRIMARY KEY (heartbeat_id);


--
-- Name: swap_fee_collections swap_fee_collections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_fee_collections
    ADD CONSTRAINT swap_fee_collections_pkey PRIMARY KEY (fee_id);


--
-- Name: swap_ledgers swap_ledgers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_ledgers
    ADD CONSTRAINT swap_ledgers_pkey PRIMARY KEY (ledger_id);


--
-- Name: swap_requests swap_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_requests
    ADD CONSTRAINT swap_requests_pkey PRIMARY KEY (swap_id);


--
-- Name: swap_requests swap_requests_swap_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_requests
    ADD CONSTRAINT swap_requests_swap_uuid_key UNIQUE (swap_uuid);


--
-- Name: swap_transactions swap_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_pkey PRIMARY KEY (swap_transaction_id);


--
-- Name: swap_vouchers swap_vouchers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_vouchers
    ADD CONSTRAINT swap_vouchers_pkey PRIMARY KEY (voucher_id);


--
-- Name: transaction_fees transaction_fees_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_fees
    ADD CONSTRAINT transaction_fees_pkey PRIMARY KEY (fee_id);


--
-- Name: transaction_fees transaction_fees_transaction_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_fees
    ADD CONSTRAINT transaction_fees_transaction_type_key UNIQUE (transaction_type);


--
-- Name: transaction_splits transaction_splits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_splits
    ADD CONSTRAINT transaction_splits_pkey PRIMARY KEY (split_id);


--
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (transaction_id);


--
-- Name: transactions transactions_reference_uuid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_reference_uuid_key UNIQUE (reference_uuid);


--
-- Name: user_hooks user_hooks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_hooks
    ADD CONSTRAINT user_hooks_pkey PRIMARY KEY (id);


--
-- Name: user_hooks user_hooks_user_identifier_hook_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_hooks
    ADD CONSTRAINT user_hooks_user_identifier_hook_name_key UNIQUE (user_identifier, hook_name);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_phone_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_phone_key UNIQUE (phone);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: ussd_sessions ussd_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ussd_sessions
    ADD CONSTRAINT ussd_sessions_pkey PRIMARY KEY (id);


--
-- Name: vouchmorph_notifications vouchmorph_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vouchmorph_notifications
    ADD CONSTRAINT vouchmorph_notifications_pkey PRIMARY KEY (id);


--
-- Name: idx_admin_actions_admin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_actions_admin ON public.admin_actions USING btree (admin_id);


--
-- Name: idx_admin_actions_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_actions_created ON public.admin_actions USING btree (created_at);


--
-- Name: idx_admin_actions_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_actions_type ON public.admin_actions USING btree (action_type);


--
-- Name: idx_api_logs_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_logs_created ON public.api_message_logs USING btree (created_at);


--
-- Name: idx_api_logs_message_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_logs_message_id ON public.api_message_logs USING btree (message_id);


--
-- Name: idx_api_logs_participant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_logs_participant ON public.api_message_logs USING btree (participant_id);


--
-- Name: idx_api_logs_success; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_logs_success ON public.api_message_logs USING btree (success) WHERE (success = false);


--
-- Name: idx_api_logs_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_logs_type ON public.api_message_logs USING btree (message_type);


--
-- Name: idx_card_applications_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_applications_created ON public.card_applications USING btree (created_at);


--
-- Name: idx_card_applications_email; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_applications_email ON public.card_applications USING btree (email);


--
-- Name: idx_card_applications_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_applications_phone ON public.card_applications USING btree (phone);


--
-- Name: idx_card_applications_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_applications_status ON public.card_applications USING btree (status);


--
-- Name: idx_card_applications_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_applications_user ON public.card_applications USING btree (user_id);


--
-- Name: idx_card_auth_card_suffix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_auth_card_suffix ON public.card_authorizations USING btree (card_suffix);


--
-- Name: idx_card_auth_expiry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_auth_expiry ON public.card_authorizations USING btree (expiry_at);


--
-- Name: idx_card_auth_hold_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_auth_hold_ref ON public.card_authorizations USING btree (hold_reference);


--
-- Name: idx_card_auth_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_auth_status ON public.card_authorizations USING btree (status);


--
-- Name: idx_card_auth_swap_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_auth_swap_ref ON public.card_authorizations USING btree (swap_reference);


--
-- Name: idx_card_transactions_auth; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_transactions_auth ON public.card_transactions USING btree (auth_code);


--
-- Name: idx_card_transactions_card; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_transactions_card ON public.card_transactions USING btree (card_id);


--
-- Name: idx_card_transactions_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_card_transactions_date ON public.card_transactions USING btree (created_at);


--
-- Name: idx_cashout_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cashout_code ON public.cashout_authorizations USING btree (swap_code) WHERE ((status)::text = 'ACTIVE'::text);


--
-- Name: idx_cashout_expiry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cashout_expiry ON public.cashout_authorizations USING btree (code_expiry) WHERE ((status)::text = 'ACTIVE'::text);


--
-- Name: idx_cashout_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cashout_phone ON public.cashout_authorizations USING btree (client_phone, created_at);


--
-- Name: idx_cashout_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cashout_status ON public.cashout_authorizations USING btree (status);


--
-- Name: idx_deposit_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_deposit_created ON public.deposit_transactions USING btree (created_at);


--
-- Name: idx_deposit_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_deposit_phone ON public.deposit_transactions USING btree (client_phone, created_at);


--
-- Name: idx_deposit_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_deposit_status ON public.deposit_transactions USING btree (status);


--
-- Name: idx_fee_collections_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_collections_date ON public.swap_fee_collections USING btree (collected_at);


--
-- Name: idx_fee_collections_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_collections_status ON public.swap_fee_collections USING btree (status);


--
-- Name: idx_fee_collections_swap_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_collections_swap_ref ON public.swap_fee_collections USING btree (swap_reference);


--
-- Name: idx_holds_expiry_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_holds_expiry_status ON public.hold_transactions USING btree (hold_expiry, status) WHERE ((status)::text = 'ACTIVE'::text);


--
-- Name: idx_holds_reference; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_holds_reference ON public.hold_transactions USING btree (hold_reference);


--
-- Name: idx_holds_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_holds_status ON public.hold_transactions USING btree (status);


--
-- Name: idx_holds_swap; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_holds_swap ON public.hold_transactions USING btree (swap_reference);


--
-- Name: idx_message_cards_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_cards_batch ON public.message_cards USING btree (batch_id);


--
-- Name: idx_message_cards_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_cards_hash ON public.message_cards USING btree (card_number_hash);


--
-- Name: idx_message_cards_hold; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_cards_hold ON public.message_cards USING btree (hold_reference);


--
-- Name: idx_message_cards_lifecycle; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_cards_lifecycle ON public.message_cards USING btree (lifecycle_status);


--
-- Name: idx_message_cards_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_cards_user ON public.message_cards USING btree (user_id);


--
-- Name: idx_message_outbox_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_outbox_created ON public.message_outbox USING btree (created_at);


--
-- Name: idx_message_outbox_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_message_outbox_status ON public.message_outbox USING btree (status);


--
-- Name: idx_net_positions_creditor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_net_positions_creditor ON public.net_positions USING btree (creditor);


--
-- Name: idx_net_positions_debtor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_net_positions_debtor ON public.net_positions USING btree (debtor);


--
-- Name: idx_net_positions_debtor_creditor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_net_positions_debtor_creditor ON public.net_positions USING btree (debtor, creditor);


--
-- Name: idx_regulator_notifications_read; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_regulator_notifications_read ON public.regulator_notifications USING btree (is_read);


--
-- Name: idx_regulator_notifications_severity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_regulator_notifications_severity ON public.regulator_notifications USING btree (severity);


--
-- Name: idx_regulatory_reports_type_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_regulatory_reports_type_date ON public.regulatory_reports USING btree (report_type, report_date);


--
-- Name: idx_send_receiver; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_send_receiver ON public.send_to_other_transactions USING btree (receiver_phone, created_at);


--
-- Name: idx_send_sender; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_send_sender ON public.send_to_other_transactions USING btree (sender_phone, created_at);


--
-- Name: idx_send_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_send_status ON public.send_to_other_transactions USING btree (status);


--
-- Name: idx_settlement_messages_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_settlement_messages_status ON public.settlement_messages USING btree (status);


--
-- Name: idx_settlement_queue_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_settlement_queue_created ON public.settlement_queue USING btree (created_at);


--
-- Name: idx_settlement_reports_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_settlement_reports_date ON public.settlement_reports USING btree (report_date);


--
-- Name: idx_swap_requests_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_swap_requests_status ON public.swap_requests USING btree (status);


--
-- Name: idx_swap_requests_uuid; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_swap_requests_uuid ON public.swap_requests USING btree (swap_uuid);


--
-- Name: idx_swap_vouchers_code_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_swap_vouchers_code_hash ON public.swap_vouchers USING btree (code_hash) WHERE ((status)::text = 'ACTIVE'::text);


--
-- Name: idx_swap_vouchers_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_swap_vouchers_phone ON public.swap_vouchers USING btree (claimant_phone);


--
-- Name: idx_swap_vouchers_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_swap_vouchers_status ON public.swap_vouchers USING btree (status);


--
-- Name: uniq_session_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_session_key ON public.ussd_sessions USING btree (session_id, session_key);


--
-- Name: admins trg_admins_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_admins_updated BEFORE UPDATE ON public.admins FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: aml_checks trg_aml_checks_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_aml_checks_updated BEFORE UPDATE ON public.aml_checks FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: audit_logs trg_audit_logs_integrity; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_logs_integrity BEFORE INSERT ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION public.audit_log_integrity_trigger();


--
-- Name: audit_logs trg_audit_logs_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_audit_logs_updated BEFORE UPDATE ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: card_applications trg_card_applications_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_card_applications_updated BEFORE UPDATE ON public.card_applications FOR EACH ROW EXECUTE FUNCTION public.update_card_applications_updated_at();


--
-- Name: kyc_documents trg_kyc_documents_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_kyc_documents_updated BEFORE UPDATE ON public.kyc_documents FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: ledger_accounts trg_ledger_accounts_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ledger_accounts_updated BEFORE UPDATE ON public.ledger_accounts FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: ledger_entries trg_ledger_entries_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ledger_entries_updated BEFORE UPDATE ON public.ledger_entries FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: otp_logs trg_otp_logs_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_otp_logs_updated BEFORE UPDATE ON public.otp_logs FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: regulator_outbox trg_regulator_outbox_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_regulator_outbox_updated BEFORE UPDATE ON public.regulator_outbox FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: sandbox_disclosures trg_sandbox_disclosures_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_sandbox_disclosures_updated BEFORE UPDATE ON public.sandbox_disclosures FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: supervisory_heartbeat trg_supervisory_heartbeat_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_supervisory_heartbeat_updated BEFORE UPDATE ON public.supervisory_heartbeat FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: swap_transactions trg_swap_transactions_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_swap_transactions_updated BEFORE UPDATE ON public.swap_transactions FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: transaction_splits trg_transaction_splits_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_transaction_splits_updated BEFORE UPDATE ON public.transaction_splits FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: transactions trg_transactions_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_transactions_updated BEFORE UPDATE ON public.transactions FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: users trg_users_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_users_updated BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: admins update_admins_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_admins_updated_at BEFORE UPDATE ON public.admins FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: hold_transactions update_hold_transactions_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_hold_transactions_updated_at BEFORE UPDATE ON public.hold_transactions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: kyc_documents update_kyc_documents_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_kyc_documents_updated_at BEFORE UPDATE ON public.kyc_documents FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: ledger_accounts update_ledger_accounts_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_ledger_accounts_updated_at BEFORE UPDATE ON public.ledger_accounts FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: ledger_entries update_ledger_entries_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_ledger_entries_updated_at BEFORE UPDATE ON public.ledger_entries FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: net_positions update_net_positions_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_net_positions_updated_at BEFORE UPDATE ON public.net_positions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: participants update_participants_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_participants_updated_at BEFORE UPDATE ON public.participants FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: settlement_queue update_settlement_queue_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_settlement_queue_updated_at BEFORE UPDATE ON public.settlement_queue FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: swap_fee_collections update_swap_fee_collections_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_swap_fee_collections_updated_at BEFORE UPDATE ON public.swap_fee_collections FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: swap_transactions update_swap_transactions_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_swap_transactions_updated_at BEFORE UPDATE ON public.swap_transactions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: transactions update_transactions_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_transactions_updated_at BEFORE UPDATE ON public.transactions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: users update_users_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: admin_actions admin_actions_admin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_actions
    ADD CONSTRAINT admin_actions_admin_id_fkey FOREIGN KEY (admin_id) REFERENCES public.admins(admin_id);


--
-- Name: admins admins_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.admins(admin_id);


--
-- Name: admins admins_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(role_id);


--
-- Name: admins admins_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.admins(admin_id);


--
-- Name: aml_checks aml_checks_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.aml_checks
    ADD CONSTRAINT aml_checks_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: api_message_logs api_message_logs_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_message_logs
    ADD CONSTRAINT api_message_logs_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id);


--
-- Name: card_applications card_applications_card_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_applications
    ADD CONSTRAINT card_applications_card_id_fkey FOREIGN KEY (card_id) REFERENCES public.message_cards(card_id);


--
-- Name: card_applications card_applications_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_applications
    ADD CONSTRAINT card_applications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: card_transactions card_transactions_card_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_card_id_fkey FOREIGN KEY (card_id) REFERENCES public.message_cards(card_id);


--
-- Name: card_transactions card_transactions_settlement_queue_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.card_transactions
    ADD CONSTRAINT card_transactions_settlement_queue_id_fkey FOREIGN KEY (settlement_queue_id) REFERENCES public.settlement_queue(id);


--
-- Name: hold_transactions hold_transactions_destination_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_destination_participant_id_fkey FOREIGN KEY (destination_participant_id) REFERENCES public.participants(participant_id);


--
-- Name: hold_transactions hold_transactions_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id);


--
-- Name: kyc_documents kyc_documents_admin_reviewer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT kyc_documents_admin_reviewer_id_fkey FOREIGN KEY (admin_reviewer_id) REFERENCES public.admins(admin_id);


--
-- Name: kyc_documents kyc_documents_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT kyc_documents_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: ledger_entries ledger_entries_credit_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_credit_account_id_fkey FOREIGN KEY (credit_account_id) REFERENCES public.ledger_accounts(account_id);


--
-- Name: ledger_entries ledger_entries_debit_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_debit_account_id_fkey FOREIGN KEY (debit_account_id) REFERENCES public.ledger_accounts(account_id);


--
-- Name: ledger_entries ledger_entries_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transaction_id);


--
-- Name: message_cards message_cards_batch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_cards
    ADD CONSTRAINT message_cards_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.card_batches(batch_id);


--
-- Name: message_cards message_cards_hold_reference_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_cards
    ADD CONSTRAINT message_cards_hold_reference_fkey FOREIGN KEY (hold_reference) REFERENCES public.hold_transactions(hold_reference);


--
-- Name: message_cards message_cards_swap_reference_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_cards
    ADD CONSTRAINT message_cards_swap_reference_fkey FOREIGN KEY (swap_reference) REFERENCES public.swap_requests(swap_uuid);


--
-- Name: message_cards message_cards_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.message_cards
    ADD CONSTRAINT message_cards_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: participant_fee_overrides participant_fee_overrides_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.participant_fee_overrides
    ADD CONSTRAINT participant_fee_overrides_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id) ON DELETE CASCADE;


--
-- Name: regulator_notifications regulator_notifications_read_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulator_notifications
    ADD CONSTRAINT regulator_notifications_read_by_fkey FOREIGN KEY (read_by) REFERENCES public.admins(admin_id);


--
-- Name: regulatory_reports regulatory_reports_generated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regulatory_reports
    ADD CONSTRAINT regulatory_reports_generated_by_fkey FOREIGN KEY (generated_by) REFERENCES public.admins(admin_id);


--
-- Name: sandbox_disclosures sandbox_disclosures_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sandbox_disclosures
    ADD CONSTRAINT sandbox_disclosures_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: swap_transactions swap_transactions_ledger_entry_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_ledger_entry_id_fkey FOREIGN KEY (ledger_entry_id) REFERENCES public.ledger_entries(entry_id);


--
-- Name: swap_transactions swap_transactions_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transaction_id);


--
-- Name: swap_vouchers swap_vouchers_swap_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.swap_vouchers
    ADD CONSTRAINT swap_vouchers_swap_id_fkey FOREIGN KEY (swap_id) REFERENCES public.swap_requests(swap_id);


--
-- Name: transaction_splits transaction_splits_credited_account_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_splits
    ADD CONSTRAINT transaction_splits_credited_account_fkey FOREIGN KEY (credited_account) REFERENCES public.ledger_accounts(account_id);


--
-- Name: transaction_splits transaction_splits_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_splits
    ADD CONSTRAINT transaction_splits_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transaction_id);


--
-- PostgreSQL database dump complete
--

\unrestrict e385Tco0wzyxGxejaBEUCTmnWALpjQpoSgKj51Dpd1SJyJmqoWN09NIK43YuFwe
