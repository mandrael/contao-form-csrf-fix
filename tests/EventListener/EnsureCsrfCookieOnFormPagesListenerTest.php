<?php

declare(strict_types=1);

/*
 * This file is part of the mandrael/contao-form-csrf-fix.
 *
 * (c) Michael Gasperl
 *
 * @license MIT
 */

namespace Mandrael\ContaoFormCsrfFix\Tests\EventListener;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Csrf\MemoryTokenStorage;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Mandrael\ContaoFormCsrfFix\EventListener\EnsureCsrfCookieOnFormPagesListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

class EnsureCsrfCookieOnFormPagesListenerTest extends TestCase
{
    private const TOKEN_NAME = 'contao_csrf_token';

    /**
     * @var ContaoCsrfTokenManager
     */
    private $tokenManager;

    /**
     * @var string
     */
    private $tokenValue;

    protected function setUp(): void
    {
        parent::setUp();

        $storage = new MemoryTokenStorage();
        $storage->initialize([]);

        $this->tokenManager = new ContaoCsrfTokenManager(
            new RequestStack(),
            'csrf_',
            new UriSafeTokenGenerator(),
            $storage
        );

        // Simulate a rendered form: the token manager has handed out a token
        $this->tokenValue = $this->tokenManager->getToken(self::TOKEN_NAME)->getValue();
    }

    public function testMarkerCookieDoesNotUseTheCsrfPrefix(): void
    {
        $this->assertStringStartsNotWith('csrf_', EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE);
    }

    public function testPadsTheMarkerNameIfItWouldMatchTheCsrfCookiePrefix(): void
    {
        // With an exotic prefix like "contao_" the default marker name would
        // be treated as a CSRF cookie by the core's isCsrfCookie() check.
        $listener = new EnsureCsrfCookieOnFormPagesListener(
            $this->tokenManager,
            $this->createScopeMatcher(),
            'contao_'
        );

        $event = $this->createEvent($this->createFrontendRequest(), $this->createHtmlResponse($this->formBody()));
        $listener->onKernelResponse($event);

        $cookies = $event->getRequest()->cookies;

        $this->assertFalse($cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
        $this->assertTrue($cookies->has('x'.EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
    }

    public function testInjectsMarkerAndMakesResponsePrivateForCookielessFormPage(): void
    {
        $event = $this->createEvent($this->createFrontendRequest(), $this->createHtmlResponse($this->formBody()));

        $this->createListener()->onKernelResponse($event);

        $this->assertTrue($event->getRequest()->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
        $this->assertStringContainsString('private', (string) $event->getResponse()->headers->get('Cache-Control'));
    }

    public function testInjectsMarkerIfOnlyCsrfCookiesArePresent(): void
    {
        $request = $this->createFrontendRequest(['csrf_contao_csrf_token' => 'abc123']);
        $event = $this->createEvent($request, $this->createHtmlResponse($this->formBody()));

        $this->createListener()->onKernelResponse($event);

        $this->assertTrue($request->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
    }

    public function testDoesNotInjectMarkerButStillMakesPrivateIfOtherCookiesArePresent(): void
    {
        $request = $this->createFrontendRequest(['_fbp' => 'fb.1.123', 'csrf_contao_csrf_token' => 'abc123']);
        $event = $this->createEvent($request, $this->createHtmlResponse($this->formBody()));

        $this->createListener()->onKernelResponse($event);

        $this->assertFalse($request->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
        $this->assertStringContainsString('private', (string) $event->getResponse()->headers->get('Cache-Control'));
    }

    public function testIgnoresPagesWithoutRenderedToken(): void
    {
        $response = $this->createHtmlResponse('<html><body>No form here</body></html>');
        $response->setSharedMaxAge(300);
        $event = $this->createEvent($this->createFrontendRequest(), $response);

        $this->createListener()->onKernelResponse($event);

        $this->assertFalse($event->getRequest()->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function testIgnoresNonFrontendRequests(): void
    {
        $request = $this->createFrontendRequest();
        $request->attributes->set('_scope', 'backend');
        $event = $this->createEvent($request, $this->createHtmlResponse($this->formBody()));

        $this->createListener()->onKernelResponse($event);

        $this->assertFalse($request->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
    }

    public function testIgnoresRoutesWithDisabledTokenCheck(): void
    {
        $request = $this->createFrontendRequest();
        $request->attributes->set('_token_check', false);
        $event = $this->createEvent($request, $this->createHtmlResponse($this->formBody()));

        $this->createListener()->onKernelResponse($event);

        $this->assertFalse($request->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
    }

    public function testIgnoresUnsuccessfulResponses(): void
    {
        $event = $this->createEvent($this->createFrontendRequest(), $this->createHtmlResponse($this->formBody(), 404));

        $this->createListener()->onKernelResponse($event);

        $this->assertFalse($event->getRequest()->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
    }

    public function testIgnoresRedirects(): void
    {
        $event = $this->createEvent($this->createFrontendRequest(), $this->createHtmlResponse($this->formBody(), 303));

        $this->createListener()->onKernelResponse($event);

        $this->assertFalse($event->getRequest()->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
    }

    public function testIgnoresNonHtmlResponses(): void
    {
        $response = new Response($this->formBody(), 200, ['Content-Type' => 'application/json']);
        $event = $this->createEvent($this->createFrontendRequest(), $response);

        $this->createListener()->onKernelResponse($event);

        $this->assertFalse($event->getRequest()->cookies->has(EnsureCsrfCookieOnFormPagesListener::MARKER_COOKIE));
    }

    public function testAddsDiagnosticHeaderOnlyWhenEnabled(): void
    {
        $event = $this->createEvent($this->createFrontendRequest(), $this->createHtmlResponse($this->formBody()));
        $this->createListener(true)->onKernelResponse($event);
        $this->assertSame('1', $event->getResponse()->headers->get('X-Contao-Csrf-Fix'));

        $event = $this->createEvent($this->createFrontendRequest(), $this->createHtmlResponse($this->formBody()));
        $this->createListener(false)->onKernelResponse($event);
        $this->assertNull($event->getResponse()->headers->get('X-Contao-Csrf-Fix'));
    }

    private function createListener(bool $diagnosticHeader = false): EnsureCsrfCookieOnFormPagesListener
    {
        return new EnsureCsrfCookieOnFormPagesListener(
            $this->tokenManager,
            $this->createScopeMatcher(),
            'csrf_',
            $diagnosticHeader
        );
    }

    private function createScopeMatcher(): ScopeMatcher
    {
        // Mocked instead of constructed: the constructor signature differs
        // between Contao 4.13 (2 arguments) and 5.x (3 arguments).
        $scopeMatcher = $this->createStub(ScopeMatcher::class);
        $scopeMatcher
            ->method('isFrontendMainRequest')
            ->willReturnCallback(
                static function ($event): bool {
                    return $event->isMainRequest() && 'frontend' === $event->getRequest()->attributes->get('_scope');
                }
            );

        return $scopeMatcher;
    }

    private function createFrontendRequest(array $cookies = []): Request
    {
        $request = Request::create('https://example.com/event/test', 'GET', [], $cookies);
        $request->attributes->set('_scope', 'frontend');

        return $request;
    }

    private function createHtmlResponse(string $body, int $status = 200): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function formBody(): string
    {
        return '<html><body><form method="post"><input type="hidden" name="REQUEST_TOKEN" value="'.$this->tokenValue.'"></form></body></html>';
    }

    private function createEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );
    }
}
