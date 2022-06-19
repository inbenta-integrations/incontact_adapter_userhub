<?php

namespace App;

use Exception;
use Symfony\Component\HttpFoundation\Session\Session;

class SessionManager
{
    protected $headers;
    protected $session;

    public function __construct()
    {
        $this->headers = getallheaders();
        $this->validateOrigin();
        $this->validateToken();
        $this->createSession();
    }

    /**
     * Validate if origin is correct
     * @return void
     */
    protected function validateOrigin(): void
    {
        if (!isset($this->headers['Origin'])) return;
        if ($this->headers['Origin'] === '') return;

        $origin = str_replace(['https://', 'http://'], '', $this->headers['Origin']);
        $domains = explode(',', str_replace('', '', $_ENV['DOMAINS']));
        if (!in_array($origin, $domains)) {
            throw new Exception("Domain error");
        }
    }

    /**
     * Validate if header exists
     * @return void
     */
    protected function validateToken(): void
    {
        if (!isset($this->headers['X-Inbenta-Token']) || $this->headers['X-Inbenta-Token'] === '') {
            throw new Exception("Error on token");
        }
    }

    /**
     * Check if session exists, if not create it (based on Header "X-Inbenta-Token"))
     * @return void
     */
    protected function createSession(): void
    {
        $sessionToken = $this->headers['X-Inbenta-Token'];

        $this->session = new Session();
        $this->session->setId($sessionToken);

        if (count($this->session->all()) == 0) {
            $this->session->start();
        }
    }

    /**
     * Validate if session is an extra session
     * @return bool
     */
    public function isExtraSession(): bool
    {
        $sessionToken = $this->session->getId();
        $suffix = $_ENV['EXTRA_SESSION_SUFFIX'];
        return strpos($sessionToken, $suffix) > 0;
    }

    /**
     * Get session Id
     * @return String
     */
    public function getId(): string
    {
        return $this->session->getId();
    }

    /**
     * Remove all session variables
     * @return Array
     */
    public function clean(): array
    {
        $this->session->clear();
        return [];
    }

    /**
     * Get value from specific value key in session
     * @param $key String  Key to retrieve
     * @return Array|String
     */
    public function get($key)
    {
        return !($this->session->get($key)) ? $this->session->get($key) : false;
    }

    /**
     * Set value from specific value key in session
     * @param $key    String  Key to save
     * @param $value  String  Value to save
     */
    public function set($key, $value): void
    {
        $this->session->set($key, $value);
    }

    /**
     * Delete a key from session
     * @param $key    String  Key to save
     */
    public function delete($key): void
    {
        $this->session->remove($key);
    }
}
