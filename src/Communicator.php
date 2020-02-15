<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 2018/4/29
 * Time: 下午12:55.
 */

namespace Tars\client;

use Tars\monitor\StatFWrapper;
use Tars\registry\QueryFWrapper;

class Communicator
{
    // endpoints info which contains ip and port
    protected $_socketMode;
    protected $_routeInfo;

    // monitorHelper to report stats
    protected $_statF;
    // register Helper to query endpoints
    protected $_queryF;
    // config for the communicator
    protected $_config;

    public function __construct(CommunicatorConfig $config)
    {
        $this->_config = $config;
        $this->_socketMode = $this->_config->getSocketMode();

        $refreshEndpointInterval = empty($config->getRefreshEndpointInterval())
            ? 60000 : $config->getRefreshEndpointInterval();
        $reportInterval = empty($this->_config->getReportInterval())
            ? 60000 : $config->getReportInterval();
        $servantName = $this->_config->getServantName();
        $moduleName = $this->_config->getModuleName();
        $locator = $this->_config->getLocator();

        // 如果已经有配置的地址的话,直接选用
        if (!empty($config->getRouteInfo())) {
            $this->_routeInfo = $config->getRouteInfo();
        } else {
            // 完成服务的路由
            $this->_queryF = new QueryFWrapper($locator, $this->_socketMode,
                $refreshEndpointInterval);
            $this->_routeInfo = $this->_queryF->findObjectById($servantName);
            // 初始化上报组件,只在指定了主控的前提下
            if(class_exists("\Tars\App")) {
                $this->_statF = \Tars\App::getStatF();
            }
            else {
                $this->_statF = new StatFWrapper($locator, $this->_socketMode,
                    $this->_config->getStat(), $moduleName, $reportInterval);
            }
        }

    }

    // 同步的socket tcp收发
    public function invoke(RequestPacket $requestPacket, $timeout,
        $sIp = '', $iPort = 0)
    {
        // 转换成网络需要的timeout
        $timeout = $timeout / 1000;

        $startTime = $this->militime();
        $count = count($this->_routeInfo) - 1;
        if ($count === -1) {
            throw new \Exception('Rout fail', Code::ROUTE_FAIL);
        }
        $index = rand(0, $count);
        $sIp = empty($sIp) ? $this->_routeInfo[$index]['sIp'] : $sIp;
        $iPort = empty($iPort) ? $this->_routeInfo[$index]['iPort'] : $iPort;
        $bTcp = isset($this->_routeInfo[$index]['bTcp']) ?
            $this->_routeInfo[$index]['bTcp'] : 1;

        $preFilters = $this->_config->preFilters;
        if (!empty($preFilters)) {
            $clientRequest = new ClientRequest($requestPacket, $timeout,
                $sIp, $iPort);
            foreach ($preFilters as $filterClass) {
                // call each filter and pass clientRequest as reference
                call_user_func(array($filterClass, "doFilter"), $clientRequest);
            }
            $requestPacket = $clientRequest->requestPacket;
        }

        try {
            $requestBuf = $requestPacket->encode();
            $responseBuf = '';
            if ($bTcp) {
                switch ($this->_socketMode) {
                    // 单纯的socket
                    case 1:{
                        $responseBuf = $this->socketTcp($sIp, $iPort,
                            $requestBuf, $timeout);
                        break;
                    }
                    case 2:{
                        $responseBuf = $this->swooleTcp($sIp, $iPort,
                            $requestBuf, $timeout);
                        break;
                    }
                    case 3:{
                        $responseBuf = $this->swooleCoroutineTcp($sIp, $iPort,
                            $requestBuf, $timeout);
                        break;
                    }
                }
            } else {
                switch ($this->_socketMode) {
                    // 单纯的socket
                    case 1:{
                        $responseBuf = $this->socketUdp($sIp, $iPort, $requestBuf, $timeout);
                        break;
                    }
                    case 2:{
                        $responseBuf = $this->swooleUdp($sIp, $iPort, $requestBuf, $timeout);
                        break;
                    }
                    case 3:{
                        $responseBuf = $this->swooleCoroutineUdp($sIp, $iPort, $requestBuf, $timeout);
                        break;
                    }
                }
            }

            $responsePacket = new ResponsePacket();
            $responsePacket->_responseBuf = $responseBuf;
            $responsePacket->iVersion = $this->_config->getIVersion();;

            $endTime = $this->militime();
            $elapsedTime = $endTime - $startTime;
            if(!is_null($this->_config->getLocator()))
            {
                $this->_statF->addStat($requestPacket->_servantName, $requestPacket->_funcName, $sIp,
                    $iPort, $elapsedTime, 0, 0);
            }

            // todo 似乎静态方法更好一些
            $postFilters = $this->_config->postFilters;
            if (!empty($postFilters)) {
                $clientResponse = new ClientResponse($responsePacket, $elapsedTime);
                foreach ($postFilters as $filterClass) {
                    // call each filter and pass clientRequest as reference
                    call_user_func(array($filterClass, "filter"), $clientResponse);
                }
                $responsePacket = $clientResponse->responsePacket;
            }

            $sBuffer = $responsePacket->decode();
            return $sBuffer;
        } catch (\Exception $e) {
            $endTime = $this->militime();

            if(!is_null($this->_config->getLocator()))
            {
                $this->_statF->addStat($requestPacket->_servantName, $requestPacket->_funcName, $sIp,
                    $iPort, ($endTime - $startTime), $e->getCode(), $e->getCode());
            }
            throw $e;
        }
    }

