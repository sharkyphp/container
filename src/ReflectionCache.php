<?php
/**
 * SharkyPHP Container Library
 *
 * 容器反射缓存
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

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;

final class ReflectionCache
{

    private static array $classMetaCache = [];
    private static array $callablCache   = [];
    private static array $recentCache    = [];
    private static int $seq              = 0;
    private const MAX_RECENT             = 10;

    /**
     * 获取反射对象
     * @param string $id 类名
     * @return ReflectionClass
     */
    public static function getClass(string $id): ReflectionClass
    {
        // 热点缓存命中，更新访问序号
        if (isset(static::$recentCache[$id])) {
            static::$recentCache[$id]['last'] = ++static::$seq;
            return static::$recentCache[$id]['ref'];
        }

        // 已经缓存过元信息，类不存在直接返回null
        if (isset(static::$classMetaCache[$id])
            && static::$classMetaCache[$id]['exists'] === false
        ) {
            throw new ReflectionException(sprintf("Class %s not found", $id));
        }

        if (! class_exists($id)) {
            static::$classMetaCache[$id] = [
                'exists'            => false,
                'constructorPublic' => false,
            ];
            throw new ReflectionException(sprintf("Class %s not found", $id));
        }

        $ref = new ReflectionClass($id);

        // 判断构造函数是否public
        $constructor       = $ref->getConstructor();
        $constructorPublic = true;
        if ($constructor !== null) {
            $constructorPublic = $constructor->isPublic();
        }

        // 写入永久元数据缓存
        static::$classMetaCache[$id] = [
            'exists'            => true,
            'constructorPublic' => $constructorPublic,
        ];

        // 写入MRU热点缓存
        static::$recentCache[$id] = [
            'ref'  => $ref,
            'last' => ++static::$seq,
        ];

        // 超过容量，淘汰最久访问
        if (count(static::$recentCache) > static::MAX_RECENT) {
            $oldKey = null;
            $minSeq = PHP_INT_MAX;
            foreach (static::$recentCache as $className => $item) {
                if ($item['last'] < $minSeq) {
                    $minSeq = $item['last'];
                    $oldKey = $className;
                }
            }
            if ($oldKey !== null) {
                unset(static::$recentCache[$oldKey]);
            }
        }

        return $ref;
    }

    /**
     * 获取缓存的元信息：构造函数是否是public
     * 调用这个方法前，请先调用 ::get() 保证元数据已经缓存
     * @param string $id
     * @return bool
     */
    public static function isConstructorPublic(string $id): bool
    {
        try {
            if (! isset(static::$classMetaCache[$id])) {
                // 元数据不存在，主动加载一次
                static::getClass($id);
            }
            return static::$classMetaCache[$id]['constructorPublic'] ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 获取反射对象
     * @param string $cb
     * @return ReflectionFunctionAbstract
     */
    public static function getCallable(callable $cb): ReflectionFunctionAbstract
    {
        $id = static::getCallableKey($cb);

        if (isset(self::$callablCache[$id])) {
            static::$callablCache[$id]['last'] = ++static::$seq;
            return static::$callablCache[$id]['ref'];
        }

        if ($cb instanceof Closure) {
            $ref = new ReflectionFunction($cb);
        }
        if (is_object($cb)) {
            $ref = new ReflectionMethod($cb, '__invoke');
        }
        if (is_array($cb)) {
            $ref = new ReflectionMethod($cb[0], $cb[1]);
        }
        $ref = new ReflectionFunction($cb);

        // 写入MRU热点缓存
        static::$callablCache[$id] = [
            'ref'  => $ref,
            'last' => ++static::$seq,
        ];

        // 超过容量，淘汰最久访问
        if (count(static::$callablCache) > static::MAX_RECENT) {
            $oldKey = null;
            $minSeq = PHP_INT_MAX;
            foreach (static::$callablCache as $callableId => $item) {
                if ($item['last'] < $minSeq) {
                    $minSeq = $item['last'];
                    $oldKey = $callableId;
                }
            }
            if ($oldKey !== null) {
                unset(static::$callablCache[$oldKey]);
            }
        }

        return $ref;
    }

    /**
     * 清空当前进程全部缓存
     */
    public static function clear(): void
    {
        static::$classMetaCache = [];
        static::$recentCache    = [];
        static::$seq            = 0;
    }

    private static function getCallableKey(callable $cb): string
    {
        if ($cb instanceof Closure) {
            return 'closure:' . spl_object_hash($cb);
        }
        if (is_object($cb)) {
            return 'class:' . get_class($cb) . '::__invoke';
        }
        if (is_array($cb)) {
            $cls = is_object($cb[0]) ? get_class($cb[0]) : $cb[0];
            return 'class:' . $cls . ':' . $cb[1];
        }
        if (is_string($cb)) {
            return 'func:' . $cb;
        }
        throw new InvalidArgumentException('invalid callable');
    }
}
