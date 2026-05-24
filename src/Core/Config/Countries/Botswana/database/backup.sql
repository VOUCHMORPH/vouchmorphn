--
-- PostgreSQL database dump
--

\restrict 7Xi944aMjZXaAsZPSSefi8eK4Kur0wCOkcDId6HGfGyDNI4bmIFIvmubJ8Segba

-- Dumped from database version 16.11 (Ubuntu 16.11-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.11 (Ubuntu 16.11-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: postgres
--

-- *not* creating schema, since initdb creates it


ALTER SCHEMA public OWNER TO postgres;

--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: postgres
--

COMMENT ON SCHEMA public IS '';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: account_type; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.account_type AS ENUM (
    'asset',
    'liability',
    'equity',
    'revenue',
    'expense'
);


ALTER TYPE public.account_type OWNER TO postgres;

--
-- Name: fraud_status; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.fraud_status AS ENUM (
    'unchecked',
    'passed',
    'failed',
    'manual_review'
);


ALTER TYPE public.fraud_status OWNER TO postgres;

--
-- Name: kyc_status; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.kyc_status AS ENUM (
    'pending',
    'approved',
    'rejected',
    'expired'
);


ALTER TYPE public.kyc_status OWNER TO postgres;

--
-- Name: ledger_type; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.ledger_type AS ENUM (
    'customer',
    'escrow',
    'treasury',
    'fee',
    'settlement'
);


ALTER TYPE public.ledger_type OWNER TO postgres;

--
-- Name: swap_status; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.swap_status AS ENUM (
    'pending',
    'processing',
    'completed',
    'failed',
    'cancelled'
);


ALTER TYPE public.swap_status OWNER TO postgres;

--
-- Name: fn_update_timestamp(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_update_timestamp() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.fn_update_timestamp() OWNER TO postgres;

--
-- Name: load_participants_from_json(text); Type: FUNCTION; Schema: public; Owner: postgres
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


ALTER FUNCTION public.load_participants_from_json(json_file_path text) OWNER TO postgres;

--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: public; Owner: vouchmorphn_user
--

CREATE FUNCTION public.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.update_updated_at_column() OWNER TO vouchmorphn_user;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admins; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.admins OWNER TO vouchmorphn_user;

--
-- Name: admins_admin_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.admins_admin_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admins_admin_id_seq OWNER TO vouchmorphn_user;

--
-- Name: admins_admin_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.admins_admin_id_seq OWNED BY public.admins.admin_id;


--
-- Name: aml_checks; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.aml_checks OWNER TO vouchmorphn_user;

--
-- Name: aml_checks_check_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.aml_checks_check_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.aml_checks_check_id_seq OWNER TO vouchmorphn_user;

--
-- Name: aml_checks_check_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.aml_checks_check_id_seq OWNED BY public.aml_checks.check_id;


--
-- Name: api_message_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.api_message_logs OWNER TO postgres;

--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.api_message_logs_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.api_message_logs_log_id_seq OWNER TO postgres;

--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.api_message_logs_log_id_seq OWNED BY public.api_message_logs.log_id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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
    CONSTRAINT audit_logs_performed_by_type_check CHECK (((performed_by_type)::text = ANY ((ARRAY['user'::character varying, 'admin'::character varying, 'system'::character varying])::text[]))),
    CONSTRAINT audit_logs_severity_check CHECK (((severity)::text = ANY ((ARRAY['info'::character varying, 'warning'::character varying, 'error'::character varying, 'critical'::character varying])::text[])))
);


ALTER TABLE public.audit_logs OWNER TO vouchmorphn_user;

--
-- Name: audit_logs_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.audit_logs_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.audit_logs_audit_id_seq OWNER TO vouchmorphn_user;

--
-- Name: audit_logs_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.audit_logs_audit_id_seq OWNED BY public.audit_logs.audit_id;


--
-- Name: cashout_authorizations; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.cashout_authorizations OWNER TO postgres;

--
-- Name: cashout_authorizations_auth_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.cashout_authorizations_auth_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.cashout_authorizations_auth_id_seq OWNER TO postgres;

--
-- Name: cashout_authorizations_auth_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.cashout_authorizations_auth_id_seq OWNED BY public.cashout_authorizations.auth_id;


--
-- Name: deposit_transactions; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.deposit_transactions OWNER TO postgres;

--
-- Name: deposit_transactions_deposit_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.deposit_transactions_deposit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.deposit_transactions_deposit_id_seq OWNER TO postgres;

--
-- Name: deposit_transactions_deposit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.deposit_transactions_deposit_id_seq OWNED BY public.deposit_transactions.deposit_id;


--
-- Name: hold_transactions; Type: TABLE; Schema: public; Owner: postgres
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
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.hold_transactions OWNER TO postgres;

--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hold_transactions_hold_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hold_transactions_hold_id_seq OWNER TO postgres;

--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hold_transactions_hold_id_seq OWNED BY public.hold_transactions.hold_id;


--
-- Name: kyc_documents; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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
    CONSTRAINT kyc_documents_document_type_check CHECK (((document_type)::text = ANY ((ARRAY['passport'::character varying, 'national_id'::character varying, 'drivers_license'::character varying, 'utility_bill'::character varying, 'bank_statement'::character varying])::text[])))
);


ALTER TABLE public.kyc_documents OWNER TO vouchmorphn_user;

--
-- Name: kyc_documents_kyc_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.kyc_documents_kyc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.kyc_documents_kyc_id_seq OWNER TO vouchmorphn_user;

--
-- Name: kyc_documents_kyc_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.kyc_documents_kyc_id_seq OWNED BY public.kyc_documents.kyc_id;


--
-- Name: ledger_accounts; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.ledger_accounts OWNER TO vouchmorphn_user;

--
-- Name: ledger_accounts_account_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.ledger_accounts_account_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ledger_accounts_account_id_seq OWNER TO vouchmorphn_user;

--
-- Name: ledger_accounts_account_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.ledger_accounts_account_id_seq OWNED BY public.ledger_accounts.account_id;


--
-- Name: ledger_entries; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.ledger_entries OWNER TO vouchmorphn_user;

--
-- Name: ledger_entries_entry_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.ledger_entries_entry_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ledger_entries_entry_id_seq OWNER TO vouchmorphn_user;

--
-- Name: ledger_entries_entry_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.ledger_entries_entry_id_seq OWNED BY public.ledger_entries.entry_id;


--
-- Name: message_outbox; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.message_outbox OWNER TO postgres;

--
-- Name: net_positions; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.net_positions OWNER TO vouchmorphn_user;

--
-- Name: net_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.net_positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.net_positions_id_seq OWNER TO vouchmorphn_user;

--
-- Name: net_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.net_positions_id_seq OWNED BY public.net_positions.id;


--
-- Name: otp_logs; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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
    CONSTRAINT otp_logs_identifier_type_check CHECK (((identifier_type)::text = ANY ((ARRAY['phone'::character varying, 'email'::character varying, 'user_id'::character varying])::text[]))),
    CONSTRAINT otp_logs_purpose_check CHECK (((purpose)::text = ANY ((ARRAY['login'::character varying, 'transaction'::character varying, 'kyc'::character varying, 'password_reset'::character varying, 'phone_verification'::character varying])::text[])))
);


ALTER TABLE public.otp_logs OWNER TO vouchmorphn_user;

--
-- Name: otp_logs_otp_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.otp_logs_otp_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.otp_logs_otp_id_seq OWNER TO vouchmorphn_user;

--
-- Name: otp_logs_otp_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.otp_logs_otp_id_seq OWNED BY public.otp_logs.otp_id;


--
-- Name: participant_fee_overrides; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.participant_fee_overrides OWNER TO vouchmorphn_user;

--
-- Name: participant_fee_overrides_override_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.participant_fee_overrides_override_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.participant_fee_overrides_override_id_seq OWNER TO vouchmorphn_user;

--
-- Name: participant_fee_overrides_override_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.participant_fee_overrides_override_id_seq OWNED BY public.participant_fee_overrides.override_id;


--
-- Name: participants; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.participants OWNER TO vouchmorphn_user;

--
-- Name: participants_participant_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.participants_participant_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.participants_participant_id_seq OWNER TO vouchmorphn_user;

--
-- Name: participants_participant_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.participants_participant_id_seq OWNED BY public.participants.participant_id;


--
-- Name: regulator_outbox; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.regulator_outbox OWNER TO vouchmorphn_user;

--
-- Name: regulator_outbox_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.regulator_outbox_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.regulator_outbox_id_seq OWNER TO vouchmorphn_user;

--
-- Name: regulator_outbox_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.regulator_outbox_id_seq OWNED BY public.regulator_outbox.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: vouchmorphn_user
--

CREATE TABLE public.roles (
    role_id bigint NOT NULL,
    role_name character varying(50) NOT NULL,
    description text,
    permissions jsonb DEFAULT '[]'::jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT role_name_check CHECK (((role_name)::text = ANY ((ARRAY['user'::character varying, 'admin'::character varying, 'compliance'::character varying, 'auditor'::character varying, 'super_admin'::character varying])::text[])))
);


ALTER TABLE public.roles OWNER TO vouchmorphn_user;

--
-- Name: roles_role_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.roles_role_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.roles_role_id_seq OWNER TO vouchmorphn_user;

--
-- Name: roles_role_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.roles_role_id_seq OWNED BY public.roles.role_id;


--
-- Name: sandbox_disclosures; Type: TABLE; Schema: public; Owner: vouchmorphn_user
--

