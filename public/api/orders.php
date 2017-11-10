<?php
function handle() {
	$data_json 		= file_get_contents('php://input');
	$orders_arr		= json_decode($data_json, true);
	
	$orders		= [];

	foreach ($orders_arr as $id => $order_number) {
		$file 	= "../../data/orders/".$order_number;

		if (file_exists($file)) {
			$orders[(string) $order_number] 	= json_decode(file_get_contents($file));
		}
	}

	$orders_json 	= json_encode($orders);

	return $orders_json;

}
print_r(handle());