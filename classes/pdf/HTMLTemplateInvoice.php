<?php
/**
 * 2007-2015 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author 	PrestaShop SA <contact@prestashop.com>
 *  @copyright  2007-2015 PrestaShop SA
 *  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

/**
 * @since 1.5
 */
class HTMLTemplateInvoiceCore extends HTMLTemplate
{
	public $order;
	public $available_in_your_account = false;

	/**
	 * @param OrderInvoice $order_invoice
	 * @param $smarty
	 * @throws PrestaShopException
	 */
	public function __construct(OrderInvoice $order_invoice, $smarty)
	{
		$this->order_invoice = $order_invoice;
		$this->order = new Order((int)$this->order_invoice->id_order);
		$this->smarty = $smarty;

		// header informations
		$this->date = Tools::displayDate($order_invoice->date_add);

		$id_lang = Context::getContext()->language->id;
		$this->title = $order_invoice->getInvoiceNumberFormatted($id_lang);

		$this->shop = new Shop((int)$this->order->id_shop);
	}

	/**
	 * Returns the template's HTML header
	 *
	 * @return string HTML header
	 */
	public function getHeader()
	{
		$this->assignCommonHeaderData();
		$this->smarty->assign(array(
			'header' => $this->l('INVOICE'),
		));

		return $this->smarty->fetch($this->getTemplate('header'));
	}

	/**
	 * Compute layout elements size
	 *
	 * @params $params Array Layout elements
	 *
	 * @return Array Layout elements columns size
	 */

	private function computeLayout($params)
	{
		$layout = array(
			'reference' => array(
				'width' => 15,
			),
			'product' => array(
				'width' => 40,
			),
			'quantity' => array(
				'width' => 8,
			),
			'tax_code' => array(
				'width' => 8,
			),
			'unit_price_tax_excl' => array(
				'width' => 0,
			),
			'total_tax_excl' => array(
				'width' => 0,
			)
		);

		if (isset($params['has_discount']) && $params['has_discount'])
		{
			$layout['before_discount'] = array('width' => 0);
			$layout['product']['width'] -= 7;
			$layout['reference']['width'] -= 3;
		}

		$total_width = 0;
		$free_columns_count = 0;
		foreach ($layout as $data)
		{
			if ($data['width'] === 0)
				++$free_columns_count;

			$total_width += $data['width'];
		}

		$delta = 100 - $total_width;

		foreach ($layout as $row => $data)
			if ($data['width'] === 0)
				$layout[$row]['width'] = $delta / $free_columns_count;

		$layout['_colCount'] = count($layout);

		return $layout;
	}

