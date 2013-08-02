<?php
namespace Celeritas\Cacher;

use Celeritas\Exception;
use Zend\Code\Reflection;

/**
 * @category Celeritas
 * @package  Cacher
 */
class Cacher
{
    const TAB = '    ';

    /**
     * @var Entity\Settings
     */
    protected $config;

    /**
     * Keep tracks which classes are be done
     *
     * @var array (<class> => <boolean>)
     */
    protected $classList = array();

    /**
     * Contains the class cache file handle
     *
     * @var resource
     */
    protected $handle;

    /**
     * @var array
     */
    protected $exceptions = array(
        1 => "Dir '%s' does not exits.",
        2 => "Dir '%s' is not writable.",
        3 => "File '%s' is not writable.",
    );

    /**
     * @param Entity\Settings $settings
     */
    public function __construct(Entity\Settings $settings)
    {
        $this->config = $settings;

        $file = $this->getConfig()->getFile();
        $dir = dirname($file);

        if (is_dir($dir) === false) {
            throw new Exception\InvalidArgumentException(
                sprintf($this->exceptions[1], $dir),
                1
            );
        }

        if (is_writable($dir) === false) {
            throw new Exception\InvalidArgumentException(
                sprintf($this->exceptions[2], $dir),
                2
            );
        }

        if (is_file($file) && is_writable($file)) {
            throw new Exception\InvalidArgumentException(
                sprintf($this->exceptions[3], $file),
                3
            );
        }
    }

    /**
     * Make cache file from current loaded classes
     *
     */
    public function cache()
    {
        set_time_limit(120);

        $swpLockFile = $this->getConfig()->getSwapFile() . '.lock';
        if (is_file($swpLockFile)) {
            return;
        }

        file_put_contents($swpLockFile, '');

        // Open working file
        if (is_file($this->getConfig()->getSwapFile()) === false) {
            file_put_contents($this->getConfig()->getSwapFile(), '');
        }

        $this->handle = fopen($this->getConfig()->getSwapFile(), 'r+');

        // Lock the file
        if (flock($this->handle, LOCK_EX) === false) {
            return;
        }

        // Clear the file
        ftruncate($this->handle, 0);

        // Traits first, then interfaces at last the classes
        $classes = array_merge(
            get_declared_traits(),
            get_declared_interfaces(),
            get_declared_classes()
        );

        // We only want to cache classes once
        $classes = array_unique($classes);

        $this->classList = array_flip($classes);
        $this->classList = array_fill_keys($classes, false);



        // Write PHP open tag
        fwrite($this->handle, '<?php' . PHP_EOL);

        // Walk through the classes
        foreach ($this->classList as $class  => &$used) {
            $this->processClassIntoCacheFile(new Reflection\ClassReflection($class));
        }

        // Flush last contents to the file
        fflush($this->handle);

        // Release the swap lock
        flock($this->handle, LOCK_UN);

        // Close cache file handle
        fclose($this->handle);

        // Minify cache file
        file_put_contents(
            $this->getConfig()->getSwapFile(),
            php_strip_whitespace($this->getConfig()->getSwapFile())
        );

        $fileLock = $this->getConfig()->getFile() . '.lock';
        file_put_contents($fileLock, '');

        if (is_file($this->getConfig()->getFile())) {
            unlink($this->getConfig()->getFile());
        }

        // Replace old cache file
        copy($this->getConfig()->getSwapFile(), $this->getConfig()->getFile());

        if (is_file($this->getConfig()->getSwapFile())) {
            // Hotfix for Windows environments
            if (@unlink($this->getConfig()->getSwapFile()) === false) {
                unlink($this->getConfig()->getSwapFile());
            }
        }

        // Unlink Locks
        unlink($swpLockFile);
        unlink($fileLock);
    }

