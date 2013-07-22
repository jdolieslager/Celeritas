<?php
namespace Celeritas\Cacher\Entity;

/**
 * @category    Celeritas
 * @package     Cacher
 * @subpackage  Entity
 */
class Settings
{
    /**
     * @var string
     */
    protected $file;

    /**
     * @var string
     */
    protected $swapFile;

    /**
     * @var array
     */
    protected $ignoreNamespaces;

    /**
     * @param string $file
     * @return Settings
     */
    public function setFile($file)
    {
        $this->file = $file;

        return $this;
    }

    /**
     * @return string
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * @param string $swapFile
     * @return Settings
     */
    public function setSwapFile($swapFile)
    {
        $this->swapFile = $swapFile;

        return $this;
    }

    /**
     * @return string
     */
    public function getSwapFile()
    {
        return $this->swapFile;
    }

    /**
     * @param array $ignoreNamespaces
     * @return Settings
     */
    public function setIgnoreNamespaces(array $ignoreNamespaces)
    {
        $this->ignoreNamespaces = $ignoreNamespaces;

        return $this;
    }

    /**
     * @return arrays
     */
    public function getIgnoreNamespaces()
    {
        return $this->ignoreNamespaces;
    }
}