	/**
	 * Returns the template's HTML content
	 *
	 * @return string HTML content
	 */
	public function getContent()
	{
        $invoiceAddressPatternRules = Tools::jsonDecode(Configuration::get('PS_INVCE_INVOICE_ADDR_RULES'), true);
        $deliveryAddressPatternRules = Tools::jsonDecode(Configuration::get('PS_INVCE_DELIVERY_ADDR_RULES'), true);

		$invoice_address = new Address((int)$this->order->id_address_invoice);
		$country = new Country((int)$invoice_address->id_country);

		if ($this->order_invoice->invoice_address)
			$formatted_invoice_address = $this->order_invoice->invoice_address;
		else
			$formatted_invoice_address = AddressFormat::generateAddress($invoice_address, $invoiceAddressPatternRules, '<br />', ' ');

		$delivery_address = null;
		$formatted_delivery_address = '';
		if (isset($this->order->id_address_delivery) && $this->order->id_address_delivery)
		{
			if ($this->order_invoice->delivery_address)
				$formatted_delivery_address = $this->order_invoice->delivery_address;
			else
			{
				$delivery_address = new Address((int)$this->order->id_address_delivery);
				$formatted_delivery_address = AddressFormat::generateAddress($delivery_address, $deliveryAddressPatternRules, '<br />', ' ');
			}
		}

		$customer = new Customer((int)$this->order->id_customer);

		$order_details = $this->order_invoice->getProducts();

		$has_discount = false;
		foreach ($order_details as $id => &$order_detail)
		{
			// Find out if column 'price before discount' is required
			if ($order_detail['reduction_amount_tax_excl'] > 0)
			{
				$has_discount = true;
				$order_detail['unit_price_tax_excl_before_specific_price'] = $order_detail['unit_price_tax_excl_including_ecotax'] + $order_detail['reduction_amount_tax_excl'];
			}
			elseif ($order_detail['reduction_percent'] > 0)
			{
				$has_discount = true;
				$order_detail['unit_price_tax_excl_before_specific_price'] = (100 * $order_detail['unit_price_tax_excl_including_ecotax']) / (100 - 15);
			}

			// Set tax_code
			$taxes = OrderDetail::getTaxListStatic($id);
			$tax_temp = array();
			foreach ($taxes as $tax)
            {
                $obj = new Tax($tax['id_tax']);
				$tax_temp[] = sprintf($this->l('%1$s%2$s%%'), ($obj->rate + 0), '&nbsp;');
            }

			$order_detail['order_detail_tax'] = $taxes;
			$order_detail['order_detail_tax_label'] = implode(', ', $tax_temp);
		}
		unset($tax_temp);
		unset($order_detail);

		if (Configuration::get('PS_PDF_IMG_INVOICE'))
		{
			foreach ($order_details as &$order_detail)
			{
				if ($order_detail['image'] != null)
				{
					$name = 'product_mini_'.(int)$order_detail['product_id'].(isset($order_detail['product_attribute_id']) ? '_'.(int)$order_detail['product_attribute_id'] : '').'.jpg';
					$path = _PS_PROD_IMG_DIR_.$order_detail['image']->getExistingImgPath().'.jpg';

					$order_detail['image_tag'] = preg_replace(
						'/\.*'.preg_quote(__PS_BASE_URI__, '/').'/',
						_PS_ROOT_DIR_.DIRECTORY_SEPARATOR,
						ImageManager::thumbnail($path, $name, 45, 'jpg', false),
						1
					);

					if (file_exists(_PS_TMP_IMG_DIR_.$name))
						$order_detail['image_size'] = getimagesize(_PS_TMP_IMG_DIR_.$name);
					else
						$order_detail['image_size'] = false;
				}
			}
			unset($order_detail); // don't overwrite the last order_detail later
		}

		$cart_rules = $this->order->getCartRules($this->order_invoice->id);
		$free_shipping = false;
		foreach ($cart_rules as $key => $cart_rule)
		{
			if ($cart_rule['free_shipping'])
			{
				$free_shipping = true;
				/**
				 * Adjust cart rule value to remove the amount of the shipping.
				 * We're not interested in displaying the shipping discount as it is already shown as "Free Shipping".
				 */
				$cart_rules[$key]['value_tax_excl'] -= $this->order_invoice->total_shipping_tax_excl;
				$cart_rules[$key]['value'] -= $this->order_invoice->total_shipping_tax_incl;

				/**
				 * Don't display cart rules that are only about free shipping and don't create
				 * a discount on products.
				 */
				if ($cart_rules[$key]['value'] == 0)
					unset($cart_rules[$key]);
			}
		}

		$product_taxes = 0;
		foreach ($this->order_invoice->getProductTaxesBreakdown($this->order) as $details)
			$product_taxes += $details['total_amount'];

		$product_discounts_tax_excl = $this->order_invoice->total_discount_tax_excl;
		$product_discounts_tax_incl = $this->order_invoice->total_discount_tax_incl;
		if ($free_shipping)
		{
			$product_discounts_tax_excl -= $this->order_invoice->total_shipping_tax_excl;
			$product_discounts_tax_incl -= $this->order_invoice->total_shipping_tax_incl;
		}

		$products_after_discounts_tax_excl = $this->order_invoice->total_products - $product_discounts_tax_excl;
		$products_after_discounts_tax_incl = $this->order_invoice->total_products_wt - $product_discounts_tax_incl;

		$shipping_tax_excl = $free_shipping ? 0 : $this->order_invoice->total_shipping_tax_excl;
		$shipping_tax_incl = $free_shipping ? 0 : $this->order_invoice->total_shipping_tax_incl;
		$shipping_taxes = $shipping_tax_incl - $shipping_tax_excl;

		$wrapping_taxes = $this->order_invoice->total_wrapping_tax_incl - $this->order_invoice->total_wrapping_tax_excl;

		$total_taxes = $this->order_invoice->total_paid_tax_incl - $this->order_invoice->total_paid_tax_excl;

		$footer = array(
			'products_before_discounts_tax_excl' => $this->order_invoice->total_products,
			'product_discounts_tax_excl' => $product_discounts_tax_excl,
			'products_after_discounts_tax_excl' => $products_after_discounts_tax_excl,
			'products_before_discounts_tax_incl' => $this->order_invoice->total_products_wt,
			'product_discounts_tax_incl' => $product_discounts_tax_incl,
			'products_after_discounts_tax_incl' => $products_after_discounts_tax_incl,
			'product_taxes' => $product_taxes,
			'shipping_tax_excl' => $shipping_tax_excl,
			'shipping_taxes' => $shipping_taxes,
			'shipping_tax_incl' => $shipping_tax_incl,
			'wrapping_tax_excl' => $this->order_invoice->total_wrapping_tax_excl,
			'wrapping_taxes' => $wrapping_taxes,
			'wrapping_tax_incl' => $this->order_invoice->total_wrapping_tax_incl,
			'ecotax_taxes' => $total_taxes - $product_taxes - $wrapping_taxes - $shipping_taxes,
			'total_taxes' => $total_taxes,
			'total_paid_tax_excl' => $this->order_invoice->total_paid_tax_excl,
			'total_paid_tax_incl' => $this->order_invoice->total_paid_tax_incl
		);

		foreach ($footer as $key => $value)
			$footer[$key] = Tools::ps_round($value, _PS_PRICE_COMPUTE_PRECISION_, $this->order->round_mode);

		/**
		 * Need the $round_mode for the tests.
		 */
		$round_type = null;
		switch ($this->order->round_type)
		{
			case Order::ROUND_TOTAL:
				$round_type = 'total';
				break;
			case Order::ROUND_LINE;
				$round_type = 'line';
				break;
			case Order::ROUND_ITEM:
				$round_type = 'item';
				break;
			default:
				$round_type = 'line';
				break;
		}

		$display_product_images = Configuration::get('PS_PDF_IMG_INVOICE');
		$tax_excluded_display = Group::getPriceDisplayMethod($customer->id_default_group);

		$layout = $this->computeLayout(array('has_discount' => $has_discount));

		$legal_free_text = Hook::exec('displayInvoiceLegalFreeText', array('order' => $this->order));
		if (!$legal_free_text)
			$legal_free_text = Configuration::get('PS_INVOICE_LEGAL_FREE_TEXT', (int)Context::getContext()->language->id, null, (int)$this->order->id_shop);
		$order_obj = new Order($this->order->id);

		$this->context = Context::getContext();
		$products = $order_obj->getProducts();

		if (Module::isInstalled('hotelreservationsystem')) 
		{
			require_once (_PS_MODULE_DIR_.'hotelreservationsystem/define.php');

			$obj_cart_bk_data = new HotelCartBookingData();
			$obj_htl_bk_dtl = new HotelBookingDetail();
			$obj_rm_type = new HotelRoomType();

			$customer = new Customer($this->order->id_customer);
			if (!empty($products)) 
			{
				$processed_product = array();
				$refunded_rooms = 0;
				$cart_bk_data=array();

				foreach ($products as $type_key => $type_value) 
				{
					if (in_array($type_value['product_id'], $processed_product))
						continue;
					$processed_product[] = $type_value['product_id'];

					$product = new Product($type_value['product_id'], false, $this->context->language->id);
					$order_prod_dtl = $obj_htl_bk_dtl->getPsOrderDetailsByProduct($product->id, $this->order->id);

					$cover_image_arr = $product->getCover($type_value['product_id']);
							
					if(!empty($cover_image_arr))
						$cover_img = $this->context->link->getImageLink($product->link_rewrite, $product->id.'-'.$cover_image_arr['id_image'], 'small_default');
					else 
						$cover_img = $this->context->link->getImageLink($product->link_rewrite, $this->context->language->iso_code."-default", 'small_default');

					if (isset($customer->id)) 
					{
						$cart_obj = new Cart($this->order->id_cart);
						$order_bk_data = $obj_htl_bk_dtl->getOnlyOrderBookingData($this->order->id, $cart_obj->id_guest, $type_value['product_id'], $customer->id);
					}
					else
					{
						$order_bk_data = $obj_htl_bk_dtl->getOnlyOrderBookingData($this->order->id, $customer->id_guest, $type_value['product_id']);
					}
					$rm_dtl = $obj_rm_type->getRoomTypeInfoByIdProduct($type_value['product_id']);

					$cart_htl_data[$type_key]['id_product'] = $type_value['product_id'];
					$cart_htl_data[$type_key]['cover_img'] 	= $cover_img;
					$cart_htl_data[$type_key]['adult'] 		= $rm_dtl['adult'];
					$cart_htl_data[$type_key]['children']	= $rm_dtl['children'];

					foreach ($order_bk_data as $data_k => $data_v) 
					{
						$date_join = strtotime($data_v['date_from']).strtotime($data_v['date_to']);

						/*Product price when order was created*/
						$order_details_obj = new OrderDetail($data_v['id_order_detail']);
						$unit_price_tax_excl = 0;
						$unit_price_tax_incl = 0;
						$unit_price_tax_excl = $order_details_obj->unit_price_tax_excl;
						$unit_price_tax_incl = $order_details_obj->unit_price_tax_incl;
						$prod_ord_dtl_name = $order_details_obj->product_name;
						$cart_htl_data[$type_key]['name'] = $prod_ord_dtl_name;

						$cart_htl_data[$type_key]['unit_price_tax_excl'] = $unit_price_tax_excl;
						$cart_htl_data[$type_key]['unit_price_tax_incl'] = $unit_price_tax_incl;
						//work on entring refund data
						$obj_ord_ref_info = new HotelOrderRefundInfo();
						$ord_refnd_info = $obj_ord_ref_info->getOderRefundInfoByIdOrderIdProductByDate($this->order->id, $type_value['product_id'], $data_v['date_from'], $data_v['date_to']);
						if ($ord_refnd_info)
						{
							$obj_refund_stages = new HotelOrderRefundStages();
							$stage_name = $obj_refund_stages->getNameById($ord_refnd_info['refund_stage_id']);

							if ($stage_name == 'Refunded')
								$refunded_rooms = 1;
						}
						else
						{
							$stage_name = '';
						}
						// END Order Refund


						if (isset($cart_htl_data[$type_key]['date_diff'][$date_join]))
						{
							$cart_htl_data[$type_key]['date_diff'][$date_join]['num_rm'] += 1;

							$num_days = $cart_htl_data[$type_key]['date_diff'][$date_join]['num_days'];
							$vart_quant = (int)$cart_htl_data[$type_key]['date_diff'][$date_join]['num_rm'] * $num_days;

							$amount = $unit_price_tax_excl;
							$amount *= $vart_quant;

							$cart_htl_data[$type_key]['date_diff'][$date_join]['amount'] = $amount;

							// For order refund
							$cart_htl_data[$type_key]['date_diff'][$date_join]['stage_name'] = $stage_name;
							$cart_htl_data[$type_key]['date_diff'][$date_join]['id_room'] = $data_v['id_room'];
						}
						else
						{
							$num_days = $obj_htl_bk_dtl->getNumberOfDays($data_v['date_from'], $data_v['date_to']);

							$cart_htl_data[$type_key]['date_diff'][$date_join]['num_rm'] = 1;
							$cart_htl_data[$type_key]['date_diff'][$date_join]['data_form'] = $data_v['date_from'];
							$cart_htl_data[$type_key]['date_diff'][$date_join]['data_to'] = $data_v['date_to'];
							$cart_htl_data[$type_key]['date_diff'][$date_join]['num_days'] = $num_days;
							$amount = $unit_price_tax_excl;
							$amount *= $num_days;
							
							$cart_htl_data[$type_key]['date_diff'][$date_join]['amount'] = $amount;
		
							// For order refund
							$cart_htl_data[$type_key]['date_diff'][$date_join]['stage_name'] = $stage_name;
							$cart_htl_data[$type_key]['date_diff'][$date_join]['id_room'] = $data_v['id_room'];
						}
					}
				}

				// For Advanced Payment
				$obj_customer_adv = new HotelCustomerAdvancedPayment();
				$order_adv_dtl = $obj_customer_adv->getCstAdvPaymentDtlByIdOrder($order_obj->id);
				if ($order_adv_dtl) 
					$this->smarty->assign('order_adv_dtl', $order_adv_dtl);
			}
		}
		$data = array(
			'cart_htl_data' => $cart_htl_data,
			'refunded_rooms' => $refunded_rooms,
			'order' => $this->order,
            'order_invoice' => $this->order_invoice,
            'order_details' => $order_details,
			'cart_rules' => $cart_rules,
			'delivery_address' => $formatted_delivery_address,
			'invoice_address' => $formatted_invoice_address,
			'addresses' => array('invoice' => $invoice_address, 'delivery' => $delivery_address),
			'tax_excluded_display' => $tax_excluded_display,
			'display_product_images' => $display_product_images,
			'layout' => $layout,
			'tax_tab' => $this->getTaxTabContent(),
			'customer' => $customer,
			'footer' => $footer,
			'ps_price_compute_precision' => _PS_PRICE_COMPUTE_PRECISION_,
			'round_type' => $round_type,
			'legal_free_text' => $legal_free_text,
		);

		if (Tools::getValue('debug'))
			die(json_encode($data));

		$this->smarty->assign($data);

		$tpls = array(
			'style_tab' => $this->smarty->fetch($this->getTemplate('invoice.style-tab')),
			'addresses_tab' => $this->smarty->fetch($this->getTemplate('invoice.addresses-tab')),
			'summary_tab' => $this->smarty->fetch($this->getTemplate('invoice.summary-tab')),
			'product_tab' => $this->smarty->fetch($this->getTemplate('invoice.product-tab')),
			'tax_tab' => $this->getTaxTabContent(),
			'payment_tab' => $this->smarty->fetch($this->getTemplate('invoice.payment-tab')),
			'total_tab' => $this->smarty->fetch($this->getTemplate('invoice.total-tab')),
		);

		$this->smarty->assign($tpls);
		return $this->smarty->fetch($this->getTemplateByCountry($country->iso_code));
	}

