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

namespace Vvveb\Plugins\CspManager\System;

use Vvveb\System\Routes;
use Vvveb\System\User\Admin;

/**
 * Route-aware, merge-only Content-Security-Policy finalizer.
 *
 * This is the "last writer" for the CSP header on every page. It never
 * assumes it's the only plugin setting one — it reads back whatever
 * Content-Security-Policy / Content-Security-Policy-Report-Only header(s)
 * are already queued (via headers_list()), at the point it runs (see
 * plugin.php's docblock for why View::compile is late enough to run after
 * every other plugin's own init-phase header() call), and unions this
 * route's configured profile into them rather than replacing them. A
 * payment gateway plugin's own merge-aware CSP class (e.g. the PayPal
 * Smart Buttons / Global Payments plugins' own system/csp.php) keeps
 * working completely unchanged whether or not this plugin is installed —
 * when it IS installed, this is simply the plugin that has the final say
 * and applies the site's configured report-only/enforce mode.
 *
 * Profile selection: exactly one of two profiles applies to a given
 * request — 'checkout' if the current route/module matches the
 * configured checkout_routes list, otherwise 'default'. Because a single
 * HTTP response only ever renders one route, there is never a need to mix
 * a strict and a lax policy within one response — this just has to pick
 * the right one, once, per request.
 */
class Csp {
	private const NAMESPACE = 'csp_manager';

	/**
	 * Out-of-line Speculation Rules document for the checkout page's own
	 * links (prefetch/prerender hints; see applySpeculationRules()'s
	 * docblock for what this does and why it lives here rather than in a
	 * payment gateway plugin).
	 *
	 * Deliberately not a user-editable settings field: adding a new field
	 * to Csp::defaults() without also adding its #id binding to both
	 * admin/template/settings.tpl and public/admin/settings.html would make
	 * save()'s generic loop clear it on every save. A theme customises the
	 * rules by shipping its own js/checkout-rules.json instead.
	 *
	 * This is the route that serves those rules (see
	 * speculationRulesFile() and Controller\Index::speculationRules()).
	 * Browsers only accept a Speculation-Rules file served as
	 * application/speculationrules+json; a static .json file normally goes
	 * out as application/json and is silently ignored, so the rules are
	 * served by this plugin with the right type instead of linked directly.
	 */
	const SPECULATION_RULES_ROUTE = '/csp-manager-speculation-rules';

	/** Where a theme can put its own rules, relative to the theme folder. */
	private const THEME_RULES_FILE = 'js/checkout-rules.json';

	/** Theme that held the rules file before 0.4 (kept as a fallback). */
	private const LEGACY_RULES_THEME = 'landing';

