<?php

namespace Webkul\GraphQLAPI\Queries\Admin\Catalog\Products;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\BookingProduct\Models\BookingProduct;
use Webkul\BookingProduct\Repositories\BookingProductRepository;
use Webkul\BookingProduct\Repositories\BookingProductTableSlotRepository;
use Webkul\BookingProduct\Repositories\BookingRepository;
use Webkul\Customer\Repositories\WishlistRepository;
use Webkul\GraphQLAPI\Queries\BaseFilter;
use Webkul\Product\Helpers\ConfigurableOption as ProductConfigurableHelper;
use Webkul\Product\Helpers\Review as ProductReviewHelper;
use Webkul\Product\Helpers\View as ProductViewHelper;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\BookingProduct\Helpers\TableSlot as TableSlotHelper;

class ProductContent extends BaseFilter
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected WishlistRepository $wishlistRepository,
        protected ProductViewHelper $productViewHelper,
        protected ProductReviewHelper $productReviewHelper,
        protected ProductConfigurableHelper $productConfigurableHelper,
        protected BookingProductRepository $bookingProductRepository,
        protected TableSlotHelper $tableSlotHelper,
        protected BookingProductTableSlotRepository $bookingProductTableSlotRepository,
        protected BookingRepository $bookingRepository
    ) {}

    /**
     * Get product details.
     *
     * @return array
     */
    public function getAdditionalData($product)
    {
        return $this->productViewHelper->getAdditionalData($product);
    }

    /**
     * Get product price html.
     *
     * @return array
     */
    public function getProductPriceHtml($product)
    {
        $productType = $product->getTypeInstance();

        $priceArray = [
            'id'                         => $product->id,
            'type'                       => $product->type,
            'priceHtml'                  => $productType->getPriceHtml(),
            'priceWithoutHtml'           => strip_tags($productType->getPriceHtml()),
            'minPrice'                   => core()->formatPrice($productType->getMinimalPrice()),
            'regularPrice'               => '',
            'formattedRegularPrice'      => '',
            'finalPrice'                 => '',
            'formattedFinalPrice'        => '',
            'currencyCode'               => core()->getCurrentCurrency()->code,
        ];

        $regularPrice = $productType->getProductPrices();

        switch ($product->type) {
            case 'simple':
            case 'virtual':
            case 'downloadable':
            case 'grouped':

                $priceArray['finalPrice'] = $regularPrice['final']['price'];
                $priceArray['formattedFinalPrice'] = $regularPrice['final']['formatted_price'];
                $priceArray['regularPrice'] = $regularPrice['regular']['price'];
                $priceArray['formattedRegularPrice'] = $regularPrice['regular']['formatted_price'];

                break;
            case 'configurable':

                $priceArray['regularPrice'] = $regularPrice['regular']['price'];
                $priceArray['formattedRegularPrice'] = $regularPrice['regular']['formatted_price'];

                break;

            case 'bundle':
                $priceArray['finalPrice'] = '';
                $priceArray['formattedFinalPrice'] = '';
                $priceArray['regularPrice'] = '';
                $priceArray['formattedRegularPrice'] = '';

                /**
                 * Not in use.
                 */
                $priceArray['regularWithoutCurrencyCode'] = $priceArray['specialWithoutCurrencyCode'] = '';

                if ($regularPrice['from']['regular']['price'] != $regularPrice['from']['final']['price']) {
                    $priceArray['finalPrice'] = $regularPrice['from']['final']['price'];
                    $priceArray['formattedFinalPrice'] = $regularPrice['from']['final']['formatted_price'];
                    $priceArray['regularPrice'] = $regularPrice['from']['regular']['price'];
                    $priceArray['formattedRegularPrice'] = $regularPrice['from']['regular']['formatted_price'];
                } else {
                    $priceArray['regularPrice'] .= $regularPrice['from']['regular']['price'];
                    $priceArray['formattedRegularPrice'] .= $regularPrice['from']['regular']['formatted_price'];
                }

                if ($regularPrice['from']['regular']['price'] != $regularPrice['to']['regular']['price']
                    || $regularPrice['from']['final']['price'] != $regularPrice['to']['final']['price']
                ) {
                    $priceArray['regularPrice'] .= ' To ';
                    $priceArray['formattedRegularPrice'] .= ' To ';
                    $priceArray['finalPrice'] .= ' To ';
                    $priceArray['formattedFinalPrice'] .= ' To ';

                    if ($regularPrice['to']['regular']['price'] != $regularPrice['to']['final']['price']) {
                        $priceArray['finalPrice'] .= $regularPrice['to']['final']['price'];
                        $priceArray['formattedFinalPrice'] .= $regularPrice['to']['final']['formatted_price'];
                        $priceArray['regularPrice'] .= $regularPrice['to']['regular']['price'];
                        $priceArray['formattedRegularPrice'] .= $regularPrice['to']['regular']['formatted_price'];
                    } else {
                        $priceArray['regularPrice'] .= $regularPrice['to']['regular']['price'];
                        $priceArray['formattedRegularPrice'] .= $regularPrice['to']['regular']['formatted_price'];
                    }
                }
                break;
        }

        return $priceArray;
    }

    /**
     * Get bundle type product price.
     *
     * @return array
     */
    public function getBundleProductPrice($data)
    {
        $product = app(ProductRepository::class)->find($data['id']);

        $priceArray = [
            'finalPriceFrom'            => '',
            'formattedFinalPriceFrom'   => '',
            'regularPriceFrom'          => '',
            'formattedRegularPriceFrom' => '',
            'finalPriceTo'              => '',
            'formattedFinalPriceTo'     => '',
            'regularPriceTo'            => '',
            'formattedRegularPriceTo'   => '',
        ];

        $regularPrice = $product->getTypeInstance()->getProductPrices();

        if ($product->type == 'bundle') {
            $priceArray['finalPriceFrom'] = $regularPrice['from']['final']['price'];
            $priceArray['formattedFinalPriceFrom'] = $regularPrice['from']['final']['formatted_price'];
            $priceArray['regularPriceFrom'] = $regularPrice['from']['regular']['price'];
            $priceArray['formattedRegularPriceFrom'] = $regularPrice['from']['regular']['formatted_price'];
            $priceArray['finalPriceTo'] = $regularPrice['to']['final']['price'];
            $priceArray['formattedFinalPriceTo'] = $regularPrice['to']['final']['formatted_price'];
            $priceArray['regularPriceTo'] = $regularPrice['to']['regular']['price'];
            $priceArray['formattedRegularPriceTo'] = $regularPrice['to']['regular']['formatted_price'];
        }

        return $priceArray;
    }

    /**
     * Check product is in wishlist.
     */
    public function checkIsInWishlist($product): bool
    {
        if (! auth()->guard('api')->check()) {
            return false;
        }

        return (bool) $this->wishlistRepository->where([
            'customer_id' => auth()->guard('api')->user()->id,
            'product_id'  => $product->id,
        ])->count();
    }

    /**
     * Check product is in sale
     *
     * @return bool
     */
    public function checkIsInSale($product)
    {
        $productTypeInstance = $product->getTypeInstance();

        if ($productTypeInstance->haveDiscount()) {
            return true;
        }

        return false;
    }

    /**
     * Check product is in stock
     */
    public function checkIsSaleable($product): bool
    {
        return $product->getTypeInstance()->isSaleable();
    }

    /**
     * Get configurable data.
     *
     * @return mixed
     */
    public function getConfigurableData($product)
    {
        $data = $this->productConfigurableHelper->getConfigurationConfig($product);

        $index = [];

        foreach ($data['index'] as $key => $attributeOptionsIds) {
            if (! isset($index[$key])) {
                $index[$key] = [
                    'id'                 => $key,
                    'attributeOptionIds' => [],
                ];
            }

            foreach ($attributeOptionsIds as $attributeId => $optionId) {
                if ($optionId) {
                    $optionData = [
                        'attributeId'       => $attributeId,
                        'attributeCode'     => '',
                        'attributeOptionId' => $optionId,
                    ];

                    foreach ($data['attributes'] as $attribute) {
                        if ($attribute['id'] == $attributeId) {
                            $optionData['attributeCode'] = $attribute['code'];
                            break;
                        }
                    }

                    $index[$key]['attributeOptionIds'][] = $optionData;
                }
            }
        }

        $data['index'] = $index;

        $variantPrices = [];

        foreach ($data['variant_prices'] as $key => $prices) {
            $variantPrices[$key] = [
                'id'            => $key,
                'regular'       => $prices['regular'],
                'final'         => $prices['final'],
            ];
        }

        $data['variant_prices'] = $variantPrices;

        $variantImages = [];

        foreach ($data['variant_images'] as $key => $imgs) {
            $variantImages[$key] = [
                'id'     => $key,
                'images' => [],
            ];

            foreach ($imgs as $img_index => $urls) {
                $variantImages[$key]['images'][$img_index] = $urls;
            }
        }

        $data['variant_images'] = $variantImages;

        $variantVideos = [];

        foreach ($data['variant_videos'] as $key => $imgs) {
            $variantVideos[$key] = [
                'id'     => $key,
                'videos' => [],
            ];

            foreach ($imgs as $img_index => $urls) {
                $variantVideos[$key]['videos'][$img_index] = $urls;
            }
        }

        $data['variant_videos'] = $variantVideos;

        return $data;
    }

    /**
     * Get cached gallery images.
     *
     * @return mixed
     */
    public function getCacheGalleryImages($product)
    {
        return product_image()->getGalleryImages($product);
    }

    /**
     * Get product's review list.
     *
     * @return mixed
     */
    public function getReviews($product)
    {
        return $product->reviews->where('status', 'approved');
    }

    /**
     * Get product base image.
     *
     * @return mixed
     */
    public function getProductBaseImage($product)
    {
        themes()->set('default');

        return product_image()->getProductBaseImage($product);
    }

    /**
     * Get product avarage rating.
     *
     * @return string
     */
    public function getAverageRating($product)
    {
        return $this->productReviewHelper->getAverageRating($product);
    }

    /**
     * Get product percentage rating.
     *
     * @return array
     */
    public function getPercentageRating($product)
    {
        return $this->productReviewHelper->getPercentageRating($product);
    }

    /**
     * Get product share URL.
     *
     * @return string|null
     */
    public function getProductShareUrl($product)
    {
        return route('shop.product_or_category.index', $product->url_key);
    }

    /**
     * Get product booking product.
     *
     * @return mixed
     */
    public function getBookingProduct($product): mixed
    {
        if (method_exists($product, 'booking_product')) {
            return $product->booking_product;
        }

        return null;
    }

    public function getFormattedTableSlots($product): mixed
    {
        //Log::channel('api')->info('ProductContent getFormattedTableSlots', ['data' => $limit]);
        $bookingProduct = $this->bookingProductRepository->find($product->booking_product_id);

        if (empty($bookingProduct)) {
            return json_encode([
                'data' => [],
            ]);
        }

        $bookingProductSlot = $this->bookingProductTableSlotRepository->findOneByField('booking_product_id', $bookingProduct->id);

        if (empty($bookingProductSlot->slots)) {
            return json_encode([
                'data' => [],
            ]);
        }

        $requestQuery = request()->input('query');
        preg_match('/\bkey: "slot_date",\s*value: "([\d-]+)"/', $requestQuery, $matches);
        $tempDate = $matches[1] ?? now()->format('Y-m-d');
        $requestedDate = Carbon::createFromTimeString($tempDate . ' 00:00:00');

        $availableFrom = ! $bookingProduct->available_every_week && $bookingProduct->available_from
            ? Carbon::createFromTimeString($bookingProduct->available_from)
            : Carbon::now()->copy()->startOfDay();

        $availableTo = ! $bookingProduct->available_every_week && $bookingProduct->available_from
            ? Carbon::createFromTimeString($bookingProduct->available_to)
            : Carbon::createFromTimeString('2080-01-01 00:00:00');

        $timeDurations = $bookingProductSlot->same_slot_all_days
            ? $bookingProductSlot->slots
            : ($bookingProductSlot->slots[$requestedDate->format('w')] ?? []);

        if (
            $requestedDate < $availableFrom
            || $requestedDate > $availableTo
        ) {
            return json_encode([
                'data' => [],
            ]);
        }

        $slots = [];

        foreach ($timeDurations as $index => $timeDuration) {
            $fromChunks = explode(':', $timeDuration['from']);
            $toChunks = explode(':', $timeDuration['to']);

            $startDayTime = Carbon::createFromTimeString($requestedDate->format('Y-m-d').' 00:00:00')
                ->addMinutes($fromChunks[0] * 60 + $fromChunks[1]);

            $tempStartDayTime = clone $startDayTime;

            $endDayTime = Carbon::createFromTimeString($requestedDate->format('Y-m-d').' 00:00:00')
                ->addMinutes($toChunks[0] * 60 + $toChunks[1]);

            $isFirstIteration = true;

            while (1) {
                $from = clone $tempStartDayTime;

                $tempStartDayTime->addMinutes($bookingProductSlot->duration);

                if ($isFirstIteration) {
                    $isFirstIteration = false;
                } else {
                    $from->modify('+'.$bookingProductSlot->break_time.' minutes');
                    $tempStartDayTime->modify('+'.$bookingProductSlot->break_time.' minutes');
                }

                $to = clone $tempStartDayTime;

                if (
                    $startDayTime <= $from
                    && $from <= $availableTo
                    && $availableTo >= $to
                    && $to >= $startDayTime
                    && $startDayTime <= $from
                    && $from <= $endDayTime
                    && $endDayTime >= $to
                    && $to >= $startDayTime
                ) {
                    if (Carbon::now() <= $from) {
                        $result = $this->bookingRepository->getModel()
                            ->leftJoin('order_items', 'bookings.order_item_id', '=', 'order_items.id')
                            ->addSelect(DB::raw('SUM(qty_ordered - qty_canceled - qty_refunded) as total_qty_booked'))
                            ->where('bookings.product_id', $bookingProduct->product_id)
                            ->where('bookings.from', $from->getTimestamp())
                            ->where('bookings.to', $to->getTimestamp())
                            ->where(DB::raw("JSON_EXTRACT(order_items.additional, '$.booking.slot')"), $from->getTimestamp().'-'.$to->getTimestamp())
                            ->first();

                        $slots[] = [
                            'from'      => $from->format('h:i A'),
                            'to'        => $to->format('h:i A'),
                            'timestamp' => $from->getTimestamp().'-'.$to->getTimestamp(),
                            'booked'    => $result->total_qty_booked ?? 0,
                        ];
                    }
                } else {
                    break;
                }
            }
        }

        return json_encode([
            'data' => $slots,
        ]);
    }
}
