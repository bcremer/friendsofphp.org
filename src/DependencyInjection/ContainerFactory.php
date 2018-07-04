<?php declare(strict_types=1);

namespace Fop\DependencyInjection;

use Psr\Container\ContainerInterface;

final class ContainerFactory
{
    public function create(): ContainerInterface
    {
        $appKernel = new FopKernel('dev', true);
        $appKernel->boot();

        return $appKernel->getContainer();
    }
}