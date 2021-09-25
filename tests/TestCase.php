<?php

namespace Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{

    /** @var \Illuminate\Testing\TestResponse */
    public $response;

    use CreatesApplication;

    /** @var bool Damn you laravel 5.7, this prevented \Artisan::output() from working. */
    public $mockConsoleOutput = false;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
//        if (!isset($server['accept'])) {
//            $server = array_merge($server, [
//                'Accept' => 'application/vnd.api+json'
//            ]);
//        }
        $server = $this->transformHeadersToServerVars($server);

        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if (in_array('--debug', $_SERVER['argv'], true)
            || (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'])
            || (isset($_SERVER['APP_DEBUG']) && $_SERVER['APP_DEBUG'])
            // Enable debug for PHPStorm
            || isset($_SERVER['IDE_PHPUNIT_CUSTOM_LOADER'])
        ) {
            fwrite(
                STDOUT,
                "-$method-$uri-" . json_encode($parameters) . "-$content" . PHP_EOL .
                $response->getContent() . PHP_EOL
            );
        }

        return $response;
    }

    /**
     * Call protected/private method of a class.
     *
     * @param object &$object Instantiated object that we will run method on.
     * @param string $methodName Method name to call
     * @param array $parameters Array of parameters to pass into method.
     *
     * @return mixed Method return.
     * @throws \ReflectionException
     */
    public function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Create a model factory and forget observers so events do not trigger actions.
     * @param $class
     * @param string $name
     * @return \Illuminate\Database\Eloquent\FactoryBuilder
     */
    public function factoryWithoutObservers($class, $name = 'default')
    {
        $class::flushEventListeners();

        return factory($class, $name);
    }

    /**
     * Turn on DB queries debug.
     */
    public function enableSqlDebug()
    {
        \DB::listen(function (QueryExecuted $sql) {
            var_dump($sql->sql, $sql->bindings, $sql->time);
        });
    }

    protected function getCookie($response, $cookieName, $isEncrypted = true)
    {
        $cookie = \Arr::first(
            $response->headers->getCookies(),
            function ($cookie, $index) use ($cookieName) {
                return $cookie->getName() === $cookieName;
            }
        );

        return $isEncrypted
            ? $this->app['encrypter']->decrypt($cookie->getValue())
            : $cookie->getValue();
    }
}
