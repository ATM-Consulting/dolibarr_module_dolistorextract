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

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once 'dolistorextractConfig.class.php';

class dolistoreMailExtract
{

	/**
	 *
	 * @var Db $db DB object
	 */
	public $db;

	/**
	 *
	 * @var string $htmlBody	Body of the message, HTML version
	 */
	public $htmlBody;

	/**
	 * @param Db $db
	 * @param string $htmlBody
	 */
	function __construct($db, $htmlBody = '')
	{
		$this->db = $db;
		if (!empty($htmlBody)) {
			$this->htmlBody = $htmlBody;
		}
	}

	/**
	 * Extract order data from message content
	 *
	 * Load DOM data from hidden div id="invoice_fulldata"
	 *
	 * Return an array with keys and values extracted
	 */
	function extractOrderDatas()
	{
		if (empty($this->htmlBody)) {
			return array();
		}
		$confDolExtract = new dolistorextractConfig();
		$doc = new DOMDocument();
		@$doc->loadHTML($this->htmlBody);
		$xml = simplexml_import_dom($doc);

		$extractDatas = array();

		// Invoice informations
		$datas = $xml->xpath('//div[@id="invoice_fulldata"]/span');
		foreach ($datas as $data)
		{
			$attribute = (string) $data->attributes()->class;
			if (in_array($attribute, $confDolExtract->arrayExtractTags)) {
				$extractDatas[$attribute] = (string) $data[0];
			}
		}

		return $extractDatas;
	}

	/**
	 * Extract products datas from email body
	 *
	 * @see self::ARRAY_EXTRACT_TAGS_PRODUCT
	 *
	 * @return array contains keys defined in self::ARRAY_EXTRACT_TAGS_PRODUCT
	 */
	function extractProductsData()
	{
		if (empty($this->htmlBody)) {
			return array();
		}
		$confDolExtract = new dolistorextractConfig();
		$doc = new DOMDocument();
		@$doc->loadHTML($this->htmlBody);
		$xml = simplexml_import_dom($doc);

		$extractProducts = array();

		// Invoice informations
		$datas = $xml->xpath('//table[@class="table table-recap"]/tbody/tr[@class="item_data"]');
		$i=0;

		// tr
		foreach ($datas as $row)
		{
			$extractProducts[$i] = array();
			// Cells
			foreach( $row as $cell) {
				if ($cell->span) {
					$attribute = (string) $cell->span->attributes()->class;
					if (in_array($attribute, $confDolExtract->arrayExtractTagsProduct)) {
						$extractProducts[$i][$attribute] = (string) $cell->span;
					}
				} else if ($cell->strong->span) { // Case for product title
					$extractProducts[$i]['item_name'] = (string) $cell->strong->span;
				}
			}
			++$i;
		}
		foreach($extractProducts as $key => &$lineprod) {
			$ref = $lineprod["item_reference"];
			// Test if module is our. Else we unset the line
			if(!preg_match("/^c458/", $ref)) {
				unset($extractProducts[$key]);
			}
		}
		return $extractProducts;
	}


	/**
	 * Extract all datas
	 *
	 * Extract all datas from $this->htmlBody and return an array which contains one keys `items` for products listing
	 * @return array
	 */
	public function extractAllDatas()
	{
		$datas = $this->extractOrderDatas();
		// Extract product data
		$lines = $this->extractProductsData();
		if (is_array($lines) && count($lines) > 0) {
			$datas['items'] = $lines;
		}

		if(empty($datas['invoice_company'])) {
			if(!empty($datas['invoice_lastname']) && !empty($datas['invoice_firstname'])) {
				$datas['invoice_company'] = $datas['invoice_firstname'].' '.$datas['invoice_lastname'];
			}
		}

		return (array) $datas;
	}

	/**
	 * Detect email lang from subject
	 *
	 * @param string $subject
	 * @return string Langage code
	 * @see dolistoreMailExtract::ARRAY_TITLE_TRANSLATION_MAP
	 */
	public static function detectLang($subject)
	{
		$foundLang = '';
		$confDolExtract = new dolistorextractConfig();

		foreach ($confDolExtract->arrayTitleTranslationMap as $key => $lang) {
			if (preg_match('/'.$key.'/', $subject)) {
				$foundLang = $lang;
				break;
			}
		}
		return $foundLang;
	}

	/**
	 * Extract customer data from plain text mail
	 *
	 * @param string $textPlain Text message, plain format
	 * @param string $lang Lang code
	 * @return array
	 */
	public static function extractCustomerDatasFromText($textPlain, $lang = '')
	{
		$customerDatas = array();
		$arrayLines = explode("\n", $textPlain);
		$confDolExtract = new dolistorextractConfig();


		// Search in each line if match found for datas
		for ($i=0; $i < count($arrayLines); $i++) {

			$line = $arrayLines[$i];
			if ($line == "") continue;

			switch ($lang) {
				case 'fr_FR' || 'es_ES':

				    if (preg_match($confDolExtract->arrayPatternMailThirdpartyMap[${lang}], $line, $matches)) {
						$emailExtract = "";

						// string contains "THIRDPARTY CONTACT_NAME (EMAIL)
						$coordAll = $matches[1];

						// Extract email : text between () chars
						if (preg_match('/.*\((.*)\)/', $coordAll, $matchMail)) {
							$customerDatas['email'] =  $matchMail[1];
						}
						// Extract all not between () chars
						if (preg_match('/(.*)\(.*@.*\)/', $coordAll, $matchName)) {
							$customerDatas['contact_name'] =  trim($matchName[1]);
						}


					}

					break;

				case "en_US":

					if (preg_match('/A new order was placed on DoliStore from the following customer/', $line)) {
						$emailExtract = "";

						// In english mail contains a new line for customer data
						$coordAll = $arrayLines[${i}+1];

						// Extract email : text between () chars
						if (preg_match('/.*\((.*)\)/', $coordAll, $matchMail)) {
							$customerDatas['email'] =  $matchMail[1];
						}

						// Extract all not between () chars
						if (preg_match('/(.*)\(.*@.*\)/', $coordAll, $matchName)) {
							$customerDatas['contact_name'] =  trim($matchName[1]);
						}

					}
					break;
				default:
					print 'pas de regex pour lang='.$lang;
					break;

			}
		}
		return $customerDatas;
	}


	public static function extractOrderDatasFromSubject($subject)
	{
		$orderDatas = array();
		if (preg_match('/.*[°#]([0-9]+) - ([A-Z]+)/', $subject, $matches)) {
			$orderDatas['id'] = $matches[1];
			$orderDatas['ref'] = $matches[2];
		}
		return $orderDatas;
	}
}
