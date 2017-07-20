<?php



namespace ElasticExportlenandoDE\ResultField;



use Plenty\Modules\Cloud\ElasticSearch\Lib\ElasticSearch;

use Plenty\Modules\DataExchange\Contracts\ResultFields;

use Plenty\Modules\DataExchange\Models\FormatSetting;

use Plenty\Modules\Helper\Services\ArrayHelper;

use Plenty\Modules\Item\Search\Mutators\BarcodeMutator;

use Plenty\Modules\Item\Search\Mutators\ImageMutator;

use Plenty\Modules\Cloud\ElasticSearch\Lib\Source\Mutator\BuiltIn\LanguageMutator;

use Plenty\Modules\Item\Search\Mutators\KeyMutator;

use Plenty\Modules\Item\Search\Mutators\SkuMutator;

use Plenty\Modules\Item\Search\Mutators\DefaultCategoryMutator;



/**

 * Class lenandoDE

 * @package ElasticExportlenandoDE\ResultField

 */

class lenandoDE extends ResultFields

{

    const LENANDO_DE = 116.00;



    /**

     * @var ArrayHelper

     */

    private $arrayHelper;



    /**

     * lenandoDE constructor.

     *

     * @param ArrayHelper $arrayHelper

     */

    public function __construct(ArrayHelper $arrayHelper)

    {

        $this->arrayHelper = $arrayHelper;

    }



    /**

     * Creates the fields set to be retrieved from ElasticSearch.

     *

     * @param array $formatSettings

     * @return array

     */

    public function generateResultFields(array $formatSettings = []):array

    {

        $settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');



        $reference = $settings->get('referrerId') ? $settings->get('referrerId') : self::LENANDO_DE;



        $this->setOrderByList(['item.id', ElasticSearch::SORTING_ORDER_ASC]);



        $itemDescriptionFields = ['texts.urlPath', 'texts.lang', 'texts.keywords'];



        switch($settings->get('nameId'))

        {

            case 1:

                $itemDescriptionFields[] = 'texts.name1';

                break;

            case 2:

                $itemDescriptionFields[] = 'texts.name2';

                break;

            case 3:

                $itemDescriptionFields[] = 'texts.name3';

                break;

            default:

                $itemDescriptionFields[] = 'texts.name1';

                break;

        }



        if($settings->get('descriptionType') == 'itemShortDescription'

            || $settings->get('previewTextType') == 'itemShortDescription')

        {

            $itemDescriptionFields[] = 'texts.shortDescription';

        }



        if($settings->get('descriptionType') == 'itemDescription'

            || $settings->get('descriptionType') == 'itemDescriptionAndTechnicalData'

            || $settings->get('previewTextType') == 'itemDescription'

            || $settings->get('previewTextType') == 'itemDescriptionAndTechnicalData')

        {

            $itemDescriptionFields[] = 'texts.description';

        }

        $itemDescriptionFields[] = 'texts.technicalData';



        //Mutator

        /**

         * @var ImageMutator $imageMutator

         */

        $imageMutator = pluginApp(ImageMutator::class);

        if($imageMutator instanceof ImageMutator)

        {

            $imageMutator->addMarket($reference);

        }



        /**

         * @var KeyMutator $keyMutator

         */

        $keyMutator = pluginApp(KeyMutator::class);

        if($keyMutator instanceof KeyMutator)

        {

            $keyMutator->setKeyList($this->getKeyList());

            $keyMutator->setNestedKeyList($this->getNestedKeyList());

        }



        /**

         * @var LanguageMutator $languageMutator

         */

        $languageMutator = pluginApp(LanguageMutator::class, [[$settings->get('lang')]]);



        /**

         * @var SkuMutator $skuMutator

         */

        $skuMutator = pluginApp(SkuMutator::class);

        if($skuMutator instanceof SkuMutator)

        {

            $skuMutator->setMarket($reference);

        }



        /**

         * @var DefaultCategoryMutator $defaultCategoryMutator

         */

        $defaultCategoryMutator = pluginApp(DefaultCategoryMutator::class);

        if($defaultCategoryMutator instanceof DefaultCategoryMutator)

        {

            $defaultCategoryMutator->setPlentyId($settings->get('plentyId'));

        }



        /**

         * @var BarcodeMutator $barcodeMutator

         */

        $barcodeMutator = pluginApp(BarcodeMutator::class);

        if($barcodeMutator instanceof BarcodeMutator)

        {

            $barcodeMutator->addMarket($reference);

        }



        $fields = [

            [

                //item

                'item.id',

                'item.manufacturer.id',

                'item.free1',

                'item.free2',

                'item.free3',

                'item.free4',

                'item.free5',

                'item.free6',

                'item.free7',

                'item.free8',

                'item.free9',

                'item.free10',

                'item.storeSpecial',



                //variation

                'id',

                'variation.availability.id',

                'variation.stockLimitation',

                'variation.vatId',

                'variation.model',

                'variation.weightG',

                'variation.number',



                //images

                'images.item.urlMiddle',

                'images.item.urlPreview',

                'images.item.urlSecondPreview',

                'images.item.url',

                'images.item.path',

                'images.item.position',



                'images.variation.urlMiddle',

                'images.variation.urlPreview',

                'images.variation.urlSecondPreview',

                'images.variation.url',

                'images.variation.path',

                'images.variation.position',



                //unit

                'unit.content',

                'unit.id',



                //sku

                'skus.sku',



                //defaultCategories

                'defaultCategories.id',

                

                //attributes

                'attributes.attributeValueSetId',

                'attributes.attributeId',

                'attributes.valueId',

                'attributes.names.name',

                'attributes.names.lang',



                //barcodes

                'barcodes.code',

                'barcodes.type',



                //properties

                'properties.property.id',

                'properties.property.valueType',

                'properties.selection.name',

                'properties.selection.lang',

                'properties.texts.value',

                'properties.texts.lang',

                'properties.valueInt',

                'properties.valueFloat',

            ],



            [

                $keyMutator,

                $languageMutator,

                $skuMutator,

                $defaultCategoryMutator,

                $barcodeMutator,

            ],

        ];



        if($reference != -1)

        {

            $fields[1][] = $imageMutator;

        }



        foreach($itemDescriptionFields as $itemDescriptionField)

        {

            $fields[0][] = $itemDescriptionField;

        }



        return $fields;

    }



