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

use function Vvveb\__;
use Vvveb\Controller\Base;
use function Vvveb\deleteSetting;
use function Vvveb\setMultiSetting;
use Vvveb\Plugins\CspManager\System\Csp;
use Vvveb\System\CacheManager;

if (! defined('V_VERSION')) {
	die('Invalid request!');
}

class Settings extends Base {
	private $namespace = 'csp_manager';

	/**
	 * POST target (.../settings/save) — mirrors the PayPal Smart Buttons
	 * plugin's own controller shape: a separate save() action rather than
	 * a request-method check inside index(), and no explicit
	 * $this->view->render() call. Calling render() directly bypasses the
	 * framework's own template resolution + admin-layout wrapping (header/
	 * sidebar) — confirmed live: it was the cause of the settings page
	 * loading with no admin chrome at all.
	 */
	public function save() {
		$post     = $this->request->post;
		$settings = [];

		foreach (array_keys(Csp::defaults()) as $field) {
			$settings[$field] = trim((string) ($post[$field] ?? ''));
		}

		if (! in_array($settings['mode'], ['report-only', 'enforce'], true)) {
			$settings['mode'] = 'report-only';
		}

		$errors = [];

		if ($settings['notify_email'] !== '' && ! filter_var($settings['notify_email'], FILTER_VALIDATE_EMAIL)) {
			$errors[] = __('Notify email is not a valid email address.');
		}

		$settings['notify_throttle_seconds'] = (string) max(0, (int) $settings['notify_throttle_seconds']);

		$invalid = [];
		$entries = Csp::parseRemoveList($settings['checkout_remove'], $invalid);

		if ($invalid) {
			$errors[] = sprintf(__('Remove from checkout: these lines are not an #id, a .class or part of a URL: %s'), implode(', ', $invalid));
		} else {
			//store it tidied: one entry per line
			$settings['checkout_remove'] = implode("\n", array_map(function ($entry) {
				return ($entry[0] === 'id' ? '#' : ($entry[0] === 'class' ? '.' : '')) . $entry[1];
			}, $entries));
		}

		if ($errors) {
			$this->view->errors = $errors;

			$this->index();

			//show what was typed, not the saved values, so nothing is lost
			$this->view->set($settings);

			return;
		}

		//full replace, same as the payment plugins' own save() — a field
		//left blank should actually clear to blank, not silently keep
		//whatever was saved before
		deleteSetting($this->namespace, null, $this->global['site_id'] ?? null);
		setMultiSetting($this->namespace, $settings, $this->global['site_id'] ?? null);

		//a settings change otherwise risks being masked by a stale
		//compiled page — see claude/vvveb-plugin-architecture.md's
		//"Caching / stale-file risk" section
		CacheManager::delete();

		$this->view->success[] = __('Settings saved!');

		return $this->index();
	}

	/**
	 * GET (.../settings) — just populates view properties; no render()
	 * call. The framework resolves 'plugins/csp-manager/admin/settings.html'
	 * from the route itself and wraps it in the normal admin layout.
	 */
	public function index() {
		$this->view->set(Csp::settings());
	}
}
