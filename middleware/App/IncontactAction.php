<?php

namespace App;

use App\SessionManager;
use App\IncontactApi;
use Klein\Response;

class IncontactAction
{
    protected $session;
    protected $headers;
    protected $api;

    /**
     * @param SessionManager $session
     * @param array $headers
     */
    public function __construct(SessionManager $session, array $headers)
    {
        $this->session = $session;
        $this->headers = $headers;
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
            $accessKeyData = $this->api->getAccessKey();
            if (!isset($accessKeyData["access_token"])) return false;
            if (!isset($accessKeyData["token_type"])) return false;

            $apiEndpointData = $this->api->getApiEndpoint($accessKeyData["access_token"], $accessKeyData["token_type"]);
            if (!isset($apiEndpointData['api_endpoint'])) return false;

            $accessKeyData["api_endpoint"] = $apiEndpointData["api_endpoint"];
            $this->setSessionValues($accessKeyData);
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
     */
    protected function setSessionValues(array $accessKeyData)
    {
        $accessKeyData["expires_in"] = $accessKeyData["expires_in"] + time();
        $this->session->set("access_token", $accessKeyData["access_token"]);
        $this->session->set("expires_in", $accessKeyData["expires_in"]);
        $this->session->set("token_type", $accessKeyData["token_type"]);
        $this->session->set("refresh_token", $accessKeyData["refresh_token"]);
        $this->session->set("api_endpoint", $accessKeyData["api_endpoint"]);

        $this->api->setCommonHeaders($accessKeyData["access_token"], $accessKeyData["token_type"]);
        $this->api->setApiEndpoint($accessKeyData["api_endpoint"]);
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
            if (isset($this->headers['Origin'])) {
                header_remove("Access-Control-Allow-Origin");
                $response->header('Access-Control-Allow-Origin', $this->headers['Origin']);
            }
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

        $this->session->sessionWriteClose();

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
