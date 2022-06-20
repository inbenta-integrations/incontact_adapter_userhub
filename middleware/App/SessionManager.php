<?php

namespace App;

use Exception;

class SessionManager
{
    protected $headers;
    protected $session;

    /**
     * @param array $headers
     */
    public function __construct(array $headers)
    {
        $this->headers = $headers;
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
        if (!isset($_ENV['DOMAINS']) || $_ENV['DOMAINS'] === '') {
            throw new Exception("Domain error");
        }

        $origin = str_replace(['https://', 'http://'], '', $this->headers['Origin']);
        $domains = explode(',', str_replace(' ', '', $_ENV['DOMAINS']));
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
        session_id($sessionToken);

        if (!isset($_SESSION)) {
            session_start();
        }
    }

    /**
     * Get session Id
     * @return String
     */
    public function getId(): string
    {
        return session_id();
    }

    /**
     * Remove all session variables
     * @return Array
     */
    public function clean(): array
    {
        return $_SESSION = [];
    }

    /**
     * Get value from specific value key in session
     * @param $key String  Key to retrieve
     * @return Array|String
     */
    public function get($key)
    {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : false;
    }

    /**
     * Set value from specific value key in session
     * @param $key    String  Key to save
     * @param $value  String  Value to save
     */
    public function set($key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Delete a key from session
     * @param $key    String  Key to save
     */
    public function delete($key): void
    {
        unset($_SESSION[$key]);
    }

    public function sessionWriteClose()
    {
        session_write_close();
    }
}
