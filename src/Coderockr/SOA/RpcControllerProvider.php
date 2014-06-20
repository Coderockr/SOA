<?php

namespace Coderockr\SOA;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\SerializerBuilder;

class RpcControllerProvider implements ControllerProviderInterface
{
    private $useCache = false;
    private $cache;
    private $serviceNamespace;

    public function setCache($cache)
    {
        $this->useCache = true;
        $this->cache = $cache;
    }

	public function setServiceNamespace($serviceNamespace)
	{
		$this->serviceNamespace = $serviceNamespace;
	}

	protected function serialize($data, $type)
	{
		$serializer = SerializerBuilder::create()->build();
		return $serializer->serialize($data, $type);
	}

	public function connect(Application $app)
    {
        // creates a new controller based on the default route
        $controllers = $app['controllers_factory'];

        $controllers->get('/', function (Application $app) {
            // return $app->redirect('/hello');
            return 'TODO: documentation';
        });
        
        $controllers->post('/{service}', function ($service, Request $request) use ($app)
		{
		    $service = $this->serviceNamespace . '\\' . ucfirst($service);

		    if (!class_exists($service)) {
		        return new Response('Invalid service.', 400, array('Content-Type' => 'text/json'));
		    }
		    $class = new $service($em);
		    if (!$parameters = $request->get('parameters')) 
		        $parameters = array();

		    $result = $class->execute($parameters);

		    switch ($result['status']) {
		        case 'success':
		            return new Response($this->serialize($result['data'],'json'), 200, array('Content-Type' => 'text/json'));
		            break;
		        case 'error':
		            return new Response('Error executing service - ' . $this->serialize($result['data'],'json'), 400, array('Content-Type' => 'text/json'));
		            break;
		    }

		});

		//options - used in cross domain access
        $controllers->match('/{service}', function ($service, Request $request) use ($app) 
        {
            return new Response('', 200, array(
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE',
                'Access-Control-Allow-Headers' => 'Authorization'
            ));
        })->method('OPTIONS')->value('service', null);

        $controllers->before(function (Request $request) use ($app) {
            if ($request->getMethod() == 'OPTIONS') {
                return;
            }

            //@TODO: review this
            // if( ! $request->headers->has('authorization')){
            //     return new Response('Unauthorized', 401);
            // }

            // require_once getenv('APPLICATION_PATH').'/configs/clients.php';
            // if (!in_array($request->headers->get('authorization'), array_keys($clients))) {
            //     return new Response('Unauthorized', 401);
            // }
        });

        return $controllers;
    }	
}