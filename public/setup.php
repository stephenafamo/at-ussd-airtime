<?php
include __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

$type 	= "airtime";
$file 	= null;

$banks = [
	1	=> ['name' => "First Bank", 	"code" => 234001],
	2	=> ['name' => "Zenith Bank", 	"code" => 234002],
	3	=> ['name' => "Access Bank", 	"code" => 234003],
	4	=> ['name' => "GTBank Plc", 	"code" => 234004],
	5	=> ['name' => "Ecobank Plc", 	"code" => 234005],
	6	=> ['name' => "FCMB", 			"code" => 234006],
	7	=> ['name' => "Diamond Bank", 	"code" => 234007],
	8	=> ['name' => "Providus Bank",	"code" => 234008],
];

$staging_client = new Client([
	'base_uri' => $_ENV['STAGING_API_URL'],
	'headers' => [
		'apikey' => $_ENV['STAGING_API_KEY'],
		'Content-Type' => 'application/json',
		'Accept' => 'application/json'
	]
]);

$sandbox_client = new Client([
	'base_uri' => $_ENV['SANDBOX_API_URL'],
	'headers' => [
		'apikey' => $_ENV['SANDBOX_API_KEY'],
		'Content-Type' => 'application/x-www-form-urlencoded',
		'Accept' => 'application/json'
	]
]);

function handle()
{
	$details = getDetails($_POST['phoneNumber']);

	if (empty($_POST['text'])) {
		return base();
	}

	$input 				= explode("*", $_POST['text']);
	$validated_req 		= validate($input, $details);

	if (!is_array($validated_req)) {
		return $validated_req;
	}

	$amount 		= $validated_req['amount'];
	$account_key 	= addAccount($validated_req, $details);

	$charge 		= charge($amount, $account_key, $details);

	if ($charge['status'] === 'error') {
		return "END We encountered an error while trying to process this transaction";
	}

	if ($charge['status'] === 'duplicate') {
		return "END Duplicate transaction. Please try again in a few minutes";
	}

	if (!isset($input[3]) && $charge['status'] === 'success') {
		$reply 		 = "CON Enter the OTP that has been sent to your phone";
		$reply 		.= "\nYou can end the session and dail again to resume from this point.";

		$otp_code 	 = substr_replace($_POST['serviceCode'], "*OTP", -1, 0);
		$reply 		.= "\nYou can also dial $otp_code.";

		return $reply;
	}

	$otp 	= $input[3];

	return verifyOtp($details, $otp);
}

function getDetails($phoneNumber)
{
	if (!file_exists('../data/'.$phoneNumber)) {
		$file = fopen('../data/'.$phoneNumber, "w");
		fwrite($file, "{}");
		fclose($file);
	}
	
	$details 	= json_decode(file_get_contents("../data/".$phoneNumber), true);
	return $details;
}

function updateDetails($details)
{
	$phoneNumber = $_POST['phoneNumber'];

	$file = fopen('../data/'.$phoneNumber, "w");
	fwrite($file, json_encode($details));
	fclose($file);
}

function base($reply = "")
{
	if (empty($reply))
		$reply .= 'CON ';

	$reply .= "Enter the amount of airtime you want to purchase.\nMinimum: 100\nMaximum: 10,000";

	return $reply;	
}

