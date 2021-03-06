<?php
/*
 * Copyright (c) 2018, Tribal Limited
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of Zenario, Tribal Limited nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL TRIBAL LTD BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
if (!defined('NOT_ACCESSED_DIRECTLY')) exit('This file may not be directly accessed');


switch ($path) {
	case 'zenario_email_template':
		ze\contentAdm::addAbsURLsToAdminBoxField($box['tabs']['meta_data']['fields']['body']);
		
		//Show site-setting value next to field and a link to the settings panel
		if ($values['meta_data/from_details'] == 'site_settings') {
			$fields['meta_data/from_details']['post_field_html'] = '&nbsp' . ze::setting('email_address_from') . '/' . ze::setting('email_name_from');
			$fields['meta_data/from_details']['note_below'] = ze\admin::phrase('Go to')." <a href='".ze\link::absolute()."zenario/admin/organizer.php?#zenario__administration/panels/site_settings//email' target='_blank'>".ze\admin::phrase('Email site settings')."</a>";
		} else {
			$fields['meta_data/from_details']['post_field_html'] = '';
			$fields['meta_data/from_details']['note_below'] = '';
		}
		
		//Show site-setting value if no template setting is set
		if ($values['data_deletion/period_to_delete_log_headers'] == ""
			&& ($siteWideSetting = ze::setting('period_to_delete_the_email_template_sending_log_headers'))
			&& (isset($fields['data_deletion/period_to_delete_log_headers']['values'][$siteWideSetting]))
		) {
			$fields['data_deletion/period_to_delete_log_headers']['post_field_html'] = '&nbsp;(' . $fields['data_deletion/period_to_delete_log_headers']['values'][$siteWideSetting]['label'] . ')';
		} else {
			$fields['data_deletion/period_to_delete_log_headers']['post_field_html'] = '';
		}
				
		//Update body label
		if ($values['meta_data/use_standard_email_template'] == 'yes') {
			$fields['meta_data/body']['label'] = ze\admin::phrase('Email body:');
		} else {
			$fields['meta_data/body']['label'] = ze\admin::phrase('Entire email:');
		}
		
		//Only show the preview when using the standard email template
		$box['tabs']['preview']['hidden'] = $values['meta_data/use_standard_email_template'] != 'yes';
		
		//Show a preview if using the standard email template
		$values['preview/body'] = $values['meta_data/body'];
		static::putBodyInTemplate($values['preview/body']);
		
		
		//Send a test email for the email template
		$box['tabs']['meta_data']['notices']['test_send_error']['show'] = false;
		$box['tabs']['meta_data']['notices']['test_send_sucesses']['show'] = false;
		if ($fields['meta_data/test_send_button']['pressed'] ?? false) {
			$error = '';
			$success = '';
			if (!$values['meta_data/test_send_email_address']) {
				$error = ze\admin::phrase('Please enter an email address.');
			} else {
				$adminDetails = ze\admin::details($_SESSION['admin_userid'] ?? false);
				
				$body = $values['meta_data/body'];
				static::putHeadOnBody($values['advanced/head'], $body);
				if ($values['meta_data/use_standard_email_template'] == 'yes') {
					static::putBodyInTemplate($body);
				}
				
				if ($values['meta_data/from_details'] == "site_settings") {
					$emailAddressFrom = ze::setting('email_address_from');
					$emailNameFrom = ze::setting('email_name_from');
				} else {
					$emailAddressFrom = $values['meta_data/email_address_from'];
					$emailNameFrom = $values['meta_data/email_name_from'];
				}
				
				foreach (ze\ray::explodeAndTrim($values['meta_data/test_send_email_address']) as $email) {
					if (!ze\ring::validateEmailAddress($email)) {
						$error .= ($error? "\n" : ''). ze\admin::phrase('"[[email]]" is not a valid email address.', ['email' => $email]);
					} elseif (!$values['meta_data/body']) {
						$error .= ($error? "\n" : ''). ze\admin::phrase('The test email(s) could not be sent because your email body is blank.');
						break;
					} elseif ($box['key']['id'] 
						&& !static::testSendEmailTemplate(
							$body,
							$adminDetails, 
							$email, 
							$values['meta_data/subject'], 
							$emailAddressFrom,
							$emailNameFrom
						)
					) {
						$error .= ($error? "\n" : ''). ze\admin::phrase("The test email(s) could not be sent. There could be a problem with the site's email system.");
					} else {
						$success .= ($success? "\n" : ''). ze\admin::phrase('Test email sent to "[[email]]".', ['email' => $email]);
					}
				}
			}
			
			if ($error) {
				$box['tabs']['meta_data']['notices']['test_send_error']['show'] = true;
				$box['tabs']['meta_data']['notices']['test_send_error']['message'] = $error;
			}
			if ($success) {
				$box['tabs']['meta_data']['notices']['test_send_sucesses']['show'] = true;
				$box['tabs']['meta_data']['notices']['test_send_sucesses']['message'] = $success;
			}
		}
		
			
		break;
}