    private function socketTcp($sIp, $iPort, $requestBuf, $timeout = 2)
    {
        $time = microtime(true);

        $sock = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $sock) {
            throw new \Exception(Code::getErrMsg(Code::TARS_SOCKET_CREATE_FAILED), Code::TARS_SOCKET_CREATE_FAILED);
        }

        if (!\socket_connect($sock, $sIp, $iPort)) {
            \socket_close($sock);
            throw new \Exception();
        }

        if (!\socket_write($sock, $requestBuf, strlen($requestBuf))) {
            \socket_close($sock);
            throw new \Exception();
        }

        $totalLen = 0;
        $responseBuf = null;
        while (true) {
            if (microtime(true) - $time > $timeout) {
                \socket_close($sock);
                throw new \Exception();
            }
            //读取最多32M的数据
            $data = \socket_read($sock, 65536, PHP_BINARY_READ);

            if (empty($data)) {
                // 已经断开连接
                return '';
            } else {
                //第一个包
                if ($responseBuf === null) {
                    $responseBuf = $data;
                    //在这里从第一个包中获取总包长
                    $list = unpack('Nlen', substr($data, 0, 4));
                    $totalLen = $list['len'];
                } else {
                    $responseBuf .= $data;
                }

                //check if all package is receved
                if (strlen($responseBuf) >= $totalLen) {
                    \socket_close($sock);
                    break;
                }
            }
        }

