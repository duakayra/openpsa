<?php
/**
 * @copyright CONTENT CONTROL GmbH, http://www.contentcontrol-berlin.de
 */

namespace midcom\datamanager\extension\type;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use midcom\datamanager\extension\helper;
use midcom\datamanager\validation\urlname as validator;

/**
 * Experimental urlname type
 */
class urlname extends TextType
{
    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'constraints' => [],
            'write_privilege' => ['privilege' => 'midcom:urlname']
        ]);

        $resolver->setNormalizer('type_config', function (Options $options, $value) {
            $type_defaults = [
                'allow_catenate' => false,
                'allow_unclean' => false,
                'title_field' => 'title'
            ];
            return helper::resolve_options($type_defaults, $value);
        });
        $resolver->setNormalizer('constraints', function (Options $options, $value) {
            $validator_options = $options['type_config'];
            $validator_options['storage'] = $options['storage'];
            $validator_options['property'] = $options['dm2_storage'];
            $value[] = new validator($validator_options);
            return $value;
        });
    }
}
