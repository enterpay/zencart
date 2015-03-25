<?php
/*
 * Release info: Released under the GNU General Public License
 */

  class enterpay {
    var $code, $title, $description, $enabled, $tax_included;

	// class constructor
    function enterpay() {
        global $order;
     
        if(!isset($_SESSION['handled_orders'])) {   $_SESSION['handled_orders'] = array(); }
     
        $this->code = 'enterpay';
        $this->title = MODULE_PAYMENT_ENTERPAY_TITLE;
        $this->description = MODULE_PAYMENT_ENTERPAY_TEXT_DESCRIPTION;
        $this->sort_order = MODULE_PAYMENT_ENTERPAY_SORT_ORDER;
        $this->enabled = ((MODULE_PAYMENT_ENTERPAY_STATUS == 'True') ? true : false);
        $this->tax_included = ((MODULE_PAYMENT_ENTERPAY_PRICES_WITH_TAX == 'True') ? true : false);

        if ((int)MODULE_PAYMENT_ENTERPAY_ORDER_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_ENTERPAY_ORDER_STATUS_ID;
        }

        if (is_object($order)) {
            $this->update_status();
        }
        
        if (MODULE_PAYMENT_ENTERPAY_ENVIRONMENT == 'Production') {
            $this->form_action_url = 'https://laskuyritykselle.fi/api/payment/start';
        } 
        else {
            $this->form_action_url = 'https://test.laskuyritykselle.fi/api/payment/start';
        }

    }

	// class methods
    function update_status() {
      global $order, $db;
      

      if ( ($this->enabled == true) && ((int)MODULE_PAYMENT_ENTERPAY_ZONE > 0) ) {
        $check_flag = false;
        $check_query = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_ENTERPAY_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
        while (!$check_query) {
            if ($check_query->fields['zone_id'] < 1) {
                $check_flag = true;
                break;
            } else if ($check_query->fields['zone_id'] == $order->delivery['zone_id']) {
                $check_flag = true;
                break;
            }
          $check_query->MoveNext();
        }

        if ($check_flag == false) {
          $this->enabled = false;
        }
      }
    }

    function javascript_validation() {
      return false;
    }
    

    function selection() {
      return array('id' => $this->code,
                   'module' => $this->title);
    }

    function pre_confirmation_check() {
      return false;
    }

    function confirmation() {
      return false;
    }

    function process_button() {
      global $order, $currency, $ot_coupon;
      $items_total = 0;
      $items_tax = 0;
      
       $reference = $this->add_checksum(MODULE_PAYMENT_ENTERPAY_REFERENCE . $_SESSION['customer_id'] . time());
			
       	$params = array(
            "merchant" => MODULE_PAYMENT_ENTERPAY_MERCHANT_ID,
            "identifier_merchant" => $reference,
            "reference" => $reference,
             "currency" => 'EUR',
            "url_return" => zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL'),
            "version" => MODULE_PAYMENT_ENTERPAY_VERSION,
            "key_version" => MODULE_PAYMENT_ENTERPAY_KEY_VERSION,
            "locale" => "fi_FI",
            "billing_address[street]" => $order->customer['street_address'],
            "billing_address[postalCode]" => $order->customer['postcode'],
            "billing_address[city]" => $order->customer['city'],
           );

         if(MODULE_PAYMENT_ENTERPAY_DEBUG == 'True') {
            $params["debug"] = '1';
         }
 
       		
        $i = 0;
    	for ($i=0, $n=sizeof($order->products); $i<$n; $i++)
    	{
            $params["cart_items[$i][name]"] = $order->products[$i]['name'];
            $params["cart_items[$i][identifier]"] = (int) $order->products[$i]['id'];
            $params["cart_items[$i][quantity]"] = $order->products[$i]['qty'];

            if($this->tax_included)
            {
              $items_total += $order->products[$i]['qty'] * ($params["cart_items[$i][unit_price_including_tax]"] = number_format(($order->products[$i]['final_price'] + zen_calculate_tax($order->products[$i]['final_price'], $order->products[$i]['tax']))*100, 0, ".", ""));
            }
            else
            {
                $items_total += $order->products[$i]['qty'] * ($params["cart_items[$i][unit_price_excluding_tax]"] = number_format($order->products[$i]['final_price']*100, 0, ".", ""));
            }
          $items_tax += $order->products[$i]['qty'] * zen_calculate_tax($order->products[$i]['final_price'], $order->products[$i]['tax'])*100;
 
            $params["cart_items[$i][tax_rate]"] = number_format($order->products[$i]['tax']/100, "2", ".", "");
        }

// shipping
        if($_SESSION['shipping']['cost'] and $_SESSION['shipping']['cost'] > 0) {                      
            $params["cart_items[$i][name]"] = $order->info['shipping_method'];
            $params["cart_items[$i][identifier]"] =  $i;
            $params["cart_items[$i][quantity]"] = 1;

            if($this->tax_included) {
                 $items_total +=  $params["cart_items[$i][unit_price_including_tax]"] = number_format(($order->info['shipping_cost'] + $order->info['shipping_tax'])*100, 0, ".", "");
            }
            else {
                $items_total +=  $params["cart_items[$i][unit_price_excluding_tax]"] = number_format(($order->info['shipping_cost'])*100, 0, ".", "");
            }
            $items_tax += $order->info['shipping_tax']*100;
            $params["cart_items[$i][tax_rate]"] = number_format($order->info['shipping_tax']/$order->info['shipping_cost'], "2", ".", "");
            $i++;
        }
  
      // total
      if($this->tax_included) {
        $total = $params["total_price_including_tax"] = number_format($order->info['total']*100, 0, ".", "");
      } else {
        $total = $params["total_price_excluding_tax"] = number_format(($order->info['total'] - $order->info['tax'])*100, 0, ".", "");
      }
    // catch all in case there is some order total etc. module we couldn't know
    if(abs($items_total - $total) >= 1) {
          $tax_delta = (round($order->info['tax'],2)*100) - $items_tax;
          $delta = $total - $items_total;
          $tax_rate = abs(round(($tax_delta/$delta),2));
          $params["cart_items[$i][name]"] = MODULE_PAYMENT_ENTERPAY_DEDUCTION_OR_SURCHARGE;
          $params["cart_items[$i][identifier]"] = $i;
          $params["cart_items[$i][quantity]"] = 1;
          $params["cart_items[$i][tax_rate]"]  = $tax_rate;
          if($this->tax_included) {
                 $params["cart_items[$i][unit_price_including_tax]"] = number_format($delta, 0, ".", "");
            }
            else {
                $params["cart_items[$i][unit_price_excluding_tax]"] = number_format($delta, 0, ".", "");
            }
         $i++;
       }
	

        ksort($params);
        $hmac_params = array();

        foreach ($params as $k => $v) {
            if ($v !== null && $v !== '' && $k !== 'debug') {
                $hmac_params[$k] = urlencode($k) . '=' . urlencode($v);
            } 
        }

        $str = implode('&', $hmac_params);

        $hmac = hash_hmac('sha512', $str, MODULE_PAYMENT_ENTERPAY_SECRET);
        
        foreach( $params as $key => $value )
        {
            $process_button_string .= "<input type=\"hidden\" name=\"{$key}\" value=\"".htmlentities($value, ENT_COMPAT, CHARSET)."\" />\n";
        }
        $process_button_string .= "<input type=\"hidden\" name=\"hmac\" value=\"".$hmac."\" />";

      	return $process_button_string;
    }

   function before_process() {
    global $messageStack;
    $hmac_get = $_GET['hmac'];

      $params = array (
            "version" => $_GET['version'],
            "key_version" => $_GET['key_version'],
            "status" => $_GET['status'],
            "identifier_valuebuy" => $_GET['identifier_valuebuy'],
            "identifier_merchant" => $_GET['identifier_merchant'],
            "pending_reasons" => $_GET['pending_reasons']
           );
        ksort($params);
        $hmac_params = array();

        foreach ($params as $k => $v) {
            if ($v !== null && $v !== '') {
                $hmac_params[$k] = urlencode($k) . '=' . urlencode($v);
            } 
        }
        
        $hmac_calc = hash_hmac('sha512', implode('&', $hmac_params), MODULE_PAYMENT_ENTERPAY_SECRET);
	
        if($hmac_get != $hmac_calc) {
            $messageStack->add_session('checkout_payment',MODULE_PAYMENT_ENTERPAY_TEXT_ERROR,'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL')); 
            die();
        }

   //in cancel etc.
   if($_GET['status'] != 'pending' and $_GET['status'] != 'successful') {
     zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT)); die();
    }
       return false;
  }

    function after_process() {

      global $insert_id, $db;
     
      $order_id = $insert_id;

        $hmac_get = $_GET['hmac'];

        $params = array (
            "version" => $_GET['version'],
             "key_version" => $_GET['key_version'],
            "status" => $_GET['status'],
            "identifier_valuebuy" => $_GET['identifier_valuebuy'],
            "identifier_merchant" => $_GET['identifier_merchant']
           );

        ksort($params);
        $hmac_params = array();

        foreach ($params as $k => $v) {
            if ($v !== null && $v !== '') {
                $hmac_params[$k] = urlencode($k) . '=' . urlencode($v);
            } 
        }
        
        $hmac_calc = hash_hmac('sha512', implode('&', $hmac_params), MODULE_PAYMENT_ENTERPAY_SECRET);
        
        if($hmac_get != $hmac_calc) { 
          $messageStack->add_session ('checkout_payment',MODULE_PAYMENT_ENTERPAY_TEXT_ERROR,'error');
           zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT).'','SSL'); 
           return;
        }
  
        if($_GET['status'] == 'successful' or $_GET['status'] == 'pending') {
          $reference = preg_replace('/[^0-9]/','',$_GET['identifier_merchant']);
          $db->Execute('INSERT INTO ' . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) VALUES ('$order_id', '1', NOW(), '0', '" . MODULE_PAYMENT_ENTERPAY_REFERENCE_COMMENT . "$reference')");
        }

     if($_GET['status'] == 'pending') {      
      
        $db->Execute("update " . TABLE_ORDERS . " set orders_status = 1 where orders_id = '$order_id'");
        $db->Execute('INSERT INTO ' . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) VALUES ('$order_id', '1', NOW(), '0', '" . MODULE_PAYMENT_ENTERPAY_PENDING_COMMENT . "')");
        } 
     return false;
   }
 
    function get_error() {
            return false;
    }

    function check() {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_ENTERPAY_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    function install() {
        global $db;
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Start using enterpay', 'MODULE_PAYMENT_ENTERPAY_STATUS', 'True', 'Do you approve this payment method?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Title', 'MODULE_PAYMENT_ENTERPAY_TITLE', 'Laskuyritykselle.fi', 'Title of the payment method. This is shown on the list of payment methods which is visible to the customer.', '12', '2', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Merchant identifier', 'MODULE_PAYMENT_ENTERPAY_MERCHANT_ID', '', 'Merchant identifier', '12', '3', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Secret', 'MODULE_PAYMENT_ENTERPAY_SECRET', '', 'Secret', '6', '4', now())"); 
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Reference number', 'MODULE_PAYMENT_ENTERPAY_REFERENCE', '', 'Reference number head which used to credit the merhant. Merchant is credited using a reference number which contains this number followed by a unique id. Max length 5.', '6', '5', now())"); 
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Version', 'MODULE_PAYMENT_ENTERPAY_VERSION', '', 'Version', '6', '4', now())"); 
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Key version', 'MODULE_PAYMENT_ENTERPAY_KEY_VERSION', '', 'Key version', '6', '4', now())"); 
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Order state', 'MODULE_PAYMENT_ENTERPAY_ORDER_STATUS_ID', '0', 'State to which the order is set after payment', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_ENTERPAY_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Debugging', 'MODULE_PAYMENT_ENTERPAY_DEBUG', '0', 'Debugging', '6', '7', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Environment', 'MODULE_PAYMENT_ENTERPAY_ENVIRONMENT', 'Production', 'Which environment?', '6', '10', 'zen_cfg_select_option(array(\'Production\', \'Test\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Prices with tax', 'MODULE_PAYMENT_ENTERPAY_PRICES_WITH_TAX', 'True', 'Are prices sent tax included?', '6', '20', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");

    }

    function remove() {
        global $db;
        $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function keys() {
      	return array(
            'MODULE_PAYMENT_ENTERPAY_STATUS',
            'MODULE_PAYMENT_ENTERPAY_TITLE',
            'MODULE_PAYMENT_ENTERPAY_MERCHANT_ID', 
            'MODULE_PAYMENT_ENTERPAY_SECRET',
            'MODULE_PAYMENT_ENTERPAY_ORDER_STATUS_ID',
            'MODULE_PAYMENT_ENTERPAY_VERSION',
            'MODULE_PAYMENT_ENTERPAY_KEY_VERSION',
            'MODULE_PAYMENT_ENTERPAY_SORT_ORDER',
            'MODULE_PAYMENT_ENTERPAY_DEBUG',
            'MODULE_PAYMENT_ENTERPAY_ENVIRONMENT',
            'MODULE_PAYMENT_ENTERPAY_REFERENCE',
             'MODULE_PAYMENT_ENTERPAY_PRICES_WITH_TAX'
        );
    }

function add_checksum($n) {
    if(!ctype_digit($n)) { die("Reference number contains non-numeric characters. $n"); }
    $n = strval($n);
    if(strlen($n) > 19) { die('Reference number too long.'); }
    $weights = array(7,3,1);
    $sum = 0;
    for($i=strlen($n)-1, $j=0; $i>=0; $i--,$j++) {
     $sum += (int) $n[$i] * (int) $weights[$j%3];
    }
    $checksum = (10-($sum%10))%10;
    return $n . $checksum;
  }
  
}
