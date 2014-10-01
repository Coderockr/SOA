<?php

namespace Coderockr\SOA;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use Doctrine\ORM\Query\Expr\Join;

class RestControllerProvider implements ControllerProviderInterface
{
    private $useCache = false;
    private $cache;
    private $em;
    private $entityNamespace;
    private $authenticationService = null;
    private $authorizationService = null;
    private $noAuthCalls = array();
    private $authHeader = 'Authorization';

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
     
    public function setAuthenticationService($authenticationService, $noAuthCalls = array())
    {
        $this->noAuthCalls = $noAuthCalls;
        return $this->authenticationService = $authenticationService;
    }

    public function find($entity, $id)
    {
        $data = $this->em
                     ->getRepository($this->getEntityName($entity))
                     ->find($id);

        return $data;
    }

    public function findAll($entity, $fields, $joins, $limit, $offset, $filter, $sort, $count)
    {
        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder->from($this->getEntityName($entity), 'e');

        if ($joins) {
            foreach ($joins as $j) {
                
                $join = explode(':', $j);

                $entityName = 'j'.$join[0];
                $conditionField = $join[1];
                $conditionOp = $join[2];
                $conditionValue = $join[3];

                if ($conditionOp == 'like') {
                    $conditionValue = '%'.$conditionValue.'%';
                }
                
                $queryBuilder->select($entityName);
                $queryBuilder->innerJoin(
                    'e.'.$join[0], 
                    $entityName, 
                    Join::WITH, 
                    $queryBuilder->expr()->$conditionOp($entityName.'.'.$conditionField, "'".$conditionValue."'"));
            }
        }

        if ($sort) {
            
            $sort = explode(':', $sort);
            if ($sort > 1) {

                $prop = $sort[0];
                if (strpos($prop, '.') === false) {
                    $prop = 'e.' . $prop;
                }
                $queryBuilder->orderBy($prop, $sort[1]);
            }
        }

        if ($limit) {
            $queryBuilder->setMaxResults($limit);
        }

        if ($offset) {
            $queryBuilder->setFirstResult($offset);
        }

        if ($filter) {
            foreach ($filter as $f) {
                $param = explode(":", $f);
                
                $conditionField = $param[0];
                $conditionOp = $param[1];
                $conditionValue = $param[2];

                if ($conditionOp == 'like') {
                    $conditionValue = '%'.$conditionValue.'%';
                }

                $queryBuilder->andWhere($queryBuilder->expr()->$conditionOp('e.' . $conditionField, "'" . $conditionValue . "'"));
            }
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
                $count), 'json');

            return new Response($data, 200, array('Content-Type' => 'application/json'));
        });

        $controllers->get('/{entity}/{id}', function (Application $app, $entity, $id) {
            
            $data =  $this->find($entity, $id);
            if (!$data) {
                return new JsonResponse('Data not found', 404);
            }
            
            return new Response($this->serialize($data, 'json'), 200, array('Content-Type' => 'application/json'));
        
        })->assert('id', '\d+');

        $controllers->post('/{entity}', function (Application $app, Request $request, $entity) {
            $entityData = $this->serialize($this->create($request, $entity), 'json');
            return new Response($entityData, 200, array('Content-Type' => 'application/json'));
        });

        $controllers->put('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            
            $data = $this->update($request, $entity, $id);
            if (!$data) {
                return new JsonResponse('Data not found', 404);
            }
            
            return new Response($this->serialize($data, 'json'), 200, array('Content-Type' => 'application/json'));
        });

        $controllers->delete('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
            
            $deleted = $this->delete($request, $entity, $id);
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

            if (!$authorizationService->isAuthorized($token, $entity)) {
                return new JsonResponse('Unauthorized', 401);
            }
        });

        return $controllers;
    }   
}
         