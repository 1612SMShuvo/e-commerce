<?php

namespace App\Http\Controllers\Front;

use App\Classes\GeniusMailer;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Generalsetting;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderTrack;
use App\Models\Pagesetting;
use App\Models\PaymentGateway;
use App\Models\Pickup;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VendorOrder;
use Auth;
use DB;
use Illuminate\Http\Request;
use Session;
use Validator;
use App\Library\SslCommerz\SslCommerzNotification;

class CheckoutController extends Controller
{
    public function loadpayment($slug1,$slug2)
    {
        if (Session::has('currency')) {
            $curr = Currency::find(Session::get('currency'));
        }
        else {
            $curr = Currency::where('is_default', '=', 1)->first();
        }
        $payment = $slug1;
        $pay_id = $slug2;
        $gateway = '';
        if($pay_id != 0) {
            $gateway = PaymentGateway::findOrFail($pay_id);
        }
        return view('load.payment', compact('payment', 'pay_id', 'gateway', 'curr'));
    }

    public function checkout()
    {
        $this->code_image();
        if (!Session::has('cart')) {
            return redirect()->route('front.cart')->with('success', "You don't have any product to checkout.");
        }
        $gs = Generalsetting::findOrFail(1);
        $dp = 1;
        $vendor_shipping_id = 0;
        $vendor_packing_id = 0;
        if (Session::has('currency')) {
            $curr = Currency::find(Session::get('currency'));
        }
        else
            {
            $curr = Currency::where('is_default', '=', 1)->first();
        }

        // If a user is Authenticated then there is no problm user can go for checkout

        if(!Auth::guard('web')->check()) {
            return redirect('/user/login')->with('checkout', 'Please login before checkout.');
        }

        $gateways =  PaymentGateway::where('status', '=', 1)->get();
        $pickups = Pickup::all();
        $oldCart = Session::get('cart');
        $cart = new Cart($oldCart);
        $products = $cart->items;
        $products_totalPrice_cart = round($cart->totalPrice*$curr->value,2);

        $discounts_found = false;
        $discountsList= DB::table('discounts')->where('status', '=', 1)->get()->toArray();
        $discounts_product_id = array();
        $discountProductPrice = array();
        $discountsProductPriceShow = array();
        $total_discount_price = array();
        foreach ($discountsList as $key => $value) {


            if (array_key_exists($value->product_id , $products)) {


                $discountConditionalPrice= $value->conditional_price;
                $discountPrice= $value->discount;
                $discountQty = $value->max_quantity;

               /* print_r($value->product_id);
                exit();*/
               # $discountProductPriceTotal  = ($discountProductPrice);

                $discounts_product_id= $value->product_id;
               # print_r($discounts_product_id);

              # $discountsProduct= DB::table('discounts')->where('product_id', $value->product_id)->where('max_quantity', '<',   $products[$value->product_id]['qty'])->orWhere('max_quantity', $products[$value->product_id]['qty'])->get()->first();


               if($discountConditionalPrice < $products_totalPrice_cart){
                   $productsQty = $products[$value->product_id]['qty'];
                   if($discountQty <= $productsQty){

                       $total_discount_price[] = $discountQty*$discountPrice;
                   }else{
                       $total_discount_price[] = $productsQty*$discountPrice;
                  /*  if($discountQty > $productsQty){
                        $total_discount_price[] = 0;

                    }else{
                        $total_discount_price[] = 0;
                    }*/

                   }



               }

                $discounts_found = true;
            }
        }

        $total_discount_price = (array_sum($total_discount_price));

        session(['total_discount_price' => $total_discount_price]);




      /*  $discountsProduct= DB::table('discounts')->whereIn('product_id', $discounts_product_id)->get();

        foreach ($discountsProduct as $item){
            if($item->)
        }*/


        // Shipping Method

        if($gs->multiple_shipping == 1) {                        
            $user = null;
            foreach ($cart->items as $prod) {
                    $user[] = $prod['item']['user_id'];
            }
            $users = array_unique($user);
            if(count($users) == 1) {

                $shipping_data  = DB::table('shippings')->where('user_id', '=', $users[0])->get();
                if(count($shipping_data) == 0) {
                    $shipping_data  = DB::table('shippings')->where('user_id', '=', 0)->get();
                }
                else{
                    $vendor_shipping_id = $users[0];
                }
            }
            else {
                $shipping_data  = DB::table('shippings')->where('user_id', '=', 0)->get();
            }

        }
        else{
            $shipping_data  = DB::table('shippings')->where('user_id', '=', 0)->get();
        }

        // Packaging

        if($gs->multiple_packaging == 1) {
            $user = null;
            foreach ($cart->items as $prod) {
                    $user[] = $prod['item']['user_id'];
            }
            $users = array_unique($user);
            if(count($users) == 1) {
                $package_data  = DB::table('packages')->where('user_id', '=', $users[0])->get();
                if(count($package_data) == 0) {
                    $package_data  = DB::table('packages')->where('user_id', '=', 0)->get();
                }
                else{
                    $vendor_packing_id = $users[0];
                }
            }
            else {
                $package_data  = DB::table('packages')->where('user_id', '=', 0)->get();
            }

        }
        else{
            $package_data  = DB::table('packages')->where('user_id', '=', 0)->get();
        }


        foreach ($products as $prod) {
            if($prod['item']['type'] == 'Physical') {
                $dp = 0;
                break;
            }
        }
        if($dp == 1) {
            $ship  = 0;                    
        }
                $total = $cart->totalPrice;
                $coupon = Session::has('coupon') ? Session::get('coupon') : 0;
        if($gs->tax != 0) {
            $tax = ($total / 100) * $gs->tax;
            $total = $total + $tax;
        }
        if(!Session::has('coupon_total')) {
            $total = $total - $coupon;     
            $total = $total + 0;               
        }
        else {
            $total = Session::get('coupon_total');  
            $total = $total + round(0 * $curr->value, 2); 
        }
        return view('front.checkout', ['products' => $cart->items, 'totalPrice' => $total, 'pickups' => $pickups, 'totalQty' => $cart->totalQty, 'gateways' => $gateways, 'shipping_cost' => 0, 'digital' => $dp, 'curr' => $curr,'shipping_data' => $shipping_data,'package_data' => $package_data, 'vendor_shipping_id' => $vendor_shipping_id, 'vendor_packing_id' => $vendor_packing_id, 'discounts_found'=>$discounts_found,'total_discount_price' =>$total_discount_price]);
        
        /*else{
            // If guest checkout is activated then user can go for checkout
               if($gs->guest_checkout == 1)
              {
                $gateways =  PaymentGateway::where('status','=',1)->get();
                $pickups = Pickup::all();
                $oldCart = Session::get('cart');
                $cart = new Cart($oldCart);
                $products = $cart->items;

                // Shipping Method

                if($gs->multiple_shipping == 1)
                {
                    $user = null;
                    foreach ($cart->items as $prod) {
                            $user[] = $prod['item']['user_id'];
                    }
                    $users = array_unique($user);
                    if(count($users) == 1)
                    {
                        $shipping_data  = DB::table('shippings')->where('user_id','=',$users[0])->get();

                        if(count($shipping_data) == 0){
                            $shipping_data  = DB::table('shippings')->where('user_id','=',0)->get();
                        }
                        else{
                            $vendor_shipping_id = $users[0];
                        }
                    }
                    else {
                        $shipping_data  = DB::table('shippings')->where('user_id','=',0)->get();
                    }

                }
                else{
                $shipping_data  = DB::table('shippings')->where('user_id','=',0)->get();
                }

                // Packaging

                if($gs->multiple_packaging == 1)
                {
                    $user = null;
                    foreach ($cart->items as $prod) {
                            $user[] = $prod['item']['user_id'];
                    }
                    $users = array_unique($user);
                    if(count($users) == 1)
                    {
                        $package_data  = DB::table('packages')->where('user_id','=',$users[0])->get();

                        if(count($package_data) == 0){
                            $package_data  = DB::table('packages')->where('user_id','=',0)->get();
                        }
                        else{
                            $vendor_packing_id = $users[0];
                        }
                    }
                    else {
                        $package_data  = DB::table('packages')->where('user_id','=',0)->get();
                    }

                }
                else{
                $package_data  = DB::table('packages')->where('user_id','=',0)->get();
                }


                foreach ($products as $prod) {
                    if($prod['item']['type'] == 'Physical')
                    {
                        $dp = 0;
                        break;
                    }
                }
                if($dp == 1)
                {
                $ship  = 0;
                }
                $total = $cart->totalPrice;
                $coupon = Session::has('coupon') ? Session::get('coupon') : 0;
                if($gs->tax != 0)
                {
                    $tax = ($total / 100) * $gs->tax;
                    $total = $total + $tax;
                }
                if(!Session::has('coupon_total'))
                {
                $total = $total - $coupon;
                $total = $total + 0;
                }
                else {
                $total = Session::get('coupon_total');
                $total =  str_replace($curr->sign,'',$total) + round(0 * $curr->value, 2);
                }
                foreach ($products as $prod) {
                    if($prod['item']['type'] != 'Physical')
                    {
                        if(!Auth::guard('web')->check())
                        {
                $ck = 1;
                    return view('front.checkout', ['products' => $cart->items, 'totalPrice' => $total, 'pickups' => $pickups, 'totalQty' => $cart->totalQty, 'gateways' => $gateways, 'shipping_cost' => 0, 'checked' => $ck, 'digital' => $dp, 'curr' => $curr,'shipping_data' => $shipping_data,'package_data' => $package_data, 'vendor_shipping_id' => $vendor_shipping_id, 'vendor_packing_id' => $vendor_packing_id]);
                        }
                    }
                }
                    return view('front.checkout', ['products' => $cart->items, 'totalPrice' => $total, 'pickups' => $pickups, 'totalQty' => $cart->totalQty, 'gateways' => $gateways, 'shipping_cost' => 0, 'digital' => $dp, 'curr' => $curr,'shipping_data' => $shipping_data,'package_data' => $package_data, 'vendor_shipping_id' => $vendor_shipping_id, 'vendor_packing_id' => $vendor_packing_id]);
               }

         // If guest checkout is Deactivated then display pop up form with proper error message

            else{
                $gateways =  PaymentGateway::where('status','=',1)->get();
                $pickups = Pickup::all();
                $oldCart = Session::get('cart');
                $cart = new Cart($oldCart);
                $products = $cart->items;

                // Shipping Method

                if($gs->multiple_shipping == 1)
                {
                    $user = null;
                    foreach ($cart->items as $prod) {
                            $user[] = $prod['item']['user_id'];
                    }
                    $users = array_unique($user);
                    if(count($users) == 1)
                    {
                        $shipping_data  = DB::table('shippings')->where('user_id','=',$users[0])->get();

                        if(count($shipping_data) == 0){
                            $shipping_data  = DB::table('shippings')->where('user_id','=',0)->get();
                        }
                        else{
                            $vendor_shipping_id = $users[0];
                        }
                    }
                    else {
                        $shipping_data  = DB::table('shippings')->where('user_id','=',0)->get();
                    }

                }
                else{
                $shipping_data  = DB::table('shippings')->where('user_id','=',0)->get();
                }

                // Packaging

                if($gs->multiple_packaging == 1)
                {
                    $user = null;
                    foreach ($cart->items as $prod) {
                            $user[] = $prod['item']['user_id'];
                    }
                    $users = array_unique($user);
                    if(count($users) == 1)
                    {
                        $package_data  = DB::table('packages')->where('user_id','=',$users[0])->get();

                        if(count($package_data) == 0){
                            $package_data  = DB::table('packages')->where('user_id','=',0)->get();
                        }
                        else{
                            $vendor_packing_id = $users[0];
                        }
                    }
                    else {
                        $package_data  = DB::table('packages')->where('user_id','=',0)->get();
                    }

                }
                else{
                $package_data  = DB::table('packages')->where('user_id','=',0)->get();
                }


                $total = $cart->totalPrice;
                $coupon = Session::has('coupon') ? Session::get('coupon') : 0;
                if($gs->tax != 0)
                {
                    $tax = ($total / 100) * $gs->tax;
                    $total = $total + $tax;
                }
                if(!Session::has('coupon_total'))
                {
                $total = $total - $coupon;
                $total = $total + 0;
                }
                else {
                $total = Session::get('coupon_total');
                $total = $total + round(0 * $curr->value, 2);
                }
                $ck = 1;
        return view('front.checkout', ['products' => $cart->items, 'totalPrice' => $total, 'pickups' => $pickups, 'totalQty' => $cart->totalQty, 'gateways' => $gateways, 'shipping_cost' => 0, 'checked' => $ck, 'digital' => $dp, 'curr' => $curr,'shipping_data' => $shipping_data,'package_data' => $package_data, 'vendor_shipping_id' => $vendor_shipping_id, 'vendor_packing_id' => $vendor_packing_id]);
                    }
        }*/

    }


