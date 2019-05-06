<?php

require_once("crypto.php");
require_once("logger.php");
require_once("struct.php");

class GpWebPay {

    public $log = null;
    private $mysqlConfig = null;
    private $dbConnect = null;

    public function __construct($databaseConfig) {
        //global $mysql;
        $this->log = new Logger();
        $this->mysqlConfig = array(
            'host' => $databaseConfig->host,
            'name' => $databaseConfig->name,
            'user' => $databaseConfig->user,
            'password' => $databaseConfig->password
        );
        $this->dbConnect = $this->sqlconnect($this->mysqlConfig);
    }

    public function insertTransaction($orderNumber, $cart) {
        $sql = "INSERT INTO gpTransaction(orderNumber, paymentStatus, created, updated, cart) VALUES ("
                . $this->toSql($orderNumber) . ", "
                . "'processing' , "
                . "NOW()" . ", "
                . $this->toSql(null) . ", "
                . $this->toSql(json_encode($cart)) . ")";
        $this->log->write($sql);
        $id = $this->sqlExecutewithid($sql);
        return $id;
    }

    public function updateTransaction($payId, $cart) {
        $sql = "update gpTransaction set cart = " . $this->toSql(json_encode($cart)) . " where payId = " . $this->toSql($payId);
        $this->log->write($sql);
        $this->sqlExecute($sql);
    }

    public function updateTransactionStatus($payId, $status) {
        $sql = "UPDATE gpTransaction SET paymentStatus=" . $this->toSql($status) . ", updated=" . "NOW()" . " WHERE payId = " . $this->toSql($payId);
        $this->log->write($sql);
        $this->sqlExecute($sql);
    }

    public function selectTransaction($orderNumber) {
        $sql = "SELECT * FROM gpTransaction WHERE orderNumber=" . $orderNumber . " ORDER BY payId DESC LIMIT 1 ;";
        $result = $this->sqlExecute($sql);
        $row = mysqli_fetch_assoc($result);
        $this->log->write($sql);
        return $row;
    }

    /*function clearTransaction($orderNumber, $response) {
        $sql = "UPDATE gpTransaction SET paymentStatus = " . $this->toSql($response->paymentStatus) . ", authCode = " . $this->toSql(null) . ", updated=NOW(), payId=" . $this->toSql(null) . " WHERE orderNumber = " . $this->toSql($orderNumber);
        $this->log->write($sql);
        $this->sqlExecute($sql);
    }*/

    private function sqlconnect($mysql) {

        $dbconn = mysqli_connect($mysql['host'], $mysql['user'], $mysql['password']);
        mysqli_select_db($dbconn, $mysql['name']) or die(mysqli_error($this->dbConnect));
        mysqli_query($dbconn, "SET NAMES 'UTF-8'");
        return $dbconn;
    }

    private function sqlexecute($sql) {
        $res = mysqli_query($this->dbConnect, $sql) or trigger_error(mysqli_error($this->dbConnect) . "<br/>SQL: " . $sql, E_USER_ERROR);
        return $res;
    }

    private function sqlexecutewithid($sql) {
        if (mysqli_query($this->dbConnect, $sql)) {
            $last_id = mysqli_insert_id($this->dbConnect);
        }
        return $last_id;
    }

    private function sqlquery($sql) {
        $res = $this->sqlexecute($sql);

        $ar = array();
        while ($row = mysqli_fetch_array($res)) {
            $ar[] = $row;
        }

        return $ar;
    }

    private function toSql($text) {
        if (is_null($text)) {
            return "null";
        } else {
            return "'" . mysqli_real_escape_string($this->dbConnect, $text) . "'";
        }
    }

}
