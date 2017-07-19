<?php

namespace ElasticExportKaufluxDE\Generator;

use ElasticExport\Helper\ElasticExportPriceHelper;
use ElasticExport\Helper\ElasticExportStockHelper;
use ElasticExportKaufluxDE\Helper\MarketHelper;
use ElasticExportKaufluxDE\Helper\PropertyHelper;
use ElasticExportKaufluxDE\Helper\StockHelper;
use Plenty\Modules\DataExchange\Contracts\CSVPluginGenerator;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\DataExchange\Models\FormatSetting;
use ElasticExport\Helper\ElasticExportCoreHelper;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\ItemCrossSelling\Contracts\ItemCrossSellingRepositoryContract;
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchScrollRepositoryContract;
use Plenty\Plugin\Log\Loggable;

/**
 * Class KaufluxDE
 * @package ElasticExportKaufluxDE\Generator
 */
class KaufluxDE extends CSVPluginGenerator
{
    use Loggable;

    const KAUFLUX_DE = 116.00;

    const DELIMITER = ";";

    const STATUS_VISIBLE = 0;
    const STATUS_LOCKED = 1;
    const STATUS_HIDDEN = 2;

    /**
     * @var ElasticExportCoreHelper $elasticExportHelper
     */
    private $elasticExportHelper;

    /**
     * @var ElasticExportStockHelper
     */
    private $elasticExportStockHelper;

    /**
     * @var ElasticExportPriceHelper
     */
    private $elasticExportPriceHelper;

    /**
     * @var ItemCrossSellingRepositoryContract
     */
    private $itemCrossSellingRepository;

    /**
     * @var ArrayHelper
     */
    private $arrayHelper;

    /**
     * @var PropertyHelper
     */
    private $propertyHelper;

    /**
     * @var StockHelper
     */
    private $stockHelper;

    /**
     * @var MarketHelper
     */
    private $marketHelper;

    /**
     * @var array
     */
    private $shippingCostCache;

    /**
     * @var array
     */
    private $manufacturerCache;

    /**
     * @var array
     */
    private $itemCrossSellingListCache;

    /**
     * @var array
     */
    private $addedItems = [];

    /**
     * @var array
     */
    private $flags = [
        0 => '',
        1 => 'Sonderangebot',
        2 => 'Neuheit',
        3 => 'Top Artikel',
    ];

    /**
     * KaufluxDE constructor.
     *
     * @param ArrayHelper $arrayHelper
     * @param PropertyHelper $propertyHelper
     */
    public function __construct(
        ArrayHelper $arrayHelper,
        PropertyHelper $propertyHelper,
        StockHelper $stockHelper,
        MarketHelper $marketHelper,
        ItemCrossSellingRepositoryContract $itemCrossSellingRepository
    )
    {
        $this->arrayHelper = $arrayHelper;
        $this->propertyHelper = $propertyHelper;
        $this->stockHelper = $stockHelper;
        $this->marketHelper = $marketHelper;
        $this->itemCrossSellingRepository = $itemCrossSellingRepository;
    }

