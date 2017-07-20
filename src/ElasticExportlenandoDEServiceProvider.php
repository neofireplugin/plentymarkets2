<?php



namespace ElasticExportlenandoDE;



use Plenty\Modules\DataExchange\Services\ExportPresetContainer;

use Plenty\Plugin\DataExchangeServiceProvider;



/**

 * Class ElasticExportlenandoDEServiceProvider

 * @package ElasticExportlenandoDE

 */

class ElasticExportlenandoDEServiceProvider extends DataExchangeServiceProvider

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

            'lenandoDE-Plugin',

            'ElasticExportlenandoDE\ResultField\lenandoDE',

            'ElasticExportlenandoDE\Generator\lenandoDE',

            '',

            true,

            true

        );

    }

}
