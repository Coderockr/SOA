# How to use


## bootstrap.php

	<?php
	use Doctrine\ORM\Tools\Setup,
	    Doctrine\ORM\EntityManager,
	    Doctrine\Common\EventManager as EventManager,
	    Doctrine\ORM\Events,
	    Doctrine\ORM\Configuration,
	    Doctrine\Common\Cache\ArrayCache as Cache,
	    Doctrine\Common\Annotations\AnnotationRegistry, 
	    Doctrine\Common\Annotations\AnnotationReader,
	    Doctrine\Common\ClassLoader;

	$loader = require __DIR__.'/vendor/autoload.php';
	$loader->add('Skel', __DIR__.'/src');

	//doctrine
	$config = new Configuration();
	//$cache = new Cache();
	$cache = new \Doctrine\Common\Cache\ApcCache();
	$config->setQueryCacheImpl($cache);
	$config->setProxyDir('/tmp');
	$config->setProxyNamespace('EntityProxy');
	$config->setAutoGenerateProxyClasses(true);
	 
	//mapping (example uses annotations, could be any of XML/YAML or plain PHP)
	AnnotationRegistry::registerFile(__DIR__. DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'doctrine' . DIRECTORY_SEPARATOR . 'orm' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Doctrine' . DIRECTORY_SEPARATOR . 'ORM' . DIRECTORY_SEPARATOR . 'Mapping' . DIRECTORY_SEPARATOR . 'Driver' . DIRECTORY_SEPARATOR . 'DoctrineAnnotations.php');

	\Doctrine\Common\Annotations\AnnotationRegistry::registerFile(
	    __DIR__ . '/vendor/jms/serializer/src/JMS/Serializer/Annotation/Type.php'
	);

	$driver = new Doctrine\ORM\Mapping\Driver\AnnotationDriver(
	    new Doctrine\Common\Annotations\AnnotationReader(),
	    array(__DIR__.'/src/Skel/Model')
	);
	$config->setMetadataDriverImpl($driver);
	$config->setMetadataCacheImpl($cache);


## app.php

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
	//you can set authorization and authentication classes
	//$api->setAuthenticationService(new \Skel\Service\AuthenticationService);
	//$api->setAuthorizationService(new \Skel\Service\AuthorizationService);
	$app->mount('/api', $api);

	$rpc = new RpcControllerProvider();
	$rpc->setCache($cache); //Doctrine cache, created in bootstrap.php
	$rpc->setServiceNamespace('Skel\Service');
	//you can set authorization and authentication classes
	//$api->setAuthenticationService(new \Skel\Service\AuthenticationService);
	//$api->setAuthorizationService(new \Skel\Service\AuthorizationService);
	$app->mount('/rpc', $rpc);