    /**
     * Generates and populates the data into the CSV file.
     *
     * @param VariationElasticSearchScrollRepositoryContract $elasticSearch
     * @param array $formatSettings
     * @param array $filter
     */
    protected function generatePluginContent($elasticSearch, array $formatSettings = [], array $filter = [])
    {
        $this->elasticExportHelper = pluginApp(ElasticExportCoreHelper::class);

        $this->elasticExportStockHelper = pluginApp(ElasticExportStockHelper::class);

        $this->elasticExportPriceHelper = pluginApp(ElasticExportPriceHelper::class);

        $settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');

        $this->setDelimiter(self::DELIMITER);

        $this->addCSVContent($this->head());

        $startTime = microtime(true);

        if($elasticSearch instanceof VariationElasticSearchScrollRepositoryContract)
        {
            // Initiate the counter for the variations limit
            $limitReached = false;
            $limit = 0;

            do
            {
                $this->getLogger(__METHOD__)->debug('ElasticExportKaufluxDE::log.writtenLines', [
                    'Lines written' => $limit,
                ]);

                // Stop writing if limit is reached
                if($limitReached === true)
                {
                    break;
                }

                $esStartTime = microtime(true);

                // Get the data from Elastic Search
                $resultList = $elasticSearch->execute();

                $this->getLogger(__METHOD__)->debug('ElasticExportKaufluxDE::log.esDuration', [
                    'Elastic Search duration' => microtime(true) - $esStartTime,
                ]);

                if(count($resultList['error']) > 0)
                {
                    $this->getLogger(__METHOD__)->error('ElasticExportKaufluxDE::log.occurredElasticSearchErrors', [
                        'Error message' => $resultList['error'],
                    ]);
                }

                $buildRowsStartTime = microtime(true);

                if(is_array($resultList['documents']) && count($resultList['documents']) > 0)
                {
                    $previousItemId = null;

                    foreach ($resultList['documents'] as $variation)
                    {
                        // Stop and set the flag if limit is reached
                        if($limit == $filter['limit'])
                        {
                            $limitReached = true;
                            break;
                        }

                        // If filtered by stock is set and stock is negative, then skip the variation
                        if($this->elasticExportStockHelper->isFilteredByStock($variation, $filter) === true)
                        {
                            $this->getLogger(__METHOD__)->info('ElasticExportKaufluxDE::log.variationNotPartOfExportStock', [
                                'VariationId' => (string)$variation['id']
                            ]);

                            continue;
                        }

                        // If is not valid, then skip the variation
                        if(!$this->stockHelper->isValid($variation))
                        {
                            continue;
                        }

                        try
                        {
                            // Set the caches if we have the first variation or when we have the first variation of an item
                            if($previousItemId === null || $previousItemId != $variation['data']['item']['id'])
                            {
                                $previousItemId = $variation['data']['item']['id'];
                                unset($this->shippingCostCache, $this->itemCrossSellingListCache);

                                // Build the caches arrays
                                $this->buildCaches($variation, $settings);
                            }

                            // Build the new row for printing in the CSV file
                            $this->buildRow($variation, $settings);
                        }
                        catch(\Throwable $throwable)
                        {
                            $this->getLogger(__METHOD__)->error('ElasticExportKaufluxDE::logs.fillRowError', [
                                'Error message ' => $throwable->getMessage(),
                                'Error line'     => $throwable->getLine(),
                                'VariationId'    => (string)$variation['id']
                            ]);
                        }

                        // New line was added
                        $limit++;
                    }

                    $this->getLogger(__METHOD__)->debug('ElasticExportKaufluxDE::log.buildRowsDuration', [
                        'Build rows duration' => microtime(true) - $buildRowsStartTime,
                    ]);
                }

            } while ($elasticSearch->hasNext());
        }

        $this->getLogger(__METHOD__)->debug('ElasticExportKaufluxDE::log.fileGenerationDuration', [
            'Whole file generation duration' => microtime(true) - $startTime,
        ]);
    }

    /**
     * Creates the header of the CSV file.
     *
     * @return array
     */
    private function head():array
    {
        return array(
            'GroupID',
            'BestellNr',
            'EAN',
            'Hersteller',
            'BestandModus',
            'BestandAbsolut',
            'Liefertyp',
            'VersandKlasse',
            'Lieferzeit',
            'Umtausch',
            'Bezeichnung',
            'KurzText',
            'DetailText',
            'Keywords',
            'Bild1',
            'Bild2',
            'Bild3',
            'Gewicht',
            'Preis',
            'MwSt',
            'UVP',
            'Katalog1',
            'Flags',
            'LinkXS',
            'ExtLinkDetail',
            'Status',
            'FreeVar1',
            'FreeVar2',
            'FreeVar3',
            'InhaltMenge',
            'InhaltEinheit',
            'InhaltVergleich',
            'HerstellerArtNr',
        );
    }

