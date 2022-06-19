<?php

namespace App;

use App\IncontactApi;
use Klein\Response;

class IncontactAction
{
    protected $session;
    protected $sessionToken;
    protected $api;

    public function __construct($session)
    {
        $this->session = $session;
        $this->sessionToken = $this->session->getId();
        $this->api = new IncontactApi();
        $this->getAccessToken();
    }

    /**
     * Generate the token
     * @return bool
     */
    protected function getAccessToken(): bool
    {
        $accessKey = $this->session->get("access_token");
        if (!$accessKey) {
            if (!$this->isExtraSession()) {
                $accessKeyData = $this->api->getAccessKey();
                if (!isset($accessKeyData["access_token"])) return false;
                if (!isset($accessKeyData["token_type"])) return false;

                $apiEndpointData = $this->api->getApiEndpoint($accessKeyData["access_token"], $accessKeyData["token_type"]);
                if (!isset($apiEndpointData['api_endpoint'])) return false;

                $accessKeyData["api_endpoint"] = $apiEndpointData["api_endpoint"];
                $this->setSessionValues($accessKeyData, true);
            }
        } else if (!$this->validateTokenOnTime($this->session->get("expires_in"))) {
            $this->session->delete("access_token");
            return $this->getAccessToken();
        } else {
            $this->api->setCommonHeaders($this->session->get("access_token"), $this->session->get("token_type"));
            $this->api->setApiEndpoint($this->session->get("api_endpoint"));
        }
        return true;
    }

    /**
     * Set the values for the session
     * @param array $accessKeyData
     * @param bool $saveFile = true
     */
    protected function setSessionValues(array $accessKeyData, bool $saveFile = false)
    {
        $accessKeyData["expires_in"] = $accessKeyData["expires_in"] + time();
        $this->session->set("access_token", $accessKeyData["access_token"]);
        $this->session->set("expires_in", $accessKeyData["expires_in"]);
        $this->session->set("token_type", $accessKeyData["token_type"]);
        $this->session->set("refresh_token", $accessKeyData["refresh_token"]);
        $this->session->set("api_endpoint", $accessKeyData["api_endpoint"]);

        $this->api->setCommonHeaders($accessKeyData["access_token"], $accessKeyData["token_type"]);
        $this->api->setApiEndpoint($accessKeyData["api_endpoint"]);

        if ($saveFile) {
            $fileName = sys_get_temp_dir() . "/" . $this->sessionToken;
            $tmpFile = fopen($fileName, "w") or die;
            fwrite($tmpFile, json_encode($accessKeyData));
            fclose($tmpFile);
        }
    }

    /**
     * Validate if session is extra session
     * @return bool
     */
    protected function isExtraSession(): bool
    {
        if (!$this->session->isExtraSession()) return false;

        $data = $this->getInfoFromFile();
        if (count($data) == 0) return false;

        $this->setSessionValues($data);
        return true;
    }

    /**
     * Get information from file
     * @return array
     */
    protected function getInfoFromFile(): array
    {
        $sessionToken = str_replace($_ENV['EXTRA_SESSION_SUFFIX'], '', $this->sessionToken);
        $fileName = sys_get_temp_dir() . '/' . $sessionToken;

        if (file_exists($fileName)) {
            $handle = fopen($fileName, 'r');
            $data = json_decode(fgets($handle), true);

            if (isset($data["access_token"]) && isset($data["expires_in"]) && $this->validateTokenOnTime($data["expires_in"])) {
                return $data;
            }
        }
        return [];
    }

    /**
     * Validate the time of expiration
     * @param int $expiration
     * @return bool
     */
    protected function validateTokenOnTime(int $expiration): bool
    {
        if ($expiration - 5 < time()) {
            return false;
        }
        return true;
    }

    /**
     * Set the code of the response
     * @param array $result
     * @param Response $response
     * @return array $result
     */
    protected function setCodeResponse(array $result, Response $response): array
    {
        if (isset($result["code"]) && $result["code"] > 0) {
            $response->code($result["code"]);
            unset($result["code"]);
        }
        return $result;
    }

