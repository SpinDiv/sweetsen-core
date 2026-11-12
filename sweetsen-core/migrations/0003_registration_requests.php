<?php
/**
 * Registration requests from the public website: seniors (or family) asking to be registered,
 * and organizations asking to become sponsors. Nothing here is a senior record or an organization
 * until LGU staff approve it; approval creates the real record and links it back.
 */

declare(strict_types=1);

return [
    'version' => '0003_registration_requests',
    'statements' => static function (string $p, string $o): array {
        return [
            "CREATE TABLE {$p}registration_requests (
                id BINARY(16) NOT NULL PRIMARY KEY, tenant_id BINARY(16) NOT NULL,
                ref_no VARCHAR(20) NOT NULL, kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'submitted',
                last_name VARCHAR(100) NULL, first_name VARCHAR(100) NULL, middle_name VARCHAR(100) NULL, suffix VARCHAR(10) NULL,
                sex CHAR(1) NULL, birthdate DATE NULL, barangay VARCHAR(100) NULL, osca_id VARCHAR(40) NULL,
                filed_by VARCHAR(16) NULL, filer_name VARCHAR(191) NULL, filer_relation VARCHAR(60) NULL,
                org_name VARCHAR(191) NULL, org_type VARCHAR(16) NULL,
                contact_name VARCHAR(191) NULL, contact_email VARCHAR(191) NULL, contact_phone VARCHAR(20) NULL,
                message VARCHAR(500) NULL, ip_hash CHAR(64) NULL,
                reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME(6) NULL, review_note VARCHAR(255) NULL,
                beneficiary_id BINARY(16) NULL, organization_id BINARY(16) NULL, user_id BIGINT UNSIGNED NULL,
                created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NULL,
                UNIQUE KEY uq_req_ref (tenant_id, ref_no),
                KEY ix_req_queue (tenant_id, kind, status, created_at),
                CONSTRAINT fk_req_tenant FOREIGN KEY (tenant_id) REFERENCES {$p}tenants(id)
            ) {$o}",
        ];
    },
];
