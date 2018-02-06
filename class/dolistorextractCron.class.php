<?php
/* Copyright (C) 2017      Jean-François Ferry	<jfefe@aternatik.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Cron class for module dolistorextract
 * @author jfefe
 *
 */
class dolistorextractCron 
{

	public $db;
	
	function __construct(&$db) {
		$this->db = $db;
	}
	
	/**
	 * Method to call with CRON module 
	 */
	public function runImport()
	{
		
		global $conf, $langs, $user;
		
		require_once 'actions_dolistorextract.class.php';
		
		$dolistorextractActions = new \ActionsDolistorextract($this->db);
		$res = $dolistorextractActions->launchCronJob();
		if ($res <= 0) {
			print 'erreur import dolistore!';
			print_r($dolistorextractActions->errors);
		}
		if($res > 0) {
			
		}
		
	}
}