    /**
     * Creates the variation row and prints it into the CSV file.
     *
     * @param array $variation
     * @param KeyValue $settings
     */
    private function buildRow($variation, KeyValue $settings)
    {
        $this->getLogger(__METHOD__)->debug('ElasticExportKaufluxDE::log.variationConstructRow', [
            'Data row duration' => 'Row printing start'
        ]);

        $rowTime = microtime(true);

        // Get the price list
        $priceList = $this->elasticExportPriceHelper->getPriceList($variation, $settings);

        // Only variations with the Retail Price greater than zero will be handled
        if(!is_null($priceList['price']) && $priceList['price'] > 0)
        {
            // Get shipping cost
            $shippingCost = $this->getShippingCost($variation);

            // Get the manufacturer
            $manufacturer = $this->getManufacturer($variation);

            // Get the cross sold items
            $itemCrossSellingList = $this->getItemCrossSellingList($variation);

            // Get base price information list
            $basePriceList = $this->elasticExportHelper->getBasePriceList($variation, (float)$priceList['price'], $settings->get('lang'));

            // Get image list in the specified order
            $imageList = $this->elasticExportHelper->getImageListInOrder($variation, $settings, 3, 'variationImages');

            // Get the flag for the store special
            $flag = $this->getStoreSpecialFlag($variation);

            $data = [
                'GroupID' 			=> $variation['data']['item']['id'],
                'BestellNr' 		=> $this->elasticExportHelper->generateSku($variation['id'], self::KAUFLUX_DE, 0, $variation['data']['skus'][0]['sku']),
                'EAN' 				=> $this->elasticExportHelper->getBarcodeByType($variation, $settings->get('barcode')),
                'Hersteller' 		=> $manufacturer,
                'BestandModus' 		=> $this->marketHelper->getConfigValue('stockCondition'),
                'BestandAbsolut' 	=> $this->stockHelper->getStock($variation),
                'Liefertyp' 		=> 'V',
                'VersandKlasse' 	=> $shippingCost,
                'Lieferzeit' 		=> $this->elasticExportHelper->getAvailability($variation, $settings, false),
                'Umtausch' 			=> $this->marketHelper->getConfigValue('returnDays'),
                'Bezeichnung' 		=> $this->elasticExportHelper->getMutatedName($variation, $settings), //. ' ' . $variation->variationBase->variationName, todo maybe add the attribute value name
                'KurzText' 			=> $this->elasticExportHelper->getMutatedPreviewText($variation, $settings),
                'DetailText' 		=> $this->elasticExportHelper->getMutatedDescription($variation, $settings) . ' ' . $this->propertyHelper->getPropertyListDescription($variation, $settings->get('lang')),
                'Keywords' 			=> $variation['data']['texts']['keywords'],
                'Bild1' 			=> count($imageList) > 0 && array_key_exists(0, $imageList) ? $imageList[0] : '',
                'Bild2' 			=> count($imageList) > 0 && array_key_exists(1, $imageList) ? $imageList[1] : '',
                'Bild3' 			=> count($imageList) > 0 && array_key_exists(2, $imageList) ? $imageList[2] : '',
                'Gewicht' 			=> $variation['data']['variation']['weightG'],
                'Preis' 			=> $priceList['price'],
                'MwSt' 				=> $priceList['vatValue'],
                'UVP' 				=> $priceList['recommendedRetailPrice'],
                'Katalog1' 			=> $this->elasticExportHelper->getCategoryMarketplace((int)$variation['data']['defaultCategories'][0]['id'], (int)$settings->get('plentyId'), (int)self::KAUFLUX_DE),
                'Flags' 			=> $flag,
                'LinkXS' 			=> $itemCrossSellingList,
                'ExtLinkDetail' 	=> $this->elasticExportHelper->getMutatedUrl($variation, $settings),
                'Status' 			=> $this->getStatus($variation),
                'FreeVar1' 			=> $variation['data']['item']['free1'],
                'FreeVar2' 			=> $variation['data']['item']['free2'],
                'FreeVar3' 			=> $variation['data']['item']['free3'],
                'InhaltMenge' 		=> $basePriceList['lot'],
                'InhaltEinheit' 	=> $basePriceList['unit'], //TODO use Kauflux measurements
                'InhaltVergleich' 	=> '',
                'HerstellerArtNr' 	=> $variation['data']['variation']['model'],
            ];

            $this->addCSVContent(array_values($data));

            $this->getLogger(__METHOD__)->debug('ElasticExportKaufluxDE::log.variationConstructRowFinished', [
                'Data row duration' => 'Row printing took: ' . (microtime(true) - $rowTime),
            ]);
        }
        else
        {
            $this->getLogger(__METHOD__)->info('ElasticExportKaufluxDE::log.variationNotPartOfExportPrice', [
                'VariationId' => (string)$variation['id']
            ]);
        }
    }

