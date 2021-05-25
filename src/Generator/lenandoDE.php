<?php

namespace ElasticExportlenandoDE\Generator;
use ElasticExport\Helper\ElasticExportPriceHelper;
use ElasticExport\Helper\ElasticExportStockHelper;
use ElasticExportlenandoDE\Helper\MarketHelper;
use ElasticExportlenandoDE\Helper\PropertyHelper;
use ElasticExportlenandoDE\Helper\StockHelper;
use Plenty\Modules\DataExchange\Contracts\CSVPluginGenerator;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\DataExchange\Models\FormatSetting;
use ElasticExport\Helper\ElasticExportCoreHelper;
use ElasticExport\Helper\ElasticExportItemHelper;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\ItemCrossSelling\Contracts\ItemCrossSellingRepositoryContract;
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchScrollRepositoryContract;
// NEU
use Plenty\Modules\Market\Helper\Contracts\MarketPropertyHelperRepositoryContract;
// NEU
use Plenty\Plugin\Log\Loggable;
/**
 * Class lenandoDE
 * @package ElasticExportlenandoDE\Generator
 */
class lenandoDE extends CSVPluginGenerator
{
    use Loggable;
	
	
	const RAKUTEN_DE = 106.00;
    const PROPERTY_TYPE_ENERGY_CLASS       = 'energy_efficiency_class';
    const PROPERTY_TYPE_ENERGY_CLASS_GROUP = 'energy_efficiency_class_group';
    const PROPERTY_TYPE_ENERGY_CLASS_UNTIL = 'energy_efficiency_class_until';
	
	
	const CHARACTER_TYPE_ENERGY_EFFICIENCY_CLASS	= 'energy_efficiency_class';
	
    const LENANDO_DE = 116.00;
    const DELIMITER = ";";
	
    const STATUS_VISIBLE = 1;
    const STATUS_LOCKED = 1;
    const STATUS_HIDDEN = 1;
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
	
	// NEU
	/**
     * MarketPropertyHelperRepositoryContract $marketPropertyHelperRepository
     */
    private $marketPropertyHelperRepository;
    
    // NEU
    /**
     * @var ItemCrossSellingRepositoryContract
     */
    private $itemCrossSellingRepository;
    
