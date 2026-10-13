<?php

/**
 * The licence tiers. What each one allows is fixed here; what each one costs is set by a platform administrator
 * (Platform > Settings > Plans & trial). `users` null means unlimited.
 */
return [
    'tiers' => [
        1 => ['name' => 'Tier 1', 'users' => 5, 'sms_per_month' => 100, 'sms_numbers' => 1],
        2 => ['name' => 'Tier 2', 'users' => 15, 'sms_per_month' => 500, 'sms_numbers' => 3],
        3 => ['name' => 'Tier 3', 'users' => 50, 'sms_per_month' => 1000, 'sms_numbers' => 5],
        4 => ['name' => 'Tier 4', 'users' => 100, 'sms_per_month' => 3000, 'sms_numbers' => 10],
        5 => ['name' => 'Tier 5', 'users' => null, 'sms_per_month' => 5000, 'sms_numbers' => 20],
    ],

    /** The tier every new company starts on (and every trial runs on). */
    'trial_tier' => 1,

    /** A tier with unlimited users is stored as this many seats: large enough never to bind, recognised by the screens. */
    'unlimited_users' => 999999,

    /** Months of free trial a new sign-up gets until the platform changes it. */
    'default_trial_months' => 3,
];