CREATE TABLE public.sandbox_disclosures (
    id integer NOT NULL,
    user_id bigint,
    consent_version character varying(10),
    has_accepted boolean DEFAULT false,
    disclosure_text text,
    experimental_risk_acknowledged_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.sandbox_disclosures OWNER TO vouchmorphn_user;

--
-- Name: sandbox_disclosures_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.sandbox_disclosures_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sandbox_disclosures_id_seq OWNER TO vouchmorphn_user;

--
-- Name: sandbox_disclosures_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.sandbox_disclosures_id_seq OWNED BY public.sandbox_disclosures.id;


--
-- Name: send_to_other_transactions; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.send_to_other_transactions OWNER TO postgres;

--
-- Name: send_to_other_transactions_send_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.send_to_other_transactions_send_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.send_to_other_transactions_send_id_seq OWNER TO postgres;

--
-- Name: send_to_other_transactions_send_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.send_to_other_transactions_send_id_seq OWNED BY public.send_to_other_transactions.send_id;


--
-- Name: settlement_messages; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.settlement_messages OWNER TO postgres;

--
-- Name: settlement_messages_message_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.settlement_messages_message_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settlement_messages_message_id_seq OWNER TO postgres;

--
-- Name: settlement_messages_message_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.settlement_messages_message_id_seq OWNED BY public.settlement_messages.message_id;


--
-- Name: settlement_queue; Type: TABLE; Schema: public; Owner: vouchmorphn_user
--

CREATE TABLE public.settlement_queue (
    id bigint NOT NULL,
    debtor character varying(100) NOT NULL,
    creditor character varying(100) NOT NULL,
    amount numeric(20,8) DEFAULT 0,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


ALTER TABLE public.settlement_queue OWNER TO vouchmorphn_user;

--
-- Name: settlement_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.settlement_queue_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settlement_queue_id_seq OWNER TO vouchmorphn_user;

--
-- Name: settlement_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.settlement_queue_id_seq OWNED BY public.settlement_queue.id;


--
-- Name: supervisory_heartbeat; Type: TABLE; Schema: public; Owner: vouchmorphn_user
--

CREATE TABLE public.supervisory_heartbeat (
    heartbeat_id integer NOT NULL,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    latency_ms integer DEFAULT 0,
    system_load numeric(5,2) DEFAULT 0
);


ALTER TABLE public.supervisory_heartbeat OWNER TO vouchmorphn_user;

--
-- Name: supervisory_heartbeat_heartbeat_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.supervisory_heartbeat_heartbeat_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.supervisory_heartbeat_heartbeat_id_seq OWNER TO vouchmorphn_user;

--
-- Name: supervisory_heartbeat_heartbeat_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.supervisory_heartbeat_heartbeat_id_seq OWNED BY public.supervisory_heartbeat.heartbeat_id;


--
-- Name: swap_fee_collections; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.swap_fee_collections OWNER TO postgres;

--
-- Name: swap_fee_collections_fee_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_fee_collections_fee_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_fee_collections_fee_id_seq OWNER TO postgres;

--
-- Name: swap_fee_collections_fee_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_fee_collections_fee_id_seq OWNED BY public.swap_fee_collections.fee_id;


--
-- Name: swap_ledgers; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.swap_ledgers OWNER TO postgres;

--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_ledgers_ledger_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_ledgers_ledger_id_seq OWNER TO postgres;

--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_ledgers_ledger_id_seq OWNED BY public.swap_ledgers.ledger_id;


--
-- Name: swap_requests; Type: TABLE; Schema: public; Owner: postgres
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
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_requests OWNER TO postgres;

--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_requests_swap_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_requests_swap_id_seq OWNER TO postgres;

--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_requests_swap_id_seq OWNED BY public.swap_requests.swap_id;


--
-- Name: swap_transactions; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.swap_transactions OWNER TO vouchmorphn_user;

--
-- Name: swap_transactions_swap_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.swap_transactions_swap_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_transactions_swap_transaction_id_seq OWNER TO vouchmorphn_user;

--
-- Name: swap_transactions_swap_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.swap_transactions_swap_transaction_id_seq OWNED BY public.swap_transactions.swap_transaction_id;


--
-- Name: swap_vouchers; Type: TABLE; Schema: public; Owner: postgres
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
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_vouchers OWNER TO postgres;

--
-- Name: swap_vouchers_voucher_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_vouchers_voucher_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_vouchers_voucher_id_seq OWNER TO postgres;

--
-- Name: swap_vouchers_voucher_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_vouchers_voucher_id_seq OWNED BY public.swap_vouchers.voucher_id;


--
-- Name: transaction_fees; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.transaction_fees OWNER TO vouchmorphn_user;

--
-- Name: transaction_fees_fee_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.transaction_fees_fee_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transaction_fees_fee_id_seq OWNER TO vouchmorphn_user;

--
-- Name: transaction_fees_fee_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.transaction_fees_fee_id_seq OWNED BY public.transaction_fees.fee_id;


--
-- Name: transaction_log_view; Type: VIEW; Schema: public; Owner: postgres
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


ALTER VIEW public.transaction_log_view OWNER TO postgres;

--
-- Name: transaction_splits; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.transaction_splits OWNER TO vouchmorphn_user;

--
-- Name: transaction_splits_split_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.transaction_splits_split_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transaction_splits_split_id_seq OWNER TO vouchmorphn_user;

--
-- Name: transaction_splits_split_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.transaction_splits_split_id_seq OWNED BY public.transaction_splits.split_id;


--
-- Name: transactions; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.transactions OWNER TO vouchmorphn_user;

--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.transactions_transaction_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transactions_transaction_id_seq OWNER TO vouchmorphn_user;

--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.transactions_transaction_id_seq OWNED BY public.transactions.transaction_id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: vouchmorphn_user
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


ALTER TABLE public.users OWNER TO vouchmorphn_user;

--
-- Name: users_user_id_seq; Type: SEQUENCE; Schema: public; Owner: vouchmorphn_user
--

CREATE SEQUENCE public.users_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_user_id_seq OWNER TO vouchmorphn_user;

--
-- Name: users_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: vouchmorphn_user
--

ALTER SEQUENCE public.users_user_id_seq OWNED BY public.users.user_id;


--
-- Name: vouchmorph_notifications; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.vouchmorph_notifications OWNER TO postgres;

--
-- Name: vouchmorph_notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vouchmorph_notifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vouchmorph_notifications_id_seq OWNER TO postgres;

--
-- Name: vouchmorph_notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vouchmorph_notifications_id_seq OWNED BY public.vouchmorph_notifications.id;


--
-- Name: admins admin_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.admins ALTER COLUMN admin_id SET DEFAULT nextval('public.admins_admin_id_seq'::regclass);


--
-- Name: aml_checks check_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.aml_checks ALTER COLUMN check_id SET DEFAULT nextval('public.aml_checks_check_id_seq'::regclass);


--
-- Name: api_message_logs log_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_message_logs ALTER COLUMN log_id SET DEFAULT nextval('public.api_message_logs_log_id_seq'::regclass);


--
-- Name: audit_logs audit_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN audit_id SET DEFAULT nextval('public.audit_logs_audit_id_seq'::regclass);


--
-- Name: cashout_authorizations auth_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cashout_authorizations ALTER COLUMN auth_id SET DEFAULT nextval('public.cashout_authorizations_auth_id_seq'::regclass);


--
-- Name: deposit_transactions deposit_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deposit_transactions ALTER COLUMN deposit_id SET DEFAULT nextval('public.deposit_transactions_deposit_id_seq'::regclass);


--
-- Name: hold_transactions hold_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions ALTER COLUMN hold_id SET DEFAULT nextval('public.hold_transactions_hold_id_seq'::regclass);


--
-- Name: kyc_documents kyc_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.kyc_documents ALTER COLUMN kyc_id SET DEFAULT nextval('public.kyc_documents_kyc_id_seq'::regclass);


--
-- Name: ledger_accounts account_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_accounts ALTER COLUMN account_id SET DEFAULT nextval('public.ledger_accounts_account_id_seq'::regclass);


--
-- Name: ledger_entries entry_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_entries ALTER COLUMN entry_id SET DEFAULT nextval('public.ledger_entries_entry_id_seq'::regclass);


--
-- Name: net_positions id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.net_positions ALTER COLUMN id SET DEFAULT nextval('public.net_positions_id_seq'::regclass);


--
-- Name: otp_logs otp_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.otp_logs ALTER COLUMN otp_id SET DEFAULT nextval('public.otp_logs_otp_id_seq'::regclass);


--
-- Name: participant_fee_overrides override_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.participant_fee_overrides ALTER COLUMN override_id SET DEFAULT nextval('public.participant_fee_overrides_override_id_seq'::regclass);


--
-- Name: participants participant_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.participants ALTER COLUMN participant_id SET DEFAULT nextval('public.participants_participant_id_seq'::regclass);


--
-- Name: regulator_outbox id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.regulator_outbox ALTER COLUMN id SET DEFAULT nextval('public.regulator_outbox_id_seq'::regclass);


--
-- Name: roles role_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.roles ALTER COLUMN role_id SET DEFAULT nextval('public.roles_role_id_seq'::regclass);


--
-- Name: sandbox_disclosures id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.sandbox_disclosures ALTER COLUMN id SET DEFAULT nextval('public.sandbox_disclosures_id_seq'::regclass);


--
-- Name: send_to_other_transactions send_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.send_to_other_transactions ALTER COLUMN send_id SET DEFAULT nextval('public.send_to_other_transactions_send_id_seq'::regclass);


--
-- Name: settlement_messages message_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_messages ALTER COLUMN message_id SET DEFAULT nextval('public.settlement_messages_message_id_seq'::regclass);


--
-- Name: settlement_queue id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.settlement_queue ALTER COLUMN id SET DEFAULT nextval('public.settlement_queue_id_seq'::regclass);


--
-- Name: supervisory_heartbeat heartbeat_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.supervisory_heartbeat ALTER COLUMN heartbeat_id SET DEFAULT nextval('public.supervisory_heartbeat_heartbeat_id_seq'::regclass);


--
-- Name: swap_fee_collections fee_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_fee_collections ALTER COLUMN fee_id SET DEFAULT nextval('public.swap_fee_collections_fee_id_seq'::regclass);


--
-- Name: swap_ledgers ledger_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers ALTER COLUMN ledger_id SET DEFAULT nextval('public.swap_ledgers_ledger_id_seq'::regclass);


--
-- Name: swap_requests swap_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_requests ALTER COLUMN swap_id SET DEFAULT nextval('public.swap_requests_swap_id_seq'::regclass);


--
-- Name: swap_transactions swap_transaction_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.swap_transactions ALTER COLUMN swap_transaction_id SET DEFAULT nextval('public.swap_transactions_swap_transaction_id_seq'::regclass);


--
-- Name: swap_vouchers voucher_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_vouchers ALTER COLUMN voucher_id SET DEFAULT nextval('public.swap_vouchers_voucher_id_seq'::regclass);


--
-- Name: transaction_fees fee_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transaction_fees ALTER COLUMN fee_id SET DEFAULT nextval('public.transaction_fees_fee_id_seq'::regclass);


--
-- Name: transaction_splits split_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transaction_splits ALTER COLUMN split_id SET DEFAULT nextval('public.transaction_splits_split_id_seq'::regclass);


--
-- Name: transactions transaction_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transactions ALTER COLUMN transaction_id SET DEFAULT nextval('public.transactions_transaction_id_seq'::regclass);


--
-- Name: users user_id; Type: DEFAULT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.users ALTER COLUMN user_id SET DEFAULT nextval('public.users_user_id_seq'::regclass);


--
-- Name: vouchmorph_notifications id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vouchmorph_notifications ALTER COLUMN id SET DEFAULT nextval('public.vouchmorph_notifications_id_seq'::regclass);


--
-- Data for Name: admins; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.admins (admin_id, username, email, phone, password_hash, role_id, mfa_enabled, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: aml_checks; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.aml_checks (check_id, user_id, check_type, check_reference, risk_score, status, findings, performed_by, performed_at, expiry_date, created_at) FROM stdin;
\.


--
-- Data for Name: api_message_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.api_message_logs (log_id, message_id, message_type, direction, participant_id, participant_name, endpoint, request_payload, response_payload, http_status_code, curl_error, success, duration_ms, retry_count, created_at, processed_at) FROM stdin;
55	3145a5cc507709e58a4767f6c7499984	verify_asset	outgoing	\N	ZURUBANK	/api/verify-asset	{"amount": 1500, "reference": "3145a5cc507709e58a4767f6c7499984", "asset_type": "VOUCHER", "institution": "ZURUBANK", "voucher_pin": "516060", "claimant_phone": "+26770000000", "voucher_number": "326059135833"}	{"balance": 1500, "success": true, "asset_id": 23, "metadata": {"status": "active", "currency": "BWP", "created_at": "2026-02-26 17:49:20.977818", "voucher_id": 23, "sat_purchased": false, "external_reference": null, "source_institution": null}, "verified": true, "asset_type": "VOUCHER", "expiry_date": "2026-03-27 16:54:17", "holder_name": "Motho", "voucher_number": "326059135833", "recipient_phone": "+26770000000", "available_balance": 1500}	200		t	\N	0	2026-03-02 08:16:32.599867+02	\N
56	3145a5cc507709e58a4767f6c7499984	hold_placement	outgoing	\N	ZURUBANK	/api/authorize	{"action": "PLACE_HOLD", "amount": 1500, "expiry": "2026-03-03 07:16:32", "reference": "3145a5cc507709e58a4767f6c7499984", "asset_type": "VOUCHER", "destination": "SACCUSSALIS", "hold_reason": "PENDING_TRANSACTION", "foreign_bank": "SACCUSSALIS", "claimant_phone": "+26770000000", "voucher_number": "326059135833", "beneficiary_bank": "SACCUSSALIS", "destination_institution": "SACCUSSALIS"}	{"amount": "1500.00", "status": "SUCCESS", "message": "Voucher is now on hold", "asset_id": 23, "asset_type": "VOUCHER", "hold_placed": true, "hold_reference": "3145a5cc507709e58a4767f6c7499984", "voucher_number": "326059135833"}	200		t	\N	0	2026-03-02 08:16:32.599867+02	\N
57	3145a5cc507709e58a4767f6c7499984	generate_token	outgoing	\N	SACCUSSALIS	/api/generate-atm-code	{"reference": "3145a5cc507709e58a4767f6c7499984", "beneficiary_phone": "+26770000000"}	{"pin": "924230", "amount": 1500, "sat_id": 9, "atm_pin": "924230", "success": true, "currency": "BWP", "metadata": {"user_id": 2, "reference": "3145a5cc507709e58a4767f6c7499984", "wallet_id": 1, "source_institution": "ZURUBANK"}, "expires_at": "2026-03-03 07:16:32", "sat_number": "876393263575", "issuer_bank": "SACCUS", "acquirer_bank": "ZURUBANK", "instrument_id": 11, "token_generated": true, "token_reference": "876393263575"}	200		t	\N	0	2026-03-02 08:16:32.599867+02	\N
58	3145a5cc507709e58a4767f6c7499984	debit_funds	outgoing	\N	ZURUBANK	/api/debit	{"hold_reference": "3145a5cc507709e58a4767f6c7499984"}	{"data": {"debited": true, "hold_reference": "3145a5cc507709e58a4767f6c7499984", "transaction_reference": "3145a5cc507709e58a4767f6c7499984"}, "amount": 1500, "status": "SUCCESS", "debited": true, "message": "Voucher released and interbank settlement recorded", "success": true, "journal_id": 9, "hold_reference": "3145a5cc507709e58a4767f6c7499984", "voucher_number": "326059135833", "counterparty_bank": "SACCUSSALIS", "settlement_reference": "3145a5cc507709e58a4767f6c7499984"}	200		t	\N	0	2026-03-02 08:16:32.599867+02	\N
66	897ba2207e18cc592d82fffa28be41ff	verify_asset	outgoing	\N	ZURUBANK	/api/verify-asset	{"amount": 1500, "reference": "897ba2207e18cc592d82fffa28be41ff", "asset_type": "VOUCHER", "institution": "ZURUBANK", "voucher_pin": "868597", "claimant_phone": "+26770000000", "voucher_number": "586361180565"}	{"balance": 1500, "success": true, "asset_id": 26, "metadata": {"status": "active", "currency": "BWP", "created_at": "2026-02-26 21:56:07.24955", "voucher_id": 26, "sat_purchased": false, "external_reference": null, "source_institution": null}, "verified": true, "asset_type": "VOUCHER", "expiry_date": null, "holder_name": "Motho", "voucher_number": "586361180565", "recipient_phone": "+26770000000", "available_balance": 1500}	200		t	\N	0	2026-03-02 09:02:54.485004+02	\N
67	897ba2207e18cc592d82fffa28be41ff	hold_placement	outgoing	\N	ZURUBANK	/api/authorize	{"action": "PLACE_HOLD", "amount": 1500, "expiry": "2026-03-03 08:02:54", "reference": "897ba2207e18cc592d82fffa28be41ff", "asset_type": "VOUCHER", "destination": "SACCUSSALIS", "hold_reason": "PENDING_TRANSACTION", "foreign_bank": "SACCUSSALIS", "claimant_phone": "+26770000000", "voucher_number": "586361180565", "beneficiary_bank": "SACCUSSALIS", "destination_institution": "SACCUSSALIS"}	{"amount": "1500.00", "status": "SUCCESS", "message": "Voucher is now on hold", "asset_id": 26, "asset_type": "VOUCHER", "hold_placed": true, "hold_reference": "897ba2207e18cc592d82fffa28be41ff", "voucher_number": "586361180565"}	200		t	\N	0	2026-03-02 09:02:54.485004+02	\N
68	897ba2207e18cc592d82fffa28be41ff	generate_token	outgoing	\N	SACCUSSALIS	/api/generate-atm-code	{"reference": "897ba2207e18cc592d82fffa28be41ff", "beneficiary_phone": "+26770000000"}	{"pin": "469943", "amount": 1500, "sat_id": 11, "atm_pin": "469943", "success": true, "currency": "BWP", "metadata": {"user_id": 2, "reference": "897ba2207e18cc592d82fffa28be41ff", "wallet_id": 1, "source_institution": "ZURUBANK"}, "expires_at": "2026-03-03 08:02:54", "sat_number": "381942164429", "issuer_bank": "SACCUS", "acquirer_bank": "ZURUBANK", "instrument_id": 13, "token_generated": true, "token_reference": "381942164429"}	200		t	\N	0	2026-03-02 09:02:54.485004+02	\N
69	897ba2207e18cc592d82fffa28be41ff	debit_funds	outgoing	\N	ZURUBANK	/api/debit	{"hold_reference": "897ba2207e18cc592d82fffa28be41ff"}	{"data": {"debited": true, "hold_reference": "897ba2207e18cc592d82fffa28be41ff", "transaction_reference": "897ba2207e18cc592d82fffa28be41ff"}, "amount": 1500, "status": "SUCCESS", "debited": true, "message": "Voucher released and interbank settlement recorded", "success": true, "journal_id": 10, "hold_reference": "897ba2207e18cc592d82fffa28be41ff", "voucher_number": "586361180565", "counterparty_bank": "SACCUSSALIS", "settlement_reference": "897ba2207e18cc592d82fffa28be41ff"}	200		t	\N	0	2026-03-02 09:02:54.485004+02	\N
70	8c4dc51e09119dd0ee509a31c4eb6070	verify_asset	outgoing	\N	SACCUSBWXX	/api/verify-asset	{"amount": 100, "reference": "8c4dc51e09119dd0ee509a31c4eb6070", "asset_type": "E-WALLET", "institution": "SACCUSSALIS", "ewallet_phone": "+26770000000"}	{"asset_id": 4, "metadata": {"phone": "+26770000000", "currency": "BWP", "wallet_type": "default"}, "verified": true, "expiry_date": null, "holder_name": "Saccus Salis Customer", "available_balance": 998750}	200		t	\N	0	2026-03-02 09:04:22.822985+02	\N
71	8c4dc51e09119dd0ee509a31c4eb6070	hold_placement	outgoing	\N	SACCUSBWXX	/api/authorize	{"action": "PLACE_HOLD", "amount": 100, "expiry": "2026-03-03 08:04:22", "reference": "8c4dc51e09119dd0ee509a31c4eb6070", "asset_type": "E-WALLET", "destination": "ZURUBANK", "hold_reason": "PENDING_TRANSACTION", "foreign_bank": "ZURUBANK", "ewallet_phone": "+26770000000", "beneficiary_bank": "ZURUBANK", "destination_institution": "ZURUBANK"}	{"status": "SUCCESS", "message": "Hold placed successfully", "session_id": "SESSION-69a53676ce2de", "hold_placed": true, "new_balance": 995450, "held_balance": "3300.0000", "hold_reference": "8c4dc51e09119dd0ee509a31c4eb6070"}	200		t	\N	0	2026-03-02 09:04:22.822985+02	\N
72	8c4dc51e09119dd0ee509a31c4eb6070	generate_token	outgoing	\N	ZURUBWXX	/api/generate-atm-code	{"reference": "8c4dc51e09119dd0ee509a31c4eb6070", "beneficiary_phone": "+26770000000"}	{"debug": {"reference": "8c4dc51e09119dd0ee509a31c4eb6070", "cashout_id": 13, "voucher_id": 38}, "expiry": "2026-03-03 08:04:22", "atm_pin": "091829", "message": "ATM token generated successfully", "voucher_number": "137784084242", "token_generated": true, "token_reference": "VCH-137784084242"}	200		t	\N	0	2026-03-02 09:04:22.822985+02	\N
73	8c4dc51e09119dd0ee509a31c4eb6070	debit_funds	outgoing	\N	SACCUSBWXX	/api/debit	{"hold_reference": "8c4dc51e09119dd0ee509a31c4eb6070"}	{"amount": 100, "status": "SUCCESS", "debited": true, "message": "Funds debited and settled successfully", "transaction_reference": "8c4dc51e09119dd0ee509a31c4eb6070"}	200		t	\N	0	2026-03-02 09:04:22.822985+02	\N
75	ee1c9f897a174639e584727e51268555	verify_asset	outgoing	\N	ZURUBANK	/api/verify-asset	{"amount": 1500, "reference": "ee1c9f897a174639e584727e51268555", "asset_type": "VOUCHER", "institution": "ZURUBANK", "voucher_pin": "377975", "claimant_phone": "+26770000000", "voucher_number": "458063031195"}	{"balance": 1500, "success": true, "asset_id": 25, "metadata": {"status": "active", "currency": "BWP", "created_at": "2026-02-26 21:50:22.512047", "voucher_id": 25, "sat_purchased": false, "external_reference": null, "source_institution": null}, "verified": true, "asset_type": "VOUCHER", "expiry_date": null, "holder_name": "Motho", "voucher_number": "458063031195", "recipient_phone": "+26770000000", "available_balance": 1500}	200		t	\N	0	2026-03-02 11:26:27.733255+02	\N
88	SMS-API-69a5c93763fab	sms_send	outgoing	\N	\N	/backend/routes/api.php?path=sms/send	{"message": "🔐 VouchMorph Withdrawal\\nCode: 716775\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "reference": "ATM-69a5c937494db", "recipient_number": "26770000000"}	{"status": "success", "gateway": "SMS delivered to 26770000000", "message": "SMS sent successfully"}	200	\N	t	\N	0	2026-03-02 19:30:31.433613+02	\N
76	ee1c9f897a174639e584727e51268555	hold_placement	outgoing	\N	ZURUBANK	/api/authorize	{"action": "PLACE_HOLD", "amount": 1500, "expiry": "2026-03-03 10:26:27", "reference": "ee1c9f897a174639e584727e51268555", "asset_type": "VOUCHER", "destination": "SACCUSSALIS", "hold_reason": "PENDING_TRANSACTION", "foreign_bank": "SACCUSSALIS", "claimant_phone": "+26770000000", "voucher_number": "458063031195", "beneficiary_bank": "SACCUSSALIS", "destination_institution": "SACCUSSALIS"}	{"amount": "1500.00", "status": "SUCCESS", "message": "Voucher is now on hold", "asset_id": 25, "asset_type": "VOUCHER", "hold_placed": true, "hold_reference": "ee1c9f897a174639e584727e51268555", "voucher_number": "458063031195"}	200		t	\N	0	2026-03-02 11:26:27.733255+02	\N
77	ee1c9f897a174639e584727e51268555	generate_token	outgoing	\N	SACCUSSALIS	/api/generate-atm-code	{"action": "GENERATE_ATM_TOKEN", "amount": 1490, "code_hash": "$2y$10$zc2IJUetwVh.MBQF/QdBw.mp9B2lC4KJMU2yvv4.vzgPIvJJ50Xa6", "reference": "ee1c9f897a174639e584727e51268555", "beneficiary_phone": "+26770000000", "source_asset_type": "VOUCHER", "source_institution": "ZURUBANK", "source_hold_reference": "ee1c9f897a174639e584727e51268555"}	{"pin": "454459", "amount": 1490, "sat_id": 12, "atm_pin": "454459", "success": true, "currency": "BWP", "metadata": {"user_id": 2, "reference": "ee1c9f897a174639e584727e51268555", "wallet_id": 1, "source_institution": "ZURUBANK"}, "expires_at": "2026-03-03 10:26:28", "sat_number": "715478837754", "issuer_bank": "SACCUS", "acquirer_bank": "ZURUBANK", "instrument_id": 14, "token_generated": true, "token_reference": "715478837754"}	200		t	\N	0	2026-03-02 11:26:27.733255+02	\N
78	ee1c9f897a174639e584727e51268555	debit_funds	outgoing	\N	ZURUBANK	/api/debit	{"hold_reference": "ee1c9f897a174639e584727e51268555"}	{"data": {"debited": true, "hold_reference": "ee1c9f897a174639e584727e51268555", "transaction_reference": "ee1c9f897a174639e584727e51268555"}, "amount": 1500, "status": "SUCCESS", "debited": true, "message": "Voucher released and interbank settlement recorded", "success": true, "journal_id": 11, "hold_reference": "ee1c9f897a174639e584727e51268555", "voucher_number": "458063031195", "counterparty_bank": "SACCUSSALIS", "settlement_reference": "ee1c9f897a174639e584727e51268555"}	200		t	\N	0	2026-03-02 11:26:27.733255+02	\N
79	SMS-69a5b19591215	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "source": "VOUCHMORPH", "message": "Test SMS from VouchMorph at 2026-03-02 16:49:41", "priority": "high", "reference": "TEST-69a5b1958daf1", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:49:41.612392+02	\N
80	SMS-69a5b195a2124	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "source": "VOUCHMORPH", "message": "🔐 VouchMorph Withdrawal\\nCode: 302672\\nPIN: 1417\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "priority": "high", "reference": "ATM-69a5b1959fc88", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:49:41.664594+02	\N
81	SMS-69a5b195a851b	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "source": "VOUCHMORPH", "message": "✅ VouchMorph Transaction successful\\nType: CASHOUT\\nAmount: 1500 BWP\\nRef: SWAP-69a...\\nThank you for using VouchMorph!", "priority": "normal", "reference": "CONF-SWAP-69a5b195a434b", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:49:41.689997+02	\N
82	SMS-69a5b195ac6f1	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "expiry": 1772467181, "source": "VOUCHMORPH", "message": "Your VouchMorph verification code is: 589717\\nValid for 10 minutes.", "priority": "high", "reference": "OTP-69a5b195a9618", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:49:41.706813+02	\N
83	SMS-69a5b1e76bbe2	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "source": "VOUCHMORPH", "message": "Test SMS from VouchMorph at 2026-03-02 16:51:03", "priority": "high", "reference": "TEST-69a5b1e76ac1e", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:51:03.443023+02	\N
84	SMS-69a5b1e76ebc4	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "source": "VOUCHMORPH", "message": "🔐 VouchMorph Withdrawal\\nCode: 867426\\nPIN: 7125\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "priority": "high", "reference": "ATM-69a5b1e76e239", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:51:03.45426+02	\N
85	SMS-69a5b1e770a72	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "source": "VOUCHMORPH", "message": "✅ VouchMorph Transaction successful\\nType: CASHOUT\\nAmount: 1500 BWP\\nRef: SWAP-69a...\\nThank you for using VouchMorph!", "priority": "normal", "reference": "CONF-SWAP-69a5b1e76fef4", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:51:03.462026+02	\N
86	SMS-69a5b1e772b80	sms_send	outgoing	\N	\N	/routes/send_sms.php	{"to": "26770000000", "expiry": 1772467263, "source": "VOUCHMORPH", "message": "Your VouchMorph verification code is: 329992\\nValid for 10 minutes.", "priority": "high", "reference": "OTP-69a5b1e771b68", "callback_url": "http://localhost/vouchmorph/public/api/callback/sms_delivery.php"}	{"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}	200	\N	f	\N	0	2026-03-02 17:51:03.470757+02	\N
87	SMS-API-69a5b8c3e80e7	sms_send	outgoing	\N	\N	/backend/routes/api.php?path=sms/send	{"message": "🔐 VouchMorph Withdrawal\\nCode: 840983\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "reference": "ATM-69a5b8c3d8002", "recipient_number": "26770000000"}	{"status": "success", "gateway": "SMS delivered to 26770000000", "message": "SMS sent successfully"}	200	\N	t	\N	0	2026-03-02 18:20:19.952564+02	\N
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.audit_logs (audit_id, audit_uuid, entity_type, entity_id, action, category, severity, old_value, new_value, changes, performed_by_type, performed_by_id, ip_address, user_agent, geo_location, request_id, performed_at) FROM stdin;
1	1739821c-5dc9-42e9-92b9-11056b62932c	SWAP	\N	EXECUTE	TRANSACTION	info	\N	\N	\N	user	\N	\N	\N	\N	\N	2026-02-28 15:52:47.671013+02
2	52fe71ac-aac3-4e4a-9251-a46ee3020477	SWAP	\N	EXECUTE	TRANSACTION	info	\N	\N	\N	user	\N	\N	\N	\N	\N	2026-03-02 09:04:23.024224+02
71	3f858e6f-c5a2-4d07-9571-bfa496ff19f7	SWAP_REQUEST	1	SWAP_EXECUTED	transaction	info	\N	{"amount": 1500, "status": "completed"}	{"fee_deducted": 10.00}	user	1001	192.168.1.100	Mozilla/5.0	\N	\N	2026-03-02 11:35:12.688858+02
72	df1f9b6d-f0ce-4c1c-8d22-59e57c62e4a9	USER	1001	LOGIN_SUCCESS	authentication	info	\N	{"session_id": "sess_abc123"}	\N	user	1001	192.168.1.100	Mozilla/5.0	\N	\N	2026-03-02 10:35:12.688858+02
73	d4c73870-199f-4245-80c2-a1d06e5661e4	SETTLEMENT	1	SETTLEMENT_PROCESSED	settlement	info	{"status": "pending"}	{"status": "completed"}	\N	system	\N	127.0.0.1	System	\N	\N	2026-03-02 12:35:12.688858+02
74	d6d8cf8d-c233-4d23-a409-cc7bad3e9319	FEE_COLLECTION	1	FEE_DEDUCTED	fee	info	\N	{"type": "CASHOUT_SWAP_FEE", "amount": 10.00}	\N	system	\N	127.0.0.1	System	\N	\N	2026-03-02 11:35:12.688858+02
\.


--
-- Data for Name: cashout_authorizations; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.cashout_authorizations (auth_id, swap_reference, client_phone, source_institution, source_wallet, amount, currency, fee_amount, swap_code, pin_code, code_expiry, cashout_point, cashout_provider, status, created_at, updated_at, completed_at, code_used_at, metadata) FROM stdin;
\.


--
-- Data for Name: deposit_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.deposit_transactions (deposit_id, transaction_reference, client_phone, source_type, source_institution, source_account, destination_type, destination_institution, destination_account, amount, currency, fee_amount, status, created_at, updated_at, completed_at, metadata) FROM stdin;
\.


--
-- Data for Name: hold_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hold_transactions (hold_id, hold_reference, swap_reference, participant_id, participant_name, asset_type, amount, currency, status, hold_expiry, source_details, destination_institution, destination_participant_id, metadata, placed_at, released_at, debited_at, created_at, updated_at) FROM stdin;
16	3145a5cc507709e58a4767f6c7499984	3145a5cc507709e58a4767f6c7499984	\N	ZURUBANK	VOUCHER	1500.00000000	BWP	DEBITED	\N	"VCH-5833"	SACCUSSALIS	6	{"source_asset_type": "VOUCHER", "source_institution": "ZURUBANK", "destination_institution": "SACCUSSALIS"}	2026-03-02 08:16:32.599867+02	\N	2026-03-02 08:16:32.599867+02	2026-03-02 08:16:32.599867+02	2026-03-02 08:16:32.599867+02
18	897ba2207e18cc592d82fffa28be41ff	897ba2207e18cc592d82fffa28be41ff	\N	ZURUBANK	VOUCHER	1500.00000000	BWP	DEBITED	\N	"VCH-0565"	SACCUSSALIS	6	{"source_asset_type": "VOUCHER", "source_institution": "ZURUBANK", "destination_institution": "SACCUSSALIS"}	2026-03-02 09:02:54.485004+02	\N	2026-03-02 09:02:54.485004+02	2026-03-02 09:02:54.485004+02	2026-03-02 09:02:54.485004+02
19	8c4dc51e09119dd0ee509a31c4eb6070	8c4dc51e09119dd0ee509a31c4eb6070	\N	SACCUSBWXX	e-wallet	100.00000000	BWP	DEBITED	\N	"EWL-70000000"	ZURUBANK	5	{"source_asset_type": "e-wallet", "source_institution": "SACCUSSALIS", "destination_institution": "ZURUBANK"}	2026-03-02 09:04:22.822985+02	\N	2026-03-02 09:04:22.822985+02	2026-03-02 09:04:22.822985+02	2026-03-02 09:04:22.822985+02
20	ee1c9f897a174639e584727e51268555	ee1c9f897a174639e584727e51268555	\N	ZURUBANK	VOUCHER	1500.00000000	BWP	DEBITED	\N	"VCH-1195"	SACCUSSALIS	6	{"source_asset_type": "VOUCHER", "source_institution": "ZURUBANK", "destination_institution": "SACCUSSALIS"}	2026-03-02 11:26:27.733255+02	\N	2026-03-02 11:26:27.733255+02	2026-03-02 11:26:27.733255+02	2026-03-02 11:26:27.733255+02
\.


--
-- Data for Name: kyc_documents; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.kyc_documents (kyc_id, user_id, document_type, document_number, status, document_path, document_hash, expiry_date, admin_reviewer_id, review_date, review_notes, metadata, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: ledger_accounts; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.ledger_accounts (account_id, account_code, account_name, account_type, balance, participant_id, currency_code, is_active, created_at, updated_at) FROM stdin;
1	BANK_A_SETTLEMENT	BANK_A Settlement Account	settlement	1000000.00000000	\N	BWP	t	2026-02-23 00:01:15.678225+02	2026-02-23 00:01:15.678225+02
2	BANK_B_SETTLEMENT	BANK_B Settlement Account	settlement	1000000.00000000	\N	BWP	t	2026-02-23 00:01:15.678225+02	2026-02-23 00:01:15.678225+02
3	BANK_C_SETTLEMENT	BANK_C Settlement Account	settlement	1000000.00000000	\N	BWP	t	2026-02-23 00:01:15.678225+02	2026-02-23 00:01:15.678225+02
4	BANK_D_SETTLEMENT	BANK_D Settlement Account	settlement	1000000.00000000	\N	BWP	t	2026-02-23 00:01:15.678225+02	2026-02-23 00:01:15.678225+02
5	BANK_A_1771797675	BANK_A_SETTLEMENT	settlement	0.00000000	\N	BWP	t	2026-02-23 00:01:15.747261+02	2026-02-23 00:01:15.747261+02
6	BANK_B_1771797675	BANK_B_SETTLEMENT	settlement	0.00000000	\N	BWP	t	2026-02-23 00:01:15.747261+02	2026-02-23 00:01:15.747261+02
9	BANK_C_1771798293	BANK_C_SETTLEMENT	settlement	0.00000000	\N	BWP	t	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02
10	BANK_D_1771798293	BANK_D_SETTLEMENT	settlement	0.00000000	\N	BWP	t	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02
11	BANK_F_1771852263	BANK_F_SETTLEMENT	settlement	0.00000000	\N	BWP	t	2026-02-23 15:11:03.219272+02	2026-02-23 15:11:03.219272+02
12	SACCUS_1772279797	SACCUSSALIS_SETTLEMENT	settlement	0.00000000	\N	BWP	t	2026-02-28 13:56:37.710811+02	2026-02-28 13:56:37.710811+02
13	ZURUBA_1772279797	ZURUBANK_SETTLEMENT	settlement	0.00000000	\N	BWP	t	2026-02-28 13:56:37.710811+02	2026-02-28 13:56:37.710811+02
\.


--
-- Data for Name: ledger_entries; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.ledger_entries (entry_id, transaction_id, debit_account_id, credit_account_id, amount, currency_code, reference, split_type, created_at, updated_at) FROM stdin;
1	1	5	6	487.40000000	BWP	SWAP_TO_SWAP	main	2026-02-23 00:01:15.747261+02	2026-02-23 00:01:15.747261+02
2	2	5	6	487.40000000	BWP	DEPOSIT	main	2026-02-23 00:01:15.747261+02	2026-02-23 00:01:15.747261+02
6	6	5	6	487.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 00:11:33.590783+02	2026-02-23 00:11:33.590783+02
7	7	5	6	487.00000000	BWP	DEPOSIT	main	2026-02-23 00:11:33.590783+02	2026-02-23 00:11:33.590783+02
8	8	5	9	974.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02
9	9	5	9	974.50000000	BWP	DEPOSIT	main	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02
10	10	5	10	250.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02
11	11	5	11	48749.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:11:03.219272+02	2026-02-23 15:11:03.219272+02
12	12	5	11	48749.50000000	BWP	DEPOSIT	main	2026-02-23 15:11:03.219272+02	2026-02-23 15:11:03.219272+02
13	13	5	6	97.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:20:29.727005+02	2026-02-23 15:20:29.727005+02
14	14	5	6	97.00000000	BWP	DEPOSIT	main	2026-02-23 15:20:29.727005+02	2026-02-23 15:20:29.727005+02
15	15	5	6	974.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:22:33.106617+02	2026-02-23 15:22:33.106617+02
16	16	5	6	974.50000000	BWP	DEPOSIT	main	2026-02-23 15:22:33.106617+02	2026-02-23 15:22:33.106617+02
17	17	5	11	4874.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:22:33.118678+02	2026-02-23 15:22:33.118678+02
18	18	5	11	4874.50000000	BWP	DEPOSIT	main	2026-02-23 15:22:33.118678+02	2026-02-23 15:22:33.118678+02
19	19	5	6	974.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:24:23.633775+02	2026-02-23 15:24:23.633775+02
20	20	5	6	974.50000000	BWP	DEPOSIT	main	2026-02-23 15:24:23.633775+02	2026-02-23 15:24:23.633775+02
21	21	5	11	4874.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:24:23.647304+02	2026-02-23 15:24:23.647304+02
22	22	5	11	4874.50000000	BWP	DEPOSIT	main	2026-02-23 15:24:23.647304+02	2026-02-23 15:24:23.647304+02
23	23	5	6	974.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:24:37.47646+02	2026-02-23 15:24:37.47646+02
24	24	5	6	974.50000000	BWP	DEPOSIT	main	2026-02-23 15:24:37.47646+02	2026-02-23 15:24:37.47646+02
25	25	5	11	4874.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:24:37.485972+02	2026-02-23 15:24:37.485972+02
26	26	5	11	4874.50000000	BWP	DEPOSIT	main	2026-02-23 15:24:37.485972+02	2026-02-23 15:24:37.485972+02
27	27	5	6	974.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:25:55.093399+02	2026-02-23 15:25:55.093399+02
28	28	5	6	974.50000000	BWP	DEPOSIT	main	2026-02-23 15:25:55.093399+02	2026-02-23 15:25:55.093399+02
29	29	5	11	4874.50000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-23 15:25:55.107054+02	2026-02-23 15:25:55.107054+02
30	30	5	11	4874.50000000	BWP	DEPOSIT	main	2026-02-23 15:25:55.107054+02	2026-02-23 15:25:55.107054+02
31	31	12	13	100.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-28 13:56:37.710811+02	2026-02-28 13:56:37.710811+02
32	32	12	13	100.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-28 14:06:05.673521+02	2026-02-28 14:06:05.673521+02
33	33	12	13	100.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-02-28 15:52:47.445708+02	2026-02-28 15:52:47.445708+02
39	39	13	12	1500.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-03-02 08:16:32.599867+02	2026-03-02 08:16:32.599867+02
41	41	13	12	1500.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-03-02 09:02:54.485004+02	2026-03-02 09:02:54.485004+02
42	42	12	13	100.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-03-02 09:04:22.822985+02	2026-03-02 09:04:22.822985+02
43	43	13	12	1490.00000000	BWP	INTER_PARTICIPANT_SETTLEMENT	main	2026-03-02 11:26:27.733255+02	2026-03-02 11:26:27.733255+02
\.


--
-- Data for Name: message_outbox; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.message_outbox (message_id, channel, destination, payload, status, created_at, sent_at) FROM stdin;
SMS-69a2d7f5c5fd7	SMS	+26770000000	{"code": "418596", "amount": 100, "message": "🔐 VouchMorph Withdrawal\\nCode: 418596\\nATM PIN: 302238\\nAmount: 100 BWP\\nValid for 24 hours.\\nKeep this code secure!", "atm_info": {"debug": {"reference": "f0191648e17fd091eb2096d8dd4bd132", "cashout_id": 8, "voucher_id": 33}, "expiry": "2026-03-01 12:56:37", "atm_pin": "302238", "message": "ATM token generated successfully", "voucher_number": "187943147702", "token_generated": true, "token_reference": "VCH-187943147702"}, "currency": "BWP"}	PENDING	2026-02-28 13:56:37.710811	\N
SMS-69a2da2dbae45	SMS	+26770000000	{"code": "112824", "amount": 100, "message": "🔐 VouchMorph Withdrawal\\nCode: 112824\\nATM PIN: 602273\\nAmount: 100 BWP\\nValid for 24 hours.\\nKeep this code secure!", "atm_info": {"debug": {"reference": "7847ef137e2e8fe4560717e4a416e21f", "cashout_id": 9, "voucher_id": 34}, "expiry": "2026-03-01 13:06:05", "atm_pin": "602273", "message": "ATM token generated successfully", "voucher_number": "319886326605", "token_generated": true, "token_reference": "VCH-319886326605"}, "currency": "BWP"}	PENDING	2026-02-28 14:06:05.673521	\N
SMS-69a2f32f91b19	SMS	+26770000000	{"code": "411553", "amount": 100, "message": "🔐 VouchMorph Withdrawal\\nCode: 411553\\nATM PIN: 853807\\nAmount: 100 BWP\\nValid for 24 hours.\\nKeep this code secure!", "atm_info": {"debug": {"reference": "805a45ee0bf22b8cd566c778ce106c81", "cashout_id": 12, "voucher_id": 37}, "expiry": "2026-03-01 14:52:47", "atm_pin": "853807", "message": "ATM token generated successfully", "voucher_number": "703337860851", "token_generated": true, "token_reference": "VCH-703337860851"}, "currency": "BWP"}	PENDING	2026-02-28 15:52:47.445708	\N
SMS-69a52b40a7390	SMS	+26770000000	{"code": "876079", "amount": 1500, "message": "🔐 VouchMorph Withdrawal\\nCode: 876079\\nATM PIN: 924230\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "atm_info": {"pin": "924230", "amount": 1500, "sat_id": 9, "atm_pin": "924230", "success": true, "currency": "BWP", "metadata": {"user_id": 2, "reference": "3145a5cc507709e58a4767f6c7499984", "wallet_id": 1, "source_institution": "ZURUBANK"}, "expires_at": "2026-03-03 07:16:32", "sat_number": "876393263575", "issuer_bank": "SACCUS", "acquirer_bank": "ZURUBANK", "instrument_id": 11, "token_generated": true, "token_reference": "876393263575"}, "currency": "BWP"}	PENDING	2026-03-02 08:16:32.599867	\N
SMS-69a5361e8dbfb	SMS	+26770000000	{"code": "297845", "amount": 1500, "message": "🔐 VouchMorph Withdrawal\\nCode: 297845\\nATM PIN: 469943\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "atm_info": {"pin": "469943", "amount": 1500, "sat_id": 11, "atm_pin": "469943", "success": true, "currency": "BWP", "metadata": {"user_id": 2, "reference": "897ba2207e18cc592d82fffa28be41ff", "wallet_id": 1, "source_institution": "ZURUBANK"}, "expires_at": "2026-03-03 08:02:54", "sat_number": "381942164429", "issuer_bank": "SACCUS", "acquirer_bank": "ZURUBANK", "instrument_id": 13, "token_generated": true, "token_reference": "381942164429"}, "currency": "BWP"}	PENDING	2026-03-02 09:02:54.485004	\N
SMS-69a53676ea315	SMS	+26770000000	{"code": "586092", "amount": 100, "message": "🔐 VouchMorph Withdrawal\\nCode: 586092\\nATM PIN: 091829\\nAmount: 100 BWP\\nValid for 24 hours.\\nKeep this code secure!", "atm_info": {"debug": {"reference": "8c4dc51e09119dd0ee509a31c4eb6070", "cashout_id": 13, "voucher_id": 38}, "expiry": "2026-03-03 08:04:22", "atm_pin": "091829", "message": "ATM token generated successfully", "voucher_number": "137784084242", "token_generated": true, "token_reference": "VCH-137784084242"}, "currency": "BWP"}	PENDING	2026-03-02 09:04:22.822985	\N
SMS-69a557c41c87a	SMS	+26770000000	{"code": "328888", "amount": 1490, "message": "🔐 VouchMorph Withdrawal\\nCode: 328888\\nATM PIN: 454459\\nAmount: 1490 BWP\\nValid for 24 hours.\\nKeep this code secure!", "atm_info": {"pin": "454459", "amount": 1490, "sat_id": 12, "atm_pin": "454459", "success": true, "currency": "BWP", "metadata": {"user_id": 2, "reference": "ee1c9f897a174639e584727e51268555", "wallet_id": 1, "source_institution": "ZURUBANK"}, "expires_at": "2026-03-03 10:26:28", "sat_number": "715478837754", "issuer_bank": "SACCUS", "acquirer_bank": "ZURUBANK", "instrument_id": 14, "token_generated": true, "token_reference": "715478837754"}, "currency": "BWP"}	PENDING	2026-03-02 11:26:27.733255	\N
SMS-39dd7a3a-c994-42ff-b61e-2379861685c8	SMS	+26770000000	{"code": "123456", "amount": 1500, "message": "Your withdrawal code: 123456"}	PENDING	2026-03-02 13:05:12.688858	\N
SMS-42eb67c6-b836-4323-8814-6a94fcc74b46	SMS	+26771111111	{"code": "789012", "amount": 2500, "message": "Your withdrawal code: 789012"}	SENT	2026-03-02 11:35:12.688858	\N
EMAIL-870901d9-47b9-453b-b536-622c83f3e1d6	EMAIL	test@example.com	{"body": "Your swap of 1500 BWP was successful", "subject": "Swap Completed"}	PENDING	2026-03-02 13:20:12.688858	\N
SMS-69a5b1959d9fd	SMS	+26770000000	{"phone": "+26770000000", "message": "Test SMS from VouchMorph at 2026-03-02 16:49:41", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:49:41.647357	\N
SMS-69a5b195a2a50	SMS	+26770000000	{"phone": "+26770000000", "message": "🔐 VouchMorph Withdrawal\\nCode: 302672\\nPIN: 1417\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:49:41.666694	\N
SMS-69a5b195a903f	SMS	+26770000000	{"phone": "+26770000000", "message": "✅ VouchMorph Transaction successful\\nType: CASHOUT\\nAmount: 1500 BWP\\nRef: SWAP-69a...\\nThank you for using VouchMorph!", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:49:41.692561	\N
SMS-69a5b195ad649	SMS	+26770000000	{"phone": "+26770000000", "message": "Your VouchMorph verification code is: 589717\\nValid for 10 minutes.", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:49:41.710896	\N
SMS-69a5b1e76cdf5	SMS	+26770000000	{"phone": "+26770000000", "message": "Test SMS from VouchMorph at 2026-03-02 16:51:03", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:51:03.446856	\N
SMS-69a5b1e76f479	SMS	+26770000000	{"phone": "+26770000000", "message": "🔐 VouchMorph Withdrawal\\nCode: 867426\\nPIN: 7125\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:51:03.456315	\N
SMS-69a5b1e77138f	SMS	+26770000000	{"phone": "+26770000000", "message": "✅ VouchMorph Transaction successful\\nType: CASHOUT\\nAmount: 1500 BWP\\nRef: SWAP-69a...\\nThank you for using VouchMorph!", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:51:03.464396	\N
SMS-69a5b1e773607	SMS	+26770000000	{"phone": "+26770000000", "message": "Your VouchMorph verification code is: 329992\\nValid for 10 minutes.", "api_response": {"error": "Invalid JSON response", "success": false, "raw_response": "<?xml version=\\"1.0\\" encoding=\\"UTF-8\\"?>\\n<!DOCTYPE html PUBLIC \\"-//W3C//DTD XHTML 1.0 Strict//EN\\"\\n  \\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\\">\\n<html xmlns=\\"http://www.w3.org/1999/xhtml\\" lang=\\"en\\" xml:lang=\\"en\\">\\n<head>\\n<title>Object not found!</title>\\n<link rev=\\"made\\" href=\\"mailto:you@example.com\\" />\\n<style type=\\"text/css\\"><!--/*--><![CDATA[/*><!--*/ \\n    body { color: #000000; background-color: #FFFFFF; }\\n    a:link { color: #0000CC; }\\n    p, address {margin-left: 3em;}\\n    span {font-size: smaller;}\\n/*]]>*/--></style>\\n</head>\\n\\n<body>\\n<h1>Object not found!</h1>\\n<p>\\n\\n\\n    The requested URL was not found on this server.\\n\\n  \\n\\n    If you entered the URL manually please check your\\n    spelling and try again.\\n\\n  \\n\\n</p>\\n<p>\\nIf you think this is a server error, please contact\\nthe <a href=\\"mailto:you@example.com\\">webmaster</a>.\\n\\n</p>\\n\\n<h2>Error 404</h2>\\n<address>\\n  <a href=\\"/\\">localhost</a><br />\\n  <span>Apache/2.4.56 (Unix) OpenSSL/1.1.1t PHP/8.2.4 mod_perl/2.0.12 Perl/v5.34.1</span>\\n</address>\\n</body>\\n</html>\\n\\n"}}	FAILED	2026-03-02 17:51:03.47299	\N
SMS-69a5b8c3ea5dd	SMS	+26770000000	{"phone": "+26770000000", "message": "🔐 VouchMorph Withdrawal\\nCode: 840983\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "reference": "ATM-69a5b8c3d8002", "api_response": {"status": "success", "gateway": "SMS delivered to 26770000000", "message": "SMS sent successfully"}}	SENT	2026-03-02 18:20:19.96083	\N
SMS-69a5c9376e8c8	SMS	+26770000000	{"phone": "+26770000000", "message": "🔐 VouchMorph Withdrawal\\nCode: 716775\\nAmount: 1500 BWP\\nValid for 24 hours.\\nKeep this code secure!", "reference": "ATM-69a5c937494db", "api_response": {"status": "success", "gateway": "SMS delivered to 26770000000", "message": "SMS sent successfully"}}	SENT	2026-03-02 19:30:31.454914	\N
\.


--
-- Data for Name: net_positions; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.net_positions (id, debtor, creditor, amount, currency_code, created_at, updated_at) FROM stdin;
1	BANK_A	BANK_B	15000.00000000	BWP	2026-02-23 15:19:21.823206+02	2026-02-23 15:19:21.823206+02
2	BANK_B	BANK_C	5000.00000000	BWP	2026-02-23 15:19:21.823206+02	2026-02-23 15:19:21.823206+02
3	BANK_C	BANK_A	25000.00000000	BWP	2026-02-23 15:19:21.823206+02	2026-02-23 15:19:21.823206+02
4	BANK_D	BANK_A	10000.00000000	BWP	2026-02-23 15:19:21.823206+02	2026-02-23 15:19:21.823206+02
5	BANK_A	BANK_D	7500.00000000	BWP	2026-02-23 15:19:21.823206+02	2026-02-23 15:19:21.823206+02
6	ZURUBANK	SACCUSSALIS	1500.00000000	BWP	2026-03-02 09:02:54.485004+02	2026-03-02 13:35:12.688858+02
10	TEST_BANK_A	TEST_BANK_B	2500.00000000	BWP	2026-02-28 13:35:12.688858+02	2026-03-02 11:35:12.688858+02
7	SACCUSSALIS	ZURUBANK	-800.00000000	BWP	2026-03-02 09:04:22.822985+02	2026-03-02 13:35:12.688858+02
\.


--
-- Data for Name: otp_logs; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.otp_logs (otp_id, identifier, identifier_type, code_hash, purpose, expires_at, used_at, attempts, ip_address, user_agent, created_at) FROM stdin;
\.


--
-- Data for Name: participant_fee_overrides; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.participant_fee_overrides (override_id, participant_id, transaction_type, fee_amount, split, active, created_at) FROM stdin;
1	3	CASHOUT	5.00	{"mno": 2.5, "vouchmorph": 2.5}	t	2026-02-23 00:30:27.925424
2	4	CASHOUT	5.00	{"mno": 2.5, "vouchmorph": 2.5}	t	2026-02-23 00:30:27.927611
\.


--
-- Data for Name: participants; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.participants (participant_id, name, type, category, provider_code, auth_type, base_url, system_user_id, legal_entity_identifier, license_number, settlement_account, settlement_type, status, capabilities, resource_endpoints, phone_format, security_config, message_profile, routing_info, metadata) FROM stdin;
1	TEST_BANK_A	FINANCIAL_INSTITUTION	BANK	TEST_BIC_A	MTLS_OAUTH2	https://sandbox-bank.local	1	TEST_LEI_001	CB-BW-001	TEST_ACC_001	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "VOUCHER"], "supports_realtime_settlement": true}	{"reversal": "/sandbox/payments/{id}/reversal", "funds_confirmation": "/sandbox/accounts/{id}/balance", "payment_initiation": "/sandbox/payments", "beneficiary_validation": "/sandbox/identity/verify"}	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "TEST_ACC_001"}	\N
2	TEST_BANK_B	FINANCIAL_INSTITUTION	BANK	TEST_BIC_B	MTLS_OAUTH2	https://sandbox-bank-b.local	2	TEST_LEI_002	CB-BW-002	TEST_ACC_002	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "VOUCHER"], "supports_realtime_settlement": true}	{"status_query": "/api/v1/transaction/status.php", "credit_transfer": "/api/v1/deposit/direct.php", "identity_lookup": "/api/v1/verify_account.php", "voucher_request": "/api/v1/atm/generate_code.php", "reverse_transaction": "/api/v1/transaction/reverse.php", "settlement_instruction": "/api/v1/settlement/notify_debit.php"}	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "TEST_ACC_002"}	\N
3	TEST_MNO_A	MOBILE_MONEY_OPERATOR	MNO	TEST_MNC_A	OAUTH2_JWT	https://sandbox-mno.local	\N	\N	\N	\N	\N	ACTIVE	{"supports_sca": true, "wallet_types": ["WALLET"], "supports_realtime_disbursement": true}	{"kyc_check": "/sandbox/subscribers/{msisdn}/validate", "collection": "/sandbox/request-to-pay", "disbursement": "/sandbox/disbursements"}	\N	\N	\N	\N	\N
4	TEST_MNO_B	MOBILE_MONEY_OPERATOR	MNO	TEST_MNC_B	OAUTH2_JWT	https://sandbox-mno-b.local	\N	\N	\N	\N	\N	ACTIVE	{"supports_sca": true, "wallet_types": ["WALLET"], "supports_realtime_disbursement": true}	{"kyc_check": "/sandbox/subscribers/{msisdn}/validate", "collection": "/sandbox/request-to-pay", "disbursement": "/sandbox/disbursements"}	\N	\N	\N	\N	\N
5	ZURUBANK	FINANCIAL_INSTITUTION	BANK	ZURUBWXX	MTLS_OAUTH2	http://localhost/zurubank/Backend	\N	\N	\N	\N	\N	ACTIVE	\N	\N	\N	\N	\N	\N	\N
6	SACCUSSALIS	FINANCIAL_INSTITUTION	BANK	SACCUSBWXX	MTLS_OAUTH2	http://localhost/SaccusSalisbank/backend	\N	\N	\N	\N	\N	ACTIVE	\N	\N	\N	\N	\N	\N	\N
\.


--
-- Data for Name: regulator_outbox; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.regulator_outbox (id, report_id, payload, integrity_hash, status, attempts, last_attempt, created_at) FROM stdin;
39	RPT_DAILY_20260302	{"date": "2026-03-02", "currency": "BWP", "report_type": "DAILY_SETTLEMENT", "total_amount": 4800.00}	295032a014a0858b35ac2abb05cce4da17a3e1e38c4e7a0dfa290f0b5d3e1bee	PENDING	0	\N	2026-03-02 12:35:12.688858+02
40	RPT_AML_20260302	{"date": "2026-03-02", "flagged": 2, "report_type": "AML_CHECK", "transactions_reviewed": 150}	32a49955e8b01beb68c4520c79eb707b4d212e7567bb47a6b853c091b749ee21	SENT	1	2026-03-02 11:35:12.688858+02	2026-03-02 10:35:12.688858+02
41	RPT_FEES_20260302	{"date": "2026-03-02", "currency": "BWP", "total_fees": 26.00, "report_type": "FEE_REPORT"}	01ce0c07754eede2788a98c4b72280331ba41a8a48b0459be3a7cc6d1f24add5	RETRY	3	2026-03-02 13:05:12.688858+02	2026-03-02 11:35:12.688858+02
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.roles (role_id, role_name, description, permissions, created_at, updated_at) FROM stdin;
1	user	Regular system user	["basic_access", "create_swaps", "view_own_transactions"]	2026-02-22 16:19:48.915225+02	2026-02-22 16:19:48.915225+02
2	admin	System administrator	["full_access", "manage_users", "manage_swaps", "view_all_transactions"]	2026-02-22 16:19:48.915225+02	2026-02-22 16:19:48.915225+02
3	compliance	Compliance officer	["view_kyc", "approve_kyc", "view_audit_logs", "fraud_investigation"]	2026-02-22 16:19:48.915225+02	2026-02-22 16:19:48.915225+02
4	auditor	System auditor	["view_audit_logs", "view_transactions", "view_reports"]	2026-02-22 16:19:48.915225+02	2026-02-22 16:19:48.915225+02
5	super_admin	Super administrator	["full_access", "manage_admins", "system_configuration"]	2026-02-22 16:19:48.915225+02	2026-02-22 16:19:48.915225+02
\.


--
-- Data for Name: sandbox_disclosures; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.sandbox_disclosures (id, user_id, consent_version, has_accepted, disclosure_text, experimental_risk_acknowledged_at) FROM stdin;
\.


--
-- Data for Name: send_to_other_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.send_to_other_transactions (send_id, transaction_reference, sender_phone, sender_institution, sender_account, receiver_phone, receiver_institution, receiver_account, amount, currency, fee_amount, status, created_at, updated_at, completed_at, notification_sent, metadata) FROM stdin;
\.


--
-- Data for Name: settlement_messages; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.settlement_messages (message_id, transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at, processed_at) FROM stdin;
1	f0191648e17fd091eb2096d8dd4bd132	SACCUSSALIS	ZURUBANK	100.00	CASHOUT_SETTLEMENT	PENDING	{"source_type": "E-WALLET", "hold_reference": "f0191648e17fd091eb2096d8dd4bd132", "source_reference": "EWL-70000000", "beneficiary_phone": "70000000"}	2026-02-28 13:56:37.710811	\N
2	7847ef137e2e8fe4560717e4a416e21f	SACCUSSALIS	ZURUBANK	100.00	CASHOUT_SETTLEMENT	PENDING	{"source_type": "E-WALLET", "hold_reference": "7847ef137e2e8fe4560717e4a416e21f", "source_reference": "EWL-70000000", "beneficiary_phone": "70000000"}	2026-02-28 14:06:05.673521	\N
3	805a45ee0bf22b8cd566c778ce106c81	SACCUSSALIS	ZURUBANK	100.00	CASHOUT_SETTLEMENT	PENDING	{"source_type": "e-wallet", "hold_reference": "805a45ee0bf22b8cd566c778ce106c81", "source_reference": "EWL-70000000", "beneficiary_phone": "70000000"}	2026-02-28 15:52:47.445708	\N
9	3145a5cc507709e58a4767f6c7499984	ZURUBANK	SACCUSSALIS	1500.00	CASHOUT_SETTLEMENT	PENDING	{"source_type": "VOUCHER", "hold_reference": "3145a5cc507709e58a4767f6c7499984", "source_reference": "VCH-5833", "beneficiary_phone": "70000000"}	2026-03-02 08:16:32.599867	\N
11	897ba2207e18cc592d82fffa28be41ff	ZURUBANK	SACCUSSALIS	1500.00	CASHOUT_SETTLEMENT	PENDING	{"source_type": "VOUCHER", "hold_reference": "897ba2207e18cc592d82fffa28be41ff", "source_reference": "VCH-0565", "beneficiary_phone": "70000000"}	2026-03-02 09:02:54.485004	\N
12	8c4dc51e09119dd0ee509a31c4eb6070	SACCUSSALIS	ZURUBANK	100.00	CASHOUT_SETTLEMENT	PENDING	{"source_type": "e-wallet", "hold_reference": "8c4dc51e09119dd0ee509a31c4eb6070", "source_reference": "EWL-70000000", "beneficiary_phone": "70000000"}	2026-03-02 09:04:22.822985	\N
13	ee1c9f897a174639e584727e51268555	ZURUBANK	SACCUSSALIS	1500.00	CASHOUT_SETTLEMENT	PENDING	{"fee": {"split": {"vouchmorph": 4, "source_participant": 2, "destination_participant": 4}, "fee_id": 2, "total_fee": 10, "net_amount": 1490}, "source_type": "VOUCHER", "hold_reference": "ee1c9f897a174639e584727e51268555", "source_reference": "VCH-1195", "beneficiary_phone": "70000000"}	2026-03-02 11:26:27.733255	\N
20	SWAP-4dbdeabe-3ba4-442a-bbd1-9591f9bf7ea2	ZURUBANK	SACCUSSALIS	1500.00	CASHOUT_SETTLEMENT	PENDING	{"source_type": "VOUCHER", "beneficiary_phone": "+26770000000"}	2026-03-02 12:35:12.688858	\N
21	SWAP-b39bafe4-137b-4230-8533-724f1a67b501	TEST_BANK_A	TEST_BANK_B	2500.00	DEPOSIT_SETTLEMENT	PENDING	{"beneficiary": "+26771111111", "source_type": "ACCOUNT"}	2026-03-02 10:35:12.688858	\N
\.


--
-- Data for Name: settlement_queue; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.settlement_queue (id, debtor, creditor, amount, created_at, updated_at) FROM stdin;
8	ZURUBANK	SACCUSSALIS	1500.00000000	2026-03-02 12:35:12.688858+02	2026-03-02 13:35:12.688858+02
9	TEST_BANK_A	TEST_BANK_B	2500.00000000	2026-03-02 11:35:12.688858+02	2026-03-02 13:35:12.688858+02
10	SACCUSSALIS	ZURUBANK	800.00000000	2026-03-02 13:05:12.688858+02	2026-03-02 13:35:12.688858+02
\.


--
-- Data for Name: supervisory_heartbeat; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.supervisory_heartbeat (heartbeat_id, status, created_at, latency_ms, system_load) FROM stdin;
1	ACTIVE	2026-02-22 16:19:48.915225+02	0	0.00
2	ACTIVE	2026-03-02 13:35:12.688858+02	45	0.23
\.


--
-- Data for Name: swap_fee_collections; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_fee_collections (fee_id, swap_reference, fee_type, total_amount, currency, source_institution, destination_institution, split_config, vat_amount, status, collected_at, settled_at, created_at, updated_at) FROM stdin;
2	ee1c9f897a174639e584727e51268555	CASHOUT_SWAP_FEE	10.00000000	BWP	ZURUBANK	SACCUSSALIS	{"vouchmorph": 4, "source_participant": 2, "destination_participant": 4}	1.40000000	COLLECTED	2026-03-02 11:26:27.733255+02	\N	2026-03-02 11:26:27.733255+02	2026-03-02 11:26:27.733255+02
3	a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6	CASHOUT_SWAP_FEE	10.00000000	BWP	ZURUBANK	SACCUSSALIS	{"vouchmorph": 4.00, "source_participant": 2.00, "destination_participant": 4.00}	1.40000000	COLLECTED	2026-02-28 13:10:53.676164+02	\N	2026-03-02 13:10:53.676164+02	2026-03-02 13:10:53.676164+02
4	b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7	CASHOUT_SWAP_FEE	10.00000000	BWP	TEST_BANK_A	TEST_BANK_B	{"vouchmorph": 4.00, "source_participant": 2.00, "destination_participant": 4.00}	1.40000000	COLLECTED	2026-03-01 13:10:53.676164+02	\N	2026-03-02 13:10:53.676164+02	2026-03-02 13:10:53.676164+02
5	c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8	DEPOSIT_SWAP_FEE	6.00000000	BWP	SACCUSSALIS	ZURUBANK	{"vouchmorph": 2.40, "source_participant": 1.20, "destination_participant": 2.40}	0.84000000	COLLECTED	2026-03-02 01:10:53.676164+02	\N	2026-03-02 13:10:53.676164+02	2026-03-02 13:10:53.676164+02
12	swap_b8a2fb2b-276f-483b-9a25-d97479c0e288	CASHOUT_SWAP_FEE	10.00000000	BWP	ZURUBANK	SACCUSSALIS	{"vouchmorph": 4.00, "source_participant": 2.00, "destination_participant": 4.00}	1.40000000	COLLECTED	2026-03-02 11:35:12.688858+02	\N	2026-03-02 13:35:12.688858+02	2026-03-02 13:35:12.688858+02
13	swap_48287a2c-16f0-43c6-a7c2-d58d1a57116f	CASHOUT_SWAP_FEE	10.00000000	BWP	TEST_BANK_A	TEST_BANK_B	{"vouchmorph": 4.00, "source_participant": 2.00, "destination_participant": 4.00}	1.40000000	COLLECTED	2026-03-02 08:35:12.688858+02	\N	2026-03-02 13:35:12.688858+02	2026-03-02 13:35:12.688858+02
14	ee1c9f897a174639e584727e51268555	DEPOSIT_SWAP_FEE	6.00000000	BWP	SACCUSSALIS	ZURUBANK	{"vouchmorph": 2.40, "source_participant": 1.20, "destination_participant": 2.40}	0.84000000	COLLECTED	2026-03-02 01:35:12.688858+02	\N	2026-03-02 13:35:12.688858+02	2026-03-02 13:35:12.688858+02
\.


--
-- Data for Name: swap_ledgers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_ledgers (ledger_id, swap_reference, from_institution, to_institution, amount, currency_code, swap_fee, status, created_at) FROM stdin;
1	f0191648e17fd091eb2096d8dd4bd132	SACCUSSALIS	ZURUBANK	100.00	BWP	4.00	pending	2026-02-28 13:56:37.710811
2	7847ef137e2e8fe4560717e4a416e21f	SACCUSSALIS	ZURUBANK	100.00	BWP	4.00	pending	2026-02-28 14:06:05.673521
3	805a45ee0bf22b8cd566c778ce106c81	SACCUSSALIS	ZURUBANK	100.00	BWP	4.00	pending	2026-02-28 15:52:47.445708
9	3145a5cc507709e58a4767f6c7499984	ZURUBANK	SACCUSSALIS	1500.00	BWP	32.00	pending	2026-03-02 08:16:32.599867
10	897ba2207e18cc592d82fffa28be41ff	ZURUBANK	SACCUSSALIS	1500.00	BWP	32.00	pending	2026-03-02 09:02:54.485004
11	8c4dc51e09119dd0ee509a31c4eb6070	SACCUSSALIS	ZURUBANK	100.00	BWP	4.00	pending	2026-03-02 09:04:22.822985
12	ee1c9f897a174639e584727e51268555	ZURUBANK	SACCUSSALIS	1500.00	BWP	10.00	pending	2026-03-02 11:26:27.733255
\.


--
-- Data for Name: swap_requests; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_requests (swap_id, swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at) FROM stdin;
20	2a1de08f8ee354fa765c4ecfaca3cd7f	BWP	BWP	50.00	{"reference": "EWL-70000000", "asset_type": "E-WALLET", "holder_name": "Saccus Salis Customer", "institution": "SACCUSSALIS", "available_balance": 998750}	{"asset_type": "ACCOUNT", "beneficiary": "1234567890", "institution": "ZURUBANK"}	pending	2026-02-28 13:52:52.109138
21	f0191648e17fd091eb2096d8dd4bd132	BWP	BWP	100.00	{"reference": "EWL-70000000", "asset_type": "E-WALLET", "holder_name": "Saccus Salis Customer", "institution": "SACCUSSALIS", "available_balance": 998750}	{"asset_type": "ATM", "beneficiary": "+26770000000", "institution": "ZURUBANK"}	pending	2026-02-28 13:56:37.710811
22	cfbe8dcc64f9371e0730a6ba8658b340	BWP	BWP	50.00	{"reference": "EWL-70000000", "asset_type": "E-WALLET", "holder_name": "Saccus Salis Customer", "institution": "SACCUSSALIS", "available_balance": 998750}	{"asset_type": "ACCOUNT", "beneficiary": "1234567890", "institution": "ZURUBANK"}	pending	2026-02-28 13:56:37.865983
24	7847ef137e2e8fe4560717e4a416e21f	BWP	BWP	100.00	{"reference": "EWL-70000000", "asset_type": "E-WALLET", "holder_name": "Saccus Salis Customer", "institution": "SACCUSSALIS", "available_balance": 998750}	{"asset_type": "ATM", "beneficiary": "+26770000000", "institution": "ZURUBANK"}	pending	2026-02-28 14:06:05.673521
25	c1853cebc78a0de4aabc93c671008af7	BWP	BWP	50.00	{"reference": "EWL-70000000", "asset_type": "E-WALLET", "holder_name": "Saccus Salis Customer", "institution": "SACCUSSALIS", "available_balance": 998750}	{"asset_type": "ACCOUNT", "beneficiary": "1234567890", "institution": "ZURUBANK"}	pending	2026-02-28 14:06:05.7859
34	805a45ee0bf22b8cd566c778ce106c81	BWP	BWP	100.00	{"reference": "EWL-70000000", "asset_type": "e-wallet", "holder_name": "Saccus Salis Customer", "institution": "SACCUSSALIS", "available_balance": 998750}	{"asset_type": "ATM", "beneficiary": "+26770000000", "institution": "ZURUBANK"}	pending	2026-02-28 15:52:47.445708
45	3145a5cc507709e58a4767f6c7499984	BWP	BWP	1500.00	{"reference": "VCH-5833", "asset_type": "VOUCHER", "holder_name": "Motho", "institution": "ZURUBANK", "available_balance": 1500}	{"asset_type": null, "beneficiary": "+26770000000", "institution": "SACCUSSALIS"}	pending	2026-03-02 08:16:32.599867
47	897ba2207e18cc592d82fffa28be41ff	BWP	BWP	1500.00	{"reference": "VCH-0565", "asset_type": "VOUCHER", "holder_name": "Motho", "institution": "ZURUBANK", "available_balance": 1500}	{"asset_type": null, "beneficiary": "+26770000000", "institution": "SACCUSSALIS"}	pending	2026-03-02 09:02:54.485004
48	8c4dc51e09119dd0ee509a31c4eb6070	BWP	BWP	100.00	{"reference": "EWL-70000000", "asset_type": "e-wallet", "holder_name": "Saccus Salis Customer", "institution": "SACCUSSALIS", "available_balance": 998750}	{"asset_type": "ATM", "beneficiary": "+26770000000", "institution": "ZURUBANK"}	pending	2026-03-02 09:04:22.822985
49	ee1c9f897a174639e584727e51268555	BWP	BWP	1500.00	{"reference": "VCH-1195", "asset_type": "VOUCHER", "holder_name": "Motho", "institution": "ZURUBANK", "available_balance": 1500}	{"asset_type": null, "beneficiary": "+26770000000", "institution": "SACCUSSALIS"}	pending	2026-03-02 11:26:27.733255
51	a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6	BWP	BWP	1500.00	{"reference": "VCH-1234", "asset_type": "VOUCHER", "holder_name": "Test User", "institution": "ZURUBANK", "available_balance": 1500.00}	{"asset_type": "ATM", "beneficiary": "+26770000000", "institution": "SACCUSSALIS"}	completed	2026-02-28 13:10:53.676164
52	b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7	BWP	BWP	2500.00	{"reference": "ACC-5678", "asset_type": "ACCOUNT", "holder_name": "Business User", "institution": "TEST_BANK_A", "available_balance": 2500.00}	{"asset_type": "WALLET", "beneficiary": "+26771111111", "institution": "TEST_BANK_B"}	completed	2026-03-01 13:10:53.676164
53	c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8	BWP	BWP	800.00	{"reference": "EWL-9012", "asset_type": "E-WALLET", "holder_name": "Wallet User", "institution": "SACCUSSALIS", "available_balance": 800.00}	{"asset_type": "DEPOSIT", "beneficiary": "+26772222222", "institution": "ZURUBANK"}	completed	2026-03-02 01:10:53.676164
63	swap_48287a2c-16f0-43c6-a7c2-d58d1a57116f	BWP	BWP	1500.00	{"reference": "VCH-1234", "asset_type": "VOUCHER", "institution": "ZURUBANK"}	{"asset_type": "ATM", "beneficiary": "+26770000000", "institution": "SACCUSSALIS"}	completed	2026-03-02 11:35:12.688858
64	swap_81b70ecb-0364-4c61-825d-d80dc86004a0	BWP	BWP	2500.00	{"reference": "ACC-5678", "asset_type": "ACCOUNT", "institution": "TEST_BANK_A"}	{"asset_type": "WALLET", "beneficiary": "+26771111111", "institution": "TEST_BANK_B"}	completed	2026-03-02 08:35:12.688858
65	swap_b8a2fb2b-276f-483b-9a25-d97479c0e288	BWP	BWP	800.00	{"reference": "EWL-9012", "asset_type": "E-WALLET", "institution": "SACCUSSALIS"}	{"asset_type": "DEPOSIT", "beneficiary": "+26772222222", "institution": "ZURUBANK"}	pending	2026-03-02 13:05:12.688858
\.


--
-- Data for Name: swap_transactions; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.swap_transactions (swap_transaction_id, swap_id, transaction_id, from_account_details, to_account_details, amount, ledger_entry_id, settlement_batch_id, status, error_message, retry_count, metadata, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: swap_vouchers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_vouchers (voucher_id, swap_id, code_hash, code_suffix, amount, expiry_at, status, claimant_phone, is_cardless_redemption, created_at) FROM stdin;
11	21	$2y$10$93FsEGK8rW0TRM15p9mQx.288gENLKWu8mtiXnTPBpuMG1XAmy0w.	8596	100.00	2026-03-01 12:56:37	ACTIVE	+26770000000	t	2026-02-28 13:56:37.710811
12	24	$2y$10$7p0MlKAsjJgq3i2TqylfieKrS0P9oHgImh0SJ5Sq4eRV4bBxevt5a	2824	100.00	2026-03-01 13:06:05	ACTIVE	+26770000000	t	2026-02-28 14:06:05.673521
13	34	$2y$10$Bv9pzGQd.kHdDCriUZuRnuJf3lAMuY3Cuvc5aAdUq3dr7Nanua6kq	1553	100.00	2026-03-01 14:52:47	ACTIVE	+26770000000	t	2026-02-28 15:52:47.445708
24	45	$2y$10$t/X7b0/SFv0RRUgrF/AlL.Ven3tEdXyDTiOa4TuoVr9hYF/U7ZT0u	6079	1500.00	2026-03-03 07:16:32	ACTIVE	+26770000000	t	2026-03-02 08:16:32.599867
26	47	$2y$10$rDmnGesABwfb3ibOGuvrY.TrDjHXj71EtUJ/EFDjQh3b0hcbfuvou	7845	1500.00	2026-03-03 08:02:54	ACTIVE	+26770000000	t	2026-03-02 09:02:54.485004
27	48	$2y$10$4nthay2iaEnE9VqGKmCxx.pIGNJ67Ms8uhes47zjOjlgnnPAbjY/a	6092	100.00	2026-03-03 08:04:22	ACTIVE	+26770000000	t	2026-03-02 09:04:22.822985
28	49	$2y$10$zc2IJUetwVh.MBQF/QdBw.mp9B2lC4KJMU2yvv4.vzgPIvJJ50Xa6	8888	1500.00	2026-03-03 10:26:28	ACTIVE	+26770000000	t	2026-03-02 11:26:27.733255
31	65	$2y$10$dd07e036bdd3d4110310	1234	1500.00	2026-03-03 13:35:12.688858	ACTIVE	+26770000000	t	2026-03-02 11:35:12.688858
32	63	$2y$10$c7ea7f3282fbc56ced15	5678	2500.00	2026-03-03 13:35:12.688858	ACTIVE	+26771111111	t	2026-03-02 08:35:12.688858
33	\N	$2y$10$iMhj9aLk3biscDV8s7Wm..PYY243uxsXr/uMfAHkmSEC.eNRMtFWC	1589	1500.00	2026-03-03 18:20:20.134866	ACTIVE	+26770000000	t	2026-03-02 18:20:20.134866
34	\N	$2y$10$uFfhe2gFVPhir/fCS7itQuMWGR44XxgKxOJGB5ETOdxgBQem0Zpy2	2453	1500.00	2026-03-03 19:30:31.66694	ACTIVE	+26770000000	t	2026-03-02 19:30:31.66694
\.


--
-- Data for Name: transaction_fees; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.transaction_fees (fee_id, transaction_type, amount, currency, split_config, taxable, created_at) FROM stdin;
1	CASHOUT	10.00	BWP	{"vouchmorph": 4.00, "source_participant": 2.00, "destination_participant": 4.00}	t	2026-02-22 17:55:06.669142
2	DEPOSIT	6.00	BWP	{"vouchmorph": 2.40, "source_participant": 1.20, "destination_participant": 2.40}	t	2026-02-22 17:55:06.669142
\.


--
-- Data for Name: transaction_splits; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.transaction_splits (split_id, transaction_id, split_type, amount, currency_code, credited_account, created_at) FROM stdin;
\.


--
-- Data for Name: transactions; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.transactions (transaction_id, transaction_type, amount, fee, sat_purchased, currency_code, status, sca_required, sca_verified_at, reference_uuid, created_at, updated_at, origin_participant_id, destination_participant_id, origin_name, destination_name) FROM stdin;
1	SWAP_TO_SWAP	487.40000000	0.00000000	0.00000000	BWP	PDNG	f	\N	1357509a-6e6b-46fe-bf20-15a427b979e4	2026-02-23 00:01:15.747261+02	2026-02-23 00:01:15.747261+02	\N	\N	\N	\N
2	DEPOSIT	487.40000000	0.00000000	0.00000000	BWP	PDNG	f	\N	102ed0d1-85ba-4295-9b55-5b2869f1af6e	2026-02-23 00:01:15.747261+02	2026-02-23 00:01:15.747261+02	\N	\N	\N	\N
6	INTER_PARTICIPANT_SETTLEMENT	487.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	30b78264-82b0-4be8-87ef-2587907e61c4	2026-02-23 00:11:33.590783+02	2026-02-23 00:11:33.590783+02	\N	\N	\N	\N
7	DEPOSIT	487.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	49cb7909-563b-4f58-9aac-0905153c79ae	2026-02-23 00:11:33.590783+02	2026-02-23 00:11:33.590783+02	\N	\N	\N	\N
8	INTER_PARTICIPANT_SETTLEMENT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	49f524b6-f922-4c76-a8b2-ec27d03a6b1d	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02	\N	\N	\N	\N
9	DEPOSIT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	ff093919-c313-4c32-ad6f-89147cc666c8	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02	\N	\N	\N	\N
10	INTER_PARTICIPANT_SETTLEMENT	250.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	2d03edca-7c23-4631-aca0-f04dc921e4b0	2026-02-23 00:11:33.60119+02	2026-02-23 00:11:33.60119+02	\N	\N	\N	\N
11	INTER_PARTICIPANT_SETTLEMENT	48749.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	6d287ee8-f2a9-40a1-84b4-56e7b450acd3	2026-02-23 15:11:03.219272+02	2026-02-23 15:11:03.219272+02	\N	\N	\N	\N
12	DEPOSIT	48749.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	a9a94cbd-c642-4e33-8338-7c4e8a0069c6	2026-02-23 15:11:03.219272+02	2026-02-23 15:11:03.219272+02	\N	\N	\N	\N
13	INTER_PARTICIPANT_SETTLEMENT	97.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	0c8c1e64-3ad8-499b-8005-53d55feba640	2026-02-23 15:20:29.727005+02	2026-02-23 15:20:29.727005+02	\N	\N	\N	\N
14	DEPOSIT	97.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	dcfc4e46-3c59-4aa5-aaee-e4de5b899cca	2026-02-23 15:20:29.727005+02	2026-02-23 15:20:29.727005+02	\N	\N	\N	\N
15	INTER_PARTICIPANT_SETTLEMENT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	b9718e38-ae5d-46a3-9a9a-0c037ed8c259	2026-02-23 15:22:33.106617+02	2026-02-23 15:22:33.106617+02	\N	\N	\N	\N
16	DEPOSIT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	0d34413d-b925-4012-8d3c-b24a1e10af24	2026-02-23 15:22:33.106617+02	2026-02-23 15:22:33.106617+02	\N	\N	\N	\N
17	INTER_PARTICIPANT_SETTLEMENT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	bc817d4f-29a8-46fd-bd25-a77396640c1d	2026-02-23 15:22:33.118678+02	2026-02-23 15:22:33.118678+02	\N	\N	\N	\N
18	DEPOSIT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	5698622d-a615-4b28-a080-372028f1ab82	2026-02-23 15:22:33.118678+02	2026-02-23 15:22:33.118678+02	\N	\N	\N	\N
19	INTER_PARTICIPANT_SETTLEMENT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	b560f145-2401-4a99-a427-c1b8c14dcf2a	2026-02-23 15:24:23.633775+02	2026-02-23 15:24:23.633775+02	\N	\N	\N	\N
20	DEPOSIT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	462463fb-1581-441f-a94e-b335f0d27652	2026-02-23 15:24:23.633775+02	2026-02-23 15:24:23.633775+02	\N	\N	\N	\N
21	INTER_PARTICIPANT_SETTLEMENT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	1cb685ed-fdd6-4f28-b8ff-f8642e6f23a0	2026-02-23 15:24:23.647304+02	2026-02-23 15:24:23.647304+02	\N	\N	\N	\N
22	DEPOSIT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	ad21a105-e627-4ca1-a4ad-275b936da6ea	2026-02-23 15:24:23.647304+02	2026-02-23 15:24:23.647304+02	\N	\N	\N	\N
23	INTER_PARTICIPANT_SETTLEMENT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	b1c64fef-4a03-446e-b986-dc39164ff4f3	2026-02-23 15:24:37.47646+02	2026-02-23 15:24:37.47646+02	\N	\N	\N	\N
24	DEPOSIT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	d10c191e-95c3-41e5-bf88-84d21017b646	2026-02-23 15:24:37.47646+02	2026-02-23 15:24:37.47646+02	\N	\N	\N	\N
25	INTER_PARTICIPANT_SETTLEMENT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	dfbdce8f-e3ee-4531-a06f-450fee15596d	2026-02-23 15:24:37.485972+02	2026-02-23 15:24:37.485972+02	\N	\N	\N	\N
26	DEPOSIT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	6a8110e8-9869-4a5a-a637-dfccf824636d	2026-02-23 15:24:37.485972+02	2026-02-23 15:24:37.485972+02	\N	\N	\N	\N
27	INTER_PARTICIPANT_SETTLEMENT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	b1a84532-b193-4136-b3fa-803e01b6cbed	2026-02-23 15:25:55.093399+02	2026-02-23 15:25:55.093399+02	\N	\N	\N	\N
28	DEPOSIT	974.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	e25d8199-2dea-484c-b6c9-12cffc8090e1	2026-02-23 15:25:55.093399+02	2026-02-23 15:25:55.093399+02	\N	\N	\N	\N
29	INTER_PARTICIPANT_SETTLEMENT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	713c1bf9-9aa2-4861-bde2-eba714db70f2	2026-02-23 15:25:55.107054+02	2026-02-23 15:25:55.107054+02	\N	\N	\N	\N
30	DEPOSIT	4874.50000000	0.00000000	0.00000000	BWP	PDNG	f	\N	277b6169-86dc-4745-9235-1d35b087edbf	2026-02-23 15:25:55.107054+02	2026-02-23 15:25:55.107054+02	\N	\N	\N	\N
31	INTER_PARTICIPANT_SETTLEMENT	100.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	9d46d09f-e599-4dd8-a034-aff0f806426a	2026-02-28 13:56:37.710811+02	2026-02-28 13:56:37.710811+02	\N	\N	\N	\N
32	INTER_PARTICIPANT_SETTLEMENT	100.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	f9f17327-3876-4b1c-afb5-58d0ae049314	2026-02-28 14:06:05.673521+02	2026-02-28 14:06:05.673521+02	\N	\N	\N	\N
33	INTER_PARTICIPANT_SETTLEMENT	100.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	22136d4e-7a1f-47b0-9f7a-39d027b11e0b	2026-02-28 15:52:47.445708+02	2026-02-28 15:52:47.445708+02	\N	\N	\N	\N
39	INTER_PARTICIPANT_SETTLEMENT	1500.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	4119d0af-8812-41d8-9233-a1cf334748fa	2026-03-02 08:16:32.599867+02	2026-03-02 08:16:32.599867+02	\N	\N	\N	\N
41	INTER_PARTICIPANT_SETTLEMENT	1500.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	c9da12ac-0f2c-41e9-8954-0b1b4c9d18ca	2026-03-02 09:02:54.485004+02	2026-03-02 09:02:54.485004+02	\N	\N	\N	\N
42	INTER_PARTICIPANT_SETTLEMENT	100.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	a59f2366-3920-452f-959f-4abf5cecc483	2026-03-02 09:04:22.822985+02	2026-03-02 09:04:22.822985+02	\N	\N	\N	\N
43	INTER_PARTICIPANT_SETTLEMENT	1490.00000000	0.00000000	0.00000000	BWP	PDNG	f	\N	35fc5a19-3dda-4423-b2bf-a4213ea5a64d	2026-03-02 11:26:27.733255+02	2026-03-02 11:26:27.733255+02	\N	\N	\N	\N
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: vouchmorphn_user
--

COPY public.users (user_id, username, email, phone, password_hash, role_id, verified, kyc_verified, aml_score, mfa_enabled, created_at, updated_at) FROM stdin;
1	test_user	test@vouchmorph.com	26771000000	$2y$10$DsVCTk96nFXmlSbULtPbO.MUt9u/zXaMJ/kOOn2OPiQ98BG5Lki2S	1	t	t	0.00	f	2026-02-23 00:01:15.678225+02	2026-02-23 00:01:15.678225+02
\.


--
-- Data for Name: vouchmorph_notifications; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.vouchmorph_notifications (id, swap_number, swap_pin, amount, user_phone, destination_bank_id, status, created_at, acknowledged_at) FROM stdin;
\.


--
-- Name: admins_admin_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.admins_admin_id_seq', 1, false);


--
-- Name: aml_checks_check_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.aml_checks_check_id_seq', 1, false);


--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.api_message_logs_log_id_seq', 88, true);


--
-- Name: audit_logs_audit_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.audit_logs_audit_id_seq', 74, true);


--
-- Name: cashout_authorizations_auth_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.cashout_authorizations_auth_id_seq', 1, false);


--
-- Name: deposit_transactions_deposit_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.deposit_transactions_deposit_id_seq', 1, false);


--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hold_transactions_hold_id_seq', 20, true);


--
-- Name: kyc_documents_kyc_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.kyc_documents_kyc_id_seq', 1, false);


--
-- Name: ledger_accounts_account_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.ledger_accounts_account_id_seq', 13, true);


--
-- Name: ledger_entries_entry_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.ledger_entries_entry_id_seq', 43, true);


--
-- Name: net_positions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.net_positions_id_seq', 11, true);


--
-- Name: otp_logs_otp_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.otp_logs_otp_id_seq', 1, false);


--
-- Name: participant_fee_overrides_override_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.participant_fee_overrides_override_id_seq', 2, true);


--
-- Name: participants_participant_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.participants_participant_id_seq', 6, true);


--
-- Name: regulator_outbox_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.regulator_outbox_id_seq', 41, true);


--
-- Name: roles_role_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.roles_role_id_seq', 10, true);


--
-- Name: sandbox_disclosures_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.sandbox_disclosures_id_seq', 1, false);


--
-- Name: send_to_other_transactions_send_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.send_to_other_transactions_send_id_seq', 1, false);


--
-- Name: settlement_messages_message_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.settlement_messages_message_id_seq', 21, true);


--
-- Name: settlement_queue_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.settlement_queue_id_seq', 10, true);


--
-- Name: supervisory_heartbeat_heartbeat_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.supervisory_heartbeat_heartbeat_id_seq', 2, true);


--
-- Name: swap_fee_collections_fee_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_fee_collections_fee_id_seq', 14, true);


--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_ledgers_ledger_id_seq', 12, true);


--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_requests_swap_id_seq', 65, true);


--
-- Name: swap_transactions_swap_transaction_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.swap_transactions_swap_transaction_id_seq', 1, false);


--
-- Name: swap_vouchers_voucher_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_vouchers_voucher_id_seq', 34, true);


--
-- Name: transaction_fees_fee_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.transaction_fees_fee_id_seq', 4, true);


--
-- Name: transaction_splits_split_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.transaction_splits_split_id_seq', 1, false);


--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.transactions_transaction_id_seq', 43, true);


--
-- Name: users_user_id_seq; Type: SEQUENCE SET; Schema: public; Owner: vouchmorphn_user
--

SELECT pg_catalog.setval('public.users_user_id_seq', 1, true);


--
-- Name: vouchmorph_notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.vouchmorph_notifications_id_seq', 1, false);


--
-- Name: admins admins_email_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_email_key UNIQUE (email);


--
-- Name: admins admins_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_pkey PRIMARY KEY (admin_id);


--
-- Name: admins admins_username_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_username_key UNIQUE (username);


--
-- Name: aml_checks aml_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.aml_checks
    ADD CONSTRAINT aml_checks_pkey PRIMARY KEY (check_id);


--
-- Name: api_message_logs api_message_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_message_logs
    ADD CONSTRAINT api_message_logs_pkey PRIMARY KEY (log_id);


--
-- Name: audit_logs audit_logs_audit_uuid_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_audit_uuid_key UNIQUE (audit_uuid);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (audit_id);


--
-- Name: cashout_authorizations cashout_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cashout_authorizations
    ADD CONSTRAINT cashout_authorizations_pkey PRIMARY KEY (auth_id);


--
-- Name: cashout_authorizations cashout_authorizations_swap_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cashout_authorizations
    ADD CONSTRAINT cashout_authorizations_swap_code_key UNIQUE (swap_code);


--
-- Name: cashout_authorizations cashout_authorizations_swap_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cashout_authorizations
    ADD CONSTRAINT cashout_authorizations_swap_reference_key UNIQUE (swap_reference);


--
-- Name: deposit_transactions deposit_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deposit_transactions
    ADD CONSTRAINT deposit_transactions_pkey PRIMARY KEY (deposit_id);


--
-- Name: deposit_transactions deposit_transactions_transaction_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deposit_transactions
    ADD CONSTRAINT deposit_transactions_transaction_reference_key UNIQUE (transaction_reference);


--
-- Name: hold_transactions hold_transactions_hold_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_hold_reference_key UNIQUE (hold_reference);


--
-- Name: hold_transactions hold_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_pkey PRIMARY KEY (hold_id);


--
-- Name: kyc_documents kyc_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT kyc_documents_pkey PRIMARY KEY (kyc_id);


--
-- Name: ledger_accounts ledger_accounts_account_code_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_account_code_key UNIQUE (account_code);


--
-- Name: ledger_accounts ledger_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_pkey PRIMARY KEY (account_id);


--
-- Name: ledger_entries ledger_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_pkey PRIMARY KEY (entry_id);


--
-- Name: message_outbox message_outbox_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.message_outbox
    ADD CONSTRAINT message_outbox_pkey PRIMARY KEY (message_id);


--
-- Name: net_positions net_positions_debtor_creditor_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.net_positions
    ADD CONSTRAINT net_positions_debtor_creditor_key UNIQUE (debtor, creditor);


--
-- Name: net_positions net_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.net_positions
    ADD CONSTRAINT net_positions_pkey PRIMARY KEY (id);


--
-- Name: otp_logs otp_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.otp_logs
    ADD CONSTRAINT otp_logs_pkey PRIMARY KEY (otp_id);


--
-- Name: participant_fee_overrides participant_fee_overrides_participant_id_transaction_type_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.participant_fee_overrides
    ADD CONSTRAINT participant_fee_overrides_participant_id_transaction_type_key UNIQUE (participant_id, transaction_type);


--
-- Name: participant_fee_overrides participant_fee_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.participant_fee_overrides
    ADD CONSTRAINT participant_fee_overrides_pkey PRIMARY KEY (override_id);


--
-- Name: participants participants_name_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.participants
    ADD CONSTRAINT participants_name_key UNIQUE (name);


--
-- Name: participants participants_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.participants
    ADD CONSTRAINT participants_pkey PRIMARY KEY (participant_id);


--
-- Name: regulator_outbox regulator_outbox_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.regulator_outbox
    ADD CONSTRAINT regulator_outbox_pkey PRIMARY KEY (id);


--
-- Name: regulator_outbox regulator_outbox_report_id_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.regulator_outbox
    ADD CONSTRAINT regulator_outbox_report_id_key UNIQUE (report_id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (role_id);


--
-- Name: roles roles_role_name_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_role_name_key UNIQUE (role_name);


--
-- Name: sandbox_disclosures sandbox_disclosures_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.sandbox_disclosures
    ADD CONSTRAINT sandbox_disclosures_pkey PRIMARY KEY (id);


--
-- Name: send_to_other_transactions send_to_other_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.send_to_other_transactions
    ADD CONSTRAINT send_to_other_transactions_pkey PRIMARY KEY (send_id);


--
-- Name: send_to_other_transactions send_to_other_transactions_transaction_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.send_to_other_transactions
    ADD CONSTRAINT send_to_other_transactions_transaction_reference_key UNIQUE (transaction_reference);


--
-- Name: settlement_messages settlement_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_messages
    ADD CONSTRAINT settlement_messages_pkey PRIMARY KEY (message_id);


--
-- Name: settlement_queue settlement_queue_debtor_creditor_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.settlement_queue
    ADD CONSTRAINT settlement_queue_debtor_creditor_key UNIQUE (debtor, creditor);


--
-- Name: settlement_queue settlement_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.settlement_queue
    ADD CONSTRAINT settlement_queue_pkey PRIMARY KEY (id);


--
-- Name: supervisory_heartbeat supervisory_heartbeat_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.supervisory_heartbeat
    ADD CONSTRAINT supervisory_heartbeat_pkey PRIMARY KEY (heartbeat_id);


--
-- Name: swap_fee_collections swap_fee_collections_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_fee_collections
    ADD CONSTRAINT swap_fee_collections_pkey PRIMARY KEY (fee_id);


--
-- Name: swap_ledgers swap_ledgers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers
    ADD CONSTRAINT swap_ledgers_pkey PRIMARY KEY (ledger_id);


--
-- Name: swap_requests swap_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_requests
    ADD CONSTRAINT swap_requests_pkey PRIMARY KEY (swap_id);


--
-- Name: swap_requests swap_requests_swap_uuid_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_requests
    ADD CONSTRAINT swap_requests_swap_uuid_key UNIQUE (swap_uuid);


--
-- Name: swap_transactions swap_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_pkey PRIMARY KEY (swap_transaction_id);


--
-- Name: swap_vouchers swap_vouchers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_vouchers
    ADD CONSTRAINT swap_vouchers_pkey PRIMARY KEY (voucher_id);


--
-- Name: transaction_fees transaction_fees_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transaction_fees
    ADD CONSTRAINT transaction_fees_pkey PRIMARY KEY (fee_id);


--
-- Name: transaction_fees transaction_fees_transaction_type_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transaction_fees
    ADD CONSTRAINT transaction_fees_transaction_type_key UNIQUE (transaction_type);


--
-- Name: transaction_splits transaction_splits_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transaction_splits
    ADD CONSTRAINT transaction_splits_pkey PRIMARY KEY (split_id);


--
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (transaction_id);


--
-- Name: transactions transactions_reference_uuid_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_reference_uuid_key UNIQUE (reference_uuid);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_phone_key; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_phone_key UNIQUE (phone);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: vouchmorph_notifications vouchmorph_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vouchmorph_notifications
    ADD CONSTRAINT vouchmorph_notifications_pkey PRIMARY KEY (id);


--
-- Name: idx_api_logs_created; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_created ON public.api_message_logs USING btree (created_at);


--
-- Name: idx_api_logs_message_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_message_id ON public.api_message_logs USING btree (message_id);


--
-- Name: idx_api_logs_participant; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_participant ON public.api_message_logs USING btree (participant_id);


--
-- Name: idx_api_logs_success; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_success ON public.api_message_logs USING btree (success) WHERE (success = false);


--
-- Name: idx_api_logs_type; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_type ON public.api_message_logs USING btree (message_type);


--
-- Name: idx_cashout_code; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_cashout_code ON public.cashout_authorizations USING btree (swap_code) WHERE ((status)::text = 'ACTIVE'::text);


--
-- Name: idx_cashout_expiry; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_cashout_expiry ON public.cashout_authorizations USING btree (code_expiry) WHERE ((status)::text = 'ACTIVE'::text);


--
-- Name: idx_cashout_phone; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_cashout_phone ON public.cashout_authorizations USING btree (client_phone, created_at);


--
-- Name: idx_cashout_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_cashout_status ON public.cashout_authorizations USING btree (status);


--
-- Name: idx_deposit_created; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deposit_created ON public.deposit_transactions USING btree (created_at);


--
-- Name: idx_deposit_phone; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deposit_phone ON public.deposit_transactions USING btree (client_phone, created_at);


--
-- Name: idx_deposit_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deposit_status ON public.deposit_transactions USING btree (status);


--
-- Name: idx_fee_collections_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fee_collections_date ON public.swap_fee_collections USING btree (collected_at);


--
-- Name: idx_fee_collections_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fee_collections_status ON public.swap_fee_collections USING btree (status);


--
-- Name: idx_fee_collections_swap_ref; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fee_collections_swap_ref ON public.swap_fee_collections USING btree (swap_reference);


--
-- Name: idx_holds_reference; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holds_reference ON public.hold_transactions USING btree (hold_reference);


--
-- Name: idx_holds_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holds_status ON public.hold_transactions USING btree (status);


--
-- Name: idx_holds_swap; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holds_swap ON public.hold_transactions USING btree (swap_reference);


--
-- Name: idx_message_outbox_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_message_outbox_status ON public.message_outbox USING btree (status);


--
-- Name: idx_net_positions_creditor; Type: INDEX; Schema: public; Owner: vouchmorphn_user
--

CREATE INDEX idx_net_positions_creditor ON public.net_positions USING btree (creditor);


--
-- Name: idx_net_positions_debtor; Type: INDEX; Schema: public; Owner: vouchmorphn_user
--

CREATE INDEX idx_net_positions_debtor ON public.net_positions USING btree (debtor);


--
-- Name: idx_send_receiver; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_send_receiver ON public.send_to_other_transactions USING btree (receiver_phone, created_at);


--
-- Name: idx_send_sender; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_send_sender ON public.send_to_other_transactions USING btree (sender_phone, created_at);


--
-- Name: idx_send_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_send_status ON public.send_to_other_transactions USING btree (status);


--
-- Name: idx_settlement_messages_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_settlement_messages_status ON public.settlement_messages USING btree (status);


--
-- Name: idx_settlement_queue_created; Type: INDEX; Schema: public; Owner: vouchmorphn_user
--

CREATE INDEX idx_settlement_queue_created ON public.settlement_queue USING btree (created_at);


--
-- Name: idx_swap_requests_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_swap_requests_status ON public.swap_requests USING btree (status);


--
-- Name: idx_swap_requests_uuid; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_swap_requests_uuid ON public.swap_requests USING btree (swap_uuid);


--
-- Name: idx_swap_vouchers_phone; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_swap_vouchers_phone ON public.swap_vouchers USING btree (claimant_phone);


--
-- Name: idx_swap_vouchers_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_swap_vouchers_status ON public.swap_vouchers USING btree (status);


--
-- Name: admins trg_admins_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_admins_updated BEFORE UPDATE ON public.admins FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: aml_checks trg_aml_checks_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_aml_checks_updated BEFORE UPDATE ON public.aml_checks FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: audit_logs trg_audit_logs_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_audit_logs_updated BEFORE UPDATE ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: kyc_documents trg_kyc_documents_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_kyc_documents_updated BEFORE UPDATE ON public.kyc_documents FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: ledger_accounts trg_ledger_accounts_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_ledger_accounts_updated BEFORE UPDATE ON public.ledger_accounts FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: ledger_entries trg_ledger_entries_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_ledger_entries_updated BEFORE UPDATE ON public.ledger_entries FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: otp_logs trg_otp_logs_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_otp_logs_updated BEFORE UPDATE ON public.otp_logs FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: regulator_outbox trg_regulator_outbox_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_regulator_outbox_updated BEFORE UPDATE ON public.regulator_outbox FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: roles trg_roles_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_roles_updated BEFORE UPDATE ON public.roles FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: sandbox_disclosures trg_sandbox_disclosures_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_sandbox_disclosures_updated BEFORE UPDATE ON public.sandbox_disclosures FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: supervisory_heartbeat trg_supervisory_heartbeat_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_supervisory_heartbeat_updated BEFORE UPDATE ON public.supervisory_heartbeat FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: swap_transactions trg_swap_transactions_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_swap_transactions_updated BEFORE UPDATE ON public.swap_transactions FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: transaction_splits trg_transaction_splits_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_transaction_splits_updated BEFORE UPDATE ON public.transaction_splits FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: transactions trg_transactions_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_transactions_updated BEFORE UPDATE ON public.transactions FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: users trg_users_updated; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER trg_users_updated BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.fn_update_timestamp();


--
-- Name: admins update_admins_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_admins_updated_at BEFORE UPDATE ON public.admins FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: hold_transactions update_hold_transactions_updated_at; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER update_hold_transactions_updated_at BEFORE UPDATE ON public.hold_transactions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: kyc_documents update_kyc_documents_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_kyc_documents_updated_at BEFORE UPDATE ON public.kyc_documents FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: ledger_accounts update_ledger_accounts_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_ledger_accounts_updated_at BEFORE UPDATE ON public.ledger_accounts FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: ledger_entries update_ledger_entries_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_ledger_entries_updated_at BEFORE UPDATE ON public.ledger_entries FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: net_positions update_net_positions_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_net_positions_updated_at BEFORE UPDATE ON public.net_positions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: participants update_participants_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_participants_updated_at BEFORE UPDATE ON public.participants FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: roles update_roles_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_roles_updated_at BEFORE UPDATE ON public.roles FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: settlement_queue update_settlement_queue_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_settlement_queue_updated_at BEFORE UPDATE ON public.settlement_queue FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: swap_fee_collections update_swap_fee_collections_updated_at; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER update_swap_fee_collections_updated_at BEFORE UPDATE ON public.swap_fee_collections FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: swap_transactions update_swap_transactions_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_swap_transactions_updated_at BEFORE UPDATE ON public.swap_transactions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: transactions update_transactions_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_transactions_updated_at BEFORE UPDATE ON public.transactions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: users update_users_updated_at; Type: TRIGGER; Schema: public; Owner: vouchmorphn_user
--

CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: admins admins_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(role_id);


--
-- Name: aml_checks aml_checks_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.aml_checks
    ADD CONSTRAINT aml_checks_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: api_message_logs api_message_logs_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_message_logs
    ADD CONSTRAINT api_message_logs_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id);


--
-- Name: hold_transactions hold_transactions_destination_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_destination_participant_id_fkey FOREIGN KEY (destination_participant_id) REFERENCES public.participants(participant_id);


--
-- Name: hold_transactions hold_transactions_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id);


--
-- Name: kyc_documents kyc_documents_admin_reviewer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT kyc_documents_admin_reviewer_id_fkey FOREIGN KEY (admin_reviewer_id) REFERENCES public.admins(admin_id);


--
-- Name: kyc_documents kyc_documents_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.kyc_documents
    ADD CONSTRAINT kyc_documents_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: ledger_entries ledger_entries_credit_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_credit_account_id_fkey FOREIGN KEY (credit_account_id) REFERENCES public.ledger_accounts(account_id);


--
-- Name: ledger_entries ledger_entries_debit_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_debit_account_id_fkey FOREIGN KEY (debit_account_id) REFERENCES public.ledger_accounts(account_id);


--
-- Name: ledger_entries ledger_entries_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.ledger_entries
    ADD CONSTRAINT ledger_entries_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transaction_id);


--
-- Name: participant_fee_overrides participant_fee_overrides_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.participant_fee_overrides
    ADD CONSTRAINT participant_fee_overrides_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id) ON DELETE CASCADE;


--
-- Name: sandbox_disclosures sandbox_disclosures_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.sandbox_disclosures
    ADD CONSTRAINT sandbox_disclosures_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: swap_transactions swap_transactions_ledger_entry_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_ledger_entry_id_fkey FOREIGN KEY (ledger_entry_id) REFERENCES public.ledger_entries(entry_id);


--
-- Name: swap_transactions swap_transactions_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transaction_id);


--
-- Name: swap_vouchers swap_vouchers_swap_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_vouchers
    ADD CONSTRAINT swap_vouchers_swap_id_fkey FOREIGN KEY (swap_id) REFERENCES public.swap_requests(swap_id);


--
-- Name: transaction_splits transaction_splits_credited_account_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transaction_splits
    ADD CONSTRAINT transaction_splits_credited_account_fkey FOREIGN KEY (credited_account) REFERENCES public.ledger_accounts(account_id);


--
-- Name: transaction_splits transaction_splits_transaction_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.transaction_splits
    ADD CONSTRAINT transaction_splits_transaction_id_fkey FOREIGN KEY (transaction_id) REFERENCES public.transactions(transaction_id);


--
-- Name: users users_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: vouchmorphn_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(role_id);


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: postgres
--

REVOKE USAGE ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO PUBLIC;
GRANT USAGE ON SCHEMA public TO vouchmorphn_user;


--
-- Name: FUNCTION armor(bytea); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.armor(bytea) TO vouchmorphn_user;


--
-- Name: FUNCTION armor(bytea, text[], text[]); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.armor(bytea, text[], text[]) TO vouchmorphn_user;


--
-- Name: FUNCTION crypt(text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.crypt(text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION dearmor(text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.dearmor(text) TO vouchmorphn_user;


--
-- Name: FUNCTION decrypt(bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.decrypt(bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION decrypt_iv(bytea, bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.decrypt_iv(bytea, bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION digest(bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.digest(bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION digest(text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.digest(text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION encrypt(bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.encrypt(bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION encrypt_iv(bytea, bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.encrypt_iv(bytea, bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION fn_update_timestamp(); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.fn_update_timestamp() TO vouchmorphn_user;


--
-- Name: FUNCTION gen_random_bytes(integer); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.gen_random_bytes(integer) TO vouchmorphn_user;


--
-- Name: FUNCTION gen_random_uuid(); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.gen_random_uuid() TO vouchmorphn_user;


--
-- Name: FUNCTION gen_salt(text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.gen_salt(text) TO vouchmorphn_user;


--
-- Name: FUNCTION gen_salt(text, integer); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.gen_salt(text, integer) TO vouchmorphn_user;


--
-- Name: FUNCTION hmac(bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.hmac(bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION hmac(text, text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.hmac(text, text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION load_participants_from_json(json_file_path text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.load_participants_from_json(json_file_path text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_armor_headers(text, OUT key text, OUT value text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_armor_headers(text, OUT key text, OUT value text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_key_id(bytea); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_key_id(bytea) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_decrypt(bytea, bytea); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_decrypt(bytea, bytea) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_decrypt(bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_decrypt(bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_decrypt(bytea, bytea, text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_decrypt(bytea, bytea, text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_decrypt_bytea(bytea, bytea); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_decrypt_bytea(bytea, bytea) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_decrypt_bytea(bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_decrypt_bytea(bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_decrypt_bytea(bytea, bytea, text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_decrypt_bytea(bytea, bytea, text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_encrypt(text, bytea); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_encrypt(text, bytea) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_encrypt(text, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_encrypt(text, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_encrypt_bytea(bytea, bytea); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_encrypt_bytea(bytea, bytea) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_pub_encrypt_bytea(bytea, bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_pub_encrypt_bytea(bytea, bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_decrypt(bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_decrypt(bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_decrypt(bytea, text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_decrypt(bytea, text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_decrypt_bytea(bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_decrypt_bytea(bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_decrypt_bytea(bytea, text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_decrypt_bytea(bytea, text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_encrypt(text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_encrypt(text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_encrypt(text, text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_encrypt(text, text, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_encrypt_bytea(bytea, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_encrypt_bytea(bytea, text) TO vouchmorphn_user;


--
-- Name: FUNCTION pgp_sym_encrypt_bytea(bytea, text, text); Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON FUNCTION public.pgp_sym_encrypt_bytea(bytea, text, text) TO vouchmorphn_user;


--
-- Name: TABLE api_message_logs; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.api_message_logs TO PUBLIC;
GRANT ALL ON TABLE public.api_message_logs TO vouchmorphn_user;


--
-- Name: SEQUENCE api_message_logs_log_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.api_message_logs_log_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.api_message_logs_log_id_seq TO vouchmorphn_user;


--
-- Name: TABLE cashout_authorizations; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.cashout_authorizations TO PUBLIC;
GRANT ALL ON TABLE public.cashout_authorizations TO vouchmorphn_user;


--
-- Name: SEQUENCE cashout_authorizations_auth_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.cashout_authorizations_auth_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.cashout_authorizations_auth_id_seq TO vouchmorphn_user;


--
-- Name: TABLE deposit_transactions; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.deposit_transactions TO PUBLIC;
GRANT ALL ON TABLE public.deposit_transactions TO vouchmorphn_user;


--
-- Name: SEQUENCE deposit_transactions_deposit_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.deposit_transactions_deposit_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.deposit_transactions_deposit_id_seq TO vouchmorphn_user;


--
-- Name: TABLE hold_transactions; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.hold_transactions TO PUBLIC;
GRANT ALL ON TABLE public.hold_transactions TO vouchmorphn_user;


--
-- Name: SEQUENCE hold_transactions_hold_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.hold_transactions_hold_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.hold_transactions_hold_id_seq TO vouchmorphn_user;


--
-- Name: TABLE message_outbox; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.message_outbox TO PUBLIC;
GRANT ALL ON TABLE public.message_outbox TO vouchmorphn_user;


--
-- Name: TABLE participant_fee_overrides; Type: ACL; Schema: public; Owner: vouchmorphn_user
--

GRANT ALL ON TABLE public.participant_fee_overrides TO PUBLIC;


--
-- Name: SEQUENCE participant_fee_overrides_override_id_seq; Type: ACL; Schema: public; Owner: vouchmorphn_user
--

GRANT ALL ON SEQUENCE public.participant_fee_overrides_override_id_seq TO PUBLIC;


--
-- Name: TABLE participants; Type: ACL; Schema: public; Owner: vouchmorphn_user
--

GRANT ALL ON TABLE public.participants TO PUBLIC;


--
-- Name: SEQUENCE participants_participant_id_seq; Type: ACL; Schema: public; Owner: vouchmorphn_user
--

GRANT ALL ON SEQUENCE public.participants_participant_id_seq TO PUBLIC;


--
-- Name: TABLE send_to_other_transactions; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.send_to_other_transactions TO PUBLIC;
GRANT ALL ON TABLE public.send_to_other_transactions TO vouchmorphn_user;


--
-- Name: SEQUENCE send_to_other_transactions_send_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.send_to_other_transactions_send_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.send_to_other_transactions_send_id_seq TO vouchmorphn_user;


--
-- Name: TABLE settlement_messages; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.settlement_messages TO PUBLIC;
GRANT ALL ON TABLE public.settlement_messages TO vouchmorphn_user;


--
-- Name: SEQUENCE settlement_messages_message_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.settlement_messages_message_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.settlement_messages_message_id_seq TO vouchmorphn_user;


--
-- Name: TABLE settlement_queue; Type: ACL; Schema: public; Owner: vouchmorphn_user
--

GRANT ALL ON TABLE public.settlement_queue TO PUBLIC;


--
-- Name: SEQUENCE settlement_queue_id_seq; Type: ACL; Schema: public; Owner: vouchmorphn_user
--

GRANT ALL ON SEQUENCE public.settlement_queue_id_seq TO PUBLIC;


--
-- Name: TABLE swap_fee_collections; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.swap_fee_collections TO PUBLIC;
GRANT ALL ON TABLE public.swap_fee_collections TO vouchmorphn_user;


--
-- Name: SEQUENCE swap_fee_collections_fee_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.swap_fee_collections_fee_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.swap_fee_collections_fee_id_seq TO vouchmorphn_user;


--
-- Name: TABLE swap_ledgers; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.swap_ledgers TO PUBLIC;
GRANT ALL ON TABLE public.swap_ledgers TO vouchmorphn_user;


--
-- Name: SEQUENCE swap_ledgers_ledger_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.swap_ledgers_ledger_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.swap_ledgers_ledger_id_seq TO vouchmorphn_user;


--
-- Name: TABLE swap_requests; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.swap_requests TO PUBLIC;
GRANT ALL ON TABLE public.swap_requests TO vouchmorphn_user;


--
-- Name: SEQUENCE swap_requests_swap_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.swap_requests_swap_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.swap_requests_swap_id_seq TO vouchmorphn_user;


--
-- Name: TABLE swap_vouchers; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.swap_vouchers TO PUBLIC;
GRANT ALL ON TABLE public.swap_vouchers TO vouchmorphn_user;


--
-- Name: SEQUENCE swap_vouchers_voucher_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.swap_vouchers_voucher_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.swap_vouchers_voucher_id_seq TO vouchmorphn_user;


--
-- Name: TABLE transaction_log_view; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.transaction_log_view TO PUBLIC;
GRANT ALL ON TABLE public.transaction_log_view TO vouchmorphn_user;


--
-- Name: TABLE vouchmorph_notifications; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.vouchmorph_notifications TO PUBLIC;
GRANT ALL ON TABLE public.vouchmorph_notifications TO vouchmorphn_user;


--
-- Name: SEQUENCE vouchmorph_notifications_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.vouchmorph_notifications_id_seq TO PUBLIC;
GRANT ALL ON SEQUENCE public.vouchmorph_notifications_id_seq TO vouchmorphn_user;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES TO PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES TO vouchmorphn_user;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON FUNCTIONS TO vouchmorphn_user;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES TO PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES TO vouchmorphn_user;


--
-- PostgreSQL database dump complete
--

\unrestrict 7Xi944aMjZXaAsZPSSefi8eK4Kur0wCOkcDId6HGfGyDNI4bmIFIvmubJ8Segba

