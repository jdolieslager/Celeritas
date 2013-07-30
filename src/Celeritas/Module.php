<?php
namespace Celeritas;

use Zend\Console\Request as ConsoleRequest;
use Zend\EventManager\Event;
use Zend\Loader;
use Zend\ModuleManager\Feature;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\MvcEvent;

/**
 * @category    Module
 */
class Module implements Feature\AutoloaderProviderInterface
{
    /**
     * Runtime create cache flag
     *
     * @var boolean
     */
    protected $generateCache = true;

    /**
     * {@inheritdoc}
     */
    public function init(ModuleManager $moduleManager)
    {
        /** @var \Zend\EventManager\SharedEventManager $eventManager */
        $eventManager = $moduleManager->getEventManager()->getSharedManager();
        $eventManager->attach('Zend\Mvc\Application', 'finish', array($this, 'finish'));
    }

    /**
     * {@inheritdoc}
     */
    public function onBootstrap(MvcEvent $event)
    {
        $event->getTarget()->getEventManager()
            ->attach('celeritas.no_cache', array($this, 'noCache'));
    }

    /**
     * Tells the module to not generate cacheclass
     *
     * @param Event $event
     */
    public function noCache(Event $event)
    {
        $this->generateCache = false;
    }

    /**
     * @param MvcEvent $mvcEvent
     */
    public function finish(MvcEvent $mvcEvent)
    {
        $applicationConfig = $mvcEvent->getApplication()
            ->getServiceManager()->get('ApplicationConfig');

        $celeritasOptions = $applicationConfig['celeritas_options'];
        $file             = $celeritasOptions['cache_file'];
        $swap             = $celeritasOptions['cache_file'] . '.swp';

        if (
            $this->generateCache === false ||
            $celeritasOptions['enabled'] === false ||
            $mvcEvent->getRequest() instanceof ConsoleRequest ||
            is_file($file) ||
            is_file($swap)
        ) {
            return;
        }

        // Retrieve requested extension
        $requestUri = $mvcEvent->getRequest()->getRequestUri();
        $extension  = substr($requestUri, strrpos($requestUri, '.') + 1);

        if (in_array($extension, $celeritasOptions['ignore_extensions'])) {
            return;
        }

        $settings = new Cacher\Entity\Settings();
        $settings->setFile($file)
            ->setSwapFile($swap)
            ->setIgnoreNamespaces($celeritasOptions['ignore_namespaces']);

        $cache = new Cacher\Cacher($settings);
        $cache->cache();
    }

    /**
     * {@inheritdoc}
     */
    public function getAutoloaderConfig()
    {
        return array(
            Loader\AutoloaderFactory::STANDARD_AUTOLOADER => array(
                Loader\StandardAutoloader::LOAD_NS => array(
                    __NAMESPACE__ => __DIR__,
                ),
            ),
        );
    }
}