<?php

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Get module metadata
 *
 * @return array
 */
function quickpay_MetaData()
{
    return array(
        'DisplayName' => 'QuickPay',
        'APIVersion' => '1.1',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Get module configuration
 *
 * @return array
 */
function quickpay_config()
{
    quickpay_verify_table();

    $config = array(
        "FriendlyName" => array(
            "Type" => "System",
            "Value" => "Quickpay"
        ),
        "quickpay_versionnumber" => array("FriendlyName" => "Installed module version", "Type" => null, "Description" => "2.3.2", "Size" => "20", "disabled" => true),
        "whmcs_adminname" => array("FriendlyName" => "WHMCS administrator username", "Type" => "text", "Value" => "admin", "Size" => "20",),
        "merchant" => array("FriendlyName" => "Merchant ID", "Type" => "text", "Size" => "30",),
        "md5secret" => array("FriendlyName" => "Payment Window Api Key", "Type" => "text", "Size" => "60",),
        "apikey" => array("FriendlyName" => "API Key", "Type" => "text", "Size" => "60",),
        "private_key" => array("FriendlyName" => "Private Key", "Type" => "text", "Size" => "60",),
        "agreementid" => array("FriendlyName" => "Agreement ID", "Type" => "text", "Size" => "30",),
        "payment_type" => array("FriendlyName" => "Subscription", "Type" => "dropdown", "Options" => array(
            "payment" => "Standard Payment",
            "subscription" => "Subscription"
        )),
        "language" => array("FriendlyName" => "Language", "Type" => "dropdown", "Options" => "da,de,en,es,fi,fr,fo,kl,it,no,nl,pl,sv,ru",),
        "autofee" => array("FriendlyName" => "Autofee", "Type" => "dropdown", "Options" => "0,1",),
        "autocapture" => array("FriendlyName" => "Autocapture", "Type" => "dropdown", "Options" => "0,1",),
        "payment_methods" => array("FriendlyName" => "Payment Method", "Type" => "text", "Size" => "30", "Value" => "creditcard"),
        "prefix" => array("FriendlyName" => "Order Prefix", "Type" => "text", "Size" => "30",),
        "quickpay_branding_id" => array("FriendlyName" => "Branding ID", "Type" => "text", "Size" => "30",),
        "quickpay_google_analytics_tracking_id" => array("FriendlyName" => "Google Analytics Tracking ID", "Type" => "text", "Size" => "30",),
        "quickpay_google_analytics_client_id" => array("FriendlyName" => "Google Analytics Client ID", "Type" => "text", "Size" => "30",),
        "link_text" => array("FriendlyName" => "Pay now text", "Type" => "text", "Value" => "Pay Now", "Size" => "60",)
    );

    return $config;
}

/**
 * Create payment, get payment link and redirect
 *
 * @param $params
 * @return string
 */
function quickpay_link($params)
{
    $payment = quickpay_get_payment($params);

    $code = sprintf('<a href="%s">%s</a>', $payment, $params['link_text']);

    $cart = $_GET['a'];

    if ($cart == 'complete') {
        $invoiceId = $params['invoiceid'];
        header('Location: viewinvoice.php?id='.$invoiceId.'&qpredirect=true');
    }

    //Determine if we should autoredirect
    if ($_GET['qpredirect']) {
        $code .= '<script type="text/javascript">window.location.replace("' . $payment . '");</script>';
    }

    return $code;
}

/**
 * Get or create payment
 *
 * @param $params
 * @return mixed
 */
function quickpay_get_payment($params)
{
    //Get PDO and determine if payment exists
    $pdo = Capsule::connection()->getPdo();

    $statement = $pdo->prepare("SELECT * FROM quickpay_transactions WHERE invoice_id = :invoice_id");
    $statement->execute([
        ':invoice_id' => $params['invoiceid'],
    ]);

    $result = $statement->fetch();

    if ($result > 0) {
        return $result['payment_link'];
    }

    //If not create it
    if ($params['payment_type'] === 'subscription') {
        $paymentlink = quickpay_create_subscription($params);
    } else {
        $paymentlink = quickpay_create_payment($params);
    }

    return $paymentlink;
}

/**
 * Create QuickPay payment
 *
 * @param $params
 * @return mixed
 * @throws Exception
 */
function quickpay_create_payment($params)
{
    $apiKey = $params['apikey'];
    $invoiceId = $params['invoiceid'];
    $orderPrefix = $params['prefix'];
    $currencyCode = $params['currency'];

    $request = array(
        'order_id' => sprintf('%s%04d', $orderPrefix, $invoiceId), //Pad to length
        'currency' => $currencyCode,
    );

    $payment = quickpay_request($apiKey, '/payments', $request, 'POST');

    if (! isset($payment->id)) {
        throw new Exception('Failed to create payment');
    }

    $paymentLink = quickpay_create_payment_link($payment, $params);

    return $paymentLink;
}

/**
 * Create payment link
 *
 * @param $payment
 * @param $params
 * @return mixed
 * @throws Exception
 */
function quickpay_create_payment_link($payment, $params, $type = 'payment')
{
    $apiKey = $params['apikey'];
    $systemUrl = $params['systemurl'];
    $moduleName = $params['paymentmethod'];

    $request = array(
        "amount"                       => str_replace('.', '', $params['amount']),
        "continueurl"                  => $params['returnurl'],
        "cancelurl"                    => $params['returnurl'],
        "callbackurl"                  => $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php',
        "customer_email"               => $params['clientdetails']['email'],
        "payment_methods"              => $params['payment_methods'],
        "language"                     => $params['language'],
        "autocapture"                  => $params['autocapture'],
        "autofee"                      => $params['autofee'],
        "branding_id"                  => $params['quickpay_branding_id'],
        "google_analytics_tracking_id" => $params['quickpay_google_analytics_tracking_id'],
        "google_analytics_client_id"   => $params['quickpay_google_analytics_client_id'],
    );

    $endpoint = sprintf('payments/%s/link', $payment->id);

    if ($type === 'subscription') {
        $endpoint = sprintf('subscriptions/%s/link', $payment->id);
    }

    $paymentlink = quickpay_request($apiKey, $endpoint, $request, 'PUT');

    if (! isset($paymentlink->url)) {
        throw new Exception('Failed to create payment link');
    }

    //Save to database
    $pdo = Capsule::connection()->getPdo();

    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'INSERT INTO quickpay_transactions (invoice_id, transaction_id, payment_link) VALUES (:invoice_id, :transaction_id, :payment_link)'
        );

        $statement->execute([
            ':invoice_id' => $params['invoiceid'],
            ':transaction_id' => $payment->id,
            ':payment_link' => $paymentlink->url,
        ]);

        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        throw new Exception('Failed to create payment link, please try again later');
    }

    return $paymentlink->url;
}