	/**
	 * Returns the tax tab content
	 *
	 * @return String Tax tab html content
	 */
	public function getTaxTabContent()
	{
		$debug = Tools::getValue('debug');

		$address = new Address((int)$this->order->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
		$tax_exempt = Configuration::get('VATNUMBER_MANAGEMENT')
							&& !empty($address->vat_number)
							&& $address->id_country != Configuration::get('VATNUMBER_COUNTRY');
		$carrier = new Carrier($this->order->id_carrier);

		$tax_breakdowns = $this->getTaxBreakdown();

		$data = array(
			'tax_exempt' => $tax_exempt,
			'use_one_after_another_method' => $this->order_invoice->useOneAfterAnotherTaxComputationMethod(),
			'display_tax_bases_in_breakdowns' => $this->order_invoice->displayTaxBasesInProductTaxesBreakdown(),
			'product_tax_breakdown' => $this->order_invoice->getProductTaxesBreakdown($this->order),
			'shipping_tax_breakdown' => $this->order_invoice->getShippingTaxesBreakdown($this->order),
			'ecotax_tax_breakdown' => $this->order_invoice->getEcoTaxTaxesBreakdown(),
			'wrapping_tax_breakdown' => $this->order_invoice->getWrappingTaxesBreakdown(),
			'tax_breakdowns' => $tax_breakdowns,
			'order' => $debug ? null : $this->order,
			'order_invoice' => $debug ? null : $this->order_invoice,
			'carrier' => $debug ? null : $carrier
		);

		if ($debug)
			return $data;

		$this->smarty->assign($data);

		return $this->smarty->fetch($this->getTemplate('invoice.tax-tab'));
	}

	/**
	 * Returns different tax breakdown elements
	 *
	 * @return Array Different tax breakdown elements
	 */
	protected function getTaxBreakdown()
	{
		$breakdowns = array(
			'product_tax' => $this->order_invoice->getProductTaxesBreakdown($this->order),
			'shipping_tax' => $this->order_invoice->getShippingTaxesBreakdown($this->order),
			'ecotax_tax' => $this->order_invoice->getEcoTaxTaxesBreakdown(),
			'wrapping_tax' => $this->order_invoice->getWrappingTaxesBreakdown(),
		);

		foreach ($breakdowns as $type => $bd)
		{
			if (empty($bd))
				unset($breakdowns[$type]);
		}

		if (empty($breakdowns))
			$breakdowns = false;

		if (isset($breakdowns['product_tax']))
		{
			foreach ($breakdowns['product_tax'] as &$bd)
				$bd['total_tax_excl'] = $bd['total_price_tax_excl'];
		}

		if (isset($breakdowns['ecotax_tax'])) {
			foreach ($breakdowns['ecotax_tax'] as &$bd) {
				$bd['total_tax_excl'] = $bd['ecotax_tax_excl'];
				$bd['total_amount'] = $bd['ecotax_tax_incl'] - $bd['ecotax_tax_excl'];
			}
		}

		return $breakdowns;
	}

    /*
	protected function getTaxLabel($tax_breakdowns)
	{
		$tax_label = '';
		$all_taxes = array();

		foreach ($tax_breakdowns as $type => $bd)
			foreach ($bd as $line)
				if(isset($line['id_tax']))
					$all_taxes[] = $line['id_tax'];

		$taxes = array_unique($all_taxes);

		foreach ($taxes as $id_tax) {
			$tax = new Tax($id_tax);
			$tax_label .= $tax->id.': '.$tax->name[$this->order->id_lang].' ('.$tax->rate.'%) ';
		}

		return $tax_label;
	}
    */

	/**
	 * Returns the invoice template associated to the country iso_code
	 *
	 * @param string $iso_country
	 */
	protected function getTemplateByCountry($iso_country)
	{
		$file = Configuration::get('PS_INVOICE_MODEL');

		// try to fetch the iso template
		$template = $this->getTemplate($file.'.'.$iso_country);

		// else use the default one
		if (!$template)
			$template = $this->getTemplate($file);

		return $template;
	}

	/**
	 * Returns the template filename when using bulk rendering
	 *
	 * @return string filename
	 */
	public function getBulkFilename()
	{
		return 'invoices.pdf';
	}

	/**
	 * Returns the template filename
	 *
	 * @return string filename
	 */
	public function getFilename()
	{
		return Configuration::get('PS_INVOICE_PREFIX', Context::getContext()->language->id, null, $this->order->id_shop).sprintf('%06d', $this->order_invoice->number).'.pdf';
	}
}