	/**
	 * The rules file to serve: the active theme's js/checkout-rules.json,
	 * then the Landing theme's (where earlier versions looked), then the copy
	 * bundled with this plugin (the same rules Landing puts inline on its
	 * pages). Files that aren't valid JSON are skipped.
	 */
	public static function speculationRulesFile(?string $theme = null): ?string {
		$candidates = [];

		if ($theme && preg_match('/^[\w.-]+$/', $theme)) {
			$candidates[] = DIR_THEME . $theme . '/' . self::THEME_RULES_FILE;
		}

		$candidates[] = DIR_THEME . self::LEGACY_RULES_THEME . '/' . self::THEME_RULES_FILE;
		$candidates[] = dirname(__DIR__) . '/checkout-rules.json';

		foreach (array_unique($candidates) as $file) {
			if (is_file($file) && is_array(json_decode((string) @file_get_contents($file), true))) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * The URL for the Speculation-Rules header, or null if there are no rules.
	 */
	public static function speculationRulesUrl(?string $theme = null): ?string {
		return self::speculationRulesFile($theme) ? self::SPECULATION_RULES_ROUTE : null;
	}

	/**
	 * Directive names this plugin's settings cover, in the form used in
	 * setting keys (underscored) — translated to real CSP directive names
	 * (hyphenated) when the header is built.
	 */
	private const DIRECTIVES = [
		'default_src',
		'script_src',
		'style_src',
		'img_src',
		'font_src',
		'frame_src',
		'connect_src',
		'base_uri',
		'object_src',
		'form_action',
		'frame_ancestors',
	];

	public static function defaults(): array {
		$defaults = [
			'mode'                    => 'report-only',
			'report_uri'              => '/csp-manager-report',
			'checkout_routes'         => 'checkout/checkout/index',
			'notify_email'            => '',
			'notify_throttle_seconds' => 300,
			'strip_inline_handlers'   => '0',
			'strip_checkout_cookie_notice' => '1',
			'strip_checkout_flagcounter' => '1',
		];

		//strict baseline for the checkout page — deliberately minimal;
		//payment gateway plugins merge their own required hosts/nonces on
		//top of this via the mechanism described above
		$checkout = [
			'default_src'     => "'self'",
			'script_src'      => "'self'",
			'style_src'       => "'self' 'unsafe-inline'",
			'img_src'         => "'self' data:",
			'font_src'        => "'self' data:",
			'frame_src'       => "'self'",
			'connect_src'     => "'self'",
			'base_uri'        => "'self'",
			'object_src'      => "'none'",
			'form_action'     => "'self'",
			'frame_ancestors' => "'self'",
		];

		//sensible-but-editable starting point for the rest of the site —
		//the site owner is expected to loosen/tighten this from the
		//settings page for whatever the theme/other plugins actually need
		//(analytics, chat widgets, embeds, etc.)
		$default = [
			'default_src'     => "'self'",
			'script_src'      => "'self' 'unsafe-inline'",
			'style_src'       => "'self' 'unsafe-inline'",
			'img_src'         => "'self' data: https:",
			'font_src'        => "'self' data: https:",
			'frame_src'       => "'self' https:",
			'connect_src'     => "'self' https:",
			'base_uri'        => "'self'",
			'object_src'      => "'none'",
			'form_action'     => "'self'",
			'frame_ancestors' => "'self'",
		];

		foreach ($checkout as $directive => $value) {
			$defaults['checkout_' . $directive] = $value;
		}

		foreach ($default as $directive => $value) {
			$defaults['default_' . $directive] = $value;
		}

		return $defaults;
	}

	public static function settings(): array {
		//getSetting() with $key = null returns only the rows actually
		//saved — never assume every key is present (a fresh install has
		//none yet), so merge over the defaults rather than relying on it.
		return \Vvveb\getSetting(self::NAMESPACE, null, []) + self::defaults();
	}

	public static function finalizeForCurrentRoute(): void {
		if (headers_sent()) {
			return;
		}

		$settings = self::settings();

		$profile = self::isCurrentRouteCheckout($settings) ? 'checkout' : 'default';

		$baseline = [];

		foreach (self::DIRECTIVES as $directive) {
			$value = trim((string) ($settings[$profile . '_' . $directive] ?? ''));

			if ($value !== '') {
				$baseline[str_replace('_', '-', $directive)] = preg_split('/\s+/', $value);
			}
		}

		$merged = self::parseExisting();

		foreach ($baseline as $name => $sources) {
			$merged[$name] = array_values(array_unique(array_merge($merged[$name] ?? [], $sources)));
		}

		$reportUri = trim((string) ($settings['report_uri'] ?? ''));

		if ($reportUri !== '') {
			$reportUris = $merged['report-uri'] ?? [];

			if (! in_array($reportUri, $reportUris, true)) {
				$reportUris[] = $reportUri;
			}

			$merged['report-uri'] = $reportUris;
		}

		$policy = [];

		foreach ($merged as $name => $sources) {
			$policy[] = $name . ' ' . implode(' ', $sources);
		}

		$headerValue = implode('; ', $policy);
		$enforce     = ($settings['mode'] ?? 'report-only') === 'enforce';

		//only one of the two header names should ever reach the browser —
		//sending both would mean BOTH get enforced/reported independently,
		//which isn't what a single configured mode means
		if ($enforce) {
			header_remove('Content-Security-Policy-Report-Only');
			header('Content-Security-Policy: ' . $headerValue, true);
		} else {
			header_remove('Content-Security-Policy');
			header('Content-Security-Policy-Report-Only: ' . $headerValue, true);
		}
	}

	/**
	 * Sends the HTTP response header that loads an out-of-line Speculation
	 * Rules document on the checkout page — a JSON file telling the
	 * browser which same-origin links it's safe to prefetch/prerender in
	 * the background, purely a perceived-speed optimization (the browser
	 * starts fetching — or fully rendering, for "prerender" — a page the
	 * visitor is statistically likely to click next, e.g. a content page
	 * linked from checkout, so the navigation feels instant when they
	 * actually click it). It has no effect on anything this plugin or any
	 * payment plugin actually does; a browser that doesn't support the
	 * Speculation Rules API (or that has the header blocked/stripped
	 * somewhere) just never prefetches anything extra — nothing breaks.
	 *
	 * CORRECTION carried over from the PayPal Smart Buttons plugin, where
	 * this lived originally: an earlier pass had the theme load the rules
	 * via `<script type="speculationrules" src="...">`. That is NOT a real
	 * mechanism — confirmed live (Chrome raises an error on the tag and
	 * never requests the file; the `src` attribute simply isn't supported
	 * for this script type, regardless of the referenced file's MIME
	 * type). The only way to load an out-of-line speculation-rules
	 * document is this response header, whose value is a Structured
	 * Fields List of double-quoted URL strings (sf-string syntax — hence
	 * the literal quote marks below). The referenced JSON file still needs
	 * to be served as `Content-Type: application/speculationrules+json`
	 * (the `.htaccess` <Files>/ForceType rule already added for
	 * /themes/landing/js/checkout-rules.json covers that) — that part of
	 * the original fix was correct; only the delivery mechanism was wrong.
	 *
	 * MOVED HERE from the PayPal Smart Buttons plugin's own plugin.php
	 * (October 2026): that plugin sent this header from its own
	 * route-gated 'Vvveb\Controller\Base' 'init' handler, which means the
	 * header — and therefore the whole prefetch optimization — silently
	 * stopped being sent the moment that plugin was uninstalled or
	 * disabled, even though neither the JSON file, the .htaccess rule, nor
	 * the theme's own checkout links have anything to do with any specific
	 * payment gateway. This plugin is the right, decoupled home for it:
	 * it's already the centralized, always-applies-to-checkout coordinator
	 * for route-gated response headers (see the class docblock), it
	 * already has a working, tested isCurrentRouteCheckout() check, and
	 * it's called from the same 'render' event (every request,
	 * unconditionally — see plugin.php) that the main CSP header uses, so
	 * headers_sent()/routing timing are already known-good here. A site
	 * that uninstalls CSP Manager loses its CSP header entirely too (each
	 * payment plugin's own fallback CSP header is the only thing left in
	 * that case) — speculation rules sharing that same fate is consistent
	 * with the rest of this plugin's role, rather than a new, separate
	 * single point of failure.
	 */
	public static function applySpeculationRules(?array $settings = null, ?string $theme = null): void {
		if (headers_sent()) {
			return;
		}

		if (! self::isCurrentRouteCheckout($settings)) {
			return;
		}

		$url = self::speculationRulesUrl($theme);

		if ($url !== null) {
			header('Speculation-Rules: "' . $url . '"');
		}
	}

	/**
	 * Emails a throttled summary of one CSP violation report to the
	 * configured developer/admin address, if one is set. Called from
	 * app/controller/index.php's report() action, in addition to (not
	 * instead of) the existing error_log() — the log stays the complete,
	 * untruncated record; this is just an alert that something landed
	 * there.
	 *
	 * CONFIRMED from reading the real \Vvveb\System\Email,
	 * \Vvveb\System\Mail\Smtp and \Vvveb\Controller\Settings\Email classes:
	 * \Vvveb\email() is NOT limited to rendering a named core/plugin
	 * template — Settings\Email::test() (the site's own "send a test
	 * email" admin action) calls it as
	 * email($to, $subject, ['html' => $message, 'txt' => $message], [],
	 * [], null, SITE_ID, ['debug' => true]), i.e. the third argument can be
	 * a plain ['html' => ..., 'txt' => ...] array of ready-made content
	 * instead of a template name. That's exactly what a one-off plain-text
	 * alert like this needs, with no plugin template file required.
	 *
	 * This also fixes the original SMTP gap raw mail() had: \Vvveb\email()
	 * routes through \Vvveb\System\Email::getInstance(), which picks its
	 * driver from config('mail.driver') — 'mail' (PHP's mail(), same local-
	 * MTA limitation as before) or 'smtp' (\Vvveb\System\Mail\Smtp, a real
	 * SMTP client with host/port/user/password/TLS support), whichever the
	 * site has configured under Admin → Settings → Email. Using
	 * \Vvveb\email() instead of raw mail() means this notification
	 * automatically follows that same site-wide configuration — if SMTP is
	 * set up there, this now actually goes out over SMTP; if not, it falls
	 * back to the same local-MTA mail() behaviour as before.
	 *
	 * Wrapped in try/catch because Email::send() throws (not just returns
	 * false) if a required field — including 'from'/'sender', which
	 * \Vvveb\email() is expected to fill from the site's configured sender
	 * identity — ends up missing; a misconfigured mail setup must never
	 * take down the 204 response this method's caller still owes the
	 * browser for the report-uri POST (that caller already wraps this
	 * whole call in its own try/catch too, as defense in depth).
	 *
	 * Throttled via a single 'notify_last_sent_at' row under this plugin's
	 * own namespace (not part of defaults()/settings() — it's internal
	 * bookkeeping, not a user-facing field) so a burst of reports (e.g.
	 * right after switching to Enforce) sends at most one email per
	 * notify_throttle_seconds, however many reports actually arrive in
	 * that window.
	 */
	public static function notify(array $report, string $rawBody): void {
		$settings = self::settings();
		$to       = trim((string) ($settings['notify_email'] ?? ''));

		if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return;
		}

		/**
		 * Suppress the EMAIL (never the error_log() call — report()
		 * already wrote the full report before calling this method,
		 * regardless of what happens here) whenever the violation report
		 * itself arrived from a browser that's currently got an admin
		 * session. The report-uri POST is same-origin, so it always
		 * carries whatever session cookie the reporting page's own tab
		 * had — Admin::current() reads that same session the exact same
		 * way admin-bar.php does to decide whether to render the toolbar
		 * at all, so this is a precise, non-guessing signal: a real,
		 * anonymous shopper's browser can never satisfy it.
		 *
		 * This exists because Vvveb core's own admin toolbar
		 * (admin/admin-bar.php, injected into every storefront page only
		 * while an admin is logged in) ships a bare `<script>` tag with no
		 * nonce — confirmed, by reading that file directly, to be the
		 * exact source of a recurring script-src-elem/"inline" violation
		 * at a fixed line number. That's a core limitation outside any
		 * plugin's reach (admin-bar.php isn't something a plugin can
		 * patch), not a bug in this site's CSP policy or in any payment
		 * plugin — it will keep firing, by design, for as long as an
		 * admin browses the storefront with CSP enforced. The right
		 * response is to stop it from paging someone every time the site
		 * owner tests their own checkout while logged in, not to loosen
		 * the policy or keep eating the noise.
		 *
		 * A second, separate script-src-attr/"inline" violation reported
		 * alongside this one did NOT come from admin-bar.php — that file
		 * contains no onclick/inline-attribute handler at all (confirmed
		 * by reading it in full) — so this suppression is a reasonable,
		 * but not yet fully confirmed, fix for it too: if it also only
		 * ever fires during an admin session (e.g. from the visual page
		 * editor's own element overlay, which only renders for a logged-
		 * in admin), this same check covers it for the same reason. If
		 * reports of it keep arriving from requests where Admin::current()
		 * is genuinely empty (a real anonymous shopper), that would mean
		 * it has a second, independent, real cause — e.g. a theme onclick
		 * pattern stripKnownInlineHandlers() doesn't yet know about — and
		 * needs its own fix there (the error_log is still the place to
		 * find its exact source-file/line-number/column-number once it's
		 * known to still be happening for a non-admin visitor).
		 */
		if (Admin::current()) {
			return;
		}

		$throttle = (int) ($settings['notify_throttle_seconds'] ?? 300);
		$lastSent = (int) \Vvveb\getSetting(self::NAMESPACE, 'notify_last_sent_at', 0);

		if ($throttle > 0 && (time() - $lastSent) < $throttle) {
			return;
		}

		$data               = $report['csp-report'] ?? $report;
		$documentUri        = $data['document-uri'] ?? 'unknown';
		$violatedDirective  = $data['violated-directive'] ?? 'unknown';
		$blockedUri         = $data['blocked-uri'] ?? 'unknown';
		$enforcing          = ($settings['mode'] ?? 'report-only') === 'enforce';

		$subject = 'CSP violation (' . ($enforcing ? 'blocked' : 'report-only') . '): ' . $violatedDirective;

		$text = "A Content-Security-Policy violation was reported.\n\n"
			. "Document URI: {$documentUri}\n"
			. "Violated directive: {$violatedDirective}\n"
			. "Blocked URI: {$blockedUri}\n"
			. 'Mode: ' . ($enforcing ? 'enforce (this request was actually blocked)' : 'report-only (not blocked, logged only)') . "\n\n"
			. "Full report:\n{$rawBody}\n\n"
			. "Further reports are throttled to at most one email every {$throttle} seconds while they keep arriving; "
			. 'every individual report is still written to the PHP error log regardless of this email.';

		$html = nl2br(htmlspecialchars($text));

		try {
			$siteId = defined('SITE_ID') ? SITE_ID : null;

			\Vvveb\email($to, $subject, ['html' => $html, 'txt' => $text], [], [], null, $siteId, []);
		} catch (\Throwable $e) {
			//same reasoning as the error_log() calls elsewhere in this
			//plugin: a failed notification must be visible somewhere, even
			//though it can never be allowed to break the caller's response
			error_log('csp_manager [notify-send-error] ' . $e->getMessage());

			return;
		}

		\Vvveb\setMultiSetting(self::NAMESPACE, ['notify_last_sent_at' => time()]);
	}

	/**
	 * Rewrites a handful of KNOWN, exact onclick="..." attributes shipped by
	 * the storefront theme into either a data-* attribute (for the password-
	 * visibility toggle, which needs its second argument preserved) or
	 * nothing at all (the others — a delegated listener keyed on their
	 * existing element ids covers them with no replacement attribute
	 * needed). See plugin.php's docblock on why this runs as an output-
	 * buffer transform of the fully-resolved HTML rather than a template
	 * edit: data-v-copy-from/data-v-save-global means the real markup for
	 * many of these elements is never the requesting page's own file.
	 *
	 * Deliberately narrow and literal (exact function-call patterns, not a
	 * generic onclick stripper). This is only ever registered for APP ==
	 * 'app' requests (see plugin.php) — i.e. storefront, never admin — so
	 * it can't touch the admin's own legitimate onclick="addTab(this)"/
	 * "removeTab(this, ...)" (used by the multi-instance settings-tab UI
	 * documented in the project notes) even by accident. Staying this
	 * literal is extra insurance on top of that route-level gate: a
	 * broader regex risks corrupting unrelated, legitimate onclick usage
	 * anywhere else on the storefront that this project hasn't inventoried.
	 *
	 * Skips non-HTML responses (an AJAX endpoint returning JSON, etc.) via
	 * the Content-Type response header already queued at the point this
	 * runs — applying an HTML-oriented regex to a JSON body is harmless in
	 * practice (these exact onclick strings won't appear in JSON) but
	 * skipping is cheap and avoids the question entirely.
	 */
	public static function stripKnownInlineHandlers(string $html): string {
		foreach (headers_list() as $header) {
			if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
				return $html;
			}
		}

		//togglePasswordInput(this, 'target-id') -> data-toggle-password="target-id"
		$html = preg_replace(
			'/\sonclick="togglePasswordInput\(this,\s*\'([^\']+)\'\)"/',
			' data-toggle-password="$1"',
			$html
		);

		//toggleLoginForm() / toggleRegister(this) / toggleShippingAddress(this)
		//— no argument worth preserving, just delete the attribute
		$html = preg_replace(
			'/\sonclick="toggle(?:LoginForm\(\)|Register\(this\)|ShippingAddress\(this\))"/',
			'',
			$html
		);

		return $html;
	}

