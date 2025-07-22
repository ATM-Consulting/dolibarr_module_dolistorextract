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

//namespace custom\dolistorextract\class;


/**
 *
 * @author jfefe
 *
 */
class dolistorextractConfig
{

    /**
     * Array to store keys to extract data about email
     *
     * @var array
     */
    public $arrayExtractTags = array(
        //ARRAY_EXTRACT_TAGS
        'buyer_company',
        'buyer_firstname',
        'buyer_lastname',
        'buyer_address1',
        'buyer_address2',
        'buyer_city',
        'buyer_postal_code',
        'buyer_country',
        'buyer_country_code',
        'buyer_state',
        'buyer_phone',
        'buyer_email',
		'buyer_idprof2',
		'buyer_intravat',
        'order_ref',
        'order_currency',
        'iso_code'
    );

    /**
     * Array to store keys to extract data for product
     * @var array
     */
    public $arrayExtractTagsProduct = array(
    //ARRAY_EXTRACT_TAGS_PRODUCT
        'item_reference',
        'item_name',
        'item_price',
        'item_quantity',
        'item_price_total'
    );

    /**
     * Map for pattern title and related lang
     */
    public $arrayTitleTranslationMap = array(
    //ARRAY_TITLE_TRANSLATION_MAP
        'Order notification' => 'en_US',
        'Notification de commande' => 'fr_FR',
        'Nuevo pedido' => 'es_ES',
        'Notifica ordine' => 'it_IT',
        'Bestellbenachrichtigung' => 'de_DE'
    );

    public $arrayPatternMailThirdpartyMap = array(
    //ARRAY_PATTERN_MAIL_THIRDPARTY_MAP
        'en_US' => '/DoliStore par ce client : (.*)/',
        'fr_FR' => '/DoliStore par ce client : (.*)/',
        'es_ES' => '/cliente : (.*)/'
    );

    /**
     */
    public function __construct()
    {

    }

}

