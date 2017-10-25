<?php
namespace ElasticExportlenandoDE\Helper;
use Illuminate\Support\Collection;
use Plenty\Modules\StockManagement\Stock\Contracts\StockRepositoryContract;
use Plenty\Modules\StockManagement\Stock\Models\Stock;
use Plenty\Plugin\Log\Loggable;
use Plenty\Repositories\Models\PaginatedResult;
class StockHelper
{
    use Loggable;
    const STOCK_WAREHOUSE_TYPE = 'sales';
    /**
     * @var StockRepositoryContract
     */
    private $stockRepository;
    /**
     * StockHelper constructor.
     *
     * @param StockRepositoryContract $stockRepositoryContract
     */
    public function __construct(StockRepositoryContract $stockRepositoryContract)
    {
        $this->stockRepository = $stockRepositoryContract;
    }
    /**
     * Calculates the stock based depending on different limits.
     *
     * @param  array $variation
     * @return int
     */
    public function getStock($variation):int
    {
        $stock = $stockNet = 0;
        if($this->stockRepository instanceof StockRepositoryContract)
        {
            $this->stockRepository->setFilters(['variationId' => $variation['id']]);
            $stockResult = $this->stockRepository->listStockByWarehouseType(self::STOCK_WAREHOUSE_TYPE, ['stockNet'], 1, 1);
            if($stockResult instanceof PaginatedResult)
            {
                $result = $stockResult->getResult();
                if($result instanceof Collection)
                {
                    foreach($result as $model)
                    {
                        if($model instanceof Stock)
                        {
                            $stockNet = (int)$model->stockNet;
                        }
                    }
                }
            }
        }
        // get stock
        if($variation['data']['variation']['stockLimitation'] == 2)
        {
            $stock = 999;
        }
        elseif($variation['data']['variation']['stockLimitation'] == 1 && $stockNet > 0)
        {
            if($stockNet > 999)
            {
                $stock = 999;
            }
            else
            {
                $stock = $stockNet;
            }
        }
        elseif($variation['data']['variation']['stockLimitation'] == 0)
        {
            if($stockNet > 999)
            {
                $stock = 999;
            }
            else
            {
                if($stockNet > 0)
                {
                    $stock = $stockNet;
                }
                else
                {
                    $stock = 999;
                }
            }
        }
        return $stock;
    }
}