	/**
	 * True if the current request's route/module matches one of the
	 * configured checkout_routes (space/comma-separated). Shared by
	 * finalizeForCurrentRoute()'s profile selection and by
	 * stripCheckoutOnlyWidgets()'s own gating below, so both only ever
	 * disagree about what "checkout" means if a future edit changes one
	 * and not the other.
	 */
	public static function isCurrentRouteCheckout(?array $settings = null): bool {
		$settings = $settings ?? self::settings();

		$url    = Routes::getUrlData();
		$module = $url['module'] ?? '';

		$checkoutRoutes = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $settings['checkout_routes'])));

		return in_array($module, $checkoutRoutes, true);
	}

	/**
	 * Belt-and-braces removal of the (separately-maintained) Cookie Notice
	 * plugin's banner + script from the checkout page, independent of
	 * whether that plugin's own checkout-skip logic is installed, enabled,
	 * or even present at all (a future/different cookie-consent plugin that
	 * never learned to skip checkout would be caught here too, as long as
	 * it reuses the same #cookie-notice / #cookie-notice-js ids — a plugin
	 * using different ids would need its own entry here, same as
	 * stripKnownInlineHandlers() only knows specific, named patterns).
	 *
	 * Only ever called for a route that isCurrentRouteCheckout() has
	 * already confirmed — see plugin.php's gating. A cookie-consent banner
	 * is unrelated to accepting payment, any choice was already captured
	 * earlier in the session on another page, and removing it here also
	 * shrinks the checkout page's own CSP/script surface a little further
	 * and avoids any chance of it overlapping the payment method's
	 * accordion panel.
	 *
	 * Uses a depth-counting scan rather than a single regex over nested
	 * <div>s — #cookie-notice is three levels deep in the shipped plugin,
	 * and a non-greedy/greedy regex assumption about exact nesting breaks
	 * the moment that markup changes. Bails out and returns the HTML
	 * UNCHANGED if it can't find a balanced closing tag, rather than risk
	 * truncating or corrupting the rest of the page.
	 */
	public static function stripCheckoutOnlyWidgets(string $html): string {
		foreach (headers_list() as $header) {
			if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
				return $html;
			}
		}

		$html = self::stripBalancedDivById($html, 'cookie-notice');

		//every <script>/<style> whose id starts with cookie-notice: the old
		//plugin's inline or external #cookie-notice-js, and the Vvveb 1.0.9.0
		//plugin's #cookie-notice-config (inline settings or JSON data block),
		//#cookie-notice-consent-default (inline or external Consent Mode / UET
		//default), #cookie-notice-js and #cookie-notice-style. Script and style
		//elements never nest, so a non-greedy match is safe; the id may be
		//anywhere in the tag
		$html = preg_replace(
			'/<(script|style)\b[^>]*\bid="cookie-notice[\w-]*"[^>]*>.*?<\/\1\s*>/is',
			'',
			$html
		) ?? $html;

		//the 1.0.9.0 plugin's revisit (cookie settings) button; buttons can't nest
		$html = preg_replace(
			'/<button\b[^>]*\bid="cookie-notice-revisit"[^>]*>.*?<\/button\s*>/is',
			'',
			$html
		) ?? $html;

		return $html;
	}

	/**
	 * Strips the flagcounter.com visitor-counter widget from the checkout
	 * page specifically. It loads a remote <img> from s01.flagcounter.com,
	 * which a strict checkout img-src correctly blocks — confirmed live on
	 * the user's own site (a blocked GET to
	 * s01.flagcounter.com/count2/bTfc/...). Rather than allowlisting a
	 * third-party tracking host under img-src for the one page PCI DSS
	 * SAQ A asks to keep minimal, this simply doesn't render the widget
	 * there — it still shows normally on every other page. Narrow/named,
	 * same rationale as stripCheckoutOnlyWidgets() above: targets this
	 * one widget's known wrapper class, not a generic "strip widgets" rule.
	 *
	 * Only ever called for a route isCurrentRouteCheckout() has already
	 * confirmed (see plugin.php's gating), same as stripCheckoutOnlyWidgets().
	 *
	 * The wrapper (`<div class="col-md visitors">...</div>`) has no nested
	 * <div> inside it in the shipped footer markup — just an <a><img></a> —
	 * so, unlike stripCheckoutOnlyWidgets()'s #cookie-notice removal, a
	 * plain non-greedy regex is safe here without needing
	 * stripBalancedDivById()'s depth-counting. Keyed on the literal
	 * "visitors" class token (not "col-md" alone, a plain Bootstrap grid
	 * class that legitimately appears elsewhere on the page), limited to
	 * one replacement in case of any future accidental duplication.
	 */
	public static function stripCheckoutFlagcounter(string $html): string {
		foreach (headers_list() as $header) {
			if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
				return $html;
			}
		}

		return preg_replace(
			'/<div[^>]*\bclass="[^"]*\bvisitors\b[^"]*"[^>]*>.*?<\/div>/is',
			'',
			$html,
			1
		) ?? $html;
	}

	/**
	 * Finds <div id="$id" ...> and removes it along with everything up to
	 * its true matching </div>, by counting nested <div>/</div> tags rather
	 * than assuming a fixed nesting depth. Returns $html unchanged if the
	 * id isn't found or a balanced close can't be located.
	 */
	private static function stripBalancedDivById(string $html, string $id): string {
		$start = stripos($html, '<div id="' . $id . '"');

		if ($start === false) {
			return $html;
		}

		$openTagEnd = strpos($html, '>', $start);

		if ($openTagEnd === false) {
			return $html;
		}

		$pos   = $openTagEnd + 1;
		$depth = 1;
		$len   = strlen($html);

		while ($depth > 0 && $pos < $len) {
			$nextOpen  = stripos($html, '<div', $pos);
			$nextClose = stripos($html, '</div>', $pos);

			if ($nextClose === false) {
				//malformed/unexpected markup — bail out rather than mangle the page
				return $html;
			}

			if ($nextOpen !== false && $nextOpen < $nextClose) {
				$depth++;
				$pos = $nextOpen + 4;
			} else {
				$depth--;
				$pos = $nextClose + 6;
			}
		}

		return substr($html, 0, $start) . substr($html, $pos);
	}

	/**
	 * @return array<string, string[]>
	 */
	private static function parseExisting(): array {
		$result = [];

		foreach (headers_list() as $header) {
			if (stripos($header, 'Content-Security-Policy-Report-Only:') === 0) {
				$value = trim(substr($header, strlen('Content-Security-Policy-Report-Only:')));
			} elseif (stripos($header, 'Content-Security-Policy:') === 0) {
				$value = trim(substr($header, strlen('Content-Security-Policy:')));
			} else {
				continue;
			}

			foreach (explode(';', $value) as $part) {
				$part = trim($part);

				if ($part === '') {
					continue;
				}

				$tokens = preg_split('/\s+/', $part);
				$name   = array_shift($tokens);
				$result[$name] = array_values(array_unique(array_merge($result[$name] ?? [], $tokens)));
			}
		}

		return $result;
	}

	/**
	 * Works around a confirmed Vvveb core routing/caching bug — found live
	 * in this project while tracking down why /csp-manager-report (and
	 * every custom route the PayPal Smart Buttons plugin registers) 404s on
	 * every single request, even immediately after clearing every cache:
	 *
	 * - system/core/startup.php's start() calls Sites::getSiteData() before
	 *   Plugins::loadPlugins() — and getSiteData() triggers the FIRST
	 *   Routes::init() call of the request (for site/language routing),
	 *   which — on a cache miss — builds the route table from
	 *   config/app-routes.php + core's own 'Routes::init' event ONLY,
	 *   since no plugin has run yet, then WRITES that plugin-free table to
	 *   the 'routes' cache namespace.
	 * - Plugins::loadPlugins() then runs (still within the same request),
	 *   and this plugin's own Routes::addRoute() call correctly appends
	 *   /csp-manager-report to Routes' in-memory $routes/$urls.
	 * - But system/core/frontcontroller.php's dispatch() then calls
	 *   Routes::init(APP, $routesSiteId) AGAIN, unconditionally (not
	 *   guarded by Routes' own $init flag the way match()/getRouteData()
	 *   are) — and since step 1 already wrote a cache entry under the same
	 *   key, this is a cache HIT: Routes::init()'s cache-hit branch does a
	 *   bare `static::$routes = $result['routes']` (no merge), which
	 *   OVERWRITES our just-added route with the stale, plugin-free table
	 *   from step 1 — right before Routes::match() runs to resolve THIS
	 *   request's own module. Confirmed via direct instrumentation of
	 *   Routes::match() on a fresh Vvveb install: total registered routes
	 *   stayed at the core's own count across every match() call in the
	 *   request, both before AND after this plugin's app() ran.
	 * - Because step 1 always reprimes the SAME stale, plugin-free cache
	 *   entry before plugins get a chance on every subsequent request too,
	 *   clearing the cache (Tools → Clear cache, or deleting
	 *   storage/cache/* by hand) does NOT fix this even temporarily — the
	 *   very next request rebuilds the identical plugin-free table before
	 *   Plugins::loadPlugins() ever runs.
	 *
	 * Fix: after registering our route(s), check the cache entry that step
	 * 3 above is about to blindly reload. If it's missing any of the given
	 * paths, delete it — forcing frontcontroller's Routes::init() call to
	 * be a genuine cache MISS instead of a HIT. Its cache-miss path does
	 * `self::$routes += include $routesConfig` — array union, which
	 * preserves keys already in self::$routes (ours, just added by
	 * addRoute() above) rather than discarding them — so it rebuilds the
	 * FULL, correct table (core routes + ours) and re-caches THAT version.
	 * From the very next request onward, step 1's own cache-hit is already
	 * correct, so this self-heals after a single request and adds no
	 * per-request cost once healed (the cheap isset() check below is all
	 * that runs on every normal request afterward).
	 *
	 * Reaches into Vvveb\System\Cache directly (the same cache
	 * namespace/key Routes::init() itself uses) since Routes has no public
	 * API to invalidate just its own cache entry. Wrapped in try/catch —
	 * this is purely a cache-freshness nicety; it must never be able to
	 * break the request if the cache driver behaves unexpectedly.
	 *
	 * Identical in spirit to the PayPal Smart Buttons plugin's own copy of
	 * this helper (each plugin only knows its own routes, and plugins
	 * shouldn't depend on one another, so this is intentionally
	 * duplicated rather than shared).
	 */
	public static function ensureRoutesCacheIncludes(array $paths): void {
		try {
			$cache = \Vvveb\System\Cache::getInstance();

			//Routes::init()'s own cache key is $site_id if truthy, else
			//$app ('app') — we don't know which one frontcontroller's call
			//resolved to without reaching into its private state, so check
			//(and bust) every key it could plausibly be under.
			$candidateKeys = array_values(array_unique(array_filter([
				defined('APP') ? APP : null,
				defined('SITE_ID') ? (string) SITE_ID : null,
			])));

			foreach ($candidateKeys as $key) {
				$cached = $cache->get('routes', $key);

				if (! is_array($cached) || ! isset($cached['urls']) || ! is_array($cached['urls'])) {
					continue; // nothing cached yet under this key — core will build it fresh, nothing to fix
				}

				foreach ($paths as $path) {
					if (! isset($cached['urls'][$path])) {
						$cache->delete('routes', $key);

						break; // this key's cache is stale; move to the next candidate key
					}
				}
			}
		} catch (\Throwable $e) {
			error_log('csp_manager [route-cache-fix-error] ' . $e->getMessage());
		}
	}

	/**
	 * Moves the given keys to the front of $routes, preserving their
	 * relative order and leaving every other key where it was.
	 *
	 * Routes::match() is a plain sequential, first-match-wins foreach over
	 * Routes::$urls, with no precedence/specificity sorting at all. Core's
	 * own config/app-routes.php gets merged into Routes::$routes FIRST
	 * (during the earliest Routes::init() call of the request, which runs
	 * before any plugin loads — see ensureRoutesCacheIncludes()'s
	 * docblock), and a plugin's own Routes::addRoute() calls only ever
	 * APPEND new keys afterward. That means a generic catch-all pattern
	 * like '/{slug}' (core's own CMS page/post route) sits ahead of
	 * anything a plugin registers, and matches first — confirmed live: a
	 * request to /csp-manager-report matched '/{slug}' (as
	 * slug=csp-manager-report, module content/post/index) and never
	 * reached this plugin's own entry, even once it was genuinely present
	 * in the table (i.e. even with the cache-freshness fix above already
	 * applied).
	 *
	 * Fix: call this from inside an Event::on('Vvveb\System\Routes',
	 * 'init', ...) handler — the SAME core event Routes::init()'s
	 * cache-miss branch already fires via
	 * `list(self::$routes) = Event::trigger('Vvveb\System\Routes', 'init',
	 * self::$routes)`, right before it rebuilds Routes::$urls from
	 * Routes::$routes. Moving our path(s) to the front there fixes the
	 * match order for that rebuild AND for whatever gets cached
	 * afterward — so once this fires on one genuine cache-miss rebuild
	 * (which ensureRoutesCacheIncludes() guarantees happens whenever
	 * needed), every later cache-HIT request loads the
	 * already-correctly-ordered table, at no further cost.
	 */
	/**
	 * Clears Vvveb\System\Routes' private static $urls array via
	 * Reflection, right before core's own Routes::init() rebuilds it.
	 *
	 * Reordering Routes::$routes (moveRoutesToFront() below) alone isn't
	 * enough: Routes::init()'s cache-miss branch rebuilds $urls by calling
	 * processRoute() for every entry in $routes, but processRoute() just
	 * does `self::$urls[$route] = [...]` — and reassigning a value to an
	 * ALREADY-EXISTING array key does not change that key's position in a
	 * PHP array, it only updates the value in place. Because $urls already
	 * contains every one of these keys by this point in the request (core's
	 * own earlier, pre-plugin Routes::init() call already populated it —
	 * see ensureRoutesCacheIncludes()'s docblock — and this plugin's own
	 * addRoute() call appended ours after that), simply re-running
	 * processRoute() in the new, reordered $routes order has NO effect on
	 * $urls' actual iteration order (confirmed live: $routes correctly
	 * showed our path first after this event fired, but $urls still listed
	 * core's routes first immediately afterward). Clearing $urls first
	 * forces the upcoming foreach to insert every key fresh, in the SAME
	 * order it now iterates the reordered $routes — which is the only way
	 * to actually change $urls' match order.
	 */
	public static function resetRoutesUrlsCache(): void {
		try {
			$prop = new \ReflectionProperty(\Vvveb\System\Routes::class, 'urls');
			$prop->setAccessible(true);
			$prop->setValue(null, []);
		} catch (\Throwable $e) {
			error_log('csp_manager [route-order-fix-error] ' . $e->getMessage());
		}
	}

	public static function moveRoutesToFront(array $routes, array $keys): array {
		$prefix = [];

		foreach ($keys as $key) {
			if (array_key_exists($key, $routes)) {
				$prefix[$key] = $routes[$key];
				unset($routes[$key]);
			}
		}

		return $prefix + $routes;
	}
}
