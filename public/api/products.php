<?php
require 'products_array.php';
function handle() {
	global $products;
	return json_encode($products);
}

print_r(handle());