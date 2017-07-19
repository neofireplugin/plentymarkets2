<?php

namespace ElasticExportKaufluxDE;

use Plenty\Modules\DataExchange\Services\ExportPresetContainer;
use Plenty\Plugin\DataExchangeServiceProvider;

/**
 * Class ElasticExportKaufluxDEServiceProvider
 * @package ElasticExportKaufluxDE
 */
class ElasticExportKaufluxDEServiceProvider extends DataExchangeServiceProvider
{
    /**
     * Abstract function for registering the service provider.
     */
    public function register()
    {

    }

    /**
     * Adds the export format to the export container.
     *
     * @param ExportPresetContainer $container
     */
    public function exports(ExportPresetContainer $container)
    {
        $container->add(
            'KaufluxDE-Plugin',
            'ElasticExportKaufluxDE\ResultField\KaufluxDE',
            'ElasticExportKaufluxDE\Generator\KaufluxDE',
            '',
            true,
            true
        );
    }
}