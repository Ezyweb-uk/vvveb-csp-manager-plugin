<?php

/**
 * CSP Manager for Vvveb
 *
 * Copyright (C) 2026  Ezyweb.uk
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

/*
Name: CSP Manager
Slug: csp-manager
Category: other
Url: https://github.com/Ezyweb-uk/vvveb-csp-manager-plugin
Description: Centralized, route-aware Content-Security-Policy for the checkout page (strict) and the rest of the site (configurable, lax by default). Starts in report-only mode. Merges with any CSP header other plugins (e.g. payment gateway plugins) already queued, rather than overwriting them.
Author: Ezyweb.uk
Version: 0.4
Thumb: csp-manager.svg
Author url: https://ezyweb.uk
Settings: /admin/index.php?module=plugins/csp-manager/settings
*/

use Vvveb\Plugins\CspManager\System\Csp;
use Vvveb\System\Event;
use Vvveb\System\Routes;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

class CspManager {
	public function app() {
		//CONFIRMED BUG (found live by the Global Payments plugin session,
		//October 2026), now fixed: this previously hooked View::'compile'
		//instead of 'render'. Reading core's own system/core/view.php
		//directly confirms 'compile' only fires from inside the private
		//compile() method, which is itself only CALLED when
		//checkNeedRecompile() decides a template actually needs
		//recompiling (a template/html mtime newer than the compiled
		//file, or the compiled file missing entirely) — i.e. only on a
		//genuine cache MISS. Once the compiled-template cache is warm
		//(the normal, steady-state case for every request after the
		//first), compile() is never called again and 'compile' never
		//fires — so this plugin's own finalizeForCurrentRoute() call
		//stopped running at all, and checkout pages went out carrying
		//only whatever OTHER plugins had queued (e.g. a payment gateway
		//plugin's own partial header), never this plugin's baseline or
		//its configured report-only/enforce mode. Reproduced on the
		//testbed: enforcement only ever appeared on the first request
		//after clearing the compiled-template cache, and silently
		//reverted to a partial/absent policy on every request after
		//that.
		//
		//'render' is the fix: View::render() triggers
		//Event::trigger(__CLASS__, __FUNCTION__, ...) totally
		//unconditionally, as the very first line of render(), on EVERY
		//request regardless of compile-cache state (confirmed from the
		//same core file) — and render() still runs after
		//Controller\Base's 'init' has finished (it's part of producing
		//the final output, long after routing/init), so every other
		//plugin's own init-phase CSP header() call has still already
		//queued whatever it's going to queue by the time this fires.
		//Same "last word, no cooperation required" property as before,
		//just on a hook that actually fires every time. See
		//system/csp.php's class docblock for the merge mechanics.
		//
		//render() can still fire more than once per request (once per
		//rendered template/component), same as 'compile' could — each
		//firing just re-merges whatever is queued with the route's
		//profile and re-sends, which is cheap and idempotent
		//(array_unique dedupes), so no own-firing guard is needed here
		//either.
		Event::on('Vvveb\System\Core\View', 'render', __CLASS__, function ($template, $tplFile, $templateEngine, $view) {
			Csp::finalizeForCurrentRoute();

				//Out-of-line Speculation Rules header for the checkout
				//page — moved here from the PayPal Smart Buttons plugin so
				//it no longer depends on any one payment plugin being
				//installed. See Csp::applySpeculationRules()'s docblock.
				Csp::applySpeculationRules(null, method_exists($view, 'getTheme') ? $view->getTheme() : null);

			return [$template, $tplFile, $templateEngine, $view];
		});

		//the CSP violation-report endpoint (see app/controller/index.php) —
		//essential in report-only mode, since that's the only way to see
		//what the policy WOULD have blocked before switching to enforce.
		Routes::addRoute('/csp-manager-report', ['module' => 'plugins/csp-manager/index/report']);

		//checkout speculation rules, served as application/speculationrules+json
		//(see Csp::SPECULATION_RULES_ROUTE)
		Routes::addRoute(Csp::SPECULATION_RULES_ROUTE, ['module' => 'plugins/csp-manager/index/speculationRules']);

		//Routes::match() does a plain sequential, first-match-wins scan with
		//no precedence/specificity sorting, and core's own generic catch-all
		//pattern '/{slug}' (module content/post/index) sits ahead of any
		//route a plugin adds later via addRoute() — confirmed live: a request
		//to /csp-manager-report matched '/{slug}' (slug=csp-manager-report)
		//and never reached this plugin's own entry, even once it was
		//genuinely present in Routes::$urls. Hooking the same core event
		//Routes::init()'s cache-miss branch fires right before it rebuilds
		//Routes::$urls lets us move our path to the FRONT of that array—
		//see Csp::moveRoutesToFront()'s docblock.
		Event::on('Vvveb\System\Routes', 'init', __CLASS__, function ($routes) {
			Csp::resetRoutesUrlsCache();

			return [Csp::moveRoutesToFront($routes, ['/csp-manager-report', Csp::SPECULATION_RULES_ROUTE])];
		});

		//Works around a confirmed Vvveb core routing/caching bug — see
		//Csp::ensureRoutesCacheIncludes()'s docblock. Without this,
		///csp-manager-report 404s on every single request (confirmed
		//live: the browser's own CSP violation reports — the whole
		//point of report-only mode — were silently never reaching this
		//plugin at all), no matter how many times any cache is cleared.
		Csp::ensureRoutesCacheIncludes(['/csp-manager-report', Csp::SPECULATION_RULES_ROUTE]);

		//Theme onclick="..." handlers (togglePasswordInput, toggleLoginForm,
		//toggleRegister, toggleShippingAddress) can't reliably be removed by
		//editing a page's own template file: Vvveb\System\Component\Component
		//resolves every data-v-copy-from/data-v-save-global element by
		//replacing it with the matching element from its SOURCE file
		//(confirmed by reading component.php — both attributes are treated
		//identically, same code path) — e.g. every theme page's own copy of
		//the header/nav markup is discarded and replaced with index.html's,
		//so an edit to any page OTHER than index.html for a save-global'd
		//block is silently overwritten at render time and never reaches the
		//browser. Editing index.html itself is the obvious alternative, but
		//the site owner may not want to hand-edit a core theme file (it's
		//usually the first thing a theme update overwrites).
		//
		//Fix: operate on the fully-resolved final HTML instead of any one
		//template file, via an output buffer registered here — this plugin
		//is instantiated very early (every request, before routing/
		//dispatch), so ob_start() here wraps the ENTIRE response, after
		//every data-v-copy-from/save-global substitution and every vTpl
		//rule has already run. Where the onclick originated (index.html, a
		//copied-in global block, or a page's own markup) stops mattering.
		//Same output-buffer mechanism, three independent, separately-
		//toggleable transforms: known theme onclick="..." handlers
		//(above), — belt-and-braces alongside the Cookie Notice
		//plugin's own checkout-skip fix — that plugin's banner/script on
		//the checkout page specifically, in case that plugin's own fix
		//isn't installed/enabled, or a future/different cookie-consent
		//plugin never learned to skip checkout at all, and the
		//flagcounter.com visitor-counter widget on the checkout page
		//(its remote <img> is correctly blocked by a strict checkout
		//img-src, and PCI DSS SAQ A's minimisation principle says drop
		//it there rather than allowlist a third-party tracking host).
		//See Csp::stripCheckoutOnlyWidgets()'s /
		//Csp::stripCheckoutFlagcounter()'s docblocks for why each is
		//narrow/named rather than a generic "remove widgets" rule.
		$settings          = Csp::settings();
		$stripInline       = (bool) ($settings['strip_inline_handlers'] ?? false);
		$stripCookieNotice = (bool) ($settings['strip_checkout_cookie_notice'] ?? true);
		$stripFlagcounter  = (bool) ($settings['strip_checkout_flagcounter'] ?? true);

		if ($stripInline || $stripCookieNotice || $stripFlagcounter) {
			ob_start(function ($html) use ($stripInline, $stripCookieNotice, $stripFlagcounter, $settings) {
				if ($stripInline) {
					$html = Csp::stripKnownInlineHandlers($html);
				}

				if (Csp::isCurrentRouteCheckout($settings)) {
					if ($stripCookieNotice) {
						$html = Csp::stripCheckoutOnlyWidgets($html);
					}

					if ($stripFlagcounter) {
						$html = Csp::stripCheckoutFlagcounter($html);
					}
				}

				return $html;
			});
		}
	}

	public function __construct() {
		if (\Vvveb\isEditor()) {
			return;
		}

		if (APP == 'app') {
			$this->app();
		}
	}
}

$cspManager = new CspManager();
