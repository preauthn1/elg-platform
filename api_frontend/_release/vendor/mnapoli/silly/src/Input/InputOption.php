<?php

namespace Silly\Input;

/**
 * Extending InputOption because...
 *
 * @author Matthieu Napoli <user@example.invalid>
 */
class InputOption extends \Symfony\Component\Console\Input\InputOption
{
    private $description;

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getDescription()
    {
        return $this->description ?: parent::getDescription();
    }
}
