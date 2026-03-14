<?php

namespace FChubFakturownia\Tests;

class WpSendJsonException extends \RuntimeException
{
    public array $data;
    public int $statusCode;

    public function __construct(array $data, int $statusCode = 200)
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
        parent::__construct(json_encode($data), $statusCode);
    }
}
