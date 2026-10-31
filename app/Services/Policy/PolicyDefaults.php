<?php

namespace App\Services\Policy;

/**
 * Default COLISAP policy values (General Provisions). Every value is editable by the
 * Administrator under Policy Settings; nothing else in the application hard-codes them.
 */
final class PolicyDefaults
{
    /**
     * @var array<string, array{group: string, label: string, type: 'int'|'decimal'|'bool'|'string', value: mixed, help?: string, options?: array<string, string>}>
     */
    public const SETTINGS = [
        // Benefits (IV)
        'benefit_40k' => ['group' => 'Benefits', 'label' => 'Basic category benefit (₱)', 'type' => 'decimal', 'value' => 40000, 'help' => 'The category is named after this amount everywhere in the system (₱40,000 shows as 40K).'],
        'benefit_60k' => ['group' => 'Benefits', 'label' => 'Higher category benefit (₱)', 'type' => 'decimal', 'value' => 60000, 'help' => 'The category members upgrade to. Also named after this amount (₱60,000 shows as 60K).'],

        // Maintaining balance (II.3, II.4, VII)
        'min_balance_40k' => ['group' => 'Savings', 'label' => 'Basic category minimum maintaining balance (₱)', 'type' => 'decimal', 'value' => 500],
        'min_balance_60k' => ['group' => 'Savings', 'label' => 'Higher category minimum maintaining balance (₱)', 'type' => 'decimal', 'value' => 2000],
        'replenishment_days' => ['group' => 'Savings', 'label' => 'Replenishment period (days after notice)', 'type' => 'int', 'value' => 15],
        'replenishment_warning_days' => ['group' => 'Savings', 'label' => 'Warn when replenishment deadline is within (days)', 'type' => 'int', 'value' => 5],
        'auto_issue_replenishment_notices' => ['group' => 'Savings', 'label' => 'Automatically issue replenishment notices when balance falls below minimum', 'type' => 'bool', 'value' => true],
        'allow_negative_balance' => ['group' => 'Savings', 'label' => 'Accept negative savings balances on import', 'type' => 'bool', 'value' => false],

        // Effectivity & upgrades (III)
        'effectivity_days' => ['group' => 'Effectivity', 'label' => 'Effectivity / waiting period (days after approval)', 'type' => 'int', 'value' => 180],
        'upgrade_wait_days' => ['group' => 'Effectivity', 'label' => 'Upgrade waiting period (days)', 'type' => 'int', 'value' => 90],
        'auto_apply_upgrades' => ['group' => 'Effectivity', 'label' => 'Automatically move members to the higher category when the upgrade waiting period ends', 'type' => 'bool', 'value' => false, 'help' => 'When off, eligible upgrades are listed under Monitoring for an Admin to apply.'],
        'max_entry_age_months' => ['group' => 'Effectivity', 'label' => 'Maximum applicant age (months)', 'type' => 'int', 'value' => 714, 'help' => '59 years and 6 months = 714 months.'],

        // Dormancy & termination (VII)
        'dormancy_months' => ['group' => 'Dormancy', 'label' => 'Inactivity before an account is dormant (months)', 'type' => 'int', 'value' => 3],
        'dormancy_termination_months' => ['group' => 'Dormancy', 'label' => 'Consecutive dormant months before COLISAP termination', 'type' => 'int', 'value' => 3],
        'dormant_balance_threshold' => ['group' => 'Dormancy', 'label' => 'Savings below this are dormant in balance (₱)', 'type' => 'decimal', 'value' => 2000, 'help' => 'Set by the savings import as dormant-bal. Dormant in balance — like dormant in transaction (no transaction for the inactivity period above) — for the consecutive dormant months below ends COLISAP participation.'],
        'dormancy_activity_source' => ['group' => 'Dormancy', 'label' => 'What counts as account activity', 'type' => 'string', 'value' => 'both', 'options' => [
            'masterlist_flag' => 'Dormancy flag from the masterlist (dormant-txn / dormant-bal)',
            'recorded_activity' => 'Savings transactions recorded in this system',
            'both' => 'Either of the above',
        ], 'help' => 'The policy does not define "activity" precisely; choose how dormancy is determined.'],
        'auto_enforce_terminations' => ['group' => 'Dormancy', 'label' => 'Automatically terminate / downgrade when policy conditions are met', 'type' => 'bool', 'value' => true],
        'dormancy_warning_days' => ['group' => 'Dormancy', 'label' => 'Warn when dormancy termination is within (days)', 'type' => 'int', 'value' => 30],

        // Program (X, XI)
        'reapplication_fee' => ['group' => 'Program', 'label' => 'Re-application fee (₱)', 'type' => 'decimal', 'value' => 30],
        'min_program_participants' => ['group' => 'Program', 'label' => 'Minimum program participants', 'type' => 'int', 'value' => 100],
        'alert_window_days' => ['group' => 'Program', 'label' => 'Dashboard "coming up" window (days)', 'type' => 'int', 'value' => 30],
    ];
}
