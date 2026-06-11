<?php

declare(strict_types=1);

/*
 * This file is part of the mandrael/contao-form-csrf-fix.
 *
 * (c) Michael Gasperl
 *
 * @license MIT
 */

namespace Mandrael\ContaoFormCsrfFix\EventListener;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Works around the "Invalid request token" (400) error for first-time visitors,
 * see https://github.com/contao/contao/issues/2820.
 *
 * Background: Contao's CSRF protection uses a double-submit cookie. For
 * requests WITHOUT any non-CSRF cookies, the core CsrfTokenCookieSubscriber
 * (kernel.response, priority -1006) does NOT set the csrf_* cookie (and in
 * Contao <5.3.31 it additionally replaces all rendered token values in the
 * HTML with an empty string so the page stays cacheable). Token validation is
 * skipped for cookie-less POST requests, so this normally works — UNTIL a
 * tracking/consent script (Google Analytics, Meta Pixel, Matomo, consent
 * banners, ...) sets a cookie via JavaScript between page load and form
 * submission. The POST then carries cookies, ContaoCsrfTokenManager::
 * canSkipTokenValidation() returns false, and validation fails because the
 * csrf_* cookie half of the double-submit pair was never set: the visitor
 * gets a 400 "Invalid request token" page on their very first submission
 * attempt. This affects ALL Contao versions from 4.13 up to and including
 * 5.7/main (verified June 2026).
 *
 * This listener runs right before the core CsrfTokenCookieSubscriber (its
 * kernel.response priority is computed at container compile time as the core
 * subscriber's priority + 2, since that differs across Contao versions:
 * -1006 in 4.13 and 5.3.31+, -832 in 5.0 - 5.3.30). When a
 * successful frontend HTML response contains an actually rendered CSRF token
 * (checked against ContaoCsrfTokenManager::getUsedTokenValues(), the exact
 * data source the core uses), it does two things:
 *
 *  1. It ALWAYS makes the response private, so pages containing a token are
 *     never stored in the shared HTTP cache. This also closes a pre-existing
 *     cache-poisoning window: when the request carries an unchanged csrf_*
 *     cookie plus any other cookie, the core sends no Set-Cookie header, the
 *     MakeResponsePrivateListener (-1012) does not kick in, and a response
 *     with a user-bound token could otherwise be cached publicly.
 *
 *  2. If the request carries no cookies (or only csrf_* cookies), it injects
 *     a marker cookie into the REQUEST cookie bag (server-side only — it is
 *     never sent to the browser). The core subscriber at -1006 then takes its
 *     setCookies() path: the token stays in the HTML and the csrf_* cookie is
 *     set immediately, so the very first POST validates no matter which
 *     cookies tracking scripts create in the meantime.
 *
 * The marker name must NOT start with the CSRF cookie prefix ("csrf_" by
 * default), otherwise the core's isCsrfCookie() check would ignore it.
 *
 * This does NOT weaken CSRF protection: no validation is disabled — the
 * listener merely activates the same code path the core uses for every
 * cookie-carrying visitor anyway, just one request earlier.
 *
 * Trade-off: pages that render a CSRF token (i.e. pages containing a form)
 * are excluded from the shared page cache. Pages without forms are not
 * affected and remain fully cacheable.
 */
class EnsureCsrfCookieOnFormPagesListener
{
    public const MARKER_COOKIE = 'contao_csrf_fix';

    /**
     * @var ContaoCsrfTokenManager
     */
    private $csrfTokenManager;

    /**
     * @var ScopeMatcher
     */
    private $scopeMatcher;

    /**
     * @var string
     */
    private $csrfCookiePrefix;

    /**
     * @var bool
     */
    private $addDiagnosticHeader;

    public function __construct(ContaoCsrfTokenManager $csrfTokenManager, ScopeMatcher $scopeMatcher, string $csrfCookiePrefix = 'csrf_', bool $addDiagnosticHeader = false)
    {
        $this->csrfTokenManager = $csrfTokenManager;
        $this->scopeMatcher = $scopeMatcher;
        $this->csrfCookiePrefix = $csrfCookiePrefix;
        $this->addDiagnosticHeader = $addDiagnosticHeader;
    }

    // No static getSubscribedEvents(): the kernel.response priority is
    // computed at container compile time (see ContaoFormCsrfFixExtension) as
    // "core CsrfTokenCookieSubscriber priority + 2", because the core
    // priority differs between Contao versions (-1006 in 4.13 and 5.3.31+,
    // -832 in 5.0 - 5.3.30). This guarantees we always run right before it.

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Frontend main requests only (isFrontendMainRequest() includes the
        // isMainRequest() check, so fragments/sub-requests are skipped too)
        if (!$this->scopeMatcher->isFrontendMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();

        // Honour routes with a disabled token check (mirrors the core guard)
        if (false === $request->attributes->get('_token_check')) {
            return;
        }

        $response = $event->getResponse();

        // The core only acts on successful responses (see contao/contao#2252).
        // Redirects (e.g. 303 after a successful submission) and error pages
        // are left untouched.
        if (!$response->isSuccessful()) {
            return;
        }

        // HTML responses only (same check as the core subscriber)
        if (false === stripos((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }

        $content = $response->getContent();

        // StreamedResponse/BinaryFileResponse return false, 304 has no body
        if (!\is_string($content) || '' === $content) {
            return;
        }

        // Does the page contain an actually rendered token? This mirrors the
        // core's own str_replace() data source and covers hidden REQUEST_TOKEN
        // inputs as well as {{request_token}} insert tags in inline JS.
        $hasRenderedToken = false;

        foreach (array_unique($this->csrfTokenManager->getUsedTokenValues()) as $tokenValue) {
            if ('' !== (string) $tokenValue && false !== strpos($content, (string) $tokenValue)) {
                $hasRenderedToken = true;
                break;
            }
        }

        if (!$hasRenderedToken) {
            return;
        }

        // 1) ALWAYS: pages with a rendered token must never be stored in the
        // shared cache. This has to happen before the cookie check below,
        // because for "unchanged csrf_* cookie plus another cookie" requests
        // the core sends no Set-Cookie header and nothing else would make the
        // response private.
        $response->setPrivate();

        if ($this->addDiagnosticHeader) {
            $response->headers->set('X-Contao-Csrf-Fix', '1');
        }

        // 2) Inject the marker only if the core would otherwise take the
        // "no CSRF required" path, i.e. the request carries only csrf_*
        // cookies or none at all. If a non-CSRF cookie is present, the core
        // sets the csrf_* cookie by itself.
        foreach ($request->cookies->all() as $name => $value) {
            if (!\is_string($name) || 0 !== strpos($name, $this->csrfCookiePrefix)) {
                return;
            }
        }

        // Never sent to the browser; the HTTP cache kernel only forwards a
        // clone of the request anyway.
        $request->cookies->set($this->markerCookieName(), '1');
    }

    /**
     * The marker must never start with the configured CSRF cookie prefix,
     * otherwise the core's isCsrfCookie() check would treat it as a CSRF
     * cookie and ignore it. With the default prefix "csrf_" the constant is
     * used as-is; for exotic prefixes (e.g. "contao_") the name is padded
     * until it no longer matches.
     */
    private function markerCookieName(): string
    {
        $name = self::MARKER_COOKIE;

        while ('' !== $this->csrfCookiePrefix && 0 === strpos($name, $this->csrfCookiePrefix)) {
            $name = 'x'.$name;
        }

        return $name;
    }
}
