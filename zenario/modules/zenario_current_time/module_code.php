<?php
/*
 * Copyright (c) 2015, Tribal Limited
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

class zenario_current_time extends module_base_class {

	protected function timeFormat() {
		return $this->setting('time_format');
	}
	
	protected function timezone() {
		return false;
	}
	
	
	public function init() {
		$timeFormat = $this->setting('time_format');
		
		$this->callScript(
			'zenario_current_time', 'setClock',
			$this->containerId, $this->AJAXLink('action=getCurrentTime'),
			$this->timeFormat(), $this->timezone()
		);
		
		return true;
	}
	

	public function showSlot() {
		$this->twigFramework();
	}
	
	public function handleAJAX() {
		switch (request('action')) {
			case 'getCurrentTime':
				$format = 'H~i~s';
				$timezone = request('timezone');
				if (!$timezone || strtoupper($timezone)=="FALSE"){
					$timezone=false;
				}
				
				$date = gmdate('Y-m-d H:i:s');
				$timestamp = strtotime($date. " UTC");
				
				if ($format && $timestamp){
					echo self::getDatetime($timestamp, $format, $timezone);
				}
					
				exit;
		}
	}
	

	public static function getDatetime($timestamp,$format,$timezone=false){
		if (inc('zenario_timezones')) {
			if (userId()) {
				//User timezone
				return zenario_timezones::convertToUserTimezone($timestamp,$format);
			} elseif ($timezone) {
				//site timezone
				return zenario_timezones::convertToUserTimezone($timestamp,$format, $timezone);
			} 
		} 
		
		//if user not logged in and no manual timezone set return server time
		return gmdate($format, $timestamp);
	}


}