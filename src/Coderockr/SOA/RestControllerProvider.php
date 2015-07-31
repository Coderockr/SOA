<?php

namespace Coderockr\SOA;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Coderockr\SOA\RestService;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;


class RestControllerProvider implements ControllerProviderInterface
{
    private $useCache = false;
    private $cache;
    private $em;
    private $entityNamespace;
    private $authenticationService = null;
    private $authorizationService = null;
    private $restService = null;
    private $noAuthCalls = array();
    private $authHeader = 'Authorization';
    private $token = null;
    
    public function setRestService($restService)
    {
        $this->restService = $restService;
    }

    public function getRestService()
    {
        if (!$this->restService) {
            $this->restService = new RestService;
            $this->restService->setEntityManager($this->em);
            $this->restService->setEntityNamespace($this->entityNamespace);
        }

        return $this->restService;
    }

    public function getAuthHeader()
    {
        return $this->authHeader;
    }
    
    public function setAuthHeader($authHeader)
    {
        $this->authHeader = $authHeader;
        return $this;
    }

    public function setCache($cache)
    {
        $this->useCache = true;
        $this->cache = $cache;
    }
    
    public function getNoAuthCalls()
    {
        return $this->noAuthCalls;
    }

    public function setEntityManager($em)
    {
        $this->em = $em;
    }

    public function setEntityNamespace($entityNamespace)
    {
        $this->entityNamespace = $entityNamespace;
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
     
    public function setAuthenticationService($authenticationService, $noAuthCalls = array())
    {
        $this->noAuthCalls = $noAuthCalls;
        return $this->authenticationService = $authenticationService;
    }

    public function find($entity, $id)
    {
        return $this->getRestService()->find($entity, $id);
    }

    public function findAll($entity, $fields, $joins, $limit, $offset, $filter, $sort, $count)
    {
        return $this->getRestService()->findAll(
            $entity, $fields, $joins, $limit, $offset, $filter, $sort, $count, $this->token
        );
    }   

    public function create($request, $entity)
    {
        return $this->getRestService()->create($request, $entity, $this->token);
    }

    public function update($request, $entity, $id)
    {
        return $this->getRestService()->update($request, $entity, $id, $this->token);
    }

    public function delete($request, $entity, $id)
    {
        return $this->getRestService()->delete($request, $entity, $id, $this->token);
    }

    protected function serialize($data, $type)
    {
        $serializer = SerializerBuilder::create()->setPropertyNamingStrategy(new IdenticalPropertyNamingStrategy())->build();
        $groupType = array($this->entityNamespace . '\Entity');
        if (is_object($data)) {
            $groupType[] = get_class($data);

        }
        if (is_array($data) && isset($data[0]) && is_object($data[0])) {
            $groupType[] = get_class($data[0]);
        }
        
        return $serializer->serialize($data, $type, SerializationContext::create()->setGroups($groupType));
    }

    public function connect(Application $app)
    {
        $this->setEntityManager($app['orm.em']);
        $controllers = $app['controllers_factory'];

        $controllers->match("{url}", function($url) use ($app) { 
            return new Response('', 204);
        })->assert('url', '.*')->method("OPTIONS");

        $controllers->get('/', function (Application $app) {
            return 'TODO: documentation';
        });
        
        $controllers->get('/{entity}', function (Application $app, $entity, Request $request) {
            $params = $request->query->all();
            $fields = isset($params['fields']) ? explode(",", $params['fields']) : null;
            $joins = isset($params['joins']) ? explode(",", $params['joins']) : null;
            $limit = isset($params['limit']) ? $params['limit'] : null;
            $offset = isset($params['offset']) ? $params['offset'] : null;
            $filter = isset($params['filter']) ? explode(",", $params['filter']) : null;
            $count = isset($params['count']) ? $params['count'] : null;
            $sort = isset($params['sort']) ? $params['sort'] : null;
            
            $data = $this->serialize($this->findAll($entity,
                $fields,
                $joins, 
                $limit, 
                $offset, 
                $filter,
                $sort, 
                $count, 
                $this->token), 'json');

            return new Response($data, 200, array('Content-Type' => 'application/json'));
        });

        $controllers->get('/{entity}/{id}', function (Application $app, $entity, $id) {
            
            $data =  $this->find($entity, $id, $this->token);
            if (!$data) {
                return new JsonResponse('Data not found', 404);
            }
            
            return new Response($this->serialize($data, 'json'), 200, array('Content-Type' => 'application/json'));
        
        })->assert('id', '\d+');

        $controllers->post('/{entity}', function (Application $app, Request $request, $entity) {
            $entityData = $this->serialize($this->create($request, $entity, $this->token), 'json');
            return new Response($entityData, 200, array('Content-Type' => 'application/json'));
        });

        $controllers->put('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            
            $data = $this->update($request, $entity, $id, $this->token);
            if (!$data) {
                return new JsonResponse('Data not found', 404);
            }
            
            return new Response($this->serialize($data, 'json'), 200, array('Content-Type' => 'application/json'));
        });

        $controllers->delete('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            
            $deleted = $this->delete($request, $entity, $id, $this->token);
            if (!$deleted) {
                return new JsonResponse('Data not found', 404);
            }

            return new JsonResponse('Data deleted', 204);
        });

        $controllers->before(function (Request $request) use ($app) {
            
            if ($request->getMethod() == 'OPTIONS') {
                return new Response('', 204);
            }

            $entity = $request->get('_route_params')['entity'];

            if (in_array('/' . $entity, $this->getNoAuthCalls())) {
                return;
            }

            $authService = $this->getAuthenticationService();
            if (!$authService) {
                return;
            }

            if(!$request->headers->has($this->getAuthHeader())) {
                return new JsonResponse('Unauthorized', 401);
            }

            $token = $request->headers->get($this->getAuthHeader());

            $authService->setEm($this->em);
            $authService->setCache($this->cache);

            if (!$authService->authenticate($token)) {
                return new JsonResponse('Unauthorized', 401);
            }

            $authorizationService = $this->getAuthorizationService();
            if (!$authorizationService) {
                return;
            }

            $authorizationService->setEm($this->em);
            $authorizationService->setCache($this->cache);

            if (!$authorizationService->isAuthorized($token, $entity)) {
                return new JsonResponse('Unauthorized', 403);
            }
            $this->token = $token;
        });

        return $controllers;
    }   
}
         