	/**
	 * @var ElasticExportItemHelper
	 */
	private $elasticExportItemHelper;
	
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
     * lenandoDE constructor.
     *
     * @param ArrayHelper $arrayHelper
     * @param PropertyHelper $propertyHelper
     */
    public function __construct(
        ArrayHelper $arrayHelper,
        // NEU
        MarketPropertyHelperRepositoryContract $marketPropertyHelperRepository,
        // NEU
        PropertyHelper $propertyHelper,
        StockHelper $stockHelper,
        MarketHelper $marketHelper,
        ItemCrossSellingRepositoryContract $itemCrossSellingRepository
    )
    {
        $this->arrayHelper = $arrayHelper;
        // NEU
        $this->marketPropertyHelperRepository = $marketPropertyHelperRepository;
        // NEU
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
        $this->elasticExportItemHelper = pluginApp(ElasticExportItemHelper::class, [1 => true]);
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
                $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.writtenLines', [
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
                $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.esDuration', [
                    'Elastic Search duration' => microtime(true) - $esStartTime,
                ]);
                if(count($resultList['error']) > 0)
                {
                    $this->getLogger(__METHOD__)->error('ElasticExportlenandoDE::log.occurredElasticSearchErrors', [
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
                            $this->getLogger(__METHOD__)->info('ElasticExportlenandoDE::log.variationNotPartOfExportStock', [
                                'VariationId' => (string)$variation['id']
                            ]);
                            continue;
                        }
                        
                        // Skip the variations that do not have attributes, print just the main variation in that case
                        $attributes = $this->getAttributeNameValueCombination($variation, $settings);
                        if(strlen($attributes) <= 0 && $variation['variation']['isMain'] === false)
                        {
                            $this->getLogger(__METHOD__)->info('ElasticExportBilligerDE::log.variationNoAttributesError', [
                                'VariationId' => (string)$variation['id']
                            ]);
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
                           
                            $this->buildRow($variation, $settings, $attributes);
                            
                            
                        }
                        catch(\Throwable $throwable)
                        {
                            $this->getLogger(__METHOD__)->error('ElasticExportlenandoDE::logs.fillRowError', [
                                'Error message ' => $throwable->getMessage(),
                                'Error line'     => $throwable->getLine(),
                                'VariationId'    => (string)$variation['id']
                            ]);
                        }
                        // New line was added
                        $limit++;
                    }
                    $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.buildRowsDuration', [
                        'Build rows duration' => microtime(true) - $buildRowsStartTime,
                    ]);
                }
            } while ($elasticSearch->hasNext());
        }
        $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.fileGenerationDuration', [
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
            'Produktname',
				'Artikelnummer',
				'ean',
				'Hersteller',
				'Steuersatz',
				'Preis',
				'Kurzbeschreibung',
				'Beschreibung',
				'Versandkosten',
				'Lagerbestand',
				'Kategoriestruktur',
				'Attribute',
				'Gewicht',
				'Lieferzeit',
				'Nachnahmegebühr',
				'MPN',
				'Bildlink',
				'Bildlink2',
				'Bildlink3',
				'Bildlink4',
				'Bildlink5',
				'Bildlink6',
				'Zustand',
				'Familienname1',
				'Eigenschaft1',
				'Familienname2',
				'Eigenschaft2',
				'ID',
				'Einheit',
				'Inhalt',
				'Freifeld1',
				'Freifeld2',
				'Freifeld3',
				'Freifeld4',
				'Freifeld5',
				'Freifeld6',
				'Freifeld7',
				'Freifeld8',
				'Freifeld9',
				'Freifeld10',
				'baseid',
				'basename',
				'level',
				'status',
				'external_categories',
				'base',
				'dealer_price',
				'link',
				'ASIN',
				'Mindestabnahme',
				'Maximalabnahme',
				'Abnahmestaffelung',
				'Energieefiizienz',
				'Energieefiizienzbild',
				'UVP',
				'EVP',
				'Grundpreis',
				'Freifeld11',
				'Freifeld12',
				'Freifeld13',
				'Freifeld14',
				'Freifeld15',
				'Freifeld16',
				'Freifeld17',
				'Freifeld18',
				'Freifeld19',
				'Freifeld20',
        );
    }
    /**
     * Creates the variation row and prints it into the CSV file.
     *
     * @param array $variation
     * @param KeyValue $settings
     */
    private function buildRow($variation, KeyValue $settings, $attributes)
    {
        $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.variationConstructRow', [
            'Data row duration' => 'Row printing start'
        ]);
        $rowTime = microtime(true);
        // Get the price list
        $priceList = $this->elasticExportPriceHelper->getPriceList($variation, $settings);
        // Only variations with the Retail Price greater than zero will be handled
        if(!is_null($priceList['price']) && $priceList['price'] > 0 && $this->stockHelper->getStock($variation) > 0)
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
            $imageList = $this->elasticExportHelper->getImageListInOrder($variation, $settings, 6, 'variationImages');
            // Get the flag for the store special
            $flag = $this->getStoreSpecialFlag($variation);
            
            
            
            
            $attributenliste = (strlen($attributes) ? ' | ' . $attributes : '');
            $attributenliste = substr("$attributenliste", 3);
            
            if($attributenliste!=str_replace("Zustand:","",$attributenliste)){
            	$attribut_teil1 = explode("Zustand:", $attributenliste);
				$attribut_teil2 = explode(" | ", $attribut_teil1[1]);
				$zustand = $attribut_teil2[0];
            	
            }elseif($attributenliste!=str_replace("zustand:","",$attributenliste)){
            	$attribut_teil1 = explode("zustand:", $attributenliste);
				$attribut_teil2 = explode(" | ", $attribut_teil1[1]);
				$zustand = $attribut_teil2[0];
            	
            }else{
            	
            	if((int)$variation['data']['item']['conditionApi']['id'] == '0'){
            		$zustand = 'NEU';
            	}elseif((int)$variation['data']['item']['conditionApi']['id'] == '1'){
            		$zustand = 'Gebraucht wie neu';
            	}elseif((int)$variation['data']['item']['conditionApi']['id'] == '2'){
            		$zustand = 'Gebraucht sehr gut';
    		}elseif((int)$variation['data']['item']['conditionApi']['id'] == '3'){
            		$zustand = 'Gebraucht gut';            	
		}elseif((int)$variation['data']['item']['conditionApi']['id'] == '4'){
            		$zustand = 'Gebraucht annehmbar';  
		}elseif((int)$variation['data']['item']['conditionApi']['id'] == '5'){
            		$zustand = 'B-Ware';    
            	}else{
            		$zustand = 'NEU';
            	}
            	
            
            	
            }
            
            
            
            $effizienzklasse = $this->getItemPropertyByExternalComponent($variation, self::RAKUTEN_DE, self::PROPERTY_TYPE_ENERGY_CLASS);
            $effizienzklasse = str_replace("1", "A+++",$effizienzklasse);
            $effizienzklasse = str_replace("2", "A++",$effizienzklasse);
            $effizienzklasse = str_replace("3", "A+",$effizienzklasse);
            $effizienzklasse = str_replace("4", "A",$effizienzklasse);
            $effizienzklasse = str_replace("5", "B",$effizienzklasse);
            $effizienzklasse = str_replace("6", "C",$effizienzklasse);
            $effizienzklasse = str_replace("7", "D",$effizienzklasse);
            $effizienzklasse = str_replace("8", "E",$effizienzklasse);
            $effizienzklasse = str_replace("9", "F",$effizienzklasse);
            $effizienzklasse = str_replace("10", "G",$effizienzklasse);
		
		
		
	$priceList = $this->elasticExportPriceHelper->getPriceList($variation, $settings, 2, '.');
        $basePriceData = $this->elasticExportPriceHelper->getBasePriceDetails($variation, (float) $priceList['price'], $settings->get('lang'));
       
		
		
		if($this->getUnit($basePriceData['unitLongName']) !== ''){
		
			$unitName = $this->getUnit($basePriceData['unitLongName']);
			$unitContent = number_format((float)$variation['data']['unit']['content'],3,',','');
			
		}else{
		
			$unitName = '';
			$unitContent = '';
			
		}
     
            
            $data = [
            		'Produktname'			=> $this->elasticExportHelper->getMutatedName($variation, $settings),
			'Artikelnummer'			=> $variation['data']['variation']['number'],
			'ean'				=> $this->elasticExportHelper->getBarcodeByType($variation, $settings->get('barcode')),
			'Hersteller'			=> $manufacturer,
			'Steuersatz'			=> $priceList['vatValue'],
			'Preis'				=> $priceList['price'],
			'Kurzbeschreibung'		=> $this->elasticExportHelper->getMutatedPreviewText($variation, $settings),
			'Beschreibung'			=> $this->elasticExportHelper->getMutatedDescription($variation, $settings) . ' ' . $this->propertyHelper->getPropertyListDescription($variation, $settings->get('lang')),
			'Versandkosten'			=> $shippingCost,
			'Lagerbestand'			=> $this->stockHelper->getStock($variation),
			'Kategoriestruktur'		=> $this->elasticExportHelper->getCategory((int)$variation['data']['defaultCategories'][0]['id'], (string)$settings->get('lang'), (int)$settings->get('plentyId')),
			'Attribute'			=> '',
			'Gewicht'			=> $variation['data']['variation']['weightG'],
			'Lieferzeit'			=> $this->elasticExportHelper->getAvailability($variation, $settings),
			'Nachnahmegebühr'		=> '',
			'MPN'				=> $variation['data']['variation']['model'],
			'Bildlink'			=> count($imageList) > 0 && array_key_exists(0, $imageList) ? $imageList[0] : '',
			'Bildlink2'			=> count($imageList) > 0 && array_key_exists(1, $imageList) ? $imageList[1] : '',
			'Bildlink3'			=> count($imageList) > 0 && array_key_exists(2, $imageList) ? $imageList[2] : '',
			'Bildlink4'			=> count($imageList) > 0 && array_key_exists(3, $imageList) ? $imageList[3] : '',
			'Bildlink5'			=> count($imageList) > 0 && array_key_exists(4, $imageList) ? $imageList[4] : '',
			'Bildlink6'			=> count($imageList) > 0 && array_key_exists(5, $imageList) ? $imageList[5] : '',
			'Zustand'			=> $zustand,
			'Familienname1'			=> '',
			'Eigenschaft1'			=> '',
			'Familienname2'			=> '',
			'Eigenschaft2'			=> '',
			'ID'				=> $variation['id'],
			'Einheit'			=> $unitName,
			'Inhalt'			=> $unitContent,
			'Freifeld1'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 1),
			'Freifeld2'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 2),
			'Freifeld3'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 3),
			'Freifeld4'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 4),
			'Freifeld5'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 5),
			'Freifeld6'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 6),
			'Freifeld7'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 7),
			'Freifeld8'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 8),
			'Freifeld9'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 9),
			'Freifeld10'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 10),
			'baseid'			=> 'BASE-'.$variation['data']['item']['id'],
			'basename'			=> $attributenliste,
			'level'				=> '0',
			'status'			=> $this->getStatus($variation),
			'external_categories'		=> '',
			'base'				=> '3',
			'dealer_price'			=> '',
			'link'				=> '',
			'ASIN'				=> '',
			'Mindestabnahme'		=> '',
			'Maximalabnahme'		=> '',
			'Abnahmestaffelung'		=> '',
			'Energieefiizienz'		=> $effizienzklasse,
			'Energieefiizienzbild'		=> '',
			'UVP'				=> $priceList['recommendedRetailPrice'],
			'EVP'				=> '',
		    	'Grundpreis'			=> '',
		    	'Freifeld11'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 11),
		    	'Freifeld12'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 12),
		    	'Freifeld13'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 13),
		    	'Freifeld14'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 14),
		    	'Freifeld15'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 15),
		    	'Freifeld16'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 16),
		    	'Freifeld17'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 17),
		    	'Freifeld18'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 18),
		    	'Freifeld19'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 19),
		    	'Freifeld20'			=> $this->elasticExportItemHelper->getFreeFields($variation['data']['item']['id'], 20),
            ];
            $this->addCSVContent(array_values($data));
            $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.variationConstructRowFinished', [
                'Data row duration' => 'Row printing took: ' . (microtime(true) - $rowTime),
            ]);
        }
        else
        {
            $this->getLogger(__METHOD__)->info('ElasticExportlenandoDE::log.variationNotPartOfExportPrice', [
                'VariationId' => (string)$variation['id']
            ]);
        }
    }
    
    
    
    
    
	
    
    // NEU
    
    
    /**
     * Get item characters that match referrer from settings and a given component id.
     * @param  array    $item
     * @param  float    $marketId
     * @param  string   $externalComponent
     * @return string
     */
    private function getItemPropertyByExternalComponent($variation ,float $marketId, $externalComponent):string
    {
        $marketProperties = $this->marketPropertyHelperRepository->getMarketProperty($marketId);
        if(is_array($variation['data']['properties']) && count($variation['data']['properties']) > 0)
        {
            foreach($variation['data']['properties'] as $property)
            {
                foreach($marketProperties as $marketProperty)
                {
                    if(array_key_exists('id', $property['property']))
                    {
                        if(is_array($marketProperty) && count($marketProperty) > 0 && $marketProperty['character_item_id'] == $property['property']['id'])
                        {
                            if (strlen($externalComponent) > 0 && strpos($marketProperty['external_component'], $externalComponent) !== false)
                            {
                                $list = explode(':', $marketProperty['external_component']);
                                if (isset($list[1]) && strlen($list[1]) > 0)
                                {
                                    return $list[1];
                                }
                            }
                        }
                    }
                }
            }
        }
        return '';
    }
    
    // NEU
    
    
    
    
    