    /**
     * Get hours of operation
     * @param array $queryParams
     * @param Response $response
     * @return array
     */
    public function getHoursOfOperation(array $queryParams, Response $response): array
    {
        $profileIdHoursOperation = '';
        if (isset($queryParams['profileIdHoursOperation']) && $queryParams['profileIdHoursOperation'] !== '') {
            $profileIdHoursOperation = $queryParams['profileIdHoursOperation'];
        }

        $hoursOfOperation = $this->api->getHoursOfOperation($profileIdHoursOperation);
        if ($profileIdHoursOperation !== '' && isset($hoursOfOperation['resultSet']['hoursOfOperationProfiles'])) {
            foreach ($hoursOfOperation['resultSet']['hoursOfOperationProfiles'] as $hours) {
                if ($hours['profileId'] === $profileIdHoursOperation) {
                    $hoursOfOperation['resultSet']['hoursOfOperationProfiles'] = [];
                    $hoursOfOperation['resultSet']['hoursOfOperationProfiles'][0] = $hours;
                    break;
                }
            }
        }
        return $this->setCodeResponse($hoursOfOperation, $response);
    }

    /**
     * Get agents availability
     * @param array $queryParams
     * @param Response $response
     * @return array
     */
    public function getAgentsAvailability(array $queryParams, Response $response): array
    {
        $agentsList = $this->api->getAgentsAvailability($queryParams);
        if (isset($queryParams['teamId']) && $queryParams['teamId'] > 0 && !isset($agentsList['error'])) {
            $agentsTmp = [];
            foreach ($agentsList['agentStates'] as $agent) {
                if ($agent['teamId'] == $queryParams['teamId']) {
                    $agentsTmp[] = $agent;
                }
            }
            if (count($agentsTmp) > 0) {
                $agentsList['agentStates'] = $agentsTmp;
            }
        }
        return $this->setCodeResponse($agentsList, $response);
    }

    /**
     * Get the chat profile
     * @param array $queryParams
     * @param Response $response
     * @return array
     */
    public function getChatProfile(array $queryParams, Response $response): array
    {
        if (!isset($queryParams['pointOfContact'])) return $this->errorParamsResponse($response, 'pointOfContact');

        $data = $this->api->getChatProfile($queryParams);
        if (isset($data["code"]) && $data["code"] == 400) {
            $data["code"] = 204;
        }
        return $this->setCodeResponse($data, $response);
    }

    /**
     * Start the session with the chat
     * @param string $params
     * @param Response $response
     * @return array
     */
    public function makeChat(string $params, Response $response): array
    {
        $paramsDecoded = json_decode($params, true);
        if (!$paramsDecoded) return $this->errorParamsResponse($response);
        if (!isset($paramsDecoded['pointOfContact'])) return $this->errorParamsResponse($response, 'pointOfContact');
        if (!isset($paramsDecoded['fromAddress'])) return $this->errorParamsResponse($response, 'fromAddress');

        $data = $this->api->makeChat($paramsDecoded);
        return $this->setCodeResponse($data, $response);
    }

    /**
     * Get the response from agent
     * @param array $queryParams
     * @return array
     */
    public function getResponse(array $queryParams, Response $response): array
    {
        if (!isset($queryParams['chatSessionId'])) return $this->errorParamsResponse($response);

        $data = $this->api->getResponse($queryParams);
        return $this->setCodeResponse($data, $response);
    }

    /**
     * Send a message to agent
     * @param array $queryParams
     * @param string $bodyParams
     * @param Response $response
     * @return array
     */
    public function sendText(array $queryParams, string $bodyParams, Response $response): array
    {
        if (!isset($queryParams['chatSessionId'])) return $this->errorParamsResponse($response);

        $data = $this->api->sendText($queryParams, $bodyParams);
        return $this->setCodeResponse($data, $response);
    }

    /**
     * End the chat
     * @param array $queryParams
     * @param Response $response
     * @return array
     */
    public function endChat(array $queryParams, Response $response): array
    {
        if (!isset($queryParams['chatSessionId'])) return $this->errorParamsResponse($response);

        $data = $this->api->endChat($queryParams);

        $this->session->delete("access_token");
        $sessionToken = str_replace($_ENV['EXTRA_SESSION_SUFFIX'], '', $this->sessionToken);

        $fileName = sys_get_temp_dir() . "/" . $sessionToken;
        if (file_exists($fileName)) @unlink($fileName);

        return $this->setCodeResponse($data, $response);
    }

    /**
     * Set the response for the error with the param
     * @param Response $response
     * @param string $paramName = ''
     * @return array
     */
    protected function errorParamsResponse(Response $response, string $paramName = ''): array
    {
        $error = "Error with params";
        if ($paramName !== '') {
            $error = "Error with param: " . $paramName;
        }
        $data = [
            "error" => $error,
            "code" => 417
        ];
        return $this->setCodeResponse($data, $response);
    }
}
