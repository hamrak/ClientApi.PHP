<?php

require_once('Requests.php');
require_once('DailyDiff.php');
require_once('DailyDiffList.php');

class FinstatDailyDiffApi
{
    private
        $apiUrl,
        $apiKey,
        $privateKey,
        $stationId,
        $stationName,
        $timeout,
        $limits;

    //
    // Constructor
    //
    public function __construct($apiUrl, $apiKey, $privateKey, $stationId, $stationName, $timeout = 10)
    {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->privateKey = $privateKey;
        $this->stationId = $stationId;
        $this->stationName = $stationName;
        $this->timeout = $timeout;
        $this->limits = null;
    }

    private function parseResponseRaw($response, $url, $parameter)
    {
        //parse limits
        $this->limits = array(
            "daily" => array(
                "current" => ($response->headers->offsetExists('finstat-daily-limit-current')) ? $response->headers->offsetGet('finstat-daily-limit-current') : null,
                "max"=> ($response->headers->offsetExists('finstat-daily-limit-max')) ? $response->headers->offsetGet('finstat-daily-limit-max') : null
            ),
            "monthly" => array(
                "current" => ($response->headers->offsetExists('finstat-monthly-limit-current')) ? $response->headers->offsetGet('finstat-monthly-limit-current') : null,
                "max"=> ($response->headers->offsetExists('finstat-monthly-limit-max')) ? $response->headers->offsetGet('finstat-monthly-limit-max') : null
            ),
        );

        if(!$response->success)
        {
            $dom = new DOMDocument();
            $dom->loadHTML($response->body);
            switch($response->status_code)
            {
                case 404:
                    if(isset($parameter) && !empty($parameter)) {
                        throw new Requests_Exception("Invalid URL: '{$url}' or specified parameter: '{$parameter}' not found in database!", 'FinstatApi', $dom->textContent, $response->status_code);
                    } else {
                        throw new Requests_Exception("Invalid URL: '{$url}'!", 'FinstatApi', $dom->textContent, $response->status_code);
                    }

                case 402:
                    throw new Requests_Exception('Limit reached!', 'FinstatApi', $dom->textContent, $response->status_code);

                case 403:
                    throw new Requests_Exception('Access Forbidden!', 'FinstatApi', $dom->textContent, $response->status_code);

                default:
                    throw new Requests_Exception('Unknown exception while communication with Finstat api!', 'FinstatApi', $dom->textContent, $response->status_code);
            }
        }
}

    private function parseResponse($response, $url, $parameter)
    {

        $this->parseResponseRaw($response, $url, $parameter);
        $detail = simplexml_load_string($response->body);

        if($detail === FALSE)
            throw new Requests_Exception('Error while parsing XML data.', 'FinstatApi');

        return $detail;
    }

    public function RequestListOfDailyDiffs()
    {
        if(!class_exists('Requests'))
        {
            trigger_error("Unable to load Requests class", E_USER_WARNING);
            return false;
        }

        Requests::register_autoloader();

        $options = array(
            'timeout' => $this->timeout,
            'follow_redirects' => false,
            'auth' => false
        );

        $data = array(
            'apiKey' => $this->apiKey,
            'Hash' => $this->ComputeVerificationHash(null),
            'StationId' => $this->stationId,
            'StationName' => $this->stationName
        );

        $url = $this->apiUrl. "/GetListOfDiffs";

        try
        {
            $response = Requests::post($url, null, $data, $options);
        }
        catch(Requests_Exception $e)
        {
            throw $e;
        }


        $detail = $this->parseResponse($response, $url, null);
        if($detail != false)
        {
            $result = new DailyDiffList();
            $result->Version = (string) $detail->Version;
            $result->Files = array();
            if (!empty($detail->Files) && isset($detail->Files->DailyDiff) && !empty($detail->Files->DailyDiff)) {
                foreach ($detail->Files->DailyDiff as $element) {
                    $result->Files[] = $this->ParseDailyDiff($element);
                }
            }

            return $result;
        }

        return null;
    }

    private function ParseDailyDiff($detail)
    {
        if($detail != false)
        {
            $result = new DailyDiff();
            $result->FileName = (string) $detail->FileName;
            $result->FileSize = (int) $detail->FileSize;
            $result->GeneratedDate = $this->parseDate($detail->GeneratedDate);

            return $result;
        }

        return null;
    }

    public function DownloadDailyDiffFile($fileName, $exportPath)
    {
        if(!class_exists('Requests'))
        {
            trigger_error("Unable to load Requests class", E_USER_WARNING);
            return false;
        }

        Requests::register_autoloader();

        $options = array(
            'timeout' => $this->timeout,
            'follow_redirects' => false,
            'auth' => false
        );

        $data = array(
            'apiKey' => $this->apiKey,
            'fileName' => $fileName,
            'Hash' => $this->ComputeVerificationHash($fileName),
            'StationId' => $this->stationId,
            'StationName' => $this->stationName
        );
        $url = $this->apiUrl. "/GetFile";

        try
        {
            $response = Requests::post($url, null, $data, $options);
        }
        catch(Requests_Exception $e)
        {
            throw $e;
        }

        $this->parseResponseRaw($response, $url, $fileName);

        return file_put_contents($exportPath, $response->body);
    }

    public function GetAPILimits()
    {
        if(empty($this->limits))
        {
            throw new  Exception('Limits are available after API call');
        }

        return $this->limits;
    }

    //
    // Compute verification hash
    //
    private function ComputeVerificationHash($parameter)
    {
        $data = sprintf("SomeSalt+%s+%s++%s+ended", $this->apiKey, $this->privateKey, $parameter);

        return hash('sha256', $data);
    }

    /**
     * Parses date string received from API and returns DateTime object or null.
     *
     * @param SimpleXMLElement $date
     * @return DateTime|null
     */
    private function parseDate(SimpleXMLElement $date = null) {

        if (empty($date) || !((string) $date)) {
          return null;
        }

        return new DateTime($date);
    }

}
