<?php

namespace App;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ClientException;

class IncontactApi
{
    private $httpClient;
    private $env;
    private $apiEndpoint;
    private $headersCommon;

    public function __construct()
    {
        $this->httpClient = new Guzzle();
        $this->env = $_ENV;
    }

    /**
     * Make the request to Incontact APIsç
     * @param string $method
     * @param string $uri
     * @param array $headers
     * @param array $data = null
     * @return array
     */
    protected function makeRequest(string $method, string $uri, array $headers, array $data = null): array
    {
        $params = is_null($data) ? $headers : array_merge($headers, $data);
        try {
            $responseTmp = $this->httpClient->$method(
                $uri,
                $params
            );
            $response = $responseTmp->getBody();

            if (!is_null($response)) {
                $responseDecoded = json_decode($response, true);
                $responseDecoded["code"] = $responseTmp->getStatusCode();;
                return $responseDecoded;
            }
            return ["error" => "no messages", "code" => 304];
        } catch (ClientException $e) {
            $error = $e->getResponse();
            return ["error" => json_decode($error->getBody()->getContents()), "code" => $error->getStatusCode()];
        }
    }

    /**
     * Set the common header for the API requests
     * @param string $accessToken
     * @param string $tokenType
     * @return void
     */
    public function setCommonHeaders(string $accessToken, string $tokenType): void
    {
        $this->headersCommon = [
            'headers' => [
                'Authorization' => $tokenType . ' ' . $accessToken,
                'Accept' => "*/*",
                'Content-Type' => 'application/json'
            ]
        ];
    }

    /**
     * Set the API Endpoint
     * @param string $apiEndpoint
     * @return void
     */
    public function setApiEndpoint(string $apiEndpoint): void
    {
        $this->apiEndpoint = $apiEndpoint . '/inContactAPI/services/' . $this->env['API_VERSION'];
    }

    /**
     * Get the access key at the start of the session
     * @return array
     */
    public function getAccessKey(): array
    {
        $headers = [
            'headers' => [
                'Content-Type' => 'application/json'
            ]
        ];
        $data = [
            'json' => [
                'accessKeyId' => $this->env['ACCESS_KEY_ID'],
                'accessKeySecret' => $this->env['ACCESS_KEY_SECRET']
            ]
        ];
        $response = $this->makeRequest('post', $this->env['AUTH_URL'], $headers, $data);
        if (isset($response['code'])) unset($response['code']);
        if (isset($response['id_token'])) unset($response['id_token']);
        return $response;
    }

    /**
     * Get the api endpoint to be used in all the requests
     * @param string $accessToken
     * @param string $tokenType
     * @return array
     */
    public function getApiEndpoint(string $accessToken, string $tokenType): array
    {
        $headers = [
            'headers' => [
                'Authorization' => $tokenType . ' ' . $accessToken
            ]
        ];
        return $this->makeRequest('get', $this->env['DISCOVERY_URL'], $headers);
    }

    /**
     * Get the hours of operation
     * @param string $profileIdHoursOperation
     * @return array
     */
    public function getHoursOfOperation(string $profileIdHoursOperation): array
    {
        $uri = $this->apiEndpoint . '/hours-of-operation';
        if ($profileIdHoursOperation !== '') {
            $uri .= '?profileIdHoursOperation=' . $profileIdHoursOperation;
        }
        return $this->makeRequest('get', $uri, $this->headersCommon);
    }

    /**
     * Get the hours of operation
     * @param array $queryParams
     * @return array
     */
    public function getAgentsAvailability(array $queryParams): array
    {
        $uri = $this->apiEndpoint . '/agents/states';
        $params = '';
        if (isset($queryParams['fields']) && $queryParams['fields'] !== '') {
            $params .= 'fields=' . $queryParams['fields'];
        }
        if (isset($queryParams['top']) && $queryParams['top'] !== '') {
            $params .= $params !== '' ? '&' : '';
            $params .= 'top=' . $queryParams['top'];
        }
        $uri = $params !== '' ? $uri . '?' . $params : $uri;

        return $this->makeRequest('get', $uri, $this->headersCommon);
    }

    /**
     * Get the chat profile
     * @param array $queryParams
     * @return array
     */
    public function getChatProfile(array $queryParams): array
    {
        $uri = $this->apiEndpoint . '/points-of-contact/' . $queryParams['pointOfContact'] . '/chat-profile';

        return $this->makeRequest('get', $uri, $this->headersCommon);
    }

    /**
     * Start the session with the chat
     * @param array $params
     * @return array
     */
    public function makeChat(array $params): array
    {
        $uri = $this->apiEndpoint . '/contacts/chats';
        $data = [
            'json' => $params
        ];
        return $this->makeRequest('post', $uri, $this->headersCommon, $data);
    }

    /**
     * Get the response from agent
     * @param array $queryParams
     * @return array
     */
    public function getResponse(array $queryParams): array
    {
        $uri = $this->apiEndpoint . '/contacts/chats/' . $queryParams['chatSessionId'];

        if (isset($queryParams['timeout']) && $queryParams['timeout'] > 0) {
            $uri .= '?timeout=' . $queryParams['timeout'];
        }
        $response = $this->makeRequest('get', $uri, $this->headersCommon);
        if (isset($response["code"]) && $response["code"] == 304) {
            $response["code"] = 204;
        }
        return $response;
    }

    /**
     * Send a new message to incontact Agent
     * @param array $queryParams
     * @param string $bodyParams
     * @return array
     */
    public function sendText(array $queryParams, string $bodyParams): array
    {
        $bodyParams = json_decode($bodyParams, true);
        $bodyParams = $this->multiMessages($bodyParams);

        $uri = $this->apiEndpoint . '/contacts/chats/' . $queryParams['chatSessionId'] . '/send-text';
        $data = [
            'json' => $bodyParams
        ];
        return $this->makeRequest('post', $uri, $this->headersCommon, $data);
    }

    /**
     * Contact in a single message when there are more than one message (from transcript)
     * @param array $bodyParams = null
     * @return array
     */
    protected function multiMessages(array $bodyParams = null): array
    {
        if (!isset($bodyParams['messages'])) return $bodyParams;
        if (!isset($bodyParams['assistant'])) return $bodyParams;
        if (!isset($bodyParams['guest'])) return $bodyParams;
        if (!isset($bodyParams['system'])) return $bodyParams;
        if (!isset($bodyParams['transcriptConversationText'])) return $bodyParams;
        if (count($bodyParams['messages']) === 0) return $bodyParams;

        $messageTmp = '';
        foreach ($bodyParams['messages'] as $message) {
            $author = $bodyParams['system'];
            if (isset($message['user'])) {
                $author = isset($bodyParams[$message['user']]) ? $bodyParams[$message['user']] : 'Unknown';
            }
            $messageTmp .= '<i>' . $author . '</i>: ' . $message['message'] . "<br>";
        }
        return [
            'message' => $messageTmp,
            'label' => $bodyParams['transcriptConversationText']
        ];
    }

    /**
     * 
     * @param array $queryParams
     * @return array
     */
    public function endChat(array $queryParams): array
    {
        $uri = $this->apiEndpoint . '/contacts/chats/' . $queryParams['chatSessionId'];

        $headers = $this->headersCommon;
        $headers['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        return $this->makeRequest('delete', $uri, $headers);
    }
}
