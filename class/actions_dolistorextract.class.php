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
 * Class ActionsDolistorextract
 *
 * Provides hooks and main processing logic for the Dolistore Extract Dolibarr module.
 * Handles automated extraction of sales/orders from Dolistore emails and integration in Dolibarr (thirdparties, contacts, events, webmodule sales).
 */
class ActionsDolistorextract extends CommonHookActions
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
	 * Hook: Provides additional info to the email element list.
	 *
	 * @param array        $parameters  Parameters from hook manager
	 * @param object       $object      Current Dolibarr object
	 * @param string       $action      Current action code
	 * @param HookManager  $hookmanager Hook manager instance
	 * @return int 0 if OK, -1 if error
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
	 * Creates a new thirdparty/customer from extracted Dolistore email data.
	 *
	 * @param User          $user           User used to create the thirdparty
	 * @param dolistoreMail $dolistoreMail  Mail object with extracted customer fields
	 * @return int                          Thirdparty rowid, or -1 if creation failed
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
		$module = (getDolGlobalString('SOCIETE_CODECLIENT_ADDON') ? getDolGlobalString('SOCIETE_CODECLIENT_ADDON') : 'mod_codeclient_leopard');
		if (substr($module, 0, 15) == 'mod_codeclient_' && substr($module, -3) == 'php') {
			$module = substr($module, 0, dol_strlen($module) - 4);
		}
		$dirsociete = array_merge(array('/core/modules/societe/'), $conf->modules_parts['societe']);
		foreach ($dirsociete as $dirroot) {
			$res = dol_include_once($dirroot . $module . '.php');
			if ($res) break;
		}
		$modCodeClient = new $module($this->db);

		$socStatic->code_client = $modCodeClient->getNextValue($socStatic, 0);
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
		if (!empty($dolistoreMail->buyer_idprof2)) {
			$buyer_idprof2_clean = preg_replace('/\D/', '', $dolistoreMail->buyer_idprof2); // on enlève tout sauf chiffres si besoin
			if (strlen($buyer_idprof2_clean) > 9) {
				$socStatic->idprof1 = substr($buyer_idprof2_clean, 0, 9);
			}
		}
		$socStatic->idprof2 = $dolistoreMail->buyer_idprof2;
		$socStatic->tva_intra = $dolistoreMail->buyer_intravat;

		// Le champ buyer_country_code contient BE/FR/DE...
		$resql = $this->db->query('SELECT rowid as fk_country FROM ' . $this->db->prefix() . "c_country WHERE code = '" . $this->db->escape($dolistoreMail->buyer_country_code) . "'");
		if ($resql) {
			if (($obj = $this->db->fetch_object($resql)) && $this->db->num_rows($resql) == 1) $socStatic->country_id = $obj->fk_country;
		}

		$socStatic->array_options["options_provenance"] = "INT";
		$socStatic->import_key = "STORE";

		$socStatic->client = 2; // Prospect / client
		$socid = $socStatic->create($user);
		$this->logOutput .= '<br/>-> <span class="ok"> Thirdparty created: ' . $dolistoreMail->buyer_company . ' (ID: '.$socStatic->id.')';

		if ($socid > 0) {
			$res = $socStatic->create_individual($user);
			$this->logOutput .= '<br/>-> Contact created: ' . $socStatic->firstname . ' ' . $socStatic->lastname . ' (ID: '.$socStatic->id.')';

		} else if (is_array($socStatic->errors)) {
			$this->errors = array_merge($this->errors, $socStatic->errors);
		}
		return $socid;
	}

	/**
	 * Creates a Dolibarr calendar event (actioncomm) for a sold product extracted from Dolistore mail.
	 * Detects and avoids duplicate events based on a tag in the event note.
	 *
	 * @param array  $productDatas  Product/item array (extracted from email)
	 * @param string $orderRef      Dolistore order reference
	 * @param int    $socid         Thirdparty/Customer rowid
	 * @return int|false            New event rowid, 0 if already exists, -1 if error
	 */
	public function createEventFromExtractDatas($productDatas, $orderRef, $socid)
	{
		global $conf, $langs;

		// Check value
		if (empty($orderRef) || empty($productDatas['item_reference'])) {
			dol_syslog(__METHOD__ . ' Error : params order_name and product_ref missing');
			return -1;
		}

		$res = 0;

		$userStatic = new User($this->db);
		$userStatic->fetch(getDolGlobalInt('DOLISTOREXTRACT_USER_FOR_ACTIONS'));

		require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
		$actionStatic = new ActionComm($this->db);

		$actionStatic->socid = $socid;

		$actionStatic->authorid = getDolGlobalInt('DOLISTOREXTRACT_USER_FOR_ACTIONS');
		$actionStatic->userownerid = getDolGlobalInt('DOLISTOREXTRACT_USER_FOR_ACTIONS');

		$actionStatic->datec = time();
		$actionStatic->datem = time();
		$actionStatic->datep = time();
		$actionStatic->percentage = 100;

		$actionStatic->type_code = 'AC_STRXTRACT';
		$actionStatic->label = $langs->trans('DolistorextractLabelActionForSale', $productDatas['item_name'] . ' (' . $productDatas['item_reference'] . ')');
		// Define a tag which allow to detect twice
		$actionStatic->note = 'ORDER:' . $orderRef . ':' . $productDatas['item_reference'];
		// Check if import already done
		if (! $this->isAlreadyImported($actionStatic->note)) {
			$res = $actionStatic->create($userStatic);
		}

		return $res;
	}

	/**
	 * Checks if an event has already been imported, by searching for a specific tag in note field.
	 *
	 * @param string $noteString Tag/note to search (e.g., 'ORDER:...:...')
	 * @return int|false Rowid if exists, false if not, -1 if error
	 */
	private function isAlreadyImported($noteString)
	{
		$sql = "SELECT id FROM " . $this->db->prefix() . "actioncomm WHERE note='" . $this->db->escape($noteString) . "'";

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
	 * Main CRON job: connect to IMAP, fetch emails, launch processing, update mailbox status.
	 * Orchestrates the full import process.
	 *
	 * @return int Positive number of sales if success, negative number of errors, or 0 if no email
	 */
	public function launchCronJob()
	{
		global $langs, $conf;

		$this->nbErrors = 0;
		$langs->load('main');

		$mailbox = getDolGlobalString('DOLISTOREXTRACT_IMAP_SERVER');
		$username = getDolGlobalString('DOLISTOREXTRACT_IMAP_USER');
		$password = getDolGlobalString('DOLISTOREXTRACT_IMAP_PWD');
		$encryption = Imap::ENCRYPT_SSL;

		// Open connection
		try {
			$imap = new Imap($mailbox, $username, $password, $encryption);
		} catch (ImapClientException $error) {
			$this->errors[] = $error->getMessage() . PHP_EOL;
			return -1;
		}

		// Select the folder Inbox
		$imap->selectFolder(getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER') ? getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER') : 'INBOX');

		// Fetch all the messages in the current folder
		$emails = $imap->getMessages();
		$this->logOutput .= '<br/><strong>Mail to process</strong>: ' . count($emails);

		if (getDolGlobalString('DOLISTOREXTRACT_DISABLE_SEND_THANK_YOU')) {
			$this->logOutput .= '<br/><strong class="error">Mail send disabled</strong>';
		}

		// Filter only unread Dolistore emails
		$dolistoreEmails = [];
		foreach ($emails as $email) {
			if (strpos($email->header->subject, 'DoliStore') > 0 && !$email->header->seen) {
				$dolistoreEmails[] = $email;
			}
		}

		if (empty($dolistoreEmails)) {
			$this->logOutput .= '<br/>No unread Dolistore email found.';
			return 0;
		}

		$this->logOutput .= '<br/><strong>Dolistore emails to process</strong>: ' . count($dolistoreEmails);

		// Process all emails at once
		$result = $this->launchImportProcess($dolistoreEmails);

		// Process results by order reference
		if (is_array($result)) {
			// Organize emails by order reference
			$emailsByOrderRef = [];
			foreach ($dolistoreEmails as $email) {
				$dolistoreMailExtract = new \dolistoreMailExtract($this->db, $email->message->text);
				$data = $dolistoreMailExtract->extractAllDatas();

				if (!empty($data) && !empty($data['order_ref'])) {
					$orderRef = $data['order_ref'];
					if (!isset($emailsByOrderRef[$orderRef])) {
						$emailsByOrderRef[$orderRef] = [];
					}
					$emailsByOrderRef[$orderRef][] = $email;
				} else {
					// Email not associated with any valid order, move to error folder
					if (getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR')) {
						$moveResult = $imap->moveMessage($email->header->uid, getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR'));
						if (!$moveResult) {
							$this->logOutput .= '<br/>Error moving message ' . $email->header->uid . ' TO ' . getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR');
						}
					}
				}
			}

			// Process emails by order reference
			$successCount = 0;
			$errorCount = 0;

			foreach ($result as $orderRef => $orderSuccess) {
				if (isset($emailsByOrderRef[$orderRef])) {
					if ($orderSuccess) {
						// Order processed successfully
						$successCount++;
						foreach ($emailsByOrderRef[$orderRef] as $email) {
							$imap->setSeenMessage($email->header->msgno, true);
							if (getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE')) {
								$moveResult = $imap->moveMessage($email->header->uid, getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE'));
								if (!$moveResult) {
									$this->logOutput .= '<br/>Error moving message ' . $email->header->uid . ' TO ' . getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE');
								}
							}
						}
					} else {
						// Order processing failed, move all related emails to error folder
						$errorCount++;
						foreach ($emailsByOrderRef[$orderRef] as $email) {
							if (getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR')) {
								$moveResult = $imap->moveMessage($email->header->uid, getDolGlobalString('DOLISTOREEXTRACT_IMAP_FOLDER_ERROR'));
								if (!$moveResult) {
									$this->logOutput .= '<br/>Error moving message ' . $email->header->uid . ' TO ' . getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR');
								}
							}
						}
						$this->logOutput .= '<br/>-> <strong class="error">Order ' . $orderRef . ' processing failed</strong>';
					}
				}
			}

			// Return overall result
			if ($errorCount > 0) {
				return -$errorCount;
			} else {
				return $successCount;
			}
		} else {
			// Fallback to the old integer result format
			if ($result < 0) {
				$this->logOutput .= '-> <strong class="error">FAIL</strong>';

				// Move all emails to error folder as we can't determine which ones succeeded
				foreach ($dolistoreEmails as $email) {
					if (getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR')) {
						$moveResult = $imap->moveMessage($email->header->uid, getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR'));
						if (!$moveResult) {
							$this->logOutput .= '<br/>Error moving message ' . $email->header->uid . ' TO ' . getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ERROR');
						}
					}
				}
				return $result;
			} else {
				// Mark all emails as read and archive them
				foreach ($dolistoreEmails as $email) {
					$imap->setSeenMessage($email->header->msgno, true);
					if (getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE')) {
						$moveResult = $imap->moveMessage($email->header->uid, getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE'));
						if (!$moveResult) {
							$this->logOutput .= '<br/>Error moving message ' . $email->header->uid . ' TO ' . getDolGlobalString('DOLISTOREXTRACT_IMAP_FOLDER_ARCHIVE');
						}
					}
				}
				return $result;
			}
		}
	}

	/**
	 * Core integration logic: reads emails, extracts orders, creates customers, contacts, events, and sales.
	 *
	 * @param array $emails Array of Dolistore IMAP emails (SSilence\ImapClient\Message[])
	 * @return array|int    Array of order_ref => success(bool) or int <0 if failure
	 */
	public function launchImportProcess($emails)
	{

		global $conf, $error;
		dol_syslog(__METHOD__ . ' launch import process for ' . count($emails) . ' messages', LOG_DEBUG);

		$error = 0;
		$emailsSent = 0;

		// Loading necessary classes
		if (!class_exists('Societe')) {
			require_once(DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php');
		}
		if (!class_exists('Contact')) {
			require_once(DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php');
		}
		if (!class_exists('Categorie')) {
			require_once(DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php');
		}
		if (!class_exists('dolistoreMailExtract')) {
			dol_include_once('/dolistorextract/class/dolistoreMailExtract.class.php');
		}
		if (!class_exists('dolistoreMail')) {
			dol_include_once('/dolistorextract/class/dolistoreMail.class.php');
		}

		$user = new \User($this->db);
		$user->fetch(getDolGlobalInt('DOLISTOREXTRACT_USER_FOR_ACTIONS'));

		// Arrays to organize data
		$orderData = []; // Array of data by order
		$emailsByOrderRef = []; // Emails associated with each order

		// 1. Loop through unread emails from the inbox
		if (!empty($emails)) {
			foreach ($emails as $email) {

				// Only mails from Dolistore and not seen
				if (strpos($email->header->subject, 'DoliStore') > 0 && !$email->header->seen) {
					$this->logOutput .= '<br/><strong>Processing email:</strong> ' . $email->header->subject;

					// Data extraction
					$dolistoreMailExtract = new \dolistoreMailExtract($this->db, $email->message->text);
					$emailLanguage = $dolistoreMailExtract->detectLang($email->header->subject);
					$data = $dolistoreMailExtract->extractAllDatas();

					// 2. Extract data by order
					if (!empty($data) && !empty($data['order_ref']) && !empty($data['buyer_email'])) {
						$orderRef = $data['order_ref'];

						$this->logOutput .= '<br/>-> <span class="ok">Data extracted successfully for order <b>' . $orderRef . '</b></span>';

						// Initialize the order if it doesn't exist yet
						if (!isset($orderData[$orderRef])) {
							$orderData[$orderRef] = [
								'buyer_data' => $data,
								'items' => [],
								'lang' => $emailLanguage
							];
							$emailsByOrderRef[$orderRef] = [];
						}

						// Check if items array exists and has elements
						if (!empty($data['items']) && is_array($data['items'])) {
							// Loop through each item in the order
							foreach ($data['items'] as $item) {
								if (!empty($item['item_reference']) && !empty($item['item_name'])) {

									// Add the product to the order
									$orderData[$orderRef]['items'][] = [
										'item_reference' => $item['item_reference'],
										'item_name' => $item['item_name'],
										'item_price' => $item['item_price'],
										'item_quantity' => $item['item_quantity'],
										'item_price_total' => $item['item_price_total']
									];

									$this->logOutput .= '<br/>-- <span class="ok">Product extracted: ' . $item['item_name'] . '</span>';
								} else {
									$this->logOutput .= '<br/>-- <strong class="error">Incomplete product data, skipping item</strong>';
								}
							}
						}else{
							$this->logOutput .= '<br/>-- <strong class="warning">No items found in this order</strong>';
						}

						// Associate this email with the order
						$emailsByOrderRef[$orderRef][] = $email;
					} else {
						$this->logOutput .= '<br/>-> <strong class="error">Failed to extract data from email: missing order_ref or buyer_email</strong>';
					}
				}
			}

		}
		else{
			$this->logOutput .= '<br/><span class="warning">No emails to process (IMAP inbox is empty)</span>';
		}

		// Arrays to store processing results
		$orderResults = []; // Store success/failure by order reference

		// Start global transaction
		$this->db->begin();

		if (!empty($orderData)) {
			// 3. Loop through orders
			foreach ($orderData as $orderRef => $orderDetails) {

				$this->logOutput .= '<br/><strong>Processing order:</strong> ' . $orderRef;
				$orderError = 0;

				// Prepare customer data
				$dolistoreMail = new \dolistoreMail();
				$dolistoreMail->setDatas($orderDetails['buyer_data']);
				$dolistoreMail->items = $orderDetails['items'];

				// 3.1 Search for / create third party / contact
				$company = new Societe($this->db);
				$companySearch = null;

				// Search exactly by name
				$fetchResult = $company->fetch(0, $orderDetails['buyer_data']['buyer_company']);

				if ($fetchResult > 0) {
					$companySearch = $company->id;
				} else {
					$fetchResult = $company->fetch(0, '', '', '', '', '', '', '', '', '', $orderDetails['buyer_data']['buyer_email']);
					if ($fetchResult > 0) {
						$companySearch = $company->id;
					}
				}

				// Search on contact
				if ($companySearch == null) {
					$contact = new Contact($this->db);
					$fetchResult = $contact->fetch('', '', '', trim($orderDetails['buyer_data']['buyer_email']));

					if ($fetchResult > 0) {
						if ($fetchResult > 1) { // Multiple contacts with this address
							$q = 'SELECT s.rowid
								FROM ' . $this->db->prefix() . 'societe s
								INNER JOIN ' . $this->db->prefix() . 'socpeople sp ON (sp.fk_soc = s.rowid)
								WHERE sp.email = "' . strval($orderDetails['buyer_data']['buyer_email']) . '"
								ORDER BY s.rowid ASC
								LIMIT 1';
							$queryResult = $this->db->query($q);

							if (!empty($queryResult)) {
								$res = $this->db->fetch_object($queryResult);
								$companySearch = $res->rowid;
								$this->logOutput .= '<br/>-> <span class="warning">Multiple contacts found, for company ID: <b>' . $companySearch . '</b></span>';

							}
						} else {
							$searchResult = $company->fetch($contact->socid);
							if ($searchResult) {
								$companySearch = $company->id;
								$this->logOutput .= '<br/>-> <span class="ok">Company found by contact: <b>' . dol_escape_htmltag($company->name) . '</b></span>';

							}
						}
					} else {
						$this->logOutput .= '<br/>-> <span class="error">No matching company or contact found</span>';
					}
				}

				if (empty($orderDetails['buyer_data']['buyer_company'])) {
					++$orderError;
					$this->logOutput .= '<br/>-> <span class="error">No company name in order data, cannot proceed</span>';
					array_push($this->errors, "Error searching for customer for order " . $orderRef);
				} else {

					if (!empty($companySearch) && $companySearch > 0) {
						$companyId = $companySearch;

						// Check if a contact with this email exists for this company
						$contact = new Contact($this->db);
						$sql = "SELECT rowid FROM " . $this->db->prefix() . "socpeople
								WHERE fk_soc = " . $companyId . "
								AND email = '" . $this->db->escape($orderDetails['buyer_data']['buyer_email']) . "'";
						$resql = $this->db->query($sql);

						// If no contact exists, create one
						if ($resql && $this->db->num_rows($resql) == 0) {
							$contact->socid = $companyId;
							$contact->lastname = $orderDetails['buyer_data']['buyer_lastname'];
							$contact->firstname = $orderDetails['buyer_data']['buyer_firstname'];
							$contact->email = $orderDetails['buyer_data']['buyer_email'];
							$contact->address = $orderDetails['buyer_data']['buyer_address1'];
							$contact->zip = $orderDetails['buyer_data']['buyer_postal_code'];
							$contact->town = $orderDetails['buyer_data']['buyer_city'];
							$contact->country_code = $orderDetails['buyer_data']['buyer_country_code'];

							$result = $contact->create($user);
							if ($result < 0) {
								$orderError++;
								$this->logOutput .= '<br/>-> <span class="error">Failed to create contact for company <b>' . dol_escape_htmltag($company->name) . '</b></span>';
							} else {
								$this->logOutput .= '<br/>-> <span class="ok">Contact created for existing company: <b>' . dol_escape_htmltag($contact->lastname) . ' ' . dol_escape_htmltag($contact->firstname) . '</b></span>';
							}
						} else {
							$this->logOutput .= '<br/>-> <span class="ok">Contact found for this company</span>';
						}
					} else {
						// Customer not found => creation
						$companyId = $this->newCustomerFromDatas($user, $dolistoreMail);
					}

					if ($companyId > 0) {
						$company->fetch($companyId);
						$productList = [];

						// 4. Loop through modules
						foreach ($orderDetails['items'] as $product) {
							// 4.1 Search for / create calendar event
							$result = $this->createEventFromExtractDatas($product, $orderRef, $companyId);

							if ($result <= 0 && $result != 0) { // 0 means already imported
								$orderError++;
								$this->logOutput .= '<br/>-> <span class="error">Failed to create event for product: <b>' . dol_escape_htmltag($product['item_name']) . '</b></span>';
							} elseif ($result == 0) {
								$this->logOutput .= '<br/>-> <span class="warning">Event already exists for product: <b>' . dol_escape_htmltag($product['item_name']) . '</b></span>';
							} else {
								$this->logOutput .= '<br/>-> <span class="ok">Event created for product: <b>' . dol_escape_htmltag($product['item_name']) . '</b></span>';
							}

							// 4.2 Search for / create webmodule sale
							if (isModEnabled("webhost")) {
								$webmoduleResult = $this->addWebmoduleSales($product, $companyId);
								if ($webmoduleResult <= 0) {
									$orderError++;
									$this->logOutput .= '<br/>-> <span class="error">Failed to create webmodule sale for <b>' . dol_escape_htmltag($product['item_name']) . '</b></span>';
								} else {
									$this->logOutput .= '<br/>-> <span class="ok">Webmodule sale created for <b>' . dol_escape_htmltag($product['item_name']) . '</b></span>';
								}
							}

							// Save list of products for email message
							$productList[] = $product['item_name'];
						}

						// Define emailToSend variable based on successful processing
						$emailToSend = ($orderError == 0 && !empty($productList));

						/*
						*  Send mail
						*/
						if (getDolGlobalString('DOLISTOREXTRACT_DISABLE_SEND_THANK_YOU')) {
							$emailsSent++;
						} elseif ($emailToSend && !getDolGlobalString('DOLISTOREXTRACT_DISABLE_SEND_THANK_YOU')) {
							require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
							require_once DOL_DOCUMENT_ROOT . '/core/class/html.formmail.class.php';
							$formMail = new FormMail($this->db);

							$from = getDolGlobalString('MAIN_INFO_SOCIETE_NOM') . ' <dolistore@atm-consulting.fr>';
							$sendTo = $dolistoreMail->buyer_email;
							$sendToCc = '';
							$sendToBcc = '';
							$trackid = '';
							$deliveryreceipt = 0;

							// EN template by default
							$templateId = getDolGlobalInt('DOLISTOREXTRACT_EMAIL_TEMPLATE_EN');
							if (preg_match('/fr.*/', $orderDetails['lang'])) {
								$templateId = getDolGlobalInt('DOLISTOREXTRACT_EMAIL_TEMPLATE_FR');
							}

							$usedTemplate = $formMail->getEMailTemplate($this->db, 'dolistore_extract', $user, '', $templateId);
							$productListString = implode(', ', $productList);
							$arraySubstitutionDolistore = [
								'__DOLISTORE_ORDER_NAME__' => $dolistoreMail->order_name,
								'__DOLISTORE_INVOICE_FIRSTNAME__' => $dolistoreMail->buyer_firstname,
								'__DOLISTORE_INVOICE_COMPANY__' => $dolistoreMail->buyer_company,
								'__DOLISTORE_INVOICE_LASTNAME__' => $dolistoreMail->buyer_lastname,
								'__DOLISTORE_LIST_PRODUCTS__' => $productListString
							];

							$subject = make_substitutions($usedTemplate->topic, $arraySubstitutionDolistore);
							$message = make_substitutions($usedTemplate->content, $arraySubstitutionDolistore);

							$emailFile = new CMailFile($subject, $sendTo, $from, $message, array(), array(), array(), $sendToCc, $sendToBcc, $deliveryreceipt, -1, '', '', $trackid);

							if ($emailFile->error) {
								++$error;
								$this->logOutput .= '<br/><span class="error">Erreur lors de la création de l\'email : ' . $emailFile->error . '</span>';
								dol_syslog('Dolistorextract::mail:' . $emailFile->error, LOG_ERR);
							} else {
								$result = $emailFile->sendfile();
								if ($result) {
									++$emailsSent;
									$this->logOutput .= '<br/><span class="ok">Email de remerciement envoyé à ' . dol_escape_htmltag($sendTo) . '</span>';
								} else {
									$this->logOutput .= '<br/><span class="error">Échec de l\'envoi du mail à ' . dol_escape_htmltag($sendTo) . '</span>';
								}
							}
						}
					} else {
						++$orderError;
						$this->logOutput .= '<br/>-> <span class="error">Failed to create new company</span>';
						array_push($this->errors, 'No company found for order ' . $orderRef);
					}
				}

				// Store the result for this order
				$orderResults[$orderRef] = ($orderError == 0);
				if ($orderError > 0) {
					$this->logOutput .= '<br/><span class="error">Order <b>' . $orderRef . '</b> processed with errors</span>';
					$error++;
				} else {
					$this->logOutput .= '<br/><span class="ok">Order <b>' . $orderRef . '</b> processed successfully</span>';
				}
			}

			// Commit or rollback based on overall success
			if ($error) {
				$this->db->rollback();
				$this->nbErrors += $error;
			} else {
				$this->db->commit();
			}

			// Return the results by order reference
			return $orderResults;
		} else {
			++$error;
			$this->logOutput .= '<br/><strong class="error">No valid order data extracted from emails</strong>';
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Adds a Dolibarr webmodule sale to the database, based on extracted product data and customer.
	 *
	 * @param array $TItemDatas Array containing item data (reference, name, price, quantity, etc.)
	 * @param int   $socid      Customer rowid
	 * @return int  ID of the created sale, or <=0 if failed
	 */
	public function addWebmoduleSales(array $TItemDatas, int $socid): int
	{
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
			$webSales->date_sale = $TItemDatas['date_sale'] ?? dol_now();  // Current date for the sale
			$webSales->status = !empty($TItemDatas['item_refunded']) ? WebModuleSales::STATUS_REFUNDED : Webmodulesales::STATUS_SOLD;

			// Create the sale and check the result
			$res = $webSales->create($user);
			if ($res <= 0) {
				// If creation fails, log the error and add it to the error array
				$this->logError('Unable to create web sale: ' . $webSales->error . ' ' . implode(' - ', $webSales->errors));
			}

			// Return the ID of the created sale if successful
			return $res;
		}
		// If no web module is found, log the error and add it to the error array
		$this->logError('No web module found for fk_dolistore=' . ($TItemDatas['item_reference'] . ' ' .  $TItemDatas['item_name']));


		// Return 0 if no web module was found
		return 0;
	}

	/**
	 * Retrieves the webmodule rowid from Dolistore ID (using extrafields linkage).
	 *
	 * @param string $fk_dolistore Dolistore product reference
	 * @return int                 Webmodule rowid, or 0 if not found
	 */
	public function getWebmoduleIdByDolistoreId(string $fk_dolistore): int
	{
		// Build SQL query to get the web module ID
		$sql =
			/** @lang SQL */
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
	public function convertToFloat(string $formattedString): float
	{
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
	 * @param string $message The error message to log
	 * @return void
	 */
	private function logError(string $message): void
	{
		global $error;
		dol_syslog(__METHOD__ . ' - ' . $message, LOG_ERR);
		$this->errors[] = $message;
		$this->logCat .= $message;
		$error++;
	}
}
