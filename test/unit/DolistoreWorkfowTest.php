<?php
/* Functionnal tests for module Dolistorextract
 * Copyright (C) 2017  Jean-François Ferry	<jfefe@aternatik.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * \file    test/unit/DolistorextractTest.php
 * \ingroup dolistorextract
 * \brief   PHPUnit tests for module DolistorExtract.
 *
 * Functionnal tests for module Dolistorextract
 */

namespace test\unit;

global $conf,$user,$langs,$db;
//define('TEST_DB_FORCE_TYPE','mysql');	// This is to force using mysql driver
//require_once 'PHPUnit/Autoload.php';
$res = false;
if (file_exists(dirname(__FILE__).'/../../../../htdocs/master.inc.php')) {
	$res = require_once dirname(__FILE__).'/../../../../htdocs/master.inc.php';
} elseif (file_exists(dirname(__FILE__).'/../../../../../htdocs/master.inc.php')) {
	$res = require_once dirname(__FILE__).'/../../../../../htdocs/master.inc.php';
} else {
	die('Include of mains fails');
}
require_once dirname(__FILE__).'/../../class/dolistoreMail.class.php';
require_once dirname(__FILE__).'/../../class/actions_dolistorextract.class.php';

use PHPUnit\Framework\TestCase;

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->getrights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS=1;

/**
 * Class DolistorextractTest
 * @package test\unit
 */
class DolistoreWorkflowTest extends TestCase
{

	protected $savconf;
	protected $savuser;
	protected $savlangs;
	protected $savdb;

	protected $socid;

	/**
	 *
	 * @var dolistoreMail dolistoreMail object
	 */
	protected $dolistoreMail;

	protected $dolistoreMailExtract;

	protected $dolistorextractActions;


	/**
	 * Global test setup
	 */
	public static function setUpBeforeClass()
	{
		fwrite(STDOUT, __METHOD__ . "\n");

	}

	/**
	 * Unit test setup
	 */
	protected function setUp()
	{
		fwrite(STDOUT, __METHOD__ . "\n");

		global $conf,$user,$langs,$db;

		$this->savconf=$conf;
		$this->savuser=$user;
		$this->savlangs=$langs;
		$this->savdb=$db;


		$this->dolistoreMail = new \dolistoreMail();

		$html = file_get_contents(dirname(__FILE__).'/../ex_info_produit.html');
		$this->dolistoreMailExtract = new \dolistoreMailExtract($db, $html);

		$this->dolistorextractActions = new \ActionsDolistorextract($db);

		fwrite(STDOUT, __METHOD__ ." db->type=".$db->type." user->id=".$user->id."\n");
	}

	/**
	 * Verify pre conditions
	 */
	protected function assertPreConditions()
	{
		fwrite(STDOUT, __METHOD__ . "\n");
	}

	/**
	 * Verify post conditions
	 */
	protected function assertPostConditions()
	{
		fwrite(STDOUT, __METHOD__ . "\n");
	}

	/**
	 * Unit test teardown
	 */
	protected function tearDown()
	{
		fwrite(STDOUT, __METHOD__ . "\n");
	}



	/**
	* testCreateCustomerFromDatas
	*
	* @return	int
	*
	* The depends says test is run only if previous is ok
	*/
	public function testCreateCustomerFromDatas()
	{

		//global $conf,$user,$langs,$db;
		$conf=$this->savconf;
		$user=$this->savuser;
		$langs=$this->savlangs;
		$db=$this->savdb;

		// Extract datas
		$datas = $this->dolistoreMailExtract->extractOrderDatas();

		if (!class_exists('User')) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		}
		$this->dolistoreMail->setDatas($datas);

		$userStatic = new \User($db);
		$userStatic->fetch( getDolGlobalInt('DOLISTOREXTRACT_USER_FOR_ACTIONS'));

		$socid = $this->dolistorextractActions->newCustomerFromDatas($userStatic, $this->dolistoreMail);
		fwrite(STDOUT, __METHOD__." created socid=".$socid."\n");
		$this->assertGreaterThan(0, $socid);

		return $socid;
	}

	/**
	 * testSetCustomerCategoryFromOrder
	 *
	 * @param int $socid
	 * return int
	 * @depends testCreateCustomerFromDatas
	 */
	public function testSetCustomerCategoryFromOrder($socid) {


		$conf=$this->savconf;
		$user=$this->savuser;
		$langs=$this->savlangs;
		$db=$this->savdb;

		$productRefTest = 'prod1234';

		$result = $this->dolistoreMailExtract->setCustomerCategoryFromOrder($productRefTest, $socid);
		fwrite(STDOUT, __METHOD__." result=".$result."\n");
		$this->assertGreaterThan(0, $result);

		return $socid;
	}

	/**
	 * testCreateEventFromDatas
	 *
	 * @param int $socid
	 * return int
	 * @depends testCreateCustomerFromDatas
	 */
	public function testCreateEventFromDatas($socid) {
		$conf=$this->savconf;
		$user=$this->savuser;
		$langs=$this->savlangs;
		$db=$this->savdb;

		$datas = $this->dolistoreMailExtract->extractAllDatas();
		$result = $this->dolistoreMailExtract->createEventsFromExtract($datas, $socid);



	}

	/**
	 * Global test teardown
	 */
	public static function tearDownAfterClass()
	{
		fwrite(STDOUT, __METHOD__ . "\n");

	}

	/**
	 * Unsuccessful test
	 *
	 * @param \Throwable $t
	 * @throws \Throwable
	 */
	protected function onNotSuccessfulTest(\Throwable $t)
	{
		fwrite(STDOUT, __METHOD__ . "\n");
		throw $t;
	}


}