function validate(Array &$input, Array $details)
{
	global $banks, $type, $file;

	$amount = null;

	$mapped_banks = [];
	
	foreach ($banks as $bankKey => $single_bank) {
		$mapped_banks[$single_bank['code']] = ['name' => $single_bank['name'], 'id' => $bankKey];
	}

	if (!empty($details['ongoing']) && count($input) === 1) {
		$otp 			= $input[0];
		$the_ongoing 	= $details['ongoing'];

		$input 			= [];

		if ($the_ongoing['file']) {
			$input[]	= end(explode("/", $the_ongoing['file']));
		} else {
			$input[]	= $the_ongoing['amount'];			
		}

		$input[]		= $details['accounts'][$the_ongoing['account_key']]['account'];
		$input[]		= $mapped_banks[$details['accounts'][$the_ongoing['account_key']]['bank']]['id'];
		$input[]		= $otp;
	}

	if (isset($input[0]) && (int) $input[0] > 50 && (int) $input[0] < 10000) {
		$amount 	= (int) $input[0];
	} 


	if ((int) $input[0] > 100000 && (int) $input[0] < 999999) {
		$order_number 	= $input[0];
		$file 			= "../data/orders/".$order_number;

		if (file_exists($file)) {
			$order 		= json_decode(file_get_contents($file));
		}

		$type 			= "checkout";
		$amount 		= $order->total;
	}

	if (!$amount) {
		return "END Invalid amount";		
	}

	if (!isset($input[1])) {
		$reply 	= "CON ";

		if ($type === "checkout") {
			$reply 	.= "You are about to pay NGN ". number_format($amount) ." for order: $order_number \n";
		}

		if (!empty($details['accounts'])) {
			$reply 	.= "Select one of the following saved bank accounts or ";
		}

		$reply 	 .= "enter your account number.";

		if (!empty($details['accounts'])) {

			foreach ($details['accounts'] as $id => $account) {
				$reply 	.= "\n";
				$reply 	.= $id + 1 . ". ";
				$reply 	.= $mapped_banks[$account['bank']]['name'] . " ";
				$reply 	.= substr_replace($account['account'], "**", 2, 6);
			}
		}

		return $reply;
	}

	if (strlen($input[1]) < 10) {
		$selected_account 	= $details['accounts'][$input[1] - 1];

		if (empty($selected_account)) {
			return "END Invalid Account";
		}

		array_splice($input, 1, 1, [$selected_account['account'], $mapped_banks[$selected_account['bank']]['id']]);
	}

	$account 	= $input[1];

	if (!isset($input[2])) {
		$reply 	= "CON Select you bank.";

		foreach ($banks as $key => $bank) {
			$reply 	.= "\n$key.". $bank['name'];
		}

		return $reply;
	}

	if (!array_key_exists($input[2], $banks)) {
		return "END Invalid bank";
	}

	$bank 		= $banks[$input[2]]['code'];

	return ["amount" => $amount, "bank" => $bank, "account" => $account];
}

function addAccount(Array $validated_account, &$details)
{
	unset($validated_account['amount']);

	if (!in_array($validated_account, $details['accounts'])) {
		$details['accounts'][] 	= $validated_account;
	}

	updateDetails($details);

	return array_search($validated_account, $details['accounts']);
}

function charge($amount, $account_key, &$details) 
{
	global $staging_client, $type, $file;

	if (!empty($details['ongoing'])) {
		return ['status' => 'success'];
	}

	$data 		= [
		"username" 		=> $_ENV['STAGING_API_USERNAME'],
		"productName" 	=> $_ENV['PAYMENT_PRODUCT_NAME'],
		"bankAccount"  	=> [
			"accountName" 		=> "",
			"accountNumber" 	=> $details['accounts'][$account_key]['account'],
			"countryCode" 		=> "NG",
			"bankCode" 			=> $details['accounts'][$account_key]['bank']
		],
		"currencyCode" 	=> "NGN",
		"amount" 		=> $amount,
		"narration" 	=> "test",
		// "metadata" 		=> []
	];

	try {		
		$response 			= $staging_client->post("charge", ['body' => json_encode($data)]);
		$response_arr		= json_decode($response->getBody()->getContents(), true);

		if ($response_arr['status'] === 'DuplicateRequest') {
			return ['status' => 'duplicate'];
		}

		if ($response_arr['status'] !== 'PendingValidation' || !array_key_exists('transactionId', $response_arr)) {
			return ['status' => 'error'];
		}

		mockOTP($_POST['phoneNumber'], $amount);
		
		$details['ongoing'] = [
			'time' 				=> time(),
			'type' 				=> $type,
			'file'				=> $file,
			'transactionId' 	=> $response_arr['transactionId'],
			'amount' 			=> $amount,
			'account_key' 		=> $account_key,
		];

		updateDetails($details);

		return ['status' => 'success'];

	} catch (GuzzleException $e) {
		return ['status' => 'error'];
	}
}

