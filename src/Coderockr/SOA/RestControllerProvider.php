<?php

namespace Coderockr\SOA;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;

class RestControllerProvider implements ControllerProviderInterface
{
    const AUTHORIZATION_HEADER = 'authorization';

    private $useCache = false;
    private $cache;
    private $em;
    private $entityNamespace;
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

    public function setEntityNamespace($entityNamespace)
    {
        $this->entityNamespace = $entityNamespace;
    }

    private function getEntityName($entity)
    {
        return $this->entityNamespace . '\\' . ucfirst($entity);
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

    public function find($entity, $id)
    {
        $data = $this->em
                     ->getRepository($this->getEntityName($entity))
                     ->find($id);

        return $data;
    }

    public function findAll($entity, $fields, $limit, $offset, $filter, $count)
    {
        $queryBuilder = $this->em->createQueryBuilder();
        
        $queryBuilder->from($this->getEntityName($entity), 'e');

        if ($limit) {
            $queryBuilder->setMaxResults($limit);
        }

        if ($offset) {
            $queryBuilder->setFirstResult($offset);
        }
        foreach ($filter as $f) {
            $param = explode("=", $f);
            $queryBuilder->andWhere($queryBuilder->expr()->like('e.' . $param[0], "'%" . $param[1] . "%'"));
        }

        
        $select = 'e';
        if (count($fields) > 0) {
            $select .= '.' . implode(',e.', $fields);
        }
        if ($count == 1) {
            $select = 'count(e.id) recordCount';
        }

        $queryBuilder->select($select);
        $data = $queryBuilder->getQuery()->getResult();

        return $data;
    }   

    public function create($request, $entity)
    {
        $entityName = $this->getEntityName($entity);
        $entity = new $entityName;

        $data = $request->request->all();
        $this->em->persist($this->setData($entity, $data));
        $this->em->flush();

        return $entity;
    }

    private function setData($entity, $data) 
    {
        $class = new \ReflectionClass($entity);
        foreach ($data as $name => $value) {
            if (is_array($value)) { //it's a relationship to another entity
                $id = $value['id'];
                $relatedEntity = $this->getEntityName($name);
                if (isset($value['entityName'])) 
                    $relatedEntity = $this->getEntityName($value['entityName']);
                
                $value = $this->em->find($relatedEntity, $id);
            }
            $method = 'set'. ucfirst($name);
            if ($class->hasMethod($method)) {
               call_user_func(array($entity, $method), $value); 
            }
        }

        return $entity;

    }

    public function update($request, $entity, $id)
    {
        $entityName = $this->getEntityName($entity);
        $entity = $this->find($entity, $id);
        if (!$entity) {
            return false;
        }

        $data = $request->request->all();
        $entity = $this->setData($entity, $data);
        $entity->setUpdated(date('Y-m-d H:i:s'));

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    public function delete($request, $entity, $id)
    {
        $data = $this->em
                     ->getRepository($this->entityNamespace . '\\' . ucfirst($entity))
                     ->find($id);
        if (!$data) {
            return false;
        }
        $this->em->remove($data);
        $this->em->flush();

        return true;
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

        $controllers->get('/', function (Application $app) {
            return 'TODO: documentation';
        });
        
        $controllers->get('/{entity}', function (Application $app, $entity, Request $request) {
            $params = $request->query->all();
            $fields = null;
            $limit = null;
            $offset = null;
            $filter = null;
            $count = null;

            if (isset($params['count'])) {
                $count = $params['count'];
                unset($params['count']);
            }
            if (isset($params['fields'])) {
                $fields = $pieces = explode(",", $params['fields']);
                unset($params['fields']);
            }
            if (isset($params['limit'])) {
                $limit = $params['limit'];
                unset($params['limit']);
            }
            if (isset($params['offset'])) {
                $offset = $params['offset'];
                unset($params['offset']);
            }
            if (isset($params['filter'])) {
                $filter = $pieces = explode(",", $params['filter']);
                unset($params['filter']);
            }
            return $this->serialize($this->findAll($entity, $fields, $limit, $offset, $filter, $count), 'json');
        });

        $controllers->get('/{entity}/{id}', function (Application $app, $entity, $id) {
            $data =  $this->find($entity, $id);
            if (!$data) {
                return new Response('Data not found', 404, array('Content-Type' => 'text/json'));
            }
            return $this->serialize($data, 'json');
        })->assert('id', '\d+');

        $controllers->post('/{entity}', function (Application $app, Request $request, $entity) {
            return $this->serialize($this->create($request, $entity), 'json');
        });

        $controllers->put('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            $data = $this->update($request, $entity, $id);

            if (!$data) {
                return new Response('Data not found', 404, array('Content-Type' => 'text/json'));
            }
            return $this->serialize($data, 'json');
        });

        $controllers->delete('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            $deleted = $this->delete($request, $entity, $id);

            if (!$deleted) {
                return new Response('Data not found', 404, array('Content-Type' => 'text/json'));
            }
            return new Response('Data deleted', 200, array('Content-Type' => 'text/json'));
        });

        $controllers->after(function (Request $request, Response $response) {
            $response->headers->set('Content-Type', 'text/json');
        });

        $controllers->match('{entity}/{id}', function ($entity, $id, Request $request) use ($app) 
        {
            return new Response('', 200, array(
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE',
                'Access-Control-Allow-Headers' => 'Authorization'
            ));
        })->method('OPTIONS')->value('id', null);

        $controllers->before(function (Request $request) use ($app) {
            if ($request->getMethod() == 'OPTIONS') {
                return;
            }

            $authService = $this->getAuthenticationService();
            if ($authService) {
                if(!$request->headers->has($this::AUTHORIZATION_HEADER)) {
                    return new Response('Unauthorized', 401);
                }

                $token = $request->headers->get($this::AUTHORIZATION_HEADER);
                $authService->setEm($this->em);

                if (!$authService->authenticate($token)) {
                    return new Response('Unauthorized', 401);    
                }

                $authorizationService = $this->getAuthorizationService();
                if ($authorizationService) {
                    
                    $authorizationService->setEm($this->em);
                    if (!$authorizationService->isAuthorized($token, $resource['entity'])) {
                        return new Response('Unauthorized', 401);    
                    }
                }

            }
            
        });

        return $controllers;
    }   
}