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

/**
 *
 * Class to describe dolistore Email
 *
 */
class dolistoreMail
{

	public $buyer_company= '';
	public $buyer_firstname= '';
	public $buyer_lastname= '';
	public $buyer_address1= '';
	public $buyer_address2= '';
	public $buyer_city= '';
	public $buyer_postal_code= '';
	public $buyer_country= '';
	public $buyer_country_code='';
	public $buyer_state= '';
	public $buyer_phone= '';
	public $buyer_email= '';
	public $order_name= '';
	public $order_currency= '';
	public $iso_code= '';

	/**
	 *
	 * @var array
	 */
	public $items = array();




	function __construct()
	{

	}

	/**
	 * Set data for email object
	 *
	 * @param array $datasOrderArray Array filled with \dolistoreMailExtract::extractOrderDatas()
	 * @return number
	 */
	public function setDatas($datasOrderArray = array())
	{
		if (empty($datasOrderArray)) {
			return 0;
		}
		foreach ($datasOrderArray as $key => $value) {
			$this->{$key} = $value;
		}
		return count($datasOrderArray);

	}
	/**
	 * Set data for object lines
	 *
	 * @param array $extractProductDatas Array filled with \dolistoreMailExtract::extractProductsDatas()
	 * @return number
	 */
	public function fetchProducts($extractProductDatas = array())
	{
		if (empty($extractProductDatas)) {
			return 0;
		}

		$this->items = array();
		$i = 0;
		foreach ($extractProductDatas as $prod) {

			$line = new dolistoreMailLine();
			$line->item_name = $prod['item_name'];
			$line->item_reference = $prod['item_reference'];
			$line->item_price = $prod['item_price'];
			$line->item_quantity = $prod['item_quantity'];
			$line->item_price_total = $prod['item_price_total'];

			$this->items[$i] = $line;
			++$i;
		}
		return count($this->items);
	}


}
