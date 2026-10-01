<?php

namespace App\Services\Access;

/**
 * Role-based access control map. Policies and pages check these permission names;
 * the Super Admin bypasses every check (see AppServiceProvider Gate::before).
 */
final class Permissions
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const CRS = 'crs';

    public const AUDITOR = 'auditor';

    /**
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        self::SUPER_ADMIN => 'Super Admin',
        self::ADMIN => 'Admin',
        self::CRS => 'CRS',
        self::AUDITOR => 'Auditor (read-only)',
    ];

    /**
     * @var array<string, string>
     */
    public const ALL = [
        'members.view' => 'View members',
        'members.create' => 'Add members',
        'members.update' => 'Edit member details',
        'members.update_colisap' => 'Change status, category & segmentation',
        'members.delete' => 'Archive members',
        'members.restore' => 'Restore archived members',
        'members.import' => 'Import Excel masterlist',
        'members.export' => 'Export members',
        'beneficiaries.view' => 'View beneficiaries',
        'beneficiaries.manage' => 'Add / edit / remove beneficiaries',
        'applications.view' => 'View applications',
        'applications.manage' => 'Process applications, re-applications & withdrawals',
        'claims.view' => 'View mortuary claims',
        'claims.manage' => 'File and edit mortuary claims',
        'claims.approve' => 'Approve / reject claims',
        'claims.settle' => 'Settle claims',
        'contributions.view' => 'View contributions',
        'contributions.manage' => 'Generate and record contributions',
        'savings.view' => 'View savings',
        'savings.manage' => 'Record savings transactions',
        'monitoring.view' => 'View monitoring & alerts',
        'monitoring.manage' => 'Resolve notices, apply upgrades',
        'reports.view' => 'Generate reports',
        'imports.view' => 'View import history',
        'branches.manage' => 'Manage branches',
        'users.manage' => 'Manage users',
        'roles.manage' => 'Manage roles & permissions',
        'policy.manage' => 'Edit policy settings',
        'audit.view' => 'View audit logs',
    ];

    /**
     * Default permissions per role. Super Admin has every permission implicitly.
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        $view = ['members.view', 'beneficiaries.view', 'applications.view', 'claims.view', 'contributions.view', 'savings.view', 'monitoring.view', 'reports.view'];

        return [
            self::ADMIN => [
                ...$view,
                'members.create', 'members.update', 'members.update_colisap', 'members.delete', 'members.restore', 'members.import', 'members.export',
                'beneficiaries.manage', 'applications.manage',
                'claims.manage', 'claims.approve', 'claims.settle',
                'contributions.manage', 'savings.manage', 'monitoring.manage', 'imports.view',
            ],
            self::CRS => [
                'members.view', 'members.create', 'members.update', 'members.import',
                'beneficiaries.view', 'beneficiaries.manage',
                'applications.view', 'claims.view', 'savings.view', 'monitoring.view', 'imports.view',
            ],
            self::AUDITOR => [...$view, 'imports.view', 'audit.view', 'members.export'],
        ];
    }
}
