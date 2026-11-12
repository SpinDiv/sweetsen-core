<?php

declare(strict_types=1);

namespace SweetSen\Roles;

/**
 * The five MVP roles and their capabilities, straight from the permission matrix.
 * WordPress administrators get none of these: site admin is not data access.
 */
final class Roles
{
    public const SENIOR = 'ss_senior';
    public const LGU_ADMIN = 'ss_lgu_admin';
    public const OFFICER = 'ss_program_officer';
    public const VERIFIER = 'ss_verifier';
    public const ORG_USER = 'ss_org_user';

    public const LABELS = [
        self::SENIOR => 'SweetSen: Senior',
        self::LGU_ADMIN => 'SweetSen: LGU Admin',
        self::OFFICER => 'SweetSen: Program Officer',
        self::VERIFIER => 'SweetSen: Verifier',
        self::ORG_USER => 'SweetSen: Sponsor',
    ];

    /** @var array<string, list<string>> */
    public const CAPS = [
        self::SENIOR => [
            'ss_access_portal', 'ss_view_own_profile', 'ss_submit_application',
        ],
        self::LGU_ADMIN => [
            'ss_access_portal', 'ss_manage_beneficiaries', 'ss_import', 'ss_verify_beneficiary', 'ss_manage_qr',
            'ss_manage_programs', 'ss_submit_application', 'ss_review_pledges', 'ss_record_donations',
            'ss_view_reports', 'ss_view_audit', 'ss_manage_users', 'ss_export_beneficiaries',
        ],
        self::OFFICER => [
            'ss_access_portal', 'ss_manage_beneficiaries', 'ss_verify_beneficiary', 'ss_manage_qr',
            'ss_submit_application', 'ss_decide_application', 'ss_reverse_release', 'ss_view_reports',
        ],
        self::VERIFIER => [
            'ss_access_portal', 'ss_release', 'ss_view_reports',
        ],
        self::ORG_USER => [
            'ss_access_portal', 'ss_submit_pledge', 'ss_view_reports',
        ],
    ];

    /** @return list<string> */
    public static function caps(string $role): array
    {
        return self::CAPS[$role] ?? [];
    }

    /** @return list<string> */
    public static function allCaps(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CAPS))));
    }

    /** Create or refresh the roles. Idempotent. */
    public static function install(): void
    {
        foreach (self::CAPS as $role => $caps) {
            remove_role($role);
            $grant = ['read' => true];
            foreach ($caps as $cap) {
                $grant[$cap] = true;
            }
            add_role($role, self::LABELS[$role], $grant);
        }
        // Make sure no stock role carries Sweet Sen capabilities.
        foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $stock) {
            $r = get_role($stock);
            if ($r === null) {
                continue;
            }
            foreach (self::allCaps() as $cap) {
                $r->remove_cap($cap);
            }
        }
    }
}
