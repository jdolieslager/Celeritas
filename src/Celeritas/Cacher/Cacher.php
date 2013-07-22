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
        // Traits first, then interfaces at last the classes
        $classes = array_merge(
            get_declared_traits(),
            get_declared_interfaces(),
            get_declared_classes()
        );

        // We only want to cache classes once
        $classes = array_unique($classes);

        // Open working file
        $handle = fopen($this->getConfig()->getSwapFile(), 'w+');

        // Write PHP open tag
        fwrite($handle, '<?php' . PHP_EOL);

        // Walk through the classes
        foreach ($classes as $index  => $class) {
            $parts = explode('\\', $class);
            if (count($parts) < 2) {
                $namespace = '\\';
            } else {
                $class     = array_pop($parts);
                $namespace = implode('\\', $parts);
            }

            // Check if we should skip this namespace
            $skip = false;
            foreach ($this->getConfig()->getIgnoreNamespaces() as $ignoreNamespace) {
                if (strpos($namespace, $ignoreNamespace) === 0) {
                    $skip = true;
                    break;
                }
            }

            // Should we skip the class??
            if ($skip === true) {
                continue;
            }

            // Write namespace to file
            fwrite($handle, $this->buildNamespace($namespace, $class));
        }

        // Close cache file handle
        fclose($handle);

        // Minify cache file
        file_put_contents(
            $this->getConfig()->getSwapFile(),
            php_strip_whitespace($this->getConfig()->getSwapFile())
        );

        // Replace old cache file
        rename($this->getConfig()->getSwapFile(), $this->getConfig()->getFile());
    }

    /**
     * Create namespace code
     *
     * @param string $namespace
     * @param Reflection\ClassReflection $class
     * @return string the namespace + class + uses
     * @todo Detect defined constants
     */
    protected function buildNamespace($namespace, $class)
    {
        $code  = "namespace {$namespace} {" . PHP_EOL;
        $uses  = array();
        $class = new Reflection\ClassReflection($namespace . '\\' . $class);

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

        return $code;
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
     * @return Entity\Settings
     */
    public function getConfig()
    {
        return $this->config;
    }
}
