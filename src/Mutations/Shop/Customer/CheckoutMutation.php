<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\CartRule\Repositories\CartRuleCouponRepository;
use Webkul\Checkout\Facades\Cart;
use Webkul\Core\Rules\PhoneNumber;
use Webkul\Customer\Repositories\CustomerAddressRepository;
use Webkul\GraphQLAPI\Repositories\NotificationRepository;
use Webkul\GraphQLAPI\Validators\CustomException;
use Webkul\Payment\Facades\Payment;
use Webkul\Paypal\Payment\SmartButton;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\Shipping\Facades\Shipping;

class CheckoutMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CartRuleCouponRepository $cartRuleCouponRepository,
        protected CustomerAddressRepository $customerAddressRepository,
        protected OrderRepository $orderRepository,
        protected NotificationRepository $notificationRepository,
        protected SmartButton $smartButton,
    ) {
        Auth::setDefaultDriver('api');
    }

    /**
     * Returns a customer's addresses detail.
     *
     * @return array
     *
     * @throws CustomException
     */
    public function addresses(mixed $rootValue, array $args, GraphQLContext $context)
    {
        try {
            $cart = Cart::getCart();

            //$customer = bagisto_graphql()->authorize();

            return [
                'is_guest'         => is_null($cart->customer_id),
                'billing_address'  => $cart->billing_address ? $cart->billing_address->toArray() : null,
                'shipping_address' => $cart->shipping_address ? $cart->shipping_address->toArray() : null,
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * Store a newly created resource in storage and return shipping methods.
     *
     * @return array
     *
     * @throws CustomException
     */
    public function saveCartAddresses(mixed $rootValue, array $args, GraphQLContext $context)
    {
        $rules = [];

        $rules = array_merge($rules, $this->mergeAddressRules('billing'));

        if (
            empty($args['billing']['use_for_shipping'])
            && Cart::getCart()->haveStockableItems()
        ) {
            $rules = array_merge($rules, $this->mergeAddressRules('shipping'));
        }

        bagisto_graphql()->validate($args, $rules);

        if (
            ! auth()->guard('api')->check()
            && ! Cart::getCart()?->hasGuestCheckoutItems()
        ) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.addresses.guest-address-warning'));
        }

        if (Cart::hasError()) {
            throw new CustomException(current(Cart::getErrors()));
        }

        if (auth()->guard('api')->check()) {
            $args = array_merge($args, [
                'billing' => array_merge($args['billing'], [
                    'customer_id' => auth()->guard('api')->user()->id,
                ]),
            ]);

            if (
                empty($args['billing']['use_for_shipping'])
                && Cart::getCart()->haveStockableItems()
            ) {
                $args = array_merge($args, [
                    'shipping' => array_merge($args['shipping'], [
                        'customer_id' => auth()->guard('api')->user()->id,
                    ]),
                ]);
            }

            if (! empty($args['billing']['save_address'])) {
                $this->customerAddressRepository->create(array_merge($args['billing'], [
                    'address' => implode(PHP_EOL, $args['billing']['address']),
                ]));
            }
        }

        Cart::saveAddresses($args);

        $cart = Cart::getCart();

        Cart::collectTotals();

        if ($cart->haveStockableItems()) {
            if (! $rates = Shipping::collectRates()) {
                throw new CustomException(trans('bagisto_graphql::app.shop.checkout.something-wrong'));
            }

            $shipping_methods = [];

            foreach ($rates['shippingMethods'] as $shippingMethod) {
                $methods = [];

                foreach ($shippingMethod['rates'] as $rate) {
                    $methods = [
                        'code'                 => $rate->method,
                        'label'                => $rate->method_title,
                        'price'                => $rate->price,
                        'formatted_price'      => core()->formatPrice($rate->price),
                        'base_price'           => $rate->base_price,
                        'formatted_base_price' => core()->formatBasePrice($rate->base_price),
                    ];
                }

                $shipping_methods[] = [
                    'title'   => $shippingMethod['carrier_title'],
                    'methods' => $methods,
                ];
            }

            return [
                'message'          => trans('bagisto_graphql::app.shop.checkout.addresses.address-save-success'),
                'cart'             => Cart::getCart(),
                'shipping_methods' => $shipping_methods,
                'jump_to_section'  => 'shipping',
            ];
        }

        return [
            'message'         => trans('bagisto_graphql::app.shop.checkout.addresses.address-save-success'),
            'cart'            => Cart::getCart(),
            'payment_methods' => Payment::getPaymentMethods(),
            'jump_to_section' => 'payment',
        ];
    }

    /**
     * Merge new address rules.
     */
    private function mergeAddressRules(string $addressType): array
    {
        return [
            "{$addressType}.company_name" => ['nullable'],
            "{$addressType}.first_name"   => ['required'],
            "{$addressType}.last_name"    => ['required'],
            "{$addressType}.email"        => ['required'],
            "{$addressType}.address"      => ['required', 'array', 'min:1'],
            "{$addressType}.city"         => ['required'],
            "{$addressType}.country"      => ['required'],
            "{$addressType}.state"        => ['required'],
            "{$addressType}.postcode"     => ['required', 'numeric'],
            "{$addressType}.phone"        => ['required', new PhoneNumber],
        ];
    }

    /**
     * get shipping methods based on the cart address.
     *
     * @return array
     *
     * @throws CustomException
     */
    public function shippingMethods(mixed $rootValue, array $args, GraphQLContext $context)
    {
        $cart = Cart::getCart();

        if (! $cart) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.empty-cart'));
        }

        if (
            ! auth()->guard('api')->check()
            && ! Cart::getCart()->hasGuestCheckoutItems()
        ) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.invalid-guest-user'));
        }

        if (empty($cart->shipping_address->id)) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.no-address-found'));
        }

        Cart::collectTotals();

        if ($cart->haveStockableItems()) {
            if (! $rates = Shipping::collectRates()) {
                throw new CustomException(trans('bagisto_graphql::app.shop.checkout.shipping.method-not-found'));
            }

            $shipping_methods = [];

            foreach ($rates['shippingMethods'] as $shippingMethod) {
                $methods = [];

                foreach ($shippingMethod['rates'] as $rate) {
                    $methods = [
                        'code'                 => $rate->method,
                        'label'                => $rate->method_title,
                        'price'                => $rate->price,
                        'formatted_price'      => core()->formatPrice($rate->price),
                        'base_price'           => $rate->base_price,
                        'formatted_base_price' => core()->formatBasePrice($rate->base_price),
                    ];
                }

                $shipping_methods[] = [
                    'title'   => $shippingMethod['carrier_title'],
                    'methods' => $methods,
                ];
            }

            return [
                'message'          => trans('bagisto_graphql::app.shop.checkout.shipping.method-fetched'),
                'cart'             => Cart::getCart(),
                'shipping_methods' => $shipping_methods,
                'jump_to_section'  => 'shipping',
            ];
        } else {
            return [
                'message'         => trans('bagisto_graphql::app.shop.checkout.payment.method-fetched'),
                'cart'            => Cart::getCart(),
                'payment_methods' => Payment::getPaymentMethods(),
                'jump_to_section' => 'payment',
            ];
        }
    }

    /**
     * Save Payment Method
     *
     * @return array
     *
     * @throws CustomException
     */
    public function saveShipping(mixed $rootValue, array $args, GraphQLContext $context)
    {
        bagisto_graphql()->validate($args, [
            'method' => 'required',
        ]);

        try {
            if (
                Cart::hasError()
                || ! Cart::saveShippingMethod($args['method'])
            ) {
                throw new CustomException(trans('bagisto_graphql::app.shop.checkout.shipping.save-failed'));
            }

            Cart::collectTotals();

            return [
                'message'         => trans('bagisto_graphql::app.shop.checkout.shipping.save-success'),
                'cart'            => Cart::getCart(),
                'jump_to_section' => 'payment',
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * get the available payment methods and save the shipping for the current cart.
     *
     * @return array
     *
     * @throws CustomException
     */
    public function paymentMethods(mixed $rootValue, array $args, GraphQLContext $context)
    {
        bagisto_graphql()->validate($args, [
            'shipping_method' => 'nullable|string',
        ]);

        try {
            if (
                Cart::hasError()
//                || ! Cart::saveShippingMethod($args['shipping_method'])
            ) {
                throw new CustomException(trans('bagisto_graphql::app.shop.checkout.payment.method-not-found'));
            }

            Cart::collectTotals();

            return [
                'message'         => trans('bagisto_graphql::app.shop.checkout.payment.method-fetched'),
                'cart'            => Cart::getCart(),
                'payment_methods' => Payment::getPaymentMethods(),
                'jump_to_section' => 'payment',
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * Save Payment Method
     *
     * @return array
     *
     * @throws CustomException
     */
    public function savePayment(mixed $rootValue, array $args, GraphQLContext $context)
    {
        bagisto_graphql()->validate($args, [
            'method' => 'required|in:'.implode(',', collect(Payment::getPaymentMethods())->pluck('method')->toArray()),
        ]);

        try {
            if (
                Cart::hasError()
                || ! Cart::savePaymentMethod($args)
            ) {
                throw new CustomException(trans('bagisto_graphql::app.shop.checkout.payment.save-failed'));
            }

            Cart::collectTotals();

            return [
                'message'         => trans('bagisto_graphql::app.shop.checkout.payment.save-success'),
                'cart'            => Cart::getCart(),
                'jump_to_section' => 'review',
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * Apply Coupon to cart
     *
     * @return array
     *
     * @throws CustomException
     */
    public function applyCoupon(mixed $rootValue, array $args, GraphQLContext $context)
    {
        bagisto_graphql()->validate($args, [
            'code' => 'string|required',
        ]);

        try {
            if (strlen($args['code'])) {
                $coupon = $this->cartRuleCouponRepository->findOneByField('code', $args['code']);

                if (! $coupon) {
                    return [
                        'success' => false,
                        'message' => trans('bagisto_graphql::app.shop.checkout.coupon.invalid-code'),
                        'cart'    => Cart::getCart(),
                    ];
                }

                if ($coupon->cart_rule->status) {
                    if (Cart::getCart()->coupon_code == $args['code']) {
                        return [
                            'success' => true,
                            'message' => trans('bagisto_graphql::app.shop.checkout.coupon.already-applied'),
                            'cart'    => Cart::getCart(),
                        ];
                    }

                    Cart::setCouponCode($args['code'])->collectTotals();

                    if (Cart::getCart()->coupon_code == $args['code']) {
                        return [
                            'success' => true,
                            'message' => trans('bagisto_graphql::app.shop.checkout.coupon.apply-success'),
                            'cart'    => Cart::getCart(),
                        ];
                    }
                }
            }

            return [
                'success' => false,
                'message' => trans('bagisto_graphql::app.shop.checkout.coupon.invalid-code'),
                'cart'    => Cart::getCart(),
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * Remove Coupon from cart
     *
     * @return array
     *
     * @throws CustomException
     */
    public function removeCoupon(mixed $rootValue, array $args, GraphQLContext $context)
    {
        try {
            if (Cart::getCart()->coupon_code) {
                Cart::removeCouponCode()->collectTotals();

                return [
                    'success' => true,
                    'message' => trans('bagisto_graphql::app.shop.checkout.coupon.remove-success'),
                    'cart'    => Cart::getCart(),
                ];
            }

            return [
                'success' => false,
                'message' => trans('bagisto_graphql::app.shop.checkout.couponremove-failed'),
                'cart'    => Cart::getCart(),
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * Save Order
     *
     * @return array
     *
     * @throws CustomException
     */
    public function saveOrder(mixed $rootValue, array $args, GraphQLContext $context)
    {
        try {
            if (Cart::hasError()) {
                throw new CustomException(trans('bagisto_graphql::app.shop.checkout.error-placing-order'));
            }

            Cart::collectTotals();

            $this->validateOrder();

            $cart = Cart::getCart();

            $redirectUrl = $cart->payment->method === 'paypal_smart_button' ?? null;

            if ($redirectUrl) {
                $requestBody = $this->buildRequestBody();
                $paypalRedirectUrl = Payment::createPaypalOrder($cart, $requestBody);

                return [
                    'success'         => true,
                    'redirect_url'    => $paypalRedirectUrl,
                    'selected_method' => 'paypal_smart_button',
                ];
            }

            $data = (new OrderResource($cart))->jsonSerialize();

            $order = $this->orderRepository->create($data);

            if (core()->getConfigData('general.api.pushnotification.private_key')) {
                $this->prepareNotificationContent($order);
            }

            Cart::deActivateCart();

            return [
                'success'      => true,
                'redirect_url' => null,
                'order'        => $order,
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * Handle approved paypal order.
     *
     * @return array
     */
    public function paypalApproved(mixed $rootValue, array $args, GraphQLContext $context)
    {
        try {
            if (Cart::hasError()) {
                throw new CustomException(trans('bagisto_graphql::app.shop.checkout.error-placing-order'));
            }

            Cart::collectTotals();

            $this->validateOrder();

            $cart = Cart::getCart();

            \Log::channel('custom')->info('code', [$args['code']]);

            // Throws error if goes wrong
            Payment::checkPaypalOrder($cart, $args['code']);

            $data = (new OrderResource($cart))->jsonSerialize();

            $order = $this->orderRepository->create($data);

            if (core()->getConfigData('general.api.pushnotification.private_key')) {
                $this->prepareNotificationContent($order);
            }

            Cart::deActivateCart();

            return [
                'success'      => true,
                'order'        => $order,
            ];
        } catch (\Exception $e) {
            throw new CustomException($e->getMessage());
        }
    }

    /**
     * Validate order before creation
     *
     * @return void|CustomException
     */
    public function validateOrder()
    {
        $cart = Cart::getCart();

        if (
            $cart->haveStockableItems()
            && ! $cart->shipping_address
        ) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.missing-shipping-address'));
        }

        if (! $cart->billing_address) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.missing-billing-address'));
        }

        if (
            $cart->haveStockableItems()
            && ! $cart->selected_shipping_rate
        ) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.missing-shipping-method'));
        }

        if (! $cart->payment) {
            throw new CustomException(trans('bagisto_graphql::app.shop.checkout.missing-payment-method'));
        }
    }

    /**
     * Prepare data for order push notification.
     *
     * @param  \Webkul\Sales\Contracts\Order  $order
     * @return mixed
     */
    public function prepareNotificationContent($order)
    {
        $data = [
            'title'       => 'New Order Placed',
            'body'        => 'Order ('.$order->id.') placed by '.$order->customerFullName.' successfully.',
            'message'     => 'Order ('.$order->id.') placed by '.$order->customerFullName.' successfully.',
            'sound'       => 'default',
            'orderStatus' => $order->parcel_status,
            'orderId'     => (string) $order->id,
            'type'        => 'order',
        ];

        $notification = [
            'title'   => $data['title'],
            'content' => $data['body'],
        ];

        $this->notificationRepository->sendNotification($data, $notification);
    }

    /**
     * Build request body.
     *
     * @return array
     */
    private function buildRequestBody()
    {
        $cart = Cart::getCart();

        $billingAddressLines = $this->getAddressLines($cart->billing_address->address);

        $data = [
            'intent' => 'CAPTURE',

            'payer'  => [
                'name' => [
                    'given_name' => $cart->billing_address->first_name,
                    'surname'    => $cart->billing_address->last_name,
                ],

                'address' => [
                    'address_line_1' => current($billingAddressLines),
                    'address_line_2' => last($billingAddressLines),
                    'admin_area_2'   => $cart->billing_address->city,
                    'admin_area_1'   => $cart->billing_address->state,
                    'postal_code'    => $cart->billing_address->postcode,
                    'country_code'   => $cart->billing_address->country,
                ],

                'email_address' => $cart->billing_address->email,
            ],

            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => config('app.url_front') . '/checkout-success',
                'cancel_url' => config('app.url_front') . '/checkout-cancel',
            ],

            'purchase_units' => [
                [
                    'amount'   => [
                        'value'         => $this->smartButton->formatCurrencyValue((float) $cart->sub_total + $cart->tax_total + ($cart->selected_shipping_rate ? $cart->selected_shipping_rate->price : 0) - $cart->discount_amount),
                        'currency_code' => $cart->cart_currency_code,

                        'breakdown'     => [
                            'item_total' => [
                                'currency_code' => $cart->cart_currency_code,
                                'value'         => $this->smartButton->formatCurrencyValue((float) $cart->sub_total),
                            ],

                            'shipping'   => [
                                'currency_code' => $cart->cart_currency_code,
                                'value'         => $this->smartButton->formatCurrencyValue((float) ($cart->selected_shipping_rate ? $cart->selected_shipping_rate->price : 0)),
                            ],

                            'tax_total'  => [
                                'currency_code' => $cart->cart_currency_code,
                                'value'         => $this->smartButton->formatCurrencyValue((float) $cart->tax_total),
                            ],

                            'discount'   => [
                                'currency_code' => $cart->cart_currency_code,
                                'value'         => $this->smartButton->formatCurrencyValue((float) $cart->discount_amount),
                            ],
                        ],
                    ],

                    'items'    => $this->getLineItems($cart),
                ],
            ],
        ];

        if (! empty($cart->billing_address->phone)) {
            $data['payer']['phone'] = [
                'phone_type'   => 'MOBILE',

                'phone_number' => [
                    'national_number' => $this->smartButton->formatPhone($cart->billing_address->phone),
                ],
            ];
        }

        if (
            $cart->haveStockableItems()
            && $cart->shipping_address
        ) {
            $data['application_context']['shipping_preference'] = 'SET_PROVIDED_ADDRESS';

            $data['purchase_units'][0] = array_merge($data['purchase_units'][0], [
                'shipping' => [
                    'address' => [
                        'address_line_1' => current($billingAddressLines),
                        'address_line_2' => last($billingAddressLines),
                        'admin_area_2'   => $cart->shipping_address->city,
                        'admin_area_1'   => $cart->shipping_address->state,
                        'postal_code'    => $cart->shipping_address->postcode,
                        'country_code'   => $cart->shipping_address->country,
                    ],
                ],
            ]);
        }

        return $data;
    }

    /**
     * Return convert multiple address lines into 2 address lines.
     *
     * @param  string  $address
     * @return array
     */
    private function getAddressLines($address)
    {
        $address = explode(PHP_EOL, $address, 2);

        $addressLines = [current($address)];

        if (isset($address[1])) {
            $addressLines[] = str_replace(["\r\n", "\r", "\n"], ' ', last($address));
        } else {
            $addressLines[] = '';
        }

        return $addressLines;
    }

    /**
     * Return cart items.
     *
     * @param  string  $cart
     * @return array
     */
    private function getLineItems($cart)
    {
        $lineItems = [];

        foreach ($cart->items as $item) {
            $lineItems[] = [
                'unit_amount' => [
                    'currency_code' => $cart->cart_currency_code,
                    'value'         => $this->smartButton->formatCurrencyValue((float) $item->price),
                ],
                'quantity'    => $item->quantity,
                'name'        => $item->name,
                'sku'         => $item->sku,
                'category'    => $item->getTypeInstance()->isStockable() ? 'PHYSICAL_GOODS' : 'DIGITAL_GOODS',
            ];
        }

        return $lineItems;
    }
}
