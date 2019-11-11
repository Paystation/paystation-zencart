<?php

function directTransaction($url, $params)
{
    $defined_vars = get_defined_vars();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, $defined_vars['HTTP_USER_AGENT']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

class paystation
{
    var $code, $title, $description, $enabled;
    var $ms, $ti, $psid;

    function paystation()
    {
        global $order;

        $this->code = 'paystation';
        $this->title = "Paystation Payment Gateway ";
        $this->description = "Paystation three-party payment module";
        $this->sort_order = MODULE_PAYMENT_PAYSTATION_SORT_ORDER;
        $this->enabled = ((MODULE_PAYMENT_PAYSTATION_STATUS == 'Enabled') ? true : false);
        if ((int)MODULE_PAYMENT_PAYSTATION_ORDER_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_PAYSTATION_ORDER_STATUS_ID;
        }

        if (is_object($order))
            $this->update_status();
    }

    function update_status()
    {
        global $order, $db;

        if (($this->enabled == true) && ((int)MODULE_PAYMENT_PAYSTATION_ZONE > 0)) {
            $check_flag = false;
            $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PAYSTATION_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");
            while (!$check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    // this method returns the javascript that will validate the form entry
    function javascript_validation()
    {
        //echo "alert('I just got submitted!!');";
        return false;
    }

    // this method returns the html that creates the input form
    function selection()
    {
        $selection = array('id' => $this->code, 'module' => MODULE_PAYMENT_PAYSTATION_DISPLAY_TITLE);
        return $selection;
    }

    // this method is called before the data is sent to the credit card processor
    // here you can do any field validation that you need to do
    // we also set the global variables here from the form values
    function pre_confirmation_check()
    {
        return false;
    }

    // this method returns the data for the confirmation page
    function confirmation()
    {
        $paystation_return = urlencode($_POST['payment'] . '|' . $_POST['sendto'] . '|' . $shipping_cost . '|' . urlencode($shipping_method) . '|' . urlencode($comments) . '&' . SID);
        $checkout_form_action = $paystation;
        return false;
    }

    // this method performs the authorization by sending the data to the processor, and getting the result
    function process_button()
    {
        //all processing is done by cURL and redirect now in before_process()
    }

    function makePaystationSessionID($length = 8)
    {
        $token = "";
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
        for ($i = 0; $i < $length; $i++) {
            $token .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $token;
    }

    // this method gets called after the processing is done but before the app server
    // accepts the result. It is used to check for errors.
    function before_process()
    {
        global $_POST, $_GET, $order, $db, $messageStack;

        if (isset($_SESSION['postback_process']) && $_SESSION['postback_process']) return;

        if (!$_GET['zenid']) {
            $tempSession = $this->makePaystationSessionID(8) . time();

            $sql = ("insert into " . TABLE_PAYSTATION_SESSION . " 
                (pstn_ms, shipping_details, sendto, billto, customer_id, transaction_mode)
                values ('$tempSession', '" . json_encode($_SESSION['shipping']) . "', " .
                $_SESSION['sendto'] . ", " . $_SESSION['billto'] . ", " .
                $_SESSION['customer_id'] . ", '" . MODULE_PAYMENT_PAYSTATION_TESTMODE . "')");

            $db->Execute($sql);

            $psamount = (number_format($order->info['total'], 2, '.', '') * 100);

            $paystationURL = "https://www.paystation.co.nz/direct/paystation.dll";

            $name = $_SESSION["customer_first_name"] . ' ' . $_SESSION["customer_last_name"];
            $name = str_replace("'", '', $name);

            $mr = $_SESSION['customer_id'] . ":" . time() . ":" . $name;
            $pstn_mr = urlencode($mr);

            $paystationParams = "paystation=_empty&pstn_am=" . $psamount .
                "&pstn_af=cents&pstn_pi=" . MODULE_PAYMENT_PAYSTATION_ID .
                "&pstn_gi=" . MODULE_PAYMENT_PAYSTATION_GATEWAY_ID .
                "&pstn_ms=" . $tempSession . "&pstn_nr=t&pstn_mr=" . $pstn_mr;


            $_SESSION['paystation_id'] = MODULE_PAYMENT_PAYSTATION_ID;
            $_SESSION['$paystation_ms'] = $tempSession;

            if (MODULE_PAYMENT_PAYSTATION_TESTMODE == 'Test') {
                $paystationParams .= '&pstn_tm=t';
            }

            $paystationParams .= '&main_page=checkout_process';
            $paystationParams .= '&' . zen_session_name() . '=' . zen_session_id();

            $authResult = directTransaction($paystationURL, $paystationParams);

            $p = xml_parser_create();
            xml_parse_into_struct($p, $authResult, $vals, $tags);
            xml_parser_free($p);

            for ($j = 0; $j < count($vals); $j++) {
                if (!strcmp($vals[$j]["tag"], "TI") && isset($vals[$j]["value"])) {
                    $returnTI = $vals[$j]["value"];
                }
                if (!strcmp($vals[$j]["tag"], "EC") && isset($vals[$j]["value"])) {
                    $errorCode = $vals[$j]["value"];
                }
                if (!strcmp($vals[$j]["tag"], "EM") && isset($vals[$j]["value"])) {
                    $errorMessage = $vals[$j]["value"];
                }
                if (!strcmp($vals[$j]["tag"], "DIGITALORDER") && isset($vals[$j]["value"])) {
                    $digitalOrder = $vals[$j]["value"];
                }
            }
            if ($digitalOrder) {
                //initiation success
                zen_redirect($digitalOrder);
            } else {
                $messageStack->add_session('checkout_payment', "Paystation returned with the following error: " . $errorMessage, 'error');
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . $errorMessage, 'SSL', true, false));
            }
        } else {
            $success = false;
            if (isset($_GET['ec'])) {
                if ($_GET['ec'] == '0') {
                    $result = $this->_transactionVerification($_SESSION['paystation_id'], $_GET['ti'], $_SESSION['paystation_ms']);
                    if ($result == '0') {
                        $success = true;

                        if (MODULE_PAYMENT_PAYSTATION_POSTBACK == 'Yes' && $this->transaction_already_processed($_GET['ti'])) {
                            //If the transaction is already processed because of postback response, then skip
                            // to the checkout success page without processing the order again.
                            zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL', true, false));
                        }
                    }
                }
            }
            if ($success == false) {
                #Error in the Transaction
                $messageStack->add_session('checkout_payment', "Paystation returned with the following error: " . $_GET['em'], 'error');
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
            }
        }
        return false;
    }

    function transaction_already_processed($pstn_ti)
    {
        global $_POST, $_GET, $insert_id, $db;
        $ti_query = $db->Execute("select pstn_ti from  " . TABLE_ORDERS . " where pstn_ti = '$pstn_ti'");


        $count = $ti_query->RecordCount();
        return ($count >= 1);
    }

    function after_process()
    {
        $this->store_pstn_ti();
    }

    function store_pstn_ti()
    {
        //Stores the paystation transaction ID to make sure order isn't created twice.

        global $_POST, $_GET, $insert_id, $db;
        if (isset($_GET['ti'])) $ti = $_GET['ti'];
        elseif (isset($_SESSION['pstn_ti'])) $ti = $_SESSION['pstn_ti'];

        $db->Execute("update " . TABLE_ORDERS . " set pstn_ti = '$ti' where orders_id = " . $insert_id);
        return false;
    }

    function check()
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYSTATION_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    function install()
    {
        global $db;

        $postbackMessage = "We strongly suggest setting \\'Enable Postback\\' to \\'Yes\\' as it will allow the cart to capture payment results even
                    if your customers re-direct is interrupted. However, if your development/test environment is local or on a network
                    that cannot receive connections from the internet, you must set \\'Enable Postback\\' to \\'No\\'.<br/><br/>
                    Your Paystation account needs to reflect your Zencart settings accurately, otherwise order status will not update correctly.
                    Email <b>support@paystation.co.nz</b> with your Paystation ID and advise whether \\'Enable Postback\\' is set to \\'Yes\\' or \\'No\\' in 
                    your Zencart settings.";

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Postback', 'MODULE_PAYMENT_PAYSTATION_POSTBACK', 'Yes', '" . $postbackMessage . "' , '6', '5', 'zen_cfg_select_option(array(\'Yes\', \'No\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Paystation Module', 'MODULE_PAYMENT_PAYSTATION_STATUS', 'Enabled', 'Allows customers to select Paystation as a payment method', '6', '0', 'zen_cfg_select_option(array(\'Enabled\', \'Disabled\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Paystation ID', 'MODULE_PAYMENT_PAYSTATION_ID', '', 'Your Paystation ID as supplied - usually a six digit number', '6', '1', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Gateway ID', 'MODULE_PAYMENT_PAYSTATION_GATEWAY_ID', '', 'Your Paystation Gateway ID as supplied', '6', '2', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('HMAC Key', 'MODULE_PAYMENT_PAYSTATION_HMAC_KEY', '', 'HMAC key supplied by paystation', '6', '3', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Title', 'MODULE_PAYMENT_PAYSTATION_DISPLAY_TITLE', 'Credit card - you will be redirected to Paystation Payment Gateway to complete your payment', 'The text to display next to the payment in the checkout', '6', '4', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Transaction Mode', 'MODULE_PAYMENT_PAYSTATION_TESTMODE', 'Test', 'Transaction mode used for processing orders.  Used for testing after the initial \'go live\'.  You need to coordinate with Paystation during your initial launch.', '6', '6', 'zen_cfg_select_option(array(\'Test\', \'Production\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_PAYSTATION_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '7', now())");

        //Add a column to the order table to store the transaction ID when a transaction is successful
        $check_pstn_ti_query = $db->Execute("show columns from " . TABLE_ORDERS . " LIKE 'pstn_ti'");
        if ($check_pstn_ti_query->RecordCount() < 1) {
            $db->Execute("alter table " . TABLE_ORDERS . " add column pstn_ti varchar(100) after payment_method");
        }

        $db->Execute("CREATE TABLE IF NOT EXISTS paystation_session (
                        `unique_id` INT(11) NOT NULL AUTO_INCREMENT,
                        `pstn_ms` VARCHAR(50) NOT NULL,
                        `shipping_details` VARCHAR(500) NOT NULL DEFAULT '0',
                        `sendto` INT(11) NOT NULL DEFAULT '0',
                        `billto` INT(11) NOT NULL DEFAULT '0',
                        `customer_id` INT(11) NOT NULL DEFAULT '0',
                        `transaction_mode` VARCHAR(20) NOT NULL DEFAULT '0',
                        PRIMARY KEY (`unique_id`))");
    }

    function remove()
    {
        global $db;
        $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
        $db->Execute("drop table if exists " . TABLE_PAYSTATION_SESSION . " ");
    }

    function keys()
    {
        return array('MODULE_PAYMENT_PAYSTATION_STATUS', 'MODULE_PAYMENT_PAYSTATION_TESTMODE', 'MODULE_PAYMENT_PAYSTATION_ZONE', 'MODULE_PAYMENT_PAYSTATION_DISPLAY_TITLE',
            'MODULE_PAYMENT_PAYSTATION_ID', 'MODULE_PAYMENT_PAYSTATION_POSTBACK', 'MODULE_PAYMENT_PAYSTATION_GATEWAY_ID', 'MODULE_PAYMENT_PAYSTATION_HMAC_KEY', 'MODULE_PAYMENT_PAYSTATION_SORT_ORDER');
    }

    private function _transactionVerification($paystationID, $transactionID, $merchantSession)
    {

        $paystationID = rtrim($paystationID);
        $transactionVerified = '';
        $lookupXML = $this->_quickLookup($paystationID, 'ti', $transactionID);
        $p = xml_parser_create();
        xml_parse_into_struct($p, $lookupXML, $vals, $tags);
        xml_parser_free($p);
        foreach ($tags as $key => $val) {
            if ($key == "PAYSTATIONERRORCODE") {
                for ($i = 0; $i < count($val); $i++) {
                    $responseCode = $this->_parseCode($vals);
                    $transactionVerified = $responseCode;
                }
            }
        }

        return $transactionVerified;
    }

    function _quickLookup($pi, $type, $value)
    {
        $url = "https://payments.paystation.co.nz/lookup/"; //
        $params = "&pi=$pi&$type=$value";

        $authenticationKey = MODULE_PAYMENT_PAYSTATION_HMAC_KEY;
        $hmacWebserviceName = 'paystation';
        $pstn_HMACTimestamp = time();

        $hmacBody = pack('a*', $pstn_HMACTimestamp) . pack('a*', $hmacWebserviceName) . pack('a*', $params);
        $hmacHash = hash_hmac('sha512', $hmacBody, $authenticationKey);
        $hmacGetParams = '?pstn_HMACTimestamp=' . $pstn_HMACTimestamp . '&pstn_HMAC=' . $hmacHash;

        $url .= $hmacGetParams;
        $defined_vars = get_defined_vars();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        curl_close($ch);

        $h = htmlspecialchars($result);

        return $result;
    }

    private function _parseCode($mvalues)
    {
        $result = '';
        for ($i = 0; $i < count($mvalues); $i++) {
            if (!strcmp($mvalues[$i]["tag"], "QSIRESPONSECODE") && isset($mvalues[$i]["value"])) {
                $result = $mvalues[$i]["value"];
            }
        }
        return $result;
    }
}
