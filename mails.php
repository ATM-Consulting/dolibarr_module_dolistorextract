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
 *   	\file       mails.php
 *		\ingroup    dolistorextract
 *		\brief      Show dolistore email
 */

//if (! defined('NOREQUIREUSER'))  define('NOREQUIREUSER','1');
//if (! defined('NOREQUIREDB'))    define('NOREQUIREDB','1');
//if (! defined('NOREQUIRESOC'))   define('NOREQUIRESOC','1');
//if (! defined('NOREQUIRETRAN'))  define('NOREQUIRETRAN','1');
//if (! defined('NOCSRFCHECK'))    define('NOCSRFCHECK','1');			// Do not check anti CSRF attack test
//if (! defined('NOSTYLECHECK'))   define('NOSTYLECHECK','1');			// Do not check style html tag into posted data
//if (! defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL','1');		// Do not check anti POST attack test
//if (! defined('NOREQUIREMENU'))  define('NOREQUIREMENU','1');			// If there is no need to load and show top and left menu
//if (! defined('NOREQUIREHTML'))  define('NOREQUIREHTML','1');			// If we don't need to load the html.form.class.php
//if (! defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX','1');
//if (! defined("NOLOGIN"))        define("NOLOGIN",'1');				// If this page is public (can be called outside logged session)

