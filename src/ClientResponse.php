<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 2019/7/28
 * Time: 16:39
 */

namespace Tars\client;


class ClientResponse
{
    public $responsePacket;
    public $elapsedTime;

    public function __construct($requestPacket, $elapsedTime)
    {
        $this->requestPacket = $requestPacket;
        $this->elapsedTime = $elapsedTime;
    }
}