    /**
     * Get the item value for the store special flag.
     *
     * @param $variation
     * @return string
     */
    private function getStoreSpecialFlag($variation):string
    {
        if(!is_null($variation['data']['item']['storeSpecial']) && !is_null($variation['data']['item']['storeSpecial']['id']) && array_key_exists($variation['data']['item']['storeSpecial']['id'], $this->flags))
        {
            return $this->flags[$variation['data']['item']['storeSpecial']['id']];
        }

        return '';
    }

    /**
     * Get status.
     *
     * @param  array $variation
     * @return int
     */
    private function getStatus($variation):int
    {
        if(!array_key_exists($variation['data']['item']['id'], $this->addedItems))
        {
            $this->addedItems[$variation['data']['item']['id']] = $variation['data']['item']['id'];

            return self::STATUS_VISIBLE;
        }

        return self::STATUS_HIDDEN;
    }

    /**
     * Create the ids list of cross sold items.
     *
     * @param array $variation
     * @return string
     */
    private function createItemCrossSellingList($variation):string
    {
        $list = [];

        $itemCrossSellingList = $this->itemCrossSellingRepository->findByItemId($variation['data']['item']['id']);

        foreach($itemCrossSellingList as $itemCrossSelling)
        {
            $list[] = (string) $itemCrossSelling->crossItemId;
        }

        return implode(', ', $list);
    }

    /**
     * Get the ids list of cross sold items.
     *
     * @param $variation
     * @return string
     */
    private function getItemCrossSellingList($variation):string
    {
        if(isset($this->itemCrossSellingListCache) && array_key_exists($variation['data']['item']['id'], $this->itemCrossSellingListCache))
        {
            return $this->itemCrossSellingListCache[$variation['data']['item']['id']];
        }

        return '';
    }

    /**
     * Get the shipping cost.
     *
     * @param $variation
     * @return string
     */
    private function getShippingCost($variation):string
    {
        $shippingCost = null;
        if(isset($this->shippingCostCache) && array_key_exists($variation['data']['item']['id'], $this->shippingCostCache))
        {
            $shippingCost = $this->shippingCostCache[$variation['data']['item']['id']];
        }

        if(!is_null($shippingCost) && $shippingCost != '0.00')
        {
            return $shippingCost;
        }

        return '';
    }

    /**
     * Get the manufacturer name.
     *
     * @param $variation
     * @return string
     */
    private function getManufacturer($variation):string
    {
        if(isset($this->manufacturerCache) && array_key_exists($variation['data']['item']['manufacturer']['id'], $this->manufacturerCache))
        {
            return $this->manufacturerCache[$variation['data']['item']['manufacturer']['id']];
        }

        return '';
    }

    /**
     * Build the cache arrays for the item variation.
     *
     * @param $variation
     * @param $settings
     */
    private function buildCaches($variation, $settings)
    {
        if(!is_null($variation) && !is_null($variation['data']['item']['id']))
        {
            $shippingCost = $this->elasticExportHelper->getShippingCost($variation['data']['item']['id'], $settings, 0);
            $this->shippingCostCache[$variation['data']['item']['id']] = number_format((float)$shippingCost, 2, '.', '');

            $itemCrossSellingList = $this->createItemCrossSellingList($variation);
            $this->itemCrossSellingListCache[$variation['data']['item']['id']] = $itemCrossSellingList;

            if(!is_null($variation['data']['item']['manufacturer']['id']))
            {
                if(!isset($this->manufacturerCache) || (isset($this->manufacturerCache) && !array_key_exists($variation['data']['item']['manufacturer']['id'], $this->manufacturerCache)))
                {
                    $manufacturer = $this->elasticExportHelper->getExternalManufacturerName((int)$variation['data']['item']['manufacturer']['id']);
                    $this->manufacturerCache[$variation['data']['item']['manufacturer']['id']] = $manufacturer;
                }
            }
        }
    }
}
