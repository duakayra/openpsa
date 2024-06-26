<?php
/**
 * @copyright CONTENT CONTROL GmbH, http://www.contentcontrol-berlin.de
 */

namespace midcom\datamanager\extension\transformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Experimental json transformer
 */
class jsonTransformer implements DataTransformerInterface
{
    public function reverseTransform(mixed $input) : mixed
    {
        return (array) json_decode($input);
    }

    public function transform(mixed $array) : mixed
    {
        return json_encode((array) $array);
    }
}
