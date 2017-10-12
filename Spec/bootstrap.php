<?php

global $CONFIG;

date_default_timezone_set('UTC');

$minds = new Minds\Core\Minds();
$minds->loadLegacy();

$CONFIG = Minds\Core\Di\Di::_()->get('Config');
$CONFIG->default_access = 2;
$CONFIG->site_guid = 0;
$CONFIG->cassandra = new stdClass;
$CONFIG->cassandra->keyspace = 'phpspec';
$CONFIG->cassandra->servers = ['127.0.0.1'];
$CONFIG->cassandra->cql_servers = ['127.0.0.1'];

$CONFIG->payments = [
  'braintree' => [
    'default' => [
      'environment' => 'sandbox',
      'merchant_id' => 'foobar',
      'master_merchant_id' => 'foobar',
      'public_key' => 'random',
      'private_key' => 'random_private'
    ],
    'merchants' => [
      'environment' => 'sandbox',
      'merchant_id' => 'foobar',
      'master_merchant_id' => 'foobar',
      'public_key' => 'random',
      'private_key' => 'random_private'
    ],
  ]];

class Mock 
{

    private $a;

    public function __construct($a = null)
    {
        $this->a = $a;
    }

    public static function collection()
    {
        return new Mock();
    }


    public function request()
    {

    }

    public function create()
    {
      
    }

    public function withContactPoints()
    {
        return $this;
    }

    public function withPort()
    {
        return $this;
    }

    public static function text()
    {
      
    }

    public static function varint()
    {

    }

    public function value()
    {
        return (string) $this->a;
    }

    public static function cluster()
    {
        return new Mock();
    }

    public static function build()
    {
        return new Mock();
    }

    public static function connect()
    {
        return new Mock();
    }

    public static function prepare()
    {
        return new Mock();
    }

    public static function executeAsync()
    {
        return new Mock();
    }

    public static function get()
    {

    }

}

class_alias('Mock', 'Cassandra');
class_alias('Mock', 'Cassandra\ExecutionOptions');
class_alias('Mock', 'Cassandra\Varint');
class_alias('Mock', 'Cassandra\Timestamp');
class_alias('Mock', 'Cassandra\Type');
class_alias('Mock', 'Cassandra\Decimal');
