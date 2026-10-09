<?php
/**
 * Split WooCommerce cart into Printify / CustomCat / Printful shipping packages,
 * and force each package to pull rates from its correct zone (Printify & CustomCat)
 * or let the Printful plugin calculate its own live rates (Printful) —
 * bypassing WooCommerce's single-zone-per-destination matching limitation.
 */

// ─── Global holder for total package count (used by naming filter) ───────
$GLOBALS['pmr_total_packages'] = 1;

// ─── Printful brand ID (products in this brand are treated as Printful-fulfilled) ──
define( 'PMR_PRINTFUL_BRAND_ID', 902 );
define( 'PMR_PRINTFUL_BRAND_TAXONOMY', 'product_brand' );

// ─── 1. Detect Printify items ──────────────────────────────────────────────
function pmr_cart_item_is_printify( $cart_item ) {
	if ( empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
		return false;
	}

	$product    = $cart_item['data'];
	$product_id = $product->get_id();
	$flag       = get_post_meta( $product_id, '_printify_connect', true );

	if ( empty( $flag ) && $product->is_type( 'variation' ) ) {
		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$flag = get_post_meta( $parent_id, '_printify_connect', true );
		}
	}

	return ( ! empty( $flag ) && '1' === (string) $flag );
}

// ─── 1b. Detect Printful items (by product brand ID 902) ───────────────────
function pmr_cart_item_is_printful( $cart_item ) {
	if ( empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
		return false;
	}

	$product    = $cart_item['data'];
	$product_id = $product->get_id();

	if ( $product->is_type( 'variation' ) ) {
		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$product_id = $parent_id;
		}
	}

	return has_term( PMR_PRINTFUL_BRAND_ID, PMR_PRINTFUL_BRAND_TAXONOMY, $product_id );
}

function pmr_sum_contents_cost( $items ) {
	$total = 0;
	foreach ( $items as $item ) {
		$total += isset( $item['line_total'] ) ? (float) $item['line_total'] : 0;
	}
	return $total;
}

// ─── 2. Split into up to three packages ────────────────────────────────────
add_filter( 'woocommerce_cart_shipping_packages', 'pmr_split_shipping_packages', 100, 1 );
function pmr_split_shipping_packages( $packages ) {
	if ( empty( $packages ) || ! is_array( $packages ) ) {
		return $packages;
	}

	$new_packages = array();

	foreach ( $packages as $package ) {
		if ( empty( $package['contents'] ) ) {
			$new_packages[] = $package;
			continue;
		}

		$printify_items  = array();
		$printful_items  = array();
		$customcat_items = array();

		foreach ( $package['contents'] as $key => $item ) {
			if ( pmr_cart_item_is_printify( $item ) ) {
				$printify_items[ $key ] = $item;
			} elseif ( pmr_cart_item_is_printful( $item ) ) {
				$printful_items[ $key ] = $item;
			} else {
				$customcat_items[ $key ] = $item;
			}
		}

		$buckets = array(
			'printify'  => $printify_items,
			'printful'  => $printful_items,
			'customcat' => $customcat_items,
		);

		$non_empty_buckets = array_filter( $buckets );

		if ( count( $non_empty_buckets ) <= 1 ) {
			foreach ( $non_empty_buckets as $type => $items ) {
				$package['package_type'] = $type;
			}
			if ( empty( $non_empty_buckets ) ) {
				$package['package_type'] = 'customcat';
			}
			$new_packages[] = $package;
			continue;
		}

		foreach ( $non_empty_buckets as $type => $items ) {
			$new_package                  = $package;
			$new_package['contents']      = $items;
			$new_package['contents_cost'] = pmr_sum_contents_cost( $items );
			$new_package['package_type']  = $type;
			$new_packages[]               = $new_package;
		}
	}

	$GLOBALS['pmr_total_packages'] = count( $new_packages );

	return $new_packages;
}

// ─── 3. Force Printify/CustomCat packages to pull rates from their correct zone ──
// Printful packages are skipped: the Printful plugin calculates its own live rates.
function pmr_get_zone_by_name( $name ) {
	foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
		if ( 0 === strcasecmp( trim( $zone_data['zone_name'] ), $name ) ) {
			return new WC_Shipping_Zone( $zone_data['id'] );
		}
	}
	return false;
}

