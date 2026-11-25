<?php

return [
    // Set true only after the owner has recorded the required data export,
    // cr8or transfer/retention decision, and production deployment approval.
    'allow_destructive_migration' => (bool) env('DOMAIN_RETIREMENT_DATA_SIGNOFF', false),
];
