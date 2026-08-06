<?php

declare(strict_types=1);

namespace Vortos\PayHere\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class PayHerePackage implements PackageInterface
{
    /** Narrower than the interface: this package always has an extension. */
    public function getContainerExtension(): ExtensionInterface
    {
        return new PayHereExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Nothing to compile. The rail has no handler discovery of its own:
        // notifications land in the inbox and the application's own handler
        // decides what they mean, which keeps the settlement decision in the
        // context that owns the money rather than in the integration package.
    }
}