/**
     * Returns the unitname sometimes in short Text
     *
     * @param  array   $item
     * @return string
     */
    private function getUnit($unitname):string
    {
        
	switch($unitname)
        {
			case 'Kilogramm':
				return 'kg';
			case 'Gramm':
				return 'g';
			case 'Milligramm':
				return 'mg';
			case 'Liter':
				return 'l';
			case 'Meter':
				return 'm';
			case 'Milliliter':
				return 'ml';
			case 'Millimeter':
				return 'mm';
			case 'Quadratmeter':
				return 'm²';
			case 'Quadratzentimeter':
				return 'cm²';
			case 'Quadratmillimeter':
				return 'mm²';
			case 'Quadratzentimeter':
				return 'cm²';
			case 'Quadratmillimeter':
				return 'mm²';
			case 'Zentimeter':
				return 'cm';
			default:
				return $unitname;
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
     * Get attribute and name value combination for a variation.
     *
     * @param $variation
     * @param KeyValue $settings
     * @return string
     */
    private function getAttributeNameValueCombination($variation, KeyValue $settings):string
    {
        $attributes = '';
        
        $attributeName = $this->elasticExportHelper->getAttributeName($variation, $settings, ' | ');
        
        $attributeValue = $this->elasticExportHelper->getAttributeValueSetShortFrontendName($variation, $settings, ' | ');
        
        
        if(strlen($attributeName) && strlen($attributeValue))
        {
            $attributes = $this->getAttributeNameAndValueCombinations($attributeName, $attributeValue);
        }
        return $attributes;
    }
    
    
    
    
/**
	 * Returns the attribute name and value combination by delimiter.
	 *
     * @param string $attributeNames
     * @param string $attributeValues
     * @param string $delimiter
     * @return string
     */
    public function getAttributeNameAndValueCombinations(string $attributeNames, string $attributeValues, string $delimiter = ' | '):string
    {
        $attributes = '';
        $attributeNameList = array();
        $attributeValueList = array();
        if (strlen($attributeNames) && strlen($attributeValues))
        {
            $attributeNameList = explode(' | ', $attributeNames);
            $attributeValueList = explode(' | ', $attributeValues);
        }
        if (count($attributeNameList) && count($attributeValueList))
        {
            foreach ($attributeNameList as $index => $attributeName)
            {
                if ($index == 0)
                {
                    $attributes .= $attributeNameList[$index]. ':' . $attributeValueList[$index];
                }
                else
                {
                    $attributes .= $delimiter. '' . $attributeNameList[$index]. ':' . $attributeValueList[$index];
                }
            }
        }
        return $attributes;
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
