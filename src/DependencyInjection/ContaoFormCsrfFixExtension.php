<?php

declare(strict_types=1);

/*
 * This file is part of the mandrael/contao-form-csrf-fix.
 *
 * (c) Michael Gasperl
 *
 * @license MIT
 */

namespace Mandrael\ContaoFormCsrfFix\DependencyInjection;

use Contao\CoreBundle\EventListener\CsrfTokenCookieSubscriber;
use Mandrael\ContaoFormCsrfFix\EventListener\EnsureCsrfCookieOnFormPagesListener;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\KernelEvents;

class ContaoFormCsrfFixExtension extends Extension
{
    /**
     * Used if the core subscriber's priority cannot be determined (matches
     * Contao 4.13 and all currently supported 5.x versions).
     */
    private const FALLBACK_CORE_PRIORITY = -1006;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');

        // The listener must run right BEFORE the core CsrfTokenCookieSubscriber,
        // whose kernel.response priority differs across Contao versions
        // (-1006 in 4.13 and 5.3.31+, -832 in 5.0 - 5.3.30). Reading it at
        // container compile time keeps the bundle correct on every version.
        $container
            ->getDefinition(EnsureCsrfCookieOnFormPagesListener::class)
            ->addTag('kernel.event_listener', [
                'event' => KernelEvents::RESPONSE,
                'method' => 'onKernelResponse',
                'priority' => $this->getCoreSubscriberPriority() + 2,
            ]);
    }

    private function getCoreSubscriberPriority(): int
    {
        if (!class_exists(CsrfTokenCookieSubscriber::class)) {
            return self::FALLBACK_CORE_PRIORITY;
        }

        $events = CsrfTokenCookieSubscriber::getSubscribedEvents();

        if (isset($events[KernelEvents::RESPONSE][1]) && \is_int($events[KernelEvents::RESPONSE][1])) {
            return $events[KernelEvents::RESPONSE][1];
        }

        return self::FALLBACK_CORE_PRIORITY;
    }
}
