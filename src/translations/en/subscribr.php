<?php

/**
 * Subscribr's own strings.
 *
 * Listed so that a translator has one file to work from, and so that a typo in a `Craft::t()` key
 * shows up here as a string with no entry rather than as a message that silently renders its own
 * key on a customer-facing page.
 */

return [
    'Subscribr' => 'Subscribr',
    'Subscription' => 'Subscription',
    'Subscriptions' => 'Subscriptions',
    'subscription' => 'subscription',
    'subscriptions' => 'subscriptions',

    // Statuses
    'Active' => 'Active',
    'Trialing' => 'Trialing',
    'Pending' => 'Pending',
    'Paused' => 'Paused',
    'Past due' => 'Past due',
    'Cancelling' => 'Cancelling',
    'Ended' => 'Ended',

    // Index and columns
    'All subscriptions' => 'All subscriptions',
    'Status' => 'Status',
    'Plans' => 'Plans',
    'Plan' => 'Plan',
    'Boxes' => 'Boxes',
    'Dunning' => 'Dunning',
    'Gateways' => 'Gateways',
    'Reference' => 'Reference',
    'Subscriber' => 'Subscriber',
    'Renewal' => 'Renewal',
    'Cadence' => 'Cadence',
    'Next payment' => 'Next payment',
    'Cycles' => 'Cycles',
    'Gateway' => 'Gateway',
    'Failures' => 'Failures',
    'Started' => 'Started',
    'Renewing in 7 days' => 'Renewing in 7 days',
    'Renewal price' => 'Renewal price',
    'Guest' => 'Guest',

    // Permissions
    'View subscriptions' => 'View subscriptions',
    'Change subscriptions' => 'Change subscriptions',
    'Take payments and issue renewals' => 'Take payments and issue renewals',
    'Manage plans and boxes' => 'Manage plans and boxes',
    'Manage dunning' => 'Manage dunning',

    // Actions
    'Pause' => 'Pause',
    'Resume' => 'Resume',
    'Cancel at period end' => 'Cancel at period end',
    'Retry payment now' => 'Retry payment now',
    'Renew now' => 'Renew now',
    'Reinstate' => 'Reinstate',

    // Validation
    'A fixed-price box needs a price.' => 'A fixed-price box needs a price.',
    'A plan that overrides the price needs one.' => 'A plan that overrides the price needs one.',
    'A daily plan bills every day; it cannot be anchored.' => 'A daily plan bills every day; it cannot be anchored.',
    'A weekly plan is anchored to a day of the week, 0–6.' => 'A weekly plan is anchored to a day of the week, 0–6.',
    'A monthly plan is anchored to a day from 1 to 28, so that every month has one.' => 'A monthly plan is anchored to a day from 1 to 28, so that every month has one.',

    // Subscriber-facing
    'Your subscriptions' => 'Your subscriptions',
    'Your next order' => 'Your next order',
    'Skip my next order' => 'Skip my next order',
    'Start again' => 'Start again',
    'Cancel my subscription' => 'Cancel my subscription',
    'Actually, keep it going' => 'Actually, keep it going',
    'Claim my gift' => 'Claim my gift',
    'Payment method' => 'Payment method',
    'History' => 'History',
];