    /**

     * Returns the list of keys.

     *

     * @return array

     */

    private function getKeyList()

    {

        $keyList = [

            //item

            'item.id',

            'item.manufacturer.id',

            'item.free1',

            'item.free2',

            'item.free3',

            'item.free4',

            'item.free5',

            'item.free6',

            'item.free7',

            'item.free8',

            'item.free9',

            'item.free10',

            'item.storeSpecial',



            //variation

            'variation.availability.id',

            'variation.stockLimitation',

            'variation.vatId',

            'variation.model',

            'variation.weightG',



            //unit

            'unit.content',

            'unit.id',

        ];



        return $keyList;

    }



    /**

     * Returns the list of nested keys.

     *

     * @return mixed

     */

    private function getNestedKeyList()

    {

        $nestedKeyList['keys'] = [

            //images

            'images.item',

            'images.variation',



            //texts

            'texts',



            //defaultCategories

            'defaultCategories',



            //barcodes

            'barcodes',

            

            //attributes

            'attributes',



            //properties

            'properties',

        ];



        $nestedKeyList['nestedKeys'] = [

            //images

            'images.item' => [

                'urlMiddle',

                'urlPreview',

                'urlSecondPreview',

                'url',

                'path',

                'position',

            ],



            'images.variation' => [

                'urlMiddle',

                'urlPreview',

                'urlSecondPreview',

                'url',

                'path',

                'position',

            ],



            //texts

            'texts' => [

                'urlPath',

                'lang',

                'texts.keywords',

                'name1',

                'name2',

                'name3',

                'shortDescription',

                'description',

                'technicalData',

            ],



            //defaultCategories

            'defaultCategories' => [

                'id'

            ],



            //barcodes

            'barcodes' => [

                'code',

                'type',

            ],

            

            //attributes

            'attributes' => [

                'attributeValueSetId',

                'attributeId',

                'valueId',

                'names.name',

                'names.lang',

            ],



            //proprieties

            'properties'    => [

                'property.id',

                'property.valueType',

                'selection.name',

                'selection.lang',

                'texts.value',

                'texts.lang',

                'valueInt',

                'valueFloat',

            ],

        ];



        return $nestedKeyList;

    }

}
