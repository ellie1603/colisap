<?php

namespace App\Services\Policy;

/**
 * Default COLISAP policy values (General Provisions). Every value is editable by the
 * Super Admin under Policy Settings; nothing else in the application hard-codes them.
 */
final class PolicyDefaults
{
    /**
     * @var array<string, array{group: string, label: string, type: 'int'|'decimal'|'bool'|'string', value: mixed, help?: string, options?: array<string, string>}>
     */
    public const SETTINGS = [
        // Benefits (IV)
        'benefit_40k' => ['group' => 'Benefits', 'label' => '40K category benefit (₱)', 'type' => 'decimal', 'value' => 40000],
        'benefit_60k' => ['group' => 'Benefits', 'label' => '60K category benefit (₱)', 'type' => 'decimal', 'value' => 60000, 'help' => 'The policy mentions ₱50,000 once in the maintaining-balance section; ₱60,000 is used everywhere else.'],

        // Maintaining balance (II.3, II.4, VII)
        'min_balance_40k' => ['group' => 'Savings', 'label' => '40K minimum maintaining balance (₱)', 'type' => 'decimal', 'value' => 500],
        'min_balance_60k' => ['group' => 'Savings', 'label' => '60K minimum maintaining balance (₱)', 'type' => 'decimal', 'value' => 2000],
        'replenishment_days' => ['group' => 'Savings', 'label' => 'Replenishment period (days after notice)', 'type' => 'int', 'value' => 15],
        'replenishment_warning_days' => ['group' => 'Savings', 'label' => 'Warn when replenishment deadline is within (days)', 'type' => 'int', 'value' => 5],
        'auto_issue_replenishment_notices' => ['group' => 'Savings', 'label' => 'Automatically issue replenishment notices when balance falls below minimum', 'type' => 'bool', 'value' => true],
        'allow_negative_balance' => ['group' => 'Savings', 'label' => 'Accept negative savings balances on import', 'type' => 'bool', 'value' => false],

        // Effectivity & upgrades (III)
        'effectivity_days' => ['group' => 'Effectivity', 'label' => 'Effectivity / waiting period (days after approval)', 'type' => 'int', 'value' => 180],
        'upgrade_wait_days' => ['group' => 'Effectivity', 'label' => '40K → 60K upgrade waiting period (days)', 'type' => 'int', 'value' => 90],
        'auto_apply_upgrades' => ['group' => 'Effectivity', 'label' => 'Automatically move members to 60K when the upgrade waiting period ends', 'type' => 'bool', 'value' => false, 'help' => 'When off, eligible upgrades are listed under Monitoring for an Admin to apply.'],
        'max_entry_age_months' => ['group' => 'Effectivity', 'label' => 'Maximum applicant age (months)', 'type' => 'int', 'value' => 714, 'help' => '59 years and 6 months = 714 months.'],

        // Dormancy & termination (VII)
        'dormancy_months' => ['group' => 'Dormancy', 'label' => 'Inactivity before an account is dormant (months)', 'type' => 'int', 'value' => 3],
        'dormancy_termination_months' => ['group' => 'Dormancy', 'label' => 'Consecutive dormant months before COLISAP termination', 'type' => 'int', 'value' => 3],
        'dormancy_activity_source' => ['group' => 'Dormancy', 'label' => 'What counts as account activity', 'type' => 'string', 'value' => 'masterlist_flag', 'options' => [
            'masterlist_flag' => 'Dormancy flag from the masterlist (dormant-txn / dormant-bal)',
            'recorded_activity' => 'Savings transactions recorded in this system',
            'both' => 'Either of the above',
        ], 'help' => 'The policy does not define "activity" precisely; choose how dormancy is determined.'],
        'auto_enforce_terminations' => ['group' => 'Dormancy', 'label' => 'Automatically terminate / downgrade when policy conditions are met', 'type' => 'bool', 'value' => true],
        'dormancy_warning_days' => ['group' => 'Dormancy', 'label' => 'Warn when dormancy termination is within (days)', 'type' => 'int', 'value' => 30],

        // Contributions (III.2, VI)
        'claim_contribution' => ['group' => 'Contributions', 'label' => 'Participant contribution per mortuary claim (₱)', 'type' => 'decimal', 'value' => 5],
        'additional_60k_contribution' => ['group' => 'Contributions', 'label' => 'Extra contribution from 60K participants for a 60K claim (₱)', 'type' => 'decimal', 'value' => 0, 'help' => 'The policy does not state how the additional ₱20,000 of a 60K claim is funded. Leave 0 until confirmed.'],
        'equal_share_threshold' => ['group' => 'Contributions', 'label' => 'Equal-share rule applies when 40K participants fall below', 'type' => 'int', 'value' => 3000],
        'equal_share_enabled' => ['group' => 'Contributions', 'label' => 'Enable equal-share contributions below the threshold', 'type' => 'bool', 'value' => false, 'help' => 'Policy VI.2. When on and participants are below the threshold, each pays benefit ÷ participants instead of the fixed contribution.'],
        'coop_share_diamond' => ['group' => 'Contributions', 'label' => 'Coop share of contribution — Diamond (%)', 'type' => 'decimal', 'value' => 100],
        'coop_share_gold' => ['group' => 'Contributions', 'label' => 'Coop share of contribution — Gold (%)', 'type' => 'decimal', 'value' => 50],
        'coop_share_silver' => ['group' => 'Contributions', 'label' => 'Coop share of contribution — Silver (%)', 'type' => 'decimal', 'value' => 0],
        'coop_share_regular' => ['group' => 'Contributions', 'label' => 'Coop share of contribution — Regular (%)', 'type' => 'decimal', 'value' => 0],

        // Program (X, XI)
        'reapplication_fee' => ['group' => 'Program', 'label' => 'Re-application fee (₱)', 'type' => 'decimal', 'value' => 30],
        'min_program_participants' => ['group' => 'Program', 'label' => 'Minimum program participants', 'type' => 'int', 'value' => 100],
        'min_beneficiaries' => ['group' => 'Program', 'label' => 'Minimum beneficiaries per member', 'type' => 'int', 'value' => 1],
        'max_beneficiaries' => ['group' => 'Program', 'label' => 'Maximum beneficiaries per member', 'type' => 'int', 'value' => 3],
        'alert_window_days' => ['group' => 'Program', 'label' => 'Dashboard "coming up" window (days)', 'type' => 'int', 'value' => 30],
    ];
}