function verifyOtp(&$details, $otp = false)
{
	global $staging_client;
	
	if (!$otp){
		$otp = $_POST['text'];
	}

	if (empty($otp)) {
		return "CON Enter the OTP that was sent to your phone";
	}

	$data 		= [
		"username" 		=> $_ENV["STAGING_API_USERNAME"],
		"transactionId" => $details['ongoing']['transactionId'],
		"otp" 			=> $otp
	];


	try {
		$response 			= $staging_client->post("validate", ['body' => json_encode($data)]);
		$response_arr		= json_decode($response->getBody()->getContents(), true);

		$ongoing 				= $details['ongoing'] ;
		$details['ongoing'] 	= [];

		updateDetails($details);

		return fulfil($_POST['phoneNumber'], $ongoing);

	} catch (GuzzleException $e) {
		$details['ongoing'] 	= [];

		updateDetails($details);

		return "END Something went wrong while veryfying your airtime purchase. Please try again.";
	}
}

function fulfil($phoneNumber, $ongoing)
{
	$type 	= $ongoing['type'];
	$amount = $ongoing['amount'];

	switch ($type) {
		case 'airtime':
		return sendAirtime($phoneNumber, $amount);
		break;

		case 'checkout':
		return pay($phoneNumber, $amount, $ongoing['file']);
		break;
	}
}

function pay($phoneNumber, $amount, $file)
{
		// var_dump($file); die;
	if (!file_exists($file)) {
		return "END we experienced an error retrieving that order. Please contact us";
	}

	$order 		= json_decode(file_get_contents($file));

	if ($order->total !== $amount)
		return "END Something seems fishy about this payment. Contact us";
	
	$order->paid 	= true;
	$order->paid_by = $phoneNumber;

	$order_json 	= json_encode($order);

	$file = fopen($file, "w");
	fwrite($file, $order_json);
	fclose($file);

	return "END You have successfully paid for your order";	
}

function sendAirtime($phoneNumber, $amount)
{
	global $sandbox_client;

	$data = [
		'username' 		=> $_ENV['SANDBOX_API_USERNAME'],
		'recipients'	=> json_encode([[
			'phoneNumber'	=> $phoneNumber,
			'amount'		=> "NGN $amount"
		]]),
	];

	try {
		$response 			= $sandbox_client->post("airtime/send", ['form_params' => $data]);
		$response_arr		= json_decode($response->getBody()->getContents(), true);

		return "CON Your airtime purchase was successful!";
	} catch (GuzzleException $e) {}
}

function mockOTP($phoneNumber, $amount)
{
	global $sandbox_client;

	$data = [
		'username' 	=> $_ENV['SANDBOX_API_USERNAME'],
		'to' 		=> $_ENV['MESSAGING_SHORTCODE'],
		'from'		=> '12345',
		'message' 	=> 'Your OTP is '.mt_rand(100000, 999999)." Use it to complete your purchase of NGN $amount airtime"
	];

	try {
		$response 			= $sandbox_client->post("messaging", ['form_params' => $data]);
		$response_arr		= json_decode($response->getBody()->getContents(), true);
		
	} catch (GuzzleException $e) {}
}

function randomString($length) {
    $keys = array_merge(range(0,9), range('a', 'z'));

    $key = "";
    for($i=0; $i < $length; $i++) {
        $key .= $keys[mt_rand(0, count($keys) - 1)];
    }
    return $key;
}