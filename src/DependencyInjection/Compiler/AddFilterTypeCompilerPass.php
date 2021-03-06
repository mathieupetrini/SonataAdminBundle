<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @final since sonata-project/admin-bundle 3.52
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
final class AddFilterTypeCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $definition = $container->getDefinition('sonata.admin.builder.filter.factory');
        $types = [];

        foreach ($container->findTaggedServiceIds('sonata.admin.filter.type') as $id => $attributes) {
            $serviceDefinition = $container->getDefinition($id);

            $serviceDefinition->setShared(false);
            $serviceDefinition->setPublic(true); // Temporary fix until we can support service locators

            $types[$serviceDefinition->getClass()] = $id;

            // NEXT_MAJOR: Remove this loop, only FQCN will be supported
            foreach ($attributes as $eachTag) {
                if (isset($eachTag['alias'])) {
                    $types[$eachTag['alias']] = $id;
                }
            }
        }

        $definition->replaceArgument(1, $types);
    }
}
