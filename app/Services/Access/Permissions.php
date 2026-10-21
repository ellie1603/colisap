<?php

namespace App\Services\Access;

/**
 * Role-based access control map. Policies and pages check these permission names.
 * Two roles: the Administrator (bypasses every check, see AppServiceProvider Gate::before)
 * and the CRS Officer, who adds members (by filing applications), updates them, files re-applications
 * and imports or exports the Excel masterlist.
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
        'members.privacy' => 'Export or permanently erase a member\'s personal data',
        'members.export' => 'Export members',
        'bmpc_members.view' => 'View BMPC members (the whole cooperative member list)',
        'bmpc_members.import' => 'Import the BMPC member list',
        'applications.view' => 'View member transactions',
        'applications.create' => 'Add member transactions (new, active, upgrade, re-application)',
        'applications.manage' => 'Approve or reject applications, re-applications & withdrawals',
        'savings.view' => 'View savings',
        'savings.manage' => 'Record savings transactions',
        'monitoring.view' => 'View monitoring & alerts',
        'monitoring.manage' => 'Resolve notices, apply upgrades',
        'monitoring.send_sms' => 'Send SMS reminders to members',
        'reports.view' => 'Generate reports',
        'imports.view' => 'View import history',
        'imports.approve' => 'Approve Excel imports sent by branches',
        'branches.manage' => 'Manage branches',
        'users.manage' => 'Manage users',
        'roles.manage' => 'Manage roles & permissions',
        'policy.manage' => 'Edit policy settings',
        'audit.view' => 'View audit logs',
        'monitoring_api.manage' => 'Manage BMPC ONE monitoring API keys',
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
                'applications.view', 'applications.create', 'imports.view', 'bmpc_members.view', 'bmpc_members.import',
            ],
        ];
    }
}
