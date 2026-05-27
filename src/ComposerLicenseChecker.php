<?php

declare(strict_types=1);

namespace D3R\Standards;

use Lendable\ComposerLicenseChecker\LicenseConfigurationBuilder;

abstract class ComposerLicenseChecker
{
    public static function defaultBuilder(): LicenseConfigurationBuilder
    {
        return (new LicenseConfigurationBuilder())
            ->addLicenses(
                'MIT',
                'BSD-2-Clause',
                'BSD-3-Clause',
                'Apache-2.0',
                'LGPL-2.1-only',
                'LGPL-2.1-or-later',
                'LGPL-3.0-only',
                'LGPL-3.0+',
                'LGPL-3.0-or-later',
                'MPL-2.0',
                'ISC',
            )
            ->addAllowedVendor('d3r') // Allow all D3R packages
            ->build();
    }
}