/**
 * Create QuickPay subscription
 *
 * @param $params
 * @return mixed
 * @throws Exception
 */
function quickpay_create_subscription($params)
{
    $apiKey = $params['apikey'];
    $invoiceId = $params['invoiceid'];
    $orderPrefix = $params['prefix'];
    $currencyCode = $params['currency'];
    $description = $params['description'];

    $request = array(
        'order_id' => sprintf('%s%04d', $orderPrefix, $invoiceId), //Pad to length
        'currency' => $currencyCode,
        'description' => $description
    );

    $payment = quickpay_request($apiKey, '/subscriptions', $request, 'POST');

    if (! isset($payment->id)) {
        throw new Exception('Failed to create subscription');
    }

    $paymentLink = quickpay_create_payment_link($payment, $params, 'subscription');

    return $paymentLink;
}


/**
 * Signs the setup parameters.
 */
function sign($params, $api_key)
{
    ksort($params);
    $base = implode(" ", $params);

    return hash_hmac("sha256", $base, $api_key);
}

/**
 * Check for table and create if not exists
 */
function quickpay_verify_table()
{
    //Get PDO and check if table exists
    $pdo = Capsule::connection()->getPdo();

    $result = $pdo->query("SHOW TABLES LIKE 'quickpay_transactions'");
    $row = $result->fetch(PDO::FETCH_ASSOC);

    //If not create it
    if ($row === false) {
        quickpay_install_table($pdo);
    }
}

/**
 * Install quickpay table
 *
 * @param PDO $pdo
 */
function quickpay_install_table(PDO $pdo)
{
    $pdo->beginTransaction();

    try {
        $query = "CREATE TABLE IF NOT EXISTS `quickpay_transactions` (
			`id` int(10) NOT NULL AUTO_INCREMENT,
			`invoice_id` int(10) UNSIGNED NOT NULL,
			`transaction_id` int(32) UNSIGNED NOT NULL,
			`payment_link` varchar(255) NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `id` (`id`)
		) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

        $statement = $pdo->prepare($query);
        $statement->execute();

        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        logActivity('Error during quickpay table creation: '.$e->getMessage());
    }
}

/**
 * Perform a request to the QuickPay API
 *
 * @param $endpoint
 * @param array $params
 * @param string $method
 * @return mixed
 * @throws Exception
 */
function quickpay_request($apikey = '', $endpoint = '', $params = array(), $method = 'GET')
{
    $baseUrl = 'https://api.quickpay.net/';
    $url = $baseUrl . $endpoint;

    $headers = array(
        'Accept-Version: v10',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(':' . $apikey),
    );

    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_URL => $url,
        CURLOPT_POSTFIELDS => $params,
    );

    $ch = curl_init();

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    //Check for errors
    if (curl_errno($ch) !== 0) {
        throw new Exception(curl_error($ch), curl_errno($ch));
    }

    curl_close ($ch);

    return json_decode($response);
}