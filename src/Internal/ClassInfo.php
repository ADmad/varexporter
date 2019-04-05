<?php

declare(strict_types=1);

namespace Brick\VarExporter\Internal;

use Brick\VarExporter\ExportException;

/**
 * Holds computed information about a class.
 *
 * @internal This class is for internal use, and not part of the public API. It may change at any time without warning.
 */
final class ClassInfo extends \Exception
{
    /**
     * The reflection of the class.
     *
     * @var \ReflectionClass
     */
    public $reflectionClass;

    /**
     * Whether the given class has a public, static, __set_state() method.
     *
     * @var bool
     */
    public $hasSetState = false;

    /**
     * Whether the given class has any non-public, non-static properties.
     *
     * Such classes cannot be safely exported, and must provide a __set_state() method.
     *
     * @var bool
     */
    public $hasNonPublicProps = false;

    /**
     * @var bool
     */
    public $hasConstructor = false;

    /**
     * @var bool
     */
    public $hasSerializeMagicMethods = false;

    /**
     * ClassInfo constructor.
     *
     * @param string $className The fully qualified class name.
     *
     * @throws \ReflectionException If the class does not exist.
     */
    public function __construct(string $className)
    {
        $this->reflectionClass = $class = new \ReflectionClass($className);

        if ($class->hasMethod('__set_state')) {
            $method = $class->getMethod('__set_state');
            $this->hasSetState = $method->isPublic() && $method->isStatic();
        }

        for ($currentClass = $class; $currentClass; $currentClass = $currentClass->getParentClass()) {
            foreach ($currentClass->getProperties() as $property) {
                if (! $property->isPublic() && ! $property->isStatic()) {
                    $this->hasNonPublicProps = true;
                    break 2;
                }
            }
        }

        $constructor = $class->getConstructor();

        if ($constructor) {
            $this->hasConstructor = true;
        }

        if ($class->hasMethod('__serialize') && $class->hasMethod('__unserialize')) {
            $this->hasSerializeMagicMethods = true;
        }
    }

    /**
     * Returns public and private object properties, as an associative array.
     *
     * This is unlike get_object_vars(), which only returns properties accessible from the current scope.
     *
     * The returned values are in line with those returned by var_export() in the array passed to __set_state(); unlike
     * var_export() however, this method throws an exception if the object has overridden private properties, as this
     * would result in a conflict in array keys. In this case, var_export() would return multiple values in the output,
     * which once executed would yield an array containing only the last value for this key in the output.
     *
     * This way we offer a better safety guarantee, while staying compatible with var_export() in the output.
     *
     * @param object $object
     *
     * @return array
     *
     * @throws ExportException
     */
    public function getObjectVars($object) : array
    {
        $result = [];

        $current = new \ReflectionObject($object);
        $isParentClass = false;

        while ($current) {
            foreach ($current->getProperties() as $property) {
                if ($isParentClass && ! $property->isPrivate()) {
                    // property already handled in the child class.
                    continue;
                }

                $name = $property->getName();

                if (array_key_exists($name, $result)) {
                    throw new ExportException(
                        'Class "' . $this->reflectionClass->getName() . '" has overridden private properties. ' .
                        'This is not supported for exporting objects with __set_state().'
                    );
                }

                $property->setAccessible(true);
                $result[$name] = $property->getValue($object);
            }

            $current = $current->getParentClass();
            $isParentClass = true;
        }

        return $result;
    }
}
