<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 2018/4/29
 * Time: 下午3:53.
 */

namespace Tars\client\grpc;

class GrpcResponsePacket
{
    public $_responseBuf;
    public $iVersion;

    public function decode()
    {
        return substr($this->_responseBuf, 5);
    }
}