        return $responseBuf;
    }

    private function socketUdp($sIp, $iPort, $requestBuf, $timeout = 2)
    {
        $time = microtime(true);
        $sock = \socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if (false === $sock) {
            throw new \Exception(Code::getErrMsg(Code::TARS_SOCKET_CREATE_FAILED), Code::TARS_SOCKET_CREATE_FAILED);
        }

        if (!\socket_set_nonblock($sock)) {
            \socket_close($sock);
            $code = Code::TARS_SOCKET_SET_NONBLOCK_FAILED; // 设置socket非阻塞失败
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        $len = strlen($requestBuf);
        if (\socket_sendto($sock, $requestBuf, $len, 0x100, $sIp, $iPort) != $len) {
            \socket_close($sock);
            $code = Code::TARS_SOCKET_SEND_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        if (0 == $timeout) {
            \socket_close($sock);

            return ''; // 无回包的情况，返回成功
        }

        $read = array($sock);
        $second = floor($timeout);
        $usecond = ($timeout - $second) * 1000000;
        $ret = \socket_select($read, $write, $except, $second, $usecond);

        if (false === $ret) {
            \socket_close($sock);
            $code = Code::TARS_SOCKET_RECEIVE_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        } elseif ($ret != 1) {
            \socket_close($sock);
            $code = Code::TARS_SOCKET_SELECT_TIMEOUT;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        $out = null;
        $responseBuf = null;
        while (true) {
            if (microtime(true) - $time > $timeout) {
                \socket_close($sock);
                $code = Code::TARS_SOCKET_TIMEOUT;
                throw new \Exception(Code::getErrMsg($code), $code);
            }

            // 32k：32768 = 1024 * 32
            $outLen = @\socket_recvfrom($sock, $out, 32768, 0, $ip, $port);
            if (!($outLen > 0 && $out != '')) {
                continue;
            }
            $responseBuf = $out;
            \socket_close($sock);

            return $responseBuf;
        }
    }
    private function swooleTcp($sIp, $iPort, $requestBuf, $timeout = 2)
    {
        $client = new \swoole_client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);

        $client->set(array(
            'open_length_check' => 1,
            'package_length_type' => 'N',
            'package_length_offset' => 0,       //第N个字节是包长度的值
            'package_body_offset' => 0,       //第几个字节开始计算长度
            'package_max_length' => 2000000,  //协议最大长度
        ));

        if (!$client->connect($sIp, $iPort, $timeout)) {
            $code = Code::TARS_SOCKET_CONNECT_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        if (!$client->send($requestBuf)) {
            $client->close();
            $code = Code::TARS_SOCKET_SEND_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }
        //读取最多32M的数据
        $tarsResponseBuf = $client->recv();

        if (empty($tarsResponseBuf)) {
            $client->close();
            // 已经断开连接
            $code = Code::TARS_SOCKET_RECEIVE_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        return $tarsResponseBuf;
    }

    private function swooleUdp($sIp, $iPort, $requestBuf, $timeout = 2)
    {
        $client = new \swoole_client(SWOOLE_SOCK_UDP);

        $client->set(array(
            'open_length_check' => 1,
            'package_length_type' => 'N',
            'package_length_offset' => 0,       //第N个字节是包长度的值
            'package_body_offset' => 0,       //第几个字节开始计算长度
            'package_max_length' => 2000000,  //协议最大长度
        ));

        if (!$client->connect($sIp, $iPort, $timeout)) {
            $code = Code::TARS_SOCKET_CONNECT_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        if (!$client->send($requestBuf)) {
            $client->close();
            $code = Code::TARS_SOCKET_SEND_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }
        //读取最多32M的数据
        $tarsResponseBuf = $client->recv();

        if (empty($tarsResponseBuf)) {
            $client->close();
            // 已经断开连接
            $code = Code::TARS_SOCKET_RECEIVE_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        return $tarsResponseBuf;
    }

    private function swooleCoroutineTcp($sIp, $iPort, $requestBuf, $timeout = 2)
    {
        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);

//        $client->set(array(
//            'open_length_check'     => 1,
//            'package_length_type'   => 'N',
//            'package_length_offset' => 0,       //第N个字节是包长度的值
//            'package_body_offset'   => 0,       //第几个字节开始计算长度
//            'package_max_length'    => 2000000,  //协议最大长度
//        ));

        if (!$client->connect($sIp, $iPort, $timeout)) {
            $code = Code::TARS_SOCKET_CONNECT_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        if (!$client->send($requestBuf)) {
            $client->close();
            $code = Code::TARS_SOCKET_SEND_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }
        $firstRsp = true;
        $curLen = 0;
        $responseBuf = '';
        $packLen = 0;
        $isConnected = true;
        while ($isConnected) {
            if ($client->errCode) {
                throw new \Exception('socket recv falied', $client->errCode);
            }
            $data = $client ? $client->recv() : '';
            if ($firstRsp) {
                $firstRsp = false;
                $list = unpack('Nlen', substr($data, 0, 4));
                $packLen = $list['len'];
                $responseBuf = $data;
                $curLen += strlen($data);
                if ($curLen == $packLen) {
                    $isConnected = false;
                    $client->close();
                }
            } else {
                if ($curLen < $packLen) {
                    $responseBuf .= $data;
                    $curLen += strlen($data);
                    if ($curLen == $packLen) {
                        $isConnected = false;
                        $client->close();
                    }
                } else {
                    $isConnected = false;
                    $client->close();
                }
            }
        }

        //读取最多32M的数据
        //$responseBuf = $client->recv();

        if (empty($responseBuf)) {
            $client->close();
            // 已经断开连接
            $code = Code::TARS_SOCKET_RECEIVE_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        return $responseBuf;
    }

    private function swooleCoroutineUdp($sIp, $iPort, $requestBuf, $timeout = 2)
    {
        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_UDP);

        $client->set(array(
            'open_length_check' => 1,
            'package_length_type' => 'N',
            'package_length_offset' => 0,       //第N个字节是包长度的值
            'package_body_offset' => 0,       //第几个字节开始计算长度
            'package_max_length' => 2000000,  //协议最大长度
        ));

        if (!$client->connect($sIp, $iPort, $timeout)) {
            $code = Code::TARS_SOCKET_CONNECT_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        if (!$client->send($requestBuf)) {
            $client->close();
            $code = Code::TARS_SOCKET_SEND_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        //读取最多32M的数据
        $responseBuf = $client->recv();

        if (empty($responseBuf)) {
            $client->close();
            // 已经断开连接
            $code = Code::TARS_SOCKET_RECEIVE_FAILED;
            throw new \Exception(Code::getErrMsg($code), $code);
        }

        return $responseBuf;
    }

    private function militime()
    {
        list($msec, $sec) = explode(' ', microtime());
        $miliseconds = (float) sprintf('%.0f', (floatval($msec) + floatval($sec)) * 1000);

        return $miliseconds;
    }
}
