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

namespace Sonata\AdminBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @final since sonata-project/admin-bundle 3.52
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class ChoiceFieldMaskType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $sanitizedMap = [];
        $allFieldNames = [];
        foreach ($options['map'] as $value => $fieldNames) {
            if (is_iterable($fieldNames)) {
                foreach ($fieldNames as $fieldName) {
                    $sanitizedFieldName = str_replace(['__', '.'], ['____', '__'], $fieldName);
                    $sanitizedMap[$value][] = $sanitizedFieldName;
                    $allFieldNames[] = $sanitizedFieldName;
                }
            }
        }

        $view->vars['all_fields'] = array_unique($allFieldNames);
        $view->vars['map'] = $sanitizedMap;

        $options['expanded'] = false;

        parent::buildView($view, $form, $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'map' => [],
        ]);

        $resolver->setAllowedTypes('map', 'array');
    }

    public function getParent()
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix()
    {
        return 'sonata_type_choice_field_mask';
    }
}
