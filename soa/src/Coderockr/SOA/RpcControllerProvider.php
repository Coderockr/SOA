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
	private $em;
	private $entityNamespace;

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

	public function find($entity, $id)
	{
		$data = $this->em->getRepository($this->entityNamespace . '\\' . ucfirst($entity))->find($id);

		return $data;
	}

	public function findAll($entity)
	{
		$data = $this->em
					 ->getRepository($this->entityNamespace . '\\' . ucfirst($entity))
					 ->findAll();

		return $data;
	}	

	public function create($request, $entity)
	{
		$data = $request->request->all();
		var_dump($data);
		return $entity;
	}

	public function update($request, $entity, $id)
	{
		return $id;
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
		$serializer = SerializerBuilder::create()->build();
		return $serializer->serialize($data, $type);
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
        
        $controllers->get('/{entity}', function (Application $app, $entity) {
        	return $this->serialize($this->findAll($entity), 'json');
        });

        $controllers->get('/{entity}/{id}', function (Application $app, $entity, $id) {
        	$data =  $this->find($entity, $id);
        	if (!$data) {
        		return new Response('Data not found', 404, array('Content-Type' => 'text/json'));
        	}
        	return $this->serialize($data, 'json');
        })->assert('id', '\d+');

        $controllers->post('/{entity}', function (Application $app, Request $request, $entity) {
        	return $this->create($request, $entity);
        });

        $controllers->put('/{entity}/{id}', function (Application $app, Request $request, $entity, $id) {
        	return $this->update($request, $entity, $id);
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

        return $controllers;
    }	
}