<?php
/**
 * Core schema for the Sweet Sen MVP. Mirrors the Architecture Pack table reference.
 * Ids are UUIDv7 in BINARY(16). Money is DECIMAL(12,2). Times are UTC DATETIME(6).
 * Foreign keys use ON DELETE RESTRICT (the default); nothing financial or audited cascades.
 */

declare(strict_types=1);

return [
    'version' => '0001_core',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        $tenant = 'tenant_id BINARY(16) NOT NULL';
        $ts = 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NULL';

        return [
            "CREATE TABLE {$p}tenants (
                {$id}, slug VARCHAR(64) NOT NULL, name VARCHAR(191) NOT NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila', {$ts},
                UNIQUE KEY uq_tenant_slug (slug)
            ) {$o}",

            "CREATE TABLE {$p}sequences (
                {$tenant}, name VARCHAR(32) NOT NULL, value BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (tenant_id, name),
                CONSTRAINT fk_seq_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
            ) {$o}",

            "CREATE TABLE {$p}organizations (
                {$id}, {$tenant}, name VARCHAR(191) NOT NULL, type VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                contact_name VARCHAR(191) NULL, contact_email VARCHAR(191) NULL,
                submitted_by BIGINT UNSIGNED NULL, verified_by BIGINT UNSIGNED NULL, verified_at DATETIME(6) NULL, {$ts},
                UNIQUE KEY uq_org_name (tenant_id, name),
                CONSTRAINT fk_org_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
            ) {$o}",

            "CREATE TABLE {$p}memberships (
                {$id}, {$tenant}, user_id BIGINT UNSIGNED NOT NULL, role VARCHAR(32) NOT NULL,
                organization_id BINARY(16) NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', {$ts},
                UNIQUE KEY uq_membership (tenant_id, user_id), KEY ix_membership_user (user_id),
                CONSTRAINT fk_mem_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_mem_org FOREIGN KEY (organization_id) REFERENCES {$p}organizations(id)
            ) {$o}",

            "CREATE TABLE {$p}import_jobs (
                {$id}, {$tenant}, filename VARCHAR(191) NOT NULL, sha256 CHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'running',
                total_rows INT UNSIGNED NOT NULL DEFAULT 0, created_count INT UNSIGNED NOT NULL DEFAULT 0,
                flagged_count INT UNSIGNED NOT NULL DEFAULT 0, error_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL, finished_at DATETIME(6) NULL,
                KEY ix_import_tenant (tenant_id, created_at),
                CONSTRAINT fk_import_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
            ) {$o}",

            "CREATE TABLE {$p}import_rows (
                {$id}, {$tenant}, job_id BINARY(16) NOT NULL, row_no INT UNSIGNED NOT NULL,
                payload_json LONGTEXT NOT NULL, outcome VARCHAR(16) NOT NULL,
                match_beneficiary_id BINARY(16) NULL, match_reason VARCHAR(64) NULL,
                beneficiary_id BINARY(16) NULL, message VARCHAR(255) NULL,
                resolved_by BIGINT UNSIGNED NULL, resolved_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_import_row (job_id, row_no), KEY ix_import_outcome (tenant_id, outcome),
                CONSTRAINT fk_irow_job FOREIGN KEY (job_id) REFERENCES {$p}import_jobs(id)
            ) {$o}",

            "CREATE TABLE {$p}beneficiaries (
                {$id}, {$tenant}, ref_no VARCHAR(20) NOT NULL, source_ref VARCHAR(40) NULL,
                user_id BIGINT UNSIGNED NULL,
                last_name VARCHAR(100) NOT NULL, first_name VARCHAR(100) NOT NULL, middle_name VARCHAR(100) NULL,
                suffix VARCHAR(10) NULL, sex CHAR(1) NULL, birthdate DATE NOT NULL,
                barangay VARCHAR(100) NOT NULL, address_line VARCHAR(255) NULL, contact_phone VARCHAR(20) NULL,
                osca_id VARCHAR(40) NULL,
                verification_status VARCHAR(16) NOT NULL DEFAULT 'unverified',
                verified_by BIGINT UNSIGNED NULL, verified_at DATETIME(6) NULL,
                import_row_id BINARY(16) NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', {$ts},
                UNIQUE KEY uq_ben_ref (tenant_id, ref_no),
                UNIQUE KEY uq_ben_osca (tenant_id, osca_id),
                UNIQUE KEY uq_ben_user (user_id),
                KEY ix_ben_name_dob (tenant_id, last_name, birthdate),
                KEY ix_ben_dob (tenant_id, birthdate),
                KEY ix_ben_status (tenant_id, verification_status),
                CONSTRAINT fk_ben_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_ben_import_row FOREIGN KEY (import_row_id) REFERENCES {$p}import_rows(id)
            ) {$o}",

            // At most one active credential per senior: active_for is the senior id only while active.
            "CREATE TABLE {$p}qr_credentials (
                {$id}, {$tenant}, beneficiary_id BINARY(16) NOT NULL, token_hash CHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                active_for BINARY(16) AS (IF(status = 'active', beneficiary_id, NULL)) PERSISTENT,
                issued_by BIGINT UNSIGNED NOT NULL, issued_at DATETIME(6) NOT NULL,
                revoked_by BIGINT UNSIGNED NULL, revoked_at DATETIME(6) NULL, revoke_reason VARCHAR(191) NULL,
                UNIQUE KEY uq_qr_token (token_hash), UNIQUE KEY uq_qr_one_active (active_for),
                KEY ix_qr_ben (beneficiary_id),
                CONSTRAINT fk_qr_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_qr_ben FOREIGN KEY (beneficiary_id) REFERENCES {$p}beneficiaries(id)
            ) {$o}",

            "CREATE TABLE {$p}documents (
                {$id}, {$tenant}, owner_type VARCHAR(32) NOT NULL, owner_id BINARY(16) NOT NULL,
                storage_key VARCHAR(191) NOT NULL, original_name VARCHAR(191) NULL, mime VARCHAR(100) NOT NULL,
                bytes BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL,
                uploaded_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_doc_key (storage_key), KEY ix_doc_owner (tenant_id, owner_type, owner_id),
                CONSTRAINT fk_doc_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
            ) {$o}",

            "CREATE TABLE {$p}programs (
                {$id}, {$tenant}, code VARCHAR(32) NOT NULL, name VARCHAR(191) NOT NULL, category VARCHAR(48) NOT NULL,
                description TEXT NULL, benefit_type VARCHAR(16) NOT NULL,
                unit_amount DECIMAL(12,2) NULL, currency CHAR(3) NOT NULL DEFAULT 'PHP',
                unit_quantity INT UNSIGNED NULL, unit_label VARCHAR(32) NULL,
                claim_limit INT UNSIGNED NOT NULL DEFAULT 1, claim_period VARCHAR(16) NOT NULL DEFAULT 'once',
                starts_on DATE NOT NULL, ends_on DATE NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft',
                rules_version INT UNSIGNED NOT NULL DEFAULT 1, rules_json LONGTEXT NULL,
                funding_source VARCHAR(16) NOT NULL DEFAULT 'lgu', {$ts},
                UNIQUE KEY uq_program_code (tenant_id, code),
                CONSTRAINT fk_prog_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
            ) {$o}",

            "CREATE TABLE {$p}applications (
                {$id}, {$tenant}, ref_no VARCHAR(20) NOT NULL,
                beneficiary_id BINARY(16) NOT NULL, program_id BINARY(16) NOT NULL, rules_version INT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL, submitted_by BIGINT UNSIGNED NOT NULL, submitted_at DATETIME(6) NULL,
                decided_by BIGINT UNSIGNED NULL, decided_at DATETIME(6) NULL, decision_note TEXT NULL, {$ts},
                UNIQUE KEY uq_app_ref (tenant_id, ref_no), KEY ix_app_status (tenant_id, status),
                KEY ix_app_ben (beneficiary_id),
                CONSTRAINT fk_app_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_app_ben FOREIGN KEY (beneficiary_id) REFERENCES {$p}beneficiaries(id),
                CONSTRAINT fk_app_prog FOREIGN KEY (program_id) REFERENCES {$p}programs(id)
            ) {$o}",

            "CREATE TABLE {$p}application_events (
                {$id}, {$tenant}, application_id BINARY(16) NOT NULL,
                from_status VARCHAR(16) NULL, to_status VARCHAR(16) NOT NULL,
                actor_user_id BIGINT UNSIGNED NOT NULL, note TEXT NULL, created_at DATETIME(6) NOT NULL,
                KEY ix_event_app (application_id, created_at),
                CONSTRAINT fk_event_app FOREIGN KEY (application_id) REFERENCES {$p}applications(id)
            ) {$o}",

            // Claim limit: one entitlement per senior, program and period.
            "CREATE TABLE {$p}entitlements (
                {$id}, {$tenant}, application_id BINARY(16) NOT NULL,
                beneficiary_id BINARY(16) NOT NULL, program_id BINARY(16) NOT NULL, period_key VARCHAR(16) NOT NULL,
                amount DECIMAL(12,2) NULL, currency CHAR(3) NOT NULL DEFAULT 'PHP', quantity INT UNSIGNED NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
                valid_from DATE NOT NULL, valid_until DATE NOT NULL,
                scheduled_for DATETIME(6) NULL, location VARCHAR(191) NULL,
                release_seq INT UNSIGNED NOT NULL DEFAULT 1, {$ts},
                UNIQUE KEY uq_ent_application (application_id),
                UNIQUE KEY uq_ent_claim_limit (tenant_id, beneficiary_id, program_id, period_key),
                KEY ix_ent_status (tenant_id, status),
                CONSTRAINT fk_ent_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_ent_app FOREIGN KEY (application_id) REFERENCES {$p}applications(id),
                CONSTRAINT fk_ent_ben FOREIGN KEY (beneficiary_id) REFERENCES {$p}beneficiaries(id),
                CONSTRAINT fk_ent_prog FOREIGN KEY (program_id) REFERENCES {$p}programs(id)
            ) {$o}",

            // Ledger. One release per entitlement cycle (uq_ful_release); one reversal per release (uq_ful_reverses).
            "CREATE TABLE {$p}fulfillments (
                {$id}, {$tenant}, ref_no VARCHAR(20) NOT NULL, entitlement_id BINARY(16) NOT NULL,
                kind VARCHAR(16) NOT NULL, release_seq INT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NULL, currency CHAR(3) NOT NULL DEFAULT 'PHP', quantity INT UNSIGNED NULL,
                method VARCHAR(16) NOT NULL, verifier_user_id BIGINT UNSIGNED NOT NULL,
                idempotency_key CHAR(36) NOT NULL, reverses_id BINARY(16) NULL, note VARCHAR(255) NULL,
                created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_ful_ref (tenant_id, ref_no),
                UNIQUE KEY uq_ful_idem (idempotency_key),
                UNIQUE KEY uq_ful_release (entitlement_id, kind, release_seq),
                UNIQUE KEY uq_ful_reverses (reverses_id),
                KEY ix_ful_tenant_time (tenant_id, created_at),
                CONSTRAINT fk_ful_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_ful_ent FOREIGN KEY (entitlement_id) REFERENCES {$p}entitlements(id),
                CONSTRAINT fk_ful_reverses FOREIGN KEY (reverses_id) REFERENCES {$p}fulfillments(id)
            ) {$o}",

            "CREATE TABLE {$p}redemption_attempts (
                {$id}, {$tenant}, credential_id BINARY(16) NULL, entitlement_id BINARY(16) NULL,
                verifier_user_id BIGINT UNSIGNED NOT NULL, method VARCHAR(16) NOT NULL,
                outcome VARCHAR(16) NOT NULL, reason_code VARCHAR(48) NULL, idempotency_key CHAR(36) NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_attempt_tenant_time (tenant_id, created_at), KEY ix_attempt_ent (entitlement_id)
            ) {$o}",

            "CREATE TABLE {$p}pledges (
                {$id}, {$tenant}, ref_no VARCHAR(20) NOT NULL, organization_id BINARY(16) NOT NULL,
                program_id BINARY(16) NULL, kind VARCHAR(16) NOT NULL,
                amount DECIMAL(12,2) NULL, currency CHAR(3) NOT NULL DEFAULT 'PHP',
                item_description VARCHAR(255) NULL, quantity INT UNSIGNED NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'submitted', submitted_by BIGINT UNSIGNED NOT NULL,
                reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME(6) NULL, review_note VARCHAR(255) NULL, {$ts},
                UNIQUE KEY uq_pledge_ref (tenant_id, ref_no), KEY ix_pledge_org (organization_id),
                CONSTRAINT fk_pledge_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_pledge_org FOREIGN KEY (organization_id) REFERENCES {$p}organizations(id),
                CONSTRAINT fk_pledge_prog FOREIGN KEY (program_id) REFERENCES {$p}programs(id)
            ) {$o}",

            "CREATE TABLE {$p}donations_received (
                {$id}, {$tenant}, pledge_id BINARY(16) NULL, organization_id BINARY(16) NOT NULL,
                receipt_no VARCHAR(32) NOT NULL, amount DECIMAL(12,2) NULL, currency CHAR(3) NOT NULL DEFAULT 'PHP',
                quantity INT UNSIGNED NULL, item_description VARCHAR(255) NULL,
                received_at DATETIME(6) NOT NULL, received_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_donation_receipt (tenant_id, receipt_no),
                CONSTRAINT fk_don_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_don_pledge FOREIGN KEY (pledge_id) REFERENCES {$p}pledges(id),
                CONSTRAINT fk_don_org FOREIGN KEY (organization_id) REFERENCES {$p}organizations(id)
            ) {$o}",

            "CREATE TABLE {$p}donation_allocations (
                {$id}, {$tenant}, donation_id BINARY(16) NOT NULL, program_id BINARY(16) NOT NULL,
                amount DECIMAL(12,2) NULL, quantity INT UNSIGNED NULL,
                allocated_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
                KEY ix_alloc_donation (donation_id),
                CONSTRAINT fk_alloc_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id),
                CONSTRAINT fk_alloc_don FOREIGN KEY (donation_id) REFERENCES {$p}donations_received(id),
                CONSTRAINT fk_alloc_prog FOREIGN KEY (program_id) REFERENCES {$p}programs(id)
            ) {$o}",

            "CREATE TABLE {$p}audit_log (
                {$id}, tenant_id BINARY(16) NULL, actor_user_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(64) NOT NULL, object_type VARCHAR(32) NOT NULL, object_id BINARY(16) NULL,
                ip_hash CHAR(64) NULL, meta_json LONGTEXT NULL, created_at DATETIME(6) NOT NULL,
                KEY ix_audit_tenant_time (tenant_id, created_at), KEY ix_audit_object (object_type, object_id)
            ) {$o}",
        ];
    },
];