    /**
     * Create namespace code
     *
     * @param string $namespace
     * @param Reflection\ClassReflection $class
     * @return string the namespace + class + uses
     * @todo Detect defined constants
     */
    protected function buildNamespace(Reflection\ClassReflection $class)
    {
        $code  = "namespace {$class->getNamespaceName()} {" . PHP_EOL;
        $uses  = array();

        // Reformat uses
        foreach ($class->getDeclaringFile()->getUses() as $use) {
            $uses[$use['use']] = $use['as'];
        }

        //Just sort the uses
        ksort($uses);

        // Create block by block
        $code .= $this->buildUses($uses);
        // @TODO $this->buildConstants($class);
        $code .= $this->buildClassDecleration($class);
        $code .= $this->buildExtend($uses, $class);
        $code .= $this->buildInterface($uses, $class);
        $code .= PHP_EOL . $this->buildContent($class) . PHP_EOL;

        $code .= "}" . PHP_EOL.PHP_EOL;

        // Clear reflection memory
        \Zend\Code\Scanner\CachingFileScanner::clearCache();

        return fwrite($this->handle, $code);
    }

    /**
     * Create namespace uses code
     *
     * @param array $uses array(<classname> => <alias>)
     * @return string
     */
    protected function buildUses(array $uses)
    {
        $code = '';

        foreach ($uses as $use => $as) {
            $code .= self::TAB . "use {$use}";

            if (empty($as) === false) {
                $code .= " as {$as}";
            }

            $code .= ";" . PHP_EOL;
        }

        return $code;
    }

    /**
     * Create the Trait/Interface/Class definition
     *
     * @param Reflection\ClassReflection $class
     * #return string
     */
    protected function buildClassDecleration(Reflection\ClassReflection $class)
    {
        $code = '';

        if (
            $class->isTrait() === false &&
            $class->isInterface() === false&&
            $class->isAbstract()
        ) {
            $code .= "abstract ";
        }

        if ($class->isFinal()) {
            $code .= "final ";
        }

        if ($class->isInterface()) {
            $code .= "interface ";
        } else if ($class->isTrait()) {
            $code .= "trait ";
        } else {
            $code .= "class ";
        }

        $code .= $class->getShortName();

        return $code;
    }

    /**
     * Create the extend code
     *
     * @param array $uses
     * @param Reflection\ClassReflection $class
     */
    protected function buildExtend(array &$uses, Reflection\ClassReflection $class)
    {
        $code             = '';
        $classNamespace   = $class->getNamespaceName();
        $extendReflection = $class->getParentClass();

        // Check if the class extends something
        if ($extendReflection !== false && $classNamespace) {
            $extendFullClassName = $extendReflection->getName();

            // Make sure the class has already been cached
            $this->processClassIntoCacheFile($extendReflection);

            // Check if the class has been defined in the uses
            if (array_key_exists($extendFullClassName, $uses)) {
                // Set the use alias or the shortname when no alias has been set
                $extend = empty($uses[$extendFullClassName]) ?
                    $extendReflection->getShortName() :
                    $uses[$extendFullClassName];
            } else {
                // Check if we're extending from a subnamespace
                $inNamespace = (strpos($extendReflection->getName(), $classNamespace) === 0);

                if ($inNamespace) {
                    $extend = substr($extendFullClassName, strlen($classNamespace) + 1);
                } else {
                    $extend = "\\{$extendReflection->getName()}";
                }
            }

            $code .= " extends {$extend}";
        } else if ($extendReflection !== false) {
            // Make sure the class has already been cached
            $this->processClassIntoCacheFile($extendReflection);

            // We're extending from the root namespace
            $code .= " extends \\{$extendReflection->getName()}";
        }

        return $code;
    }

