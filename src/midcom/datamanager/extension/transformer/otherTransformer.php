<?php
/**
 * @copyright CONTENT CONTROL GmbH, http://www.contentcontrol-berlin.de
 */

namespace midcom\datamanager\extension\transformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Experimental autocomplete transformer
 */
class otherTransformer implements DataTransformerInterface
{
    private array $choices;

    public function __construct(array $choices)
    {
        $this->choices = $choices;
    }

    public function transform(mixed $input) : mixed
    {
        $other = '';
        foreach ($input as $i => $choice) {
            if (!in_array($choice, $this->choices)) {
                $other = $choice;
                unset($input[$i]);
            }
        }
        return ['select' => $input, 'other' => $other];
    }

    public function reverseTransform(mixed $array) : mixed
    {
        $chosen = $array['select'];
        if (!empty($array['other'])) {
            $chosen[] = $array['other'];
        }
        return $chosen;
    }
}