add_filter( 'woocommerce_package_rates', 'pmr_ensure_correct_zone_rates', 5, 2 );
function pmr_ensure_correct_zone_rates( $rates, $package ) {
	if ( empty( $package['package_type'] ) || 'printful' === $package['package_type'] ) {
		return $rates;
	}

	$zone_name = ( 'printify' === $package['package_type'] ) ? 'Printify' : 'CustomCat';
	$zone      = pmr_get_zone_by_name( $zone_name );

	if ( ! $zone ) {
		return $rates;
	}

	$forced_rates = array();

	foreach ( $zone->get_shipping_methods( true ) as $method ) {
		if ( ! $method->is_enabled() ) {
			continue;
		}

		$method->rates = array();
		$method->calculate_shipping( $package );

		foreach ( $method->rates as $rate_id => $rate ) {
			$forced_rates[ $rate_id ] = $rate;
		}
	}

	return $forced_rates;
}

// ─── 4. CustomCat cost calculator ──────────────────────────────────────────
add_filter( 'woocommerce_package_rates', 'custom_cat_shipping_economy_ground', 10, 2 );
function custom_cat_shipping_economy_ground( $rates, $package ) {
	if ( ! empty( $package['package_type'] ) && 'customcat' !== $package['package_type'] ) {
		return $rates;
	}

	$shipping_rules = array(
		'economy' => array(
			'base' => array(
				'hoodies' => 7.99,
				'tshirt'  => 4.99,
			),
			'add'  => array(
				'hoodies' => 2.50,
				'tshirt'  => 1.50,
			),
		),
		'ground'  => array(
			'base' => array(
				'hoodies' => 13.99,
				'tshirt'  => 12.99,
			),
			'add'  => array(
				'hoodies' => 2.50,
				'tshirt'  => 2.50,
			),
		),
	);

	$cart_classes = array();

	foreach ( $package['contents'] as $item ) {
		$class = $item['data']->get_shipping_class();
		if ( $class ) {
			$cart_classes[ $class ] = isset( $cart_classes[ $class ] )
				? $cart_classes[ $class ] + $item['quantity']
				: $item['quantity'];
		}
	}

	if ( empty( $cart_classes ) ) {
		return $rates;
	}

	foreach ( $rates as $rate_key => $rate ) {
		$method_name = strtolower( $rate->get_label() );
		$type        = ( strpos( $method_name, 'ground' ) !== false ) ? 'ground' : 'economy';

		if ( ! isset( $shipping_rules[ $type ] ) ) {
			continue;
		}

		$rules         = $shipping_rules[ $type ];
		$max_base      = 0;
		$primary_class = '';

		foreach ( $cart_classes as $class => $qty ) {
			if ( isset( $rules['base'][ $class ] ) && $rules['base'][ $class ] > $max_base ) {
				$max_base      = $rules['base'][ $class ];
				$primary_class = $class;
			}
		}

		$total = $max_base;

		foreach ( $cart_classes as $class => $qty ) {
			$remainder = ( $class === $primary_class ) ? $qty - 1 : $qty;
			if ( isset( $rules['add'][ $class ] ) && $remainder > 0 ) {
				$total += ( $remainder * $rules['add'][ $class ] );
			}
		}

		$rates[ $rate_key ]->cost = round( $total, 2 );

		if ( wc_tax_enabled() ) {
			$tax_rates = WC_Tax::get_shipping_tax_rates();
			$taxes     = WC_Tax::calc_shipping_tax( $total, $tax_rates );
			$rates[ $rate_key ]->taxes = $taxes;
		}
	}

	return $rates;
}

// ─── 5. Final safety net — strip cross-contamination (Printify vs CustomCat) ──
add_filter( 'woocommerce_package_rates', 'pmr_filter_rates_per_package', 100, 2 );
function pmr_filter_rates_per_package( $rates, $package ) {
	if ( empty( $package['package_type'] ) || 'printful' === $package['package_type'] ) {
		return $rates;
	}

	if ( 'printify' === $package['package_type'] ) {
		foreach ( $rates as $rate_key => $rate ) {
			if ( 'pmr_printify_shipping' !== $rate->method_id ) {
				unset( $rates[ $rate_key ] );
			}
		}
	} elseif ( 'customcat' === $package['package_type'] ) {
		foreach ( $rates as $rate_key => $rate ) {
			if ( 'pmr_printify_shipping' === $rate->method_id ) {
				unset( $rates[ $rate_key ] );
			}
		}
	}

	return $rates;
}

// ─── 6. Rename "Shipment" labels to "Shipping" at checkout ────────────────
add_filter( 'woocommerce_shipping_package_name', 'pmr_rename_shipment_to_shipping', 10, 3 );
function pmr_rename_shipment_to_shipping( $package_name, $i, $package ) {
	$total_packages = isset( $GLOBALS['pmr_total_packages'] ) ? $GLOBALS['pmr_total_packages'] : 1;

	if ( $total_packages <= 1 ) {
		return __( 'Shipping', 'woocommerce' );
	}

	return sprintf( __( 'Shipping %d', 'woocommerce' ), ( $i + 1 ) );
}