    /**
     * Build the interface part
     *
     * @param array $uses
     * @param Reflection\ClassReflection $class
     * @return string
     */
    protected function buildInterface(&$uses, Reflection\ClassReflection $class)
    {
        $code                 = '';
        $interfaces           = $class->getInterfaces();
        $parentClass          = $class->getParentClass();

        // Normalize interfaces array to string
        foreach ($interfaces as &$interface) {
            $interfaceReflections[$interface->getName()] = $interface;
            $interface = $interface->getName();
        }

        // Remove interface from parent class
        if ($parentClass !== false) {
            $parentInterfaces = $parentClass->getInterfaces();

             foreach ($parentInterfaces as &$parentInterface) {
                $interfaceReflections[$parentInterface->getName()] = $parentInterface;
                $parentInterface = $parentInterface->getName();
            }

            $interfaces = array_diff($interfaces, $parentInterfaces);
        }

        // No interfaces found? Return ''
        if (count($interfaces) === 0) {
            return $code;
        }

        // Create extend/implement keyword
        $code          .= $class->isInterface() ? ' extends ' : ' implements ';
        $classNamespace = $class->getNamespaceName();

        // Retrieve interfaces from the interfaces
        foreach ($interfaces as &$interface) {
            $parentInterfaces = $interfaceReflections[$interface]->getInterfaces();

            foreach ($parentInterfaces as &$parentInterface) {
                $interfaceReflections[$parentInterface->getName()] = $parentInterface;
                $parentInterface = $parentInterface->getName();
            }

            // Remove already implemented interfaces
            $interfaces = array_diff($interfaces, $parentInterfaces);
        }

        // define interface names
        foreach ($interfaces as &$interface) {
            // Make sure the class has already been cached
            $this->processClassIntoCacheFile($interfaceReflections[$interface]);

            // Check if the interface has been defined in the uses
            if (array_key_exists($interface, $uses)) {
                // Set the use alias or the shortname when no alias has been set
                $interface = empty($uses[$interface]) ?
                    $interfaceReflections[$interface]->getShortName() :
                    $uses[$interface];
            } else {
                // Check if we're implementing from a subnamespace
                $inNamespace = (strpos($interface, $classNamespace) === 0);

                if ($inNamespace) {
                    $interface = substr($interface, strlen($classNamespace) + 1);
                } else {
                    $interface = "\\{$interface}";
                }
            }
        }

        $code .= implode(', ', $interfaces);

        return $code;
    }

    /**
     * Build the content of the class
     * It replace the __DIR__ constant with the class directoryname
     *
     * @param Reflection\ClassReflection $class
     * @return string
     */
    protected function buildContent(Reflection\ClassReflection $class)
    {
        $code     = $class->getContents(false);
        $classDir = dirname($class->getFileName());

        $code = str_replace('__DIR__', "'{$classDir}'", $code);

        return $code;
    }

    /**
     * Makes several checks.
     *
     * 1. Exclude blacklisted namespaces
     * 2. Exclude blacklisted classes
     * 3. Skip Internal classes
     *
     * @param Reflection\ClassReflection $class
     */
    protected function processClassIntoCacheFile(Reflection\ClassReflection $class)
    {
        if ($this->classList[$class->getName()] === true) {
            return;
        }

        if ($class->isInternal() === true) {
            return;
        }

        // Make the string regex compactible
        $excludeNamespaces = array_map(function($namespace) {
            return str_replace('\\', '[\\\\]', $namespace);
        }, $this->getConfig()->getIgnoreNamespaces());

        // Make the regex
        $excludeNamespaceRegex = '/^(' . implode('|', $excludeNamespaces) . ')(.*)/';

        if (preg_match($excludeNamespaceRegex, $class->getName())) {
            return;
        }

        $this->classList[$class->getName()] = true;
        $this->buildNamespace($class);
    }

    /**
     * @return Entity\Settings
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Delete swap file
     */
    public function __destrucsss()
    {
        if ($this->handle === null) {
            return;
        }

        // Destruct swap file if exists
        if (is_file($this->getConfig()->getSwapFile())) {
            unlink($this->getConfig()->getSwapFile());
        }
    }
}
