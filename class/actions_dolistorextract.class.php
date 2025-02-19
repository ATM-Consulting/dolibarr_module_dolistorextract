<?php
/* Copyright (C) - 2017    Jean-François Ferry    <jfefe@aternatik.fr>
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
*/
/**
 *    \file       dolistorextract/class/actions_dolistorextract.class.php
*    \ingroup    dolistorextract
*    \brief      File Class dolistorextract
*/
//require_once "dolistorextract.class.php";
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
dol_include_once("/dolistorextract/include/ssilence/php-imap-client/autoload.php");

use SSilence\ImapClient\ImapClientException;
use SSilence\ImapClient\ImapConnect;
use SSilence\ImapClient\ImapClient as Imap;


/**
 *    \class      ActionsTicketsup
 *    \brief      Class Actions of the module dolistorextract
 */
class ActionsDolistorextract
{
	public $db;
	public $dao;
	public $mesg;
	public $error;
	public $nbErrors;
	public $errors = array();
	//! Numero de l'erreur
	public $errno = 0;
	public $template_dir;
	public $template;

	public $logCat = '';

	/**
	 *    Constructor
	 *
	 *    @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook to add email element template
	 *
	 * @param array 		$parameters
	 * @param Object 		$object
	 * @param string 		$action
	 * @param HookManager 	$hookmanager
	 * @return int
	 */
	public function emailElementlist($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$error = 0;

		//if (in_array('admin', explode(':', $parameters['context']))) {
			$this->results = array('dolistore_extract' => $langs->trans('DolistorextractMessageToSendAfterDolistorePurchase'));
		//}

		if (! $error) {
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}

	}

	/**
	 * Create a new customer with email datas
	 *
	 * @param User $user
	 * @param dolistoreMail $dolistoreMail
	 * @return int ID of created customer
	 */
	public function newCustomerFromDatas(User $user, dolistoreMail $dolistoreMail)
	{
		global $conf;

		$socStatic = new Societe($this->db);

		if (empty($dolistoreMail->buyer_company) || empty($dolistoreMail->buyer_email)) {
			// print "buyer_company or email not found !";
			return -1;
		}
		// Load object modCodeTiers
		$module=(getDolGlobalString('SOCIETE_CODECLIENT_ADDON')?getDolGlobalString('SOCIETE_CODECLIENT_ADDON'):'mod_codeclient_leopard');
		if (substr($module, 0, 15) == 'mod_codeclient_' && substr($module, -3) == 'php')
		{
			$module = substr($module, 0, dol_strlen($module)-4);
		}
		$dirsociete=array_merge(array('/core/modules/societe/'),$conf->modules_parts['societe']);
		foreach ($dirsociete as $dirroot)
		{
			$res=dol_include_once($dirroot.$module.'.php');
			if ($res) break;
		}
		$modCodeClient = new $module;

		$socStatic->code_client = $modCodeClient->getNextValue($socStatic,0);
		$socStatic->name = $dolistoreMail->buyer_company;
		$socStatic->name_bis = $dolistoreMail->buyer_lastname;
		$socStatic->firstname = $dolistoreMail->buyer_firstname;
		$socStatic->address = $dolistoreMail->buyer_address1;
		$socStatic->zip = $dolistoreMail->buyer_postal_code;
		$socStatic->town = $dolistoreMail->buyer_city;
		$socStatic->phone = $dolistoreMail->buyer_phone;
		$socStatic->email = $dolistoreMail->buyer_email;
		$socStatic->country_code = $dolistoreMail->buyer_country_code;
		$socStatic->state = $dolistoreMail->buyer_state;
		$socStatic->multicurrency_code = $dolistoreMail->order_currency;

		// Le champ buyer_country_code contient BE/FR/DE...
		$resql = $this->db->query('SELECT rowid as fk_country FROM '.MAIN_DB_PREFIX."c_country WHERE code = '".$this->db->escape($dolistoreMail->buyer_country_code)."'");
		if($resql) {
			if(($obj = $this->db->fetch_object($resql)) && $this->db->num_rows($resql) == 1) $socStatic->country_id = $obj->fk_country;
		}

		$socStatic->array_options["options_provenance"] = "INT";
		$socStatic->import_key = "STORE";

		$socStatic->client = 2; // Prospect / client
		$socid = $socStatic->create($user);
		if($socid > 0) {
			$res = $socStatic->create_individual($user);
		} else if(is_array($socStatic->errors)){
			$this->errors = array_merge($this->errors, $socStatic->errors);
		}
		return $socid;
	}

