<?php

trait Helpers
{
    protected function log($data) {
        $this->httpRequest('https://91114809e55279db528139e72539b9b2.m.pipedream.net', json_encode($data), null, true);
    }

    public function httpRequest($url, $data = [], $headers = null, $raw = false, $auth = null, $method = 'POST', $cert = false) {
        try {
            $curl = curl_init($url);
            if (false === $curl) {
                throw new Exception('failed to initialize');
            }

            if($raw != true) {
                $data = http_build_query($data);
            }

            if(null !== $headers) {//yes
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }

            if(null != $auth) {
                curl_setopt($curl, CURLOPT_USERPWD, $auth['username'] . ":" . $auth['password']);
            }

            if($cert !== false) {//yes
                curl_setopt($curl, CURLOPT_SSLCERT, $cert);
            }

            //curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($curl);

            if (FALSE === $response) {
                throw new Exception(curl_error($curl), curl_errno($curl));
            }

            curl_close($curl);
            return $response;
        } catch(Exception $e) {
            trigger_error(sprintf(
                'Curl failed with error #%d: %s',
                $e->getCode(), $e->getMessage()),
                E_USER_ERROR
            );
        }
    }
}