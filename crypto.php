<?php

require_once ('logger.php');

class Constants {

    static $SHOP_CART_QUANTITY = 1;
}

function createPaymentInitData($merchantId, $payId, $orderNo, $totalAmount, $returnUrl, $cartDesc, $description, $customerId, $privateKey, $privateKeyPassword, $publicKey) {

    $currency = get_woocommerce_currency();

    $totalAmount =  $totalAmount * 100;

    $data = array(
        "MERCHANTNUMBER" => (string) $merchantId,
        "OPERATION" => "CREATE_ORDER",
        "ORDERNUMBER" => $payId,
        "AMOUNT" => $totalAmount,
        "CURRENCY" => (int) 978, // EUR
        "DEPOSITFLAG" => 0,
        "MERORDERNUM" => (int) $orderNo,
        "URL" => (string) $returnUrl,
        "DESCRIPTION" => $cartDesc
    );

    $sign = new CSignature(dirname(__FILE__) . $privateKey, $privateKeyPassword, dirname(__FILE__) . $publicKey);
    $string = implode('|', $data);
    $signature = $sign->sign($string);

    $verify = $sign->verify($string, $signature);

    $data["DIGEST"] = $signature;

    return $data;
}

class CSignature{
	var $privateKey, $privateKeyPassword, $publicKey;
  // parametry: jmeno souboru soukromeho klice, privateKeyPassword k soukromemu klici, jmeno souboru s publicKeym klicem
  // params: name of the private key, private key password, name of the public key
  // function CSignature($privateKey="./key/test_key.pem", $privateKeyPassword="111111", $publicKey="./key/gpe.signing_test.pem"){
	function __construct($privateKey, $privateKeyPassword, $publicKey){
	  $fp = fopen($privateKey, "r");
	  $this->privateKey = fread($fp, filesize($privateKey));
	  fclose($fp);
	  $this->privateKeyPassword=$privateKeyPassword;

	  $fp = fopen($publicKey, "r");
	  $this->publicKey = fread($fp, filesize($publicKey));
	  fclose($fp);
	}
	
	function sign($text){
	  $pkeyid = openssl_get_privatekey($this->privateKey, $this->privateKeyPassword);
	  openssl_sign($text, $signature, $pkeyid);
	  $signature = base64_encode($signature);
	  openssl_free_key($pkeyid);
	  return $signature;
	}
	
	function verify($text, $signature){
	  $pubkeyid = openssl_get_publickey($this->publicKey);
	  $signature = base64_decode($signature);
	  $result = openssl_verify($text, $signature, $pubkeyid);
	  openssl_free_key($pubkeyid);
	  return (($result==1) ? true : false);
	}
}