<?php

declare(strict_types=1);

/*
 * This file is part of the mandrael/contao-form-csrf-fix.
 *
 * (c) Michael Gasperl
 *
 * @license MIT
 */

namespace Mandrael\ContaoFormCsrfFix\Tests\DependencyInjection;

use Contao\CoreBundle\EventListener\CsrfTokenCookieSubscriber;
use Mandrael\ContaoFormCsrfFix\DependencyInjection\ContaoFormCsrfFixExtension;
use Mandrael\ContaoFormCsrfFix\EventListener\EnsureCsrfCookieOnFormPagesListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelEvents;

class ContaoFormCsrfFixExtensionTest extends TestCase
{
    public function testRegistersTheListenerRightBeforeTheCoreSubscriber(): void
    {
        $container = new ContainerBuilder();
        (new ContaoFormCsrfFixExtension())->load([], $container);

        $definition = $container->getDefinition(EnsureCsrfCookieOnFormPagesListener::class);
        $tags = $definition->getTag('kernel.event_listener');

        $this->assertCount(1, $tags);
        $this->assertSame('kernel.response', $tags[0]['event']);
        $this->assertSame('onKernelResponse', $tags[0]['method']);

        // Must be exactly 2 above the core subscriber, whatever the installed
        // Contao version declares (-1006 in 4.13/5.3.31+, -832 in 5.0-5.3.30).
        $corePriority = CsrfTokenCookieSubscriber::getSubscribedEvents()[KernelEvents::RESPONSE][1];

        $this->assertSame($corePriority + 2, $tags[0]['priority']);
    }

    public function testDefinesTheDiagnosticHeaderParameterAsDisabledByDefault(): void
    {
        $container = new ContainerBuilder();
        (new ContaoFormCsrfFixExtension())->load([], $container);

        $this->assertFalse($container->getParameter('contao_form_csrf_fix.diagnostic_header'));
    }
}
