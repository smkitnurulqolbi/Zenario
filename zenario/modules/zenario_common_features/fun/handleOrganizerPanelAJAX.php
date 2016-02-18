<?php
/*
 * Copyright (c) 2016, Tribal Limited
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
	case 'zenario__content/panels/documents':
		if (post('reorder') || post('hierarchy')) {
			$idsArray = explode(',', $ids);
			$filenamesInFolder = array();
			$folderNamesInFolder = array();
			foreach ($idsArray as $id) {
				// Foreach moved file
				if (isset($_POST['parent_ids'][$id])
					&& ($documentDetails = getRow('documents', array('filename', 'folder_name'), $id))
				) {
					$filename = $documentDetails['filename'];
					$folder_name = $documentDetails['folder_name'];
					$isFolder = (bool)$folder_name;
					
					// Check a file/folder with the same name exists in the database and hasn't moved into the same folder
					$duplicateNamesFound = false;
					$parent_id = $_POST['parent_ids'][$id];
					$sql = '
						SELECT id
						FROM ' . DB_NAME_PREFIX . 'documents
						WHERE 1=1 ';
					if ($isFolder) {
						$sql .= ' AND folder_name = "' . sqlEscape($folder_name) . '"';
					} else {
						$sql .= ' AND filename = "' . sqlEscape($filename) . '"';
					}
					$sql .= '
						AND folder_id = ' . (int)$parent_id . '
						AND id != ' . (int)$id;
					$result = sqlSelect($sql);
					while ($row = sqlFetchAssoc($result)) {
						$id2 = $row['id'];
						if (!isset($_POST['parent_ids'][$id2]) || ($_POST['parent_ids'][$id2] == $parent_id)) {
							$duplicateNamesFound = true;
						}
					}
					
					// Check identical named files/folders havn't been moved into the same folder at once
					if (!$duplicateNamesFound) { 
						if ($isFolder) {
							if (isset($folderNamesInFolder[$parent_id][$folder_name])) {
								$duplicateNamesFound = true;
							} else {
								$folderNamesInFolder[$parent_id][$folder_name] = true;
							}
						} else {
							if (isset($filenamesInFolder[$parent_id][$filename])) {
								$duplicateNamesFound = true;
							} else {
								$filenamesInFolder[$parent_id][$filename] = true;
							}
						}
					}
					
					if ($duplicateNamesFound) {
						if ($isFolder) {
							$type = 'folder';
							$name = $folder_name;
						} else {
							$type = 'file';
							$name = $filename;
						}
						echo '<!--Message_Type:Error-->';
						if ($parent_id == 0) {
							$error = adminPhrase('You cannot have more than one [[type]] named "[[name]]" in the root directory', array('name' => $name, 'type' => $type));
						} else {
							$problem_folder_name = getRow('documents', 'folder_name', $parent_id);
							$error = adminPhrase('You cannot have more than one [[type]] named "[[name]]" in the directory "[[folder_name]]"', array('name' => $name, 'folder_name' => $problem_folder_name, 'type' => $type));
						}
						echo $error;
						exit;
					}
				}
			}
			
			
			//Loop through each moved files and save
			foreach ($idsArray as $id) {
				//Look up the current id, folder_id and ordinal
				if ($file = getRow('documents', array('id', 'folder_id', 'ordinal'), $id)) {
					$cols = array();
					
					//Update the ordinal if it is different
					if (isset($_POST['ordinals'][$id]) && $_POST['ordinals'][$id] != $file['ordinal']) {
						$cols['ordinal'] = $_POST['ordinals'][$id];
					}
	
					//Update the folder id if it is different, and remember that we've done this
					if (isset($_POST['parent_ids'][$id]) && $_POST['parent_ids'][$id] != $file['folder_id']) {
						$cols['folder_id'] = $_POST['parent_ids'][$id];
						$folder = getRow('documents', array('id', 'type'), $_POST['parent_ids'][$id]);
						if ($folder['type'] == "file") {
							echo '<!--Message_Type:Error-->';
							echo adminPhrase('Files may not be moved under other files, files can only be placed under folders.');
							exit;
						}
					}
					
					
					updateRow('documents', $cols, $id);
				}
			}
		} elseif (post('upload')) {
			if (!checkDocumentTypeIsAllowed($_FILES['Filedata']['name'])) {
				echo
					adminPhrase('You must select a known file format, for example .doc, .docx, .jpg, .pdf, .png or .xls.'), 
					"\n\n",
					adminPhrase('To add a file format to the known file format list, go to "Configuration -> Uploadable file types" in Organizer.'),
					"\n\n",
					adminPhrase('Please also check that your filename does not contain any of the following characters: ' . "\n" . '\\ / : * ? " < > |');
				exit;
			}
			
			exitIfUploadError();
			$file_id = addFileToDatabase('hierarchial_file', $_FILES['Filedata']['tmp_name'], preg_replace('/([^.a-z0-9\s_]+)/i', '-',$_FILES['Filedata']['name']), false, false, true);
			$existingFile = getRow('documents', array('id'), array('file_id' => $file_id));
			if ($existingFile) {
				echo "This file has already been uploaded to the files directory!";
				return $existingFile['id'];
			}
			
			$documentProperties = array(
				'type' =>'file',
				'file_id' => $file_id,
				'folder_id' => 0,
				'ordinal' => 0);
				
			$extraProperties = self::addExtractToDocument($file_id);
			$documentProperties = array_merge($documentProperties, $extraProperties);
			
			if ($ids) {
				$documentProperties['folder_id'] = $ids;
			}
			
			if ($documentId = insertRow('documents', $documentProperties)) {
				self::processDocumentRules($documentId);
			}
			
			return $documentId;
			
		} elseif (post('rescan')) {
			$file_id = getRow('documents', 'file_id', array('id' => $ids));
			$documentProperties = self::addExtractToDocument($file_id);
			if (empty($documentProperties['extract']) || empty($documentProperties['thumbnail_id'])) {
				echo "<!--Message_Type:Error-->";
			} else {
				echo "<!--Message_Type:Success-->";
			}
			
			if (empty($documentProperties['extract'])) {
				echo '<p>', adminPhrase('Unable to update document text extract.'), '</p>';
				
				if (!((plainTextExtract(moduleDir('zenario_common_features', 'fun/test_files/test.doc'), $extract))
					 && ($extract == 'Test'))) {
					echo '<p>', adminPhrase('antiword or pdftotext do not appear to be working.'), '</p>';
				}
			} else {
				echo '<p>', adminPhrase('Successfully updated document text extract.'), '</p>';
			}
			
			if (empty($documentProperties['thumbnail_id'])) {
				echo '<p>', adminPhrase('Unable to update document image.'), '</p>';
				
				if (!createPpdfFirstPageScreenshotPng(moduleDir('zenario_common_features', 'fun/test_files/test.pdf'))) {
					echo '<p>', adminPhrase('ghostscript does not appear to be working.'), '</p>';
				}
			} else {
				echo '<p>', adminPhrase('Successfully updated document image.'), '</p>';
			}
			
			updateRow('documents', $documentProperties, array('id' => $ids));
			
		}elseif(post('rescanText')){ 
			$file_id = getRow('documents', 'file_id', array('id' => $ids));
			$documentProperties = self::addExtractToDocument($file_id);
			if (empty($documentProperties['extract'])) {
				echo "<!--Message_Type:Error-->";
			} else {
				echo "<!--Message_Type:Success-->";
			}
			if (empty($documentProperties['extract'])) {
				echo '<p>', adminPhrase('Unable to update document text extract.'), '</p>';
				
				if (!((plainTextExtract(moduleDir('zenario_common_features', 'fun/test_files/test.doc'), $extract))
					 && ($extract == 'Test'))) {
					echo '<p>', adminPhrase('antiword or pdftotext do not appear to be working.'), '</p>';
				}
			} else {
				echo "<p>Successfully updated document text extract.</p>";
				updateRow('documents', array('extract'=>$documentProperties['extract']), array('id' => $ids));
			}
		
		}elseif (post('autoset')) {
			self::processDocumentRules($ids);
			
		} elseif (post('dont_autoset_metadata')) {
			foreach (explode(',', $ids) as $id) {
				updateRow('documents', array('dont_autoset_metadata' => 1), $id);
			}
			
		} elseif (post('allow_autoset_metadata')) {
			foreach (explode(',', $ids) as $id) {
				updateRow('documents', array('dont_autoset_metadata' => 0), $id);
			}
		
		//Remove all of the custom data from a document
		} elseif (post('remove_metadata')) {
			if ($dataset = getDatasetDetails('documents')) {
				foreach (explode(',', $ids) as $id) {
					deleteRow('documents_custom_data', $id);
					deleteRow('custom_dataset_values_link', array('dataset_id' => $dataset['id'], 'linking_id' => $id));
				}
			}
			
		} elseif (post('delete')) {
			foreach (explode(',', $ids) as $id) {
				self::deleteHierarchicalDocument($id);
			}
		} elseif (post('generate_public_link')) {
			$messageType = 'Success';
			$html = '';
			foreach (explode(',', $ids) as $id) {
				$document = getRow('documents', array('file_id', 'filename'), $id);
				$file = getRow('files', 
								array('id', 'filename', 'path', 'created_datetime', 'short_checksum'),
								$document['file_id']);
				if($file['filename']) {
					if (cleanDownloads()) {
						$dirPath = createCacheDir($file['short_checksum'], 'public/downloads', false);
					}
					if (!$dirPath) {
						$messageType = 'Error';
						$html .= 'Could not generate public link because public file structure incorrect</br>';
						break;
					}
					
					$symFolder =  CMS_ROOT . $dirPath;
					$symPath = $symFolder . $document['filename'];
					
					$frontLink = $dirPath . $document['filename'];
					if (!windowsServer() && ($path = docstoreFilePath($file['id'], false))) {
						if (!file_exists($symPath)) {
							if(!file_exists($symFolder)) {
								mkdir($symFolder);
							}
							symlink($path, $symPath);
						} 
						
						updateRow('documents', array('privacy' => 'public'), $id);
				
						$baseURL = absCMSDirURL();
						
						$message="<h3>The hyperlinks to your document are shown below:</h3>";
						
						$fullLink = $baseURL.$frontLink;
						$normalLink =$frontLink;
						
						$fullLink = str_replace(' ', '%20', $fullLink );
						$normalLink = str_replace(' ', '%20', $normalLink);
				
						$html .= $message."Full hyperlink: <br>" . "<input type='text' style='width: 488px;' value = '".$fullLink."'/><br>Internal hyperlink:<br><input type='text' style='width: 488px;' value = '". $normalLink . "'/>";
					} else {
						$messageType = 'Error';
						if (windowsServer()) {
							$html .= 'Could not generate public link because the CMS is installed on a windows server.</br>';
						} else {
							$html .= 'Could not generate public link because this document is not stored in the Docstore.</br>Make sure the Docstore directory is correctly setup and re-upload this document.</br>';
						}
					}
				} else {
					$messageType = 'Error';
					$html .= 'Could not generate public link because no file exists</br>';
				}
			}
			echo '<!--Message_Type:'.$messageType.'-->';
			echo $html;
		}elseif(post('delete_public_link')){
			
			foreach (explode(',', $ids) as $id) {
				$result = self::deleteHierarchicalDocumentPubliclink($id);
				
				if ($result === true) {
					echo "<!--Message_Type:Success-->";
					echo 'Public link was deleted successfully.';
				} else {
					echo '<!--Message_Type:Error-->';
					echo $result;
				}
				
			}
		}
		break;
		
	case 'zenario__content/panels/document_tags':
		if (post('delete')) {
			foreach (explode(',', $ids) as $id) {
				self::deleteDocumentTag($id);
			}
		}
		break;
	

	case 'editor_temp_file':
		$key = false;
		$privCheck = checkPriv('_PRIV_MANAGE_MEDIA');
		
		return require funIncPath(__FILE__, 'media.handleOrganizerPanelAJAX');
		
	//For debug
	default:
		print_r($_GET);
		print_r($_POST);
}

return false;