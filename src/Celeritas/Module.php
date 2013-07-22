<?php
namespace Celeritas;

use Zend\Console\Request as ConsoleRequest;
use Zend\Loader;
use Zend\ModuleManager\Feature;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\MvcEvent;

/**
 * @category    Module
 */
class Module implements Feature\AutoloaderProviderInterface
{
    public function init(ModuleManager $moduleManager)
    {
        /** @var \Zend\EventManager\SharedEventManager $eventManager */
        $eventManager = $moduleManager->getEventManager()->getSharedManager();
        $eventManager->attach('Zend\Mvc\Application', 'finish', array($this, 'finish'));
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
            $celeritasOptions['enabled'] === false ||
            $mvcEvent->getRequest() instanceof ConsoleRequest ||
            is_file($file) ||
            is_file($swap)
        ) {
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