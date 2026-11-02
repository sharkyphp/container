<?php
/**
 * SharkyPHP Container Library
 *
 * 容器异常
 *
 * @package SharkyPHP\\Container
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Container\Exception;

use RuntimeException;

use Psr\Container\ContainerExceptionInterface;

class ContainerException extends RuntimeException implements ContainerExceptionInterface
{

}
