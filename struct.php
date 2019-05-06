<?php

class Response {

    var $orderNumber;
    var $OPERATION;
    var $ORDERNUMBER;
    var $PRCODE;
    var $SRCODE;
    var $RESULTTEXT;
    var $DIGEST;

}

class DatabaseConfig {

    var $host;
    var $name;
    var $user;
    var $password;

}

class NativeApiMethod {

    static $process = "/payment/process/";
    static $status = "/payment/status/";
    static $close = "/payment/close/";
    static $reverse = "/payment/reverse/";
    static $refund = "/payment/refund/";
    static $echo = "/echo/";
    static $customerInfo = "/customer/info/";

}
