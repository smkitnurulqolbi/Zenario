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

namespace ze;

class adminAdm {
	

	//or use \ze\adminAdm::savePerms($adminId, $actions = []) to add specific permissions
	//Formerly "saveAdminPerms()"
	public static function savePerms($adminId, $permissions, $actions = [], $details = []) {
		$clearAllOthers = true;
	
		//Catch some alternate parameters where we are trying to add permissions to an exist admin by 
		if (is_array($permissions)) {
			$actions = $permissions;
			$permissions = false;
			$clearAllOthers = false;
		}
	
		//Look up the permission type of the admin if we've not been passed it
		if (!$permissions) {
			$permissions = \ze\row::get('admins', 'permissions', $adminId);
		
			//Catch the case where there is nothing to actually update
			//I.e. there are no actions or other details that we would need to save.
			if ($permissions != 'specific_actions' && empty($details)) {
				return;
			}
		}
	
		switch ($permissions) {
			case 'all_permissions':
				//For backwards compatability with a few old bits of the system,
				//add an action called "_ALL" if someone's permission option is set to "all_permissions"
				$actions = ['_ALL' => true];
				break;
			case 'specific_actions':
				$actions['_ALL'] = false;
				$clearAllOthers = false;
				break;
			case 'specific_languages':
			case 'specific_menu_areas':
				//Admins who use specific_languages or specific_menu_areas have certain set permissions.
				//These are checked using PHP logic, but for backwards compatability with anything else
				//we'll also insert them into the database.
				$actions = \ze\admin::privsForTranslators();
				break;
		}
	
	
		//Delete any old, existing permissions
		if ($clearAllOthers) {
			\ze\row::delete('action_admin_link', ['admin_id' => $adminId]);
		}
	
		//Add/remove each permission from the database for this Admin.
		foreach ($actions as $perm => $set) {
			if ($set) {
				\ze\row::set('action_admin_link', [], ['action_name' => $perm, 'admin_id' => $adminId]);
		
			} elseif (!$clearAllOthers) {
				\ze\row::delete('action_admin_link', ['action_name' => $perm, 'admin_id' => $adminId]);
			}
		}
	
		$details['modified_date'] = \ze\date::now();
		$details['permissions'] = $permissions;
	
		//Update the admins table
		\ze\row::update('admins', $details, $adminId);
	}

	//Formerly "deleteAdmin()"
	public static function delete($admin_id, $undo = false) {
		$sql = "
			UPDATE ". DB_PREFIX. "admins SET
				status = '". ($undo? "active" : "deleted"). "',
				modified_date = NOW(),
				password = '',
				password_salt = '',
				reset_password_salt = '',
				password_needs_changing = 1
			WHERE authtype = 'local'
			  AND id = ". (int) $admin_id;
		\ze\sql::update($sql);
	}

	//Sets an admin's password, overwriting the current value
	//By default changing someone's password removes the password_needs_changing flag, but
	//you can override this and set it instead by passing a value of 1 to $requireChange
	//Formerly "setPasswordAdmin()"
	public static function setPassword($adminId, $password, $requireChange = null, $isPasswordReset = false) {
	
		//Generate a random salt for this password. If someone gets hold of the encrypted value of
		//the password in the database, having a salt on it helps to stop dictonary attacks.
		$salt = \ze\ring::random(8);
	
		if ($isPasswordReset) {
			$sql = "
				UPDATE ". DB_PREFIX. "admins SET
					modified_date = NOW(),";
		
			if ($requireChange !== null) {
				$sql .= "
					password_needs_changing = ". (int) $requireChange. ",";
			}
		
			$sql .= "
					reset_password = '". \ze\escape::sql(\ze\user::hashPassword($salt, $password)). "',
					reset_password_salt = '". \ze\escape::sql($salt). "',
					reset_password_time = NOW()
				WHERE id = ". (int) $adminId;
	
		} else {
			$sql = "
				UPDATE ". DB_PREFIX. "admins SET
					modified_date = NOW(),
					password_salt = '". \ze\escape::sql($salt). "',";
		
			if ($requireChange !== null) {
				$sql .= "
					password_needs_changing = ". (int) $requireChange. ",";
			}
		
			$sql .= "
					reset_password = NULL,
					reset_password_salt = NULL,
					password = '". \ze\escape::sql(\ze\user::hashPassword($salt, $password)). "'
				WHERE id = ". (int) $adminId;
		}
	
		$result = \ze\sql::update($sql);
	}


