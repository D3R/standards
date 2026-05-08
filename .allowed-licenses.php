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
        'ISC',
        'MPL-2.0',
    )
    ->addAllowedVendor('d3r') // Allow all D3R packages
    ->build();
