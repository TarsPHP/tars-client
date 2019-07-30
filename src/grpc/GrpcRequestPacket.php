<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 2018/4/29
 * Time: 下午3:53.
 */

namespace Tars\client\grpc;

use Tars\client\RequestPacket;

class GrpcRequestPacket extends RequestPacket
{
    public $_sBuffer;
    public $_funcName;

    public $_basePath = '';

    public function encode()
    {
        return pack('CN', 0, strlen($this->_sBuffer)) . $this->_sBuffer;
    }

    public function getPath()
    {
        return $this->_basePath . $this->_funcName;
    }
}