	/**
	 * Ajoute le client $socid dans la catégorie correspondante au module $productRef
	 *
	 * Les categories doivent avoir un champ extrafield `ref_dolistore`
	 *
	 * @uses searchCategoryDolistore()
	 * @param string $productRef Product reference
	 * @param int $socid ID of company
	 */
	public function setCustomerCategoryFromOrder($productRef, $socid)
	{
		$socStatic = new Societe($this->db);
		$catStatic = new Categorie($this->db);

		$catStatic->id = $this->searchCategoryDolistore($productRef);

		if ($catStatic->id > 0 && $socStatic->fetch($socid)) {
			return $catStatic->add_type($socStatic, 'customer');
		} else {
			return -1;
		}
		return 0;
	}

	/**
	 * Search a category with extrafield `ref_dolistore` value
	 *
	 * @param string $productRef
	 * @return int Category ID or -1 if error
	 */
	public function searchCategoryDolistore($productRef)
	{
		$sql = "SELECT fk_object FROM ".MAIN_DB_PREFIX."categories_extrafields WHERE ref_dolistore='".$productRef."'";

		dol_syslog(__METHOD__, LOG_DEBUG);
		$resql = $this->db->query($sql);
		$result = 0;
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$return = $obj->fk_object;
			}
			$this->db->free($resql);
			return $return;
		} else {
			$this->error = "Error " . $this->db->lasterror();
			dol_syslog(__METHOD__ . " " . $this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 * Create an event
	 *
	 * @param array $extractDatas Array fil
	 * @param string $socid
	 * @return number
	 */
	public function createEventFromExtractDatas($productDatas, $orderRef, $socid)
	{
		global $conf, $langs;

		// Check value
		if (empty($orderRef) || empty($productDatas['item_reference'])) {
			dol_syslog(__METHOD__.' Error : params order_name and product_ref missing');
			return -1;
		}

		$res = 0;

		$userStatic = new User($this->db);
		$userStatic->fetch($conf->global->DOLISTOREXTRACT_USER_FOR_ACTIONS);

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$actionStatic = new ActionComm($this->db);

		$actionStatic->socid = $socid;

		$actionStatic->authorid = $conf->global->DOLISTOREXTRACT_USER_FOR_ACTIONS;
		$actionStatic->userownerid = $conf->global->DOLISTOREXTRACT_USER_FOR_ACTIONS;

		$actionStatic->datec = time();
		$actionStatic->datem = time();
		$actionStatic->datep = time();
		$actionStatic->percentage = 100;

		$actionStatic->type_code = 'AC_STRXTRACT';
		$actionStatic->label = $langs->trans('DolistorextractLabelActionForSale', $productDatas['item_name'] .' ('.$productDatas['item_reference'].')');
		// Define a tag which allow to detect twice
		$actionStatic->note = 'ORDER:'.$orderRef.':'.$productDatas['item_reference'];
		// Check if import already done
		if(! $this->isAlreadyImported($actionStatic->note)) {
			$res = $actionStatic->create($userStatic);

		}
		return $res;
	}

	private function isAlreadyImported($noteString)
	{
		$sql = "SELECT id FROM ".MAIN_DB_PREFIX."actioncomm WHERE note='".$noteString."'";

		dol_syslog(__METHOD__, LOG_DEBUG);
		$resql = $this->db->query($sql);
		$result = 0;
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$return = $obj->id;
			}
			$this->db->free($resql);
			return $return;
		} else {
			$this->error = "Error " . $this->db->lasterror();
			dol_syslog(__METHOD__ . " " . $this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 * Method to launch CRON job to import datas from emails
	 */
	public function launchCronJob()
	{
		global $langs, $conf;

		$this->nbErrors = 0;
		$langs->load('main');

		$mailbox = $conf->global->DOLISTOREXTRACT_IMAP_SERVER;
		$username = $conf->global->DOLISTOREXTRACT_IMAP_USER;
		$password = $conf->global->DOLISTOREXTRACT_IMAP_PWD;
		$encryption = Imap::ENCRYPT_SSL;

		// Open connection
		try{
			$imap = new Imap($mailbox, $username, $password, $encryption);

			// You can also check out example-connect.php for more connection options

		}catch (ImapClientException $error){

			$this->errors[] = $error->getMessage().PHP_EOL;
			return -1;
		}


		// Select the folder Inbox
		$imap->selectFolder(getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER')?getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER'):'INBOX');

		// Fetch all the messages in the current folder

		$emails = $imap->getMessages();
		$this->logCat.= '<br/><strong>Mail to process</strong> :'.count($emails);

		$mailSent = 0;

		if(getDolGlobalString('DOLISTOREXTRACT_DISABLE_SEND_THANK_YOU')){
			$this->logCat.= '<br/><strong class="error">Mail send disabled</strong>';
		}

		$orderSubjects = [];
		/**
		 * @var IncomingMessage[] $emails
		 * @return negative value if KO, 0 and positive value is OK
		 */
		foreach($emails as $email) {

			$this->logCat.= '<br/><strong>Processing :</strong> '.$email->header->subject;

			// Only mails from Dolistore and not seen
			if (strpos($email->header->subject, 'DoliStore') > 0 && !$email->header->seen) {
				$isMailAlreadySent = in_array($email->header->subject, $orderSubjects);
				if (!$isMailAlreadySent){
					$this->logCat.= '<br/>-> launch Import Process ';
					$res = $this->launchImportProcess($email);
				} else $res = 1;

				if ($res >= 0) {
					if (!$isMailAlreadySent){
						$orderSubjects[]= $email->header->subject;
						++$mailSent;
						$this->logCat.= '-> <stong>OK</stong>';
					}
					// Mark email as read
					$imap->setSeenMessage($email->header->msgno, true);
					if(getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE')) {
						$resMov = $imap->moveMessage($email->header->uid, $conf->global->DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE);
						if(!$resMov){
							$this->logCat.='<br/>Erreur move message '.$email->header->uid.' TO ' . getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE');
						}
					}
				} else{
					$this->logCat.= '-> <stong class="error">FAIL</stong>';
					if(getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR')) {
						$resMov = $imap->moveMessage($email->header->uid, $conf->global->DOLISTOREXTRACT_IMAP_FOLDER_ERROR);
						if(!$resMov){
							$this->logCat.='<br/>Erreur move message '.$email->header->uid.' TO ' . getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR');
						}
					}
				}


			}
			else{
				$this->logCat.= '<br/>-> skipped ';
			}
		}


		if(!getDolGlobalString('DOLISTOREXTRACT_DISABLE_SEND_THANK_YOU')){
			$this->logCat.='<hr/><stong>'.$langs->trans('EMailSentForNElements',$mailSent).'</strong>';
		}
		if(!empty($this->nbErrors)) return -1;
		return $mailSent;

	}
	/**
	 * Launch all import process
	 * @param unknown $email Object from imap fetch with lib
	 * @return negative value if KO, 0 and positive value is OK
	 */
	public function launchImportProcess($email) {

		global $conf, $error;
		dol_syslog(__METHOD__.' launch import process for message '.$email->header->uid, LOG_DEBUG);

		$error = 0;

		if (!class_exists('Societe')) {
			require_once(DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php');
		}
		if (!class_exists('Categorie')) {
			require_once(DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php');
		}
		if (!class_exists('dolistoreMailExtract')) {
		    dol_include_once('/dolistorextract/class/dolistoreMailExtract.class.php');
		}
		if (!class_exists('dolistoreMail')) {
		    dol_include_once('/dolistorextract/class/dolistoreMail.class.php');
		}

		$dolistoreMailExtract = new \dolistoreMailExtract($this->db, $email->message->text);

		$dolistoreMail = new \dolistoreMail();
		$dolistorextractActions = new \ActionsDolistorextract($this->db);

		$userStatic = new \User($this->db);
		$userStatic->fetch($conf->global->DOLISTOREXTRACT_USER_FOR_ACTIONS);

		$mailSent = 0; // Count number of sent emails


		$langEmail = $dolistoreMailExtract->detectLang($email->header->subject);
		$datas = $dolistoreMailExtract->extractAllDatas();

		if(empty($datas['buyer_email'])) {
			// print "Erreur email vide !<br />";
			return -2;
		}

		$dolistoreMail->setDatas($datas);
		if (is_array($datas) && count($datas) > 0) {

			$this->logCat.= '<div>';
			foreach ($datas as $dk => $dv){
				$this->logCat.='<br/>'.$dk.' : '.$dv;
			}
			$this->logCat.= '</div>';

			/*
			 * import client si non existant
			 - liaison du client à une catégorie (utilisation d'un extrafield pour stocker la référence produit sur la catégorie)
			 - envoi d'une réponse automatique par mail en utilisant les modèles Dolibarr : 1 FR et 1 EN (EN tous les autres)
			 - création d'un évènement "Achat module Dolistore" avec mention de la référence de la commande Dolistore
			 */
			$socStatic = new Societe($this->db);
			$searchSoc = null;

			// Search exactly by name
			$resfetch = $socStatic->fetch(0,$datas['buyer_company']);
			if ($resfetch > 0) {
				$searchSoc = $socStatic->id;
			} else {
				$resfetch = $socStatic->fetch(0,'','','','','','','','','',$datas['buyer_email']);
				if ($resfetch > 0) {
					$searchSoc = $socStatic->id;
				}
			}
			//search on contact
			if ($searchSoc == null) {
				$contact = new Contact($this->db);
				// print "search on contact email with " . $datas['buyer_email'] . "<br />";
				$resfetch = $contact->fetch('', '', '', trim($datas['buyer_email']));
				if ($resfetch > 0) {
					if($resfetch > 1) { // Plusieurs contacts avec cette adresse, donc potentiellement plusieurs tiers, on prend le plus ancien
						$q = 'SELECT s.rowid
								FROM '.MAIN_DB_PREFIX.'societe s
								INNER JOIN '.MAIN_DB_PREFIX.'socpeople sp ON (sp.fk_soc = s.rowid)
								WHERE sp.email = "'.$datas['buyer_email'].'"
								ORDER BY s.rowid ASC
								LIMIT 1';
						$resql = $this->db->query($q);
						if(!empty($resql)) {
							$res = $this->db->fetch_object($resql);
							$searchSoc = $res->rowid;
						}
					} else {
						// note societe class fetch returns 1 on success, not socid
						$resSearch = $socStatic->fetch($contact->socid);  // Retourne -2 si on trouve plusieurs Tiers
						if ($resSearch) {
							$searchSoc = $socStatic->id;
						}
					}
				}
			}
			if(empty($datas['buyer_company'])) {
				++$error;
				array_push($this->errors,  "Erreur recherche client");
			} else {
				if(! empty($searchSoc) && $searchSoc > 0) $socid = $searchSoc;
				else {
					// Customer not found => creation
					// print "customer not found, create it";
					$socid = $dolistorextractActions->newCustomerFromDatas($userStatic, $dolistoreMail);
				}

				if($socid > 0) {

					// Flag to know if we want to send email or not
					$mailToSend = false;

					$socStatic->fetch($socid);
					$listProduct = array();

					// Loop on each product -- 2025 only one per mail
					foreach ($dolistoreMail->items as $product) {
						if(isModEnabled("webhost")) {
							$this->addWebmoduleSales($product, $socid);
						}

					    // Save list of products for email message
					    $listProduct[] = $product['item_name'];

						$catStatic = new Categorie($this->db);
						$foundCatId = 0;


						// Search existant category *by product reference*
						$resCatRef = $dolistorextractActions->searchCategoryDolistore($product['item_reference']);

						if(! $resCatRef) {
							//print 'Pas de catégorie dolistore trouvée pour la ref='.$product['item_reference'].'<br />';
							dol_syslog('No dolistore category found for ref='.$product['item_reference'], LOG_DEBUG);

							// Search existant category *by label*
							$resCatLabel = $catStatic->fetch('', $product['item_name']);
							if($resCatLabel > 0) {
								$foundCatId = $catStatic->id;
								$this->logCat.= "<br />Catégorie trouvée pour ref ".$product['item_reference']." (".$product['item_name'].") : ".$catStatic->getNomUrl(1);
							} else {
								//si c'est un mail avec des produits qui ne sont pas à nous ? -> ce n'est pas une erreur
								//situation délicate !
								// ++$error;
								array_push($this->errors, 'Pas de catégorie trouvée pour la ref='.$product['item_reference']);
							}
						} else {
							$foundCatId = $resCatRef;
							$this->logCat.= "<br />Catégorie dolistore trouvée pour ref ".$product['item_reference']." (".$product['item_name'].") : ".$resCatRef;
						}

						// Category found : continue process
						if($foundCatId) {
							// Retrieve category information
							$catStatic->fetch($foundCatId);

							$exist = $catStatic->containsObject('customer', $socid);
							// Link thirdparty to category
							$catStatic->add_type($socStatic,'customer');

							// Event creation
							$result = $dolistorextractActions->createEventFromExtractDatas($product, $dolistoreMail->order_name, $socid);

							//CAP-REL: Ajout dans l'historique des ventes indirectes
							if (isModEnabled("indirectcustomers")) {
								dol_include_once('/indirectcustomers/class/saleshistory.class.php');
								$sh = new SalesHistory($this->db);
								//Fri, 14 Jun 2024 00:58:53 +0200
								$headerdate = DateTime::createFromFormat( 'D, d M Y H:i:s O', trim($email->header->date));
								$datesell = null;
								if(empty($headerdate) || is_null($headerdate)) {
									$datesell = date('Y-m-d');
								} else {
									$datesell = $headerdate->format( 'Y-m-d');
								}
								dol_syslog("indirect customer date sell = $datesell");
								$sh->addNew($socid, $product['item_name'], $product['item_price'], $product['item_reference'], 'SRC_DOLISTORE', 'DOLISTORE', $datesell, $product['item_name']);
							}

							if ($result > 0) {
								$mailToSend = true;
							}else if ($result == 0) {
								++$mailSent;
							}

						}
					} // End products loop

					/*
					 *  Send mail
					 */

					if(getDolGlobalString('DOLISTOREXTRACT_DISABLE_SEND_THANK_YOU')){
						$mailSent++;
					}
					elseif ($mailToSend && !getDolGlobalString('DOLISTOREXTRACT_DISABLE_SEND_THANK_YOU')) {
						require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
						require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
						$formMail = new FormMail($this->db);

						$from = getDolGlobalString('MAIN_INFO_SOCIETE_NOM') . ' <dolistore@atm-consulting.fr>';
						$sendto = $dolistoreMail->buyer_email;
						$sendtocc = '';
						$sendtobcc = '';
						$trackid = '';
						$deliveryreceipt = 0;
						$trackid = '';

						// EN template by default
						$idTemplate = $conf->global->DOLISTOREXTRACT_EMAIL_TEMPLATE_EN;
						if(preg_match('/fr.*/', $langEmail)) {
							$idTemplate = $conf->global->DOLISTOREXTRACT_EMAIL_TEMPLATE_FR;
						}
						$usedTemplate = $formMail->getEMailTemplate($this->db, 'dolistore_extract', $userStatic, '',$idTemplate);
						$listProductString = implode(', ', $listProduct);
						$arraySubstitutionDolistore = [
								'__DOLISTORE_ORDER_NAME__' => $dolistoreMail->order_name,
								'__DOLISTORE_INVOICE_FIRSTNAME__' => $dolistoreMail->buyer_firstname,
								'__DOLISTORE_INVOICE_COMPANY__' => $dolistoreMail->buyer_company,
								'__DOLISTORE_INVOICE_LASTNAME__' => $dolistoreMail->buyer_lastname,
						        '__DOLISTORE_LIST_PRODUCTS__' => $listProductString
						];

						$subject=make_substitutions($usedTemplate->topic, $arraySubstitutionDolistore);
						$message=make_substitutions($usedTemplate->content, $arraySubstitutionDolistore);


						$mailfile = new CMailFile($subject, $sendto, $from, $message, array(), array(), array(), $sendtocc, $sendtobcc, $deliveryreceipt, -1, '', '', $trackid);


						if ($mailfile->error)
						{
							++$error;
							dol_syslog('Dolistorextract::mail:' .$mailfile->error, LOG_ERROR);

						}
						else
						{
							$result=$mailfile->sendfile();
							if ($result)
							{
								++$mailSent;
							}
						}
					}


				} else {
					++$error;
					array_push($this->errors, 'No societe found for email '.$email->header->uid);
				}
			}
		} else {
			++$error;
			array_push($this->errors, 'No data for email '.$email->header->uid);
		}
		if ($error) {
			$this->nbErrors += $error;
			return -1 * $error;
		} else {
			return $mailSent;
		}
	}

	/**
	 * Adds a web module sale to the database.
	 *
	 * @param array $TItemDatas Array containing the item data (price, quantity, reference).
	 * @param int $socid ID of the company associated with the sale.
	 * @return int Returns the ID of the created sale or <= 0 in case of failure.
	 */
	public function addWebmoduleSales(array $TItemDatas, int $socid): int {
		global $user, $error;

		// Include the Webmodulesales class
		dol_include_once('/webhost/class/webmodulesales.class.php');

		// Instantiate a new Webmodulesales object
		$webSales = new Webmodulesales($this->db);

		// Get the web module ID based on the Dolistore ID
		$fk_webmodule = $this->getWebmoduleIdByDolistoreId($TItemDatas['item_reference'] ?? '');

		// Check if a corresponding web module was found
		if ($fk_webmodule > 0) {
			// Convert the price to float and assign the data to the $webSales object
			$webSales->amount = $this->convertToFloat($TItemDatas['item_price'] ?? 0);
			$webSales->qty = $TItemDatas['item_quantity'] ?? 1;  // Default value if not specified
			$webSales->fk_soc = $socid;
			$webSales->import_key = date('Ymd');  // Generate import key with current date
			$webSales->fk_webmodule = $fk_webmodule;
			$webSales->date_sale = $TItemDatas['date_sale'] ?? dol_now() ;  // Current date for the sale
			$webSales->status = !empty($TItemDatas['item_refunded']) ? WebModuleSales::STATUS_REFUNDED : Webmodulesales::STATUS_SOLD;

			// Create the sale and check the result
			$res = $webSales->create($user);
			if ($res <= 0) {
				// If creation fails, log the error and add it to the error array
				$this->logError('Unable to create web sale: ' . $webSales->error. ' '.implode(' - ', $webSales->errors));
			}

			// Return the ID of the created sale if successful
			return $res;
		}
		// If no web module is found, log the error and add it to the error array
		$this->logError('No web module found for fk_dolistore=' . ($TItemDatas['item_reference'] . ' '.  $TItemDatas['item_name']));


		// Return 0 if no web module was found
		return 0;
	}

	/**
	 * Retrieves the web module ID based on the Dolistore ID.
	 *
	 * @param string $fk_dolistore Dolistore ID.
	 * @return int Web module ID or 0 if not found.
	 */
	public function getWebmoduleIdByDolistoreId(string $fk_dolistore): int {
		// Build SQL query to get the web module ID
		$sql = /** @lang SQL */
			'SELECT DISTINCT w.rowid
            FROM ' . $this->db->prefix() . 'webmodule as w
                INNER JOIN ' . $this->db->prefix() . 'webmodule_version wv ON w.rowid = wv.fk_webmodule
                INNER JOIN ' . $this->db->prefix() . 'webmodule_version_extrafields wve ON wv.rowid = wve.fk_object
            WHERE wve.iddolistore = "' . $this->db->escape($fk_dolistore) . '"';

		// Execute the query
		$resql = $this->db->query($sql);

		// Check if the query failed
		if (! $resql) {
			return 0;  // Return 0 if no result was found
		}

		// Extract the result
		$obj = $this->db->fetch_object($resql);

		// Return the web module ID or 0 if not found
		return $obj->rowid ?? 0;
	}

	/**
	 * Converts a formatted string representing a monetary amount to a float.
	 *
	 * @param string $formattedString The formatted amount (e.g., '2 356 156,00 €').
	 * @return float The converted float value (e.g., 2356156.00).
	 */
	public function convertToFloat(string $formattedString): float {
		//Espace insécable
		$formattedString = str_replace("\xC2\xA0", " ", $formattedString);  // Remplace les espaces insécables par des espaces réguliers

		// Step 1: Validate the input (checks if the string contains digits, commas, and potentially a currency symbol)
		if (! preg_match('/^[\d\s,.€]+$/', $formattedString)) {
			$this->logError('Invalid number format: ' . $formattedString);
		}

		// Step 2: Remove the euro symbol and thousand separators
		$cleanString = str_replace(['€', ' '], '', $formattedString);

		// Step 3: Replace the decimal comma with a dot for PHP's float notation
		$cleanString = str_replace(',', '.', $cleanString);

		// Step 4: Convert the cleaned string to float
		$floatValue = (float) $cleanString;

		// Return the converted float value
		return $floatValue;
	}

	/**
	 * Logs an error message, adds it to the error array, and increments the error count.
	 *
	 * @param string $message The error message to log.
	 * @return void
	 */
	private function logError(string $message): void {
		global $error;
		dol_syslog(__METHOD__ . ' - ' . $message, LOG_ERR);
		$this->errors[] = $message;
		$this->logCat .= $message;
		$error++;
	}



}