// Change this following line to use the correct relative path (../, ../../, etc)
$res=0;
if (! $res && file_exists("../main.inc.php")) $res=@include '../main.inc.php';					// to work if your module directory is into dolibarr root htdocs directory
if (! $res && file_exists("../../main.inc.php")) $res=@include '../../main.inc.php';			// to work if your module directory is into a subdir of root htdocs directory
if (! $res && file_exists("../../../dolibarr/htdocs/main.inc.php")) $res=@include '../../../dolibarr/htdocs/main.inc.php';     // Used on dev env only
if (! $res && file_exists("../../../../dolibarr/htdocs/main.inc.php")) $res=@include '../../../../dolibarr/htdocs/main.inc.php';   // Used on dev env only
if (! $res) die("Include of main fails");
// Change this following line to use the correct relative path from htdocs
include_once(DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php');
include_once(DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php');
include_once(DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php');
include_once(DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php');

include_once 'class/actions_dolistorextract.class.php';
include_once 'class/dolistoreMail.class.php';
include_once 'class/dolistoreMailExtract.class.php';

dol_include_once("/dolistorextract/include/ssilence/php-imap-client/autoload.php");

use SSilence\ImapClient\ImapClientException;
use SSilence\ImapClient\ImapConnect;
use SSilence\ImapClient\ImapClient as Imap;

// Load traductions files requiredby by page
$langs->load("dolistorextract@dolistorextract");
$langs->load("other");

// Get parameters
$id			= GETPOST('id', 'int');
$action		= GETPOST('action','alpha');
$cancel     = GETPOST('cancel');
$view       = GETPOST('view');



if (empty($action) && empty($id) && empty($ref)) $action='view';

// Protection if external user
if ($user->societe_id > 0 || ! $user->hasRight('dolistorextract', 'read'))
{
	accessforbidden();
}


$extrafields = new ExtraFields($db);

// fetch optionals attributes and labels
//$extralabels = $extrafields->fetch_name_optionals_label($object->table_element);

// Load object
//include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php';  // Must be include, not include_once  // Must be include, not include_once. Include fetch and fetch_thirdparty but not fetch_optionals

// Initialize technical object to manage hooks of modules. Note that conf->hooks_modules contains array array
$hookmanager->initHooks(array('dolistoremail'));



/*******************************************************************
* ACTIONS
*
* Put here all code to do according to value of "action" parameter
********************************************************************/

$parameters=array();
$reshook=$hookmanager->executeHooks('doActions',$parameters,$object,$action);    // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook))
{

}




/***************************************************
* VIEW
*
* Put here all code to build page
****************************************************/

llxHeader('', $langs->trans('DolistoreMailsList'),'');

$form=new Form($db);

$mailbox = getDolGlobalString('DOLISTOREXTRACT_IMAP_SERVER');
$username = getDolGlobalString('DOLISTOREXTRACT_IMAP_USER');
$password = getDolGlobalString('DOLISTOREXTRACT_IMAP_PWD');
$encryption = Imap::ENCRYPT_SSL;

// Open connection
try{
	$imap = new Imap($mailbox, $username, $password, $encryption);
	// You can also check out example-connect.php for more connection options

}catch (ImapClientException $error){
	echo $error->getMessage().PHP_EOL;
	die(); // Oh no :( we failed
}

// Select the folder Inbox
$imap->selectFolder('INBOX');

/*
 * Import des données du message
 */
if ($action == 'import') {
	$email = $imap->getMessage((int) $id);

	$dolistorextractActions = new \ActionsDolistorextract($db);
	$res = $dolistorextractActions->launchImportProcess($email);
	$action = 'read';
}

/*
 * Display selected message
 */
if ($action == 'read') {
	print load_fiche_titre($langs->trans('DolistoreMailShow'));

	$email = $imap->getMessage((int) $id);

	if ($view == 'plain') {
		print '<pre>';
		print $email->message->plain;
		print '</pre>';
	}
	if ($view == 'html') {
		print $email->message->html;
	}
	$socStatic = new Societe($db);
	$formMail = new FormMail($db);


	$dolistoreMailExtract = new \dolistoreMailExtract($db, $email->message->html);
	$datas = $dolistoreMailExtract->extractAllDatas();
	$langEmail = $dolistoreMailExtract->detectLang($email->header->subject);

	$dolistoreMail = new \dolistoreMail();
	$dolistorextractActions = new \ActionsDolistorextract($db);

	$dolistoreMail->setDatas($datas);

	// Search exactly by name
	$filterSearch = array();

	$searchSoc = $socStatic->fetch('', $datas['invoice_company']);  // Retourne -2 si on trouve plusieurs Tiers

	if($searchSoc < 0) {
		print "Erreur recherche client";

	} else {
		print 'Client trouvé : '.$socStatic->getNomUrl(1).'<br />';
	}
	$listProduct = array();
	// Category management
	foreach ($dolistoreMail->items as $product) {
	    // Save list of products for email message
	    $listProduct[] = $product['item_name'];

		$foundCatId = 0;
		$resultCat = $dolistorextractActions->searchCategoryDolistore($product['item_reference']);
		if(! $resultCat) {
			//echo 'Pas de catégorie dolistore trouvée pour la ref='.$product['item_reference'].'<br />';
			dol_syslog('No category found for ref='.$product['item_reference'], LOG_WARNING);

			// Search by label
			$catStatic = new Categorie($db);
			$resLabel = $catStatic->fetch('', $product['item_name']);
			if($resLabel > 0) {
				$foundCatId = $catStatic->id;
			}
		} else {
			$foundCatId = $resultCat;
			//echo "Catégorie dolistore trouvée pour ref ".$product['item_reference']." (".$product['item_name'].") : ".$resultCat;
		}

		if ($foundCatId) {
			$catStatic = new Categorie($db);
			$res = $catStatic->fetch($foundCatId);
			echo "<br />Catégorie trouvée pour ref ".$product['item_reference']." (".$product['item_name'].") : ".$catStatic->getNomUrl(1);
		}
	}

	print '<br />';
	print 'Langue du mail : '.$langEmail;

	// EN template by default
	$idTemplate = getDolGlobalInt('DOLISTOREXTRACT_EMAIL_TEMPLATE_EN');
	if(preg_match('/fr.*/', $langEmail)) {
		$idTemplate = getDolGlobalInt('DOLISTOREXTRACT_EMAIL_TEMPLATE_FR');
	}
	$usedTemplate = $formMail->getEMailTemplate($db, 'dolistore_extract', $user, '',$idTemplate);
	$listProductString = implode(', ', $listProduct);
	$arraySubstitutionDolistore = [
			'__DOLISTORE_ORDER_NAME__' => $dolistoreMail->order_name,
			'__DOLISTORE_INVOICE_FIRSTNAME__' => $dolistoreMail->buyer_firstname,
			'__DOLISTORE_BUYER_COMPANY__' => $dolistoreMail->buyer_company,
			'__DOLISTORE_INVOICE_LASTNAME__' => $dolistoreMail->buyer_lastname,
	        '__DOLISTORE_LIST_PRODUCTS__' => $listProductString
	];

	$subject=make_substitutions($usedTemplate->topic, $arraySubstitutionDolistore);
	$message=make_substitutions($usedTemplate->content, $arraySubstitutionDolistore);
	print '<br />Sujet du mail : '.$subject;
	print '<br />Texte du mail : '.$message;

	print '<strong>Données extraites</strong><br/>';
	print '<pre>';

	var_dump($dolistoreMail);

	print '<div class="center">';
	// TODO: check if already imported
	print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?action=import&id='.$id.'">Import</a>';


	print '<a class="button" href="'.$_SERVER['PHP_SELF'].'">Fermer</a>';

	print '</div>';

}
if (!$id) {


print load_fiche_titre($langs->trans('DolistoreMailsList'));

// Count the messages in current folder
$overallMessages = $imap->countMessages();
$unreadMessages = $imap->countUnreadMessages();

print '<div class="info">'.$overallMessages.' messages / '. $unreadMessages.' non lus</div>';
// Fetch all the messages in the current folder
$emails = $imap->getMessages();

print '<table class="liste">';

print '<tr class="liste_titre">';
print '<th>Date</th>';
print '<th>ID</th>';
print '<th>Ref</th>';
print '<th>Lang</th>';
print '<th>Company</th>';
print '<th>Mail</th>';
print '<th>Contact</th>';
print '<th>Lu/Non Lu</th>';
print '<th>Actions</th>';
print '</tr>';

foreach($emails as $email) {

	$mailExtract = new \dolistoreMailExtract($db, $email->message->html);

	// Seulement les mails en provenance de dolistore
	if (strpos($email->header->subject, 'DoliStore') > 0) {

		$langEmail = dolistoreMailExtract::detectLang($email->header->subject);
		$datasCustomer = $mailExtract->extractOrderDatas();
		$datasOrder = dolistoreMailExtract::extractOrderDatasFromSubject($email->header->subject, $langEmail);

		print '<tr>';

		// Date
		print '<td>';
		print $email->header->date;
		print '</td>';

		// ID
		print '<td>';
		print $datasOrder['id'];
		print '</td>';

		// ref
		print '<td>';
		print $datasOrder['ref'];
		print '</td>';

		// Lang
		print '<td>';
		print picto_from_langcode($langEmail);
		print '</td>';

		// Company
		print '<td>';
		print $datasCustomer['buyer_company'];
		print '</td>';

		// Email
		print '<td>';
		print $datasCustomer['buyer_email'];
		print '</td>';

		// Contact name
		print '<td>';
		print $datasCustomer['buyer_lastname'].' '.$datasCustomer['buyer_firstname'];
		print '</td>';

		// Read / unread
		print '<td>';
		print $email->header->details->Unseen == "U" ? 'Non lu' : 'Lu';
		print '</td>';

		// Actions
		print '<td>';
		print '<a href="'.$_SERVER['PHP_SELF'].'?action=read&view=plain&id='.$email->header->msgno.'">Voir</a>';
		//print '<a href="'.$_SERVER['PHP_SELF'].'?action=read&view=html&id='.$email->header->uid.'">HTML</a>';
		print '</td>';

		print '</tr>';
	}

}
print '<table>';


}

// End of page
llxFooter();
$db->close();
