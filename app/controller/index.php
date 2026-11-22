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

namespace Vvveb\Plugins\CspManager\Controller;

use Vvveb\Plugins\CspManager\System\Csp;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

/**
 * CONFIRMED (live, this project): the front controller dispatches every
 * routed module target the same way regardless of what the target class
 * extends — it assigns $this->request/$response/$view/$session onto the
 * controller instance unconditionally (frontcontroller.php, around
 * line 145-148). This class doesn't use any of those four, but on PHP 8.2+
 * assigning to a property a class never declared is a deprecated "dynamic
 * property" — logged (and, on an install with display_errors on, echoed
 * into the response body) once per property, per request, for any class
 * that doesn't declare them. Declaring the four properties below (even
 * though this class never reads them) is the fix: it satisfies the front
 * controller's assignment without actually needing to extend
 * Vvveb\Controller\Base or know its real constructor/behaviour.
 */
class Index {
	public $request;
	public $response;
	public $view;
	public $session;

	/**
	 * POST /csp-manager-report
	 * Receives the browser's CSP violation reports (fired in both
	 * report-only and enforcing mode, for whichever profile — checkout or
	 * default — applied to that request) and logs them for review. This is
	 * the only way to see what a report-only policy WOULD have blocked
	 * before switching csp_manager's mode setting to "enforce". Always
	 * acknowledges with 204 regardless of whether the body parsed, since
	 * the browser does nothing with the response.
	 */
	public function report() {
		$body   = file_get_contents('php://input');
		$report = json_decode($body, true);

		error_log('csp_manager [csp-report] ' . ($report !== null ? json_encode($report) : $body));

		if (is_array($report)) {
			try {
				Csp::notify($report, $body);
			} catch (\Throwable $e) {
				//a notification failure (bad mail config, etc.) must never
				//break the 204 the browser expects for a report-uri POST
				error_log('csp_manager [notify-error] ' . $e->getMessage());
			}
		}

		//defensive: something else on this request (another plugin's output,
		//a display_errors notice) may already have sent headers by this
		//point, in which case http_response_code() itself would throw a
		//"headers already sent" warning — the browser ignores the report
		//response either way, so just skip it rather than add more noise
		if (! headers_sent()) {
			http_response_code(204);
		}
	}

	/**
	 * GET /csp-manager-speculation-rules
	 * The rules the checkout's Speculation-Rules header points to: the
	 * active theme's js/checkout-rules.json if it has one, otherwise the
	 * copy bundled with this plugin. Sent with the
	 * application/speculationrules+json type browsers require, so no web
	 * server configuration is needed.
	 */
	public function speculationRules() {
		$theme = \Vvveb\System\Sites::getTheme();
		$file  = Csp::speculationRulesFile($theme);

		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		if (! $file) {
			http_response_code(404);
			exit;
		}

		header('Content-Type: application/speculationrules+json; charset=utf-8');
		header('Cache-Control: public, max-age=3600');
		header('X-Content-Type-Options: nosniff');

		readfile($file);
		exit;
	}
}
