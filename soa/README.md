# How to use

	<?php
	require_once __DIR__.'/bootstrap.php';

	use Silex\Application,
    	Silex\Provider\DoctrineServiceProvider,
    	Symfony\Component\HttpFoundation\Request,
    	Dflydev\Silex\Provider\DoctrineOrm\DoctrineOrmServiceProvider;

	use Symfony\Component\HttpFoundation\Response;
	use Coderockr\SOA\RestControllerProvider;

	$app = new Application();

	//configuration
	$app->register(new Silex\Provider\SessionServiceProvider());

	//getting the EntityManager
	$app->register(new DoctrineServiceProvider, array(
	    'db.options' => array(
	        'driver' => 'pdo_mysql',
	        'host' => 'localhost',
	        'port' => '3306',
	        'user' => 'skel',
	        'password' => 'skel',
	        'dbname' => 'skel'
	    )
	));

	$app->register(new DoctrineOrmServiceProvider(), array(
	    'orm.proxies_dir' => '/tmp/' . getenv('APPLICATION_ENV'),
	    'orm.em.options' => array(
	        'mappings' => array(
	            array(
	                'type' => 'annotation',
	                'use_simple_annotation_reader' => false,
	                'namespace' => 'Skel\Model',
	                'path' => __DIR__ . '/src'
	            )
	        )
	    ),
	    'orm.proxies_namespace' => 'EntityProxy',
	    'orm.auto_generate_proxies' => true
	));

	$api = new RestControllerProvider();
	$api->setCache($cache); //Doctrine cache, created in bootstrap.php
	$api->setEntityNamespace('Skel\Model');
	$app->mount('/api', $api);

