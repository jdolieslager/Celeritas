#Celeritas

### Installation

**1. Add the following configuration to your config/application.config.php**

```php
array(
  'celeritas_options' => array(
        'enabled'           => true,
        'cache_file'        => __DIR__ . '/../data/cache/classcache/' .
                               md5(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) .
                               '.php',
        'ignore_namespaces' => array(
            'Celeritas',
            'Composer\Autoload',
            '\\',
        ),
    ),
);
```

**2. Add the following to your index.php before including the autoloader**

```php
$applicationConfig = require 'config/application.config.php';

if (
    $applicationConfig['celeritas_options']['enabled'] &&
    is_file($applicationConfig['celeritas_options']['cache_file'])
) {
    require_once $applicationConfig['celeritas_options']['cache_file'];
}

// Autoloading and stuff

Zend\Mvc\Application::init($applicationConfig)->run();
```

**3. Make sure that the cache_file directory is writable.**

**Note:** For development it is better to disable the caching mechanisme

### Additional features

1. Prevent class caching

```php
/** @var \Zend\Mvc\MvcEvent $mvcEvent */
$eventManager = $mvcEvent->getTarget()->getEventManager();

// No Cache
$eventManager->trigger('celeritas.no_cache', $this, array());
```

