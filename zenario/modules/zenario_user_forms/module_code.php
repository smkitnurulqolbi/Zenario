<?php
/*
 * Copyright (c) 2017, Tribal Limited
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

class zenario_user_forms extends module_base_class {
	
	protected $data = array();
	protected $errors = array();
	protected $saveToCompleteLater = false;
	protected $loadingPartialSave = false;
	protected $formFinalSubmitSuccessfull = false;
	protected $inFullScreen = false;
	protected $firstErrorPage = false;
	
	//Variables for extended modules
	protected $allowSave = true;
	protected $messages = array();
	
	public function init() {
		$this->allowCaching(
			$atAll = true, $ifUserLoggedIn = true, $ifGetSet = false, $ifPostSet = false, $ifSessionSet = false, $ifCookieSet = false);
		$this->clearCacheBy(
			$clearByContent = false, $clearByMenu = false, $clearByUser = true, $clearByFile = false, $clearByModuleData = true);
		
		$adminId = adminId();
		$formId = $this->setting('user_form');
		
		//This plugin must have a form selected
		if (!$formId) {
			if ($adminId) {
				$this->data['form_HTML'] = '<p class="error">' . adminPhrase('You must select a form for this plugin.') . '</p>';
			}
			return true;
		}
		
		$userId = userId();
		$form = static::getForm($formId);
		$t = $form['translate_text'];
		
		//Partial completion forms must be placed on a private page and have a user logged in
		if ($form['allow_partial_completion']) {
			$equivId = equivId($this->cID, $this->cType);
			$privacy = getRow('translation_chains', 'privacy', array('equiv_id' => $equivId, 'type' => $this->cType));
			
			if ($privacy == 'public') {
				if ($adminId) {
					$this->data['form_HTML'] = '<p class="error">' . adminPhrase('This form has the "save and complete later" feature enabled and so must be placed on a password-protected page.') . '</p>';
				}
				return true;
			}
			if (!$userId) {
				if ($adminId) {
					$this->data['form_HTML'] = '<p class="error">' . adminPhrase('You must be logged in as an Extranet User to see this Plugin.') . '</p>';
				}
				return true;
			}
		}
		
		$reloaded = post('formReloaded') && ($this->instanceId == post('instanceId'));
		$reloadedWithAjax = $reloaded && (get('method_call') == 'refreshPlugin');
		
		//Decide whether to display plugin contents in a modal window
		$showInFloatingBox = false;
		$loadContentInColorbox = false;
		if ($this->setting('display_mode') == 'in_modal_window') {
			if ($reloadedWithAjax || get('showUserForm')) {
				$showInFloatingBox = true;
				$floatingBoxParams = array(
					'escKey' => false, 
					'overlayClose' => false, 
					'closeConfirmMessage' => $this->phrase('Are you sure you want to close this window? You will lose any changes.'),
				);
				$this->showInFloatingBox(true, $floatingBoxParams);
			//If colorbox was forced to close e.g. there was a file input field on the form, reopen it in JS.
			} elseif ($reloaded && !$reloadedWithAjax) {
				$loadContentInColorbox = true;
			} else {
				$this->data['form_HTML'] = $this->getModalWindowButtonHTML($form);
				return true;
			}
		}
		
		//Logged in user duplicate submission check
		if ($userId && $form['no_duplicate_submissions']) {
			if (checkRowExists(ZENARIO_USER_FORMS_PREFIX . 'user_response', array('user_id' => $userId, 'form_id' => $formId))) {
				$this->data['form_HTML'] = '<p class="info">' . static::fPhrase($form['duplicate_submission_message'], array(), $t) . '</p>';
				return true;
			}
		}
		
		//Load form data
		$data = $this->preloadCustomData();
		if ($reloaded) {
			$data = $_POST;
		} else {
			//Delete any session data saved for this form
			unset($_SESSION['custom_form_data'][$this->instanceId]);
			
			//Check if there is a part completed submission for this user to load initially
			if ($form['allow_partial_completion']) {
				$partialSave = getRow(ZENARIO_USER_FORMS_PREFIX . 'user_partial_response', array('max_page_reached'), array('user_id' => $userId, 'form_id' => $formId));
				if ($partialSave) {
					if (!$form['allow_clear_partial_data'] || post('resume')) {
						$data = static::getPartialSaveData($userId, $formId);
						$this->loadingPartialSave = true;
						$_SESSION['custom_form_data'][$this->instanceId]['max_page_reached'] = $partialSave['max_page_reached'];
					} elseif ($form['allow_clear_partial_data'] && post('clear')) {
						static::deleteOldPartialResponse($formId, $userId);
					} else {
						$this->data['form_HTML'] = $this->getPartialSaveResumeFormHTML($form);
						return true;
					}
				}
			}
		}
		
		$fields = static::getFields($formId, false, true, $data, $this->instanceId);
		$errors = array();
		$nextPage = 1;
		$currentPage = false;
		$successfulFormSubmit = false;
		
		//Handle form reload
		if ($reloaded) {
			//Get page to load
			if (post('formPage')) {
				$currentPage = (int)post('formPage');
				$nextPage = static::getNextFormPage($currentPage, $fields, $data, $this->instanceId);
			}
			
			//Upload and attachment field files that have been added
			$pageFields = static::filterFormFieldsByPage($fields, $currentPage);
			foreach ($pageFields as $fieldId => $field) {
				if ((static::getFieldType($field) == 'attachment')
					&& ($fieldName = static::getFieldName($fieldId))
					&& !empty($_FILES[$fieldName]['tmp_name'])
				) {
					$this->uploadFieldAttachment($fieldId, $t, $data);
				}
				//Handle removing an uploaded attachment
				if (post('remove_attachment_' . $fieldId)) { //To do.. make sure this doesn't trigger validation!
					unset($data[static::getFieldName($fieldId)]);
				}
			}
			
			//If this reload is to filter a list then don't do any validation or saving
			if (!post('filter')) {
				//Run validation on page if we're submitting the form, or trying to navigate to a higher page, or saving to complete later
				$valid = true;
				$submitted = post('submitForm') !== false;
				$moveToHigherPage = ($currentPage && ($nextPage > $currentPage));
				$saveToCompleteLater = post('saveLater') && $form['allow_partial_completion'] && in_array($form['partial_completion_mode'], array('button', 'auto_and_button'));
				if ($submitted || $moveToHigherPage || $saveToCompleteLater) {
					$valid = $this->validateForm($formId, $fields, $data, $currentPage, $submitted, $saveToCompleteLater);
				}
				
				//Stay on page if errors (or go to page with the error if form was submitted)
				if (!$valid) {
					if ($this->firstErrorPage) {
						$nextPage = $this->firstErrorPage;
					} else {
						$nextPage = $currentPage;
					}
				//If no errors then save form data
				} elseif ($this->allowSave) {
					$this->formFinalSubmitSuccessfull = $submitted || $saveToCompleteLater;
					$autoSavingFormOnPageNav = !$submitted && ($currentPage != $nextPage) && $form['allow_partial_completion'] && in_array($form['partial_completion_mode'], array('auto', 'auto_and_button'));
					$partialSave = $saveToCompleteLater || $autoSavingFormOnPageNav;
					$fullSave = $submitted || $partialSave;
					
					static::saveForm($formId, $fields, $data, $userId, $this->instanceId, $fullSave, $currentPage, $partialSave);
					
					//After clicking save to complete later button, show success message				
					if ($saveToCompleteLater) {
						if ($form['partial_completion_message']) {
							$successMessage = static::fPhrase($form['partial_completion_message'], array(), $t);
						} elseif (adminId()) {
							$successMessage = adminPhrase('Your partial completion message will go here when you set it.');
						} else {
							$successMessage = 'Your data will be here the next time you open this form.';
						}
						$this->data['form_HTML'] = '<div class="success">' . $successMessage . '</div>';
						return true;
					}
					
					if ($submitted) {
						$successfulFormSubmit = true;
						$this->successfulFormSubmit();
						unset($_SESSION['captcha_passed__' . $this->instanceId]);
						
						
						//After submitting a form either show a summary page
						if ($form['enable_summary_page']) {
							$html = '';
							if ($form['show_success_message']) {
								$html .= $this->getSuccessMessageHTML($form);
							}
							$this->data['form_HTML'] = $html . $this->getSummaryPageHTML($form, $fields, $data);
							return true;
						//Or a success message
						} elseif ($form['show_success_message']) {
							$this->data['form_HTML'] = $this->getSuccessMessageHTML($form);
							return true;
						//Or redirect to another page
						} elseif ($form['redirect_after_submission'] && $form['redirect_location']) {
							$cID = $cType = false;
							getCIDAndCTypeFromTagId($cID, $cType, $form['redirect_location']);
							langEquivalentItem($cID, $cType);
							$redirectURL = linkToItem($cID, $cType);
							$this->headerRedirect($redirectURL);
							return true;
						}
						//Or stay on the form
						
						unset($_SESSION['custom_form_data'][$this->instanceId]);
					}
				}
			}	
		}
		
		//Whether to show confirm message (always) when closing colorbox
		if ($showInFloatingBox && (!$successfulFormSubmit && ($nextPage > 1 || ($currentPage && $currentPage > 1)))) {
			$floatingBoxParams['alwaysShowConfirmMessage'] = true;
			$this->showInFloatingBox(true, $floatingBoxParams);
		}
		
		//Save the maximum page number reached on this form
		if (!isset($_SESSION['custom_form_data'][$this->instanceId]['max_page_reached']) 
		    || ($nextPage > $_SESSION['custom_form_data'][$this->instanceId]['max_page_reached'])
		) {
		    $_SESSION['custom_form_data'][$this->instanceId]['max_page_reached'] = $nextPage;
		}
		
		
		$this->inFullScreen = !empty($data['inFullScreen']);
		
		//Get form HTML
		$colorboxFormHTML = false;
		$html = $this->getFormHTML($form, $fields, $data, $nextPage);
		if ($loadContentInColorbox) {
			$colorboxFormHTML = $html;
			$this->data['form_HTML'] = $this->getModalWindowButtonHTML($form);
		} else {
			$this->data['form_HTML'] = $html;
		}
		
		//Init form JS
		$allowProgressBarNavigation = $form['show_page_switcher'] && ($form['page_switcher_navigation'] == 'only_visited_pages');
		$maxPageReached = $_SESSION['custom_form_data'][$this->instanceId]['max_page_reached'];
		$isErrors = (bool)$this->errors;
		
		$this->callScript('zenario_user_forms', 'initForm', $this->containerId, $this->slotName, $this->pluginAJAXLink(), $colorboxFormHTML, $this->formFinalSubmitSuccessfull, $this->inFullScreen, $allowProgressBarNavigation, $nextPage, $maxPageReached, $showLeavingPageMessage = true, $isErrors);
		return true;
	}
	
	public function showSlot() {
		$this->twigFramework($this->data);
	}
	
	
	protected function getSummaryPageHTML($form, $fields, $data) {
		$html = '';
		$dataset = getDatasetDetails('users');
		$values = static::getFormSaveDataGrouped($fields, $data, $dataset, $this->instanceId);
		
		$sectionFields = array();
		$lastField = end($fields);
		
		$html .= '<table class="summary_table">';
		foreach ($fields as $fieldId => $field) {
			$type = static::getFieldType($field);
			if ($type != 'page_break') {
				//Only show fields that the user would have been able to see.
				if ($type != 'section_description' && $type != 'restatement' && !static::isFieldHidden($field, $fields, $data, $dataset, $this->instanceId)) {
					$sectionFields[$fieldId] = $field;
				}
			}
			//Also hide any pages and all fields that were on a hidden page.
			if (($type == 'page_break' && !$field['hide_in_page_switcher'] && !static::isFieldHidden($field, $fields, $data, $dataset, $this->instanceId))
				|| ($fieldId == $lastField['id'])
			) {
				if ($form['show_page_switcher']) {
					if ($type == 'page_break') {
						$html .= $this->getSummaryPageFieldHTML($form, $type, $field['name'], false);
					} elseif (!$form['hide_final_page_in_page_switcher']) {;
						$html .= $this->getSummaryPageFieldHTML($form, 'page_break', $form['page_end_name'], false);
					}
				}
				foreach ($sectionFields as $sFieldId => $sField) {
					$type = static::getFieldType($sField);
					$valueData = isset($values[$sFieldId]) ? $values[$sFieldId] : false;
					$html .= $this->getSummaryPageFieldHTML($form, $type, $sField['label'], $valueData);
				}
				$sectionFields = array();
			}
		}
		$html .= '</table>';
		return $html;
	}
	
	protected function getSuccessMessageHTML($form) {
		$t = $form['translate_text'];
		if ($form['success_message']) {
			$successMessage = static::fPhrase($form['success_message'], array(), $t);
		} elseif (adminId()) {
			$successMessage = adminPhrase('Your success message will go here when you set it.');
		} else {
			$successMessage = 'Form submission successful!';
		}
		return '<div class="success">' . $successMessage . '</div>';
	}
	
	protected function getModalWindowButtonHTML($form) {
		$t = $form['translate_text'];
		$requests = 'showUserForm=1';
		$buttonJS = $this->refreshPluginSlotAnchor($requests, false, false);
		
		$html = '';
		$html .= '<div class="user_form_click_here" ' . $buttonJS . '>';
		$html .= '<h3>' . static::fPhrase($this->setting('display_text'), array(), $t) . '</h3>';
		$html .= '</div>';
		return $html;
	}
	
	public static function getSummaryPageFieldHTML($form, $type, $label, $data) {
		$t = $form['translate_text'];
		$html = '<tr>';
		if ($type == 'page_break') {
			$html .= '<th colspan="2" class="header">' . htmlspecialchars(static::fPhrase($label, array(), $t)) . '</th>';
		} else {
			$html .= '<th>' . htmlspecialchars(static::fPhrase($label, array(), $t)) . '</th>';
			$html .= '<td>';
			if ($data && isset($data['value'])) {
				$value = $data['value'];
				if ($type == 'attachment' || $type == 'file_picker') {
					if (isset($data['internal_value'])) {
						$fileIds = explode(',', $data['internal_value']);
						foreach ($fileIds as $fileId) {
							$fileName = getRow('files', 'filename', $fileId);
							$link = fileLink($fileId);
							$html .= ' <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($fileName) . '</a>';
						}
					}
				} elseif ($type == 'date') {
					$html .= formatDateNicely($value, '_MEDIUM');
				} elseif ($type == 'textarea') {
					$html .= nl2br(htmlspecialchars($value));
				} else {
					$html .= htmlspecialchars($value);
				}
			}
			$html .= '</td>';
		}
		$html .= '</tr>';
		return $html;
	}
	
	private function getPartialSaveResumeFormHTML($form) {
		$t = $form['translate_text'];
		$html = '<div class="resume_box">';
		$html .= $this->openForm();
		$html .= '<p>' . static::fPhrase($form['clear_partial_data_message'], array(), $t) . '</p>';
		$html .= '<input type="submit" name="resume" value="' . static::fPhrase('Resume', array(), $t) . '">';
		$html .= '<input type="submit" name="clear" value="' . static::fPhrase('Clear', array(), $t) . '">';
		$html .= $this->closeForm();
		$html .= '</div>';
		return $html;
	}
	
	private static function getNextFormPage($currentPage, $fields, $data, $instanceId) {
		if (!$currentPage) {
			$currentPage = 1;
		}
		$dataset = getDatasetDetails('users');
		
		//Move to next page
		if ($nextPage = post('target_page')) {
		    if ($nextPage > $currentPage) {
		        //Save fields on page to session
		        $pageFields = static::filterFormFieldsByPage($fields, $currentPage);
			    static::tempSaveFormFields($pageFields, $fields, $data, $dataset, $instanceId);
		    }
		} elseif (post('next')) {
			$nextPage = $currentPage + 1;
			//Save fields on page to session
			$pageFields = static::filterFormFieldsByPage($fields, $currentPage);
			static::tempSaveFormFields($pageFields, $fields, $data, $dataset, $instanceId);
		//Move to previous page
		} elseif (post('previous')) {
			$nextPage = $currentPage - 1;
		//Stay on current page (refresh for filter)
		} else {
			$nextPage = $currentPage;
		}
		
		//Skip hidden pages
		$page = 1;
		$lastVisiblePage = false;
		$goToLastVisiblePage = false;
		$firstPageBreakPassed = false;
		foreach ($fields as $fieldId => $field) {
			$fieldType = static::getFieldType($field);
			if ($fieldType == 'page_break') {
				if (!$firstPageBreakPassed) {
					//First page is never hidden
					$hidden = false;
					$firstPageBreakPassed = true;
				} else {
					$hidden = static::isFieldHidden($field, $fields, $data, $dataset, $instanceId);
				}
				
				if (!$hidden) {
					$lastVisiblePage = $page;
				}
				
				if ($page == $nextPage) {
					if ($hidden) {
						if (post('next')) {
							$nextPage++;
						} elseif (post('previous')) {
							$goToLastVisiblePage = true;
							break;
						}
					} else {
						break;
					}
				}
				++$page;
			}
		}
		
		if ($goToLastVisiblePage) {
			if ($lastVisiblePage) {
				$nextPage = $lastVisiblePage;
			} else {
				$nextPage = $currentPage;
			}
		}
		
		return $nextPage;
	}
	
	
	
	
	//An overwritable method when a form is successfully submitted
	protected function successfulFormSubmit() {
		
	}
	
	
	//An overwritable method to preload custom data for the form fields
	protected function preloadCustomData() {
		return (int)userId();
	}
	
	
	//An overwritable method to parse an array of requests which are added as hidden inputs on the form
	protected function getCustomRequests() {
		return false;
	}
	
	
	//An overwritable method to add custom HTML to the buttons area
	//Position can be "first", "center", "last", "top"
	protected function getCustomButtons($page, $onLastPage, $position) {
		return false;
	}
	
	//An overwritable method to add custom HTML to the top buttons area
	protected function getCustomTopButtons() {
		return false;
	}
	
	
	//An overwritable method to set the form title
	protected function getFormTitle($form) {
		$t = $form['translate_text'];
		if (!empty($form['title']) && !empty($form['title_tag'])) {
			$html = '<' . htmlspecialchars($form['title_tag']) . '>';
			$html .= static::fPhrase($form['title'], array(), $t);
			$html .= '</' . htmlspecialchars($form['title_tag']) . '>';
			return $html;
		}
		return '';
	}
	
	
	//An overwritable method to set the entire form as readonly
	protected function isFormReadonly($form) {
		return false;
	}
	
	
	//An overwritable method to show the submit button or not
	protected function showSubmitButton() {
		return true;
	}
	
	
	//An overwritable method for custom validation on form fields
	protected function getCustomErrors($fields, $pageFields, $data) {
		return false;
	}
	
	
	
	
	
	protected static function getPartialSaveData($userId, $formId) {
		$data = array();
		$sql = '
			SELECT d.form_field_id, d.field_row, d.value
			FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_partial_response_data d
			INNER JOIN ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_partial_response r
				ON d.user_partial_response_id = r.id
				AND r.user_id = ' . (int)$userId . '
				AND r.form_id = ' . (int)$formId;
		$result = sqlSelect($sql);
		while ($row = sqlFetchAssoc($result)) {
			$fieldId = $row['form_field_id'];
			if ($row['field_row']) {
				$fieldId .= '_' . $row['field_row'];
			}
			$data[static::getFieldName($fieldId)] = $row['value'];
		}
		return $data;
	}
	
	
	public function handlePluginAJAX() {
		if (get('filePickerUpload')) {
			//TODO validate files same as attachment
			$data = array('files' => array());
			//Upload the file
			foreach ($_FILES as $fieldName => $file) {
				if (!empty($file['tmp_name']) && is_uploaded_file($_FILES[$fieldName]['tmp_name']) && cleanDownloads()) {
					$randomDir = createRandomDir(30, 'uploads');
					$newName = $randomDir. safeFileName($_FILES[$fieldName]['name'], true). '.upload';
					if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], CMS_ROOT. $newName)) {
						$data['files'][] = array('name' => urldecode($_FILES[$fieldName]['name']), 'id' => $newName);
						chmod(CMS_ROOT. $newName, 0666);
					}
				}
			}
			echo json_encode($data);
		}
	}
	
	
	public static function getFormEncodingType($formId) {
		$sql = '
			SELECT COUNT(*)
			FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_form_fields uff
			LEFT JOIN ' . DB_NAME_PREFIX . 'custom_dataset_fields AS cdf
				ON uff.user_field_id = cdf.id
			WHERE uff.user_form_id = ' . (int)$formId . '
			AND (uff.field_type = "attachment"
				OR cdf.type = "file_picker"
			)';
		$result = sqlSelect($sql);
		$row = sqlFetchRow($result);
		if ($row[0] > 0) {
			return 'enctype="multipart/form-data"';
		}
		return 'enctype="application/x-www-form-urlencoded"';
	}
	
	
	//Get HTML for a full form
	private function getFormHTML($form, $fields, $data, $page) {
		$t = $form['translate_text'];
		$html = '';
		$html .= '<div id="' . $this->containerId . '_form_wrapper" class="form_wrapper ';
		if ($this->inFullScreen) {
			$html .= 'in_fullscreen';
		}
		$html .= '">';
		
		$html .= $this->getFormTitle($form);
		
		$topButtonsHTML = '';
		if ($this->setting('display_mode') == 'inline_in_page') {
			$topButton = $this->getCustomTopButtons();
			if ($topButton) {
				$topButtonsHTML .= $topButton;
			}
			if ($this->setting('show_print_page_button')) {
				$printButtonPages = $this->setting('print_page_button_pages');
				if ($printButtonPages) {
					$printButtonPages = explode(',', $printButtonPages);
					if (in_array($page, $printButtonPages)) {
						$topButtonsHTML .= '<div id="' . $this->containerId . '_print_page" class="print_page">' . static::fPhrase('Print', array(), $t) . '</div>';
						
					}
				}
			}
			if ($this->setting('show_fullscreen_button')) {
				$topButtonsHTML .= '<div id="' . $this->containerId . '_fullscreen" class="fullscreen_button"';
				if ($this->inFullScreen) {
					$topButtonsHTML .= ' style="display:none;"';
				}
				$topButtonsHTML .= '>' . static::fPhrase('Fullscreen', array(), $t) . '</div>';
			}
		}
		if ($topButtonsHTML) {
			$html .= '<div class="top_buttons">' . $topButtonsHTML . '</div>';
		}
		
		
		if ($form['show_page_switcher']) {
			$dataset = getDatasetDetails('users');
			$switcherHTML = '';
			$hasPageVisibleOnSwitcher = false;
			$switcherHTML .= '<div class="page_switcher"><ul class="progress_bar">';
			$targetPage = 0;
			
			$progressBarAll = array();
			foreach ($fields as $fieldId => $field) {
				$fieldType = static::getFieldType($field);
				if ($fieldType == 'page_break') {
					++$targetPage;
					if (!$field['hide_in_page_switcher']) {
						$progressBarSectionTargetPage = $targetPage;
						if (!$hasPageVisibleOnSwitcher) {
							$hasPageVisibleOnSwitcher = true;
							$progressBarSectionTargetPage = 1;
						}
						$progressBarSection = array('targetPage' => $progressBarSectionTargetPage, 'name' => $field['name'], 'field' => $field);
						if (static::isFieldHidden($field, $fields, $data, $dataset, $this->instanceId)) {
							$progressBarSection['hidden'] = true;
						}
						$progressBarAll[] = $progressBarSection;
					}
				}	
			}
			if (!$form['hide_final_page_in_page_switcher']) {
				$hasPageVisibleOnSwitcher = true;
				$progressBarAll[] = array('targetPage' => ++$targetPage, 'name' => $form['page_end_name']);
			}
			
			$maxPageReached = isset($_SESSION['custom_form_data'][$this->instanceId]['max_page_reached']) ? $_SESSION['custom_form_data'][$this->instanceId]['max_page_reached'] : 1;
			foreach ($progressBarAll as $index => $step) {
				$switcherHTML .= '<li data-page="' . $step['targetPage'] . '" ';
				if (!empty($step['hidden'])) {
					$switcherHTML .= ' style="display:none;"';
				}
				
				$extraClasses = '';
				$previousVisiblePage = false;
				$nextVisiblePage = false;
				for ($i = ($index - 1); $i >= 0; $i--) {
					if (empty($progressBarAll[$i]['hidden'])) {
						$previousVisiblePage = $progressBarAll[$i];
						break;
					}	
				}
				for ($i = ($index + 1); $i < count($progressBarAll); $i++) {
					if (empty($progressBarAll[$i]['hidden'])) {
						$nextVisiblePage = $progressBarAll[$i];
						break;
					}
				}
			
				//Current if on the section or the current page is between this one and the next one
				$isCurrent = false;
				if ($step['targetPage'] == $page 
					|| ($nextVisiblePage && ($page < $nextVisiblePage['targetPage']) && ($page > $step['targetPage']))
					|| (!$nextVisiblePage && ($page > $step['targetPage']))
					|| (!$previousVisiblePage && ($page < $step['targetPage']))
			
				) {
					$isCurrent = true;
					$extraClasses .= ' current';
				}
				//Complete if we are on a further on section
				if ($page > $step['targetPage'] 
					&& ($nextVisiblePage && ($nextVisiblePage['targetPage'] <= $page))
				) {
					$extraClasses .= ' complete';
				}
				//Available if we are not on this section and its less than the max page we reached
				if (!$isCurrent && $maxPageReached >= $step['targetPage']) {
					$extraClasses .= ' available';
				}
			
				if (isset($step['field']) && $step['field']['visibility'] == 'visible_on_condition') {
					$switcherHTML .= static::getVisibleConditionDataValuesHTML($step['field'], $fields);
					$extraClasses .= ' visible_on_condition';
				}
				$switcherHTML .= 'class="step step_' . ($index + 1) . ' ' . $extraClasses . '">' . $step['name'] . '</li>';
			}
			
			$switcherHTML .= '</ul></div>';
			if ($hasPageVisibleOnSwitcher) {
				$html .= $switcherHTML;
			}
		}
		
		
		if (isset($this->errors['global_top'])) {
			$html .= '<div class="form_error global top">' . static::fPhrase($this->errors['global_top'], array(), $t) . '</div>';
		} elseif (isset($this->messages['global_top'])) {
			$html .= '<div class="success global top">' . static::fPhrase($this->messages['global_top'], array(), $t) . '</div>';
		}
		
		$html .= '<div id="' . $this->containerId . '_user_form" class="user_form">';
		
		//Get form encoding type depending on fields
		$encodingType = static::getFormEncodingType($form['id']);
		$html .= $this->openForm('', $encodingType, false, true);
		//Hidden input to tell whenever the form has been submitted
		$html .= '<input type="hidden" name="formReloaded" value="1"/>';
		//Hidden input to tell whether the form is in fullscreen or not
		$html .= '<input type="hidden" name="inFullScreen" value="' . (int)$this->inFullScreen . '"/>';
		//Add any extra requests
		$extraRequests = $this->getCustomRequests();
		if ($extraRequests) {
			foreach ($extraRequests as $name => $value) {
				$html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '"/>';
			}
		}
		
		$html .= $this->getFieldsHTML($form, $fields, $data, $this->isFormReadonly($form), $page);
		$html .= $this->closeForm();
		$html .= '</div>';
		$html .= '</div>';
		return $html;
	}
	
	
	//Get the HTML for a forms fields
	protected function getFieldsHTML($form, $fields, $data = false, $readonly = false, $page = false) {
		$html = '';
		$t = $form['translate_text'];
		$dataset = getDatasetDetails('users');
		$lastPageBreak = static::isFormMultiPage($form['id']);
		
		$page = $page ? $page : 1;
		//Keeps track of field page in loop
		$tPage = 1;
		$onLastPage = !$lastPageBreak;
		$pageBreakId = false;
		//Variables to handle wrapper divs
		$currentDivWrapClass = false;
		$wrapDivOpen = false;
		$repeatFieldCurrentDivWrapClass = false;
		$repeatFieldWrapDivOpen = false;
		
		if ($lastPageBreak) {
			$html .= '<fieldset id="' . $this->containerId . '_page_' . $page . '" class="page_' . $page . '">';
		}
		
		//If loading a partial save on a multi page form, save that parsed data into the session as it
		//holds data for fields not on the current page which would be lost otherwise
		if (($this->loadingPartialSave && $lastPageBreak) || is_numeric($data)) {
			static::tempSaveFormFields($fields, $fields, $data, $dataset, $this->instanceId);
		}
		
		$button = $this->getCustomButtons($page, $onLastPage, 'top');
		if ($button) {
			$html .= $button;
		}
		
		$html .= '<div class="form_fields">';
		$repeatBlockField = false;
		$repeatBlockFields = array();
		
		foreach ($fields as $fieldId => $field) {
			//End of page
			$fieldType = static::getFieldType($field);
			if ($fieldType == 'page_break') {
				$pageBreakId = $fieldId;
				++$tPage;
				if ($fieldId == $lastPageBreak && $tPage == $page) {
					$onLastPage = true;
				}
				if ($tPage > $page) {
					break;
				}
				continue;
			}
			//Skip fields not on current page
			if ($page > $tPage) {
				continue;
			}
			
			if ($fieldType == 'repeat_start') {
				$html .= static::getWrapperDivHTML($field, $wrapDivOpen, $currentDivWrapClass);
				$repeatBlockField = $field;
				$html .= '<div id="' . $this->containerId . '_repeat_block_' . $field['id'] . '" data-id="' . $field['id'] . '" class="repeat_block repeat_block_' . $field['id'];
				if ($field['css_classes']) {
					$html .= ' ' . htmlspecialchars($field['css_classes']);
				}
				if ($field['visibility'] == 'visible_on_condition') {
					$html .= ' visible_on_condition';
				}
				$html .= '"';
				if (static::isFieldHidden($field, $fields, $data, $dataset, $this->instanceId)) {
					$html .= ' style="display:none;"';
				}
				if ($field['visibility'] == 'visible_on_condition') {
					$html .= static::getVisibleConditionDataValuesHTML($field, $fields);
				}
				$html .= '>';
				
				if ($field['label']) {
					$html .= '<div class="field_title">' . static::fPhrase($field['label'], array(), $t) . '</div>';
				}
				
				$html .= '<div class="repeat_rows">';
				
				$this->inRepeatBlock = true;
				continue;
				
			} elseif ($fieldType == 'repeat_end') {
				if ($repeatBlockField['current_rows'] < $repeatBlockField['max_rows']) {
					$html .= '<div class="repeat_block_buttons"><div class="add">' . static::fPhrase('Add +', array(), $t) . '</div></div>';
				}
				$html .= '</div>';
				$rowsDataId = static::getRepeatBlockRowsId($repeatBlockField['id']);
				$html .= '<input type="hidden" name="' . $rowsDataId . '" value="' . $repeatBlockField['rows'] . '"/>';
				$html .= '</div>';
				$repeatBlockField = false;
				$repeatBlockFields = array();
				$this->inRepeatBlock = false;
				
			} else {
				if (!empty($field['repeatBlockStart'])) {
					$html .= '<div class="repeat_row row_' . (int)$field['repeatBlockRow'] . '"><div class="repeat_fields">';
				}
				
				//Seperate div wraps for fields in a repeat block
				if (!empty($field['repeatBlockStartId'])) {
					$fieldWrapDivOpen = &$repeatFieldWrapDivOpen;
					$fieldCurrentDivWrapClass = &$repeatFieldCurrentDivWrapClass;
				} else {
					$fieldWrapDivOpen = &$wrapDivOpen;
					$fieldCurrentDivWrapClass = &$currentDivWrapClass;
				}
				
				$html .= static::getWrapperDivHTML($field, $fieldWrapDivOpen, $fieldCurrentDivWrapClass);
				$html .= $this->getFieldHTML($fieldId, $fields, $data, $readonly, $translatePhrases = $form['translate_text'], $dataset, !empty($field['loadDefaultValue']));
				if (!empty($field['repeatBlockEnd'])) {
					//Close final repeat block wrapper div
					if ($fieldWrapDivOpen) {
						$html .= static::getWrapperDivHTML($field, $fieldWrapDivOpen, $fieldCurrentDivWrapClass, true);
					}
					
					$html .= '</div>';
					if (!empty($field['repeatBlockDeleteButton'])) {
						$html .= '<div class="delete" data-row="' . $field['repeatBlockRow'] . '">' . static::fPhrase('Delete', array(), $t) . '</div>';
					}
					$html .= '</div>';
				}
			}
		}
		//Close final wrapper div
		if ($wrapDivOpen) {
			$html .= static::getWrapperDivHTML($field, $wrapDivOpen, $currentDivWrapClass, true);
		}
		
		//Captcha
		if ($onLastPage) {
			if ($this->showCaptcha($form)) {
				$html .= $this->getCaptchaHTML($form);
			}
			if (!empty($form['use_honeypot'])) {
			    $html .= $this->getHoneypotHTML($form, $data);
			}
		}
		
		$html .= '</div>';
		
		if (isset($this->errors['global_bottom'])) {
			$html .= '<div class="form_error global bottom">' . static::fPhrase($this->errors['global_bottom'], array(), $t) . '</div>';
		}
		
		$html .= '<div class="form_buttons">';
		
		$button = $this->getCustomButtons($page, $onLastPage, 'first');
		if ($button) {
			$html .= $button;
		}
		
		//Previous page button
		if ($lastPageBreak && $page > 1) {
			$pageBreak = $fields[$pageBreakId];
			$text = !$onLastPage && $pageBreak['previous_button_text'] ? $pageBreak['previous_button_text'] : $form['default_previous_button_text'];
			$html .= '<input type="submit" name="previous" value="' . static::fPhrase($text, array(), $t) . '" class="previous"/>';
		}
		
		$button = $this->getCustomButtons($page, $onLastPage, 'center');
		if ($button) {
			$html .= $button;
		}
		
		//Next page button
		if (!$onLastPage) {
			$pageBreak = $fields[$pageBreakId];
			$text = !$onLastPage && $pageBreak['next_button_text'] ? $pageBreak['next_button_text'] : $form['default_next_button_text'];
			$html .= '<input type="submit" name="next" value="' . static::fPhrase($text, array(), $t) . '" class="next"/>';
		}
		//Final submit button
		if ($this->showSubmitButton() && $onLastPage) {
			$html .= '<input type="submit" name="submitForm" class="next submit" value="' . static::fPhrase($form['submit_button_text'], array(), $t) . '"/>';
		}
		
		$button = $this->getCustomButtons($page, $onLastPage, 'last');
		if ($button) {
			$html .= $button;
		}
		
		if ($form['allow_partial_completion'] && ($form['partial_completion_mode'] == 'button' || $form['partial_completion_mode'] == 'auto_and_button')) {
			$html .= '<div class="complete_later"><input type="submit" name="saveLater" value="' . static::fPhrase('Save and complete later', array(), $t) . '" onclick="return confirm(\'' . static::fPhrase('You are about to save this part-completed form, so that you can return to it later. Save now?', array(), $t) . '\');"/></div>';
		}
		
		$html .= '</div>';
		
		if ($lastPageBreak) {
			$html .= '<input type="hidden" name="formPage" value="' . $page . '"/>';
			$html .= '</fieldset>';
		}
		
		return $html;
	}
	
	private static function getWrapperDivHTML($field, &$wrapDivOpen, &$currentDivWrapClass, $end = false) {
		$html = '';
		if ($end) {
			$html .= '</div>';
			$wrapDivOpen = false;
			$currentDivWrapClass = false;
		} else {
			if ($wrapDivOpen && ($currentDivWrapClass != $field['div_wrap_class'])) {
				$wrapDivOpen = false;
				$html .= '</div>';
			}
			if (!$wrapDivOpen && $field['div_wrap_class']) {
				$html .= '<div class="' . htmlspecialchars($field['div_wrap_class']) . '">';
				$wrapDivOpen = true;
			}
			$currentDivWrapClass = $field['div_wrap_class'];
		}
		return $html;
	}				
	
	public function addToPageHead() {
		$formId = $this->setting('user_form');
		if ($formId) {
			$form = getRow(ZENARIO_USER_FORMS_PREFIX . 'user_forms', array('captcha_type'), $this->setting('user_form'));
			if ($form['captcha_type'] == 'pictures' 
				&& setting('google_recaptcha_site_key') 
				&& setting('google_recaptcha_secret_key')
			){
				echo '<script>
					var recaptchaCallback = function() {
						if (document.getElementById("zenario_user_forms_google_recaptcha_section")) {
							grecaptcha.render("zenario_user_forms_google_recaptcha_section", {
								sitekey : "' . setting('google_recaptcha_site_key') . '",
								theme : "' . setting('google_recaptcha_widget_theme') . '"
							});
						}
					};
					</script>';
				echo '<script src="https://www.google.com/recaptcha/api.js?onload=recaptchaCallback&render=explicit"></script>';
			}
		}
	}
	
	private function getHoneypotHTML($form, $data) {
	    $t = $form['translate_text'];
	    $html = '<div class="form_field honeypot" style="display:none;">';
	    if ($form['honeypot_label']) {
	        $html .= '<div class="field_title">' . static::fPhrase($form['honeypot_label'], array(), $t) . '</div>';
	    }
	    if (isset($this->errors['honeypot'])) {
	        $html .= '<div class="form_error">' . static::fPhrase($this->errors['honeypot'], array(), $t) . '</div>';
	    }
	    $html .= '<input type="text" name="field_hp" value="';
	    if (isset($data['field_hp'])) {
	        $html .= htmlspecialchars($data['field_hp']);
	    }
	    $html .= '" maxlength="100"/>';
	    $html .= '</div>';
	    return $html;
	}
	
	
	private function showCaptcha($form) {
		if ($form['use_captcha'] 
			&& empty($_SESSION['captcha_passed__' . $this->instanceId]) 
			&& (!userId()
				|| $form['extranet_users_use_captcha']
			)
		) {
			return true;
		}
		return false;
	}
	
	private function getCaptchaHTML($form) {
		$t = $form['translate_text'];
		$html = '<div class="form_field captcha">';
		if (isset($this->errors['captcha'])) {
			$html .= '<div class="form_error">' . static::fPhrase($this->errors['captcha'], array(), $t) . '</div>';
		}
		if ($form['captcha_type'] == 'word') {
			$html .= $this->captcha();
		} elseif ($form['captcha_type'] == 'math') {
			$html .= '<p>
						<img id="siimage" style="border: 1px solid #000; margin-right: 15px" src="zenario/libraries/mit/securimage/securimage_show.php?sid=<?php echo md5(uniqid()) ?>" alt="CAPTCHA Image" align="left">
						
						&nbsp;
						<a tabindex="-1" style="border-style: none;" href="#" title="Refresh Image" onclick="document.getElementById(\'siimage\').src = \'zenario/libraries/mit/securimage/securimage_show.php?sid=\' + Math.random(); this.blur(); return false">
							<img src="zenario/libraries/mit/securimage/images/refresh.png" alt="Reload Image" onclick="this.blur()" align="bottom" border="0">
						</a><br />
						Do the maths:<br />
						<input type="text" name="captcha_code" size="12" maxlength="16" class="math_captcha_input" id="' . $this->containerId . '_math_captcha_input"/>
					</p>';
		} elseif ($form['captcha_type'] == 'pictures' && setting('google_recaptcha_site_key') && setting('google_recaptcha_secret_key')) {
			$html .= '<div id="zenario_user_forms_google_recaptcha_section"></div>';
			$this->callScript('zenario_user_forms', 'recaptchaCallback');
		}
		$html .= '</div>';
		return $html;
	}
	
	private function getCaptchaError($form) {
		if ($this->showCaptcha($form) && post('submitForm') && $this->instanceId == post('instanceId')) {
			$error = false;
			$t = $form['translate_text'];
			if ($form['captcha_type'] == 'word') {
				if ($this->checkCaptcha()) {
					$_SESSION['captcha_passed__' . $this->instanceId] = true;
				} else {
					$error = true;
				}
			} elseif ($form['captcha_type'] == 'math' && isset($_POST['captcha_code'])) {
				require_once CMS_ROOT. 'zenario/libraries/mit/securimage/securimage.php';
				$securimage = new Securimage();
				if ($securimage->check($_POST['captcha_code']) != false) {
					$_SESSION['captcha_passed__' . $this->instanceId] = true;
				} else {
					$error = true;
				}
			} elseif ($form['captcha_type'] == 'pictures' 
				&& isset($_POST['g-recaptcha-response']) 
				&& setting('google_recaptcha_site_key') 
				&& setting('google_recaptcha_secret_key')
			) {
				$recaptchaResponse = $_POST['g-recaptcha-response'];
				$secretKey = setting('google_recaptcha_secret_key');
				$URL = "https://www.google.com/recaptcha/api/siteverify?secret=".$secretKey."&response=".$recaptchaResponse;
				$request = file_get_contents($URL);
				$response = json_decode($request, true);
				if(is_array($response)){
					if(isset($response['success'])){
						if($response['success']){
							$_SESSION['captcha_passed__'. $this->instanceId] = true;
						}else{
							$error = true;
						}
					}
				}
			}
			if ($error) {
				return static::fPhrase('Please verify that you are human.', array(), $t);
			}
		}
		return false;
	}
	
	private $inRepeatBlock = false;
	
	//Get the HTML for a single field
	private function getFieldHTML($fieldId, $fields, $data, $readonly = false, $translatePhrases = false, $dataset = false, $forceLoadDefaultValue = false) {
		$t = $translatePhrases;
		$field = $fields[$fieldId];
		$fieldName = static::getFieldName($fieldId);
		$fieldType = static::getFieldType($field);
		$fieldElementId = $this->containerId . '__' . $fieldName;
		$ignoreSession = !empty($this->errors);
		$fieldValue = static::getFieldCurrentValue($fieldId, $fields, $data, $dataset, $this->instanceId, $ignoreSession, false, 1, $forceLoadDefaultValue);
		
		$readonly = $readonly || $field['is_readonly'];
		
		$html = '';
		$extraClasses = '';
		$errorHTML = '';
		
		if ($fieldValue) {
			$extraClasses .= ' has_value';
		}
		
		if ($field['is_required']) {
			$extraClasses .= ' mandatory';
		}
		
		if (isset($this->errors[$fieldId])) {
			$errorHTML = '<div class="form_error">' . $this->errors[$fieldId] . '</div>';
		}
		
		//Add the fields label, checkboxes have a label element
		if ($fieldType != 'group' && $fieldType != 'checkbox') {
			$html .= '<div class="field_title">' . static::fPhrase($field['label'], array(), $t) . '</div>';
			$html .= $errorHTML;
		}
		
		//Get HTML for type
		switch ($fieldType) {
			case 'group':
			case 'checkbox':
				$html .= $errorHTML;
				$html .= '<input type="checkbox"';
				if ($fieldValue) {
					$html .= ' checked="checked"';
				}
				if ($readonly) {
					$html .= ' disabled="disabled"';
				}
				$html .= ' name="' . $fieldName . '" id="' . $fieldElementId . '"/>';
				$html .= '<label class="field_title" for="' . $fieldElementId . '">' . static::fPhrase($field['label'], array(), $t) . '</label>';
				break;
			
			case 'restatement':
			case 'calculated':
			case 'url':
			case 'text':
				if ($fieldType == 'restatement') {
					$readonly = true;
					$extraClasses .= ' restatement'; 
				//Calculated fields are readonly text fields
				} elseif ($fieldType == 'calculated') {
					$readonly = true;
					$extraClasses .= ' calculated'; 
					
					$calculationCodeJSON = static::expandCalculatedFieldsInCalculationCode($field['calculation_code'], $fields);
					$calculationCode = json_decode($calculationCodeJSON, true);
					
					if ($calculationCode) {
						foreach ($calculationCode as $stepIndex => $step) {
							if ($step['type'] == 'field') {
								$inputFieldValue = static::getFieldCurrentValue($calculationCode[$stepIndex]['value'], $fields, $data, $dataset, $this->instanceId);
								if (!static::validateNumericInput($inputFieldValue)) {
									$inputFieldValue = 'NaN';
								} else {
									$inputFieldValue = (float)$inputFieldValue;
								}
								$calculationCode[$stepIndex]['v'] = $inputFieldValue;
							}
						}
					}
					
					$html .= '<div id="' . $this->containerId . '_field_' . $fieldId . '_calculation_code" style="display:none;">';
					$html .= json_encode($calculationCode);
					$html .= '</div>';
				}
				
				//Autocomplete options for text fields
				$autocompleteHTML = '';
				$useTextFieldName = true;
				if ($field['autocomplete']) {
					if ($field['values_source']) {
						$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $this->instanceId, $filtered);
						$autocompleteFieldLOV = array();
						foreach ($fieldLOV as $listValueId => $listValue) {
							$autocompleteFieldLOV[] = array('v' => $listValueId, 'l' => $listValue);
						}
						//Autocomplete fields with no values are readonly
						if (empty($autocompleteFieldLOV)) {
							$readonly = true;
						}
						
						$autocompleteHTML .= '<div class="autocomplete_json" data-id="' . $fieldId . '" style="display:none;"';
						//Add data attribute for JS events if other fields need to update when this field changes
						$isSourceField = checkRowExists(
							ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', 
							array('source_field_id' => $fieldId)
						);
						if ($isSourceField) {
							$autocompleteHTML .= ' data-source_field="1"';
						}
						//Add data attribute for JS event to update placeholder if no values in list after click
						$isTargetField = checkRowExists(
							ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', 
							array('target_field_id' => $fieldId)
						);
						if ($isTargetField && !$filtered && $field['autocomplete_no_filter_placeholder']) {
							$autocompleteHTML .= ' data-auto_placeholder="' . htmlspecialchars(static::fPhrase($field['autocomplete_no_filter_placeholder'], array(), $t)) . '"';
						}
						
						$autocompleteHTML .= '>';
						$autocompleteHTML .= json_encode($autocompleteFieldLOV);
						$autocompleteHTML .= '</div>';
						
						$autocompleteHTML .= '<input type="hidden" name="' . $fieldName  . '" ';
						if (isset($fieldLOV[$fieldValue])) {
							$autocompleteHTML .= ' value="' . $fieldValue . '"';
							$fieldValue = $fieldLOV[$fieldValue];
						} else {
							$fieldValue = '';
						}
						$autocompleteHTML .= '/>';
						//Use hidden field as whats submitted and the text field is only for display
						$useTextFieldName = false;
					}
				}
				
				//Set type to "email" if validation is for an email address
				$fieldInputType = 'text';
				if ($field['field_validation'] == 'email') {
					$fieldInputType = 'email';
				}
				$html .= '<input type="' . $fieldInputType . '"';
				if ($readonly) {
					$html .= ' readonly ';
				}
				if ($useTextFieldName) {
					$html .= ' name="' . $fieldName . '"';
				}
				$html .= ' id="' . $fieldElementId . '"';
				if ($this->inRepeatBlock) {
					$html .= ' data-repeated="1" data-repeated_row="' . $field['repeatBlockRow'] . '" data-repeat_id="' . $field['repeatBlockStartId'] . '"';
				}
				if ($fieldValue !== false) {
					$html .= ' value="' . htmlspecialchars($fieldValue) . '"';
				}
				if ($field['placeholder'] !== '' && $field['placeholder'] !== null) {
					$html .= ' placeholder="' . htmlspecialchars(static::fPhrase($field['placeholder'], array(), $t)) . '"';
				}
				//Set maxlength to 255, or shorter for system field special cases
				$maxlength = 255;
				switch ($field['db_column']) {
					case 'salutation':
						$maxlength = 25;
						break;
					case 'screen_name':
					case 'password':
						$maxlength = 50;
						break;
					case 'first_name':
					case 'last_name':
					case 'email':
						$maxlength = 100;
						break;
				}	
				$html .= ' maxlength="' . $maxlength . '" />';
				$html .= $autocompleteHTML;
				break;
				
			case 'date':
				$html .= '<input type="text" class="jquery_form_datepicker" ';
				if ($readonly) {
					$html .= ' disabled ';
				}
				if ($field['show_month_year_selectors']) {
					$html .= ' data-selectors="1"';
				}
				$html .= ' id="' . $fieldElementId . '"/>';
				$html .= '<input type="hidden" name="' . $fieldName . '" id="' . $fieldElementId . '__0"';
				if ($fieldValue !== false) {
					$html .= ' value="' . htmlspecialchars($fieldValue) . '"';
				}
				$html .= '/>';
				$html .= '<input type="button" class="clear_date" value="x" id="' . $fieldElementId . '__clear"/>';
				break;
				
			case 'textarea':
				$html .= '<textarea rows="4" cols="51"';
				if ($field['placeholder'] !== '' && $field['placeholder'] !== null) {
					$html .= ' placeholder="' . htmlspecialchars(static::fPhrase($field['placeholder'], array(), $t)) . '"';
				}
				if ($readonly) {
					$html .= ' readonly ';
				}
				$html .= ' name="' . $fieldName . '" id="' . $fieldElementId . '">';
				if ($fieldValue !== false) {
					$html .= htmlspecialchars($fieldValue);
				}
				$html .= '</textarea>';
				break;
				
			case 'section_description':
				$description = static::fPhrase($field['description'], array(), $t);
				//If no tags...
				if($description == strip_tags($description)) {
					$description = nl2br('<p>' . $description . '</p>');
				}
				$html .= '<div class="description">' . $description . '</div>';
				break;
				
			case 'radios':
				$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $this->instanceId, $filtered);
				foreach ($fieldLOV as $value => $label) {
					$radioElementId = $fieldElementId . '_' . $value;
					$html .= '<div class="field_radio">';
					$html .= '<input type="radio"  value="' . htmlspecialchars($value) . '"';
					if ($value == $fieldValue) {
						$html .= ' checked="checked" ';
					}
					if ($readonly) {
						$html .= ' disabled ';
					}
					$html .= ' name="'. $fieldName. '" id="' . $radioElementId . '"/>';
					$html .= '<label for="' . $radioElementId . '">' . static::fPhrase($label, array(), $t) . '</label>';
					$html .= '</div>'; 
				}
				if ($readonly && !empty($fieldValue)) {
					$html .= '<input type="hidden" name="' . $fieldName . '" value="'.htmlspecialchars($fieldValue).'" />';
				}
				break;
				
			case 'centralised_radios':
				$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $this->instanceId, $filtered);
				$isCountryList = static::isCentralisedListOfCountries($field);
				$radioCount = 0;
				foreach ($fieldLOV as $value => $label) {
					$value = (string)$value;
					$radioElementId = $fieldElementId . '_' . ++$radioCount;
					$html .= '<div class="field_radio">';
					$html .= '<input type="radio"  value="' . htmlspecialchars($value) . '"';
					if ($value === $fieldValue) {
						$html .= ' checked="checked" ';
					}
					if ($readonly) {
						$html .= ' disabled ';
					}
					$html .= ' name="'. $fieldName. '" id="' . $radioElementId . '"/>';
					$html .= '<label for="' . $radioElementId . '">';
					//Make sure to use system country phrases if showing a list of countries
					if ($isCountryList && $t) {
						$html .= phrase('_COUNTRY_NAME_' . $value, array(), 'zenario_country_manager');
					} else {
						$html .= static::fPhrase($label, array(), $t);
					}
					$html .= '</label>';
					$html .= '</div>'; 
				}
				if ($readonly && !empty($fieldValue)) {
					$html .= '<input type="hidden" name="' . $fieldName . '" value="'.htmlspecialchars($fieldValue).'" />';
				}
				break;
				
			case 'select':
				$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $this->instanceId, $filtered);
				$html .= '<select ';
				if ($readonly) {
					$html .= 'disabled ';
				}
				$html .= ' name="' . $fieldName . '" id="' . $fieldElementId . '">';
				$html .= '<option value="">' . static::fPhrase('-- Select --', array(), $t) . '</option>';
				foreach ($fieldLOV as $value => $label) {
					$html .= '<option value="' . htmlspecialchars($value) . '"';
					if ($value == $fieldValue) {
						$html .= ' selected="selected" ';
					}
					$html .= '>' . static::fPhrase($label, array(), $t) . '</option>';
				}
				$html .= '</select>';
				if ($readonly) {
					$html .= '<input type="hidden" name="' . $fieldName . '" value="' . htmlspecialchars($fieldValue) . '"/>';
				}
				break;
				
			case 'centralised_select':
				$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $this->instanceId, $filtered);
				$isCountryList = static::isCentralisedListOfCountries($field);
				$html .= '<select ';
				if ($readonly) {
					$html .= 'disabled ';
				}
				$html .= ' name="' . $fieldName . '" id="' . $fieldElementId . '"';
				//Add class for JS events if other fields need to update when this field changes
				$isSourceField = checkRowExists(
					ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', 
					array('source_field_id' => $fieldId)
				);
				if ($isSourceField) {
					$html .= ' class="source_field"';
				}
				$html .= '>';
				$html .= '<option value="">' . static::fPhrase('-- Select --', array(), $t) . '</option>';
				foreach ($fieldLOV as $value => $label) {
					$value = (string)$value;
					$html .= '<option value="' . htmlspecialchars($value) . '"';
					if ($value === $fieldValue) {
						$html .= ' selected="selected" ';
					}
					$html .= '>';
					//Make sure to use system country phrases if showing a list of countries
					if ($isCountryList && $t) {
						$html .= phrase('_COUNTRY_NAME_' . $value, array(), 'zenario_country_manager');
					} else {
						$html .= static::fPhrase($label, array(), $t);
					}
					$html .= '</option>';
				}
				$html .= '</select>';
				if ($readonly) {
					$html .= '<input type="hidden" name="' . $fieldName . '" value="' . htmlspecialchars($fieldValue) . '"/>';
				}
				break;
				
			case 'checkboxes':
				$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $this->instanceId, $filtered);
				
				$cols = (int)$field['value_field_columns'];
				$html .= '<div class="checkboxes_wrap';
				if ($cols > 1) {
					$items = count($fieldLOV);
					$rows = ceil($items/$cols);
					$currentRow = $currentCol = 1;
					$html .= ' columns_' . $cols;
				}
				$html .= '">';
				
				$fieldValues = array();
				if ($fieldValue) {
					$fieldValues = explode(',', $fieldValue);
				}
				
				foreach ($fieldLOV as $value => $label) {
					$checkBoxHtml = '';
					$name = $fieldName . '_' . $value; 
					$checkboxElementId = $fieldElementId . '_' . $value;
					
					$selected = in_array($value, $fieldValues);
					$checkBoxHtml .= '<div class="field_checkbox"><input type="checkbox" data-value="' . $value . '"';
					if ($selected) {
						$checkBoxHtml .= ' checked="checked"';
					}
					if ($readonly) {
						$checkBoxHtml .= ' disabled ';
					}
					$checkBoxHtml .= ' name="' . $name . '" id="' . $checkboxElementId . '"/>';
					
					if ($readonly && $selected) {
						$checkBoxHtml .= '<input type="hidden" name="' . $name . '" value="' . $selected . '" />';
					}
					$checkBoxHtml .= '<label for="' . $checkboxElementId . '">' . static::fPhrase($label, array(), $t) . '</label></div>';
					
					
					if (($cols > 1) && ($currentRow > $rows)) {
						$currentRow = 1;
						$currentCol++;
					}
					if (($cols > 1) && ($currentRow == 1)) {
						$html .= '<div class="col_' . $currentCol . ' column">';
					}
					$html .= $checkBoxHtml;
					if (($cols > 1) && ($currentRow++ == $rows)) {
						$html .= '</div>';
					}
				}
				$html .= '</div>';
				break;
			
			case 'attachment':
				if ($fieldValue || $readonly) {
					$filename = 'Unknown file';
					if (is_numeric($fieldValue)) {
						$filename = getRow('files', 'filename', $fieldValue);
					} else {
						$filename = substr(basename($fieldValue), 0, -7);
					}
					$html .= '<div class="field_data">' . htmlspecialchars($filename) . '</div>';
					$html .= '<input type="submit" name="remove_attachment_' . $fieldId . '" value="' . static::fPhrase('Remove', array(), $t) . '" class="remove_attachment">';
					$html .= '<input type="hidden" name="' . $fieldName . '" value="' . htmlspecialchars($fieldValue) . '" />';
				} else {
					$html .= '<input type="file" name="' . $fieldName . '"/>';
				}
				break;
				
			case 'file_picker':
				if ($readonly) {
					$html .= '<div class="files">';
					if ($fieldValue) {
						$fileIds = str_getcsv((string)$fieldValue, ',', '"', '//');
						$count = 0;
						foreach ($fileIds as $fileId) {
							if (is_numeric($fileId) && ($fileLink = fileLink($fileId))) {
								$file = getRow('files', array('filename'), $fileId);
								$html .= '<div class="file_row">';
								$html .= '<p><a href="' . $fileLink . '" target="_blank">' . $file['filename'] . '</a></p>';
								$html .= '<input name="' . $fieldName . '_' . (++$count) . '" type="hidden" value="' . $fileId . '" />';
								$html .= '</div>';
							}
						}
					} else {
						$html .= static::fPhrase('No file found...', array(), $t);
					}
					$html .= '</div>';
					
					$html .= '<input type="hidden" name="' . $fieldName . '" value="'.htmlspecialchars($fieldValue) .'" />';
				} else {
					$filesJSON = array();
					if ($fieldValue) {
						$fileIds = str_getcsv((string)$fieldValue, ',', '"', '//');
						foreach ($fileIds as $fileId) {
							$fileData = array(
								'id' => $fileId
							);
							//If numeric file Id make sure this is linked to the user to prevent someone seeing someone elses file details
							if (is_numeric($fileId)) {
								$file = getRow('files', array('filename'), $fileId);
								$name = $file['filename'];
								$fileData['download_link'] = fileLink($fileId);
								
							//Otherwise show filename from cache path
							} else {
								$name = substr(basename($fileId), 0, -7);
							}
							$fileData['name'] = $name;
							$filesJSON[] = $fileData;
						}
					}
					$html .= '<div class="loaded_files" style="display:none;">' . json_encode($filesJSON) . '</div>';
					$html .= '<div class="files"></div>';
					$html .= '<div class="progress_bar" style="display:none;"></div>';
					$html .= '<div class="file_upload_button"><span>' . static::fPhrase('Upload file', array(), $t) . '</span>';
					$html .= '<input class="file_picker_field" type="file" name="' . $fieldName . '"';
					$fileCount = 1;
					if ($field['multiple_select']) {
						$html .= ' multiple';
						$fileCount = 5;
					}
					$html .= ' data-limit="' . $fileCount . '"';
					if ($field['extensions']) {
						$html .= 'data-extensions="' . htmlspecialchars($field['extensions']) . '"';
					}
					$html .= '/></div>';
				}
				break;
		}
		
		if (!empty($field['note_to_user'])) {
			$html .= '<div class="note_to_user">'. static::fPhrase($field['note_to_user'], array(), $t) .'</div>';
		}
		
		//Field containing div open
		$containerHTML = '<div id="' . $this->containerId . '_field_' . $fieldId . '" data-id="' . $fieldId . '" ';
		
		if ($field['visibility'] == 'visible_on_condition') {
			$containerHTML .= static::getVisibleConditionDataValuesHTML($field, $fields);
		}
		if ($fieldType == 'restatement') {
			$containerHTML .= ' data-fieldid="' . $field['restatement_field'] . '"';
		} elseif ($fieldType == 'calculated') {
			if ($field['value_prefix']) {
				$containerHTML .= ' data-prefix="' . $field['value_prefix'] . '"';
			}
			if ($field['value_postfix']) {
				$containerHTML .= ' data-postfix="' . $field['value_postfix'] . '"';
			}
		}
		//Check if field is hidden
		if (static::isFieldHidden($field, $fields, $data, $dataset, $this->instanceId)) {
			$containerHTML .= ' style="display:none;"';
		}
		//Containing div css classes
		$containerHTML .= 'class="form_field field_' . $fieldType . ' ' . htmlspecialchars($field['css_classes']);
		if ($this->inRepeatBlock) {
			$idParts = explode('_', $fieldId);
			if (count($idParts) == 2) {
				$parentFieldId = $idParts[0];
				$containerHTML .= ' field_' . $parentFieldId . '_repeat';
			}
		}
		if (($fieldType == 'text' && in_array($field['field_validation'], array('integer', 'number', 'floating_point')))
			|| ($fieldType == 'restatement' && isset($fields[$field['restatement_field']]) && in_array($fields[$field['restatement_field']]['field_validation'], array('integer', 'number', 'floating_point')))
		) {
			$containerHTML .= ' numeric';
		}
		if ($readonly) {
			$containerHTML .= ' readonly';
		}
		if ($field['visibility'] == 'visible_on_condition') {
			$containerHTML .= ' visible_on_condition';
		}
		$containerHTML .= ' ' . $extraClasses;
		$containerHTML .= '">';
		
		$html = $containerHTML . $html . '</div>';
		return $html;
	}
	
	public static function getVisibleConditionDataValuesHTML($field, $fields) {
		$html = '';
		$html .= ' data-cfieldid="' . $field['visible_condition_field_id'] . '"';
		$html .= ' data-cfieldvalue="' . $field['visible_condition_field_value'] . '"';
		$html .= ' data-cfieldinvert="' . $field['visible_condition_invert'] . '"';
		if (isset($fields[$field['visible_condition_field_id']])) {
			$cFieldType = static::getFieldType($fields[$field['visible_condition_field_id']]);
			$html .= ' data-cfieldtype="' . $cFieldType . '"';
			if ($cFieldType == 'checkboxes') {
				$html .= ' data-cfieldoperator="' . $field['visible_condition_checkboxes_operator'] . '"';
			}
		}
		return $html;
	}
	
	
	//Check if a centralised list is a list of countries
	public static function isCentralisedListOfCountries($field) {
		$source = $field['dataset_field_id'] ? $field['dataset_values_source'] : $field['values_source'];
		return ($source == 'zenario_country_manager::getActiveCountries');
	}
	
	
	//Get the ID of the last page break on a form
	public static function isFormMultiPage($formId) {
		$sql = '
			SELECT id
			FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_form_fields
			WHERE user_form_id = ' . (int)$formId . '
			AND field_type = "page_break"
			ORDER BY ord DESC
			LIMIT 1';
		$result = sqlSelect($sql);
		$field = sqlFetchAssoc($result);
		if ($field) {
			return $field['id'];
		}
		return false;
	}
	
	
	//Get a fields current list of values
	public static function getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $instanceId, &$filtered) {
		$field = $fields[$fieldId];
		$values = array();
		$fieldType = static::getFieldType($field);
		if (in_array($fieldType, array('radios', 'centralised_radios', 'select', 'checkboxes'))) {
			if ($field['dataset_field_id']) {
				$values = getDatasetFieldLOV($field['dataset_field_id']);
			} else {
				$values = static::getUnlinkedFieldLOV($field['id']);
			}
		//Where field lists can depend on another fields value (text fields can have autocomplete lists)
		} elseif ($fieldType == 'centralised_select' || $fieldType == 'text') {
			//Check if this field has a source field to filter the list
			$filter = false;
			
			if (empty($field['source_field_id'])) {
				$sourceFieldId = getRow(
					ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', 
					'source_field_id', 
					array('target_field_id' => $field['id'])
				);
			} else {
				$sourceFieldId = $field['source_field_id'];
			}
			
			//Get source fields current value for filtering
			if ($sourceFieldId && isset($fields[$sourceFieldId])) {
				$filter = static::getFieldCurrentValue($sourceFieldId, $fields, $data, $dataset, $instanceId);
				if ($filter) {
					$filtered = true;
				}
			}
			//Handle the case where a static filter is set but the field is also being dynamically filtered by another field
			$showValues = true;
			$datasetField = false;
			if ($field['dataset_field_id']) {
				$datasetField = getDatasetFieldDetails($field['dataset_field_id']);
				if ($filtered && $datasetField['values_source_filter'] && $filter != $datasetField['values_source_filter']) {
					$showValues = false;
				}
			} else {
				if ($filtered && $field['values_source_filter'] && $filter != $field['values_source_filter']) {
					$showValues = false;
				}
			}
			//If this field is filtered by another field but the value of that field is empty, show no values
			if ($showValues && (!$sourceFieldId || $filter)) {
				if ($field['dataset_field_id']) {
					$values = getDatasetFieldLOV($datasetField, true, $filter);
				} else {
					$values = static::getUnlinkedFieldLOV($field, true, $filter);
				}
			}
		}
		return $values;
	}
	
	
	public static function getUnlinkedFieldLOV($field, $flat = true, $filter = false) {
		if (!is_array($field)) {
			$field = getRow(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', 
				array('id', 'field_type', 'values_source', 'values_source_filter'), 
				$field
			);
		}
		$values = array();
		if (chopPrefixOffString('centralised_', $field['field_type']) || $field['field_type'] == 'restatement' || $field['field_type'] == 'text') {
			if (!empty($field['values_source_filter'])) {
				$filter = $field['values_source_filter'];
			}
			if ($values = getCentralisedListValues($field['values_source'], $filter)) {
				if (!$flat) {
					cms_core::$dbupCurrentRevision = 0;
					array_walk($values, 'getDatasetFieldLOVFlatArrayToLabeled');
					cms_core::$dbupCurrentRevision = false;
				}
			}
		} else {
			if ($flat) {
				$cols = 'label';
			} else {
				$cols = array('ord', 'label', 'id', 'is_invalid');
			}
			$values = getRowsArray(ZENARIO_USER_FORMS_PREFIX. 'form_field_values', $cols, array('form_field_id' => $field['id']), 'ord');
		}
		return $values;
	}
	
	//Get a fields current value
	public static function getFieldCurrentValue($fieldId, $fields, $data = false, $dataset = false, $instanceId = false, $ignoreSession = false, $forRestatementField = false, $recursionCount = 1, $forceLoadDefaultValue = false) {
		if ($recursionCount > 99 || !isset($fields[$fieldId])) {
			return false;
		}
		
		$value = false;
		$field = $fields[$fieldId];
		$formId = $field['user_form_id'];
		$fieldType = static::getFieldType($field);
		$fieldName = static::getFieldName($fieldId);
		
		//Added because some sites rely on a hidden select fields value to display other fields later on...
		$clearData = false;
		if ($fieldType == 'select') {
			$clearData = static::isFieldHidden($field, $fields, $data, $dataset, $instanceId);
		}
		
		//If value is array, the form has been submitted, load from this data
		if (is_array($data) && !$forceLoadDefaultValue && !$clearData) {
			if ($fieldType == 'checkboxes') {
				$values = array();
				$fields = array($fieldId => $field);
				$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $instanceId, $filtered);
				if (isset($data[$fieldName])) {
					$values = explode(',', $data[$fieldName]);
				} else {
					foreach ($fieldLOV as $valueId => $label) {
						if (!empty($data[$fieldName . '_' . $valueId])) {
							$values[] = $valueId;
						}
					}
				}
				$value = implode(',', $values);
				
			} elseif ($fieldType == 'attachment') {
				if (isset($data[$fieldName])) {
					$value = $data[$fieldName];
					//Security check. The only reason an attachment is loading a numeric Id is for partial responses.
					//Make sure that the loaded Id matches what is saved.
					$valid = static::checkAttachmentFieldFileIsValid(userId(), $formId, $fieldId, $value);
					if (!$valid) {
						$value = false;
					}
				}
				
			} elseif ($fieldType == 'file_picker') {
				$values = array();
				$filesCount = 0;
				$maxFilesCount = $field['multiple_select'] ? 5 : 1;
				
				if (isset($data[$fieldName])) {
					$values = explode(',', $data[$fieldName]);
				} else {
					$files = array_intersect_key($data, array_flip(preg_grep('/^' .  $fieldName . '_\d+$/', array_keys($data))));
					foreach ($files as $inputName => $fileValue) {
						$values[] = '"' . str_replace('"', '\\"', $fileValue) . '"';
						$filePickerValueLink[$fileValue] = $inputName;
						if (++$filesCount >= $maxFilesCount) {
							break;
						}
					}
				}
				if ($values) {
					$valid = static::checkFilePickerFieldFilesAreValid(userId(), $formId, $fieldId, $values);
					if ($valid) {
						$value = implode(',', $values);
					}
				}
			} else {
				if ($field['is_readonly'] && $field['dataset_field_id']) {
					$value = datasetFieldValue($dataset, $field['dataset_field_id'], userId());
				} elseif (isset($data[$fieldName])) {
					$value = $data[$fieldName];
				}
			}
			
			if ($value === false && !$ignoreSession && isset($_SESSION['custom_form_data'][$instanceId][$fieldId])) {
				$value = $_SESSION['custom_form_data'][$instanceId][$fieldId];
			}
			
		//If value is a number, load the value stored against the user
		} elseif (is_numeric($data) && $field['dataset_field_id'] && $field['preload_dataset_field_user_data']) {
			$userId = $data;
			$value = datasetFieldValue($dataset, $field['dataset_field_id'], $userId);
			
		//If field can have a default value load it
		} elseif (!$field['dataset_field_id'] && in_array($fieldType, array('radios', 'centralised_radios', 'select', 'centralised_select', 'text', 'textarea', 'checkbox', 'group'))) {
			//Static default value
			if ($field['default_value'] !== null) {
				$value = $field['default_value'];
			//Get value with static method
			} elseif (!empty($field['default_value_class_name']) && !empty($field['default_value_method_name'])) {
				inc($field['default_value_class_name']);
				$value = call_user_func(
					array(
						$field['default_value_class_name'], 
						$field['default_value_method_name']
					),
					$field['default_value_param_1'], 
					$field['default_value_param_2']
				);
			}
		}
		
		if ($fieldType == 'restatement') {
			$value = static::getFieldCurrentValue($field['restatement_field'], $fields, $data, $dataset, $instanceId, false, true, ++$recursionCount);
		} elseif ($fieldType == 'calculated') {
			$maxNumberSize = 999999999999999;
			$minNumberSize = -1 * $maxNumberSize;
			
			$calculationCodeJSON = static::expandCalculatedFieldsInCalculationCode($field['calculation_code'], $fields);
			$calculationCode = json_decode($calculationCodeJSON, true);
			
			$equation = '';
			$isNaN = false;
			
			if ($calculationCode) {
				foreach ($calculationCode as $step) {
					switch ($step['type']) {
						case 'static_value':
							$fieldValue = (float)$step['value'];
							$fieldValue = sprintf('%f', $fieldValue);
							$equation .= $fieldValue;
							break;
						case 'field':
							$fieldValue = static::getFieldCurrentValue($step['value'], $fields, $data, $dataset, $instanceId, false, false, ++$recursionCount);
							if (!static::validateNumericInput($fieldValue)) {
								$isNaN = true;
								break 2;
							} else {
								$fieldValue = sprintf('%f', (float)$fieldValue);
								if (!empty($fields[$step['value']]['repeatBlockStartId'])) {
									$repeatFieldValues = array($fieldValue);
									$rows = explode(',', $fields[$fields[$step['value']]['repeatBlockStartId']]['rows']);
									
									//Target field and calculated field are in the same repeat block
									if (!empty($field['repeatBlockStartId']) && $field['repeatBlockStartId'] == $fields[$step['value']]['repeatBlockStartId']) {
										if ($field['repeatBlockRow'] > 1) {
											$repeatFieldValues = array();
											$rows = array($field['repeatBlockRow']);
										} else {
											$rows = array();
										}
									}
									
									foreach ($rows as $row) {
										if ($row != 1) {
											$repeatFieldId = $step['value'] . '_' . $row;
											$repeatFieldValue = static::getFieldCurrentValue($repeatFieldId, $fields, $data, $dataset, $instanceId, false, false, ++$recursionCount, !empty($fields[$repeatFieldId]['loadDefaultValue']));
											if (!static::validateNumericInput($repeatFieldValue)) {
												$isNaN = true;
												break 3;
											} else {
												$repeatFieldValue = sprintf('%f', (float)$repeatFieldValue);
											}
											$repeatFieldValues[] = $repeatFieldValue;
										}
									}
									
									$fieldValue = '(0+' . implode('+', $repeatFieldValues) . ')';
								}
								$equation .= $fieldValue;
							}
							$equation = '0+(' . $equation . ')';
							break;
						case 'parentheses_open':
							$equation .= '(';
							break;
						case 'parentheses_close':
							$equation .= ')';
							break;
						case 'operation_addition':
							$equation .= '+';
							break;
						case 'operation_subtraction':
							$equation .= '-';
							break;
						case 'operation_multiplication':
							$equation .= '*';
							break;
						case 'operation_division':
							$equation .= '/';
							break;
					}
				}
				
				if (!$isNaN && $equation) {
					$calculator = new calculator();
					try {
						$value = $calculator->calculate($equation);
						if ($value === false || ($value > $maxNumberSize) || ($value < $minNumberSize)) {
							$isNaN = true;
						}
					} catch (Exception $e) {
						$isNaN = true;
					}
				}
			}
			
			if ($isNaN) {
				$value = 'NaN';
			} else {
				if (!$value) {
					$value = 0;
				} else {
					$value = rtrim(rtrim(sprintf('%0.2f', $value), '0'), '.');
				} 
				
				if ($field['value_prefix']) {
					$value = $field['value_prefix'] . $value;
				}
				if ($field['value_postfix']) {
					$value .= $field['value_postfix'];
				}
			}
		}
		
		//For mirror fields mirroring select lists, return the label not the value as this is just for display
		if ($forRestatementField && in_array($fieldType, array('select', 'centralised_select'))) {
			$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $instanceId, $filtered);
			$value = isset($fieldLOV[$value]) ? $fieldLOV[$value] : '';
		}
		
		return $value;
	}
	
	
	private static function expandCalculatedFieldsInCalculationCode($calculationCodeJSON, $fields, $recursionCount = 1) {
		$calculationCode = json_decode($calculationCodeJSON, true);
		
		if ($recursionCount > 99) {
			return false;
		}
		
		if ($calculationCode) {
			foreach ($calculationCode as $index => $step) {
				if ($step['type'] == 'field' && $fields[$step['value']] && ($fields[$step['value']]['field_type'] == 'calculated')) {
					$nestedCalculationCode = json_decode($fields[$step['value']]['calculation_code']);
					if ($nestedCalculationCode) {
						//Surround nested calculation code with parentheses
						$openParentheses = array('type' => 'parentheses_open');
						$closeParentheses = array('type' => 'parentheses_close');
						array_unshift($nestedCalculationCode, $openParentheses);
						array_push($nestedCalculationCode, $closeParentheses);
						
						//Strip square brackets
						$nestedCalculationCodeJSON = substr(json_encode($nestedCalculationCode), 1, -1);
						
						//Place into original calculation code
						$calculationCodeJSON = str_replace(json_encode($step), $nestedCalculationCodeJSON, $calculationCodeJSON);
						
						//Keep calling until all calculated fields are expanded or max recursion limit reached
						return static::expandCalculatedFieldsInCalculationCode($calculationCodeJSON, $fields, $recursionCount + 1);
					}
				}
			}
		}
		return $calculationCodeJSON;
	}
	
	
	public static function checkAttachmentFieldFileIsValid($userId, $formId, $fieldId, $file) {
		//Numeric file Ids are only valid if they're in a partial response
		if (is_numeric($file)) {
			$sql = '
				SELECT d.value
				FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_partial_response_data d
				INNER JOIN ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_partial_response r
					ON d.user_partial_response_id = r.id
					AND r.user_id = ' . (int)$userId . '
					AND r.form_id = ' . (int)$formId . '
				WHERE d.form_field_id = ' . (int)$fieldId;
			$result = sqlSelect($sql);
			$row = sqlFetchAssoc($result);
			if (empty($row['value']) || $row['value'] != $file) {
				return false;
			}
		}
		return true;
	}
	
	
	public static function checkFilePickerFieldFilesAreValid($userId, $formId, $fieldId, $files) {
		if ($files) {
			if (!is_array($files)) {
				$files = array($files);
			}
			$sql = '
				SELECT d.value
				FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_partial_response_data d
				INNER JOIN ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_partial_response r
					ON d.user_partial_response_id = r.id
					AND r.user_id = ' . (int)$userId . '
					AND r.form_id = ' . (int)$formId . '
				WHERE d.form_field_id = ' . (int)$fieldId;
			$result = sqlSelect($sql);
			$row = sqlFetchAssoc($result);
			$partialSave = array();
			if ($row['value']) {
				$partialSave = explode(',', $row['value']);
			}
			foreach ($files as $file) {
				//Numeric file Ids are only valid if they are already saved next to the user account or they're in a partial response
				if (is_numeric($file)) {
					$saved = checkRowExists('custom_dataset_files_link', array('field_id' => $fieldId, 'linking_id' => $userId, 'file_id' => $file));
					if (!$saved && !in_array($file, $partialSave)) {
						return false;
					}
					
				}
			}
		}
		return true;
	}
	
	//Check if a field on a form is hidden or not
	public static function isFieldHidden($field, $fields, $data, $dataset, $instanceId) {
		$hidden = false;
		if ($field['visibility'] == 'hidden') {
			return true;
		} elseif ($field['visibility'] == 'visible_on_condition'
			&& !empty($field['visible_condition_field_id'])
			&& isset($fields[$field['visible_condition_field_id']])
		) {
			$cField = $fields[$field['visible_condition_field_id']];
			$cFieldName = static::getFieldName($cField['id']);
			$cFieldType = static::getFieldType($cField);
			$cFieldValue = static::getFieldCurrentValue($field['visible_condition_field_id'], $fields, $data, $dataset, $instanceId);
			
			if ($cFieldType == 'checkbox' || $cFieldType == 'group') {
				if ((bool)$field['visible_condition_field_value'] != (bool)$cFieldValue) {
					$hidden = true;
				}
			} elseif ($cFieldType == 'radios' || $cFieldType == 'select' || $cFieldType == 'centralised_radios' || $cFieldType == 'centralised_select') {
				if (($field['visible_condition_field_value'] && ($field['visible_condition_field_value'] != $cFieldValue))
					|| (!$field['visible_condition_field_value'] && !$cFieldValue)
				) {
					$hidden = true;
				}
			} elseif ($cFieldType == 'checkboxes') {
				$cFieldValues = $cFieldValue ? explode(',', $cFieldValue) : array();
				$values = $field['visible_condition_field_value'] ? explode(',', $field['visible_condition_field_value']) : array();
				
				if ($field['visible_condition_checkboxes_operator'] == 'AND') {
					$sharedValues = array_intersect($cFieldValues, $values);
					$selectedRequiredValues = array_intersect($cFieldValues, $sharedValues);
						
					$hidden = ($selectedRequiredValues != $values);
				} else {
					$hidden = true;
					foreach ($cFieldValues as $cFieldValue) {
						if (in_array($cFieldValue, $values)) {
							$hidden = false;
							break;
						}
					}
				}
				
			}
		}
		if ($field['visible_condition_invert']) {
			$hidden = !$hidden;
		}
		return $hidden;
	}
	
	
	//Get the name of the field element on the form
	public static function getFieldName($fieldId) {
		return 'field_' . $fieldId;
	}
	
	
	//Get a fields type (comes from either form or dataset field)
	public static function getFieldType($field) {
		return (!empty($field['type']) ? $field['type'] : $field['field_type']);
	}
	
	
	//Get the fields of a form
	public static function getFields($formId, $fieldId = false, $includeRepeatFields = false, $data = false, $instanceId = false) {
		$fields = array();
		$sql = '
			SELECT 
				uff.id, 
				uff.user_form_id,
				uff.ord, 
				uff.is_readonly, 
				uff.is_required,
				uff.mandatory_condition_field_id,
				uff.mandatory_condition_invert,
				uff.mandatory_condition_checkboxes_operator,
				uff.mandatory_condition_field_value,
				uff.visibility,
				uff.visible_condition_field_id,
				uff.visible_condition_invert,
				uff.visible_condition_checkboxes_operator,
				uff.visible_condition_field_value,
				uff.label,
				uff.name,
				uff.placeholder,
				uff.preload_dataset_field_user_data,
				uff.default_value,
				uff.default_value_class_name,
				uff.default_value_method_name,
				uff.default_value_param_1,
				uff.default_value_param_2,
				uff.note_to_user,
				uff.css_classes,
				uff.div_wrap_class,
				uff.required_error_message,
				uff.validation AS field_validation,
				uff.validation_error_message AS field_validation_error_message,
				uff.field_type,
				uff.next_button_text,
				uff.previous_button_text,
				uff.description,
				uff.calculation_code,
				uff.value_prefix,
				uff.value_postfix,
				uff.restatement_field,
				uff.values_source,
				uff.values_source_filter,
				uff.custom_code_name,
				uff.autocomplete,
				uff.autocomplete_class_name,
				uff.autocomplete_method_name,
				uff.autocomplete_param_1,
				uff.autocomplete_param_2,
				uff.autocomplete_no_filter_placeholder,
				uff.value_field_columns,
				uff.min_rows,
				uff.max_rows,
				uff.hide_in_page_switcher,
				uff.show_month_year_selectors,
				uff.invalid_field_value_error_message,
				uff.word_limit,
				cdf.id AS dataset_field_id, 
				cdf.type, 
				cdf.db_column, 
				cdf.label AS dataset_field_label,
				cdf.default_label,
				cdf.is_system_field, 
				cdf.dataset_id, 
				cdf.validation AS dataset_field_validation, 
				cdf.validation_message AS dataset_field_validation_message,
				cdf.multiple_select,
				cdf.store_file,
				cdf.extensions,
				cdf.values_source AS dataset_values_source
			FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_forms AS uf
			INNER JOIN ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_form_fields AS uff
				ON uf.id = uff.user_form_id
			LEFT JOIN ' . DB_NAME_PREFIX . 'custom_dataset_fields AS cdf
				ON uff.user_field_id = cdf.id
			WHERE TRUE';
		if ($formId) {
			$sql .= '
				AND uff.user_form_id = ' . (int)$formId;
		}
		if ($fieldId) {
			$sql .= '
				AND uff.id = ' . (int)$fieldId;
		}
		$sql .= '
			ORDER BY uff.ord';
		$result = sqlSelect($sql);
		$repeatBlockField = false;
		$repeatBlockFields = array();
		$inRepeatBlock = false;
		while ($field = sqlFetchAssoc($result)) {
			$fieldType = static::getFieldType($field);
			
			if ($includeRepeatFields && $fieldType == 'repeat_start') {
				$repeatBlockField = $field;
				$inRepeatBlock = true;
			} elseif ($includeRepeatFields && $fieldType == 'repeat_end') {
				$rows = static::getRepeatBlockRows($repeatBlockField, $repeatBlockFields, $data, $instanceId);
				
				$lastRow = end($rows);
				$rowAdded = false;
				//Add row button pressed
				if (post('add_repeat_row') && (post('add_repeat_row') == $repeatBlockField['id'])) {
					$rowAdded = true;
					$rows[] = ++$lastRow;
				}
				
				$rowCount = 0;
				$rowsList = array();
				foreach ($rows as $row) {
					//Delete row button pressed
					if (post('delete_repeat_row') && (post('delete_repeat_row') == $repeatBlockField['id']) && (post('row') == $row)) {
						continue;
					}
					
					if (++$rowCount > $repeatBlockField['max_rows']) {
						break;
					}
					
					$firstRepeatBlockField = reset($repeatBlockFields);
					$lastRepeatBlockField = end($repeatBlockFields);
					
					foreach ($repeatBlockFields as $rFieldId => $rField) {
						$rFieldNewId = $rFieldId;
						if ($row != 1) {
							$rFieldNewId .= '_' . $row;
						}
						$fields[$rFieldNewId] = $repeatBlockFields[$rFieldId];
						$fields[$rFieldNewId]['repeatBlockRow'] = $row;
						$fields[$rFieldNewId]['repeatBlockStartId'] = $repeatBlockField['id'];
						if ($rFieldId == $firstRepeatBlockField['id']) {
							$fields[$rFieldNewId]['repeatBlockStart'] = true;
						}
						if ($rFieldId == $lastRepeatBlockField['id']) {
							$fields[$rFieldNewId]['repeatBlockEnd'] = true;
							if ($rowCount > $repeatBlockField['min_rows']) {
								$fields[$rFieldNewId]['repeatBlockDeleteButton'] = true;
							}
						}
						if ($rowAdded && $row == $lastRow) {
							$fields[$rFieldNewId]['loadDefaultValue'] = true;
						}
						//If visible on condition on a repeat field, use the repeated field in the same block rather than the original field.
						if ($fields[$rFieldNewId]['visible_condition_field_id'] && isset($repeatBlockFields[$fields[$rFieldNewId]['visible_condition_field_id']]) && $row != 1) {
							$fields[$rFieldNewId]['visible_condition_field_id'] = $fields[$rFieldNewId]['visible_condition_field_id'] . '_' . $row;
						}
						//If mandatory on condition on a repeat field, use the repeated field in the same block rather than the original field.
						if ($fields[$rFieldNewId]['mandatory_condition_field_id'] && isset($repeatBlockFields[$fields[$rFieldNewId]['mandatory_condition_field_id']]) && $row != 1) {
							$fields[$rFieldNewId]['mandatory_condition_field_id'] = $fields[$rFieldNewId]['mandatory_condition_field_id'] . '_' . $row;
						}
						
						$fieldType = static::getFieldType($fields[$rFieldNewId]);
						if ($fieldType == 'text' || $fieldType == 'centralised_select' || $fieldType == 'centralised_radios') {
							if (!isset($fields[$rFieldNewId]['source_field_id'])) {
								$sourceFieldId = getRow(
									ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link',
									'source_field_id',
									array('target_field_id' => $fields[$rFieldNewId]['id'])
								);
								
								if (isset($repeatBlockFields[$sourceFieldId]) && $row != 1) {
									$fields[$rFieldNewId]['source_field_id'] = $sourceFieldId . '_' . $row;
								}
							}
						}
						
					}
					$rowsList[] = $row;
				}
				$fields[$repeatBlockField['id']]['current_rows'] = $rowCount;
				$fields[$repeatBlockField['id']]['rows'] = implode(',', $rowsList);
				$repeatBlockField = false;
				$repeatBlockFields = array();
				$inRepeatBlock = false;
			} elseif ($inRepeatBlock) {
				$repeatBlockFields[$field['id']] = $field;
			}
			$fields[$field['id']] = $field;
		}
		return $fields;
	}
	
	
	//Get a forms details
	public static function getForm($formId) {
		$form = getRow(
			ZENARIO_USER_FORMS_PREFIX . 'user_forms', 
			array(
				'id',
				'type',
				'name',
				'title',
				'title_tag',
				
				'send_email_to_logged_in_user',
				'user_email_use_template_for_logged_in_user',
				'user_email_template_logged_in_user',
				'send_email_to_email_from_field',
				'user_email_field',
				'user_email_use_template_for_email_from_field',
				'user_email_template_from_field',	
				
				'send_email_to_admin',
				'admin_email_use_template',
				'admin_email_addresses',
				'admin_email_template',
				'reply_to',
				'reply_to_email_field',
				'reply_to_first_name',
				'reply_to_last_name',
				'save_data',
				'save_record',
				'send_signal',
				'redirect_after_submission',
				'redirect_location',
				'user_status',
				'log_user_in',
				'log_user_in_cookie' ,
				'add_user_to_group',
				'use_captcha',
				'captcha_type',
				'extranet_users_use_captcha',
				'profanity_filter_text',
				'user_duplicate_email_action',
				'duplicate_email_address_error_message',
				'update_linked_fields',
				'no_duplicate_submissions',
				'duplicate_submission_message',
				'add_logged_in_user_to_group',
				'translate_text', 
				'default_next_button_text', 
				'default_previous_button_text',
				'submit_button_text',
				'show_success_message',
				'success_message',
				'allow_partial_completion',
				'partial_completion_mode',
				'partial_completion_message',
				'allow_clear_partial_data',
				'clear_partial_data_message',
				'use_honeypot',
				'honeypot_label',
				'show_page_switcher',
				'page_switcher_navigation',
				'hide_final_page_in_page_switcher',
				'page_end_name',
				'enable_summary_page'
			), 
			$formId
		);
		return $form;
	}
	
	
	public static function fPhrase($text, $replace, $translate) {
		if ($translate) {
			return phrase($text, $replace, 'zenario_user_forms');
		}
		applyMergeFields($text, $replace);
		return $text;
	}
	public static function fNPhrase($text, $pluralText, $n, $replace, $translate) {
		if ($translate) {
			return phrase($text, $pluralText, $n, $replace, 'zenario_user_forms');
		} elseif ($n == 1) {
			applyMergeFields($text, $replace);
			return $text;
		} else {
			applyMergeFields($pluralText, $replace);
			return $pluralText;
		}
	}
	
	
	//Validate a form submission
	private function validateForm($formId, $fields, &$data, $page = false, $finalSubmit = false, $saveToCompleteLater = false) {
		$dataset = getDatasetDetails('users');
		$form = static::getForm($formId);
		$t = $form['translate_text'];
		
		//Only validate fields on the current page (or all if final submit)
		if (!$page || $finalSubmit) {
			$pageFields = $fields;
		} else {
			$pageFields = static::filterFormFieldsByPage($fields, $page);
			static::tempSaveFormFields($pageFields, $fields, $data, $dataset, $this->instanceId);
		}
		
		foreach ($pageFields as $fieldId => $field) {
			//Validate repeated fields
			$fieldType = static::getFieldType($field);
			$error = static::getFieldErrorMessage($form, $fieldId, $fields, $data, $dataset, $this->instanceId, $saveToCompleteLater);
			if ($error) {
				$this->errors[$fieldId] = $error;
			}
		}
		
		//Validate honeypot field
		if ($form['use_honeypot'] && isset($data['field_hp']) && $data['field_hp'] !== '') {
		    $this->errors['honeypot'] = static::fPhrase('This field must be left blank.', array(), $t);
		}
		
		//Validate captcha
		$error = $this->getCaptchaError($form);
		if ($error) {
			$this->errors['captcha'] = $error;
		}
		
		//Custom messages by modules extending this
		$customErrors = $this->getCustomErrors($fields, $pageFields, $data);
		if (is_array($customErrors)) {
			foreach ($customErrors as $fieldId => $error) {
				if (!isset($this->errors[$fieldId])) {
					$this->errors[$fieldId] = $error;
				}
			}
		}
		
		if (!empty($this->errors)) {
			$page = 1;
			foreach ($fields as $fieldId => $field) {
				$fieldType = static::getFieldType($field);
				if ($fieldType == 'page_break') {
					++$page;
				}
				if (isset($this->errors[$fieldId])) {
					$this->firstErrorPage = $page;
					break;
				}
			}
		}
		return empty($this->errors);
	}
	
	protected function uploadFieldAttachment($fieldId, $t, &$data) {
		$fieldName = static::getFieldName($fieldId);
		if (isset($_FILES[$fieldName]['tmp_name']) && is_uploaded_file($_FILES[$fieldName]['tmp_name']) && cleanDownloads()) {
			try {
				//Undefined | Multiple Files | $_FILES Corruption Attack
				//If this request falls under any of them, treat it invalid.
				if (!isset($_FILES[$fieldName]['error']) || is_array($_FILES[$fieldName]['error'])) {
					throw new RuntimeException(static::fPhrase('Invalid parameters.', array(), $t));
				}
				
				//Check $_FILES[$fieldName]['error'] value.
				switch ($_FILES[$fieldName]['error']) {
					case UPLOAD_ERR_OK:
						break;
					case UPLOAD_ERR_NO_FILE:
						throw new RuntimeException(static::fPhrase('No file sent.', array(), $t));
					case UPLOAD_ERR_INI_SIZE:
					case UPLOAD_ERR_FORM_SIZE:
						throw new RuntimeException(static::fPhrase('Exceeded filesize limit.', array(), $t));
					default:
						throw new RuntimeException(static::fPhrase('Unknown errors.', array(), $t));
				}
				
				//Check filesize. 
				if ($_FILES[$fieldName]['size'] > 1000000) {
					throw new RuntimeException(static::fPhrase('Exceeded filesize limit.', array(), $t));
				}
				
				//TODO mime type validation
				
				//DO NOT TRUST $_FILES['upfile']['mime'] VALUE !!
				//Check MIME Type by yourself.
				/*
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				if (false === $ext = array_search(
					$finfo->file($_FILES['upfile']['tmp_name']),
					array(
						'jpg' => 'image/jpeg',
						'png' => 'image/png',
						'gif' => 'image/gif',
					),
					true
				)) {
					throw new RuntimeException('Invalid file format.');
				}
				*/
				
				//File is valid, add to cache and remember the location
				$randomDir = createRandomDir(30, 'uploads');
				$cacheDir = $randomDir. safeFileName($_FILES[$fieldName]['name'], true). '.upload';
				if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], CMS_ROOT. $cacheDir)) {
					chmod(CMS_ROOT. $cacheDir, 0666);
					$data[$fieldName] = $cacheDir;
				}
				
			} catch (RuntimeException $e) {
				$this->errors[$fieldId] = $e->getMessage();
			}
		}
	}
	
	
	
	//TODO? does profile need this?
	protected function getCustomMessages($fields, $pageFields, $data) {
		return false;
	}
	
	
	public static function filterFormFieldsByPage($fields, $page) {
		if (!$page) {
			$page = 1;
		}
		$currentPageNo = 1;
		foreach ($fields as $fieldId => $field) {
			if ($page != $currentPageNo) {
				unset($fields[$fieldId]);
			}
			if ($field['field_type'] == 'page_break') {
				unset($fields[$fieldId]);
				$currentPageNo++;
			}
		}
		return $fields;
	}
	
	
	//Check if a field is valid and return any error message
	public static function getFieldErrorMessage($form, $fieldId, $fields, &$data, $dataset, $instanceId, $ignoreRequired) {
		$field = $fields[$fieldId];
		$fieldType = static::getFieldType($field);
		$fieldValue = static::getFieldCurrentValue($fieldId, $fields, $data, $dataset, $instanceId);
		$t = $form['translate_text'];
		
		//If this field is conditionally mandatory, see if the condition is met
		if ($field['mandatory_condition_field_id']) {
			$requiredFieldId = $field['mandatory_condition_field_id'];
			$requiredField = $fields[$requiredFieldId];
			$requiredFieldName = static::getFieldName($requiredFieldId);
			$requiredFieldType = static::getFieldType($requiredField);
			$requiredFieldValue = static::getFieldCurrentValue($requiredFieldId, $fields, $data, $dataset, $instanceId);
			
			switch($requiredFieldType) {
				case 'checkbox':
					if ($field['mandatory_condition_field_value'] == 1) {
						if ($requiredFieldValue) {
							$field['is_required'] = true;
						}
					} elseif ($field['mandatory_condition_field_value'] == 0) {
						if (!$requiredFieldValue) {
							$field['is_required'] = true;
						}
					}
					break;
				case 'radios':
				case 'select':
				case 'centralised_radios':
				case 'centralised_select':
					if (($field['mandatory_condition_field_value'] && ($field['mandatory_condition_field_value'] == $requiredFieldValue))
						|| (!$field['mandatory_condition_field_value'] && $requiredFieldValue)
					) {
						$field['is_required'] = true;
					}
					break;
				case 'checkboxes':
					$requiredFieldValues = $requiredFieldValue ? explode(',', $requiredFieldValue) : array();
					$values = $field['mandatory_condition_field_value'] ? explode(',', $field['mandatory_condition_field_value']) : array();
					
					if ($field['mandatory_condition_checkboxes_operator'] == 'AND') {
						$sharedValues = array_intersect($requiredFieldValues, $values);
						$selectedRequiredValues = array_intersect($requiredFieldValues, $sharedValues);
						
						$field['is_required'] = ($selectedRequiredValues == $values);
					} else {
						foreach ($requiredFieldValues as $requiredFieldValue) {
							if (in_array($requiredFieldValue, $values)) {
								$field['is_required'] = true;
								break;
							}
						}
					}
					
					break;
			}
			
			if ($field['mandatory_condition_invert']) {
				$field['is_required'] = !$field['is_required'];
			}
		}
		
		//Check if field is required but has no data
		if ($field['is_required'] && !$ignoreRequired) {
			switch ($fieldType) {
				case 'group':
				case 'checkbox':
				case 'radios':
				case 'select':
				case 'checkboxes':
				case 'attachment':
				case 'file_picker':
					if (!$fieldValue) {
						return static::fPhrase($field['required_error_message'], array(), $t);
					}
					break;
				case 'centralised_radios':
				case 'centralised_select':
				case 'text':
				case 'date':
				case 'textarea':
				case 'url':
					if ($fieldValue === null || $fieldValue === '' || $fieldValue === false) {
						return static::fPhrase($field['required_error_message'], array(), $t);
					}
					break;
			}
		}
		
		//Check if user is allowed more than one submission
		if (!userId()
			&& $field['db_column'] == 'email' 
			&& $form['save_data']
			&& $form['user_duplicate_email_action'] == 'stop'
			&& $form['duplicate_email_address_error_message']
		) {
			$userId = getRow('users', 'id', array('email' => $fieldValue));
			if ($userId) {
				$responseExists = checkRowExists(
					ZENARIO_USER_FORMS_PREFIX. 'user_response', 
					array('user_id' => $userId, 'form_id' => $form['id'])
				);
			
				if ($responseExists) {
					return static::fPhrase($form['duplicate_email_address_error_message'], array(), $t);
				}
			}
		}
		
		//Text field validation
		if ($fieldType == 'text' && $field['field_validation'] && $fieldValue !== '' && $fieldValue !== false) {
			switch ($field['field_validation']) {
				case 'email':
					if (!validateEmailAddress($fieldValue)) {
						return static::fPhrase($field['field_validation_error_message'], array(), $t);
					}
					break;
				case 'URL':
					if (filter_var($fieldValue, FILTER_VALIDATE_URL) === false) {
						return static::fPhrase($field['field_validation_error_message'], array(), $t);
					}
					break;
				case 'integer':
					if (filter_var($fieldValue, FILTER_VALIDATE_INT) === false) {
						return static::fPhrase($field['field_validation_error_message'], array(), $t);
					}
					break;
				case 'number':
					if (!static::validateNumericInput($fieldValue)) {
						return static::fPhrase($field['field_validation_error_message'], array(), $t);
					}
					break;
				case 'floating_point':
					if (filter_var($fieldValue, FILTER_VALIDATE_FLOAT) === false) {
						return static::fPhrase($field['field_validation_error_message'], array(), $t);
					}
					break;
			}
		}
		
		//Multiple values invalide response validation
		if ($field['invalid_field_value_error_message'] && ($fieldType == 'checkboxes' || $fieldType == 'radios' || $fieldType == 'select')) {
			foreach (explode(',', (string)$fieldValue) as $fieldValueId) {
				$isInvalid = getRow(ZENARIO_USER_FORMS_PREFIX . 'form_field_values', 'is_invalid', array('id' => $fieldValueId, 'form_field_id' => $fieldId));
				if ($isInvalid) {
					return static::fPhrase($field['invalid_field_value_error_message'], array(), $t);
				}
			}
		}
		
		//Dataset field validation
		if ($field['dataset_field_id'] && $field['dataset_field_validation'] && $fieldValue !== '') {
			switch ($field['dataset_field_validation']) {
				case 'email':
					if (!validateEmailAddress($fieldValue)) {
						return static::fPhrase('Please enter a valid email address', array(), $t);
					}
					break;
				case 'emails':
					if (!validateEmailAddress($fieldValue, true)) {
						return static::fPhrase('Please enter a valid list of email addresses', array(), $t);
					}
					break;
				case 'no_spaces':
					if (preg_replace('/\S/', '', $fieldValue)) {
						return static::fPhrase('This field cannot contain spaces', array(), $t);
					}
					break;
				case 'numeric':
					if (($fieldValue !== '' && $fieldValue !== false) && !static::validateNumericInput($fieldValue)) {
						return static::fPhrase('This field must be numeric', array(), $t);
					}
					break;
				case 'screen_name':
					if (empty($fieldValue)) {
						$validationMessage = static::fPhrase('Please enter a screen name', array(), $t);
					} elseif (!validateScreenName($fieldValue)) {
						$validationMessage = static::fPhrase('Please enter a valid screen name', array(), $t);
					} elseif ((userId() && checkRowExists('users', array('screen_name' => $fieldValue, 'id' => array('!' => userId())))) 
						|| (!userId() && checkRowExists('users', array('screen_name' => $fieldValue)))
					) {
						return static::fPhrase('The screen name you entered is in use', array(), $t);
					}
					break;
			}
		}
		
		if ($fieldType == 'textarea' && $field['word_limit']) {
			if (str_word_count($fieldValue) > $field['word_limit']) {
				return static::fNPhrase('Cannot be more than [[n]] word.', 'Cannot be more than [[n]] words.', $field['word_limit'], array('n' => $field['word_limit']), $t);
			}
		}
		
		return false;
	}
	
	public static function validateNumericInput($input) {
		if (!is_numeric($input)) {
			return false;
		}
		$input = (string)$input;
		if (strpos($input, '.') !== false) {
			$input = str_replace('.', '', $input);
		}
		if (!ctype_digit($input)) {
			return false;
		}
		return true;
	}
	
	
	//Save a form submission
	public static function saveForm($formId, $formFields, $data, $userId, $instanceId, $fullSave = true, $pageSave = false, $partialSave = false) {
		$dataset = getDatasetDetails('users');
		
		//Save data to session for multipage forms when changing pages
		if ($pageSave) {
		    $pageFields = static::filterFormFieldsByPage($formFields, $pageSave);
            static::tempSaveFormFields($pageFields, $formFields, $data, $dataset, $instanceId);
		}
		
		if ($fullSave !== true) {
			return true;
		}
		
		$form = static::getForm($formId);
		
		$userSystemFields = array();
		$userCustomFields = array();
		$unlinkedFields = array();
		$checkBoxValues = array();
		$filePickerValues = array();
		$attachments = array();
		$fieldIdValueLink = array();
		
		static::getFormSaveData($formFields, $data, $dataset, $instanceId, $fieldIdValueLink, $userSystemFields, $userCustomFields, $unlinkedFields, $checkBoxValues, $filePickerValues, $attachments);
		
		if ($userId) {
			//Save the data temporarily for the user to complete later
			if ($partialSave) {
				$values = $userSystemFields + $userCustomFields + $unlinkedFields + $filePickerValues + $checkBoxValues;
				static::createFormPartialResponse($userId, $formId, $formFields, $data, $values, $instanceId);
				return true;
			}
			//If properly submitting the form then delete the partial response
			static::deleteOldPartialResponse($formId, $userId);
			
			if ($form['update_linked_fields']) {
				$fields = array();
				foreach ($userSystemFields as $fieldData) {
					if (empty($fieldData['readonly']) && $fieldData['db_column'] !== 'email') {
						$fields[$fieldData['db_column']] = $fieldData['value'];
					}
				}
				
				static::saveUser($fields, $userId);
				static::saveUserCustomData($userCustomFields, $userId);
				static::saveUserMultiCheckboxData($checkBoxValues, $userId);
				static::saveUserFilePickerData($filePickerValues, $userId);
			}
			
			if ($form['add_logged_in_user_to_group']) {
				addUserToGroup($userId, $form['add_logged_in_user_to_group']);
			}
		} elseif ($form['save_data']) {
			$fields = array();
			foreach ($userSystemFields as $fieldData) {
				if (empty($fieldData['readonly'])) {
					$fields[$fieldData['db_column']] = $fieldData['value'];
				}
			}
			//Try to save data if email field is on form
			if (isset($fields['email'])) { 
				//Duplicate email found
				if ($userId = getRow('users', 'id', array('email' => $fields['email']))) {
					switch ($form['user_duplicate_email_action']) {
						//Don’t change previously populated fields
						case 'merge': 
							$fields['modified_date'] = now();
							static::mergeUserData($fields, $userId, $form['log_user_in']);
							static::saveUserCustomData($userCustomFields, $userId, true);
							static::saveUserMultiCheckboxData($checkBoxValues, $userId, true);
							static::saveUserFilePickerData($filePickerValues, $userId, true);
							break;
						//Change previously populated fields
						case 'overwrite': 
							$fields['modified_date'] = now();
							$userId = static::saveUser($fields, $userId);
							static::saveUserCustomData($userCustomFields, $userId);
							static::saveUserMultiCheckboxData($checkBoxValues, $userId);
							static::saveUserFilePickerData($filePickerValues, $userId);
							break;
						//Don’t update any fields
						case 'ignore': 
							break;
					}
				//No duplicate email found
				} elseif (!empty($fields['email']) && validateEmailAddress($fields['email'])) {
					//Set new user fields
					$fields['status'] = $form['user_status'];
					$fields['password'] = createPassword();
					$fields['ip'] = visitorIP();
					if (!empty($fields['screen_name'])) {
						$fields['screen_name_confirmed'] = true;
					}
					
					//Create new user
					$userId = static::saveUser($fields);
					
					//Save new user custom data
					static::saveUserCustomData($userCustomFields, $userId);
					static::saveUserMultiCheckboxData($checkBoxValues, $userId);
					static::saveUserFilePickerData($filePickerValues, $userId);
				}
				if ($userId) {
					addUserToGroup($userId, $form['add_user_to_group']);
					//Log user in
					if ($form['log_user_in']) {
						
						$user = logUserIn($userId);
						
						if($form['log_user_in_cookie'] && canSetCookie()) {
							setCookieOnCookieDomain('LOG_ME_IN_COOKIE', $user['login_hash']);
						}
					}
				}
			}
		}
		
		//Save a record of the submission
		$user_response_id = false;
		if ($form['save_record']) {
			$values = $userSystemFields + $userCustomFields + $unlinkedFields + $filePickerValues + $checkBoxValues;
			$user_response_id = static::createFormResponse($userId, $formId, $values);
		}
		
		//Send emails
		//Profanity check
		$profanityFilterEnabled = setting('zenario_user_forms_set_profanity_filter');
		$profanityToleranceLevel = setting('zenario_user_forms_set_profanity_tolerence');
		
		if ($form['profanity_filter_text']) {
			
			$profanityValuesToCheck = $userSystemFields + $userCustomFields + $unlinkedFields;
			$wordsCount = 0;
			$allValuesFromText = "";
			
			foreach ($profanityValuesToCheck as $text) {
				$wordsCount = $wordsCount + str_word_count($text['value']);
				$isSpace = substr($text['value'], -1);
				
				if ($isSpace != " ") {
					$allValuesFromText .= $text['value'] . " ";
				} else {
					$allValuesFromText .= $text['value'];
				}
			}
			
			$profanityRating = zenario_user_forms::scanTextForProfanities($allValuesFromText);
		}

		if (!$form['profanity_filter_text'] || ($profanityRating < $profanityToleranceLevel)) {
			
			$sendEmailToUser = ($form['send_email_to_logged_in_user'] || $form['send_email_to_email_from_field']);
			$sendEmailToAdmin = ($form['send_email_to_admin'] && $form['admin_email_addresses']);
			$values = array();
			$userEmailMergeFields = 
			$adminEmailMergeFields = false;
			
			if ($sendEmailToUser || $sendEmailToAdmin) {
				$values = $userSystemFields + $userCustomFields + $checkBoxValues + $unlinkedFields;
			}
			
			//Send an email to the user
			if ($sendEmailToUser) {
				//Get merge fields
				$userEmailMergeFields = static::getTemplateEmailMergeFields($values, $userId);
				
				if ($form['send_email_to_logged_in_user']) {
					if ($userId
						&& ($email = getRow('users', 'email', $userId))
					) {
						if ($form['user_email_use_template_for_logged_in_user'] && $form['user_email_template_logged_in_user']) {
							zenario_email_template_manager::sendEmailsUsingTemplate($email, $form['user_email_template_logged_in_user'], $userEmailMergeFields, array());
						} else {
							$startLine = 'Dear user,';
							static::sendUnformattedFormEmail($values, $formFields, $form, $startLine, $email, $attachments);
						}
					}
				} 
				if ($form['send_email_to_email_from_field']) {
					if ($form['user_email_field']
						&& !empty($fieldIdValueLink[$form['user_email_field']])
						&& ($email = $fieldIdValueLink[$form['user_email_field']])
					) {
						if ($form['user_email_use_template_for_email_from_field'] && $form['user_email_template_from_field']) {
							zenario_email_template_manager::sendEmailsUsingTemplate($email, $form['user_email_template_from_field'], $userEmailMergeFields, array());
						} else {
							$startLine = 'Dear user,';
							static::sendUnformattedFormEmail($values, $formFields, $form, $startLine, $email, $attachments);
						}
					}
				}
			}
		
			//Send an email to administrators
			if ($sendEmailToAdmin) {
				
				//Get merge fields
				$adminEmailMergeFields = static::getTemplateEmailMergeFields($values, $userId, true);
				
				//Set reply to address and name
				$replyToEmail = false;
				$replyToName = false;
				if ($form['reply_to'] && $form['reply_to_email_field']) {
					if (!empty($fieldIdValueLink[$form['reply_to_email_field']])) {
						$replyToEmail = $fieldIdValueLink[$form['reply_to_email_field']];
						$replyToName = '';
						if (!empty($fieldIdValueLink[$form['reply_to_first_name']])) {
							$replyToName .= $fieldIdValueLink[$form['reply_to_first_name']];
						}
						if (!empty($fieldIdValueLink[$form['reply_to_last_name']])) {
							$replyToName .= ' '.$fieldIdValueLink[$form['reply_to_last_name']];
						}
						if (!$replyToName) {
							$replyToName = $replyToEmail;
						}
					}
				}
		
				//Send email
				if ($form['admin_email_use_template'] && $form['admin_email_template']) {
					zenario_email_template_manager::sendEmailsUsingTemplate(
						$form['admin_email_addresses'],
						$form['admin_email_template'],
						$adminEmailMergeFields,
						$attachments,
						array(),
						false,
						$replyToEmail,
						$replyToName
					);
				} else {
					$startLine = 'Dear admin,';
					static::sendUnformattedFormEmail($values, $formFields, $form, $startLine, $form['admin_email_addresses'], $attachments, $replyToEmail, $replyToName, true);
				}
			}
		} else {
			//Update if profanity filter was set in responses
			if($profanityFilterEnabled) {
				updateRow(ZENARIO_USER_FORMS_PREFIX. 'user_response', array('blocked_by_profanity_filter' => 1), array('id' => $user_response_id));
			}
		}
	
		//Set default values for this form submission for profanity filter
		if ($form['profanity_filter_text']) {
			updateRow(ZENARIO_USER_FORMS_PREFIX. 'user_response', 
				array(
					'profanity_filter_score' => $profanityRating, 
					'profanity_tolerance_limit' => $profanityToleranceLevel
				), 
				array('id' => $user_response_id)
			);
		}
		
		//Send a signal if specified
		if ($form['send_signal']) {
			$form['user_form_id'] = $formId;
			$values = $userSystemFields + $userCustomFields + $checkBoxValues + $unlinkedFields;
			$formattedData = static::getTemplateEmailMergeFields($values, $userId);
			$params = array(
				'data' => $formattedData, 
				'rawData' => $data, 
				'formProperties' => $form, 
				'fieldIdValueLink' => $fieldIdValueLink);
			if ($user_response_id) {
				$params['responseId'] = $user_response_id;
			}
			sendSignal('eventUserFormSubmitted', $params);
		}
		return $userId;
	}
	
	
	public static function sendUnformattedFormEmail($values, $formFields, $form, $startLine, $recipients, $attachments = array(), $replyToEmail = false, $replyToName = false, $includeAdminDownloadLinks = false) {
		$emailValues = array();
					
		foreach ($values as $fieldId => $fieldData) {
			if (isset($fieldData['attachment']) && $includeAdminDownloadLinks) {
				$fieldData['value'] = absCMSDirURL() . 'zenario/file.php?adminDownload=1&id=' . $fieldData['internal_value'];
			}
			if (!empty($fieldData['type']) && ($fieldData['type'] == 'textarea') && $fieldData['value']) {
				$fieldData['value'] = '<br/>' . nl2br($fieldData['value']);
			}
			
			if (!is_numeric($fieldId) && (strpos($fieldId, '_') !== false)) {
				$ids = explode('_', $fieldId);
				$fieldId = $ids[0];
			}
			$emailValues[$fieldData['ord']] = array($formFields[$fieldId]['name'], $fieldData['value']);
		}
		
		ksort($emailValues);
		
		$formName = trim($form['name']);
		$formName = empty($formName) ? phrase('[blank name]', array(), 'zenario_user_forms') : $form['name'];
		$body =
			'<p>' . $startLine . '</p>
			<p>The form "'.$formName.'" was submitted with the following data:</p>';
		
		
		//Get menu path of current page
		$menuNodeString = '';
		if ($form['send_email_to_admin'] && !$form['admin_email_use_template']) {
			$currentMenuNode = getMenuItemFromContent(cms_core::$cID, cms_core::$cType);
			if ($currentMenuNode && isset($currentMenuNode['mID']) && !empty($currentMenuNode['mID'])) {
				$nodes = static::drawMenu($currentMenuNode['mID'], cms_core::$cID, cms_core::$cType);
				for ($i = count($nodes) - 1; $i >= 0; $i--) {
					$menuNodeString .= $nodes[$i].' ';
					if ($i > 0) {
						$menuNodeString .= '&#187; ';
					}
				}
			}
		}
		if ($menuNodeString) {
			$body .= '<p>Page submitted from: '. $menuNodeString .'</p>';
		}
		
		foreach ($emailValues as $ordinal => $value) {
			$body .= '<p>'.trim($value[0], " \t\n\r\0\x0B:").': '.$value[1].'</p>';
		}
		
		$url = linkToItem(cms_core::$cID, cms_core::$cType, true, '', false, false, true);
		if (!$url) {
			$url = absCMSDirURL();
		}

		$body .= '<p>This is an auto-generated email from '.$url.'</p>';
		$subject = phrase('New form submission for: [[name]]', array('name' => $formName), 'zenario_user_forms');
		$addressFrom = setting('email_address_from');
		$nameFrom = setting('email_name_from');

		zenario_email_template_manager::sendEmails(
			$recipients,
			$subject,
			$addressFrom,
			$nameFrom,
			$body,
			array(),
			$attachments,
			array(),
			0,
			false,
			$replyToEmail,
			$replyToName
		);
	}
	
	
	
	public static function getFormSaveData($fields, $data, $dataset, $instanceId, &$fieldIdValueLink, &$userSystemFields, &$userCustomFields, &$unlinkedFields, &$checkBoxValues, &$filePickerValues, &$attachments) {
		$ord = 0;
		foreach ($fields as $fieldId => $field) {
			//Get save data for non-repeat fields
			$forceUnlinked = (!empty($field['repeatBlockRow']) && $field['repeatBlockRow'] != 1);
			static::getFieldSaveData($fieldId, $fields, ++$ord, $data, $forceUnlinked, $dataset, $instanceId, $fieldIdValueLink, $userSystemFields, $userCustomFields, $unlinkedFields, $checkBoxValues, $filePickerValues, $attachments);
		}
	}
	
	public static function getFormSaveDataGrouped($fields, $data, $dataset, $instanceId) {
		$fieldIdValueLink = $userSystemFields = $userCustomFields = $unlinkedFields = $checkBoxValues = $filePickerValues = $attachments = array();
		static::getFormSaveData($fields, $data, $dataset, $instanceId, $fieldIdValueLink, $userSystemFields, $userCustomFields, $unlinkedFields, $checkBoxValues, $filePickerValues, $attachments);
		$values = $userSystemFields + $userCustomFields + $unlinkedFields + $checkBoxValues + $filePickerValues;
		return $values;
	}
	
	private static function getRepeatBlockRowsId($fieldId) {
		return 'field_' . $fieldId . '_rows';
	}
	
	public static function getRepeatBlockRows($repeatBlockField, $repeatBlockFields, $data, $instanceId) {
		$rows = array();
		$rowsDataId = static::getRepeatBlockRowsId($repeatBlockField['id']);
		
		if (isset($data[$rowsDataId])) {
			$rows = explode(',', $data[$rowsDataId]);
		} elseif (isset($_SESSION['custom_form_data'][$instanceId][$repeatBlockField['id']]['rows'])) {
			$rows = explode(',', $_SESSION['custom_form_data'][$instanceId][$repeatBlockField['id']]['rows']);
		} else {
			$rows = static::getRepeatBlockRowsFromData($repeatBlockField, $repeatBlockFields, $data, $instanceId);
		}
		return $rows;
	}
	
	public static function getRepeatBlockRowsFromData($repeatBlockField, $repeatBlockFields, $data, $instanceId) {
		$rows = array();
		for ($i = 1; $i <= $repeatBlockField['min_rows']; $i++) {
			$rows[] = $i;
		}
		
		if (is_array($data)) {
 			$lastFieldId = end($repeatBlockFields);
 			if (is_array($lastFieldId)) {
 				$lastFieldId = $lastFieldId['id'];
 			}
			if ($lastFieldId) {
				$lastFieldName = static::getFieldName($lastFieldId);
				$ids = preg_grep('/^' .  $lastFieldName . '_(\d+)$/', array_keys($data));
				foreach ($ids as $id) {
					$idParts = explode('_', $id);
					if (isset($idParts[2]) && !in_array($idParts[2], $rows)) {
						$rows[] = $idParts[2];
					}
				}
				
				//Save repeat rows to session after loading it in from a partial save
				$_SESSION['custom_form_data'][$instanceId][$repeatBlockField['id']]['rows'] = implode(',', $rows);
			}
		}
		return $rows;
	}
	
	public static function getFieldSaveData($fieldId, $fields, $ord, $data, $forceUnlinked, $dataset, $instanceId, &$fieldIdValueLink, &$userSystemFields, &$userCustomFields, &$unlinkedFields, &$checkBoxValues, &$filePickerValues, &$attachments) {
		
		$field = $fields[$fieldId];
		$userFieldId = $field['dataset_field_id'];
		$formId = $field['user_form_id'];
		
		$fieldType = static::getFieldType($field);
		$fieldName = static::getFieldColumn($fieldId, $field);
		$fieldValue = static::getFieldCurrentValue($fieldId, $fields, $data, $dataset, $instanceId);
		
		if ($field['is_system_field'] && !$forceUnlinked){
			$valueType = 'system';
			$values = &$userSystemFields;
		} elseif ($userFieldId && !$forceUnlinked) {
			$valueType = 'custom';
			$values = &$userCustomFields;
		} else {
			$valueType = 'unlinked';
			$values = &$unlinkedFields;
		}
		
		//Array to store link between input name and file picker value
		$filePickerValueLink = array();
			
		switch ($fieldType){
			case 'group':
			case 'checkbox':
				if (!empty($fieldValue)) {
					$checked = 1;
					$eng = adminPhrase('Yes');
				} else {
					$checked = 0;
					$eng = adminPhrase('No');
				}
				
				$values[$fieldId] = array('value' => $eng, 'internal_value' => $checked);
				$fieldIdValueLink[$fieldId] = $checked;
				break;
			case 'checkboxes':
				$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $instanceId, $filtered);
				
				$valueList = array();
				if ($fieldValue) {
					$fieldValues = explode(',', $fieldValue);
					foreach ($fieldValues as $v) {
						if (isset($fieldLOV[$v])) {
							$valueList[] = $fieldLOV[$v];
						}
					}
				}
				$value = implode(', ', $valueList);
				$checkBoxValues[$fieldId] = array(
					'internal_value' => $fieldValue, 
					'value' => $value, 
					'ord' => $ord, 
					'db_column' => $fieldName,
					'value_type' => $valueType,
					'user_field_id' => $userFieldId,
					'type' => $fieldType,
					'readonly' => $field['is_readonly']
				);
				
				break;
			case 'date':
				$date = '';
				if ($fieldValue) {
					$date = $fieldValue;
				}
				$values[$fieldId] = array('value' => $date);
				$fieldIdValueLink[$fieldId] = $date;
				break;
			case 'radios':
			case 'select':
			case 'centralised_radios':
			case 'centralised_select':
				$values[$fieldId] = array();
				$fieldIdValueLink[$fieldId] = array();
				if ($userFieldId) {
					$valuesList = getDatasetFieldLOV($userFieldId);
				} else {
					$valuesList = static::getUnlinkedFieldLOV($fieldId);
				}
				
				$values[$fieldId]['internal_value'] = $fieldValue;
				if (isset($valuesList[$fieldValue])) {
					$fieldIdValueLink[$fieldId][$fieldValue] = $valuesList[$fieldValue];
					$values[$fieldId]['value'] = $valuesList[$fieldValue];
				} else {
					$values[$fieldId]['value'] = '';
				}
				break;
			case 'text':
			case 'url':
			case 'calculated':
				$values[$fieldId] = array();
				if ($field['autocomplete']) {
					$fieldLOV = static::getFieldCurrentLOV($fieldId, $fields, $data, $dataset, $instanceId, $filtered);
					$values[$fieldId]['internal_value'] = $fieldValue;
					$fieldValue = isset($fieldLOV[$fieldValue]) ? $fieldLOV[$fieldValue] : false;
				}
				
				$value = ($fieldValue || $fieldValue === '0') ? $fieldValue : '';
				switch ($field['db_column']) {
					case 'salutation':
						$value = static::substr($value, 25);
						break;
					case 'screen_name':
					case 'password':
						$value = static::substr($value,50);
						break;
					case 'first_name':
					case 'last_name':
					case 'email':
						$value = static::substr($value, 100);
						break;
					default:
						$value = static::substr($value, 'varchar');
						break;
				}
				$values[$fieldId]['value'] = $value;
				$fieldIdValueLink[$fieldId] = $value;
				break;
			case 'textarea':
				$value = ($fieldValue || $fieldValue === '0') ? $fieldValue : '';
				$value = static::substr($value, 'text');
				$values[$fieldId] = array('value' => $value);
				$fieldIdValueLink[$fieldId] = $fieldValue;
				break;
			
			case 'attachment':
				$fileId = false;
				$filename = 'Unknown file';
				
				if (is_numeric($fieldValue) && static::checkAttachmentFieldFileIsValid(userId(), $formId, $fieldId, $fieldValue)) {
					$fileId = $fieldValue;
					$filename = getRow('files', 'filename', $fileId);
				} elseif (!empty($fieldValue) && file_exists(CMS_ROOT . $fieldValue)) {
					$filename = substr(basename($fieldValue), 0, -7);
					$fileId = addFileToDatabase('forms', CMS_ROOT . $fieldValue, $filename);
				}
				
				if ($fileId) {
					$values[$fieldId] = array(
						'value' => $filename, 
						'internal_value' => $fileId, 
						'attachment' => true
					);
					if (setting('zenario_user_forms_admin_email_attachments')) {
						$attachments[] = fileLink($fileId);
					}
				}
				$fieldIdValueLink[$fieldId] = $fileId;
				break;
			
			case 'file_picker':
				//TODO improve this, validation to validation function
				$labelValues = array();
				$internalValues = array();
				if ($fieldValue && static::checkFilePickerFieldFilesAreValid(userId(), $formId, $fieldId, $fieldValue)) {
					$fileIds = str_getcsv((string)$fieldValue, ',', '"', '//');
					foreach ($fileIds as $fileId) {
						if (is_numeric($fileId)) {
							$filename = getRow('files', 'filename', $fileId);
							$labelValues[] = $filename;
							$internalValues[] = $fileId;
						} elseif (file_exists(CMS_ROOT . $fileId)) {
							//Validate file size (10MB)
							if (filesize(CMS_ROOT . $fileId) > (1024 * 1024 * 10)) {
								continue;
							}
							
							$filename = substr(basename($fileId), 0, -7);
							
							//Validate file extension
							if (trim($field['extensions'])) {
								$type = explode('.', $filename);
								$type = $type[count($type) - 1];
								$extensions = explode(',', $field['extensions']);
								foreach ($extensions as $index => $extension) {
									$extensions[$index] = trim(str_replace('.', '', $extension));
								}
								if (!in_array($type, $extensions)) {
									continue;
								}
							}
							if (!checkDocumentTypeIsAllowed($filename)) {
								continue;
							}
							
							//Just store file as a response attachment if not saving data, otherwise save as user file
							$usage = 'forms';
							$form = static::getForm($formId);
							if ($form['save_data']) {
								$usage = 'dataset_file';
							}
							
							$postKey = arrayKey($filePickerValueLink, $fileId);
							
							$fileId = addFileToDatabase($usage, CMS_ROOT . $fileId, $filename);
							
							//Change post value to file ID from cache path now file is uploaded
							if ($postKey) {
								$data[$postKey] = $fileId;
							}
							
							if ($fileId) {
								if (setting('zenario_user_forms_admin_email_attachments')) {
									$attachments[] = fileLink($fileId);
								}
								$labelValues[] = $filename;
								$internalValues[] = $fileId;
							}
						}
					}
				}
				
				$filePickerValues[$fieldId] = array(
					'value' => implode(',', $labelValues), 
					'internal_value' => implode(',', $internalValues), 
					'db_column' => $fieldName, 
					'user_field_id' => $userFieldId, 
					'ord' => $ord
				);
				break;
		}
			
		if (isset($values[$fieldId])) {
			$values[$fieldId]['type'] = $fieldType;
			$values[$fieldId]['readonly'] = $field['is_readonly'];
			$values[$fieldId]['db_column'] = $fieldName;
			$values[$fieldId]['ord'] = $ord;
		}
	}
	
	public static function substr($string, $length) {
		if ($length == 'varchar') {
			$length = 255;
		} elseif ($length == 'text') {
			$length = 65535;
		}
		return mb_substr($string, 0, $length, 'UTF-8');
	}
	
	//Save a forms field data into a session variable when a form has multiple pages
	public static function tempSaveFormFields($fields, $allFields, $data, $dataset, $instanceId) {
		foreach ($fields as $fieldId => $field) {
			$type = static::getFieldType($field);
			if ($type != 'page_break' && $type != 'section_description' && $type != 'page_end') {
				static::tempSaveFormField($fieldId, $allFields, $data, $dataset, $instanceId);
			}
		}
	}
	
	public static function tempSaveFormField($fieldId, $fields, $data, $dataset, $instanceId) {
		$fieldType = static::getFieldType($fields[$fieldId]);
		$fieldValue = static::getFieldCurrentValue($fieldId, $fields, $data, $dataset, $instanceId, $ignoreSession = true);
		if (!isset($_SESSION['custom_form_data'])) {
			$_SESSION['custom_form_data'] = array();
			if (!isset($_SESSION['custom_form_data'][$instanceId])) {
				$_SESSION['custom_form_data'][$instanceId] = array();
			}
		}
		
		if ($fieldType == 'repeat_start') {
			$rowsDataId = static::getRepeatBlockRowsId($fieldId);
			if (isset($data[$rowsDataId])) {
				$_SESSION['custom_form_data'][$instanceId][$fieldId] = array('rows' => $data[$rowsDataId]);
			}
		} else {
			$_SESSION['custom_form_data'][$instanceId][$fieldId] = $fieldValue;
		}
	}
	
	//Create a user response to a form
	public static function createFormResponse($userId, $formId, $data) {
		$responseId = insertRow(
			ZENARIO_USER_FORMS_PREFIX. 'user_response', 
			array('user_id' => $userId, 'form_id' => $formId, 'response_datetime' => now())
		);
		
		foreach ($data as $fieldId => $field) {
			$value = '';
			if (isset($field['value'])) {
				$value = $field['value'];
			}
			$row = 0;
			//Data from repeating fields have fields in the format 21_3
			if (!is_numeric($fieldId) && (strpos($fieldId, '_') !== false)) {
				$ids = explode('_', $fieldId);
				$fieldId = $ids[0];
				$row = $ids[1];
			}
			$responseData = array('user_response_id' => $responseId, 'form_field_id' => $fieldId, 'field_row' => $row, 'value' => $value);
			if (isset($field['internal_value'])) {
				$responseData['internal_value'] = $field['internal_value'];
			}
			insertRow(ZENARIO_USER_FORMS_PREFIX. 'user_response_data', $responseData);
		}
		
		sendSignal('eventFormResponseCreated', array($responseId));
		
		return $responseId;
	}
	
	
	protected static function createFormPartialResponse($userId, $formId, $fields, $data, $values, $instanceId) {
		static::deleteOldPartialResponse($formId, $userId);
		
		$maxPageReached = isset($_SESSION['custom_form_data'][$instanceId]['max_page_reached']) ? (int)$_SESSION['custom_form_data'][$instanceId]['max_page_reached'] : 1;
		$responseId = setRow(
			ZENARIO_USER_FORMS_PREFIX . 'user_partial_response',
			array('user_id' => $userId, 'form_id' => $formId, 'response_datetime' => date('Y-m-d H:i:s'), 'max_page_reached' => $maxPageReached)
		);
		
		foreach ($fields as $fieldId => $field) {
			$fieldType = static::getFieldType($field);
			
			if ($fieldType == 'page_break' || $fieldType == 'section_description' || $fieldType == 'repeat_start' || $fieldType == 'repeat_end') {
				continue;
			} else {
				static::createFieldPartialResponse($responseId, $fieldId, $values);
			}
		}
	}
	
	protected static function createFieldPartialResponse($responseId, $fieldId, $values) {
		$value = null;
		if (isset($values[$fieldId]['internal_value'])) {
			$value = $values[$fieldId]['internal_value'];
		} elseif (isset($values[$fieldId]['value'])) {
			$value = $values[$fieldId]['value'];
		}
		$row = 0;
		//Data from repeating fields have fields in the format 21_3
		if (!is_numeric($fieldId) && (strpos($fieldId, '_') !== false)) {
			$ids = explode('_', $fieldId);
			$fieldId = $ids[0];
			$row = $ids[1];
		}
		if ($value !== null) {
			insertRow(ZENARIO_USER_FORMS_PREFIX. 'user_partial_response_data', array('user_partial_response_id' => $responseId, 'form_field_id' => $fieldId, 'field_row' => $row, 'value' => $value));
		}
	}
	
	public static function deleteOldPartialResponse($formId = false, $userId = false) {
		if ($formId || $userId) {
			$keys = array();
			if ($formId) {
				$keys['form_id'] = $formId;
			}
			if ($userId) {
				$keys['user_id'] = $userId;
			}
			$result = getRows(ZENARIO_USER_FORMS_PREFIX . 'user_partial_response', array('id'), $keys);
			while ($response = sqlFetchAssoc($result)) {
				deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_partial_response', array('id' => $response['id']));
				deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_partial_response_data', array('user_partial_response_id' => $response['id']));
			}
		} else {
			deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_partial_response', array());
			deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_partial_response_data', array());
		}
	}
	
	
	protected static function mergeUserData($fields, $userId, $login) {
		$userDetails = getUserDetails($userId);
		$mergeFields = array();
		foreach ($fields as $fieldName => $value) {
			if (isset($userDetails[$fieldName]) && empty($userDetails[$fieldName])) {
				$mergeFields[$fieldName] = $value;
			}
		}
		if ($login) {
			$mergeFields['status'] = 'active';
		}
		saveUser($mergeFields, $userId);
	}
	
	
	protected static function saveUserCustomData($userCustomFields, $userId, $merge = false) {
		$userDetails = getUserDetails($userId);
		$mergeFields = array();
		//Don't save readonly fields or, if merging, only if no previous field data exists
		foreach ($userCustomFields as $fieldId => $fieldData) {
			if (empty($fieldData['readonly']) && (!$merge || (isset($userDetails[$fieldData['db_column']]) && empty($userDetails[$fieldData['db_column']])))) {
				$mergeFields[$fieldData['db_column']] = ((isset($fieldData['internal_value'])) ? $fieldData['internal_value'] : $fieldData['value']);
			}
		}
		if (!empty($mergeFields)) {
			setRow('users_custom_data', $mergeFields, array('user_id' => $userId));
		}
	}
	
	
	protected static function saveUserMultiCheckboxData($checkBoxValues, $userId, $merge = false) {
		$dataset = getDatasetDetails('users');
		foreach ($checkBoxValues as $fieldId => $fieldData) {
			if (empty($fieldData['readonly']) && $fieldData['value_type'] != 'unlinked') {
				
				$valuesList = getDatasetFieldLOV($fieldData['user_field_id']);
				$canSave = false;
				
				if ($merge) {
					$valueFound = false;
					//Find if this field has been previously completed
					foreach ($valuesList as $id => $label) {
						if (checkRowExists('custom_dataset_values_link', array('dataset_id' => $dataset['id'], 'value_id' => $id, 'linking_id' => $userId))) {
							$valueFound = true;
							break;
						}
					}
					//If no values found, save data
					if (!$valueFound && $fieldData['internal_value']) {
						$canSave = true;
					}
				} else {
					//Delete current saved values
					foreach ($valuesList as $id => $label) {
						deleteRow('custom_dataset_values_link', array('dataset_id' => $dataset['id'], 'value_id' => $id, 'linking_id' => $userId));
					}
					//Save new values
					if ($fieldData['internal_value']) {
						$canSave = true;
					}
				}
				
				if ($canSave) {
					$valuesList = explode(',', $fieldData['internal_value']);
					foreach ($valuesList as $value) {
						insertRow('custom_dataset_values_link', array('dataset_id' => $dataset['id'], 'value_id' => $value, 'linking_id' => $userId));
					}
				}
			}
		}
	}
	
	
	protected static function saveUserFilePickerData($filePickerValues, $userId, $merge = false) {
		$dataset = getDatasetDetails('users');
		foreach ($filePickerValues as $fieldId => $fieldData) {
			if (empty($fieldData['readonly'])) {
				$fileExists = checkRowExists(
					'custom_dataset_files_link', 
					array(
						'dataset_id' => $dataset['id'], 
						'field_id' => $fieldData['user_field_id'], 
						'linking_id' => $userId
					)
				);
				if (!$merge || ($merge && !$fileExists)) {
					
					//Remove other files stored against this field for this user
					deleteRow(
						'custom_dataset_files_link', 
						array(
							'dataset_id' => $dataset['id'], 
							'field_id' => $fieldData['user_field_id'], 
							'linking_id' => $userId
						)
					);
					
					$fileIds = explode(',', $fieldData['internal_value']);
					
					foreach ($fileIds as $fileId) {
						//Add the new file
						setRow(
							'custom_dataset_files_link', 
							array(), 
							array(
								'dataset_id' => $dataset['id'], 
								'field_id' => $fieldData['user_field_id'], 
								'linking_id' => $userId, 
								'file_id' => $fileId)
						);
					}
				}
			}
		}
	}
	
	
	protected static function getTemplateEmailMergeFields($values, $userId, $sendToAdmin = false) {
		$emailMergeFields = array();
		foreach($values as $fieldId => $fieldData) {
			if (isset($fieldData['attachment'])) {
				if ($sendToAdmin) {
					$fieldData['value'] = absCMSDirURL() . 'zenario/file.php?adminDownload=1&id=' . $fieldData['internal_value'];
				} else {
					$fieldData['value'] = absCMSDirURL() . fileLink($fieldData['internal_value']);
				}
			}
			if (!empty($fieldData['type']) && ($fieldData['type'] == 'textarea') && $fieldData['value']) {
				$fieldData['value'] = $fieldData['value'];
			}
			$emailMergeFields[$fieldData['db_column']] = $fieldData['value'];
		}
		
		if ($userId) {
			$user = getRow('users', array('salutation', 'first_name', 'last_name'), $userId);
			$emailMergeFields['salutation'] = $user['salutation'];
			$emailMergeFields['first_name'] = $user['first_name'];
			$emailMergeFields['last_name'] = $user['last_name'];
			if (setting('plaintext_extranet_user_passwords')) {
				$userDetails = getUserDetails($userId);
				$emailMergeFields['password'] = $userDetails['password'];
			}
			$emailMergeFields['user_id'] = $userId;
		}
		
		$emailMergeFields['cms_url'] = absCMSDirURL();
		return $emailMergeFields;
	}
	
	
	public static function drawMenu($nodeId, $cID, $cType) {
		$nodes = array();
		do {
			$text = getRow('menu_text', 'name', array('menu_id' => $nodeId, 'language_id' => setting('default_language')));
			$nodes[] = $text;
			$nodeId = getMenuParent($nodeId);
		} while ($nodeId != 0);
		$homeCID = $homeCType = false;
		langSpecialPage('zenario_home', $homeCID, $homeCType);
		if (!($cID == $homeCID && $cType == $homeCType)) {
			$equivId = equivId($homeCID, $homeCType);
			$sectionId = menuSectionId('Main');
			$menuId = getRow('menu_nodes', 'id', array('section_id' => $sectionId, 'equiv_id' => $equivId, 'content_type' => $homeCType));
			$nodes[] = getRow('menu_text', 'name', array('menu_id' => $menuId, 'language_id' => setting('default_language')));
		}
		return $nodes;
	}
	
	
	public static function getFieldColumn($fieldId, $field) {
		return ($field['db_column'] ? $field['db_column'] : 'unlinked_'. $field['field_type'].'_'.$fieldId);
	}
	
	
	protected static function saveUser($fields, $userId = false) {
		$newId = saveUser($fields, $userId);
		
		if ($userId) {
			sendSignal(
				'eventUserModified',
				array('id' => $userId));
		} else {
			sendSignal(
				'eventUserCreated',
				array('id' => $newId));
		}
		return $newId;
	}
	
	
	public function preFillOrganizerPanel($path, &$panel, $refinerName, $refinerId, $mode) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->preFillOrganizerPanel($path, $panel, $refinerName, $refinerId, $mode);
		}
	}
	
	public function fillOrganizerPanel($path, &$panel, $refinerName, $refinerId, $mode) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->fillOrganizerPanel($path, $panel, $refinerName, $refinerId, $mode);
		}
	}
	
	public function handleOrganizerPanelAJAX($path, $ids, $ids2, $refinerName, $refinerId) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->handleOrganizerPanelAJAX($path, $ids, $ids2, $refinerName, $refinerId);
		}
	}
	
	public static function deleteFormResponse($responseId) {
		sendSignal('eventFormResponseDeleted', array($responseId));
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_response_data', array('user_response_id' => $responseId));
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_response', $responseId);
	}
	
	public function organizerPanelDownload($path, $ids, $refinerName, $refinerId) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->organizerPanelDownload($path, $ids, $refinerName, $refinerId);
		}
	}
	
	public static function getFormJSON($formId) {
		$formJSON = array();
		
		$formJSON = getRow(ZENARIO_USER_FORMS_PREFIX . 'user_forms', true, $formId);
		$formJSON['_fields'] = array();
		$fieldsResult = getRows(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', true, array('user_form_id' => $formId));
		while ($field = sqlFetchAssoc($fieldsResult)) {
			$formJSON['_fields'][$field['id']] = $field;
			$formJSON['_fields'][$field['id']]['_values'] = array();
			
			$valuesResult = getRows(ZENARIO_USER_FORMS_PREFIX . 'form_field_values', true, array('form_field_id' => $field['id']));
			while ($value = sqlFetchAssoc($valuesResult)) {
				$formJSON['_fields'][$field['id']]['_values'][$value['id']] = $value;
			}
			
			$formJSON['_fields'][$field['id']]['_updates'] = array();
			$updatesResult = getRows(ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', true, array('target_field_id' => $field['id']));
			while ($update = sqlFetchAssoc($updatesResult)) {
				$formJSON['_fields'][$field['id']]['_updates'][] = $update;
			}
		}
		
		return $formJSON;
	}
	
	public function fillAdminBox($path, $settingGroup, &$box, &$fields, &$values) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->fillAdminBox($path, $settingGroup, $box, $fields, $values);
		}
	}

	public function formatAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->formatAdminBox($path, $settingGroup, $box, $fields, $values, $changes);
		}
	}
	
	public function validateAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes, $saving) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->validateAdminBox($path, $settingGroup, $box, $fields, $values, $changes, $saving);
		}
	}
	
	public function saveAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes) {
		if ($c = $this->runSubClass(__FILE__)) {
			return $c->saveAdminBox($path, $settingGroup, $box, $fields, $values, $changes);
		}
	}
	
	public static function validateFormsImport($formJSON) {
		return static::importForms($formJSON, true);
	}
	
	//Import forms
	public static function importForms($formJSON, $onlyValidate = false) {
		$formJSON = json_decode($formJSON, true);
		//Validate import file
		if ($formJSON === null) {
			return new zenario_error(adminPhrase('Invalid JSON object parsed.'));
		} elseif (!isset($formJSON['major_version']) || !isset($formJSON['minor_version']) || !isset($formJSON['forms'])) {
			return new zenario_error(adminPhrase('Invalid forms import file.'));
		} elseif ($formJSON['major_version'] != ZENARIO_MAJOR_VERSION || $formJSON['minor_version'] != ZENARIO_MINOR_VERSION) {
			return new zenario_error(
				adminPhrase(
					'Forms to import are from CMS version [[form_major_version]].[[form_minor_version]]. Your current CMS version is [[cms_major_version]].[[cms_minor_version]]. Please make sure the forms are from the same software version as this site.', 
					array(
						'form_major_version' => $formJSON['major_version'], 
						'form_minor_version' => $formJSON['minor_version'], 
						'cms_major_version' => ZENARIO_MAJOR_VERSION, 
						'cms_minor_version' => ZENARIO_MINOR_VERSION
					)
				)
			);
		}
		
		$formsImported = 0;
		$firstImportedFormId = false;
		
		//Begin import
		$results = array();
		foreach ($formJSON['forms'] as $form) {
			unset($result);
			$results[] = &$result;
			$result = array('name' => $form['name'], 'errors' => array(), 'warnings' => array(), 'fields' => array());
			//Check form name is unique
			$formNameExists = checkRowExists(ZENARIO_USER_FORMS_PREFIX . 'user_forms', array('name' => $form['name']));
			if ($formNameExists) {
				$result['errors'][] = adminPhrase('A form called "[[name]]" already exists', array('name' => $form['name']));
				continue;
			}
			
			$formId = $form['id'];
			$fields = $form['_fields'];
			unset($form['id']);
			unset($form['_fields']);
			
			//Remove site specific data
			if ($form['user_email_template_logged_in_user']) {
				if (!is_numeric($form['user_email_template_logged_in_user']) 
					&& checkRowExists('email_templates', array('code' => $form['user_email_template_logged_in_user']))
				) {
					$result['warnings'][] = adminPhrase('A user email template is set for logged in users. One with the same name was found on this site. The contents of these email templates is not guaranteed to be identical.');
				} else {
					$result['warnings'][] = adminPhrase('A user email template is set for logged in users. This value will be unset.');
					$form['user_email_template_logged_in_user'] = null;
				}
			}
			if ($form['user_email_template_from_field']) {
				if (!is_numeric($form['user_email_template_from_field']) 
					&& checkRowExists('email_templates', array('code' => $form['user_email_template_from_field']))
				) {
					$result['warnings'][] = adminPhrase('A user email template is set from an email field. One with the same name was found on this site. The contents of these email templates is not guaranteed to be identical.');
				} else {
					$result['warnings'][] = adminPhrase('A user email template is set from an email field. This value will be unset.');
					$form['user_email_template_from_field'] = null;
				}
			}
			
			if ($form['admin_email_template']) {
				if (!is_numeric($form['admin_email_template']) 
					&& checkRowExists('email_templates', array('code' => $form['admin_email_template']))
				) {
					$result['warnings'][] = adminPhrase('An admin email template is set. One with the same name was found on this site. The contents of these email templates is not guaranteed to be identical.');
				} else {
					$result['warnings'][] = adminPhrase('An admin email template is set. This value will be unset.');
					$form['admin_email_template'] = null;
				}
			}
			
			if ($form['redirect_location']) {
				if ($form['redirect_after_submission']) {
					$result['warnings'][] = adminPhrase('This form redirects to a content item. This value will be unset.');
				}
				$form['redirect_location'] = null;
			}
			
			if ($form['reply_to_email_field']) {
				if ($form['reply_to']) {
					$result['warnings'][] = adminPhrase('Admin email reply-to email field set. This value will be unset.');
				}
				$form['reply_to_email_field'] = null;
			}
			
			if ($form['reply_to_first_name']) {
				if ($form['reply_to']) {
					$result['warnings'][] = adminPhrase('Admin email reply-to first name field set. This value will be unset.');
				}
				$form['reply_to_first_name'] = null;
			}
			
			if ($form['reply_to_last_name']) {
				if ($form['reply_to']) {
					$result['warnings'][] = adminPhrase('Admin email reply-to last name field set. This value will be unset.');
				}
				$form['reply_to_last_name'] = null;
			}
			
			if ($form['add_user_to_group']) {
				$result['warnings'][] = adminPhrase('This form adds users to groups. This value will be unset.');
				$form['add_user_to_group'] = null;
			}
			
			//Create form
			$newFormId = 't';
			if (!$onlyValidate) {
				$newFormId = insertRow(ZENARIO_USER_FORMS_PREFIX . 'user_forms', $form);
				$firstImportedFormId = $newFormId;
				++$formsImported;
			}
			
			$fieldIdLink = array();
			$fieldValueIdLink = array();
			foreach ($fields as $oldFieldId => $field) {
				unset($fieldResult);
				$result['fields'][$oldFieldId] = &$fieldResult;
				$fieldResult = array('name' => $field['name'], 'warnings' => array());
				
				if ($field['user_field_id']) {
					$fieldResult['warnings'][] = adminPhrase('This field is linked to a dataset field and will not be imported.');
					continue;
				}
				if ($field['default_value_class_name'] && $field['default_value_method_name']) {
					if (!inc('default_value_class_name')) {
						$fieldResult['warnings'][] = adminPhrase('The module used to get this fields default value is not running. This value will be unset.');
						unset($field['default_value_class_name']);
						unset($field['default_value_method_name']);
					}
				}
				if ($field['autocomplete_class_name'] && $field['autocomplete_method_name']) {
					if (!inc('autocomplete_class_name')) {
						$fieldResult['warnings'][] = adminPhrase('The module used to get this fields autocomplete list is not running. This value will be unset.');
						unset($field['autocomplete_class_name']);
						unset($field['autocomplete_method_name']);
					}
				}
				if ($field['values_source']) {
					$moduleClassName = false;
					$methodName = false;
					$parts = explode('::', $field['values_source']);
					if (isset($parts[0])) {
						$moduleClassName = $parts[0];
						if (isset($parts[1])) {
							$methodName = $parts[1];
						}
					}
					if (!checkRowExists('centralised_lists', array('module_class_name' => $moduleClassName, 'method_name' => $methodName))) {
						$fieldResult['warnings'][] = adminPhrase('This fields values source was not found. This value will be unset.');
					}
				}
				
				
				$values = $field['_values'];
				$updates = $field['_updates'];
				unset($field['id']);
				unset($field['_values']);
				unset($field['_updates']);
				
				$field['user_form_id'] = $newFormId;
				
				$newFieldId = $oldFieldId;
				if (!$onlyValidate) {
					$newFieldId = insertRow(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', $field);
					foreach ($values as $oldValueId => $value) {
						unset($value['id']);
						$value['form_field_id'] = $newFieldId;
						$valueId = insertRow(ZENARIO_USER_FORMS_PREFIX . 'form_field_values', $value);
						$fieldValueIdLink[$oldValueId] = $valueId;
					}
				}
				
				$field['id'] = $newFieldId;
				$field['_updates'] = $updates;
				
				$fieldIdLink[$oldFieldId] = $field;
			}
			
			$idFields = array(
				'restatement_field' => array('warning' => 'This mirror field\'s target field will not be imported.'),
				'calculation_code' => array('warning' => 'This calculation field\'s equation could not be imported.'),
				'visible_condition_field_id' => array('warning' => 'This field\'s visible condition field will not be imported.'),
				'mandatory_condition_field_id' => array('warning' => 'This field\'s mandatory condition field will not be imported.')
			);
			
			//Update saved Ids
			foreach ($fieldIdLink as $oldFieldId => $field) {
				$values = array();
				unset($fieldResult);
				$fieldResult = &$result['fields'][$oldFieldId];
				
				//Look for field Ids stored against the field and update/warn where appropriate
				foreach ($idFields as $name => $info) {
					if (!empty($field[$name])) {
						if ($name == 'calculation_code') {
							$calculationCode = json_decode($field[$name], true);
							foreach ($calculationCode as &$step) {
								if ($step['type'] == 'field' && $step['value']) {
									if (isset($fieldIdLink[$step['value']])) {
										$step['value'] = $fieldIdLink[$step['value']]['id'];
									} else {
										$values[$name] = '';
										$fieldResult['warnings'][] = adminPhrase($info['warning']);
										break;
									}
								}
							}
							unset($step);
							$values[$name] = json_encode($calculationCode);
						} else {
							if (isset($fieldIdLink[$field[$name]])) {
								$values[$name] = $fieldIdLink[$field[$name]]['id'];
							} else {
								$values[$name] = 0;
								$fieldResult['warnings'][] = adminPhrase($info['warning']);
							}
						}
					}
				}
				
				//Update field value Ids stored next to fields to new value Ids
				if ($field['visible_condition_field_id'] 
					&& $fieldIdLink[$field['visible_condition_field_id']] 
					&& in_array($fieldIdLink[$field['visible_condition_field_id']]['field_type'], array('checkboxes', 'select', 'radios'))
					&& isset($fieldValueIdLink[$field['visible_condition_field_value']])
				) {
					$values['visible_condition_field_value'] = $fieldValueIdLink[$field['visible_condition_field_value']];
				}
				if ($field['mandatory_condition_field_id'] 
					&& $fieldIdLink[$field['mandatory_condition_field_id']] 
					&& in_array($fieldIdLink[$field['mandatory_condition_field_id']]['field_type'], array('checkboxes', 'select', 'radios'))
					&& isset($fieldValueIdLink[$field['visible_condition_field_value']])
				) {
					$values['mandatory_condition_field_value'] = $fieldValueIdLink[$field['mandatory_condition_field_value']];
				}
				
				
				if ($values && !$onlyValidate) {
					updateRow(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', $values, $field['id']);
				}
				
				//Update field filter ids
				if (!empty($field['_updates'])) {
					foreach ($field['_updates'] as $updates) {
						if (isset($fieldIdLink[$updates['source_field_id']])) {
							if (!$onlyValidate) {
								insertRow(
									ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', 
									array(
										'source_field_id' => $fieldIdLink[$updates['source_field_id']]['id'], 
										'target_field_id' => $field['id']
									)
								);
							}
						} else {
							$fieldResult['warnings'][] = adminPhrase('The filter for this field will not be imported.');
						}
					}
				}
			}
		}
		
		if (!$onlyValidate && $formsImported == 1 && $firstImportedFormId) {
			return $firstImportedFormId;
		}
		return $results;
	}
	
	//Delete a form
	public static function deleteForm($formId) {
		$error = new zenario_error();
		
		//Get form details
		$formDetails = getRow(ZENARIO_USER_FORMS_PREFIX . 'user_forms', array('name'), $formId);
		if ($formDetails === false) {
			$error->add(adminPhrase('Error. Form with ID "[[id]]" does not exist.', array('id' => $formId)));
			return $error;
		}
		
		//Don't delete forms used in plugins
		$moduleIds = static::getFormModuleIds();
		$instanceIds = array();
		$sql = '
			SELECT id
			FROM '.DB_NAME_PREFIX.'plugin_instances
			WHERE module_id IN ('. inEscape($moduleIds, 'numeric'). ')
			ORDER BY name';
		$result = sqlSelect($sql);
		while ($row = sqlFetchAssoc($result)) {
			$instanceIds[] = $row['id'];
		}
		$sql = "
            SELECT pi.id, pi.name, np.id AS egg_id
            FROM ". DB_NAME_PREFIX. "nested_plugins AS np
            INNER JOIN ". DB_NAME_PREFIX. "plugin_instances AS pi
               ON pi.id = np.instance_id
            WHERE np.module_id IN (". inEscape($moduleIds, 'numeric'). ")
            ORDER BY pi.name";
        $result = sqlSelect($sql);
        while ($row = sqlFetchAssoc($result)) {
            $instanceIds[] = $row['id'];
        }
		
		foreach ($instanceIds as $instanceId) {
			if (checkRowExists('plugin_settings', array('instance_id' => $instanceId, 'name' => 'user_form', 'value' => $formId))) {
				$error->add(adminPhrase('Error. Unable to delete form "[[name]]" as it is used in a plugin.', $formDetails));
				return $error;
			}
		}
		
		//Don't delete forms with logged responses
		if (checkRowExists(ZENARIO_USER_FORMS_PREFIX.'user_response', array('form_id' => $formId))) {
			$error->add(adminPhrase('Error. Unable to delete form "[[name]]" as it has logged user responses.', $formDetails));
			return $error;
		}
		
		//Send signal that the form is now deleted (sent before actual delete in case modules need to look at any metadata or form fields)
		sendSignal('eventFormDeleted', array($formId));
		
		//Delete form
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_forms', $formId);
		
		//Delete form fields
		$result = getRows(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', array('id'), array('user_form_id' => $formId));
		while ($row = sqlFetchAssoc($result)) {
			static::deleteFormField($row['id'], false, false);
		}
		
		//Delete responses
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_response', array('form_id' => $formId));
		
		return true;
	}
	
	//Delete a form field
	public static function deleteFormField($fieldId, $updateOrdinals = true, $formExists = true) {
		$error = new zenario_error();
		$formFields = static::getFields(false, $fieldId);
		$formField = arrayKey($formFields, $fieldId);
		
		if ($formExists) {
			//Get form field details
			if (empty($formField)) {
				$error->add(adminPhrase('Error. Form field with ID "[[id]]" does not exist.', array('id' => $fieldId)));
				return $error;
			}
			
			//Don't delete form fields used by other fields
			$sql = '
				SELECT id, name, calculation_code, restatement_field, field_type
				FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_form_fields
				WHERE user_form_id = ' . (int)$formField['user_form_id'] . '
				AND (restatement_field = ' . (int)$fieldId . '
					OR field_type = "calculated"
				)';
			$result = sqlSelect($sql);
			while ($row = sqlFetchAssoc($result)) {
				if ($row['restatement_field'] == $fieldId) {
					$row = sqlFetchAssoc($result);
					$formField['name2'] = $row['name'];
					$error->add(adminPhrase('Unable to delete the field "[[name]]" as the field "[[name2]]" depends on it.', $formField));
					return $error;
				} elseif ($row['field_type'] == 'calculated' && $row['calculation_code']) {
					$calculationCode = json_decode($row['calculation_code'], true);
					foreach ($calculationCode as $step) {
						if ($step['type'] == 'field' && $step['value'] == $fieldId) {
							$formField['name2'] = $row['name'];
							$error->add(adminPhrase('Unable to delete the field "[[name]]" as the field "[[name2]]" depends on it.', $formField));
							return $error;
						}
					}
				}
			}
		}
		
		//Send signal that the form field is now deleted (sent before actual delete in case modules need to look at any metadata or field values)
		sendSignal('eventFormFieldDeleted', array($fieldId));
		
		//Delete form field
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', $fieldId);
		
		//Update remaining field ordinals
		if ($updateOrdinals && !empty($formField)) {
			$result = getRows(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', array('id'), array('user_form_id' => $formField['user_form_id']), 'ord');
			$ord = 0;
			while ($row = sqlFetchAssoc($result)) {
				updateRow(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', array('ord' => ++$ord), $row['id']);
			}
		}
		
		//Delete any field values
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'form_field_values', array('form_field_id' => $fieldId));
		
		//Delete any update links
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', array('target_field_id' => $fieldId));
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'form_field_update_link', array('source_field_id' => $fieldId));
		
		//Delete any files saved as a response if not used elsewhere
		$type = static::getFieldType($formField);
		if ($type == 'attachment' || $type == 'file_picker') {
			$responses = getRows(ZENARIO_USER_FORMS_PREFIX . 'user_response_data', array('internal_value'), array('form_field_id' => $fieldId));
			while ($response = sqlFetchAssoc($responses)) {
				if (!empty($response['internal_value'])) {
					$sql = '
						SELECT urd.form_field_id
						FROM ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_response_data urd
						INNER JOIN ' . DB_NAME_PREFIX . ZENARIO_USER_FORMS_PREFIX . 'user_form_fields uff
							ON urd.form_field_id = uff.id
						LEFT JOIN ' . DB_NAME_PREFIX . 'custom_dataset_fields cdf
							ON uff.user_field_id = cdf.id
						WHERE (uff.field_type = "attachment" OR cdf.type = "file_picker")
						AND urd.form_field_id != ' . (int)$fieldId . '
						AND urd.internal_value = ' . (int)$response['internal_value'];
					$otherFieldResponsesWithSameFile = sqlSelect($sql);
					if (sqlNumRows($otherFieldResponsesWithSameFile) <= 0) {
						deleteFile($response['internal_value']);
					}
				}
			}
		}
		
		//Delete any response data
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'user_response_data', array('form_field_id' => $fieldId));
		return true;
	}
	
	public static function deleteFormFieldValue($valueId) {
		deleteRow(ZENARIO_USER_FORMS_PREFIX . 'form_field_values', array('id' => $valueId));
		sendSignal('eventFormFieldValueDeleted', array($valueId));
	}
	
	public static function isDatasetFieldOnForm($formId, $datasetField) {
		$datasetFieldId = $datasetField;
		if (!is_numeric($datasetField)) {
			$dataset = getDatasetDetails('users');
			$datasetField = getDatasetFieldDetails($datasetField, $dataset);
			$datasetFieldId = $datasetField['id'];
		}
		return getRow(ZENARIO_USER_FORMS_PREFIX . 'user_form_fields', 'id', array('user_form_id' => $formId, 'user_field_id' => $datasetFieldId));
	}
	
	public static function getFormModuleIds() {
		$ids = array();
		$formModuleClassNames = array('zenario_user_forms', 'zenario_extranet_profile_edit');
		foreach($formModuleClassNames as $moduleClassName) {
			if ($id = getModuleIdByClassName($moduleClassName)) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
	
	public static function getModuleClassNameByInstanceId($id) {
		$sql = '
			SELECT class_name
			FROM '.DB_NAME_PREFIX.'modules m
			INNER JOIN '.DB_NAME_PREFIX.'plugin_instances pi
				ON m.id = pi.module_id
			WHERE pi.id = '.(int)$id;
		$result = sqlSelect($sql);
		$row = sqlFetchRow($result);
		return $row[0];
	}
	
		
	public static function getPageBreakCount($formId) {
		$sql = '
			SELECT COUNT(*)
			FROM '.DB_NAME_PREFIX.ZENARIO_USER_FORMS_PREFIX . 'user_form_fields
			WHERE field_type = \'page_break\'
			AND user_form_id = '.(int)$formId;
		$result = sqlSelect($sql);
		$row = sqlFetchRow($result);
		return $row[0];
	}
	
	public static function isFormCRMEnabled($formId) {
		if (inc('zenario_crm_form_integration')) {
			$formCRMDetails = getRow(
				ZENARIO_CRM_FORM_INTEGRATION_PREFIX . 'form_crm_data', 
				array('enable_crm_integration'), 
				array('form_id' => $formId)
			);
			if ($formCRMDetails['enable_crm_integration']) {
				return true;
			}
		}
		return false;
	}
	
	public static function getTextFormFields($formId) {
		$formFields = array();
		$sql = "
			SELECT uff.id, uff.ord, uff.name, uff.validation AS field_validation, cdf.validation AS dataset_field_validation
			FROM ". DB_NAME_PREFIX. ZENARIO_USER_FORMS_PREFIX . "user_form_fields AS uff
			LEFT JOIN ". DB_NAME_PREFIX. "custom_dataset_fields AS cdf
				ON uff.user_field_id = cdf.id
			WHERE uff.user_form_id = ". (int)$formId. "
				AND (cdf.type = 'text' || uff.field_type = 'text')
			ORDER BY uff.ord";
		$result = sqlQuery($sql);
		while ($row = sqlFetchAssoc($result)) {
			$formFields[] = $row;
		}
		return $formFields;
	}
	
	public static function fieldTypeCanRecordValue($type) {
		return !in_array($type, array('page_break', 'section_description', 'restatement'));
	}
	
	public static function scanTextForProfanities($txt) {
		$profanityCsvFilePath = CMS_ROOT . 'zenario/libraries/not_to_redistribute/profanity-filter/profanities.csv';
		$csvFile = fopen($profanityCsvFilePath,"r");
		
		$profanityArr = array();
		$preparedProfanityWords = array();
		
		while(!feof($csvFile)) {
			$currentProfanity = fgetcsv($csvFile);
			$profanityArr[$currentProfanity[0]] = $currentProfanity[1];
		}
		
		foreach ($profanityArr as $k=>$v) {
			$k = str_replace('-','\\W*',$k);
			$preparedProfanityWords[$k] = $v;
		}
		
		fclose($csvFile);
		
		$profanityCount = 0;
		$txt = strip_tags($txt);
		$txt = html_entity_decode($txt,ENT_QUOTES);
		
		foreach ($preparedProfanityWords as $k=>$v) {
			preg_match_all("#\b".$k."(?:es|s)?\b#si",$txt, $matches, PREG_SET_ORDER);
			$profanityCount += count($matches)*$v;
		}
		
		return $profanityCount;
	}
}


class calculator {
    const PATTERN = '/(?:\-?\d+(?:\.?\d+)?[\+\-\*\/])+\-?\d+(?:\.?\d+)?/';

    const PARENTHESIS_DEPTH = 10;

    public function calculate($input){
    	
    	set_error_handler(function($errno, $errstr, $errfile, $errline) {
    		throw new Exception($errstr);
    	});
    	
        if(strpos($input, '+') != null || strpos($input, '-') != null || strpos($input, '/') != null || strpos($input, '*') != null){
            // Remove white spaces and invalid math chars
            $input = str_replace(',', '.', $input);
            $input = preg_replace('[^0-9\.\+\-\*\/\(\)]', '', $input);
            // Calculate each of the parenthesis from the top
            $i = 0;
            while(strpos($input, '(') || strpos($input, ')')){
                $input = preg_replace_callback('/\(([^\(\)]+)\)/', 'self::callback', $input);

                $i++;
                if($i > self::PARENTHESIS_DEPTH){
                    break;
                }
            }
            // Calculate the result
            if(preg_match(self::PATTERN, $input, $match)){
                return $this->compute($match[0]);
            }
            return 0;
        }
        
        restore_error_handler();
        
        return $input;
    }

    private function compute($input){
        $compute = create_function('', 'return '.$input.';');
        return 0 + $compute();
    }

    private function callback($input){
        if(is_numeric($input[1])){
            return $input[1];
        }
        elseif(preg_match(self::PATTERN, $input[1], $match)){
            return $this->compute($match[0]);
        }
        return 0;
    }
}
