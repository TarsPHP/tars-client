# tars-client



Brief introduction

It mainly includes the receiving and sending capacity and reporting capacity of the master addressing network



The tests directory provides a test case for the tar service. Service name is * * app.server.servant**



## Instructions for use

Refer to the test cases provided.

The tar client side calls the tar service. When instantiating, it needs to pass in an instance of * * \ tars \ client \ communicator config * * and set the necessary configuration information. It mainly includes the following contents

* Service address

* Master addressing

* When there are multiple machines providing services, services can be found through the master automatic addressing mode

* Specify service address

* Use this method when grayscale or service needs to be obtained from a specific address

* Network transmission

* There are three modes: socket, swoole sync and swoole coroutine

* sign up

* Specifies the escalation module name. By default, the master address is * * tarsprox * *, which can be filled in according to the business when specifying the service address

* Encoding format



### Service addressing mode

Combined with the test case testservant.php, the code specifications of different service addressing are introduced.

Set the relevant configuration through * * \ tars \ client \ communicatorconfig * * class. The test case gives the example codes of two addressing modes

1. Master addressing

Once the locator is specified, the tar can automatically grab the service address according to the service name. The format of the locator configuration is as follows

```php

$config = new \Tars\client\CommunicatorConfig();

$config->setLocator("tars.tarsregistry.QueryObj@tcp -h 172.16.0.161 -p 17890");

```

The tar master is also a tar service. The service name is * * tar. Tarsregistry. Queryobj * *, the transmission protocol is TCP, the service address is 172.16.0.161, and the port is 17890. Please fill in * * according to the actual situation of the service during the actual development**



After the above master service is determined, the module name and coding format can be specified as required. The default escalation module name is * * tarsproxy * *. In order to facilitate business tracking, it is recommended to re specify the escalation module name

```

$config->setModuleName("App.Server");

$config->setCharsetName("UTF-8");

```



2. Specify IP

Specify the address of the service party. In this way, you need to specify the IP and port of the service. The code is as follows.

```php

$route['sIp'] = "127.0.0.1";

$route['iPort'] = 8080;

$routeInfo[] = $route;

$config = new \Tars\client\CommunicatorConfig();

$config->setRouteInfo($routeInfo);

```

Other uses are the same as automatic addressing



Changelog

### v0.3.0(2019-07-30)

-Services that support calling protobuf