    /**
     * @param  Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function cashondelivery(Request $request)
    {
        if($request->pass_check) {
            $users = User::where('email', '=', $request->personal_email)->get();
            if(count($users) == 0) {
                if ($request->personal_pass == $request->personal_confirm) {
                    $user = new User;
                    $user->name = $request->personal_name; 
                    $user->email = $request->personal_email;   
                    $user->password = bcrypt($request->personal_pass);
                    $token = md5(time().$request->personal_name.$request->personal_email);
                    $user->verification_link = $token;
                    $user->affilate_code = md5($request->name.$request->email);
                    $user->emai_verified = 'Yes';
                    $user->save();
                    Auth::guard('web')->login($user);                     
                }else{
                    return redirect()->back()->with('unsuccess', "Confirm Password Doesn't Match.");     
                }
            }
            else {
                return redirect()->back()->with('unsuccess', "This Email Already Exist.");  
            }
        }


        if (!Session::has('cart')) {
            return redirect()->route('front.cart')->with('success', "You don't have any product to checkout.");
        }
        if (Session::has('currency')) {
            $curr = Currency::find(Session::get('currency'));
        }
        else
            {
            $curr = Currency::where('is_default', '=', 1)->first();
        }
        $gs = Generalsetting::findOrFail(1);
        $oldCart = Session::get('cart');
        $cart = new Cart($oldCart);

        $discountItemsTotal = [];

        if($request->discountItems) {
            $discountItems = json_decode($request->discountItems);
            foreach ($discountItems as $key => $value) {
                foreach ($cart->items as $cartKeys => $carts) {
                    if ($cartKeys == $key) {
                        $carts['item']['price'] = $value;
                        $carts['price'] = $value*$carts['qty'];
                        $cart->updatePrice($key,$carts['price']);
                        // dd($carts['price']); 
                    }
                }
            }

            // Session::put('cart', $cart);
        }

        // dd($cart);

        // foreach ($cart as $key => $value) {
        //     foreach($value as $cartkey => $cartvalue){
        //         foreach ($discountItemsTotal as $diskey => $disvalue) {
        //             if ($discountItemsTotal[$diskey] == $cartkey) {
        //                 $cartvalue['price'] = $disvalue;
        //             }
        //         }
        //     }
        // }



        foreach($cart->items as $key => $prod)
        {
            if(!empty($prod['item']['license']) && !empty($prod['item']['license_qty'])) {
                foreach($prod['item']['license_qty']as $ttl => $dtl)
                {
                    if($dtl != 0) {
                        $dtl--;
                        $produc = Product::findOrFail($prod['item']['id']);
                        $temp = $produc->license_qty;
                        $temp[$ttl] = $dtl;
                        $final = implode(',', $temp);
                        $produc->license_qty = $final;
                        $produc->update();
                        $temp =  $produc->license;
                        $license = $temp[$ttl];
                         $oldCart = Session::has('cart') ? Session::get('cart') : null;
                         $cart = new Cart($oldCart);
                         $cart->updateLicense($prod['item']['id'], $license);  
                         Session::put('cart', $cart);
                        break;
                    }                    
                }
            }
        }


        
        $order = new Order;
        $success_url = action('Front\PaymentController@payreturn');
        $item_name = $gs->title." Order";
        $item_number = str_random(4).time();
        $order['user_id'] = $request->user_id;
        $order['cart'] = utf8_encode(bzcompress(serialize($cart), 9));
        $order['totalQty'] = $request->totalQty;
        $order['pay_amount'] = round($request->total / $curr->value, 2);
        $order['method'] = $request->method;
        $order['shipping'] = $request->shipping;
        $order['pickup_location'] = $request->pickup_location;
        $order['customer_email'] = $request->email;
        $order['customer_name'] = $request->name;
        $order['shipping_cost'] = $request->shipping_cost;
        $order['packing_cost'] = $request->packing_cost;
        $order['tax'] = $request->tax;
        $order['customer_phone'] = $request->phone;
        $OrderNum = $this->getLastOrder();
        $newOrderNumber = $OrderNum + 1;
        $order['order_number'] = "MB-".$newOrderNumber;
        $order['customer_address'] = $request->address;
        $order['customer_country'] = "Bangladesh";
        $order['customer_city'] = $request->city;
        $order['customer_zip'] = null;
        $order['shipping_email'] = $request->shipping_email;
        $order['shipping_name'] = $request->shipping_name;
        $order['shipping_phone'] = $request->shipping_phone;
        $order['shipping_address'] = $request->shipping_address;
        $order['shipping_country'] = null;
        $order['shipping_city'] = $request->shipping_city;
        $order['shipping_zip'] = null;
        $order['order_note'] = $request->order_notes;
        $order['coupon_code'] = $request->coupon_code;
        $order['coupon_discount'] = $request->discount_price ?? $request->coupon_discount;
        $order['dp'] = $request->dp;
        $order['payment_status'] = "Pending";
        $order['currency_sign'] = $curr->sign;
        $order['currency_value'] = $curr->value;
        $order['vendor_shipping_id'] = $request->vendor_shipping_id;
        $order['vendor_packing_id'] = $request->vendor_packing_id; 
        $order['created_at']=now()->addHours(6);  
        $ordertime= $order['created_at']->toTimeString();
        if($ordertime>15) {
            $order['shipping_time']= "Morning(9:30 AM To 11:30 AM)" ;
        }
        elseif ($ordertime>11) {
            $order['shipping_time']= "Evening(4:00 PM To 7:00 PM))" ;
        }
        else{
            $order['shipping_time']= "Mid Day(11:30 AM To 3:00 PM)" ;
        }

        if (Session::has('affilate')) {
            $val = $request->total / $curr->value;
            $val = $val / 100;
            $sub = $val * $gs->affilate_charge;
            $user = User::findOrFail(Session::get('affilate'));
            $user->affilate_income += $sub;
            $user->update();
            $order['affilate_user'] = $user->name;
            $order['affilate_charge'] = $sub;
        }
        $order->save();

        $track = new OrderTrack;
        $track->title = 'Pending';
        $track->text = 'You have successfully placed your order.';
        $track->order_id = $order->id;
        $track->save();

        $notification = new Notification;
        $notification->order_id = $order->id;
        $notification->save();
        if($request->coupon_id != "") {
            $coupon = Coupon::findOrFail($request->coupon_id);
            $coupon->used++;
            if($coupon->times != null) {
                            $i = (int)$coupon->times;
                            $i--;
                            $coupon->times = (string)$i;
            }
                        $coupon->update();

        }

        foreach($cart->items as $prod)
        {
            $x = (string)$prod['size_qty'];
            if(!empty($x)) {
                $product = Product::findOrFail($prod['item']['id']);
                $x = (int)$x;
                $x = $x - $prod['qty'];
                $temp = $product->size_qty;
                $temp[$prod['size_key']] = $x;
                $temp1 = implode(',', $temp);
                $product->size_qty =  $temp1;
                $product->update();               
            }
        }


        foreach($cart->items as $prod)
        {
            $x = (string)$prod['stock'];
            if($x != null) {

                $product = Product::findOrFail($prod['item']['id']);
                $product->stock =  $prod['stock'];
                $product->update();  
                if($product->stock <= 5) {
                    $notification = new Notification;
                    $notification->product_id = $product->id;
                    $notification->save();                    
                }              
            }
        }

        $notf = null;

        foreach($cart->items as $prod)
        {
            if($prod['item']['user_id'] != 0) {
                $vorder =  new VendorOrder;
                $vorder->order_id = $order->id;
                $vorder->user_id = $prod['item']['user_id'];
                $notf[] = $prod['item']['user_id'];
                $vorder->qty = $prod['qty'];
                $vorder->price = $prod['price'];
                $vorder->order_number = $order->order_number;             
                $vorder->save();
            }

        }

        if(!empty($notf)) {
            $users = array_unique($notf);
            foreach ($users as $user) {
                $notification = new UserNotification;
                $notification->user_id = $user;
                $notification->order_number = $order->order_number;
                $notification->save();    
            }
        }

        Session::put('temporder', $order);
        Session::put('tempcart', $cart);

        Session::forget('cart');

            Session::forget('already');
            Session::forget('coupon');
            Session::forget('coupon_total');
            Session::forget('coupon_total1');
            Session::forget('coupon_percentage');

        //Sending Email To Buyer

        if($gs->is_smtp == 0) {
            $data = [
            'to' => $request->emial,
            'type' => "new_order",
            'cname' => $request->name,
            'oamount' => "",
            'aname' => "",
            'aemail' => "",
            'wtitle' => "",
            'onumber' => $order->order_number,
            ];

            $mailer = new GeniusMailer();
            $mailer->sendAutoOrderMail($data, $order->id);           
        }
        else
        {
            $to = $request->email;
            $subject = "Your Order Placed!!";
            $msg = "Hello ".$request->name."!\nYou have placed a new order.\nYour order number is ".$order->order_number.".Please wait for your delivery. \nThank you.";
            $headers = "From: ".$gs->from_name."<".$gs->from_email.">";
            mail($to, $subject, $msg, $headers);            
        }
        //Sending Email To Admin
        if($gs->is_smtp == 1) {
            $data = [
                'to' => Pagesetting::find(1)->contact_email,
                'subject' => "New Order Recieved!!",
                'body' => "Hello Admin!<br>Your store has received a new order.<br>Order Number is ".$order->order_number.".Please login to your panel to check. <br>Thank you.",
            ];

            $mailer = new GeniusMailer();
            $mailer->sendCustomMail($data);
        }
        else
        {
            $to = Pagesetting::find(1)->contact_email;
            $subject = "New Order Recieved!!";
            $msg = "Hello Admin!\nYour store has recieved a new order.\nOrder Number is ".$order->order_number.".Please login to your panel to check. \nThank you.";
            $headers = "From: ".$gs->from_name."<".$gs->from_email.">";
            mail($to, $subject, $msg, $headers);
        }

        return redirect($success_url);
    }

    private function getLastOrder()
    {
        $order = Order::latest()->first();
        $orderNumber = str_replace(array('MB-'), '', $order->order_number);
        return $orderNumber;
    }

    public function gateway(Request $request)
    {

        $input = $request->all();

        // dd($input);

        $rules = [
        'txn_id4' => 'required',
        ];


        $messages = [
        'required' => 'The Transaction ID field is required.',
        ];

        $validator = Validator::make($input, $rules, $messages);

        if ($validator->fails()) {
            Session::flash('unsuccess', $validator->messages()->first());
            return redirect()->back()->withInput();
        }

        if($request->pass_check) {
            $users = User::where('email', '=', $request->personal_email)->get();
            if(count($users) == 0) {
                if ($request->personal_pass == $request->personal_confirm) {
                    $user = new User;
                    $user->name = $request->personal_name; 
                    $user->email = $request->personal_email;   
                    $user->password = bcrypt($request->personal_pass);
                    $token = md5(time().$request->personal_name.$request->personal_email);
                    $user->verification_link = $token;
                    $user->affilate_code = md5($request->name.$request->email);
                    $user->email_verified = 'Yes';
                    $user->save();
                    Auth::guard('web')->login($user);                     
                }else{
                    return redirect()->back()->with('unsuccess', "Confirm Password Doesn't Match.");     
                }
            }
            else {
                return redirect()->back()->with('unsuccess', "This Email Already Exist.");  
            }
        }

        $gs = Generalsetting::findOrFail(1);
        if (!Session::has('cart')) {
            return redirect()->route('front.cart')->with('success', "You don't have any product to checkout.");
        }
        $oldCart = Session::get('cart');
        $cart = new Cart($oldCart);

        if($request->discountItems) {
            $discountItems = json_decode($request->discountItems);
            foreach ($discountItems as $key => $value) {
                foreach ($cart->items as $cartKeys => $carts) {
                    if ($cartKeys == $key) {
                        $carts['item']['price'] = $value;
                        $carts['price'] = $value*$carts['qty'];
                        $cart->updatePrice($key,$carts['price']);
                        // dd($carts['price']); 
                    }
                }
            }

            // Session::put('cart', $cart);
        }

        if (Session::has('currency')) {
            $curr = Currency::find(Session::get('currency'));
        }
        else
            {
            $curr = Currency::where('is_default', '=', 1)->first();
        }
        foreach($cart->items as $key => $prod)
        {
            if(!empty($prod['item']['license']) && !empty($prod['item']['license_qty'])) {
                foreach($prod['item']['license_qty']as $ttl => $dtl)
                {
                    if($dtl != 0) {
                        $dtl--;
                        $produc = Product::findOrFail($prod['item']['id']);
                        $temp = $produc->license_qty;
                        $temp[$ttl] = $dtl;
                        $final = implode(',', $temp);
                        $produc->license_qty = $final;
                        $produc->update();
                        $temp =  $produc->license;
                        $license = $temp[$ttl];
                         $oldCart = Session::has('cart') ? Session::get('cart') : null;
                         $cart = new Cart($oldCart);
                         $cart->updateLicense($prod['item']['id'], $license);  
                         Session::put('cart', $cart);
                        break;
                    }                    
                }
            }
        }
        $settings = Generalsetting::findOrFail(1);
        $order = new Order;
        $success_url = action('Front\PaymentController@payreturn');
        $item_name = $settings->title." Order";
        $item_number = str_random(4).time();
        $order['user_id'] = $request->user_id;
        $order['cart'] = utf8_encode(bzcompress(serialize($cart), 9));
        $order['totalQty'] = $request->totalQty;
        $order['pay_amount'] = round($request->total / $curr->value, 2);
        $order['method'] = $request->method;
        $order['shipping'] = $request->shipping;
        $order['pickup_location'] = $request->pickup_location;
        $order['customer_email'] = $request->email;
        $order['customer_name'] = $request->name;
        $order['shipping_cost'] = $request->shipping_cost;
        $order['packing_cost'] = $request->packing_cost;
        $order['tax'] = $request->tax;
        $order['customer_phone'] = $request->phone;
        $OrderNum = $this->getLastOrder();
        $newOrderNumber = $OrderNum + 1;
        $order['order_number'] = "MB-".$newOrderNumber;
        // $order['order_number'] = str_random(4).time();
        $order['customer_address'] = $request->address;
        $order['customer_country'] = "Bangladesh";
        $order['customer_city'] = $request->city;
        $order['customer_zip'] = null;
        $order['shipping_email'] = $request->shipping_email;
        $order['shipping_name'] = $request->shipping_name;
        $order['shipping_phone'] = $request->shipping_phone;
        $order['shipping_address'] = $request->shipping_address;
        $order['shipping_country'] = null;
        $order['shipping_city'] = $request->shipping_city;
        $order['shipping_zip'] = null;
        $order['order_note'] = $request->order_notes;
        $order['txnid'] = $request->txn_id4;
        $order['coupon_code'] = $request->coupon_code;
        $order['coupon_discount'] = $request->discount_price ?? $request->coupon_discount;
        $order['dp'] = $request->dp;
        $order['payment_status'] = "Pending";
        $order['currency_sign'] = $curr->sign;
        $order['currency_value'] = $curr->value;
        $order['vendor_shipping_id'] = $request->vendor_shipping_id;
        $order['vendor_packing_id'] = $request->vendor_packing_id; 
        $order['created_at']=now()->addHours(6);  
        $ordertime= $order['created_at']->toTimeString();
        if($ordertime>15) {
            $order['shipping_time']= "Morning(9:30 AM To 11:30 AM)" ;
        }
        elseif ($ordertime>11) {
            $order['shipping_time']= "Evening(4:00 PM To 7:00 PM))" ;
        }
        else{
            $order['shipping_time']= "Mid Day(11:30 AM To 3:00 PM)" ;
        }     
        if (Session::has('affilate')) {
            $val = $request->total / $curr->value;
            $val = $val / 100;
            $sub = $val * $gs->affilate_charge;
            $user = User::findOrFail(Session::get('affilate'));
            $user->affilate_income += $sub;
            $user->update();
            $order['affilate_user'] = $user->name;
            $order['affilate_charge'] = $sub;
        }
        $order->save();

        $track = new OrderTrack;
        $track->title = 'Pending';
        $track->text = 'You have successfully placed your order.';
        $track->order_id = $order->id;
        $track->save();
        
        $notification = new Notification;
        $notification->order_id = $order->id;
        $notification->save();
        if($request->coupon_id != "") {
            $coupon = Coupon::findOrFail($request->coupon_id);
            $coupon->used++;
            if($coupon->times != null) {
                            $i = (int)$coupon->times;
                            $i--;
                            $coupon->times = (string)$i;
            }
                        $coupon->update();

        }

        foreach($cart->items as $prod)
        {
            $x = (string)$prod['size_qty'];
            if(!empty($x)) {
                $product = Product::findOrFail($prod['item']['id']);
                $x = (int)$x;
                $x = $x - $prod['qty'];
                $temp = $product->size_qty;
                $temp[$prod['size_key']] = $x;
                $temp1 = implode(',', $temp);
                $product->size_qty =  $temp1;
                $product->update();               
            }
        }


        foreach($cart->items as $prod)
        {
            $x = (string)$prod['stock'];
            if($x != null) {

                $product = Product::findOrFail($prod['item']['id']);
                $product->stock =  $prod['stock'];
                $product->update();  
                if($product->stock <= 5) {
                    $notification = new Notification;
                    $notification->product_id = $product->id;
                    $notification->save();                    
                }              
            }
        }

        $notf = null;

        foreach($cart->items as $prod)
        {
            if($prod['item']['user_id'] != 0) {
                $vorder =  new VendorOrder;
                $vorder->order_id = $order->id;
                $vorder->user_id = $prod['item']['user_id'];
                $notf[] = $prod['item']['user_id'];
                $vorder->qty = $prod['qty'];
                $vorder->price = $prod['price'];
                $vorder->order_number = $order->order_number;             
                $vorder->save();
            }

        }

        if(!empty($notf)) {
            $users = array_unique($notf);
            foreach ($users as $user) {
                $notification = new UserNotification;
                $notification->user_id = $user;
                $notification->order_number = $order->order_number;
                $notification->save();    
            }
        }

        Session::put('temporder', $order);
        Session::put('tempcart', $cart);
        Session::forget('cart');
        Session::forget('already');
        Session::forget('coupon');
        Session::forget('coupon_total');
        Session::forget('coupon_total1');
        Session::forget('coupon_percentage');

        //Sending Email To Buyer
        if($gs->is_smtp == 1) {
            $data = [
            'to' => $request->email,
            'type' => "new_order",
            'cname' => $request->name,
            'oamount' => "",
            'aname' => "",
            'aemail' => "",
            'wtitle' => "",
            'onumber' => $order->order_number,
            ];

            $mailer = new GeniusMailer();
            $mailer->sendAutoOrderMail($data, $order->id);            
        }
        else
        {
            $to = $request->email;
            $subject = "Your Order Placed!!";
            $msg = "Hello ".$request->name."!\nYou have placed a new order.\nYour order number is ".$order->order_number.".Please wait for your delivery. \nThank you.";
            $headers = "From: ".$gs->from_name."<".$gs->from_email.">";
            mail($to, $subject, $msg, $headers);            
        }
        //Sending Email To Admin
        if($gs->is_smtp == 1) {
            $data = [
                'to' => Pagesetting::find(1)->contact_email,
                'subject' => "New Order Recieved!!",
                'body' => "Hello Admin!<br>Your store has received a new order.<br>Order Number is ".$order->order_number.".Please login to your panel to check. <br>Thank you.",
            ];

            $mailer = new GeniusMailer();
            $mailer->sendCustomMail($data);            
        }
        else
        {
            $to = Pagesetting::find(1)->contact_email;
            $subject = "New Order Recieved!!";
            $msg = "Hello Admin!\nYour store has recieved a new order.\nOrder Number is ".$order->order_number.".Please login to your panel to check. \nThank you.";
            $headers = "From: ".$gs->from_name."<".$gs->from_email.">";
            mail($to, $subject, $msg, $headers);
        }

        return redirect($success_url);
    }


    // Capcha Code Image
    private function code_image()
    {
        $actual_path = str_replace('project', '', base_path());
        $image = imagecreatetruecolor(200, 50);
        $background_color = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 200, 50, $background_color);

        $pixel = imagecolorallocate($image, 0, 0, 255);
        for($i=0;$i<500;$i++)
        {
            imagesetpixel($image, rand()%200, rand()%50, $pixel);
        }

        $font = $actual_path.'assets/front/fonts/NotoSans-Bold.ttf';
        $allowed_letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $length = strlen($allowed_letters);
        $letter = $allowed_letters[rand(0, $length-1)];
        $word='';
        //$text_color = imagecolorallocate($image, 8, 186, 239);
        $text_color = imagecolorallocate($image, 0, 0, 0);
        $cap_length=6;// No. of character in image
        for ($i = 0; $i< $cap_length;$i++)
        {
            $letter = $allowed_letters[rand(0, $length-1)];
            imagettftext($image, 25, 1, 35+($i*25), 35, $text_color, $font, $letter);
            $word.=$letter;
        }
        $pixels = imagecolorallocate($image, 8, 186, 239);
        for($i=0;$i<500;$i++)
        {
            imagesetpixel($image, rand()%200, rand()%50, $pixels);
        }
        session(['captcha_string' => $word]);
        imagepng($image, $actual_path."assets/images/capcha_code.png");
    }

}
