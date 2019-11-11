<?php

require(DIR_WS_MODULES . 'require_languages.php');

if (MODULE_PAYMENT_PAYSTATION_POSTBACK == 'No') {
    echo "Postback not enabled in Paystation module for Zencart - post response ignored";
    exit();
}

$xml = file_get_contents('php://input');
$xml = simplexml_load_string($xml);

if (!empty($xml)) {
    $errorCode = (int)$xml->ec;
    $errorMessage = $xml->em;
    $transactionId = $xml->ti;
    $cardType = $xml->ct;
    $merchantReference = $xml->merchant_ref;
    $testMode = $xml->tm;
    $merchantSession = $xml->MerchantSession;
    $usedAcquirerMerchantId = $xml->UsedAcquirerMerchantID;
    $amount = $xml->PurchaseAmount; // Note this is in cents
    $transactionTime = $xml->TransactionTime;
    $requestIp = $xml->RequestIP;

    $message = "Error Code: " . $errorCode . "<br/>";
    $message .= "Error Message: " . $errorMessage . "<br/>";
    $message .= "Transaction ID: " . $transactionId . "<br/>";
    $message .= "Card Type: " . $cardType . "<br/>";
    $message .= "Merchant Reference: " . $merchantReference . "<br/>";
    $message .= "Test Mode: " . $testMode . "<br/>";
    $message .= "Merchant Session: " . $merchantSession . "<br/>";
    $message .= "Merchant ID: " . $usedAcquirerMerchantId . "<br/>";
    $message .= "Amount: " . $amount . " (cents)<br/>";
    $message .= "Transaction Time: " . $transactionTime . "<br/>";
    $message .= "IP: " . $requestIp . "<br/>";

    $ti = $xml->ti->__toString();

    $h = transaction_already_processed($ti);

    if ($errorCode == '0' && !transaction_already_processed($ti)) {
        /* checkout_process.php contains the code that generates a row
         * in the order table and sends an email. however, it relies on 
         * the session variable containg a minimum of data.
         */
        require_once(DIR_WS_LANGUAGES . 'english/checkout_process.php');

        $sql = "select * from " . TABLE_PAYSTATION_SESSION . " where pstn_ms='$merchantSession'";

        $row = $db->Execute($sql);

        if ($row->fields['transaction_mode'] != MODULE_PAYMENT_PAYSTATION_TESTMODE) exit();

        $_SESSION['customer_id'] = $row->fields['customer_id'];
        $_SESSION["shipping"] = json_decode($row->fields['shipping_details'], true);

        $_SESSION["shipping"]["cost"] = (float)$_SESSION["shipping"]["cost"];
        $cost = $_SESSION["shipping"];

        $_SESSION['pstn_ti'] = $ti;

        $_SESSION['billto'] = (int)$row->fields['billto'];
        $_SESSION['sendto'] = (int)$row->fields['sendto'];

        $_SESSION["payment"] = "paystation";
        $_SESSION['postback_process'] = true; //used in paymentclass->before_process

        $cart = new shoppingCart();

        $cart->restore_contents();
        $_SESSION['cart'] = $cart;

        require_once(DIR_WS_MODULES . 'checkout_process.php');
        $payment_modules->after_process();

        $_SESSION['cart']->reset(true);
        exit();

        // unregister session variables used during checkout
        unset($_SESSION['sendto']);
        unset($_SESSION['billto']);
        $payment_modules->after_process();

        $_SESSION['cart']->reset(true);

        // unregister session variables used during checkout
        unset($_SESSION['sendto']);
        unset($_SESSION['billto']);
        unset($_SESSION['shipping']);
        unset($_SESSION['payment']);
        unset($_SESSION['comments']);
        //$order_total_modules->clear_posts();
    }
}

exit();

function transaction_already_processed($pstn_ti)
{
    global $_POST, $_GET, $insert_id, $db;
    $ti_query = $db->Execute("select pstn_ti from  " . TABLE_ORDERS . " where pstn_ti = '$pstn_ti'");
    $count = $ti_query->RecordCount();
    return ($count >= 1);
}
