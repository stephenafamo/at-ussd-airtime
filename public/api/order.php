<?php
require 'products_array.php';
function handle() {
	global $products;
	$data_json 	= file_get_contents('php://input');
	$cart 		= json_decode($data_json, true)['cart'];

	$total 		= 0;
	
	$order 				= [];
	$order['total'] 	= 0;
	$order['items'] 	= [];

	foreach ($cart as $id => $quantity) {
		if($quantity === null)
			continue;

		$product 				= $products[$id];
		$product['quantity'] 	= $quantity;
		$product['total_price'] = $quantity * $product['price'];

		$order['total']		   += $product['total_price'];
		$order['items'][]		= $product;
		$order['paid']			= false;
	}

	$order_json 	= json_encode($order);

	$order_number 	= mt_rand(10000, 99999);

	while(file_exists("../../data/orders/".$order_number)) {
		$order_number 	= mt_rand(1000, 9999);
	}

	$file = fopen('../../data/orders/'.$order_number, "w");
	fwrite($file, $order_json);
	fclose($file);

	$response 	= [
		'status' 	=> 'success',
		'number'	=> $order_number,
		'order'		=> $order
	];

	return json_encode($response);

}
print_r(handle());