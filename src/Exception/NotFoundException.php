<?php
/**
 * SharkyPHP Container Library
 *
 * 抽象类不存在
 *
 * @package SharkyPHP\\Container
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Container\Exception;

use RuntimeException;

use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{

}
