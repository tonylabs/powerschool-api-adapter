<?php

namespace TONYLABS\PowerSchool\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use TONYLABS\PowerSchool\Api\Exception\MissingClientCredentialsException;
use Illuminate\Support\Facades\Response as LaravelResponse;

class Request
{
    protected Client $client;
    protected string $clientId;
    protected string $clientSecret;
    protected string $authToken;
    protected int $attempts = 0;

    /**
     * Creates a new Request object to interact with PS's api
     *
     * @param string $serverAddress The url of the server
     * @param string $clientId The client id obtained from installing a plugin with oauth enabled
     * @param string $clientSecret The client secret obtained from installing a plugin with oauth enabled
     */
    public function __construct(string $serverAddress, string $clientId, string $clientSecret)
    {
        $this->client = new Client(['base_uri' => $serverAddress]);
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Makes an api call to PowerSchool
     */
    public function makeRequest(string $method, string $endpoint, array $options, bool $returnResponse = false): JsonResponse|array
    {
        $this->authenticate();
        $this->attempts++;

        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }

        // Force json
        $options['headers']['Accept'] = 'application/json';
        $options['headers']['Content-Type'] = 'application/json';

        // Add the auth token for the header
        $options['headers']['Authorization'] = 'Bearer ' . $this->authToken;

        // Throw exceptions for 4xx and 5xx errors
        $options['http_errors'] = true;

        try {
            $response = $this->getClient()
                ->request($method, $endpoint, $options);
        } catch (ClientException $exception) {
            $response = $exception->getResponse();

            // If the response is an expired token, reauthenticate and try again
            if ($response->getStatusCode() === 401 && $this->attempts < 3) {
                return $this->authenticate(true)
                    ->makeRequest($method, $endpoint, $options);
            }
            Log::info($response->getBody()->getContents())->red()->label($response->getStatusCode());
            throw $exception;
        }

        $this->attempts = 0;
        $body = json_decode($response->getBody()->getContents(), true);
        Log::info($body);
        if ($returnResponse) {
            return LaravelResponse::json($body, $response->getStatusCode());
        }

        return $body ?? [];
    }

    /**
     * Authenticates against the api and retrieves an auth token
     *
     * @param boolean $force Force authentication even if there is an existing token
     * @return $this
     * @throws MissingClientCredentialsException|\GuzzleHttp\Exception\GuzzleException
     */
    public function authenticate(bool $force = false): static
    {
        // Check if there is already a token and we're not doing a force-retrieval
        if (!$force && $this->authToken) {
            return $this;
        }

        // Double check that there are client credentials
        if (!$this->clientId || !$this->clientSecret) {
            throw new MissingClientCredentialsException('Client id or secret is missing. Please retrieve from PowerSchool.');
        }

        $token = base64_encode($this->clientId . ':' . $this->clientSecret);

        $arrayHeaders = [
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . $token,
        ];

        // Retrieve the access token
        $arrayParameters = ['headers' => $arrayHeaders, 'body' => 'grant_type=client_credentials'];
        $objRequest = $this->getClient()->post('/oauth/access_token', $arrayParameters);
        $objResponse = json_decode($objRequest->getBody()->getContents());
        $this->authToken = $objResponse->access_token;
        return $objResponse;
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}
