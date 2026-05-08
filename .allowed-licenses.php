<?php

declare(strict_types=1);

use Lendable\ComposerLicenseChecker\LicenseConfigurationBuilder;

return (new LicenseConfigurationBuilder())
    ->addLicenses(
        'MIT',
        'BSD-2-Clause',
        'BSD-3-Clause',
        'Apache-2.0',
        // And other licenses you wish to allow.
    )
    ->addAllowedVendor('d3r') // Allow any license from a specific vendor, i.e. your own company.
    ->build();