	//Look for and update the copy of the Global Admins in the local table
	//Formerly "syncSuperAdmin()"
	public static function syncMultisiteAdmins($adminIdG) {
		$adminColumns = [
			'username', 'password', 'password_salt', 'password_needs_changing', 
			'status', 'first_name', 'last_name', 'email', 'created_date', 'modified_date', 'image_id',
			'permissions', 'specific_languages', 'specific_content_items', 'specific_menu_areas'
		];

		//Attempt to connect to the global database
		if (\ze\db::connectGlobal()) {
			//Look up the details on the global database
			$globalAdmins = \ze\rowGlobal::getArray('admins', $adminColumns, ['authtype' => 'local']);

			//For all global admins...
			foreach ($globalAdmins as $globalId => &$admin) {
	
				//...check if they have an image and get the checksum...
				if ($admin['image_id']) {
					$admin['image_checksum'] = \ze\rowGlobal::get('files', 'checksum', ['id' => $admin['image_id']]);
				} else {
					$admin['image_checksum'] = false;
				}
	
				//...and get an array of their actions
				$admin['_actions_'] = \ze\rowGlobal::getArray('action_admin_link', 'action_name', ['admin_id' => $globalId], 'action_name');
			}
		} else {
			//Return an empty string if the link is not working
				//I want the "global db not enabled" and "password not correct" states to be different,
				//yet still both evaulate to false.
			return '';
		}


		//Loop through all of the global admins we found
		foreach ($globalAdmins as $globalId => &$admin) {
	
			$admin['global_id'] = $globalId;
	
			if (\ze::$dbL->checkTableDef(DB_PREFIX. 'admins', 'is_client_account')) {
				$admin['is_client_account'] = 0;
			}
	
			$key = ['global_id' => $admin['global_id']];
	
			//Skip trashed globsl admins that were never on this site in the first place
			if ($admin['status'] == 'deleted'
			 && !\ze\row::exists('admins', $key)) {
				continue;
			}
	
			//Did this admin have an image set?
			if ($admin['image_checksum'] !== false) {
				//If so, try to use the same image here, if we can find the image on this site as well
				if (!$admin['image_id'] = \ze\row::get('files', 'id', ['checksum' => $admin['image_checksum'], 'usage' => 'admin'])) {
			
					//If we can't find it, get the image from the global database
					$image = \ze\rowGlobal::get('files', ['data', 'filename', 'checksum'], $admin['image_id']);
			
					//Copy it to the local database and then use the copy
					if ($image !== false) {
						$adminG['image_id'] = \ze\file::addFromString('admin', $image['data'], $image['filename'], true);
					}
				}
			} else {
				$admin['image_id'] = 0;
			}
	
			$actions = $admin['_actions_'];
			unset($admin['image_checksum'], $admin['_actions_']);
			$admin['authtype'] = 'super';
	
			$admin['local_id'] = $localId = \ze\row::set('admins', $admin, $key);
	
			//Check to see if the specific permissions have changed
			$actionsHere = \ze\row::getValues('action_admin_link', 'action_name', ['admin_id' => $localId], 'action_name');
			if (print_r($actions, true) != print_r($actionsHere, true)) {
		
				//If so, delete the old ones and re-insert all of the new ones
				\ze\row::delete('action_admin_link', ['admin_id' => $localId]);
				foreach ($actions as $action) {
					\ze\row::insert('action_admin_link', ['admin_id' => $localId, 'action_name' => $action], true);
				}
			}
		}

		//If any superadmins on this site were not found on the global site, flag them as deleted.
		\ze\row::update('admins', ['status' => 'deleted'], ['authtype' => 'super', 'global_id' => ['!' => array_keys($globalAdmins)]]);

		if (!empty($globalAdmins[$adminIdG]['local_id'])) {
			return $globalAdmins[$adminIdG]['local_id'];
		} else {
			return false;
		}
	}
	
	
	public static function updateHash($adminId) {
		$emailAddress = \ze\row::get('admins', 'email', $adminId);
		$sql = "
			UPDATE ". DB_PREFIX. "admins 
			SET hash = '". \ze\escape::sql(\ze\userAdm::createHash($adminId, $emailAddress)). "'
			WHERE id = ". (int) $adminId;
		\ze\sql::update($sql, false, false);
	}

}