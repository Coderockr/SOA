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
    private $em;
    private $authenticationService = null;
    private $authorizationService = null;

    public function setCache($cache)
    {
        $this->useCache = true;
        $this->cache = $cache;
    }

    public function setEntityManager($em)
    {
        $this->em = $em;
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

    public function getAuthorizationService()
    {
        return $this->authorizationService;
    }
     
    public function setAuthorizationService($authorizationService)
    {
        return $this->authorizationService = $authorizationService;
    }

    public function getAuthenticationService()
    {
        return $this->authenticationService;
    }
     
    public function setAuthenticationService($authenticationService)
    {
        return $this->authenticationService = $authenticationService;
    }

	public function connect(Application $app)
    {
    	$this->setEntityManager($app['orm.em']);

        // creates a new controller based on the default route
        $controllers = $app['controllers_factory'];

        $controllers->get('/', function (Application $app) {
            // return $app->redirect('/hello');
            return 'TODO: documentation';
        });
        
        $controllers->post('/{service}/{method}', function ($service, $method, Request $request) use ($app)
        {
            $service = $this->serviceNamespace . '\\' . ucfirst($service);

            if (!class_exists($service)) {
                return new Response('Invalid service.', 400, array('Content-Type' => 'text/json'));
            }
            $class = new $service();
            $class->setEm($this->em);
            if (!$parameters = $request->get('parameters')) 
                $parameters = array();

            if (method_exists($class, $method)) {
                $result = $class->$method($parameters);
            }
            else {
                $result = array('status' => 'error', 'data' => 'Method not found');
            }
            switch ($result['status']) {
                case 'success':
                    return new Response($this->serialize($result['data'],'json'), 200, array('Content-Type' => 'text/json'));
                    break;
                case 'error':
                    return new Response('Error executing service - ' . $this->serialize($result['data'],'json'), 400, array('Content-Type' => 'text/json'));
                    break;
            }

        })->value('method', 'execute');

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

            if ($this->getAuthorizationService()) {
                if( ! $request->headers->has('authorization')) {
                    return new Response('Unauthorized', 401);
                }

                $token = $request->headers->get('authorization');
                if (!$this->getAuthenticationService()->authenticate($token)) {
                    return new Response('Unauthorized', 401);    
                }
                $resource = $request->get('_route_params');
                if (!$this->getAuthorizationService()->isAuthorized($token, $resource['service'] . $resource['method'])) {
                    return new Response('Unauthorized', 401);    
                }

            }
        });

        return $controllers;
    }	
}