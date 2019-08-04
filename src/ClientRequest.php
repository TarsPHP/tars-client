<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 2019/7/28
 * Time: 16:39
 */

namespace Tars\client;


class ClientRequest
{
    public $requestPacket;
    public $timeout;
    public $sIp;
    public $iPort;

    public function __construct($requestPacket, $timeout, $sIp, $iPort)
    {
        $this->requestPacket = $requestPacket;
        // default timeout is 5 secs
        $this->timeout = empty($timeout)?5: $timeout;
        $this->sIp = $sIp;
        $this->iPort = $iPort;
    }
}