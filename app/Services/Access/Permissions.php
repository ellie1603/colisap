<?php

namespace App\Services\Access;

/**
 * Role-based access control map. Policies and pages check these permission names.
 * Two roles: the Administrator (bypasses every check, see AppServiceProvider Gate::before)
 * and the CRS Officer, who imports, adds and maintains member records.
 */
final class Permissions
{
    public const ADMIN = 'admin';

    public const CRS = 'crs';

    /**
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        self::ADMIN => 'Administrator',
        self::CRS => 'CRS Officer',
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
        'applications.view' => 'View applications',
        'applications.manage' => 'Process applications, re-applications & withdrawals',
        'savings.view' => 'View savings',
        'savings.manage' => 'Record savings transactions',
        'monitoring.view' => 'View monitoring & alerts',
        'monitoring.manage' => 'Resolve notices, apply upgrades',
        'monitoring.send_sms' => 'Send SMS reminders to members',
        'reports.view' => 'Generate reports',
        'imports.view' => 'View import history',
        'branches.manage' => 'Manage branches',
        'users.manage' => 'Manage users',
        'roles.manage' => 'Manage roles & permissions',
        'policy.manage' => 'Edit policy settings',
        'audit.view' => 'View audit logs',
    ];

    /**
     * Default permissions per role. The Administrator also bypasses every check.
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        return [
            self::ADMIN => array_keys(self::ALL),
            self::CRS => [
                'members.view', 'members.create', 'members.update', 'members.import', 'members.export',
                'applications.view', 'savings.view', 'monitoring.view', 'monitoring.send_sms', 'reports.view', 'imports.view',
            ],
        ];
    }
}
