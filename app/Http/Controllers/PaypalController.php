<?php

namespace App\Http\Controllers;

use Srmklive\PayPal\Services\PayPal;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class PaypalController extends Controller
{
    public function payment(Request $request)
    {
        try {

            // Get order ID from session
            $orderId = $request->session()->get('id');

            if (!$orderId) {
                return redirect()->route('checkout')
                    ->with('error', 'Order not found. Please try again.');
            }

            $order = Order::find($orderId);

            if (!$order) {
                return redirect()->route('checkout')
                    ->with('error', 'Order not found. Please try again.');
            }

            // Get cart items
            $cart = Cart::where('user_id', auth()->user()->id)
                ->whereNull('order_id')
                ->get();

            if ($cart->isEmpty()) {
                return redirect()->route('checkout')
                    ->with('error', 'Your cart is empty.');
            }

            // ----------------------------
            // Prepare PayPal Items
            // ----------------------------
            $paypalItems = [];
            $itemTotal = 0;

            foreach ($cart as $item) {
                $product = Product::find($item->product_id);

                $price = (float)$item->price;
                $qty   = (int)$item->quantity;

                $itemTotal += $price * $qty;

                $paypalItems[] = [
                    'name' => $product ? $product->title : 'Product',
                    'description' => 'Thank you for your purchase',
                    'quantity' => (string)$qty,
                    'unit_amount' => [
                        'currency_code' => config('paypal.currency', 'USD'),
                        'value' => number_format($price, 2, '.', '')
                    ]
                ];
            }

            // ----------------------------
            // Shipping
            // ----------------------------
            $shipping = 0;
            if ($order->shipping_id && $order->shipping) {
                $shipping = (float)$order->shipping->price;
            }

            // ----------------------------
            // Coupon Discount
            // ----------------------------
            $discount = 0;
            if (session('coupon')) {
                $discount = (float)session('coupon')['value'];
            }

            // ----------------------------
            // Final Total
            // ----------------------------
            $grandTotal = $itemTotal + $shipping - $discount;

            if ($grandTotal < 0) {
                $grandTotal = 0;
            }

            // ----------------------------
            // Store order ID for callback
            // ----------------------------
            session()->put('paypal_order_id', $orderId);

            // ----------------------------
            // Initialize PayPal
            // ----------------------------
            $provider = new PayPal();
            $provider->setApiCredentials(config('paypal'));
            $token = $provider->getAccessToken();
            $provider->setAccessToken($token);

            // ----------------------------
            // Create PayPal Order
            // ----------------------------
            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $order->order_number,
                        'description' => "Order #{$order->order_number}",
                        'amount' => [
                            'currency_code' => config('paypal.currency', 'USD'),
                            'value' => number_format($grandTotal, 2, '.', ''),
                            'breakdown' => [
                                'item_total' => [
                                    'currency_code' => config('paypal.currency', 'USD'),
                                    'value' => number_format($itemTotal, 2, '.', '')
                                ],
                                'shipping' => [
                                    'currency_code' => config('paypal.currency', 'USD'),
                                    'value' => number_format($shipping, 2, '.', '')
                                ],
                                'discount' => [
                                    'currency_code' => config('paypal.currency', 'USD'),
                                    'value' => number_format($discount, 2, '.', '')
                                ]
                            ]
                        ],
                        'items' => $paypalItems
                    ]
                ],
                'application_context' => [
                    'return_url' => route('payment.success'),
                    'cancel_url' => route('payment.cancel'),
                    'brand_name' => config('app.name'),
                    'landing_page' => 'BILLING',
                    'user_action' => 'PAY_NOW'
                ]
            ];

            $response = $provider->createOrder($orderData);

            if (isset($response['id']) && isset($response['links'])) {

                $approveUrl = collect($response['links'])
                    ->firstWhere('rel', 'approve');

                if ($approveUrl) {

                    session()->put('paypal_order_token', $response['id']);

                    return redirect($approveUrl['href']);
                }
            }

            // ----------------------------
            // If failed
            // ----------------------------
            Log::error('PayPal Error: ' . json_encode($response));

            if ($order && $order->payment_status == 'unpaid') {
                $order->status = 'cancel';
                $order->save();
            }

            session()->forget('paypal_order_id');
            session()->forget('paypal_order_token');

            return redirect()->route('checkout')
                ->with('error', 'PayPal payment initialization failed. Please try again.');

        } catch (\Exception $e) {

            Log::error('PayPal Payment Error: ' . $e->getMessage());

            $orderId = session()->get('paypal_order_id');

            if ($orderId) {
                $order = Order::find($orderId);
                if ($order && $order->payment_status == 'unpaid') {
                    $order->status = 'cancel';
                    $order->save();
                }
            }

            session()->forget('paypal_order_id');
            session()->forget('paypal_order_token');

            return redirect()->route('checkout')
                ->with('error', 'Something went wrong. Please try again.');
        }
    }
   
    /**
     * Handle PayPal payment cancellation
     *
     * @return \Illuminate\Http\Response
     */
    public function cancel()
    {
        // Get order ID from session
        $orderId = session()->get('paypal_order_id');
        
        if ($orderId) {
            try {
                // Optionally: Delete the unpaid order or mark it as canceled
                $order = Order::find($orderId);
                if ($order && $order->payment_status == 'unpaid') {
                    // Mark order as canceled
                    $order->status = 'cancel';
                    $order->save();
                    // Note: Cart items are not linked yet, so they remain in cart
                }
            } catch (\Exception $e) {
                Log::error('PayPal Cancel Error: ' . $e->getMessage());
            }
            
            // Clear PayPal session data
            session()->forget('paypal_order_id');
            session()->forget('paypal_order_token');
        }
        
        // Cart items remain available since they weren't linked to order
        return redirect()->route('checkout')
            ->with('error', 'Payment was canceled. Your cart items are still available.');
    }
  
    /**
     * Handle successful PayPal payment
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function success(Request $request)
    {
        try {
            if (!$request->has('token')) {
                return redirect()->route('checkout')
                    ->with('error', 'Payment verification failed. Please contact support.');
            }

            $paypalOrderId = $request->input('token');
            
            // Get order ID from session
            $orderId = session()->get('paypal_order_id');
            
            if (!$orderId) {
                return redirect()->route('checkout')
                    ->with('error', 'Order not found. Please contact support.');
            }

            $order = Order::find($orderId);
            if (!$order) {
                return redirect()->route('checkout')
                    ->with('error', 'Order not found. Please contact support.');
            }

            // Initialize PayPal
            $provider = new PayPal();
            $provider->setApiCredentials(config('paypal'));
            $token = $provider->getAccessToken();
            $provider->setAccessToken($token);

            // Capture the payment
            $paymentResponse = $provider->capturePaymentOrder($paypalOrderId);

            if (isset($paymentResponse['status']) && 
                strtoupper($paymentResponse['status']) === 'COMPLETED') {
                
                // Payment successful - NOW link cart items to order
                Cart::where('user_id', auth()->user()->id)
                    ->where('order_id', null)
                    ->update(['order_id' => $order->id]);
                
                // Update order payment status
                $order->payment_status = 'paid';
                $order->save();

                // Send notification to admin
                $admin = \App\Models\User::where('role', 'admin')->first();
                if ($admin) {
                    $details = [
                        'title' => 'New order created',
                        'actionURL' => route('order.show', $order->id),
                        'fas' => 'fa-file-alt'
                    ];
                    // \Notification::send($admin, new \App\Notifications\StatusNotification($details));
                }

                // Clear session data only after successful payment
                session()->forget('cart');
                session()->forget('coupon');
                session()->forget('paypal_order_id');
                session()->forget('paypal_order_token');

                return redirect()->route('home')
                    ->with('success', 'Payment successful! Thank you for your order.');
            } else {
                Log::error('PayPal Payment Processing Error: ' . json_encode($paymentResponse));
                
                // Payment failed - cart items remain in cart (not linked to order)
                // Optionally mark order as canceled
                if ($order) {
                    $order->status = 'cancel';
                    $order->save();
                }
                
                session()->forget('paypal_order_id');
                session()->forget('paypal_order_token');
                
                return redirect()->route('checkout')
                    ->with('error', 'Payment processing failed. Your cart items are still available. Please try again.');
            }
        } catch (\Exception $e) {
            Log::error('PayPal Success Error: ' . $e->getMessage());
            
            // On error, ensure cart items remain available
            $orderId = session()->get('paypal_order_id');
            if ($orderId) {
                try {
                    $order = Order::find($orderId);
                    if ($order && $order->payment_status == 'unpaid') {
                        $order->status = 'cancel';
                        $order->save();
                    }
                } catch (\Exception $ex) {
                    // Ignore
                }
            }
            
            session()->forget('paypal_order_id');
            session()->forget('paypal_order_token');
            
            return redirect()->route('checkout')
                ->with('error', 'Something went wrong. Your cart items are still available. Please contact support if payment was deducted.');
        }
    }
}
