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
             // Allow all D3R packages
            ->addAllowedVendor('d3r')
            // https://github.com/facebook/facebook-php-business-sdk/blob/main/LICENSE is permissive
            ->addAllowedPackage('facebook/php-business-sdk')
            // Can use either Apache or GPL
            ->addAllowedPackage('voku/portable-utf8')
            // We don't care about dev dependencies as they aren't distributed
            ->ignoreDev(true)
            ;
    }
}
