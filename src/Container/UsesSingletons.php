<?php

namespace Rcalicdan\FiberAsync\Container;

trait UsesSingletons
{
    private static array $singletonInstances = [];
    private static array $singletonFactories = [];

    protected function singleton(string $className, ?callable $factory = null): object
    {
        if (! isset(self::$singletonInstances[$className])) {
            if ($factory !== null) {
                self::$singletonFactories[$className] = $factory;
            }

            if (isset(self::$singletonFactories[$className])) {
                self::$singletonInstances[$className] = self::$singletonFactories[$className]();
            } elseif (class_exists($className)) {
                self::$singletonInstances[$className] = new $className;
            } else {
                throw new \InvalidArgumentException("Class {$className} does not exist");
            }
        }

        return self::$singletonInstances[$className];
    }

    protected function bindSingleton(string $className, callable $factory): void
    {
        self::$singletonFactories[$className] = $factory;
        // Remove existing instance to force recreation with new factory
        unset(self::$singletonInstances[$className]);
    }

    protected function fresh(string $className, ?callable $factory = null): object
    {
        if ($factory !== null) {
            return $factory();
        }

        if (isset(self::$singletonFactories[$className])) {
            return self::$singletonFactories[$className]();
        }

        if (class_exists($className)) {
            return new $className;
        }

        throw new \InvalidArgumentException("Class {$className} does not exist");
    }

    protected function clearSingletons(): void
    {
        self::$singletonInstances = [];
        self::$singletonFactories = [];
    }

    protected function hasSingleton(string $className): bool
    {
        return isset(self::$singletonInstances[$className]);
    }
}
