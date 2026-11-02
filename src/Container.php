<?php
/**
 * SharkyPHP Container Library
 *
 * 容器
 *
 * @package SharkyPHP\\Container
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Container;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use Sharky\Container\Exception\ContainerException;
use Throwable;

/**
 * @template T
 */
class Container implements ContainerInterface
{
    // 缓存反射
    private $reflectionMap;

    // 用于存储绑定关系的数组，键为抽象类型名，值为对应的具体具体（可以是闭包函数或者类名等）
    private array $bindings = [];
    // 用于存储已经创建的实例，键为抽象类型名，值为对应的实例对象
    private array $instances = [];
    // 绑定缓冲区
    private array $reservations = [];
    private bool $committed     = false;

    // 绑定抽象类型和具体具体
    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void
    {
        if (array_key_exists($abstract, $this->bindings)) {
            throw new ContainerException(
                sprintf('Duplicate binding, abstract [%s] already exists in formal binding table.', $abstract)
            );
        }

        $this->bindings[$abstract] = $this->analysis($abstract, $concrete, $shared);
    }

    // 绑定单例对象
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    // 批量绑定服务
    public function batch(array $map, bool $shared = false): self
    {
        foreach ($map as $abstract => $candidate) {
            if (is_array($candidate)) {
                $shared   = $candidate['shared'] ?? $shared;
                $concrete = $candidate['concrete'] ?? $abstract;
            } else {
                $concrete = $candidate;
            }
            if ($shared) {
                $this->singleton($abstract, $concrete);
            } else {
                $this->bind($abstract, $concrete);
            }
        }
        return $this;
    }

    // 分析抽象类
    private function analysis(string $abstract, mixed $concrete = null, bool $shared = false): array
    {
        // 没有给具体类，那么抽象类名既具体类
        if ($concrete == null) {
            $concrete = $abstract;
        }

        // 两种合法情况：
        // 1. string+存在类
        // 2. is_callable
        if (! (is_string($concrete) && class_exists($concrete, true))
            && ! is_callable($concrete)
        ) {
            throw new ContainerException(sprintf('Implementation for abstract [%s] must be a class name or closure.', $abstract));
        }

        return [
            'concrete' => $concrete,
            'shared'   => $shared,
        ];
    }

    // 创建类的实例，解析并注入依赖
    public function make(string $abstract, array $provided = []): mixed
    {
        // 已经绑定的取绑定具体
        // 没有绑定的抽象既具体
        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;

        if (! $this->has($abstract)) {
            throw new ContainerException(sprintf('Container make failed: [%s] cannot be instantiated', $abstract));
        }

        if (is_string($concrete) && class_exists($concrete, true)) {
            // 反射获取构造函数信息
            $reflection  = ReflectionCache::getClass($concrete);
            $constructor = $reflection->getConstructor();

            // 如果类没有构造函数直接创建实例
            // 否则获取构造函数参数后注入参数
            if ($constructor === null) {
                $instance = $reflection->newInstance();

            } else {
                $params     = $constructor->getParameters();
                $argsToPass = $this->parameterResolver($abstract, $params, $provided);
                $instance   = $reflection->newInstanceArgs($argsToPass);
            }
        } elseif (is_callable($concrete)) {

            // 反射函数/方法拿到参数
            $reflection = ReflectionCache::getCallable($concrete);
            $params     = $reflection->getParameters();

            // 解析/自动注入参数
            $argsToPass = $this->parameterResolver($abstract, $params, $provided);
            $instance   = $concrete($this, $argsToPass);
        }

        return $instance;
    }

    // 从容器中获取一个实例
    public function get(string $abstract): mixed
    {
        // 已经绑定的单例类且存在实例
        $shared = $this->bindings[$abstract]['shared'] ?? false;
        if ($shared && isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // 创建一个新的实例
        $instance = $this->make($abstract, []);

        // 如果是单例类就缓存实例
        if ($shared) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    // 判断类是否可以被容器创建
    public function has(string $abstract): bool
    {
        // 1. 如果存在显式绑定，直接true
        if (array_key_exists($abstract, $this->bindings)) {
            return true;
        }

        // 2. 判断是否是可以自动实例化的类
        return ReflectionCache::isConstructorPublic($abstract);
    }

    // 驼峰命名转小写加下划线
    private function camelToSnake(string $str): string
    {
        $str = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $str);
        $str = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $str);
        return strtolower($str);
    }

    // 下划线转大驼峰
    private function snakeToPascal(string $str): string
    {
        // 下划线后面第一个字母大写，删掉下划线
        $res = preg_replace_callback('/_([a-z])/', function ($m) {
            return strtoupper($m[1]);
        }, $str);
        // 首字母大写
        return ucfirst($res);
    }

    // 参数解析器
    private function parameterResolver(string $abstract, array $params, array $provided): array
    {
        $argsToPass = [];
        foreach ($params as $paramIdx => $parameter) {
            $parameterName = $parameter->getName();
            $parameterType = $parameter->getType();
            if (isset($provided[$parameterName])) {

                // 已经指定参数
                $argsToPass[$parameterName] = $provided[$parameterName];
                continue;

            } elseif (isset($provided[$paramIdx])) {

                // 存在索引参数
                $argsToPass[$parameterName] = $provided[$paramIdx];
                continue;

            } elseif ($parameter->isDefaultValueAvailable()) {

                // 存在默认值，绑定默认值
                $argsToPass[$parameterName] = $parameter->getDefaultValue();
                continue;

            }

            if ($parameterType instanceof ReflectionNamedType
                && ! $parameterType->isBuiltin()
            ) {
                try {
                    // 尝试自动实例化并绑定
                    $parameterTypeName = $parameterType->getName();
                    if ($this->has($parameterTypeName)) {
                        $argsToPass[$parameterName] = $this->get($parameterTypeName);
                        continue;
                    }
                } catch (Throwable $th) {
                    throw $th;

                }

            }

            if ($parameter->allowsNull()) {

                // 允许是 Null，绑定Null
                $argsToPass[$parameterName] = null;

            } else if (! $parameter->isOptional()) {
                throw new InvalidArgumentException(
                    sprintf("Failed to instantiate abstract type '%s', missing parameter: %s",
                        $abstract, $parameter->getName())
                );
            }

        }

        return $argsToPass;
    }
}
