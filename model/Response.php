<?php
    class Response {
        private $_success;
        private $_httpStatusCode;
        private $_message = array();
        private $_data;
        private $_toCache = false;
        private $_responseData = array();

        public function setSuccess($success) {
            $this->_success = $success;
        }

        public function setHttpStatusCode($httpStatusCode) {
            $this->_httpStatusCode = $httpStatusCode;
        }

        public function addMessage($message) {
            $this->_message[] = $message;
        }

        public function setData($data) {
            $this->_data = $data;
        }

        public function toCache($toCache) {
            $this->_toCache = $toCache;
        }

        public function send() {
            header('Content-type: application/json;charset=utf-8');
            




            
        }


    }



?>