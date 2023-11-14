<?php

namespace SalesRender\Plugin\Core\Integration\Factories;

use Symfony\Component\Console\Application;

class ConsoleAppFactory extends \SalesRender\Plugin\Core\Factories\ConsoleAppFactory
{
    public function build(): Application
    {
        return parent::build();
    }
}