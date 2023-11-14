<?php

namespace SalesRender\Plugin\Core\Integration\Factories;

use Slim\App;

class WebAppFactory extends \SalesRender\Plugin\Core\Factories\WebAppFactory
{
    public function build(): App
    {
        return parent::build();
    }
}