<?php

declare(strict_types=1);

use Lendable\ComposerLicenseChecker\LicenseConfigurationBuilder;

return (new LicenseConfigurationBuilder())
    ->addLicenses(
        'MIT',
        'BSD-2-Clause',
        'BSD-3-Clause',
        'Apache-2.0',
        'LGPL-3.0+',
        'MPL-2.0',
        'ISL',
        'MPL-2.0',
    )
    ->addAllowedVendor('d3r') // Allow any license from a specific vendor, i.e. your own company.
    ->build();
