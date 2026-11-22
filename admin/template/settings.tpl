// CSP Manager for Vvveb
// Copyright (C) 2026  Ezyweb.uk
// Licensed under the GNU Affero General Public License v3 or later, see the LICENSE file

import(common.tpl)

#mode option[value="report-only"]|addNewAttribute = <?php if (($this->mode ?? 'report-only') === 'report-only') echo 'selected'; ?>
#mode option[value="enforce"]|addNewAttribute = <?php if (($this->mode ?? '') === 'enforce') echo 'selected'; ?>

#report_uri|value = <?php echo htmlspecialchars($this->report_uri ?? ''); ?>
#checkout_routes|innerText = <?php echo htmlspecialchars($this->checkout_routes ?? ''); ?>

#strip_inline_handlers|addNewAttribute = <?php if ((string) ($this->strip_inline_handlers ?? '0') === '1') echo 'checked'; ?>
#strip_checkout_cookie_notice|addNewAttribute = <?php if ((string) ($this->strip_checkout_cookie_notice ?? '1') === '1') echo 'checked'; ?>
#strip_checkout_flagcounter|addNewAttribute = <?php if ((string) ($this->strip_checkout_flagcounter ?? '1') === '1') echo 'checked'; ?>

#notify_email|value = <?php echo htmlspecialchars($this->notify_email ?? ''); ?>
#notify_throttle_seconds|value = <?php echo htmlspecialchars((string) ($this->notify_throttle_seconds ?? 300)); ?>

#checkout_default_src|value = <?php echo htmlspecialchars($this->checkout_default_src ?? ''); ?>
#checkout_script_src|value = <?php echo htmlspecialchars($this->checkout_script_src ?? ''); ?>
#checkout_style_src|value = <?php echo htmlspecialchars($this->checkout_style_src ?? ''); ?>
#checkout_img_src|value = <?php echo htmlspecialchars($this->checkout_img_src ?? ''); ?>
#checkout_font_src|value = <?php echo htmlspecialchars($this->checkout_font_src ?? ''); ?>
#checkout_frame_src|value = <?php echo htmlspecialchars($this->checkout_frame_src ?? ''); ?>
#checkout_connect_src|value = <?php echo htmlspecialchars($this->checkout_connect_src ?? ''); ?>
#checkout_base_uri|value = <?php echo htmlspecialchars($this->checkout_base_uri ?? ''); ?>
#checkout_object_src|value = <?php echo htmlspecialchars($this->checkout_object_src ?? ''); ?>
#checkout_form_action|value = <?php echo htmlspecialchars($this->checkout_form_action ?? ''); ?>
#checkout_frame_ancestors|value = <?php echo htmlspecialchars($this->checkout_frame_ancestors ?? ''); ?>

#default_default_src|value = <?php echo htmlspecialchars($this->default_default_src ?? ''); ?>
#default_script_src|value = <?php echo htmlspecialchars($this->default_script_src ?? ''); ?>
#default_style_src|value = <?php echo htmlspecialchars($this->default_style_src ?? ''); ?>
#default_img_src|value = <?php echo htmlspecialchars($this->default_img_src ?? ''); ?>
#default_font_src|value = <?php echo htmlspecialchars($this->default_font_src ?? ''); ?>
#default_frame_src|value = <?php echo htmlspecialchars($this->default_frame_src ?? ''); ?>
#default_connect_src|value = <?php echo htmlspecialchars($this->default_connect_src ?? ''); ?>
#default_base_uri|value = <?php echo htmlspecialchars($this->default_base_uri ?? ''); ?>
#default_object_src|value = <?php echo htmlspecialchars($this->default_object_src ?? ''); ?>
#default_form_action|value = <?php echo htmlspecialchars($this->default_form_action ?? ''); ?>
#default_frame_ancestors|value = <?php echo htmlspecialchars($this->default_frame_ancestors ?? ''); ?>
