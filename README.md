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


# How to use Rest

## How to use filter?

	http://skel.dev/api/v1/user?filter=name:like:%elton%,password:eq:teste

	This will query for name LIKE %elton% and password = teste

## How to use joins?

	http://skel.dev/api/v1/user?joins=roleColletion:key:eq:admin

	This will query all users with where roleColletion.key = admin

## Operators:

	Use this guide to check what you can put between field:{operator}:value

	http://docs.doctrine-project.org/en/2.1/reference/query-builder.html#the-expr-class

	You can use `eq` `like` `lt` `lte` `gt` `gte` `neq` and more methods based on key:value

## Combined with Fields, limit, offset

	http://skel.dev/api/v1/user?fields=id,name&limit=10&offset=0

	This set of parameters can be combined with both listed above (filters and joins)

## Count 
	http://skel.dev/api/v1/user?filter=name:like:%elton%&count=1

	http://skel.dev/api/v1/user?count=1

