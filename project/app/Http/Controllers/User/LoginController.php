<?php

namespace App\Http\Controllers\User;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Auth;
use Illuminate\Support\Facades\DB;
use Session;
use Illuminate\Support\Facades\Input;
use Validator;

class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest', ['except' => ['logout', 'userLogout']]);
    }

    public function showLoginForm()
    {
        $this->code_image();
        return view('user.login');
    }

    public function login(Request $request)
    {
        //--- Validation Section
        $rules = [
                  'number'   => 'required|numeric',
                ];

        $validator = Validator::make(Input::all(), $rules);

        if ($validator->fails()) {
            return response()->json(array('errors' => $validator->getMessageBag()->toArray()));
        }
        //--- Validation Section Ends

        // Attempt to log the user in
        if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            // if successful, then redirect to their intended location

            // Check If Email is verified or not
            if(Auth::guard('web')->user()->email_verified == 'No') {
                Auth::guard('web')->logout();
                return response()->json(array('errors' => [ 0 => 'Your Email is not Verified!' ]));
            }

            if(Auth::guard('web')->user()->ban == 1) {
                Auth::guard('web')->logout();
                return response()->json(array('errors' => [ 0 => 'Your Account Has Been Banned.' ]));
            }

            // Login Via Modal
            if(!empty($request->modal)) {
                // Login as Vendor
                if(!empty($request->vendor)) {
                    if(Auth::guard('web')->user()->is_vendor == 2) {
                        return response()->json(route('vendor-dashboard'));
                    }
                    else {
                        return response()->json(route('user-package'));
                    }
                }
                // Login as User
                return response()->json(1);
            }
            // Login as User
            return response()->json(route('user-dashboard'));
        }

        // if unsuccessful, then redirect back to the login with the form data
          return response()->json(array('errors' => [ 0 => 'Credentials Doesn\'t Match !' ]));
    }
    /*
     * Login for only user with OTP
     * @param $user_otp
     * @param $user_number
     */
    public function confirmLogin(Request $request)
    {
        $user_otp = $request->otp;
        $user_number = $request->number;
        $session_otp = $request->Session()->get('fourRandomDigit');
        $session_number = $request->Session()->get('user_number');

        //Login from admin panel
        if(isset($request->admin_request) && $request->admin_request == "true"){
            if (Auth::attempt(['phone' => $user_number, 'password' => 1234])) {
                return app('App\Http\Controllers\User\UserController')->index();
            }
        }

        //Login from user request
        if($user_otp == $session_otp && $user_number == $session_number) {
            if (Auth::attempt(['phone' => $user_number, 'password' => 1234])) {
                if ($request->checkout == 1) {
                    return app('App\Http\Controllers\Front\CartController')->cart();
                }
                return app('App\Http\Controllers\User\UserController')->index();
            }
        }else{
            return redirect()->back()->with('errors', 'Credentials doesn\'t match. Try again!');
        }
    }

    public function submitNumber(Request $request)
    {

        //--- Validation Section
        $rules = [
            'number'   => 'required|numeric|digits:11',
        ];
        $validator = Validator::make(Input::all(), $rules);
        if ($validator->fails()) {
            return "failed";
        }
        //--- Validation Section Ends

        //---Check number existence
        $user = DB::table('users')->where('phone', $request->number)->count();
        if($user < 1) {
            Session::put('redirect', 'You need to register first!');
            return "unRegister";
        }

        $to = $request->number;
        $fourRandomDigit = rand(1000, 9999);
        $request->Session()->put('fourRandomDigit', $fourRandomDigit);
        $request->Session()->put('user_number', $to);

        //Prepare data for sms
        $token = "ddf27746968425c62d0e9e5713ed1169";
        $message = "Your confirmation code for marnbazar.com login is ". $fourRandomDigit;
        $url = "http://api.greenweb.com.bd/api.php";
        //Send sms
        $data= array(
            'to'=>"$to",
            'message'=>"$message",
            'token'=>"$token"
        ); // Add parameters in key value
        $ch = curl_init(); // Initialize cURL
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);

        return "success";
    }

    public function logout()
    {
        Auth::guard('web')->logout();
        return redirect('/');
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
