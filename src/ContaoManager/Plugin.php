<?php

declare(strict_types=1);

/*
 * This file is part of the mandrael/contao-form-csrf-fix.
 *
 * (c) Michael Gasperl
 *
 * @license MIT
 */

namespace Mandrael\ContaoFormCsrfFix\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Mandrael\ContaoFormCsrfFix\ContaoFormCsrfFixBundle;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoFormCsrfFixBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }
}
