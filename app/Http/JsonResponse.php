<?php
namespace QueuePro\Http;

defined('QP_APP') || exit;

final class JsonResponse
{
    private $data;
    private int $code;
    private array $headers = [];

    public function __construct($data, int $code = 200)
    {
        $this->data = $data;
        $this->code = $code;
    }

    public function status(int $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->code);
            header('Content-Type: application/json; charset=utf-8');
            if (!isset($this->headers['Cache-Control'])) {
                header('Cache-Control: no-store, private');
            }
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
